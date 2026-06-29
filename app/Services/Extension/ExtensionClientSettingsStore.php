<?php

namespace App\Services\Extension;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ExtensionClientSettingsStore
{
    private const SETTINGS_KEY = 'extension.client_settings.v1';

    public function get(User $user): ?array
    {
        $value = DB::table('settings')
            ->where('key', self::SETTINGS_KEY)
            ->where('machine', $this->machineForUser($user))
            ->value('value');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $settings = json_decode($value, true);

        return is_array($settings) ? $settings : null;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function put(User $user, array $settings): array
    {
        $settings = $this->normalizeRedactedSecrets($settings);

        DB::table('settings')->updateOrInsert(
            [
                'key' => self::SETTINGS_KEY,
                'machine' => $this->machineForUser($user),
            ],
            [
                'value' => json_encode($settings, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return $settings;
    }

    private function machineForUser(User $user): string
    {
        return 'user:'.((int) $user->id);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function normalizeRedactedSecrets(array $settings): array
    {
        foreach ($settings as $key => $value) {
            if ($key === 'apiKey' && $value === null) {
                $settings[$key] = '';

                continue;
            }

            if (is_array($value)) {
                $settings[$key] = $this->normalizeRedactedSecrets($value);
            }
        }

        return $settings;
    }
}
