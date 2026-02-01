<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>500 - {{ config('app.name', 'Atlas') }}</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.ts'])
        @endif

        @include('partials.favicons')
    </head>
    <body class="app-gradient text-twilight-indigo-100 min-h-screen">
        <main class="mx-auto flex min-h-screen w-full max-w-4xl flex-col items-center justify-center gap-8 px-6 py-12 text-center">
            <section class="relative w-full overflow-hidden rounded-2xl border border-twilight-indigo-500/40 bg-prussian-blue-800/70 p-8 shadow-2xl">
                <div class="pointer-events-none absolute -right-20 -top-20 h-48 w-48 rounded-full bg-smart-blue-500/20 blur-3xl"></div>
                <div class="pointer-events-none absolute -bottom-16 -left-10 h-48 w-48 rounded-full bg-sapphire-500/20 blur-3xl"></div>

                <div class="relative space-y-4">
                    <div class="text-xs font-semibold uppercase tracking-[0.3em] text-smart-blue-200">
                        Error 500
                    </div>
                    <h1 class="text-xl font-semibold text-regal-navy-100">
                        Something went wrong
                    </h1>
                    <p class="text-sm text-blue-slate-200">
                        We hit an unexpected error while loading this page.
                    </p>
                </div>
            </section>

            <div class="flex flex-wrap items-center justify-center gap-3 text-sm">
                @auth
                    <a href="{{ url('/dashboard') }}" class="inline-flex items-center justify-center rounded-lg bg-smart-blue-500 px-6 py-2 font-semibold text-white shadow-lg transition-colors hover:bg-smart-blue-600">
                        Go to Dashboard
                    </a>
                @endauth
                <a href="{{ url('/') }}" class="inline-flex items-center justify-center rounded-lg border border-twilight-indigo-500/60 px-6 py-2 font-semibold text-twilight-indigo-100 transition-colors hover:border-smart-blue-400/60 hover:text-white">
                    Back to Home
                </a>
            </div>
        </main>
    </body>
</html>
