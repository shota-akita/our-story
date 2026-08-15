FROM php:8.4-apache

RUN docker-php-ext-install mysqli

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json /var/www/html/composer.json
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

COPY . /var/www/html

RUN a2enmod rewrite

EXPOSE 80
