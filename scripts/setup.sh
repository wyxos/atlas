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
for i in {1..30}; do
  if docker compose exec -T app php -v >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

docker compose exec -T app php artisan key:generate
docker compose exec -T app php artisan migrate --force
if [[ -n "${ATLAS_SETUP_NAME:-}" && -n "${ATLAS_SETUP_EMAIL:-}" && -n "${ATLAS_SETUP_PASSWORD:-}" ]]; then
  docker compose exec -T app php artisan app:setup     --name="${ATLAS_SETUP_NAME}"     --email="${ATLAS_SETUP_EMAIL}"     --password="${ATLAS_SETUP_PASSWORD}"
else
  docker compose exec app php artisan app:setup
fi

echo
echo "Atlas is starting up."
echo "Open: http://localhost:8080"
