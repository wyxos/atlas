<?php

namespace App\Services\Downloads;

use Illuminate\Support\Facades\Cache;

final class DownloadTransferRuntimeStore
{
    private const string KEY_PREFIX = 'downloads:transfer-runtime:';

    private const int TTL_SECONDS = 21600;

    /**
     * @param  array{cookies?: string, user_agent?: string}  $context
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
     * @return array{cookies?: string, user_agent?: string}
     */
    public function getForTransfer(int $transferId): array
    {
        $value = Cache::get($this->transferKey($transferId));

        return is_array($value) ? $this->normalizeContext($value) : [];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{cookies?: string, user_agent?: string}
     */
    private function normalizeContext(array $context): array
    {
        $normalized = [];

        $cookies = trim((string) ($context['cookies'] ?? ''));
        $cookies = $this->stripControlCharacters($cookies);
        if ($cookies !== '') {
            $normalized['cookies'] = mb_substr($cookies, 0, 20000);
        }

        $userAgent = trim((string) ($context['user_agent'] ?? ''));
        $userAgent = $this->stripControlCharacters($userAgent);
        if ($userAgent !== '') {
            $normalized['user_agent'] = mb_substr($userAgent, 0, 1000);
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
