<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class ExtensionBroadcastAuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expected = (string) config('downloads.extension_token');
        if ($expected === '') {
            return false;
        }

        $provided = $this->header('X-Atlas-Extension-Token') ?? $this->bearerToken();
        if (! is_string($provided) || $provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Invalid or missing extension token.');
    }

    public function rules(): array
    {
        return [
            'socket_id' => ['required', 'string', 'max:255'],
            'channel_name' => ['required', 'string', 'max:255', 'regex:/^private-extension-downloads\.\d+$/'],
        ];
    }
}
