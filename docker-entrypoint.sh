#!/bin/bash
set -e
PORT=${PORT:-8080}
echo "Starting PHP server on port $PORT"
exec php -S "0.0.0.0:$PORT" -t /app
