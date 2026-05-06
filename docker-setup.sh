#!/bin/bash
set -e

echo "Starting Atlas Docker setup..."

# Copy .env.docker to .env if it doesn't exist
if [ ! -f .env ]; then
    echo "Copying .env.docker to .env..."
    cp .env.docker .env
else
    echo ".env already exists, skipping copy"
fi

# Install Node dependencies and build assets on host
echo "Installing Node dependencies and building assets on host..."
npm install
npm run build

# Start Docker services (mariadb and redis must be running for artisan commands)
echo "Starting Docker services..."
docker-compose up -d

# Wait for MariaDB to be ready
echo "Waiting for MariaDB to be ready..."
for i in $(seq 1 30); do
    if docker-compose exec -T mariadb mariadb-admin ping -h localhost --silent 2>/dev/null; then
        echo "MariaDB is ready!"
        break
    fi
    echo "Waiting for MariaDB... ($i/30)"
    sleep 2
done

# Generate application key if not set
if docker-compose exec -T php grep -q "APP_KEY=base64:" .env 2>/dev/null; then
    echo "Application key already set, skipping generation"
else
    echo "Generating application key..."
    docker-compose exec -T php php artisan key:generate --no-interaction
fi

# Run database migrations
echo "Running database migrations..."
docker-compose exec -T php php artisan migrate --force

# Create admin user (if not exists)
echo "Setting up admin user..."
docker-compose exec -T php php artisan app:setup --name=Admin --email=demo@atlas.test --generate-password --no-interaction || echo "Admin user setup completed or already exists"

# Display service status
echo ""
echo "Checking service status..."
docker-compose ps

echo ""
echo "Atlas Docker setup completed!"
echo "Application: http://localhost:8080"
echo "phpMyAdmin: http://localhost:8081"
echo ""
echo "To stop: docker-compose down"
echo "To view logs: docker-compose logs -f"
echo "To rebuild: docker-compose build"
