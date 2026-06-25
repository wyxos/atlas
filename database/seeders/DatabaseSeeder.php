<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\ExtensionApiKeyService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public const LOCAL_EXTENSION_API_KEY = 'atlas_local_development_key';

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $demoUser = User::query()->updateOrCreate(
            ['email' => 'demo@atlas.test'],
            [
                'name' => 'Demo User',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'is_admin' => true,
            ],
        );

        app(ExtensionApiKeyService::class)->save(self::LOCAL_EXTENSION_API_KEY, (int) $demoUser->id);

        // Seed additional users using factory
        User::factory()->count(25)->create();

        $this->call([
            AudioDevelopmentSeeder::class,
        ]);
    }
}
