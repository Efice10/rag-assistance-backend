<?php

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Zero-trust RBAC isolation
|--------------------------------------------------------------------------
| A user may only reach documents in their own organization, in their own
| department (unless admin), at or below their clearance level. These tests
| cover every route that exposes documents: list, download, and chat.
*/

beforeEach(function () {
    $this->t = tenants();

    $this->public = Document::factory()->in($this->t['procurement'])->clearance(1)->create(['title' => 'Public Handbook']);
    $this->internal = Document::factory()->in($this->t['procurement'])->clearance(2)->create(['title' => 'Internal Memo']);
    $this->confidential = Document::factory()->in($this->t['procurement'])->clearance(3)->create(['title' => 'Confidential Pricing']);
    $this->restricted = Document::factory()->in($this->t['procurement'])->clearance(4)->create(['title' => 'Restricted Investigation']);
    $this->hrPublic = Document::factory()->in($this->t['hr'])->clearance(1)->create(['title' => 'HR Leave Policy']);
    $this->globexPublic = Document::factory()->in($this->t['operations'])->clearance(1)->create(['title' => 'Globex Fleet Manual']);

    $this->staff = User::factory()->in($this->t['procurement'])->staff(1)->create();
    $this->manager = User::factory()->in($this->t['procurement'])->manager(3)->create();
    $this->admin = User::factory()->in($this->t['procurement'])->admin()->create();
    $this->globexAdmin = User::factory()->in($this->t['operations'])->admin()->create();
});

describe('document listing', function () {
    it('shows clearance-1 staff only public documents from their own department', function () {
        Sanctum::actingAs($this->staff);

        $titles = $this->getJson('/api/documents')->assertOk()->json('data.*.title');

        expect($titles)->toBe(['Public Handbook']);
    });

    it('shows a clearance-3 manager documents up to Confidential but never Restricted', function () {
        Sanctum::actingAs($this->manager);

        $titles = $this->getJson('/api/documents')->assertOk()->json('data.*.title');

        expect($titles)
            ->toContain('Public Handbook', 'Internal Memo', 'Confidential Pricing')
            ->not->toContain('Restricted Investigation')
            ->not->toContain('HR Leave Policy');
    });

    it('lets an admin see every department in their organization but nothing from another organization', function () {
        Sanctum::actingAs($this->admin);

        $titles = $this->getJson('/api/documents')->assertOk()->json('data.*.title');

        expect($titles)
            ->toHaveCount(5)
            ->toContain('HR Leave Policy', 'Restricted Investigation')
            ->not->toContain('Globex Fleet Manual');
    });

    it('rejects unauthenticated requests', function () {
        $this->getJson('/api/documents')->assertUnauthorized();
    });
});

describe('document download', function () {
    beforeEach(function () {
        Storage::fake('local');
    });

    it('lets staff download a public document from their department', function () {
        Storage::disk()->put($this->public->file_path, '%PDF-1.4 test');
        Sanctum::actingAs($this->staff);

        $this->get("/api/documents/{$this->public->id}/download")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    });

    it('hides documents above the user\'s clearance behind a 404', function (string $document) {
        Sanctum::actingAs($this->staff);

        $this->getJson("/api/documents/{$this->{$document}->id}/download")->assertNotFound();
    })->with(['internal', 'confidential', 'restricted']);

    it('hides another department\'s documents from non-admins', function () {
        Sanctum::actingAs($this->manager);

        $this->getJson("/api/documents/{$this->hrPublic->id}/download")->assertNotFound();
    });

    it('hides another organization\'s documents even from admins', function () {
        Sanctum::actingAs($this->admin);
        $this->getJson("/api/documents/{$this->globexPublic->id}/download")->assertNotFound();

        Sanctum::actingAs($this->globexAdmin);
        $this->getJson("/api/documents/{$this->restricted->id}/download")->assertNotFound();
    });
});

describe('chat scope', function () {
    beforeEach(function () {
        config(['services.ai.secret' => 'test-internal-secret']);
    });

    it('sends the AI service a scope derived from the user, ignoring forged request values', function () {
        Http::fake(['*/query' => Http::response(fakeAiStream([$this->public->id]), 200, ['Content-Type' => 'text/event-stream'])]);
        Sanctum::actingAs($this->staff);

        $this->postJson('/api/chat', [
            'prompt' => 'What is the restricted investigation about?',
            // An attacker trying to widen their own scope:
            'organization_id' => $this->t['globex']->id,
            'department_id' => $this->t['hr']->id,
            'clearance_level' => 4,
            'is_admin' => true,
        ])->assertOk()->streamedContent();

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/query')
                && $request->hasHeader('X-Internal-Secret', 'test-internal-secret')
                && $request['organization_id'] === $this->t['acme']->id
                && $request['department_id'] === $this->t['procurement']->id
                && $request['clearance_level'] === 1
                && $request['is_admin'] === false;
        });
    });

    it('flags admins so the AI service can drop the department filter, still inside their organization', function () {
        Http::fake(['*/query' => Http::response(fakeAiStream(), 200)]);
        Sanctum::actingAs($this->globexAdmin);

        $this->postJson('/api/chat', ['prompt' => 'Summarise the fleet manual.'])->assertOk()->streamedContent();

        Http::assertSent(fn ($request) => $request['organization_id'] === $this->t['globex']->id
            && $request['clearance_level'] === 4
            && $request['is_admin'] === true);
    });

    it('relays the stream and records the prompt and cited documents in the audit log', function () {
        Http::fake(['*/query' => Http::response(fakeAiStream([$this->public->id], 'See the handbook.'), 200)]);
        Sanctum::actingAs($this->staff);

        $content = $this->postJson('/api/chat', ['prompt' => 'Where is the handbook?'])->assertOk()->streamedContent();

        expect($content)->toContain('See the handbook.')->toContain('event: sources');

        $log = AuditLog::sole();

        expect($log->action)->toBe(AuditLog::ACTION_CHAT_QUERY)
            ->and($log->user_id)->toBe($this->staff->id)
            ->and($log->organization_id)->toBe($this->t['acme']->id)
            ->and($log->prompt)->toBe('Where is the handbook?')
            ->and($log->retrieved_document_ids)->toBe([$this->public->id]);
    });

    it('still writes an audit record when the AI service is down', function () {
        Http::fake(['*/query' => Http::response('boom', 500)]);
        Sanctum::actingAs($this->staff);

        $content = $this->postJson('/api/chat', ['prompt' => 'Anything?'])->assertOk()->streamedContent();

        expect($content)->toContain('event: error')
            ->and(AuditLog::sole()->action)->toBe(AuditLog::ACTION_CHAT_QUERY_FAILED);
    });

    it('records a failure when the AI service reports an error mid-stream', function () {
        Http::fake(['*/query' => Http::response("event: error\ndata: {\"message\":\"failed\"}\n\n", 200)]);
        Sanctum::actingAs($this->staff);

        $this->postJson('/api/chat', ['prompt' => 'Anything?'])->assertOk()->streamedContent();

        expect(AuditLog::sole()->action)->toBe(AuditLog::ACTION_CHAT_QUERY_FAILED);
    });

    it('rate limits chat to 20 prompts per minute per user', function () {
        Http::fake(['*/query' => Http::response(fakeAiStream(), 200)]);
        Sanctum::actingAs($this->staff);

        foreach (range(1, 20) as $attempt) {
            $this->postJson('/api/chat', ['prompt' => "Question {$attempt}"])->assertOk()->streamedContent();
        }

        $this->postJson('/api/chat', ['prompt' => 'One too many'])->assertTooManyRequests();
    });
});

describe('audit logs', function () {
    beforeEach(function () {
        $this->acmeLog = AuditLog::create([
            'organization_id' => $this->t['acme']->id,
            'user_id' => $this->staff->id,
            'action' => AuditLog::ACTION_CHAT_QUERY,
            'prompt' => 'Acme prompt',
            'retrieved_document_ids' => [$this->public->id],
            'ip_address' => '127.0.0.1',
        ]);
    });

    it('is visible only to admins', function (string $role) {
        Sanctum::actingAs($this->{$role});

        $this->getJson('/api/audit-logs')->assertForbidden();
    })->with(['staff', 'manager']);

    it('shows an admin their own organization\'s logs with cited document titles', function () {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/audit-logs')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.prompt', 'Acme prompt')
            ->assertJsonPath('data.0.documents.0.title', 'Public Handbook');
    });

    it('never shows one organization\'s logs to another organization\'s admin', function () {
        Sanctum::actingAs($this->globexAdmin);

        $this->getJson('/api/audit-logs')->assertOk()->assertJsonPath('meta.total', 0);
    });

    it('cannot be updated or deleted through the model', function () {
        expect(fn () => $this->acmeLog->update(['prompt' => 'tampered']))->toThrow(LogicException::class)
            ->and(fn () => $this->acmeLog->delete())->toThrow(LogicException::class);
    });

    it('cannot be updated or deleted with raw queries either', function () {
        expect(fn () => DB::table('audit_logs')->update(['prompt' => 'tampered']))->toThrow(Exception::class)
            ->and(fn () => DB::table('audit_logs')->delete())->toThrow(Exception::class)
            ->and(AuditLog::sole()->prompt)->toBe('Acme prompt');
    });
});
