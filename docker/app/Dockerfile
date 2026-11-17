FROM node:24-bookworm-slim AS frontend
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM php:8.4-cli-bookworm AS php-base
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libicu-dev libzip-dev libonig-dev libcurl4-openssl-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath intl mbstring zip pcntl curl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/app/php.ini /usr/local/etc/php/conf.d/mediqueue.ini
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --no-dev --optimize \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && ln -s /run/mediqueue-runtime/app.env .env.demo \
    && chown -R www-data:www-data storage bootstrap/cache
COPY --from=frontend /build/public/build public/build
ENV APP_ENV=demo
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]

FROM php-base AS development
COPY --from=frontend /usr/local/bin/node /usr/local/bin/node
COPY --from=frontend /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && composer install --no-interaction --prefer-dist --no-progress

FROM php-base AS runtime
USER www-data
