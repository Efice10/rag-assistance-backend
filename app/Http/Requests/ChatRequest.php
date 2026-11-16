<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the prompt is accepted. Scope fields such as organization_id or
     * clearance_level are deliberately not validated, so they never reach
     * validated() even if a client sends them.
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'min:2', 'max:2000'],
        ];
    }
}
