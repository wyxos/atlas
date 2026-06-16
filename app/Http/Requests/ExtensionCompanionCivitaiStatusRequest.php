<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ExtensionCompanionCivitaiStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.request_id' => ['nullable', 'string', 'max:128'],
            'items.*.id' => ['required', 'integer', 'min:1'],
            'items.*.url' => ['required', 'string', 'max:4096'],
            'items.*.type' => ['nullable', 'string', 'in:image,video'],
            'items.*.nsfw' => ['nullable'],
            'items.*.nsfwLevel' => ['nullable'],
            'items.*.width' => ['nullable', 'integer', 'min:1'],
            'items.*.height' => ['nullable', 'integer', 'min:1'],
            'items.*.hash' => ['nullable', 'string', 'max:255'],
            'items.*.postId' => ['nullable', 'integer', 'min:1'],
            'items.*.username' => ['nullable', 'string', 'max:255'],
            'items.*.meta' => ['nullable', 'array'],
            'items.*.modelId' => ['nullable', 'integer', 'min:1'],
            'items.*.modelVersionId' => ['nullable', 'integer', 'min:1'],
            'items.*.modelType' => ['nullable', 'string', 'max:64'],
            'items.*.resource_containers' => ['nullable', 'array', 'max:100'],
            'items.*.resource_containers.*.type' => ['required', 'string', 'in:Checkpoint,LoRA'],
            'items.*.resource_containers.*.modelId' => ['required', 'integer', 'min:1'],
            'items.*.resource_containers.*.modelVersionId' => ['required', 'integer', 'min:1'],
            'items.*.resource_containers.*.referrerUrl' => ['required', 'string', 'max:4096'],
        ];
    }
}
