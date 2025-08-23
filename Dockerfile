# syntax=docker/dockerfile:1.7

ARG PHP_VERSION=8.4
ARG NODE_VERSION=22

# --- Composer vendor/cache stage ---
FROM composer:2 AS composer
WORKDIR /app
# Provide composer binary for later stages
COPY composer.json composer.lock* ./

# --- Frontend build (Vite) ---
FROM node:${NODE_VERSION}-alpine AS node
ENV PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1
# Provide Vite build-time envs for Reverb
ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST
ARG VITE_REVERB_PORT
ARG VITE_REVERB_SCHEME
ENV VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY}
ENV VITE_REVERB_HOST=${VITE_REVERB_HOST}
ENV VITE_REVERB_PORT=${VITE_REVERB_PORT}
ENV VITE_REVERB_SCHEME=${VITE_REVERB_SCHEME}

WORKDIR /app
COPY package.json package-lock.json* ./
# Cache npm store between builds
RUN --mount=type=cache,target=/root/.npm \
    if [ -f package-lock.json ]; then npm ci --no-audit --no-fund; else npm install --no-audit --no-fund; fi
# Copy only what's needed for the build
COPY resources resources
COPY vite.config.ts tsconfig.json ./
COPY public public
COPY bootstrap bootstrap
RUN npm run build

# --- App image (PHP-FPM) ---
FROM php:${PHP_VERSION}-fpm-alpine AS app
WORKDIR /var/www/html

# System packages
RUN apk add --no-cache \
    bash git unzip curl \
    ffmpeg \
    icu-dev oniguruma-dev libzip-dev zlib-dev curl-dev \
    libpng-dev libjpeg-turbo-dev freetype-dev \
    mariadb-connector-c-dev \
    netcat-openbsd \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS

# PHP extensions: pdo_mysql (MariaDB), pcntl/posix (Horizon), mbstring, curl (for HTTP clients), gd (images), zip, redis
# Note: sockets is dev-only for Pest Browser; omit to avoid Alpine header issues
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) pdo_mysql pcntl posix mbstring curl gd zip \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && apk del --no-network .build-deps

# Opcache for production
RUN printf "opcache.enable=1\n\
opcache.enable_cli=1\n\
opcache.memory_consumption=128\n\
opcache.interned_strings_buffer=16\n\
opcache.max_accelerated_files=20000\n\
opcache.validate_timestamps=0\n\
opcache.save_comments=1\n" > /usr/local/etc/php/conf.d/opcache.ini

# Create runtime directories and set permissions
RUN mkdir -p storage bootstrap/cache public/build \
 && chown -R www-data:www-data /var/www/html

# Copy only composer files first to leverage layer caching
COPY composer.json composer.lock* /var/www/html/

# Install PHP dependencies (no-dev) now that required extensions are present
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
# Install vendors without running scripts yet (artisan not present until source is copied)
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --no-scripts

# Now copy the full application source (excluding heavy/ignored files via .dockerignore)
COPY --link . /var/www/html

# Bring in built frontend assets from node stage
COPY --from=node /app/public/build /var/www/html/public/build

# Now that the full app is present, generate optimized autoloads (triggers post-autoload-dump scripts)
RUN composer dump-autoload --no-dev --optimize

# Ensure storage directories and public symlinks exist at build time so the web image inherits them
RUN mkdir -p storage/app/public storage/app/atlas \
 && php artisan storage:link || true

# Entrypoint script
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
# Normalize line endings to LF to avoid /usr/bin/env 'bash\r' errors on Alpine
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint && chmod +x /usr/local/bin/entrypoint

# Permissions
RUN chown -R www-data:www-data storage bootstrap/cache public/build \
 && find storage bootstrap/cache -type d -exec chmod 775 {} \; \
 && find storage bootstrap/cache -type f -exec chmod 664 {} \;

# php-fpm runs as www-data by default
ENTRYPOINT ["entrypoint"]
# Start PHP-FPM in foreground
CMD ["php-fpm", "-F"]

# --- Nginx image (serves static assets and proxies to PHP-FPM) ---
FROM nginx:alpine AS web
COPY --from=app /var/www/html /var/www/html
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD wget -qO- http://127.0.0.1/up >/dev/null 2>&1 || exit 1
CMD ["nginx", "-g", "daemon off;"]

