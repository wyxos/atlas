<?php

use App\Models\File;
use App\Models\FileMetadata;
use App\Services\CivitAiImages;
use App\Support\PhotoContainers;
use Atlas\Plugin\Contracts\ServiceRegistry;

beforeEach(function () {
    /** @var ServiceRegistry $registry */
    $registry = app(ServiceRegistry::class);
    if (! $registry->get(CivitAiImages::key())) {
        $registry->register(app(CivitAiImages::class));
    }
});

it('returns containers for service-backed files', function () {
    $file = File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => 'abc-123',
        'listing_metadata' => [
            'postId' => 123,
            'username' => 'atlas-user',
            'baseModel' => 'Foo v1',
        ],
    ]);

    FileMetadata::create([
        'file_id' => $file->id,
        'payload' => [
            'width' => 512,
            'height' => 512,
        ],
    ]);

    $registry = app(ServiceRegistry::class);
    expect($registry->all())->not->toBeEmpty();
    expect(collect($registry->all())->map(fn ($service) => strtolower($service::source()))->values())->toContain('civitai');

    $file = $file->fresh('metadata');
    expect($file->listing_metadata)->toMatchArray([
        'postId' => 123,
        'username' => 'atlas-user',
        'baseModel' => 'Foo v1',
    ]);

    $serviceContainers = app(CivitAiImages::class)->containers($file->listing_metadata, $file->metadata?->payload ?? []);
    expect($serviceContainers)->toHaveCount(3);

    $resolver = new \ReflectionMethod(PhotoContainers::class, 'resolveService');
    $resolver->setAccessible(true);
    $service = $resolver->invoke(null, $file);
    expect($service)->toBeInstanceOf(CivitAiImages::class);

    $containers = PhotoContainers::forFile($file);

    expect($containers)->toHaveCount(3)
        ->sequence(
            fn ($first) => $first->toMatchArray([
                'key' => 'postId',
                'label' => 'post',
                'value' => 123,
            ]),
            fn ($second) => $second->toMatchArray([
                'key' => 'username',
                'label' => 'user',
                'value' => 'atlas-user',
            ]),
            fn ($third) => $third->toMatchArray([
                'key' => 'baseModel',
                'label' => 'model',
                'value' => 'Foo v1',
            ]),
        );
});

it('returns an empty list for non service sources', function () {
    $file = File::factory()->create([
        'source' => 'local',
        'listing_metadata' => null,
    ]);

    $containers = PhotoContainers::forFile($file);

    expect($containers)->toBeArray()->toBeEmpty();
});
