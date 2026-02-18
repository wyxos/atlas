<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class DeleteExternalFileDownloadRequest extends FormRequest
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
            'url' => ['required', 'string', 'url', 'max:2048'],
            'tag_name' => ['nullable', 'string', 'max:50'],
            'download_via' => ['nullable', 'string', 'in:yt-dlp'],
        ];
    }
}
