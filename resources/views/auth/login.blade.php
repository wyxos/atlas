<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>Login - {{ config('app.name', 'Atlas') }}</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.ts'])
        @endif

        @include('partials.favicons')
    </head>
    <body class="bg-prussian-blue-600 text-twilight-indigo-100 min-h-screen">
        <div id="app"></div>
        <main class="flex min-h-screen items-center justify-center p-6">
            <div class="w-full max-w-md">
                <div class="bg-prussian-blue-600 rounded-lg shadow-2xl p-8 lg:p-12">
                    <div class="text-center mb-8">
                        <div class="flex justify-center mb-6">
                            <x-atlas-icon class="w-24 h-24" />
                        </div>
                        <h1 class="text-3xl font-bold text-smart-blue-900 mb-2">
                            Welcome Back
                        </h1>
                        <p class="text-blue-slate-300">
                            Sign in to your account
                        </p>
                    </div>

                    @if ($errors->any())
                        <div class="mb-6 p-4 rounded-lg bg-danger-100 border-2 border-danger-600">
                            <div class="flex">
                                <svg class="w-5 h-5 mr-2 text-danger-800" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                </svg>
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-danger-900">
                                        {{ $errors->first() }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('login') }}" class="space-y-6">
                        @csrf

                        <div>
                            <label for="email" class="block text-sm font-medium mb-2 text-smart-blue-900">
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
                                class="w-full px-4 py-3 rounded-lg focus:ring-2 focus:outline-none transition-colors bg-prussian-blue-500 border-2 border-twilight-indigo-500 text-twilight-indigo-100 focus:border-smart-blue-600 focus:ring-smart-blue-600/20"
                                placeholder="you@example.com"
                            >
                            @error('email')
                                <p class="mt-1 text-sm text-danger-800">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="password" class="block text-sm font-medium mb-2 text-smart-blue-900">
                                Password
                            </label>
                            <input
                                type="password"
                                name="password"
                                id="password"
                                required
                                autocomplete="current-password"
                                class="w-full px-4 py-3 rounded-lg focus:ring-2 focus:outline-none transition-colors bg-prussian-blue-500 border-2 border-twilight-indigo-500 text-twilight-indigo-100 focus:border-smart-blue-600 focus:ring-smart-blue-600/20"
                                placeholder="••••••••"
                            >
                            @error('password')
                                <p class="mt-1 text-sm text-danger-800">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <input
                                    type="checkbox"
                                    name="remember"
                                    id="remember"
                                    class="w-4 h-4 rounded focus:ring-2 accent-smart-blue-500"
                                >
                                <label for="remember" class="ml-2 text-sm text-twilight-indigo-300">
                                    Remember me
                                </label>
                            </div>
                        </div>

                        <div>
                            <x-ui.button
                                type="submit"
                                class="w-full px-6 py-3 shadow-lg"
                            >
                                Sign In
                            </x-ui.button>
                        </div>
                    </form>

                    <div class="mt-6 text-center">
                        <a href="{{ route('home') }}" class="text-sm font-medium transition-colors text-smart-blue-300 hover:text-smart-blue-200">
                            ← Back to Home
                        </a>
                    </div>
                </div>
            </div>
        </main>
    </body>
</html>
