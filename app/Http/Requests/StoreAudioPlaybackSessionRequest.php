<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAudioPlaybackSessionRequest extends FormRequest
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
        $isClaim = $this->routeIs('api.audio.playback-session.claim');
        $isRelease = $this->routeIs('api.audio.playback-session.release');

        return [
            'instance_id' => ['required', 'string', 'max:128'],
            'lease_token' => [$isClaim ? 'missing' : 'required', 'string', 'max:128'],
            'owner_label' => [$isClaim ? 'required' : 'sometimes', 'nullable', 'string', 'max:128'],
            'state' => [$isRelease ? 'sometimes' : 'required', Rule::in(['idle', 'playing', 'paused'])],
            'source' => [$isRelease ? 'sometimes' : 'nullable', Rule::in(['local', 'spotify'])],
            'current_track' => [$isClaim ? 'required' : 'sometimes', 'nullable', 'array'],
            'queue_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'position_seconds' => ['sometimes', 'numeric', 'min:0'],
            'duration_seconds' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'spotify_device_id' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
