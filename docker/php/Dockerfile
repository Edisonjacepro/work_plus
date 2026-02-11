FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    git \
    icu-dev \
    libzip-dev \
    postgresql-dev \
    unzip

RUN docker-php-ext-install intl opcache pdo pdo_pgsql

WORKDIR /var/www/html

