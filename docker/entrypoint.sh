#!/bin/bash
set -e

# Railway injects $PORT and routes traffic there. Default to 80 if unset
# (e.g. running locally without Railway's env).
PORT="${PORT:-80}"
echo "=== Configuring Apache to listen on port $PORT ==="
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-enabled/000-default.conf

echo "=== BEFORE fix: mods-enabled mpm state ==="
ls -la /etc/apache2/mods-enabled/ | grep -i mpm || echo "no mpm symlinks found"

echo "=== Re-applying MPM fix at runtime (in case something reset it after build) ==="
a2dismod mpm_event mpm_worker 2>/dev/null || true
a2enmod mpm_prefork 2>/dev/null || true
a2enmod rewrite 2>/dev/null || true

echo "=== AFTER fix: mods-enabled mpm state ==="
ls -la /etc/apache2/mods-enabled/ | grep -i mpm || echo "no mpm symlinks found"

echo "=== apache2ctl -M after runtime fix ==="
apache2ctl -M 2>&1 || true

service cron start
exec "$@"