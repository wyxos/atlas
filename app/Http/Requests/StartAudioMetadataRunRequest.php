<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartAudioMetadataRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scope' => ['nullable', Rule::in(['all', 'whole_library', 'missing_metadata', 'missing_covers'])],
            'source_filter' => ['nullable', Rule::in(['all', 'local', 'spotify'])],
        ];
    }
}
