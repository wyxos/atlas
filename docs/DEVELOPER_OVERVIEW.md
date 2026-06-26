# Developer Overview

Atlas is a Laravel 12 and Vue 3 application for fast media feed curation, local library browsing, background downloads, moderation, and lightweight audio/video playback. It exposes a browser-extension API for the standalone Atlas extension.

## Main pieces

- `routes/web.php` defines the public home page, login routes, SPA catch-all, authenticated JSON endpoints, and browser-extension API endpoints.
- `app/Http/Controllers` contains the request entry points for browsing, files, tabs, downloads, moderation, settings, audio, library scans, and extension integration.
- `app/Models` contains the core domain records, including files, sources, reactions, tabs, transfers, moderation rules, playlists, albums, artists, library scans, and search documents.
- `app/Services` holds most domain behavior. Notable areas include browsing, CivitAI and DeviantArt source handling, downloads, preview generation, moderation, library indexing, and audio metadata.
- `app/Jobs` and `app/Console/Commands` run background work for downloads, scans, previews, metadata, cleanup, reindexing, and maintenance.
- `resources/js` is the Vue SPA. `resources/js/app.ts` mounts the dashboard shell, configures Vue Router, registers Laravel Echo/Reverb, and mounts the public home screenshot carousel when present.

## Runtime dependencies

Atlas expects a database, Redis, Typesense, FFmpeg, and writable Atlas storage. Setup details live in [SETUP.md](SETUP.md). The app uses Horizon for queue supervision, Scout with Typesense for search, Reverb/Echo for realtime browser updates, and Vite for frontend assets.

Local development uses the seeded non-production account from `database/seeders/DatabaseSeeder.php`: `demo@atlas.test` with password `password`.

## Frontend routing and API shape

The Laravel app serves Blade views for the public home page and authenticated SPA shell. Vue Router handles dashboard navigation after the SPA shell loads. Most application data flows through `/api/...` routes declared in `routes/web.php`; extension-facing endpoints are also under `/api/extension/...`.

Wayfinder is installed for typed route and controller helpers. When route definitions change, regenerate Wayfinder output if the Vite plugin has not already done it during development.

## Development checks

Use the smallest check that covers the change:

- PHP tests: `php artisan test --compact`
- Frontend unit tests: `npm run test`
- Frontend typecheck: `npm run typecheck`
- Frontend lint: `npm run lint`
- PHP style: `vendor/bin/pint --dirty`
- Full local check: `npm run check`

For browser-visible Atlas UI work, verify locally through `https://atlas.test` when feasible.
