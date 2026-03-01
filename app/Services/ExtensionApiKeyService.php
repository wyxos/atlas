<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExtensionApiKeyService
{
    private const SETTINGS_KEY = 'extension.api_key_hash';

    private const SETTINGS_USER_ID_KEY = 'extension.api_key_user_id';

    private const SETTINGS_MACHINE = '';

    private const CACHE_STORED_HASH_KEY = 'extension:api-key:stored-hash';

    private const CACHE_STORED_USER_ID_KEY = 'extension:api-key:stored-user-id';

    private const CACHE_RESOLVED_USER_ID_KEY = 'extension:api-key:resolved-user-id';

    private const CACHE_TTL_SECONDS = 15;

    public function isConfigured(): bool
    {
        return $this->storedHash() !== null;
    }

    public function save(string $rawApiKey, int $userId): void
    {
        $hash = hash('sha256', $rawApiKey);
        $now = now();

        DB::table('settings')->updateOrInsert(
            [
                'key' => self::SETTINGS_KEY,
                'machine' => self::SETTINGS_MACHINE,
            ],
            [
                'value' => $hash,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        DB::table('settings')->updateOrInsert(
            [
                'key' => self::SETTINGS_USER_ID_KEY,
                'machine' => self::SETTINGS_MACHINE,
            ],
            [
                'value' => (string) $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $this->invalidateCache();
    }

    public function generateAndSave(int $userId): string
    {
        $generatedKey = 'atlas_'.Str::random(48);
        $this->save($generatedKey, $userId);

        return $generatedKey;
    }

    public function matches(string $rawApiKey): bool
    {
        $storedHash = $this->storedHash();
        if ($storedHash !== null && $storedHash !== '') {
            return hash_equals($storedHash, hash('sha256', $rawApiKey));
        }

        return false;
    }

    public function resolveUserForApiKey(string $rawApiKey): ?User
    {
        if (! $this->matches($rawApiKey)) {
            return null;
        }

        $userId = $this->resolvedUserId();
        if ($userId === null) {
            return null;
        }

        return User::query()->select('id')->find($userId);
    }

    private function storedHash(): ?string
    {
        $cached = Cache::remember(
            self::CACHE_STORED_HASH_KEY,
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            fn (): array => ['value' => $this->readStoredHash()],
        );

        $value = $cached['value'] ?? null;

        return is_string($value) ? $value : null;
    }

    private function storedUserId(): ?int
    {
        $cached = Cache::remember(
            self::CACHE_STORED_USER_ID_KEY,
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            fn (): array => ['value' => $this->readStoredUserId()],
        );

        $value = $cached['value'] ?? null;

        return is_int($value) ? $value : null;
    }

    private function resolvedUserId(): ?int
    {
        $cached = Cache::remember(
            self::CACHE_RESOLVED_USER_ID_KEY,
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            fn (): array => ['value' => $this->readResolvedUserId()],
        );

        $value = $cached['value'] ?? null;

        return is_int($value) ? $value : null;
    }

    private function readStoredHash(): ?string
    {
        $value = DB::table('settings')
            ->where('key', self::SETTINGS_KEY)
            ->where('machine', self::SETTINGS_MACHINE)
            ->value('value');

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function readStoredUserId(): ?int
    {
        $value = DB::table('settings')
            ->where('key', self::SETTINGS_USER_ID_KEY)
            ->where('machine', self::SETTINGS_MACHINE)
            ->value('value');

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || ! ctype_digit($trimmed)) {
            return null;
        }

        $userId = (int) $trimmed;

        return $userId > 0 ? $userId : null;
    }

    private function readResolvedUserId(): ?int
    {
        $storedUserId = $this->storedUserId();
        if ($storedUserId !== null) {
            return $storedUserId;
        }

        $fallbackUser = User::query()
            ->select('id')
            ->orderBy('id')
            ->first();

        if (! $fallbackUser) {
            return null;
        }

        DB::table('settings')->updateOrInsert(
            [
                'key' => self::SETTINGS_USER_ID_KEY,
                'machine' => self::SETTINGS_MACHINE,
            ],
            [
                'value' => (string) $fallbackUser->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        Cache::forget(self::CACHE_STORED_USER_ID_KEY);

        return $fallbackUser->id;
    }

    private function invalidateCache(): void
    {
        Cache::forget(self::CACHE_STORED_HASH_KEY);
        Cache::forget(self::CACHE_STORED_USER_ID_KEY);
        Cache::forget(self::CACHE_RESOLVED_USER_ID_KEY);
    }
}
