<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExtensionSettingsStoreRequest extends FormRequest
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
            'settings' => ['required', 'array'],
            'settings.schemaVersion' => ['required', 'integer', 'in:1'],
            'settings.settings' => ['required', 'array'],
        ];
    }
}
