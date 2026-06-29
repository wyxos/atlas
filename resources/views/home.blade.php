<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'Atlas') }}</title>
        <meta name="description" content="Atlas is a private media library for triaging noisy feeds, saving what matters, and keeping a searchable personal archive.">

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.ts'])
        @endif

        @include('partials.favicons')
        @include('partials.google-analytics')

        <style>
            html {
                scroll-behavior: smooth;
            }

            body {
                overflow: hidden;
            }

            .atlas-home-scroller {
                height: 100svh;
                overflow-y: auto;
                scroll-behavior: smooth;
                scroll-snap-type: y mandatory;
            }

            .atlas-home-section {
                --atlas-home-surface: var(--color-prussian-blue-900);

                background: var(--atlas-home-surface);
                height: 100svh;
                min-height: 100svh;
                overflow: hidden;
                scroll-snap-align: start;
                scroll-snap-stop: always;
                opacity: 0.42;
                transform: translateY(1.5rem) scale(0.985);
                transition: opacity 500ms ease-in-out, transform 500ms ease-in-out;
            }

            .atlas-home-section.is-active {
                opacity: 1;
                transform: translateY(0) scale(1);
            }

            .atlas-hero-section {
                background: var(--atlas-home-surface);
            }

            .atlas-hero-section .atlas-section-media {
                transform: none;
                transition: none;
            }

            .atlas-section-media {
                min-height: min(46svh, 30rem);
                overflow: hidden;
                transform: translateY(1rem);
                transition: transform 500ms ease-in-out;
            }

            .atlas-home-section.is-active .atlas-section-media {
                transform: translateY(0);
            }

            .atlas-dashboard-screenshot-frame {
                position: relative;
                background: var(--atlas-home-surface);
                box-shadow: 0 2rem 7rem color-mix(in srgb, var(--atlas-home-surface) 72%, transparent);
                isolation: isolate;
            }

            .atlas-dashboard-screenshot-frame::after {
                position: absolute;
                inset: 0;
                z-index: 1;
                background:
                    linear-gradient(90deg, var(--atlas-home-surface) 0%, transparent 6%, transparent 94%, var(--atlas-home-surface) 100%),
                    linear-gradient(180deg, var(--atlas-home-surface) 0%, transparent 7%, transparent 93%, var(--atlas-home-surface) 100%);
                content: "";
                pointer-events: none;
            }

            .atlas-dashboard-screenshot {
                height: 100%;
                min-height: inherit;
                width: 100%;
                object-fit: cover;
                object-position: left top;
                opacity: 1;
            }

            .atlas-progress-bar {
                animation: atlas-progress 4s ease-in-out infinite;
            }

            .atlas-float-frame {
                animation: atlas-float 7s ease-in-out infinite;
            }

            .atlas-section-dot[aria-current="true"] {
                background: var(--color-smart-blue-300);
                border-color: var(--color-smart-blue-200);
            }

            @keyframes atlas-progress {
                0%, 100% {
                    width: 32%;
                }

                50% {
                    width: 82%;
                }
            }

            @keyframes atlas-float {
                0%, 100% {
                    transform: translateY(0);
                }

                50% {
                    transform: translateY(-0.75rem);
                }
            }

            @media (prefers-reduced-motion: reduce) {
                html,
                .atlas-home-scroller {
                    scroll-behavior: auto;
                }

                .atlas-home-section,
                .atlas-section-media,
                .atlas-float-frame,
                .atlas-progress-bar {
                    animation: none;
                    transition: none;
                }
            }

            @media (max-width: 767px) {
                .atlas-home-section,
                .atlas-home-section > .grid {
                    gap: 1rem;
                }

                .atlas-section-media {
                    max-height: 16rem;
                    min-height: 12rem;
                }

                .atlas-section-media .min-h-72 {
                    min-height: 0 !important;
                }
            }
        </style>
    </head>
    <body class="app-gradient min-h-screen overflow-hidden text-twilight-indigo-100 antialiased">
        @php
            $browseScreenshots = [
                [
                    'src' => asset('home/browse-civitai-most-reactions.png'),
                    'alt' => 'Browse grid sorted by most reactions',
                    'label' => 'Feed sorting',
                ],
                [
                    'src' => asset('home/browse-deviantart.png'),
                    'alt' => 'Browse grid for an external image source',
                    'label' => 'Source browsing',
                ],
                [
                    'src' => asset('home/browse-full-view.png'),
                    'alt' => 'Fullscreen image review mode',
                    'label' => 'Full view',
                ],
            ];

            $heroPills = [
                ['label' => 'External feeds', 'description' => 'Browse web services in one queue'],
                ['label' => 'Local imports', 'description' => 'Scan folders into managed storage'],
                ['label' => 'Reactions + moderation', 'description' => 'Keep, skip, flag, and filter fast'],
            ];
        @endphp

        <header class="fixed left-0 right-0 top-0 z-30 border-b border-twilight-indigo-500/40 bg-prussian-blue-800/85 px-4 backdrop-blur md:px-8">
            <div class="flex h-16 w-full items-center justify-between gap-4">
                <a href="#hero" class="flex items-center gap-3 text-white" aria-label="Home">
                    <x-atlas-icon class="h-9 w-9 shrink-0" />
                    <span class="text-lg font-semibold">Atlas</span>
                </a>

                @if (Route::has('login'))
                    <div class="flex items-center gap-3 text-sm">
                        @auth
                            <a href="{{ url('/browse') }}" class="hidden text-twilight-indigo-200 transition-colors hover:text-smart-blue-100 sm:inline">Browse</a>
                            <a href="{{ url('/dashboard') }}" class="inline-flex items-center justify-center rounded bg-smart-blue-500 px-4 py-2 font-semibold text-white transition-colors hover:bg-smart-blue-600">
                                Dashboard
                            </a>
                        @else
                            <span class="hidden text-xs text-twilight-indigo-300 sm:inline">Private self-hosted workspace</span>
                            <a href="{{ route('login') }}" class="inline-flex items-center justify-center rounded bg-smart-blue-500 px-4 py-2 font-semibold text-white transition-colors hover:bg-smart-blue-600">
                                Log in
                            </a>
                        @endauth
                    </div>
                @endif
            </div>
        </header>

        <nav class="fixed right-4 top-1/2 z-30 hidden -translate-y-1/2 flex-col gap-3 md:flex" aria-label="Home sections">
            <a class="atlas-section-dot h-3 w-3 rounded-full border border-twilight-indigo-400 bg-prussian-blue-700 transition-colors" href="#hero" aria-label="Hero"></a>
            <a class="atlas-section-dot h-3 w-3 rounded-full border border-twilight-indigo-400 bg-prussian-blue-700 transition-colors" href="#what-it-does" aria-label="What it does"></a>
            <a class="atlas-section-dot h-3 w-3 rounded-full border border-twilight-indigo-400 bg-prussian-blue-700 transition-colors" href="#supported-sources" aria-label="Supported sources"></a>
            <a class="atlas-section-dot h-3 w-3 rounded-full border border-twilight-indigo-400 bg-prussian-blue-700 transition-colors" href="#browser-extension" aria-label="Extension"></a>
            <a class="atlas-section-dot h-3 w-3 rounded-full border border-twilight-indigo-400 bg-prussian-blue-700 transition-colors" href="#import-existing-files" aria-label="Import"></a>
            <a class="atlas-section-dot h-3 w-3 rounded-full border border-twilight-indigo-400 bg-prussian-blue-700 transition-colors" href="#moderation" aria-label="Moderation"></a>
            <a class="atlas-section-dot h-3 w-3 rounded-full border border-twilight-indigo-400 bg-prussian-blue-700 transition-colors" href="#keyboard-flow" aria-label="Keyboard flow"></a>
            <a class="atlas-section-dot h-3 w-3 rounded-full border border-twilight-indigo-400 bg-prussian-blue-700 transition-colors" href="#playback" aria-label="Playback"></a>
        </nav>

        <main id="atlas-home" class="atlas-home-scroller w-full" data-home-scroller>
            <section id="hero" data-home-section class="atlas-home-section atlas-hero-section flex w-full flex-col justify-start gap-8 px-4 pb-8 pt-24 md:px-8 lg:px-12">
                <div class="grid w-full flex-1 items-center gap-8 lg:grid-cols-[0.82fr_1.18fr]">
                    <div class="space-y-6">
                        <div class="space-y-4">
                            <p class="text-xs font-semibold uppercase tracking-widest text-smart-blue-200">Private media library</p>
                            <h1 class="text-4xl font-semibold text-regal-navy-100 sm:text-5xl lg:text-5xl">Triage noisy media feeds without losing the good stuff.</h1>
                            <p class="text-sm leading-6 text-blue-slate-100 md:text-lg">
                                Browse external sources and local folders, react fast, auto-save what matters, and keep a searchable private library behind one dashboard.
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-3">
                            @auth
                                <a href="{{ url('/dashboard') }}" class="inline-flex items-center justify-center rounded bg-smart-blue-500 px-5 py-3 text-sm font-semibold text-white transition-colors hover:bg-smart-blue-600">
                                    Open dashboard
                                </a>
                                <a href="{{ url('/browse') }}" class="inline-flex items-center justify-center rounded border border-twilight-indigo-500 px-5 py-3 text-sm font-semibold text-twilight-indigo-100 transition-colors hover:border-smart-blue-300 hover:text-smart-blue-100">
                                    Browse files
                                </a>
                            @else
                                <a href="{{ route('login') }}" class="inline-flex items-center justify-center rounded bg-smart-blue-500 px-5 py-3 text-sm font-semibold text-white transition-colors hover:bg-smart-blue-600">
                                    Log in
                                </a>
                            @endauth
                        </div>
                        <div class="grid gap-3 sm:grid-cols-3">
                            @foreach ($heroPills as $pill)
                                <div class="rounded border border-twilight-indigo-500/40 bg-prussian-blue-800/70 p-4">
                                    <div class="text-sm font-semibold text-regal-navy-100">{{ $pill['label'] }}</div>
                                    <div class="mt-2 text-xs leading-5 text-twilight-indigo-200">{{ $pill['description'] }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <figure class="atlas-section-media atlas-dashboard-screenshot-frame overflow-hidden rounded-lg">
                        <img
                            src="{{ asset('home/dashboard-hero.png') }}"
                            alt="Dashboard view with coverage, inventory, and container metrics"
                            width="1920"
                            height="1080"
                            class="atlas-dashboard-screenshot"
                            decoding="async"
                            fetchpriority="high"
                        >
                        <figcaption class="absolute bottom-4 left-4 z-10 rounded border border-white/15 bg-prussian-blue-950/80 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-smart-blue-100 backdrop-blur">
                            Dashboard overview · coverage, inventory, containers
                        </figcaption>
                    </figure>
                </div>
            </section>

            <section id="what-it-does" data-home-section class="atlas-home-section flex w-full flex-col justify-start gap-8 px-4 pb-8 pt-24 md:px-8 lg:px-12">
                <div class="grid w-full items-center gap-8 lg:grid-cols-[0.72fr_1.28fr]">
                    <header class="space-y-4">
                        <p class="text-xs font-semibold uppercase tracking-widest text-smart-blue-200">What it does</p>
                        <h2 class="text-3xl font-semibold text-regal-navy-100 md:text-5xl">One pass from discovery to a saved library.</h2>
                        <p class="text-sm leading-6 text-blue-slate-100 md:text-base">
                            Review a stream, mark the decision, and let Atlas carry the save, transfer, and queue state forward.
                        </p>
                    </header>

                    <div
                        id="atlas-home-screenshot-carousel"
                        class="atlas-section-media atlas-dashboard-screenshot-frame overflow-hidden rounded-lg"
                        data-slides='@json($browseScreenshots)'
                    >
                        <figure class="relative h-full min-h-[min(46svh,30rem)] overflow-hidden">
                            <img
                                src="{{ asset('home/browse-civitai-most-reactions.png') }}"
                                alt="Browse grid sorted by most reactions"
                                width="1920"
                                height="1080"
                                class="h-full min-h-[min(46svh,30rem)] w-full object-cover object-left-top"
                                decoding="async"
                            >
                        </figure>
                    </div>
                </div>
            </section>

            <section id="supported-sources" data-home-section class="atlas-home-section flex w-full flex-col justify-start gap-8 px-4 pb-8 pt-24 md:px-8 lg:px-12">
                <div class="grid w-full items-center gap-8 lg:grid-cols-[0.78fr_1.22fr]">
                    <header class="space-y-4">
                        <p class="text-xs font-semibold uppercase tracking-widest text-smart-blue-200">Supported sources</p>
                        <h2 class="text-3xl font-semibold text-regal-navy-100 md:text-5xl">Online feeds beside local files.</h2>
                        <p class="text-sm leading-6 text-blue-slate-100 md:text-base">
                            Remote services and local libraries share filters for type, reaction, transfer state, moderation, and random sampling.
                        </p>
                    </header>

                    <figure class="atlas-section-media rounded-lg border border-smart-blue-500/30 bg-prussian-blue-800/70 p-4">
                        <div class="grid h-full min-h-72 gap-4 md:grid-cols-[0.9fr_1.1fr]">
                            <div class="space-y-3">
                                @foreach (['CivitAI', 'DeviantArt', 'Wallhaven', 'Local files'] as $feed)
                                    <div class="flex items-center justify-between rounded border border-twilight-indigo-500/30 bg-prussian-blue-900/70 px-4 py-3">
                                        <span class="text-sm font-semibold text-regal-navy-100">{{ $feed }}</span>
                                        <span class="h-2 w-2 rounded-full bg-smart-blue-300"></span>
                                    </div>
                                @endforeach
                            </div>
                            <div class="rounded border border-twilight-indigo-500/30 bg-prussian-blue-900/70 p-4">
                                <div class="mb-4 text-sm font-semibold text-smart-blue-200">Local filters</div>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    @foreach (['Images', 'Videos', 'Audio', 'Unreacted', 'Saved', 'Random'] as $filter)
                                        <span class="rounded border border-twilight-indigo-500/40 px-3 py-2 text-xs text-twilight-indigo-100">{{ $filter }}</span>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </figure>
                </div>
            </section>

            <section id="browser-extension" data-home-section class="atlas-home-section flex w-full flex-col justify-start gap-8 px-4 pb-8 pt-24 md:px-8 lg:px-12">
                <div class="grid w-full items-center gap-8 lg:grid-cols-[1.16fr_0.84fr]">
                    <figure class="atlas-section-media order-2 rounded-lg border border-smart-blue-500/30 bg-prussian-blue-800/70 p-4 lg:order-1">
                        <div class="rounded border border-twilight-indigo-500/30 bg-prussian-blue-900/80">
                            <div class="flex h-10 items-center gap-2 border-b border-twilight-indigo-500/30 px-4">
                                <span class="h-2 w-2 rounded-full bg-danger-400"></span>
                                <span class="h-2 w-2 rounded-full bg-warning-400"></span>
                                <span class="h-2 w-2 rounded-full bg-success-400"></span>
                                <div class="ml-2 h-5 flex-1 rounded bg-prussian-blue-700"></div>
                            </div>
                            <div class="grid min-h-72 gap-4 p-4 md:grid-cols-[1fr_16rem]">
                                <div class="grid grid-cols-2 gap-3">
                                    <div class="rounded bg-smart-blue-500/20"></div>
                                    <div class="rounded bg-success-500/20"></div>
                                    <div class="rounded bg-warning-500/20"></div>
                                    <div class="rounded bg-danger-500/20"></div>
                                </div>
                                <div class="space-y-3 rounded border border-smart-blue-500/40 bg-prussian-blue-700/90 p-4">
                                    <div class="text-sm font-semibold text-smart-blue-100">Action badge</div>
                                    <div class="grid grid-cols-2 gap-2 text-xs">
                                        <span class="rounded bg-smart-blue-500 px-3 py-2 text-center text-white">Love</span>
                                        <span class="rounded border border-smart-blue-400 px-3 py-2 text-center text-smart-blue-100">Like</span>
                                        <span class="rounded border border-danger-400 px-3 py-2 text-center text-danger-100">Blacklist</span>
                                        <span class="rounded border border-success-400 px-3 py-2 text-center text-success-100">Save</span>
                                    </div>
                                    <div class="h-2 rounded bg-prussian-blue-900">
                                        <div class="atlas-progress-bar h-2 rounded bg-success-300"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </figure>

                    <header class="order-1 space-y-4 lg:order-2">
                        <p class="text-xs font-semibold uppercase tracking-widest text-smart-blue-200">Extension</p>
                        <h2 class="text-3xl font-semibold text-regal-navy-100 md:text-5xl">React and save without leaving the page.</h2>
                        <p class="text-sm leading-6 text-blue-slate-100 md:text-base">
                            The bundled add-on brings Atlas decisions into the source page with badges, batch reactions, transfer status, and remote tab handoff.
                        </p>
                    </header>
                </div>
            </section>

            <section id="import-existing-files" data-home-section class="atlas-home-section flex w-full flex-col justify-start gap-8 px-4 pb-8 pt-24 md:px-8 lg:px-12">
                <div class="grid w-full items-center gap-8 lg:grid-cols-[0.78fr_1.22fr]">
                    <header class="space-y-4">
                        <p class="text-xs font-semibold uppercase tracking-widest text-smart-blue-200">Import</p>
                        <h2 class="text-3xl font-semibold text-regal-navy-100 md:text-5xl">Turn existing folders into managed inventory.</h2>
                        <p class="text-sm leading-6 text-blue-slate-100 md:text-base">
                            Scans discover folders, queue imports, detect duplicates, and rerun parsers so old files join the same workflow.
                        </p>
                    </header>

                    <figure class="atlas-section-media rounded-lg border border-smart-blue-500/30 bg-prussian-blue-800/70 p-4">
                        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <span class="text-sm font-semibold text-smart-blue-200">Scan run</span>
                            <span class="rounded border border-smart-blue-400/50 px-3 py-1 text-xs text-smart-blue-100">processing</span>
                        </div>
                        <div class="mb-4 h-2 rounded bg-prussian-blue-900">
                            <div class="atlas-progress-bar h-2 rounded bg-smart-blue-300"></div>
                        </div>
                        <div class="grid gap-3 text-sm md:grid-cols-3">
                            @foreach ([['Found', '14,208'], ['Imported', '9,842'], ['Duplicates', '316']] as $metric)
                                <div class="rounded border border-twilight-indigo-500/30 bg-prussian-blue-900/70 p-4">
                                    <div class="text-xs text-twilight-indigo-300">{{ $metric[0] }}</div>
                                    <div class="mt-2 text-xl font-semibold text-regal-navy-100">{{ $metric[1] }}</div>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-4 space-y-2 text-xs text-twilight-indigo-200">
                            <div class="grid grid-cols-[1fr_6rem_6rem] gap-3 rounded border border-twilight-indigo-500/30 bg-prussian-blue-900/70 px-3 py-2">
                                <span class="truncate">imports/sets/video-001.mp4</span>
                                <span>video</span>
                                <span class="text-success-200">imported</span>
                            </div>
                            <div class="grid grid-cols-[1fr_6rem_6rem] gap-3 rounded border border-twilight-indigo-500/30 bg-prussian-blue-900/70 px-3 py-2">
                                <span class="truncate">audio/archive/track.flac</span>
                                <span>audio</span>
                                <span class="text-smart-blue-200">parsed</span>
                            </div>
                        </div>
                    </figure>
                </div>
            </section>

            <section id="moderation" data-home-section class="atlas-home-section flex w-full flex-col justify-start gap-8 px-4 pb-8 pt-24 md:px-8 lg:px-12">
                <div class="grid w-full items-center gap-8 lg:grid-cols-[1.1fr_0.9fr]">
                    <figure class="atlas-section-media order-2 rounded-lg border border-smart-blue-500/30 bg-prussian-blue-800/70 p-4 lg:order-1">
                        <div class="grid h-full min-h-72 gap-4 md:grid-cols-[1.2fr_0.8fr]">
                            <div class="grid grid-cols-2 gap-3">
                                @foreach (['Favorite', 'Like', 'Funny', 'Blacklist'] as $reaction)
                                    <div class="flex items-end rounded border border-twilight-indigo-500/30 bg-prussian-blue-900/70 p-3">
                                        <span class="text-sm font-semibold text-regal-navy-100">{{ $reaction }}</span>
                                    </div>
                                @endforeach
                            </div>
                            <div class="space-y-3 rounded border border-twilight-indigo-500/30 bg-prussian-blue-900/70 p-4">
                                <div class="text-sm font-semibold text-smart-blue-200">Container rules</div>
                                <div class="space-y-2 text-xs text-twilight-indigo-200">
                                    <div class="rounded bg-prussian-blue-700 px-3 py-2">User: auto-blacklist</div>
                                    <div class="rounded bg-prussian-blue-700 px-3 py-2">Model: keep positives</div>
                                    <div class="rounded bg-prussian-blue-700 px-3 py-2">Missing: hide by default</div>
                                </div>
                            </div>
                        </div>
                    </figure>

                    <header class="order-1 space-y-4 lg:order-2">
                        <p class="text-xs font-semibold uppercase tracking-widest text-smart-blue-200">Moderation</p>
                        <h2 class="text-3xl font-semibold text-regal-navy-100 md:text-5xl">Make every decision improve the next pass.</h2>
                        <p class="text-sm leading-6 text-blue-slate-100 md:text-base">
                            Reactions, container rules, not-found state, blacklists, preview counts, and metadata keep repeat noise out of the way.
                        </p>
                    </header>
                </div>
            </section>

            <section id="keyboard-flow" data-home-section class="atlas-home-section flex w-full flex-col justify-start gap-8 px-4 pb-8 pt-24 md:px-8 lg:px-12">
                <div class="grid w-full items-center gap-8 lg:grid-cols-[0.82fr_1.18fr]">
                    <header class="space-y-4">
                        <p class="text-xs font-semibold uppercase tracking-widest text-smart-blue-200">Keyboard flow</p>
                        <h2 class="text-3xl font-semibold text-regal-navy-100 md:text-5xl">Fast triage without turning the browser into a form.</h2>
                        <p class="text-sm leading-6 text-blue-slate-100 md:text-base">
                            Undo, auto-scroll, loading locks, quick reactions, blacklist actions, and original opens stay under your hands.
                        </p>
                    </header>

                    <figure class="atlas-section-media rounded-lg border border-smart-blue-500/30 bg-prussian-blue-800/70 p-4">
                        <div class="grid h-full min-h-72 gap-3 md:grid-cols-2">
                            @foreach ([['Ctrl/Cmd + Z', 'Undo queued reaction'], ['Alt + Click', 'Favorite'], ['Alt + Middle', 'Like'], ['Alt + Right click', 'Blacklist'], ['Middle click', 'Open original'], ['Space', 'Toggle auto-scroll'], ['Alt + L', 'Page loading lock']] as $shortcut)
                                <div class="flex items-center justify-between gap-4 rounded border border-twilight-indigo-500/30 bg-prussian-blue-900/70 px-4 py-3">
                                    <span class="rounded bg-prussian-blue-700 px-3 py-2 text-xs font-semibold text-smart-blue-100">{{ $shortcut[0] }}</span>
                                    <span class="text-sm text-twilight-indigo-100">{{ $shortcut[1] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </figure>
                </div>
            </section>

            <section id="playback" data-home-section class="atlas-home-section flex w-full flex-col justify-start gap-8 px-4 pb-8 pt-24 md:px-8 lg:px-12">
                <div class="grid w-full items-center gap-8 lg:grid-cols-[1.02fr_0.98fr]">
                    <figure class="atlas-section-media order-2 rounded-lg border border-smart-blue-500/30 bg-prussian-blue-800/70 p-4 lg:order-1">
                        <div class="grid h-full min-h-72 place-items-center">
                            <div class="w-full rounded-lg border border-twilight-indigo-500/40 bg-prussian-blue-900 p-3 md:w-72">
                                <div class="mb-3 flex items-center justify-between text-xs text-twilight-indigo-300">
                                    <span>/files/2841</span>
                                    <span>range</span>
                                </div>
                                <div class="aspect-[9/16] rounded bg-prussian-blue-700 p-3">
                                    <div class="flex h-full flex-col justify-between rounded bg-smart-blue-500/20 p-3">
                                        <div class="h-2 w-1/3 rounded bg-white/60"></div>
                                        <div class="space-y-3">
                                            <div class="h-2 rounded bg-prussian-blue-900">
                                                <div class="atlas-progress-bar h-2 rounded bg-smart-blue-200"></div>
                                            </div>
                                            <div class="flex justify-center gap-3">
                                                <span class="h-8 w-8 rounded-full border border-smart-blue-200"></span>
                                                <span class="h-8 w-8 rounded-full bg-smart-blue-400"></span>
                                                <span class="h-8 w-8 rounded-full border border-smart-blue-200"></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </figure>

                    <header class="order-1 space-y-4 lg:order-2">
                        <p class="text-xs font-semibold uppercase tracking-widest text-smart-blue-200">Playback</p>
                        <h2 class="text-3xl font-semibold text-regal-navy-100 md:text-5xl">Stream saved media through browser-native playback.</h2>
                        <p class="text-sm leading-6 text-blue-slate-100 md:text-base">
                            Downloaded and imported files get byte-range support, posters, previews, and mobile-sized controls for video, audio, and image checks.
                        </p>
                        <div class="flex flex-wrap gap-3">
                            @auth
                                <a href="{{ url('/browse') }}" class="inline-flex items-center justify-center rounded bg-smart-blue-500 px-5 py-3 text-sm font-semibold text-white transition-colors hover:bg-smart-blue-600">
                                    Browse library
                                </a>
                            @else
                                <a href="{{ route('login') }}" class="inline-flex items-center justify-center rounded bg-smart-blue-500 px-5 py-3 text-sm font-semibold text-white transition-colors hover:bg-smart-blue-600">
                                    Log in to Atlas
                                </a>
                            @endauth
                            <a href="https://youtu.be/g1Ogg5vivSM" class="inline-flex items-center justify-center rounded border border-twilight-indigo-500 px-5 py-3 text-sm font-semibold text-twilight-indigo-100 transition-colors hover:border-smart-blue-300 hover:text-smart-blue-100">
                                Watch demo
                            </a>
                        </div>
                    </header>
                </div>
            </section>
        </main>

        <script>
            (() => {
                const scroller = document.querySelector('[data-home-scroller]');
                const sections = Array.from(document.querySelectorAll('[data-home-section]'));
                const dots = Array.from(document.querySelectorAll('.atlas-section-dot'));

                if (!scroller || sections.length === 0) {
                    return;
                }

                function setActive(section) {
                    sections.forEach((item) => item.classList.toggle('is-active', item === section));
                    dots.forEach((dot) => dot.setAttribute('aria-current', dot.hash === `#${section.id}` ? 'true' : 'false'));

                    if (window.location.hash !== `#${section.id}`) {
                        window.history.replaceState(null, '', `#${section.id}`);
                    }
                }

                function scrollToHash(hash) {
                    const id = hash.replace('#', '');
                    const target = sections.find((section) => section.id === id);

                    if (target) {
                        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                }

                const observer = new IntersectionObserver((entries) => {
                    const visible = entries
                        .filter((entry) => entry.isIntersecting)
                        .sort((left, right) => right.intersectionRatio - left.intersectionRatio)[0];

                    if (visible?.target instanceof HTMLElement) {
                        setActive(visible.target);
                    }
                }, {
                    root: scroller,
                    threshold: [0.55, 0.7, 0.85],
                });

                sections.forEach((section) => observer.observe(section));
                dots.forEach((dot) => {
                    dot.addEventListener('click', (event) => {
                        event.preventDefault();
                        scrollToHash(dot.hash);
                    });
                });

                window.addEventListener('hashchange', () => scrollToHash(window.location.hash));
                window.requestAnimationFrame(() => {
                    if (window.location.hash) {
                        scrollToHash(window.location.hash);
                        return;
                    }

                    setActive(sections[0]);
                });
            })();
        </script>
    </body>
</html>
