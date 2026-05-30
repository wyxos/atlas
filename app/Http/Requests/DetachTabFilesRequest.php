<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DetachTabFilesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'file_ids' => ['required', 'array', 'min:1'],
            'file_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }
}
