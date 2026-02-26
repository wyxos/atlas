<?php

namespace App\Http\Requests\Concerns;

trait ValidatesExtensionAuthContext
{
    /**
     * @return array<string, array<int, string>>
     */
    protected function extensionAuthContextRules(): array
    {
        return [
            'auth_context' => ['nullable', 'array'],
            'auth_context.source_url' => ['nullable', 'string', 'url', 'max:2048'],
            'auth_context.user_agent' => ['nullable', 'string', 'max:1024'],
            'auth_context.cookies' => ['nullable', 'array', 'max:120'],
            'auth_context.cookies.*.domain' => ['required_with:auth_context.cookies', 'string', 'max:255'],
            'auth_context.cookies.*.path' => ['required_with:auth_context.cookies', 'string', 'max:1024'],
            'auth_context.cookies.*.name' => ['required_with:auth_context.cookies', 'string', 'max:255'],
            'auth_context.cookies.*.value' => ['nullable', 'string', 'max:4096'],
            'auth_context.cookies.*.secure' => ['nullable', 'boolean'],
            'auth_context.cookies.*.host_only' => ['nullable', 'boolean'],
            'auth_context.cookies.*.expires' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
