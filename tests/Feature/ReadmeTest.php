<?php

it('documents Atlas with current home page screenshots', function () {
    $readme = file_get_contents(base_path('README.md'));

    expect($readme)
        ->toContain('Private media library, feed triage, and personal archive operations.')
        ->toContain('Triage noisy media feeds without losing the good stuff.')
        ->toContain('## Screenshots')
        ->toContain('## Development')
        ->not->toContain('media curation')
        ->not->toContain('curation workflows');

    foreach ([
        'public/home/dashboard-hero.png',
        'public/home/browse-civitai-most-reactions.png',
        'public/home/browse-deviantart.png',
        'public/home/browse-full-view.png',
    ] as $screenshotPath) {
        expect($readme)->toContain($screenshotPath);
        expect(file_exists(base_path($screenshotPath)))->toBeTrue();
    }
});
