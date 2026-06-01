<?php

namespace App\Services\Extension;

use Illuminate\Support\Facades\DB;

class ExtensionSettingsService
{
    private const SETTINGS_KEY = 'extension.settings';

    private const SETTINGS_VERSION = 1;

    private const SETTINGS_MACHINE_PREFIX = 'user:';

    private const CLOSE_TAB_AFTER_QUEUE_MODES = ['queued', 'completed'];

    private const MEDIA_CLEANER_STRATEGIES = ['civitaiCanonical'];

    /**
     * @return array{
     *     version: int,
     *     siteCustomizations: list<array<string, mixed>>,
     *     closeTabAfterQueueByDomain: array<string, string>,
     *     reactAllItemsInPostByDomain: array<string, bool>
     * }
     */
    public function forUser(int $userId): array
    {
        $value = DB::table('settings')
            ->where('key', self::SETTINGS_KEY)
            ->where('machine', $this->machineForUser($userId))
            ->value('value');

        if (! is_string($value) || trim($value) === '') {
            return $this->defaultSettings();
        }

        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return $this->defaultSettings();
        }

        return $this->normalizeSettings($decoded);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{
     *     version: int,
     *     siteCustomizations: list<array<string, mixed>>,
     *     closeTabAfterQueueByDomain: array<string, string>,
     *     reactAllItemsInPostByDomain: array<string, bool>
     * }
     */
    public function saveForUser(int $userId, array $settings): array
    {
        $normalized = $this->normalizeSettings($settings);
        $now = now();

        DB::table('settings')->updateOrInsert(
            [
                'key' => self::SETTINGS_KEY,
                'machine' => $this->machineForUser($userId),
            ],
            [
                'value' => json_encode($normalized, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        return $normalized;
    }

    /**
     * @return array{
     *     version: int,
     *     siteCustomizations: list<array<string, mixed>>,
     *     closeTabAfterQueueByDomain: array<string, string>,
     *     reactAllItemsInPostByDomain: array<string, bool>
     * }
     */
    private function defaultSettings(): array
    {
        return [
            'version' => self::SETTINGS_VERSION,
            'siteCustomizations' => [],
            'closeTabAfterQueueByDomain' => [],
            'reactAllItemsInPostByDomain' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{
     *     version: int,
     *     siteCustomizations: list<array<string, mixed>>,
     *     closeTabAfterQueueByDomain: array<string, string>,
     *     reactAllItemsInPostByDomain: array<string, bool>
     * }
     */
    private function normalizeSettings(array $settings): array
    {
        return [
            'version' => self::SETTINGS_VERSION,
            'siteCustomizations' => $this->normalizeSiteCustomizations($settings['siteCustomizations'] ?? []),
            'closeTabAfterQueueByDomain' => $this->normalizeCloseTabAfterQueueByDomain(
                $settings['closeTabAfterQueueByDomain'] ?? []
            ),
            'reactAllItemsInPostByDomain' => $this->normalizeDomainBooleanPreferences(
                $settings['reactAllItemsInPostByDomain'] ?? []
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeSiteCustomizations(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $byDomain = [];
        foreach ($value as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $domain = $this->normalizeDomainKey($entry['domain'] ?? '');
            if ($domain === '') {
                continue;
            }

            $byDomain[$domain] = [
                'enabled' => ($entry['enabled'] ?? true) !== false,
                'domain' => $domain,
                'matchRules' => $this->normalizeStringList($entry['matchRules'] ?? []),
                'widget' => [
                    'minImageWidth' => $this->normalizeNullableInteger(
                        data_get($entry, 'widget.minImageWidth')
                    ),
                ],
                'referrerCleaner' => [
                    'stripQueryParams' => $this->normalizeQueryParams(
                        data_get($entry, 'referrerCleaner.stripQueryParams')
                    ),
                ],
                'mediaCleaner' => [
                    'stripQueryParams' => $this->normalizeQueryParams(
                        data_get($entry, 'mediaCleaner.stripQueryParams')
                    ),
                    'rewriteRules' => $this->normalizeMediaRewriteRules(
                        data_get($entry, 'mediaCleaner.rewriteRules')
                    ),
                    'strategies' => $this->normalizeMediaCleanerStrategies(
                        data_get($entry, 'mediaCleaner.strategies')
                    ),
                ],
            ];
        }

        ksort($byDomain);

        return array_values($byDomain);
    }

    /**
     * @return array<string, string>
     */
    private function normalizeCloseTabAfterQueueByDomain(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $rawDomain => $rawMode) {
            $domain = $this->normalizeDomainKey($rawDomain);
            if ($domain === '') {
                continue;
            }

            if ($rawMode === true) {
                $normalized[$domain] = 'queued';

                continue;
            }

            if (is_string($rawMode) && in_array($rawMode, self::CLOSE_TAB_AFTER_QUEUE_MODES, true)) {
                $normalized[$domain] = $rawMode;
            }
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @return array<string, bool>
     */
    private function normalizeDomainBooleanPreferences(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $rawDomain => $rawEnabled) {
            $domain = $this->normalizeDomainKey($rawDomain);
            if ($domain === '' || ! is_bool($rawEnabled)) {
                continue;
            }

            $normalized[$domain] = $rawEnabled;
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $trimmed = trim($entry);
            if ($trimmed !== '') {
                $normalized[$trimmed] = $trimmed;
            }
        }

        return array_values($normalized);
    }

    /**
     * @return list<string>
     */
    private function normalizeQueryParams(mixed $value): array
    {
        $params = array_map(
            static fn (string $entry): string => strtolower($entry),
            $this->normalizeStringList($value)
        );

        if (in_array('*', $params, true)) {
            return ['*'];
        }

        $deduped = [];
        foreach ($params as $param) {
            $deduped[$param] = $param;
        }

        return array_values($deduped);
    }

    /**
     * @return list<array{pattern: string, replace: string}>
     */
    private function normalizeMediaRewriteRules(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $entry) {
            if (! is_array($entry) || ! is_string($entry['pattern'] ?? null)) {
                continue;
            }

            $pattern = trim($entry['pattern']);
            if ($pattern === '') {
                continue;
            }

            $replace = is_string($entry['replace'] ?? null) ? $entry['replace'] : '';
            $key = $pattern."\0".$replace;
            $normalized[$key] = [
                'pattern' => $pattern,
                'replace' => $replace,
            ];
        }

        return array_values($normalized);
    }

    /**
     * @return list<string>
     */
    private function normalizeMediaCleanerStrategies(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $entry) {
            if (! is_string($entry) || ! in_array($entry, self::MEDIA_CLEANER_STRATEGIES, true)) {
                continue;
            }

            $normalized[$entry] = $entry;
        }

        return array_values($normalized);
    }

    private function normalizeNullableInteger(mixed $value): ?int
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        $number = (float) $value;
        if (! is_finite($number) || $number < 0) {
            return null;
        }

        return (int) floor($number);
    }

    private function normalizeDomainKey(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $trimmed = strtolower(trim($value));
        if ($trimmed === '') {
            return '';
        }

        if (str_contains($trimmed, '://')) {
            $host = parse_url($trimmed, PHP_URL_HOST);

            return is_string($host) ? strtolower(trim($host, '.')) : '';
        }

        return trim($trimmed, '.');
    }

    private function machineForUser(int $userId): string
    {
        return self::SETTINGS_MACHINE_PREFIX.$userId;
    }
}
