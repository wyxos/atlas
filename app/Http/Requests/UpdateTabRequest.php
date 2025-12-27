<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTabRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $tab = $this->route('tab');

        return $tab && $tab->user_id === $this->user()->id;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'required', 'string', 'max:255'],
            'params' => ['nullable', 'array'],
            'params.feed' => ['nullable', 'string', 'in:online,local'],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
