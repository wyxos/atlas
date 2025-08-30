<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        {{-- Google Analytics - only load if GOOGLE_ANALYTICS_ID is set --}}
        @if(config('services.google_analytics.id'))
        <!-- Google tag (gtag.js) -->
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ config('services.google_analytics.id') }}"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', '{{ config('services.google_analytics.id') }}');
        </script>
        @endif
        
<meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Atlas is an open-source, self-hosted media management and streaming platform. It offers AI-powered metadata extraction, intelligent file organization, and a modern web interface for your media collection.">
        <meta name="keywords" content="self-hosted media server, streaming platform, user management, media organization, Laravel, Vue.js">
        <meta name="author" content="Wyxos">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our Atlas theme in app.css --}}
        <style>
            html {
                background-color: hsl(0 0% 100%); /* Light mode white */
            }

            html.dark {
                background-color: hsl(216 100% 2.5%); /* Atlas Rich Black */
            }
        </style>

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        @include('partials.favicons')

        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet" />

        @routes
        @vite(['resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
