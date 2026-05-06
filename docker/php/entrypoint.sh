#!/bin/bash
set -e



# Run storage:link if storage link is missing
if [ ! -d "public/storage" ]; then
    echo "public/storage missing, running storage:link..."
    php artisan storage:link
fi

# Set proper permissions on storage directories
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

# Execute the CMD
exec "$@"
