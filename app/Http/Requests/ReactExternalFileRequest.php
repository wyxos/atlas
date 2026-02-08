<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class ReactExternalFileRequest extends FormRequest
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
            'type' => ['required', 'string', 'in:love,like,dislike,funny'],
            'url' => ['required', 'string', 'url', 'max:2048'],
            'original_url' => ['nullable', 'string', 'url', 'max:2048'],
            'referrer_url' => ['nullable', 'string', 'url', 'max:2048'],
            // Some sites generate very long titles; keep it permissive since we only store it as metadata.
            'page_title' => ['nullable', 'string', 'max:2000'],
            'source' => ['nullable', 'string', 'max:100'],
            'source_id' => ['nullable', 'string', 'max:255'],
            'filename' => ['nullable', 'string', 'max:255'],
            'preview_url' => ['nullable', 'string', 'url', 'max:2048'],
            'mime_type' => ['nullable', 'string', 'max:255'],
            'size' => ['nullable', 'integer', 'min:0'],
            'width' => ['nullable', 'integer', 'min:1'],
            'height' => ['nullable', 'integer', 'min:1'],
            'tag_name' => ['nullable', 'string', 'max:50'],
            // Some sites embed large/SEO-heavy alt text.
            'alt' => ['nullable', 'string', 'max:2000'],
            'download_via' => ['nullable', 'string', 'in:yt-dlp'],
        ];
    }
}
