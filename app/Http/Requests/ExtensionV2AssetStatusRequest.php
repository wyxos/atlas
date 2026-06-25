<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExtensionV2AssetStatusRequest extends FormRequest
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
            'asset_urls' => ['required', 'array', 'max:300'],
            'asset_urls.*' => ['required', 'url', 'max:4096'],
        ];
    }
}
