<p align="center">
	<img src="public/favicon.svg" alt="Atlas" width="96" height="96" />
</p>

<h1 align="center">Atlas <sub>(WIP)</sub></h1>

Atlas is a self-hosted media server focused on high-volume browsing, curation, and background downloading.

The hard part isn’t finding content anymore. It’s filtering it.

Atlas is me taking control of that. When I see something good, I want one action to keep it and queue the download. When I see noise, spam, or repeats, I want it to stop showing up. Everything else exists to make that scale.

## Status

**Alpha.** Expect breaking changes.

- No upgrade / backward-compatibility guarantees yet
- Not recommended for production until a tagged beta exists

## What Atlas Does

- Browse from online sources (currently includes CivitAI Images + Wallhaven)
- React to items (love / like / funny / dislike)
- Positive reactions automatically queue background downloads
- Track state (seen / previewed / downloaded) so repeats don’t keep resurfacing
- Rule-based moderation to filter/auto-dislike items based on text patterns
- Container-level blacklisting (source-defined blacklistable container types)
- Tabs as separate “channels” to keep different hunts separate

## Why Not Plex/Jellyfin/etc.?

They’re great for traditional media libraries, but Atlas is built around rapid curation from chaotic feeds:
aggressive de-noising, fast keep/nope workflows, and a frictionless download pipeline.

## Requirements (Meaningful Use)

Atlas is designed around a few “real-world” building blocks:

- PHP + Composer
- Node.js + npm
- A real database (recommended: Postgres or MySQL/MariaDB)
- Redis (queues + Horizon)
- Typesense (search via Scout)

You can do some basic UI/dev work without the full stack, but core workflows (queues, search, background downloads) assume the services above. External API keys are optional, but recommended for higher rate limits.

## Quickstart (Local Dev)

1) Install dependencies

```bash
composer install
npm install
```

2) Configure environment

```bash
cp .env.example .env
php artisan key:generate
```

3) Configure services in `.env`

At minimum for meaningful use:

- Database: set `DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- Redis: set `REDIS_HOST`, `REDIS_PORT` (and password if needed)
- Typesense: set `SCOUT_DRIVER=typesense` and Typesense connection vars

4) Migrate + seed

```bash
php artisan migrate --force
php artisan db:seed
```

Seeded demo credentials:

- Email: `demo@atlas.test`
- Password: `password`

5) Run

```bash
composer run dev
```

## Production-ish Notes

If you’re deploying to a server, you’ll typically want:

- `QUEUE_CONNECTION=redis`
- A Horizon worker process (instead of `queue:listen`)
- A scheduler process for Horizon maintenance commands (`horizon:snapshot`, etc.)

## Services / API Keys

These are optional but supported:

- `CIVITAI_API_KEY`
- `WALLHAVEN_API_KEY`
- `WALLHAVEN_USER_AGENT`

## Storage

Atlas stores downloaded files under Laravel storage disks:

- `atlas`: `storage/app/atlas`
- `atlas-app`: `storage/app/atlas/.app` (used by the download pipeline)

## Tests

```bash
php artisan test
npm test
```
