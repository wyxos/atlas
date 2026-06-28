<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ComfyCompanionCivitAiModelBrowseFeedRequest extends FormRequest
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
            'cursor' => ['nullable', 'string', 'max:2048'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'model_id' => ['required', 'integer', 'min:1'],
            'model_type' => ['nullable', 'string', 'max:120'],
            'model_version_id' => ['nullable', 'integer', 'min:1'],
            'nsfw' => ['sometimes', 'boolean'],
            'period' => ['sometimes', 'string', 'max:120'],
            'sort' => ['sometimes', 'string', 'max:120'],
            'type' => ['sometimes', 'string', 'max:120'],
        ];
    }
}
