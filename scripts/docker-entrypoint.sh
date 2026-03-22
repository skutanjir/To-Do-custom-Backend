#!/bin/sh
set -e

# Force fix MPM at runtime
rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf
ln -sf /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load
ln -sf /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf

# Run migrations if database is ready
php artisan migrate --force --no-interaction

# Create storage link
php artisan storage:link

# Optimize Laravel
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Set Apache to listen on the port provided by Railway/Render
if [ -n "$PORT" ]; then
    sed -i "s/Listen 80/Listen $PORT/g" /etc/apache2/ports.conf
    sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:$PORT>/g" /etc/apache2/sites-available/000-default.conf
fi

exec apache2-foreground