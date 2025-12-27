<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTabRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // User must be authenticated (handled by middleware)
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'params' => ['nullable', 'array'],
            'params.feed' => ['nullable', 'string', 'in:online,local'],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
