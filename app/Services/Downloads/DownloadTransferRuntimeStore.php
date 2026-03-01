<?php

namespace App\Services\Downloads;

use Illuminate\Support\Facades\Cache;

final class DownloadTransferRuntimeStore
{
    private const string KEY_PREFIX = 'downloads:transfer-runtime:';

    private const int TTL_SECONDS = 21600;

    /**
     * @param  array{
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
     * }  $context
     */
    public function putForTransfer(int $transferId, array $context): void
    {
        $normalized = $this->normalizeContext($context);
        if ($normalized === []) {
            return;
        }

        Cache::put($this->transferKey($transferId), $normalized, now()->addSeconds(self::TTL_SECONDS));
    }

    /**
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
    public function getForTransfer(int $transferId): array
    {
        $value = Cache::get($this->transferKey($transferId));

        return is_array($value) ? $this->normalizeContext($value) : [];
    }

    public function forgetForTransfer(int $transferId): void
    {
        Cache::forget($this->transferKey($transferId));
    }

    /**
     * @param  array<string, mixed>  $context
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
    private function normalizeContext(array $context): array
    {
        $normalized = [];

        $cookies = $this->normalizeCookies($context['cookies'] ?? null);
        if ($cookies !== []) {
            $normalized['cookies'] = $cookies;
        }

        $userAgent = trim((string) ($context['user_agent'] ?? ''));
        $userAgent = $this->stripControlCharacters($userAgent);
        if ($userAgent !== '') {
            $normalized['user_agent'] = mb_substr($userAgent, 0, 1000);
        }

        return $normalized;
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
    private function normalizeCookies(mixed $cookies): array
    {
        if (! is_array($cookies)) {
            return [];
        }

        $normalized = [];

        foreach ($cookies as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $name = trim((string) ($entry['name'] ?? ''));
            $domain = ltrim(strtolower(trim((string) ($entry['domain'] ?? ''))), '.');
            if ($name === '' || $domain === '' || preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) !== 1) {
                continue;
            }

            $path = trim((string) ($entry['path'] ?? '/'));
            if ($path === '') {
                $path = '/';
            } elseif (! str_starts_with($path, '/')) {
                $path = '/'.$path;
            }

            $value = $this->stripControlCharacters((string) ($entry['value'] ?? ''));
            $expiresAt = null;
            if (isset($entry['expires_at']) && is_numeric($entry['expires_at'])) {
                $expiresAt = max(0, (int) $entry['expires_at']);
            }

            $normalized[] = [
                'name' => mb_substr($name, 0, 255),
                'value' => mb_substr($value, 0, 4096),
                'domain' => mb_substr($domain, 0, 255),
                'path' => mb_substr($path, 0, 2048),
                'secure' => ($entry['secure'] ?? false) === true,
                'http_only' => ($entry['http_only'] ?? false) === true,
                'host_only' => ($entry['host_only'] ?? false) === true,
                'expires_at' => $expiresAt,
            ];

            if (count($normalized) >= 300) {
                break;
            }
        }

        return $normalized;
    }

    private function stripControlCharacters(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $value));
    }

    private function transferKey(int $transferId): string
    {
        return self::KEY_PREFIX.$transferId;
    }
}
