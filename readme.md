# ATLAS

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel)](https://laravel.com)
[![Vue.js](https://img.shields.io/badge/Vue.js-3-4FC08D?logo=vue.js)](https://vuejs.org)
[![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?logo=php)](https://php.net)
[![TypeScript](https://img.shields.io/badge/TypeScript-5-3178C6?logo=typescript)](https://typescriptlang.org)

*Your media. Your server. Your rules.*

ATLAS is a work-in-progress, self-hosted media server for people who want reliable organization, fast search, and direct streaming of their own libraries.

## Why ATLAS?

- I built ATLAS because reliability and simplicity mattered more than knobs and toggles.
- I wanted better organization and sorting without waiting on upstream features.
- I care about first-class content browsing with blacklist and curation.
- I prefer one-click or shortcut-driven workflows to download and manage content.
- I want content permanence: keep what you love even if platforms or creators remove it.

Existing media servers like Plex, Jellyfin, and Emby are powerful. ATLAS focuses on reliability, frictionless organization, and content browsing.

## Status: Work in Progress

ATLAS is under active development. Features ship incrementally and may change. Feedback is welcome. Open issues and ideas here: https://github.com/wyxos/atlas/issues

## What Works Today

- Audio library management with metadata extraction (ID3, cover art).
- File organization by artist and album.
- Typesense search when configured (SCOUT_DRIVER=typesense).
- Direct audio streaming, rating, and listen tracking.
- Dashboard statistics and health indicators.
- Multi-user accounts with admin controls.

## Quick Start (Docker)

Run, Open, Setup.

1) Run

```bash path=null start=null
docker compose up -d --build
```

2) Open

- Web UI: http://localhost:8080
- Health check: http://localhost:8080/up should return 200

3) Setup

- Register your account. The first user becomes admin.
- Add media to the atlas storage (files on the atlas disk are served under `/atlas/...`).

Reset

```bash path=null start=null
docker compose down -v --remove-orphans
docker compose up -d --build
```

### Troubleshooting

- Database not ready: the app waits, but first boot can take time. Check logs and container health.
  ```bash path=null start=null
  docker compose ps
  docker compose logs -f db
  ```
- Typesense not reachable: confirm SCOUT_DRIVER and Typesense env vars; ensure the container is healthy.
  ```bash path=null start=null
  docker compose logs -f typesense
  ```
- Storage link missing: create the public storage link inside the app container.
  ```bash path=null start=null
  docker compose exec app php artisan storage:link
  ```

## Screenshots

![Dashboard](docs/images/dashboard.png)
Dashboard with library stats and storage breakdown.

![Audio Player](docs/images/audio-player.png)
Audio player with track metadata and playback controls.

## Usage Examples

1) Import audio and build metadata

- Put audio files in the configured storage path for the `atlas` disk.
- Extract metadata from files.

```bash path=null start=null
php artisan files:extract-metadata
# Or for a specific file
php artisan files:extract-metadata --file=123
```

- Translate extracted metadata (optional).

```bash path=null start=null
php artisan files:translate-metadata
# Force reprocessing
php artisan files:translate-metadata --force
# Specific file
php artisan files:translate-metadata --file=123
```

2) Search and browse

- Use the search bar and filters in the UI.
- If Typesense is configured, full-text search spans artists, albums, and track fields.

## Roadmap (Near-term)

### Near-term (next)

- [ ] Playlist management
- [ ] Enhanced audio player (queue and playback controls)
- [ ] Batch operations for metadata and file tasks
- [ ] In-app metadata editing

### Later

- [ ] Plugin system for extensibility

## Developer Setup (Manual)

### Prerequisites

- PHP 8.2+ with extensions: mbstring, xml, json, gd
- Composer
- Node.js and npm
- Database: SQLite (default), MySQL 8.0+, or PostgreSQL 13+
- Queue: database (default) or Redis
- Optional: Typesense for search

### Steps

1. Clone

```bash path=null start=null
git clone https://github.com/wyxos/atlas.git
cd atlas
```

2. Install dependencies

```bash path=null start=null
composer install
npm install
```

3. Configure environment

```bash path=null start=null
cp .env.example .env
php artisan key:generate
```

- Set DB/queue and optional Typesense variables in `.env`.

4. Migrate

```bash path=null start=null
php artisan migrate
```

5. Run locally

```bash path=null start=null
# start queue worker (separate terminal)
php artisan queue:work
# start dev server
php artisan serve
```

6. Build assets

```bash path=null start=null
npm run dev
# or
npm run build
```

### Dev scripts

| Task                         | Command                         |
|------------------------------|---------------------------------|
| Start dev (app + queue + Vite) | `composer run dev`              |
| Start dev with SSR           | `composer run dev:ssr`           |
| Run tests                    | `composer run test` or `php artisan test` |
| Lint                         | `npm run lint`                   |
| Format                       | `npm run format`                 |
| Build                        | `npm run build`                  |

## Contributing

Contributions are welcome. Open an issue or submit a pull request:

- Issues: https://github.com/wyxos/atlas/issues
- Pull Requests: https://github.com/wyxos/atlas/pulls

## License & Acknowledgments

ATLAS is open-source software licensed under the [MIT License](LICENSE). You're free to use, modify, and distribute this software according to the license terms.

- Created by [Wyxos](https://wyxos.com)
- Built with [Laravel](https://laravel.com/) and [Vue.js](https://vuejs.org/)
- UI components from [Shadcn Vue](https://www.shadcn-vue.com/)
- Search powered by [Typesense](https://typesense.org/)
