<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExtensionAssetMatchRuleRequest extends FormRequest
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
            'chunk' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'rule' => ['required', 'array'],
            'rule.cleanup' => ['nullable', 'array'],
            'rule.domain' => ['nullable', 'string', 'max:255'],
            'rule.match_by' => ['required', 'string', 'in:source,referrer'],
            'rule.rule_digest' => ['nullable', 'string', 'max:128'],
            'rule.rule_id' => ['nullable', 'string', 'max:128'],
        ];
    }
}
