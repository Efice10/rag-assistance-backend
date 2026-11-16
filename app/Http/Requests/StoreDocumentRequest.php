<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
{
    public const MIME_TYPES = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public function authorize(): bool
    {
        return $this->user()->canUploadDocuments();
    }

    public function rules(): array
    {
        $user = $this->user();

        // Admins may file a document under any department in their own
        // organization; managers only under their own department.
        $departmentRule = Rule::exists('departments', 'id')
            ->where('organization_id', $user->organization_id);

        if (! $user->isAdmin()) {
            $departmentRule->where('id', $user->department_id);
        }

        return [
            'title' => ['required', 'string', 'max:255'],
            'department_id' => ['required', 'integer', $departmentRule],
            // Nobody can classify a document above their own clearance.
            'clearance_level' => ['required', 'integer', 'min:1', 'max:'.$user->clearance_level],
            'file' => [
                'required',
                'file',
                'max:20480',
                'extensions:pdf,docx',
                // DOCX is a zip container, which is how fileinfo sometimes reports it.
                'mimetypes:application/pdf,application/zip,'.self::MIME_TYPES['docx'],
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'department_id.exists' => 'You cannot upload documents to that department.',
            'clearance_level.max' => 'You cannot classify a document above your own clearance level.',
            'file.extensions' => 'Only PDF and DOCX files are accepted.',
            'file.mimetypes' => 'Only PDF and DOCX files are accepted.',
        ];
    }
}
