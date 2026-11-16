<?php

use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    $this->t = tenants();
    $this->manager = User::factory()->in($this->t['procurement'])->manager(3)->create();
});

function pdfUpload(): UploadedFile
{
    return UploadedFile::fake()->create('policy.pdf', 120, 'application/pdf');
}

it('stores the file privately, creates a pending document and queues ingestion', function () {
    Queue::fake();
    Sanctum::actingAs($this->manager);

    $response = $this->post('/api/documents', [
        'title' => 'Vendor Onboarding SOP',
        'department_id' => $this->t['procurement']->id,
        'clearance_level' => 2,
        'file' => pdfUpload(),
    ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.status', 'pending');

    $document = Document::findOrFail($response->json('data.id'));

    expect($document->organization_id)->toBe($this->t['acme']->id)
        ->and($document->uploaded_by)->toBe($this->manager->id)
        ->and($document->file_path)->toStartWith("documents/{$this->t['acme']->id}/");

    Storage::disk()->assertExists($document->file_path);
    Queue::assertPushed(ProcessDocumentJob::class, fn ($job) => $job->document->is($document));
});

it('does not let staff upload', function () {
    Sanctum::actingAs(User::factory()->in($this->t['procurement'])->staff(2)->create());

    $this->post('/api/documents', [
        'title' => 'Nope',
        'department_id' => $this->t['procurement']->id,
        'clearance_level' => 1,
        'file' => pdfUpload(),
    ], ['Accept' => 'application/json'])->assertForbidden();
});

it('rejects uploads outside the uploader\'s scope', function (string $field, callable $value) {
    Queue::fake();
    Sanctum::actingAs($this->manager);

    $payload = [
        'title' => 'Out of scope',
        'department_id' => $this->t['procurement']->id,
        'clearance_level' => 1,
        'file' => pdfUpload(),
    ];
    $payload[$field] = $value($this->t);

    $this->post('/api/documents', $payload, ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(Document::count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'a clearance above their own' => ['clearance_level', fn () => 4],
    'another department' => ['department_id', fn (array $t) => $t['hr']->id],
    'another organization\'s department' => ['department_id', fn (array $t) => $t['operations']->id],
    'a file that is not PDF or DOCX' => ['file', fn () => UploadedFile::fake()->create('notes.txt', 10, 'text/plain')],
]);

it('sends the AI service the document scope and the internal secret, then marks it indexed', function () {
    config(['services.ai.secret' => 'test-internal-secret']);
    Http::fake(['*/ingest' => Http::response(['status' => 'indexed', 'chunks' => 3])]);

    $document = Document::factory()->in($this->t['procurement'])->clearance(3)->create(['status' => Document::STATUS_PENDING]);

    ProcessDocumentJob::dispatchSync($document);

    Http::assertSent(fn ($request) => $request->hasHeader('X-Internal-Secret', 'test-internal-secret')
        && $request['document_id'] === $document->id
        && $request['organization_id'] === $this->t['acme']->id
        && $request['department_id'] === $this->t['procurement']->id
        && $request['clearance_level'] === 3
        && str_contains($request['file_path'], 'documents'));

    expect($document->fresh()->status)->toBe(Document::STATUS_INDEXED);
});

it('marks the document failed when the AI service rejects it', function () {
    Http::fake(['*/ingest' => Http::response(['detail' => 'Unreadable PDF'], 422)]);

    $document = Document::factory()->in($this->t['procurement'])->create(['status' => Document::STATUS_PENDING]);

    ProcessDocumentJob::dispatchSync($document);

    expect($document->fresh())
        ->status->toBe(Document::STATUS_FAILED)
        ->error_message->toBe('Unreadable PDF');
});
