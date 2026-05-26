<?php

namespace App\Http\Requests;

use App\Models\Playlist;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAudioPlaylistCoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        $playlist = $this->route('playlist');

        return $this->user() !== null
            && $playlist instanceof Playlist
            && (int) $playlist->user_id === (int) $this->user()->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cover_file_ids' => [
                Rule::requiredIf(fn (): bool => $this->input('cover_mode') === 'custom'),
                'array',
                'max:24',
            ],
            'cover_file_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('files', 'id')->where(fn ($query) => $query->where('mime_type', 'like', 'audio/%')),
            ],
            'cover_mode' => ['required', Rule::in(['first_track', 'custom', 'random_track'])],
        ];
    }
}
