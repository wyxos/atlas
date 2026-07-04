<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExtensionReactionRequest extends FormRequest
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
            'asset_url' => ['required', 'url', 'max:4096'],
            'download_action' => ['nullable', 'string', 'in:queue,skip,force'],
            'match_identity' => ['nullable', 'array'],
            'match_identity.match_by' => ['required_with:match_identity', 'string', 'in:source,referrer'],
            'match_identity.match_url' => ['required_with:match_identity', 'url', 'max:4096'],
            'match_identity.rule_digest' => ['nullable', 'string', 'max:128'],
            'match_identity.rule_id' => ['nullable', 'string', 'max:128'],
            'metadata' => ['nullable', 'array'],
            'metadata.asset_type' => ['nullable', 'string', 'in:image,video,audio'],
            'metadata.page_title' => ['nullable', 'string', 'max:500'],
            'metadata.resolution' => ['nullable', 'string', 'max:50'],
            'referrer_url' => ['nullable', 'url', 'max:4096'],
            'source' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:love,like,funny,blacklist'],
        ];
    }
}
