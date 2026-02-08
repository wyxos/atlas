<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'Atlas') }}</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.ts'])
        @endif

        @include('partials.favicons')
    </head>
    <body class="app-gradient text-twilight-indigo-100 min-h-screen">
        <div id="app"></div>
        <main class="mx-auto flex min-h-screen w-full max-w-6xl flex-col gap-10 px-6 py-12">
            <header class="relative overflow-hidden rounded-2xl border border-twilight-indigo-500/40 bg-prussian-blue-800/70 p-8 shadow-2xl lg:p-12">
                <div class="pointer-events-none absolute -right-20 -top-24 h-64 w-64 rounded-full bg-smart-blue-500/20 blur-3xl"></div>
                <div class="pointer-events-none absolute -bottom-24 -left-10 h-64 w-64 rounded-full bg-sapphire-500/20 blur-3xl"></div>

                <div class="relative space-y-6">
                    <div class="flex flex-col items-center gap-6 text-center">
                        <x-atlas-icon class="h-28 w-28" />
                        <div class="space-y-3">
                            <div class="text-xs font-semibold uppercase tracking-[0.3em] text-smart-blue-200">Atlas</div>
                            <h1 class="text-xl font-semibold text-regal-navy-100">
                                Media operations, organized.
                            </h1>
                            <p class="text-sm text-blue-slate-200">
                                Keep your library tidy, moderation consistent, and downloads moving in one console.
                            </p>
                        </div>
                    </div>

                    @if (Route::has('login'))
                        <div class="flex flex-col items-center gap-3 sm:flex-row sm:justify-center">
                            @auth
                                <a href="{{ url('/dashboard') }}" class="inline-flex items-center justify-center rounded-lg bg-smart-blue-500 px-6 py-2 text-sm font-semibold text-white shadow-lg transition-colors hover:bg-smart-blue-600">
                                    Open Dashboard
                                </a>
                                <a href="{{ url('/browse') }}" class="inline-flex items-center justify-center rounded-lg border border-twilight-indigo-500/60 px-6 py-2 text-sm font-semibold text-twilight-indigo-100 transition-colors hover:border-smart-blue-400/60 hover:text-smart-blue-100">
                                    Browse Library
                                </a>
                            @else
                                <a href="{{ route('login') }}" class="inline-flex items-center justify-center rounded-lg bg-smart-blue-500 px-6 py-2 text-sm font-semibold text-white shadow-lg transition-colors hover:bg-smart-blue-600">
                                    Log In
                                </a>
                                <span class="text-xs text-twilight-indigo-300">Private workspace access only.</span>
                            @endauth
                        </div>
                    @endif
                </div>
            </header>

            <section class="grid gap-4 md:grid-cols-3">
                <div class="rounded-lg border border-twilight-indigo-500/30 bg-prussian-blue-800/60 p-6">
                    <h2 class="text-lg font-semibold text-regal-navy-100">Library clarity</h2>
                    <p class="mt-2 text-sm text-blue-slate-200">
                        Track totals, availability, and source coverage without digging through raw lists.
                    </p>
                </div>

                <div class="rounded-lg border border-twilight-indigo-500/30 bg-prussian-blue-800/60 p-6">
                    <h2 class="text-lg font-semibold text-regal-navy-100">Moderation control</h2>
                    <p class="mt-2 text-sm text-blue-slate-200">
                        Keep reactions, rule blacklists, and manual actions aligned with your policy.
                    </p>
                </div>

                <div class="rounded-lg border border-twilight-indigo-500/30 bg-prussian-blue-800/60 p-6">
                    <h2 class="text-lg font-semibold text-regal-navy-100">Download flow</h2>
                    <p class="mt-2 text-sm text-blue-slate-200">
                        Monitor queues, troubleshoot failures, and keep fresh media moving.
                    </p>
                </div>
            </section>

            <section class="rounded-lg border border-twilight-indigo-500/30 bg-prussian-blue-800/60 p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="space-y-2">
                        <h2 class="text-lg font-semibold text-regal-navy-100">Workflow you can trust</h2>
                        <p class="text-sm text-blue-slate-200">
                            Atlas brings scanning, curation, and review into a single operational surface.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span class="rounded-full border border-twilight-indigo-500/40 px-3 py-1 text-xs text-twilight-indigo-200">Realtime updates</span>
                        <span class="rounded-full border border-twilight-indigo-500/40 px-3 py-1 text-xs text-twilight-indigo-200">Searchable sources</span>
                        <span class="rounded-full border border-twilight-indigo-500/40 px-3 py-1 text-xs text-twilight-indigo-200">Moderation audits</span>
                        <span class="rounded-full border border-twilight-indigo-500/40 px-3 py-1 text-xs text-twilight-indigo-200">Queue health</span>
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-twilight-indigo-500/30 bg-prussian-blue-800/60 p-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="space-y-1">
                        <h2 class="text-lg font-semibold text-regal-navy-100">Learn more</h2>
                        <p class="text-sm text-blue-slate-200">Project details, demo walkthrough, and the main site.</p>
                    </div>
                    <div class="flex flex-wrap gap-3 text-sm">
                        <a href="https://github.com/wyxos/atlas" target="_blank" rel="noreferrer"
                            class="text-smart-blue-200 underline underline-offset-4 hover:text-smart-blue-100">
                            GitHub
                        </a>
                        <a href="https://discord.gg/reebNY9w" target="_blank" rel="noreferrer"
                            class="text-smart-blue-200 underline underline-offset-4 hover:text-smart-blue-100">
                            Discord
                        </a>
                        <a href="https://www.youtube.com/watch?v=g1Ogg5vivSM" target="_blank" rel="noreferrer"
                            class="text-smart-blue-200 underline underline-offset-4 hover:text-smart-blue-100">
                            Demo video
                        </a>
                        <a href="https://wyxos.com" target="_blank" rel="noreferrer"
                            class="text-smart-blue-200 underline underline-offset-4 hover:text-smart-blue-100">
                            Wyxos.com
                        </a>
                    </div>
                </div>
            </section>
        </main>
    </body>
</html>
