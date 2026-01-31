FROM php:8.4-fpm-bullseye AS node-build
WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        gnupg \
        libicu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        bcmath \
        intl \
        pcntl \
        pdo_mysql \
        zip \
        gd \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && npm install -g npm@9.9.2 \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install PHP deps first (so wayfinder has artisan + autoload)
COPY composer.json composer.lock ./
COPY artisan ./artisan
COPY bootstrap ./bootstrap
COPY config ./config
COPY routes ./routes
COPY app ./app
COPY database ./database
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

# Provide a minimal .env so artisan commands (used by Wayfinder during vite build) can run
COPY .env.example ./.env
RUN php artisan key:generate --ansi


# Build frontend assets
COPY package*.json ./
RUN npm install --no-audit --no-fund
COPY resources ./resources
COPY public ./public
COPY vite.config.js tsconfig.json eslint.config.js ./
RUN npm run build

FROM php:8.4-fpm-bullseye
WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ffmpeg \
        git \
        libicu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        bcmath \
        intl \
        pcntl \
        pdo_mysql \
        zip \
        gd \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
COPY artisan ./artisan
COPY bootstrap ./bootstrap
COPY config ./config
COPY routes ./routes
COPY app ./app
COPY database ./database
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

# Provide a minimal .env so artisan commands (used by Wayfinder during vite build) can run
COPY .env.example ./.env
RUN php artisan key:generate --ansi


COPY . .
COPY --from=node-build /var/www/html/public/build /var/www/html/public/build

RUN chown -R www-data:www-data storage bootstrap/cache
