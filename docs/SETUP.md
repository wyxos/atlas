# Setup

This guide mirrors a real-world install on a server.

If you want the Docker option, use `docs/DOCKER.md` instead.

## What you need

- A database (MariaDB/MySQL or Postgres)
- Redis (for queues)
- Typesense (for fast browsing/search)
- FFmpeg (for previews and posters)
- A storage path with enough space (local disk or mounted NAS)

## Steps

1) Clone the repo to a server with the basics installed (PHP, database, Redis, web server).

2) Copy `.env.example` to `.env` and set:
   - `APP_URL` to your domain or IP
   - `DB_*` for your database
   - `REDIS_*` for Redis
   - `SCOUT_DRIVER=typesense` and `TYPESENSE_*`
   - `DOWNLOADS_FFMPEG_PATH` to your ffmpeg path
   - `ATLAS_STORAGE` to your storage root (use a mounted NAS path if desired)

3) Install dependencies and build assets:
   - `composer install`
   - `npm install`
   - `npm run build`

4) Generate the app key and migrate the database:
   - `php artisan key:generate`
   - `php artisan migrate --force`

5) Create the first admin account:
   - `php artisan app:setup`

6) Start background workers:
   - Horizon (recommended for queues and downloads)
   - Scheduler (for maintenance tasks)

## Optional

- CivitAI and Wallhaven API keys improve rate limits.
- HTTPS is recommended if you access Atlas outside your network.
