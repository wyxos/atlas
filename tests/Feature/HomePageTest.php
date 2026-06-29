<?php

it('renders the section-based public home page with hash navigation hooks', function () {
    $response = $this->get('/');

    $response
        ->assertOk()
        ->assertSeeInOrder([
            'Hero',
            'What it does',
            'Supported sources',
            'Extension',
            'Import',
            'Moderation',
            'Keyboard flow',
            'Playback',
        ], false)
        ->assertSee('Triage noisy media feeds without losing the good stuff.')
        ->assertSee('Private media library')
        ->assertSee('Browse external sources and local folders, react fast, auto-save what matters, and keep a searchable private library behind one dashboard.')
        ->assertSee('External feeds')
        ->assertSee('Local imports')
        ->assertSee('Reactions + moderation')
        ->assertSee('id="atlas-home"', false)
        ->assertSee('data-home-scroller', false)
        ->assertSee('home/dashboard-hero.png', false)
        ->assertSee('id="atlas-home-screenshot-carousel"', false)
        ->assertSee('home/browse-civitai-most-reactions.png', false)
        ->assertSee('browse-deviantart.png', false)
        ->assertSee('browse-full-view.png', false)
        ->assertSee('--atlas-home-surface', false)
        ->assertSee('scroll-snap-type: y mandatory', false)
        ->assertSee('window.history.replaceState', false)
        ->assertSee('CivitAI', false)
        ->assertSee('DeviantArt', false)
        ->assertSee('Wallhaven', false)
        ->assertSee('Local files', false)
        ->assertDontSee('Media operations, organized.', false)
        ->assertDontSee('One private workspace for feeds, imports, reactions, transfers, metadata, and playback.', false)
        ->assertDontSee('What is Atlas', false)
        ->assertDontSee('Private media curation')
        ->assertDontSee('curation')
        ->assertDontSee('Atlas badge', false)
        ->assertDontSee('Bring unmanaged files into Atlas storage', false)
        ->assertDontSee('Live queue', false)
        ->assertDontSee('mx-auto', false);

    $body = preg_replace('/^.*<body[^>]*>|<\/body>.*$/s', '', $response->getContent());
    $visibleText = strip_tags(preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $body));

    preg_match_all('/\bAtlas\b/', html_entity_decode($visibleText), $matches);

    expect($matches[0])->toHaveCount(4);

    foreach (['CivitAI', 'DeviantArt', 'Wallhaven'] as $sourceName) {
        preg_match_all('/\b'.preg_quote($sourceName, '/').'\b/', html_entity_decode($visibleText), $sourceMatches);

        expect($sourceMatches[0])->toHaveCount(1);
    }
});
