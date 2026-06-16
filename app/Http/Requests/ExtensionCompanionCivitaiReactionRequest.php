<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ExtensionCompanionCivitaiReactionRequest extends FormRequest
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
            'type' => ['required', 'string', 'in:love,like,funny,blacklist'],
            'download_behavior' => ['nullable', 'string', 'in:queue,skip,force'],
            'item' => ['required', 'array'],
            'item.request_id' => ['nullable', 'string', 'max:128'],
            'item.id' => ['required', 'integer', 'min:1'],
            'item.url' => ['required', 'string', 'max:4096'],
            'item.type' => ['nullable', 'string', 'in:image,video'],
            'item.nsfw' => ['nullable'],
            'item.nsfwLevel' => ['nullable'],
            'item.width' => ['nullable', 'integer', 'min:1'],
            'item.height' => ['nullable', 'integer', 'min:1'],
            'item.hash' => ['nullable', 'string', 'max:255'],
            'item.postId' => ['nullable', 'integer', 'min:1'],
            'item.username' => ['nullable', 'string', 'max:255'],
            'item.meta' => ['nullable', 'array'],
            'item.modelId' => ['nullable', 'integer', 'min:1'],
            'item.modelVersionId' => ['nullable', 'integer', 'min:1'],
            'item.modelType' => ['nullable', 'string', 'max:64'],
            'item.resource_containers' => ['nullable', 'array', 'max:100'],
            'item.resource_containers.*.type' => ['required', 'string', 'in:Checkpoint,LoRA'],
            'item.resource_containers.*.modelId' => ['required', 'integer', 'min:1'],
            'item.resource_containers.*.modelVersionId' => ['required', 'integer', 'min:1'],
            'item.resource_containers.*.referrerUrl' => ['required', 'string', 'max:4096'],
            'cookies' => ['nullable', 'array', 'max:300'],
            'cookies.*.name' => ['required', 'string', 'max:255'],
            'cookies.*.value' => ['required', 'string', 'max:4096'],
            'cookies.*.domain' => ['required', 'string', 'max:255'],
            'cookies.*.path' => ['required', 'string', 'max:2048'],
            'cookies.*.secure' => ['nullable', 'boolean'],
            'cookies.*.http_only' => ['nullable', 'boolean'],
            'cookies.*.host_only' => ['nullable', 'boolean'],
            'cookies.*.expires_at' => ['nullable', 'integer', 'min:0'],
            'user_agent' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
