<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Login - {{ config('app.name', 'Atlas') }}</title>

        <!-- Styles / Scripts -->
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif

        @include('partials.favicons')
    </head>
    <body class="min-h-screen bg-gradient-to-br from-blue-50 to-blue-100">
        <div class="flex min-h-screen items-center justify-center p-6">
            <div class="w-full max-w-md">
                <div class="bg-white rounded-lg shadow-xl p-8 md:p-12">
                    <div class="text-center mb-8">
                        <div class="flex justify-center mb-6">
                            <x-atlas-icon class="w-24 h-24" />
                        </div>
                        <h1 class="text-3xl md:text-4xl font-bold text-blue-900 mb-2">
                            Welcome Back
                        </h1>
                        <p class="text-blue-700">
                            Sign in to your account
                        </p>
                    </div>

                    @if ($errors->any())
                        <div class="mb-6 p-4 bg-red-50 border-2 border-red-200 rounded-lg">
                            <div class="flex">
                                <svg class="w-5 h-5 text-red-600 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                </svg>
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-red-800">
                                        {{ $errors->first() }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('login') }}" class="space-y-6">
                        @csrf

                        <div>
                            <label for="email" class="block text-sm font-medium text-blue-900 mb-2">
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
                                class="w-full px-4 py-3 border-2 border-blue-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 focus:outline-none transition-colors"
                                placeholder="you@example.com"
                            >
                            @error('email')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="password" class="block text-sm font-medium text-blue-900 mb-2">
                                Password
                            </label>
                            <input
                                type="password"
                                name="password"
                                id="password"
                                required
                                autocomplete="current-password"
                                class="w-full px-4 py-3 border-2 border-blue-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 focus:outline-none transition-colors"
                                placeholder="••••••••"
                            >
                            @error('password')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <input
                                    type="checkbox"
                                    name="remember"
                                    id="remember"
                                    class="w-4 h-4 text-blue-600 border-blue-300 rounded focus:ring-blue-500"
                                >
                                <label for="remember" class="ml-2 text-sm text-blue-700">
                                    Remember me
                                </label>
                            </div>
                        </div>

                        <div>
                            <button
                                type="submit"
                                class="w-full px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition-colors shadow-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                            >
                                Sign In
                            </button>
                        </div>
                    </form>

                    <div class="mt-6 text-center">
                        <a href="{{ route('home') }}" class="text-sm text-blue-600 hover:text-blue-800 font-medium">
                            ← Back to Home
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </body>
</html>

