<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExtensionBroadcastAuthRequest extends FormRequest
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
            'channel_name' => ['required', 'string', 'max:255'],
            'socket_id' => ['required', 'string', 'regex:/^\d+\.\d+$/'],
        ];
    }
}
