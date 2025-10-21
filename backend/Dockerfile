# Single final image with extensions compiled here
FROM php:8.3-fpm-alpine
ENV COMPOSER_ALLOW_SUPERUSER=1

# Runtime libs (curl for healthcheck; brotli-libs for swoole runtime)
RUN apk add --no-cache \
    libpq oniguruma icu \
    freetype libpng libjpeg-turbo libwebp \
    libzip zlib git zip unzip \
    curl brotli-libs

# Build deps just for compiling PHP extensions, then remove
RUN apk add --no-cache --virtual .build-deps \
    build-base autoconf \
    libpq-dev oniguruma-dev icu-dev \
    freetype-dev libpng-dev libjpeg-turbo-dev libwebp-dev \
    libzip-dev zlib-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" \
      pdo pdo_pgsql intl mbstring gd zip pcntl \
 && apk del .build-deps

# Build Swoole for Octane (with brotli dev libs available)
RUN apk add --no-cache --virtual .swoole-build \
      build-base autoconf pcre2-dev openssl-dev linux-headers brotli-dev pkgconf \
 && pecl install swoole \
 && docker-php-ext-enable swoole \
 && apk del .swoole-build

# (Optional) enable opcache
RUN docker-php-ext-enable opcache || true

# Ensure pcntl_* isn’t disabled anywhere (for both CLI & FPM)
RUN { \
      echo 'disable_functions='; \
      echo 'opcache.enable_cli=1'; \
    } > /usr/local/etc/php/conf.d/zz-octane.ini

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# App code
COPY . /var/www/html

# PHP deps
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

# Ensure needed runtime dirs exist
RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs

EXPOSE 8686
