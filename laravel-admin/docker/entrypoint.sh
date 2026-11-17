#!/bin/sh
set -eu
umask 077
key_file=/var/lib/laravel/app-key
if [ "${1:-}" = initialize ]; then
    if [ ! -s "$key_file" ]; then
        php -r 'echo "base64:".base64_encode(random_bytes(32));' > "$key_file"
    fi
    export APP_KEY="$(cat "$key_file")"
    if [ ! -f /var/lib/laravel/database.sqlite ]; then
        touch /var/lib/laravel/database.sqlite
    fi
    exec php artisan migrate --database=sqlite --force --no-interaction
fi
if [ ! -s "$key_file" ] || [ ! -f /var/lib/laravel/database.sqlite ]; then
    echo 'Initialize the application first: docker compose run --rm app initialize' >&2
    exit 1
fi
export APP_KEY="$(cat "$key_file")"
exec "$@"
