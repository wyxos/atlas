<?php

function setMediaProcessorConfigEnv(string $key, ?string $value): void
{
    if ($value === null) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);

        return;
    }

    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

function loadMediaProcessorConfig(): array
{
    return require dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'media_processor.php';
}

function legacyMediaProcessorEnvNames(): array
{
    return [
        'enabled' => 'ATLAS_'.'MEDIA_PROCESSOR_ENABLED',
        'url' => 'ATLAS_'.'MEDIA_PROCESSOR_URL',
        'secret' => 'ATLAS_'.'MEDIA_PROCESSOR_SECRET',
        'instance' => 'ATLAS_'.'MEDIA_PROCESSOR_INSTANCE',
        'storage_profile' => 'ATLAS_'.'MEDIA_PROCESSOR_STORAGE_PROFILE',
        'timeout_seconds' => 'ATLAS_'.'MEDIA_PROCESSOR_TIMEOUT_SECONDS',
        'websocket_required' => 'ATLAS_'.'MEDIA_PROCESSOR_WEBSOCKET_REQUIRED',
        'stale_task_minutes' => 'ATLAS_'.'MEDIA_PROCESSOR_STALE_TASK_MINUTES',
    ];
}

beforeEach(function () {
    $this->mediaProcessorEnvKeys = [
        'MEDIA_PROCESSOR',
        'MEDIA_PROCESSOR_URL',
        'MEDIA_PROCESSOR_SECRET',
        'MEDIA_PROCESSOR_INSTANCE',
        'MEDIA_PROCESSOR_STORAGE_PROFILE',
        'MEDIA_PROCESSOR_TIMEOUT_SECONDS',
        'MEDIA_PROCESSOR_WEBSOCKET_REQUIRED',
        'MEDIA_PROCESSOR_STALE_TASK_MINUTES',
        ...array_values(legacyMediaProcessorEnvNames()),
    ];

    $this->originalMediaProcessorEnv = [];

    foreach ($this->mediaProcessorEnvKeys as $key) {
        $this->originalMediaProcessorEnv[$key] = getenv($key);
        setMediaProcessorConfigEnv($key, null);
    }
});

afterEach(function () {
    foreach ($this->originalMediaProcessorEnv as $key => $value) {
        setMediaProcessorConfigEnv($key, $value === false ? null : $value);
    }
});

it('maps media processor config to unprefixed env names', function () {
    setMediaProcessorConfigEnv('MEDIA_PROCESSOR', 'true');
    setMediaProcessorConfigEnv('MEDIA_PROCESSOR_URL', 'https://processor.test');
    setMediaProcessorConfigEnv('MEDIA_PROCESSOR_SECRET', 'test-secret');
    setMediaProcessorConfigEnv('MEDIA_PROCESSOR_INSTANCE', 'worker-a');
    setMediaProcessorConfigEnv('MEDIA_PROCESSOR_STORAGE_PROFILE', 'atlas-shared');
    setMediaProcessorConfigEnv('MEDIA_PROCESSOR_TIMEOUT_SECONDS', '45');
    setMediaProcessorConfigEnv('MEDIA_PROCESSOR_WEBSOCKET_REQUIRED', 'false');
    setMediaProcessorConfigEnv('MEDIA_PROCESSOR_STALE_TASK_MINUTES', '17');

    expect(loadMediaProcessorConfig())->toMatchArray([
        'enabled' => true,
        'url' => 'https://processor.test',
        'secret' => 'test-secret',
        'instance' => 'worker-a',
        'storage_profile' => 'atlas-shared',
        'timeout_seconds' => 45,
        'websocket_required' => false,
        'stale_task_minutes' => 17,
    ]);
});

it('does not use legacy atlas-prefixed media processor env names', function () {
    $legacyNames = legacyMediaProcessorEnvNames();

    setMediaProcessorConfigEnv($legacyNames['enabled'], 'true');
    setMediaProcessorConfigEnv($legacyNames['url'], 'https://legacy-processor.test');
    setMediaProcessorConfigEnv($legacyNames['secret'], 'legacy-secret');
    setMediaProcessorConfigEnv($legacyNames['instance'], 'legacy-worker');
    setMediaProcessorConfigEnv($legacyNames['storage_profile'], 'legacy-storage');
    setMediaProcessorConfigEnv($legacyNames['timeout_seconds'], '99');
    setMediaProcessorConfigEnv($legacyNames['websocket_required'], 'false');
    setMediaProcessorConfigEnv($legacyNames['stale_task_minutes'], '99');

    expect(loadMediaProcessorConfig())->toMatchArray([
        'enabled' => false,
        'url' => null,
        'secret' => null,
        'instance' => env('APP_ENV', 'local'),
        'storage_profile' => 'atlas-local',
        'timeout_seconds' => 15,
        'websocket_required' => true,
        'stale_task_minutes' => 5,
    ]);
});
