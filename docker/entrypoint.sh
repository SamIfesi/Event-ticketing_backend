#!/bin/bash
set -e

# Laravel checks for a physical .env file on disk at boot in some setups.
# Railway injects config as real OS environment variables instead of a
# file, which Laravel's env()/config() helpers read fine on their own —
# but the file_exists() guard some apps have still needs a file present.
# An empty file satisfies the check without duplicating the 24 Railway
# variables already injected into the process environment.
if [ ! -f /var/www/html/.env ]; then
    echo "=== No .env file found, creating empty placeholder (Railway vars are injected via process env) ==="
    touch /var/www/html/.env
    chown www-data:www-data /var/www/html/.env
fi

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

# /etc/cron.d/ files can be silently rejected by cron if they don't end
# with a trailing newline (a common gotcha with files edited via web
# editors like GitHub's, which don't always make it obvious whether one
# is present). Force one here so we never depend on git/editor behavior.
CRONFILE="/etc/cron.d/ticketer-workers"
if [ -f "$CRONFILE" ] && [ -n "$(tail -c 1 "$CRONFILE")" ]; then
    echo "=== Crontab missing trailing newline, fixing ==="
    echo "" >> "$CRONFILE"
fi
echo "=== Final crontab content (with visible line endings) ==="
cat -A "$CRONFILE"

service cron start
exec "$@"