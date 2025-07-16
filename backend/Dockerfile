FROM php:8.3-cli

# System dependencies
RUN apt-get update && apt-get install -y \
    git unzip curl libpq-dev libzip-dev libonig-dev libcurl4-openssl-dev \
    libxml2-dev zlib1g-dev libssl-dev libpng-dev libjpeg-dev libfreetype6-dev \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        zip \
        sockets \
        pcntl

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install Swoole
RUN pecl install swoole && docker-php-ext-enable swoole

# Set working directory
WORKDIR /var/www/html

EXPOSE 8686
