<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Dashboard - {{ config('app.name', 'Atlas') }}</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif

        @include('partials.favicons')
    </head>
    <body style="background-color: #001233; color: #d0d7e5; min-height: 100vh;">
        <div id="app"></div>
        <div class="flex min-h-screen items-center justify-center p-6">
            <div class="w-full max-w-4xl">
                <div style="background-color: #000e29; border-radius: 0.5rem; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3); padding: 2rem 3rem;">
                    <div class="text-center mb-8">
                        <div class="flex justify-center mb-6">
                            <x-atlas-icon class="w-24 h-24" />
                        </div>
                        <h1 style="font-size: 1.875rem; font-weight: 700; color: #c3e0fe; margin-bottom: 0.5rem;">
                            Dashboard
                        </h1>
                        <p style="color: #a0aecb;">
                            Welcome, {{ Auth::user()->name }}!
                        </p>
                    </div>

                    <div class="text-center">
                        <form method="POST" action="{{ route('logout') }}" class="inline">
                            @csrf
                            <button
                                type="submit"
                                class="inline-block px-8 py-3 text-white font-semibold rounded-lg transition-colors shadow-lg"
                                style="background-color: #0466c8;"
                                onmouseover="this.style.backgroundColor='#0f85fa'"
                                onmouseout="this.style.backgroundColor='#0466c8'"
                            >
                                Log Out
                            </button>
                        </form>
                        <a
                            href="{{ route('home') }}"
                            class="inline-block ml-4 px-8 py-3 font-semibold rounded-lg transition-colors shadow-lg"
                            style="background-color: #0353a4; color: #ffffff;"
                            onmouseover="this.style.backgroundColor='#0576e8'"
                            onmouseout="this.style.backgroundColor='#0353a4'"
                        >
                            Back to Home
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </body>
</html>
