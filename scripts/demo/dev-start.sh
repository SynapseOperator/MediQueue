#!/usr/bin/env bash
set -euo pipefail
cd /var/www/html
if [[ ! -e .env.demo && ! -L .env.demo ]]; then
    ln -s /run/mediqueue-runtime/app.env .env.demo
elif [[ ! -L .env.demo || "$(readlink .env.demo)" != /run/mediqueue-runtime/app.env ]]; then
    echo 'An existing .env.demo must not be overwritten. Use a fresh checkout for this devcontainer.' >&2
    exit 1
fi
composer install --no-interaction --prefer-dist --no-progress
npm ci
npm run build
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
exec php artisan serve --host=0.0.0.0 --port=8000 --no-reload
