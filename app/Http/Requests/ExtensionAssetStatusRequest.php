<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExtensionAssetStatusRequest extends FormRequest
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
            'asset_urls' => ['nullable', 'array', 'max:300', 'required_without_all:referrer_urls,match_items'],
            'asset_urls.*' => ['required', 'url', 'max:4096'],
            'match_items' => ['nullable', 'array', 'max:300', 'required_without_all:asset_urls,referrer_urls'],
            'match_items.*.lookup_id' => ['required', 'string', 'max:255'],
            'match_items.*.match_by' => ['required', 'string', 'in:source,referrer'],
            'match_items.*.match_url' => ['required', 'url', 'max:4096'],
            'match_items.*.rule_digest' => ['nullable', 'string', 'max:128'],
            'match_items.*.rule_id' => ['nullable', 'string', 'max:128'],
            'referrer_urls' => ['nullable', 'array', 'max:300', 'required_without_all:asset_urls,match_items'],
            'referrer_urls.*' => ['required', 'url', 'max:4096'],
        ];
    }
}
