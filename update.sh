#!/bin/bash
set -euo pipefail

if [ "$EUID" -eq 0 ]
        then echo "Do run this script as adsight user"
        echo "sudo -u adsight ./update.sh"
        exit 1
fi

APP_DIR=/home/adsight/web/pricing.adsight.video/public_html/admanager
PHP=/usr/bin/php8.4
ARTISAN="$PHP $APP_DIR/artisan"

cd "$APP_DIR"

# Put the app in maintenance mode, pull the latest changes, install dependencies, run migrations,
# rebuild caches and bring the application back up. With `set -e` any failure stops the script
# and leaves the app in maintenance mode so a broken deploy is never served.
$ARTISAN down
git pull --ff-only
$PHP /home/adsight/.composer/composer install --no-dev --no-interaction --optimize-autoloader
npm ci
npm run build
$ARTISAN migrate --force
$ARTISAN route:clear
$ARTISAN route:cache
$ARTISAN cache:clear
$ARTISAN config:clear
$ARTISAN optimize
$ARTISAN up
