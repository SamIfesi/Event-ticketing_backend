#!/bin/bash
set -e

echo "=== Enabled Apache modules ==="
apache2ctl -M 2>&1 || true
echo "==============================="

service cron start
exec "$@"