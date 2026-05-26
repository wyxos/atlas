<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAudioPlaybackEventRequest extends FormRequest
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
            'event' => ['required', Rule::in(['play', 'skip'])],
            'file_id' => [
                'required',
                'integer',
                Rule::exists('files', 'id')->where(fn ($query) => $query->where('mime_type', 'like', 'audio/%')),
            ],
        ];
    }
}
