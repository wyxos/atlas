<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBrowseTabRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $browseTab = $this->route('browseTab');

        return $browseTab && $browseTab->user_id === $this->user()->id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'required', 'string', 'max:255'],
            'query_params' => ['nullable', 'array'],
            'position' => ['nullable', 'integer', 'min:0'],
            'file_ids' => ['nullable', 'array'],
            'file_ids.*' => ['required', 'integer', 'exists:files,id'],
        ];
    }
}
