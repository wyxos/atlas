<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewAudioMetadataProposalRequest extends FormRequest
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
            'action' => ['required', Rule::in(['apply', 'ignore'])],
            'fields' => ['nullable', 'array'],
            'fields.*' => [
                'string',
                'distinct',
                Rule::in(['title', 'artists', 'album', 'duration_seconds', 'cover_url', 'spotify_uri']),
            ],
        ];
    }
}
