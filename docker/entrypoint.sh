#!/bin/bash
set -e

echo "=== Enabled Apache modules (mods-enabled symlinks) ==="
ls -la /etc/apache2/mods-enabled/ | grep -i mpm || echo "no mpm symlinks found"
echo "==============================================="

echo "=== Any hardcoded LoadModule mpm lines outside mods-enabled? ==="
grep -rn "LoadModule mpm" /etc/apache2/ 2>/dev/null || echo "none found via grep"
echo "==============================================="

echo "=== Full apache2ctl -M output ==="
apache2ctl -M 2>&1 || true
echo "==============================================="

echo "=== apache2ctl -S (parsed config dump, shows what's actually active) ==="
apache2ctl -S 2>&1 || true
echo "==============================================="

service cron start
exec "$@"