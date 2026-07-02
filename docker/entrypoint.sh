#!/bin/bash
set -e

# Start cron in the background
service cron start

# Hand off to the main container command (apache2-foreground)
exec "$@"