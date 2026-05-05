#!/bin/bash
set -e

# Run composer install if vendor directory is missing
if [ ! -d "vendor" ]; then
    echo "Vendor directory missing, running composer install..."
    composer install --no-interaction
fi

# Run npm install if node_modules is missing
if [ ! -d "node_modules" ]; then
    echo "node_modules directory missing, running npm install..."
    npm install
fi

# Run npm run build if public/build directory is missing
if [ ! -d "public/build" ]; then
    echo "public/build directory missing, running npm run build..."
    npm run build
fi

# Run storage:link if storage link is missing
if [ ! -d "public/storage" ]; then
    echo "public/storage missing, running storage:link..."
    php artisan storage:link
fi

# Execute the CMD
exec "$@"
