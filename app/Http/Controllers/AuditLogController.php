<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->isAdmin(), 403, 'Only administrators can view audit logs.');

        $search = trim((string) $request->query('q', ''));

        $logs = AuditLog::query()
            ->where('organization_id', $user->organization_id)
            ->when($search !== '', function ($query) use ($search) {
                $term = '%'.mb_strtolower($search).'%';

                $query->where(function ($inner) use ($term) {
                    $inner->whereRaw('LOWER(prompt) LIKE ?', [$term])
                        ->orWhereHas('user', fn ($users) => $users->whereRaw('LOWER(name) LIKE ?', [$term]));
                });
            })
            ->with('user:id,name,email')
            ->latest('id')
            ->paginate(20);

        // Resolve cited document IDs to titles in one query for the whole page.
        $titles = Document::query()
            ->where('organization_id', $user->organization_id)
            ->whereIn('id', $logs->getCollection()->pluck('retrieved_document_ids')->flatten()->unique())
            ->pluck('title', 'id');

        return response()->json([
            'data' => $logs->getCollection()->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'prompt' => $log->prompt,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toIso8601String(),
                'user' => $log->user?->only(['id', 'name', 'email']),
                'documents' => collect($log->retrieved_document_ids)
                    ->map(fn ($id) => ['id' => $id, 'title' => $titles[$id] ?? 'Deleted document'])
                    ->values(),
            ]),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
