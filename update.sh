#!/bin/bash

if [ "$EUID" -eq 0 ]
        then echo "Do run this script as adsight user"
        echo "sudo -u adsight ./update.sh"
        exit;
fi

# Update the admin panel and pull the latest changes from the repository, then clear and cache the routes and config, and finally bring the application back up.
/usr/bin/php8.4 /home/adsight/web/pricing.adsight.video/public_html/admanager/artisan down
git pull
/usr/bin/php8.4 /home/adsight/.composer/composer install --no-dev
npm ci
npm run build
/usr/bin/php8.4 /home/adsight/web/pricing.adsight.video/public_html/admanager/artisan route:clear
/usr/bin/php8.4 /home/adsight/web/pricing.adsight.video/public_html/admanager/artisan route:cache
/usr/bin/php8.4 /home/adsight/web/pricing.adsight.video/public_html/admanager/artisan cache:clear
/usr/bin/php8.4 /home/adsight/web/pricing.adsight.video/public_html/admanager/artisan config:clear
/usr/bin/php8.4 /home/adsight/web/pricing.adsight.video/public_html/admanager/artisan optimize
/usr/bin/php8.4 /home/adsight/web/pricing.adsight.video/public_html/admanager/artisan up
