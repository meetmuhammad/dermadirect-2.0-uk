FROM php:8.2-apache

# System libraries required to build the PHP extensions below.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libfreetype6-dev \
        libwebp-dev \
        libxml2-dev \
        libonig-dev \
        unzip \
        git \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        mysqli \
        pdo_mysql \
        gd \
        zip \
        intl \
        exif \
        mbstring \
        bcmath \
        soap \
        opcache

# Composer binary only — copied from the official image at build time. Project dependencies
# are never installed automatically; they are pulled explicitly via the `composer` compose
# service so the app image build never depends on network access to package registries.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY 000-default.conf /etc/apache2/sites-available/000-default.conf
COPY php.ini /usr/local/etc/php/conf.d/local-overrides.ini

RUN a2enmod rewrite headers expires

WORKDIR /var/www/html
