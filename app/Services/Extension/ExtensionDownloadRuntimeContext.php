<?php

namespace App\Services\Extension;

use Illuminate\Http\Request;

class ExtensionDownloadRuntimeContext
{
    /**
     * @param  array<string, mixed>  $validated
     * @return array{
     *     cookies?: list<array{
     *         name: string,
     *         value: string,
     *         domain: string,
     *         path: string,
     *         secure: bool,
     *         http_only: bool,
     *         host_only: bool,
     *         expires_at: int|null
     *     }>,
     *     user_agent?: string
     * }
     */
    public function fromValidated(array $validated, Request $request): array
    {
        $context = [];

        $cookies = $this->normalizeRuntimeCookies($validated['cookies'] ?? null);
        if ($cookies !== []) {
            $context['cookies'] = $cookies;
        }

        $userAgent = trim((string) ($validated['user_agent'] ?? ''));
        if ($userAgent === '') {
            $userAgent = trim((string) $request->userAgent());
        }
        if ($userAgent !== '') {
            $context['user_agent'] = $userAgent;
        }

        return $context;
    }

    /**
     * @return list<array{
     *     name: string,
     *     value: string,
     *     domain: string,
     *     path: string,
     *     secure: bool,
     *     http_only: bool,
     *     host_only: bool,
     *     expires_at: int|null
     * }>
     */
    private function normalizeRuntimeCookies(mixed $cookies): array
    {
        if (! is_array($cookies)) {
            return [];
        }

        $normalized = [];

        foreach ($cookies as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            $domain = ltrim(strtolower(trim((string) ($row['domain'] ?? ''))), '.');
            $path = trim((string) ($row['path'] ?? '/'));
            if ($path === '') {
                $path = '/';
            } elseif (! str_starts_with($path, '/')) {
                $path = '/'.$path;
            }

            if ($name === '' || $domain === '' || preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) !== 1) {
                continue;
            }

            $expiresAt = null;
            if (isset($row['expires_at']) && is_numeric($row['expires_at'])) {
                $expiresAt = max(0, (int) $row['expires_at']);
            }

            $normalized[] = [
                'name' => $name,
                'value' => preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '',
                'domain' => $domain,
                'path' => $path,
                'secure' => ($row['secure'] ?? false) === true,
                'http_only' => ($row['http_only'] ?? false) === true,
                'host_only' => ($row['host_only'] ?? false) === true,
                'expires_at' => $expiresAt,
            ];
        }

        return $normalized;
    }
}
