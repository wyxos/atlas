<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ExtensionApiKeyService
{
    private const SETTINGS_KEY = 'extension.api_key_hash';

    private const SETTINGS_MACHINE = '';

    public function isConfigured(): bool
    {
        return $this->storedHash() !== null || $this->legacyToken() !== '';
    }

    public function save(string $rawApiKey): void
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
    }

    public function matches(string $rawApiKey): bool
    {
        $storedHash = $this->storedHash();
        if ($storedHash !== null && $storedHash !== '') {
            return hash_equals($storedHash, hash('sha256', $rawApiKey));
        }

        $legacyToken = $this->legacyToken();
        if ($legacyToken === '') {
            return false;
        }

        return hash_equals($legacyToken, $rawApiKey);
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

    private function legacyToken(): string
    {
        return trim((string) config('downloads.extension_token', ''));
    }
}
