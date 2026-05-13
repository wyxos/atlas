FROM php:8.4-fpm AS builder

# Install build dependencies
RUN apt-get update && apt-get install -y \
    git \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libmagickwand-dev \
    libonig-dev \
    libpng-dev \
    libzip-dev \
    nodejs \
    npm \
    unzip \
    zip \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions needed during build
RUN docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip sockets opcache && \
    pecl install redis imagick && \
    docker-php-ext-enable redis imagick

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application source and build production dependencies/assets
COPY --chown=root:root . .
RUN composer install --no-dev --optimize-autoloader --classmap-authoritative && \
    npm ci && \
    npm run build

FROM php:8.4-fpm AS runtime

# Install runtime dependencies (including build deps for PHP extensions)
RUN apt-get update && apt-get install -y \
    ffmpeg \
    yt-dlp \
    libcurl4-openssl-dev \
    libxml2-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libzip-dev \
    libonig-dev \
    libmagickwand-dev \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip sockets opcache xml curl && \
    pecl install redis imagick && \
    docker-php-ext-enable redis imagick

# Set working directory
WORKDIR /var/www/html

ENV HOME=/tmp \
    XDG_CONFIG_HOME=/tmp

# Copy application source and built artifacts
COPY --chown=www-data:www-data . .
COPY --from=builder --chown=www-data:www-data /var/www/html/vendor /var/www/html/vendor
COPY --from=builder --chown=www-data:www-data /var/www/html/public/build /var/www/html/public/build

# Copy runtime configuration
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/php/php-wrapper.sh /usr/local/bin/php-wrapper.sh
COPY docker/php/pgrep-wrapper.sh /usr/local/bin/pgrep-wrapper.sh
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/zz-docker.conf /usr/local/etc/php-fpm.d/zz-docker.conf
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/php-wrapper.sh /usr/local/bin/pgrep-wrapper.sh && \
    mv /usr/local/bin/php /usr/local/bin/php-real && \
    mv /usr/bin/pgrep /usr/bin/pgrep-real && \
    ln -s /usr/local/bin/php-wrapper.sh /usr/local/bin/php && \
    ln -s /usr/local/bin/pgrep-wrapper.sh /usr/local/bin/pgrep

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

# Expose port 9000 and start php-fpm server
EXPOSE 9000

# Start php-fpm server
CMD ["php-fpm", "-F"]

USER www-data
