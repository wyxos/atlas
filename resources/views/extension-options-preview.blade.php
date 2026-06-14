<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Atlas Extension Options Preview - {{ config('app.name', 'Atlas') }}</title>

        @php
            $previewEntry = 'resources/js/extension-options-preview.ts';
            $manifestPath = public_path('build/manifest.json');
            $manifest = file_exists($manifestPath)
                ? json_decode(file_get_contents($manifestPath), true)
                : [];
            $hasPreviewEntry = file_exists(public_path('hot'))
                || (is_array($manifest) && array_key_exists($previewEntry, $manifest));
        @endphp

        @if ($hasPreviewEntry)
            @vite($previewEntry)
        @endif

        @include('partials.favicons')
    </head>
    <body class="app-gradient min-h-screen text-twilight-indigo-100 antialiased">
        <div id="app" data-vue-root="extension-options-preview"></div>
    </body>
</html>
