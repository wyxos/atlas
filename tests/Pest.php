<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

uses(Tests\TestCase::class)
    ->beforeEach(function (): void {
        config([
            'services.audio_metadata.vgmdb_enabled' => false,
            'services.audio_metadata.spotify_catalog_enabled' => false,
            'services.audio_metadata.apple_enabled' => false,
            'services.audio_metadata.deezer_enabled' => false,
        ]);
    })
    ->in('Feature', 'Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function configureLibraryScanStorage(): string
{
    $root = storage_path('framework/testing/library-scan-'.Illuminate\Support\Str::random(10));

    if (is_dir($root)) {
        Illuminate\Support\Facades\File::deleteDirectory($root);
    }

    config()->set('atlas.root', $root);
    config()->set('filesystems.disks.atlas.root', $root.DIRECTORY_SEPARATOR.'.app');
    Illuminate\Support\Facades\Storage::forgetDisk(['atlas']);

    Illuminate\Support\Facades\File::ensureDirectoryExists($root);
    Illuminate\Support\Facades\File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'.app');

    return $root;
}

function audioMetadataAiResponse(array $payload, string $model = 'qwen-test'): mixed
{
    return Illuminate\Support\Facades\Http::response([
        'id' => 'resp_test',
        'status' => 'completed',
        'model' => $model,
        'output' => [[
            'type' => 'message',
            'status' => 'completed',
            'content' => [[
                'type' => 'output_text',
                'text' => json_encode($payload),
            ]],
        ]],
        'usage' => [
            'input_tokens' => 12,
            'output_tokens' => 8,
            'input_tokens_details' => [
                'cached_tokens' => 0,
            ],
            'output_tokens_details' => [
                'reasoning_tokens' => 0,
            ],
        ],
    ]);
}

function audioMetadataAiSchemaVersion(Illuminate\Http\Client\Request $request): string
{
    return (string) data_get($request->data(), 'metadata.schema_version', '');
}

function audioMetadataAiPrompt(Illuminate\Http\Client\Request $request): string
{
    return (string) data_get($request->data(), 'input.1.content.0.text', '');
}
