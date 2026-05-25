<?php

use App\Models\User;
use App\Services\SettingsInfrastructureHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guest cannot view infrastructure health', function () {
    $response = $this->getJson('/api/settings/infrastructure-health');

    $response->assertUnauthorized();
});

test('authenticated user can view infrastructure health', function () {
    $user = User::factory()->create();

    $this->app->instance(
        SettingsInfrastructureHealthService::class,
        new class extends SettingsInfrastructureHealthService
        {
            public function __construct() {}

            public function check(): array
            {
                return [
                    'checked_at' => '2026-05-25T11:00:00.000000Z',
                    'typesense' => [
                        'ok' => true,
                        'status' => 'healthy',
                        'endpoint' => 'http://typesense.test:8108',
                        'message' => 'Typesense health endpoint responded.',
                        'latency_ms' => 12,
                        'response_ok' => true,
                    ],
                    'storage' => [
                        'ok' => true,
                        'status' => 'healthy',
                        'disk' => 'atlas',
                        'root' => 'D:\\Atlas',
                        'app_root' => 'D:\\Atlas\\.app',
                        'root_exists' => true,
                        'app_root_exists' => true,
                        'readable' => true,
                        'writable' => true,
                        'write_probe' => true,
                        'read_probe' => true,
                        'delete_probe' => true,
                        'free_bytes' => 1024,
                        'total_bytes' => 2048,
                        'namespaces' => [
                            ['name' => 'downloads', 'exists' => true],
                            ['name' => 'imports', 'exists' => true],
                        ],
                        'message' => 'Atlas storage accepted a write/read/delete probe.',
                        'latency_ms' => 8,
                    ],
                ];
            }
        },
    );

    $response = $this->actingAs($user)->getJson('/api/settings/infrastructure-health');

    $response->assertSuccessful();
    $response->assertJsonPath('typesense.status', 'healthy');
    $response->assertJsonPath('typesense.endpoint', 'http://typesense.test:8108');
    $response->assertJsonPath('storage.status', 'healthy');
    $response->assertJsonPath('storage.write_probe', true);
    $response->assertJsonPath('storage.namespaces.0.name', 'downloads');
});
