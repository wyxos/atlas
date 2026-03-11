<?php

use App\Jobs\NormalizeReferrerUrls;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('normalize referrer urls job strips all query params for matching domains and updates hashes', function () {
    $matching = File::factory()->create([
        'referrer_url' => 'https://domain.com/?id=123&tag=blue+sky',
    ]);

    $matchingSubdomain = File::factory()->create([
        'referrer_url' => 'https://cdn.domain.com/post/view?tag=blue&tags=blue+sky#image-2',
    ]);

    $nonMatching = File::factory()->create([
        'referrer_url' => 'https://other.example.com/?id=123&tag=blue',
    ]);

    $job = new NormalizeReferrerUrls(
        domain: 'domain.com',
        queryParamsToStrip: ['*'],
        afterId: 0,
        chunk: 20,
        queueName: 'processing',
    );

    app()->call([$job, 'handle']);

    $matching->refresh();
    $matchingSubdomain->refresh();
    $nonMatching->refresh();

    expect($matching->referrer_url)->toBe('https://domain.com/')
        ->and($matching->referrer_url_hash)->toBe(hash('sha256', 'https://domain.com/'));

    expect($matchingSubdomain->referrer_url)->toBe('https://cdn.domain.com/post/view#image-2')
        ->and($matchingSubdomain->referrer_url_hash)->toBe(hash('sha256', 'https://cdn.domain.com/post/view#image-2'));

    expect($nonMatching->referrer_url)->toBe('https://other.example.com/?id=123&tag=blue');
});

test('normalize referrer urls job queues the next chunk when more matching files remain', function () {
    Bus::fake();

    $first = File::factory()->create([
        'referrer_url' => 'https://domain.com/?id=1&tag=blue',
    ]);

    $second = File::factory()->create([
        'referrer_url' => 'https://domain.com/?id=2&tag=green',
    ]);

    File::factory()->create([
        'referrer_url' => 'https://domain.com/?id=3&tag=red',
    ]);

    $job = new NormalizeReferrerUrls(
        domain: 'domain.com',
        queryParamsToStrip: ['tag'],
        afterId: 0,
        chunk: 2,
        queueName: 'maintenance',
    );

    app()->call([$job, 'handle']);

    $first->refresh();
    $second->refresh();

    expect($first->referrer_url)->toBe('https://domain.com/?id=1');
    expect($second->referrer_url)->toBe('https://domain.com/?id=2');

    Bus::assertDispatched(NormalizeReferrerUrls::class, function (NormalizeReferrerUrls $job) use ($second): bool {
        return $job->domain === 'domain.com'
            && $job->queryParamsToStrip === ['tag']
            && $job->afterId === $second->id
            && $job->chunk === 2
            && $job->queueName === 'maintenance';
    });
});
