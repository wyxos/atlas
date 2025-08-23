#!/usr/bin/env bash
set -euo pipefail

# Switch to project root
cd /var/www/html

# Ensure .env exists
if [ ! -f .env ]; then
  if [ -f .env.example ]; then
    cp .env.example .env
  else
    echo ".env.example not found; cannot bootstrap env" >&2
    exit 1
  fi
fi

# Always ensure APP_KEY exists (artisan may fail if APP_KEY line missing)
if ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force --no-interaction || true
  # Fallback: manually inject a key if still missing
  if ! grep -q '^APP_KEY=base64:' .env; then
    NEW_KEY=$(php -r "echo 'base64:'.base64_encode(random_bytes(32));")
    if grep -q '^APP_KEY=' .env; then
      sed -i "s/^APP_KEY=.*/APP_KEY=${NEW_KEY}/" .env
    else
      printf "\nAPP_KEY=%s\n" "$NEW_KEY" >> .env
    fi
  fi
fi

# Inject container DB host if present
: "${DB_HOST:=db}"
: "${DB_PORT:=3306}"

# Wait for MariaDB
echo "Waiting for MariaDB at ${DB_HOST}:${DB_PORT}..."
for i in {1..60}; do
  nc -z "${DB_HOST}" "${DB_PORT}" && break
  sleep 1
  if [ "$i" -eq 60 ]; then
    echo "MariaDB not reachable at ${DB_HOST}:${DB_PORT}" >&2
    exit 1
  fi
done

# Wait for Typesense
: "${TYPESENSE_HOST:=typesense}"
: "${TYPESENSE_PORT:=8108}"
: "${TYPESENSE_API_KEY:=xyz}"

echo "Waiting for Typesense at ${TYPESENSE_HOST}:${TYPESENSE_PORT}..."
for i in {1..60}; do
  nc -z "${TYPESENSE_HOST}" "${TYPESENSE_PORT}" && break
  sleep 1
  if [ "$i" -eq 60 ]; then
    echo "Typesense not reachable at ${TYPESENSE_HOST}:${TYPESENSE_PORT}" >&2
    exit 1
  fi
done

# Optimize storage directories
mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache
# Ensure web user owns runtime paths (volume mounts override image ownership)
chown -R www-data:www-data storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod 775 {} \;
find storage bootstrap/cache -type f -exec chmod 664 {} \;

# Generate app key if missing
if ! grep -q "^APP_KEY=base64:" .env; then
  php artisan key:generate --force --no-interaction || true
fi

# Run migrations only if explicitly enabled (force non-interactive)
if [ "${MIGRATE_ON_BOOT:-false}" = "true" ]; then
  php artisan migrate --force || true
fi

# Clear and cache config/routes/view for consistent boot
php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

# Finally exec CMD
exec "$@"

