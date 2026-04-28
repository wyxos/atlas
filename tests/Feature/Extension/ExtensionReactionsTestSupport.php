<?php

if (! function_exists('setExtensionReactionApiKey')) {
    function setExtensionReactionApiKey(string $value, ?int $userId = null): void
    {
        \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert([
            'key' => 'extension.api_key_hash',
            'machine' => '',
        ], [
            'value' => hash('sha256', $value),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($userId !== null) {
            \Illuminate\Support\Facades\DB::table('settings')->updateOrInsert([
                'key' => 'extension.api_key_user_id',
                'machine' => '',
            ], [
                'value' => (string) $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
