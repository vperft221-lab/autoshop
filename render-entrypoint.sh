#!/bin/sh
set -e
PORT="${PORT:-10000}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Seeds demo data on first boot only (skips automatically if already seeded)
php /var/www/html/database/seed.php || true
chown -R www-data:www-data /var/www/html/storage

exec apache2-foreground
