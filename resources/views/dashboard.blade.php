<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Dashboard - {{ config('app.name', 'Atlas') }}</title>

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
                            <x-atlas-icon class="w-24 h-24" />
                        </div>
                        <h1 class="text-3xl md:text-4xl font-bold text-blue-900 mb-2">
                            Dashboard
                        </h1>
                        <p class="text-blue-700">
                            Welcome, {{ Auth::user()->name }}!
                        </p>
                    </div>

                    <div class="text-center">
                        <form method="POST" action="{{ route('logout') }}" class="inline">
                            @csrf
                            <button
                                type="submit"
                                class="inline-block px-8 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition-colors shadow-lg"
                            >
                                Log Out
                            </button>
                        </form>
                        <a
                            href="{{ route('home') }}"
                            class="inline-block ml-4 px-8 py-3 bg-amber-500 hover:bg-amber-600 text-white font-semibold rounded-lg transition-colors shadow-lg"
                        >
                            Back to Home
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </body>
</html>

