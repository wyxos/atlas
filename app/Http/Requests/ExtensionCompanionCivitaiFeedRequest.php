<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ExtensionCompanionCivitaiFeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'model_id' => ['required', 'integer', 'min:1'],
            'model_version_id' => ['nullable', 'integer', 'min:1'],
            'model_type' => ['nullable', 'string', 'max:64'],
            'nsfw' => ['sometimes', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'cursor' => ['nullable', 'string', 'max:512'],
            'sort' => ['nullable', 'string', 'max:64'],
        ];
    }
}
