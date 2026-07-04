<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExtensionBatchReactionRequest extends FormRequest
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
            'download_action' => ['nullable', 'string', 'in:queue,skip,force'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.asset_url' => ['required', 'url', 'max:4096'],
            'items.*.match_identity' => ['nullable', 'array'],
            'items.*.match_identity.match_by' => ['required_with:items.*.match_identity', 'string', 'in:source,referrer'],
            'items.*.match_identity.match_url' => ['required_with:items.*.match_identity', 'url', 'max:4096'],
            'items.*.match_identity.rule_digest' => ['nullable', 'string', 'max:128'],
            'items.*.match_identity.rule_id' => ['nullable', 'string', 'max:128'],
            'items.*.metadata' => ['nullable', 'array'],
            'items.*.metadata.asset_type' => ['nullable', 'string', 'in:image,video,audio'],
            'items.*.metadata.page_title' => ['nullable', 'string', 'max:500'],
            'items.*.metadata.resolution' => ['nullable', 'string', 'max:50'],
            'items.*.referrer_url' => ['nullable', 'url', 'max:4096'],
            'items.*.source' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:love,like,funny,blacklist'],
        ];
    }
}
