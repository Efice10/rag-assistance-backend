<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChatRequest;
use App\Models\AuditLog;
use App\Services\AiServiceClient;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ChatController extends Controller
{
    public function __invoke(ChatRequest $request, AiServiceClient $ai): StreamedResponse
    {
        $user = $request->user();
        $prompt = $request->validated('prompt');
        $ipAddress = $request->ip();

        // Trusted, server-derived scope. Nothing from the request body is used.
        $scope = $user->retrievalScope();

        return response()->stream(function () use ($ai, $user, $prompt, $scope, $ipAddress) {
            // Keep running if the browser disconnects, so the audit record is always written.
            ignore_user_abort(true);

            $documentIds = [];
            $action = AuditLog::ACTION_CHAT_QUERY;

            try {
                $body = $ai->streamQuery($prompt, $scope);
                $buffer = '';

                while (! $body->eof()) {
                    $chunk = $body->read(1024);

                    if ($chunk === '') {
                        continue;
                    }

                    $this->send($chunk);

                    // Watch the stream for the citations event as it passes through.
                    $buffer .= str_replace("\r\n", "\n", $chunk);

                    while (($end = strpos($buffer, "\n\n")) !== false) {
                        $event = substr($buffer, 0, $end);
                        $buffer = substr($buffer, $end + 2);

                        $documentIds = [...$documentIds, ...$this->documentIdsFrom($event)];

                        // The AI service reports its own failures as an error event.
                        if (preg_match('/^event:\s*error\s*$/m', $event)) {
                            $action = AuditLog::ACTION_CHAT_QUERY_FAILED;
                        }
                    }
                }
            } catch (Throwable $e) {
                report($e);
                $action = AuditLog::ACTION_CHAT_QUERY_FAILED;

                $this->send("event: error\ndata: ".json_encode([
                    'message' => 'The AI service is unavailable. Please try again shortly.',
                ])."\n\n");
            } finally {
                // One insert, after the stream ends: audit_logs rows are never updated.
                AuditLog::create([
                    'organization_id' => $user->organization_id,
                    'user_id' => $user->id,
                    'action' => $action,
                    'prompt' => $prompt,
                    'retrieved_document_ids' => array_values(array_unique($documentIds)),
                    'ip_address' => $ipAddress,
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function send(string $data): void
    {
        echo $data;

        if (ob_get_level() > 0) {
            @ob_flush();
        }

        flush();
    }

    /**
     * @return list<int>
     */
    private function documentIdsFrom(string $event): array
    {
        if (! preg_match('/^event:\s*sources\s*$/m', $event) || ! preg_match('/^data:\s*(.+)$/m', $event, $match)) {
            return [];
        }

        $payload = json_decode($match[1], true);

        return collect($payload['sources'] ?? [])
            ->pluck('document_id')
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
