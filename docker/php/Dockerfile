FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    git \
    icu-dev \
    libzip-dev \
    postgresql-dev \
    unzip

RUN docker-php-ext-install intl opcache pdo pdo_pgsql

WORKDIR /var/www/html

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/php/zz-app.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/php/conf.d/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
