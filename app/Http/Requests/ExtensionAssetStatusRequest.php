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
            'asset_urls' => ['nullable', 'array', 'max:300', 'required_without:referrer_urls'],
            'asset_urls.*' => ['required', 'url', 'max:4096'],
            'referrer_urls' => ['nullable', 'array', 'max:300', 'required_without:asset_urls'],
            'referrer_urls.*' => ['required', 'url', 'max:4096'],
        ];
    }
}
