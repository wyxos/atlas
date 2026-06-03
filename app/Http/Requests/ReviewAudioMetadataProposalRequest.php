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
                Rule::in([
                    'title',
                    'title_aliases',
                    'artists',
                    'artist_aliases',
                    'album',
                    'album_aliases',
                    'duration_seconds',
                    'cover_url',
                    'spotify_uri',
                    'track_number',
                    'disc_number',
                    'release_label',
                    'catalog_number',
                    'barcode',
                    'release_date',
                    'release_country',
                    'isrc',
                    'musicbrainz_recording_id',
                    'musicbrainz_release_id',
                    'discogs_release_id',
                ]),
            ],
        ];
    }
}
