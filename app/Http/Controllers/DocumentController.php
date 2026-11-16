<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $documents = Document::query()
            ->visibleTo($request->user())
            ->with(['department:id,name', 'uploader:id,name'])
            ->latest('id')
            ->get();

        return response()->json(['data' => $documents->map->toApiArray()]);
    }

    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $user = $request->user();
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());

        $path = $file->storeAs(
            "documents/{$user->organization_id}",
            Str::uuid().'.'.$extension,
        );

        $document = Document::create([
            'organization_id' => $user->organization_id,
            'department_id' => $request->integer('department_id'),
            'uploaded_by' => $user->id,
            'title' => $request->validated('title'),
            'file_path' => $path,
            'mime_type' => StoreDocumentRequest::MIME_TYPES[$extension],
            'clearance_level' => $request->integer('clearance_level'),
            'status' => Document::STATUS_PENDING,
        ]);

        ProcessDocumentJob::dispatch($document);

        return response()->json(
            ['data' => $document->load(['department:id,name', 'uploader:id,name'])->toApiArray()],
            201,
        );
    }

    /**
     * A document outside the user's scope returns 404, not 403, so its
     * existence cannot be inferred by probing IDs.
     */
    public function download(Request $request, int $document): StreamedResponse
    {
        $document = Document::query()->visibleTo($request->user())->findOrFail($document);

        abort_unless(Storage::disk()->exists($document->file_path), 404, 'The stored file is missing.');

        $extension = pathinfo($document->file_path, PATHINFO_EXTENSION);

        return Storage::disk()->download(
            $document->file_path,
            Str::slug($document->title).'.'.$extension,
            ['Content-Type' => $document->mime_type],
        );
    }
}
