#!/usr/bin/env bash
# Backend deploy. Run on the server from /var/www/lexible/backend.
#
#   ./deploy.sh
#
# Safe to re-run: it only touches tracked files, never .env or storage.
set -euo pipefail

PHP=${PHP:-php8.4}
BRANCH=${BRANCH:-main}

cd "$(dirname "$0")"

echo "→ Kod tortilmoqda ($BRANCH)"
git fetch --quiet origin "$BRANCH"
git reset --hard --quiet "origin/$BRANCH"

echo "→ Bogʼliqliklar"
composer install --no-dev --optimize-autoloader --no-interaction --quiet

echo "→ Migratsiyalar"
$PHP artisan migrate --force

# Caches are rebuilt from scratch: a stale package cache from another machine
# is the classic way to break a deploy.
echo "→ Keshlar"
rm -f bootstrap/cache/*.php
$PHP artisan package:discover --quiet
$PHP artisan config:cache --quiet
$PHP artisan route:cache --quiet
$PHP artisan view:cache --quiet

echo "→ Ruxsatlar"
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

echo "✅ Backend yangilandi — $(git log -1 --format='%h %s')"
