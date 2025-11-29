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
    <body class="bg-prussian-blue-700 text-twilight-indigo-900 min-h-screen">
        <div id="app"></div>
        <main class="flex min-h-screen items-center justify-center p-6">
            <div class="w-full max-w-4xl">
                <div class="bg-prussian-blue-700 rounded-lg shadow-2xl p-8 lg:p-12">
                    <div class="text-center mb-8">
                        <div class="flex justify-center mb-6">
                            <x-atlas-icon class="w-32 h-32" />
                        </div>
                        <h1 class="text-5xl font-bold text-smart-blue-900 mb-4">
                            Welcome to {{ config('app.name', 'Atlas') }}
                        </h1>
                        <p class="text-lg text-blue-slate-700">
                            Your media server solution
                        </p>
                    </div>

                    <div class="grid md:grid-cols-2 gap-6 mb-8">
                        <div class="bg-smart-blue-300 border-2 border-smart-blue-500 rounded-lg p-6">
                            <h2 class="text-xl font-semibold text-smart-blue-900 mb-3 flex items-center">
                                <svg class="w-6 h-6 mr-2 text-smart-blue-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                Features
                            </h2>
                            <p class="text-twilight-indigo-900">
                                Manage your media library with ease. Organize, stream, and enjoy your content.
                            </p>
                        </div>

                        <div class="bg-sapphire-300 border-2 border-sapphire-500 rounded-lg p-6">
                            <h2 class="text-xl font-semibold text-sapphire-900 mb-3 flex items-center">
                                <svg class="w-6 h-6 mr-2 text-sapphire-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                </svg>
                                Performance
                            </h2>
                            <p class="text-twilight-indigo-900">
                                Fast, reliable, and efficient. Built for modern media consumption.
                            </p>
                        </div>
                    </div>

                    <div class="text-center">
                        @if (Route::has('login'))
                            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                                @auth
                                    <a href="{{ url('/dashboard') }}" class="inline-block px-8 py-3 text-white font-semibold rounded-lg transition-colors shadow-lg bg-smart-blue-500 hover:bg-smart-blue-600">
                                        Go to Dashboard
                                    </a>
                                @else
                                    <a href="{{ route('login') }}" class="inline-block px-8 py-3 text-white font-semibold rounded-lg transition-colors shadow-lg bg-smart-blue-500 hover:bg-smart-blue-600">
                                        Log In
                                    </a>
                                @endauth
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </main>
    </body>
</html>
