<?php

use App\Jobs\BackfillExtensionAssetMatchIdentities;
use App\Models\ExtensionAssetMatchIdentity;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('extension asset match identity backfill creates identities without rewriting raw urls', function () {
    $file = File::factory()->create([
        'referrer_url' => 'https://www.facebook.com/photo/?fbid=122099716773370530&set=a.1',
        'url' => 'https://scontent.example.test/v/t39/photo.jpg?oh=stored-token',
    ]);

    (new BackfillExtensionAssetMatchIdentities([
        'cleanup' => [[
            'query_params' => ['fbid'],
            'type' => 'keep_query_params',
        ]],
        'domain' => 'facebook.com',
        'match_by' => 'referrer',
        'rule_digest' => 'facebook-photo-fbid-v1',
        'rule_id' => 'facebook-photo-fbid',
    ], chunk: 50))->handle(app(\App\Services\Extension\ExtensionAssetMatchIdentityService::class));

    $file->refresh();

    expect($file->url)->toBe('https://scontent.example.test/v/t39/photo.jpg?oh=stored-token');
    expect($file->referrer_url)->toBe('https://www.facebook.com/photo/?fbid=122099716773370530&set=a.1');
    expect(ExtensionAssetMatchIdentity::query()
        ->where('file_id', $file->id)
        ->where('match_by', 'referrer')
        ->where('match_url', 'https://www.facebook.com/photo/?fbid=122099716773370530')
        ->exists())->toBeTrue();
});
