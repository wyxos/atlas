<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'Atlas') }}</title>

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
                            <x-atlas-icon class="w-32 h-32" />
                        </div>
                        <h1 style="font-size: 2.5rem; font-weight: 700; color: #c3e0fe; margin-bottom: 1rem;">
                            Welcome to {{ config('app.name', 'Atlas') }}
                        </h1>
                        <p style="font-size: 1.125rem; color: #a0aecb;">
                            Your media server solution
                        </p>
                    </div>

                    <div class="grid md:grid-cols-2 gap-6 mb-8">
                        <div style="background-color: #023d78; border: 2px solid #0466c8; border-radius: 0.5rem; padding: 1.5rem;">
                            <h2 style="font-size: 1.25rem; font-weight: 600; color: #c3e0fe; margin-bottom: 0.75rem; display: flex; align-items: center;">
                                <svg class="w-6 h-6 mr-2" style="color: #4ba3fb;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                Features
                            </h2>
                            <p style="color: #d0d7e5;">
                                Manage your media library with ease. Organize, stream, and enjoy your content.
                            </p>
                        </div>

                        <div style="background-color: #023263; border: 2px solid #0353a4; border-radius: 0.5rem; padding: 1.5rem;">
                            <h2 style="font-size: 1.25rem; font-weight: 600; color: #bcddfe; margin-bottom: 0.75rem; display: flex; align-items: center;">
                                <svg class="w-6 h-6 mr-2" style="color: #3698fb;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                </svg>
                                Performance
                            </h2>
                            <p style="color: #d0d7e5;">
                                Fast, reliable, and efficient. Built for modern media consumption.
                            </p>
                        </div>
                    </div>

                    <div class="text-center">
                        @if (Route::has('login'))
                            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                                @auth
                                    <a href="{{ url('/dashboard') }}" class="inline-block px-8 py-3 text-white font-semibold rounded-lg transition-colors shadow-lg" style="background-color: #0466c8;" onmouseover="this.style.backgroundColor='#0f85fa'" onmouseout="this.style.backgroundColor='#0466c8'">
                                        Go to Dashboard
                                    </a>
                                @else
                                    <a href="{{ route('login') }}" class="inline-block px-8 py-3 text-white font-semibold rounded-lg transition-colors shadow-lg" style="background-color: #0466c8;" onmouseover="this.style.backgroundColor='#0f85fa'" onmouseout="this.style.backgroundColor='#0466c8'">
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
