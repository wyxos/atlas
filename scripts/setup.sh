#!/usr/bin/env bash
set -euo pipefail

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is required. Install Docker Desktop, then re-run this script."
  echo "https://www.docker.com/products/docker-desktop/"
  exit 1
fi

./scripts/setup-env.sh

docker compose up -d --build

echo "Waiting for app container..."
ready_ok=0
for i in {1..30}; do
  if docker compose exec -T app php -v >/dev/null 2>&1; then
    ready_ok=1
    break
  fi
  sleep 2
done

if [[ "$ready_ok" != "1" ]]; then
  echo "App container did not become ready. Check: docker compose ps && docker compose logs --tail=200" >&2
  exit 1
fi

docker compose exec -T app php artisan key:generate
docker compose exec -T app php artisan migrate --force
if [[ -n "${ATLAS_SETUP_NAME:-}" && -n "${ATLAS_SETUP_EMAIL:-}" && -n "${ATLAS_SETUP_PASSWORD:-}" ]]; then
  docker compose exec -T app php artisan app:setup     --name="${ATLAS_SETUP_NAME}"     --email="${ATLAS_SETUP_EMAIL}"     --password="${ATLAS_SETUP_PASSWORD}"
else
  docker compose exec app php artisan app:setup
fi

echo
echo "Atlas is starting up."
echo "Open: http://localhost:6363"
