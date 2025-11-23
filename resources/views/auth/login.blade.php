<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Login - {{ config('app.name', 'Atlas') }}</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif

        @include('partials.favicons')
    </head>
    <body style="background-color: #001233; color: #d0d7e5; min-height: 100vh;">
        <div id="app"></div>
        <div class="flex min-h-screen items-center justify-center p-6">
            <div class="w-full max-w-md">
                <div style="background-color: #000e29; border-radius: 0.5rem; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3); padding: 2rem 3rem;">
                    <div class="text-center mb-8">
                        <div class="flex justify-center mb-6">
                            <x-atlas-icon class="w-24 h-24" />
                        </div>
                        <h1 style="font-size: 1.875rem; font-weight: 700; color: #c3e0fe; margin-bottom: 0.5rem;">
                            Welcome Back
                        </h1>
                        <p style="color: #a0aecb;">
                            Sign in to your account
                        </p>
                    </div>

                    @if ($errors->any())
                        <div class="mb-6 p-4 rounded-lg" style="background-color: #023d78; border: 2px solid #0466c8;">
                            <div class="flex">
                                <svg class="w-5 h-5 mr-2" style="color: #4ba3fb;" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                </svg>
                                <div class="flex-1">
                                    <p style="font-size: 0.875rem; font-weight: 500; color: #c3e0fe;">
                                        {{ $errors->first() }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('login') }}" class="space-y-6">
                        @csrf

                        <div>
                            <label for="email" class="block text-sm font-medium mb-2" style="color: #c3e0fe;">
                                Email Address
                            </label>
                            <input
                                type="email"
                                name="email"
                                id="email"
                                value="{{ old('email') }}"
                                required
                                autofocus
                                autocomplete="email"
                                class="w-full px-4 py-3 rounded-lg focus:ring-2 focus:outline-none transition-colors"
                                style="background-color: #001233; border: 2px solid #33415c; color: #d0d7e5;"
                                placeholder="you@example.com"
                                onfocus="this.style.borderColor='#0f85fa'; this.style.boxShadow='0 0 0 3px rgba(15, 133, 250, 0.2)'"
                                onblur="this.style.borderColor='#33415c'; this.style.boxShadow='none'"
                            >
                            @error('email')
                                <p class="mt-1 text-sm" style="color: #4ba3fb;">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="password" class="block text-sm font-medium mb-2" style="color: #c3e0fe;">
                                Password
                            </label>
                            <input
                                type="password"
                                name="password"
                                id="password"
                                required
                                autocomplete="current-password"
                                class="w-full px-4 py-3 rounded-lg focus:ring-2 focus:outline-none transition-colors"
                                style="background-color: #001233; border: 2px solid #33415c; color: #d0d7e5;"
                                placeholder="••••••••"
                                onfocus="this.style.borderColor='#0f85fa'; this.style.boxShadow='0 0 0 3px rgba(15, 133, 250, 0.2)'"
                                onblur="this.style.borderColor='#33415c'; this.style.boxShadow='none'"
                            >
                            @error('password')
                                <p class="mt-1 text-sm" style="color: #4ba3fb;">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <input
                                    type="checkbox"
                                    name="remember"
                                    id="remember"
                                    class="w-4 h-4 rounded focus:ring-2"
                                    style="accent-color: #0466c8;"
                                >
                                <label for="remember" class="ml-2 text-sm" style="color: #d0d7e5;">
                                    Remember me
                                </label>
                            </div>
                        </div>

                        <div>
                            <button
                                type="submit"
                                class="w-full px-6 py-3 text-white font-semibold rounded-lg transition-colors shadow-lg focus:outline-none focus:ring-2"
                                style="background-color: #0466c8;"
                                onmouseover="this.style.backgroundColor='#0f85fa'"
                                onmouseout="this.style.backgroundColor='#0466c8'"
                            >
                                Sign In
                            </button>
                        </div>
                    </form>

                    <div class="mt-6 text-center">
                        <a href="{{ route('home') }}" class="text-sm font-medium transition-colors" style="color: #4ba3fb;" onmouseover="this.style.color='#0f85fa'" onmouseout="this.style.color='#4ba3fb'">
                            ← Back to Home
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </body>
</html>
