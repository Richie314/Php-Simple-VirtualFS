ARG PHP_VERSION=8.3
FROM composer:2 AS composer
FROM php:${PHP_VERSION}-cli-bookworm

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev libwebp-dev unzip \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd \
    && rm -rf /var/lib/apt/lists/* \
    && printf 'upload_max_filesize=16M\npost_max_size=18M\nsession.cookie_httponly=1\nsession.cookie_samesite=Strict\ndisplay_errors=Off\n' > "$PHP_INI_DIR/conf.d/demo.ini"

WORKDIR /workspace
COPY composer.* ./
COPY src/ src/
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --prefer-dist --no-dev
COPY tests/ tests/
COPY demo/*.php demo/
COPY demo/public/ demo/public/

EXPOSE 8080
CMD ["sh", "-c", "php demo/setup.php && exec php -S 0.0.0.0:8080 -t demo/public"]
