<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class StoreExternalFileRequest extends FormRequest
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
            // Required: extension-triggered downloads are driven by the same reaction pipeline as the app.
            // No fallback to "queue download without reaction".
            'reaction_type' => ['required', 'string', 'in:love,like,dislike,funny'],
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
            'force_download' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'url.required' => 'A media URL is required.',
            'url.url' => 'The media URL must be a valid URL.',
            'reaction_type.required' => 'A reaction_type is required.',
            'referrer_url.url' => 'The referrer URL must be a valid URL.',
            'preview_url.url' => 'The preview URL must be a valid URL.',
            'size.integer' => 'The size must be an integer.',
            'width.integer' => 'The width must be an integer.',
            'height.integer' => 'The height must be an integer.',
        ];
    }
}
