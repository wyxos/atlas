<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class CheckExternalFilesRequest extends FormRequest
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
            'urls' => ['required', 'array', 'min:1', 'max:200'],
            'urls.*' => ['required', 'string', 'url', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'urls.required' => 'A list of URLs is required.',
            'urls.array' => 'URLs must be an array.',
            'urls.*.url' => 'Each URL must be a valid URL.',
        ];
    }
}
