# Setup

This guide mirrors a real-world install on a server.

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

## Docker Setup

Run Atlas with all dependencies containerized — no need to install PHP, Node.js, MariaDB, Redis, or Typesense locally.

### Prerequisites

- [Docker](https://docs.docker.com/get-docker/) and [Docker Compose](https://docs.docker.com/compose/install/) installed

### Quick Start

```bash
./docker-setup.sh
```

This script copies `.env.docker` to `.env`, starts all containers, waits for MariaDB, generates the app key, runs migrations, and creates the admin account.

### Manual Setup

```bash
cp .env.docker .env
docker-compose up -d

# Wait for MariaDB to be ready (check with: docker-compose ps)
docker-compose exec php php artisan key:generate
docker-compose exec php php artisan migrate --force
docker-compose exec php php artisan app:setup
```

### Services and Ports

| Service | Port | Purpose |
|---------|------|---------|
| Nginx (App) | 8080 | Web application |
| phpMyAdmin | 8081 | Database management |
| MariaDB | 3306 | Database |
| Redis | 6379 | Cache and sessions |
| Typesense | 8108 | Search engine |
| Reverb | 8080 | WebSockets (same port as app) |

### Data Persistence

Docker named volumes keep your data across container restarts:

- `atlas-mariadb-data` — Database files
- `atlas-redis-data` — Redis persistence
- `atlas-typesense-data` — Search index

### Common Commands

```bash
docker-compose down                    # Stop all services
docker-compose logs -f                 # View all logs
docker-compose logs -f php             # View PHP/app logs
docker-compose build                   # Rebuild images after Dockerfile changes
docker-compose exec php php artisan migrate        # Run migrations
docker-compose exec php php artisan horizon:terminate  # Restart Horizon
```

### Troubleshooting

- **Reverb keeps restarting**: Run `docker-compose exec php php artisan migrate --force` first, then `docker-compose restart reverb`
- **500 error on app**: Check `docker-compose logs php` and ensure `.env` was copied from `.env.docker`
- **Permission issues on storage**: `docker-compose exec php chmod -R 777 storage bootstrap/cache`
- **Reset everything**: `docker-compose down -v` (WARNING: deletes all data volumes)
