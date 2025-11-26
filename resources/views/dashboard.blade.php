<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="user-id" content="{{ Auth::user()->id }}">
        <meta name="user-name" content="{{ Auth::user()->name }}">
        <meta name="app-name" content="{{ config('app.name', 'Atlas') }}">

        <title>Dashboard - {{ config('app.name', 'Atlas') }}</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.ts'])
        @endif

        @include('partials.favicons')
    </head>
    <body class="bg-prussian-blue-500 text-twilight-indigo-900 min-h-screen">
        <div id="app"></div>
    </body>
</html>
