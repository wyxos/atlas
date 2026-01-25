# Docker Setup

This is the quickest way to get Atlas running on a local machine or a small server.

## 1) Prepare your .env

Copy `.env.example` to `.env`, then set these values:

- `APP_URL=http://localhost:8080`
- `DB_CONNECTION=mysql`
- `DB_HOST=db`
- `DB_PORT=3306`
- `DB_DATABASE=atlas`
- `DB_USERNAME=atlas`
- `DB_PASSWORD=atlas`
- `REDIS_HOST=redis`
- `SCOUT_DRIVER=typesense`
- `TYPESENSE_HOST=typesense`
- `TYPESENSE_PORT=8108`
- `TYPESENSE_API_KEY=typesense`
- `DOWNLOADS_FFMPEG_PATH=ffmpeg`

If you want to store files on a NAS, set:

- `ATLAS_STORAGE=/data/atlas`

## 2) Build and start

```bash
docker compose up -d --build
```

## 3) Initialize the app

```bash
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force
docker compose exec app php artisan app:setup
```

## 4) Open the app

Visit: `http://localhost:8080`

## Storage notes

The default Docker volume is `atlas_storage`. If you want a host path or NAS mount,
replace that volume in `docker-compose.yml` with your desired path.
