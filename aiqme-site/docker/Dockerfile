# Образ AIQME (W-20, §8.1, §8.3). Контекст — только чистый экспорт: локально bin/build-context, на сервере git archive.
# Цели: app (PHP-FPM 8.4; тот же образ у worker и scheduler) и web (nginx с public/ образа app).
# Теги закреплены (Debian 13 trixie); apt-пакеты из deb.debian.org скачиваются только здесь (D-20).

FROM php:8.4-fpm-trixie AS php
# Расширения §2.1; gd — с JPEG, WebP и FreeType.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libfreetype-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
        libicu-dev libpq-dev libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" bcmath intl gd pdo_pgsql pgsql zip exif opcache \
    && rm -rf /var/lib/apt/lists/*
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/aiqme.ini "$PHP_INI_DIR/conf.d/zz-aiqme.ini"
COPY docker/php/zz-aiqme-fpm.conf /usr/local/etc/php-fpm.d/zz-aiqme.conf
WORKDIR /var/www/html

# Зависимости PHP без dev-пакетов.
FROM php AS vendor
COPY --from=composer:2.8.12 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && composer dump-autoload --no-dev --optimize --no-interaction

# Сборка Vite; vendor/ — из PHP-стадии.
FROM node:22-trixie-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
COPY --from=vendor /var/www/html/vendor ./vendor
RUN npm run build

FROM php AS app
COPY --from=vendor /var/www/html /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build
# Каталоги записи — пользователю PHP-FPM; public/storage — относительная симлинка на том storage (/storage/… раздаёт web).
RUN mkdir -p storage/app/private storage/app/public \
    && chown -R www-data:www-data bootstrap/cache storage \
    && ln -sfn ../storage/app/public public/storage

FROM nginx:1.30-trixie AS web
COPY --from=app /var/www/html/public /var/www/html/public
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
