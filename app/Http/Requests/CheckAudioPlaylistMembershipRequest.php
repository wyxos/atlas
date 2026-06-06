<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckAudioPlaylistMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'playlist' => ['required', 'string', 'max:160'],
            'file_ids' => ['required', 'array', 'min:1', 'max:200'],
            'file_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }
}
