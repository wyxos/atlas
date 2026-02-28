<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExtensionApiKeyService
{
    private const SETTINGS_KEY = 'extension.api_key_hash';

    private const SETTINGS_USER_ID_KEY = 'extension.api_key_user_id';

    private const SETTINGS_MACHINE = '';

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

        $userId = $this->storedUserId();
        if ($userId === null) {
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

            return $fallbackUser;
        }

        return User::query()->select('id')->find($userId);
    }

    private function storedHash(): ?string
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

    private function storedUserId(): ?int
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
}
