<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ComfyCompanionCivitAiModelBrowseTabRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'model_id' => ['required', 'integer', 'min:1'],
            'model_version_id' => ['nullable', 'integer', 'min:1'],
            'nsfw' => ['sometimes', 'boolean'],
        ];
    }
}
