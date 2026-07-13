<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolveSourceMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'refresh' => ['sometimes', 'boolean'],
            'retry' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'refresh.boolean' => 'The refresh flag must be true or false.',
            'retry.integer' => 'The retry value must be an integer.',
            'retry.min' => 'The retry value must be at least 1.',
        ];
    }
}
