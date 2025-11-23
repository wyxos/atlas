<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'Atlas') }}</title>

        <!-- Styles / Scripts -->
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif

        @include('partials.favicons')
    </head>
    <body class="min-h-screen bg-gradient-to-br from-blue-50 to-blue-100">
        <div class="flex min-h-screen items-center justify-center p-6">
            <div class="w-full max-w-4xl">
                <div class="bg-white rounded-lg shadow-xl p-8 md:p-12">
                    <div class="text-center mb-8">
                        <div class="flex justify-center mb-6">
                            <x-atlas-icon />
                        </div>
                        <h1 class="text-4xl md:text-5xl font-bold text-blue-900 mb-4">
                            Welcome to {{ config('app.name', 'Atlas') }}
                        </h1>
                        <p class="text-lg text-blue-700 mb-8">
                            Your media server solution
                        </p>
                    </div>

                    <div class="grid md:grid-cols-2 gap-6 mb-8">
                        <div class="bg-blue-50 border-2 border-blue-200 rounded-lg p-6">
                            <h2 class="text-xl font-semibold text-blue-900 mb-3 flex items-center">
                                <svg class="w-6 h-6 mr-2 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                Features
                            </h2>
                            <p class="text-blue-800">
                                Manage your media library with ease. Organize, stream, and enjoy your content.
                            </p>
                        </div>

                        <div class="bg-amber-50 border-2 border-amber-200 rounded-lg p-6">
                            <h2 class="text-xl font-semibold text-amber-900 mb-3 flex items-center">
                                <svg class="w-6 h-6 mr-2 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                </svg>
                                Performance
                            </h2>
                            <p class="text-amber-800">
                                Fast, reliable, and efficient. Built for modern media consumption.
                            </p>
                        </div>
                    </div>

                    <div class="text-center">
                        @if (Route::has('login'))
                            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                                @auth
                                    <a href="{{ url('/dashboard') }}" class="inline-block px-8 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition-colors shadow-lg">
                                        Go to Dashboard
                                    </a>
                                @else
                                    <a href="{{ route('login') }}" class="inline-block px-8 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition-colors shadow-lg">
                                        Log In
                                    </a>
                                @endauth
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </body>
</html>

