#!/bin/sh
# Prepare the application, then start the process supervisor.
# Required settings are checked before any cache is written.
set -eu

fail_boot() {
    printf 'Refusing to boot. %s\n' "$1" >&2
    exit 1
}

append_problem() {
    if [ -n "$problems" ]; then
        problems="$problems; $1"
    else
        problems="$1"
    fi
}

require_non_local_settings() {
    app_env=$(printf '%s' "${APP_ENV:-production}" | tr -d '[:space:]')

    if [ "$app_env" = "local" ]; then
        return 0
    fi

    problems=""
    app_debug=$(printf '%s' "${APP_DEBUG:-}" | tr -d '[:space:]')
    db_connection=$(printf '%s' "${DB_CONNECTION:-}" | tr -d '[:space:]')

    if [ "$app_debug" != "false" ]; then
        append_problem "APP_DEBUG must be false"
    fi

    if [ -z "$db_connection" ]; then
        append_problem "DB_CONNECTION is missing"
    elif [ "$db_connection" != "mysql" ]; then
        append_problem "DB_CONNECTION must be mysql"
    fi

    if [ -z "${APP_KEY:-}" ]; then
        append_problem "APP_KEY is missing"
    fi

    if [ -z "${APP_URL:-}" ]; then
        append_problem "APP_URL is missing"
    fi

    if [ -z "${DB_HOST:-}" ]; then
        append_problem "DB_HOST is missing"
    fi

    if [ -z "${DB_PORT:-}" ]; then
        append_problem "DB_PORT is missing"
    fi

    if [ -z "${DB_DATABASE:-}" ]; then
        append_problem "DB_DATABASE is missing"
    fi

    if [ -z "${DB_USERNAME:-}" ]; then
        append_problem "DB_USERNAME is missing"
    fi

    if [ -z "${DB_PASSWORD:-}" ]; then
        append_problem "DB_PASSWORD is missing"
    fi

    if [ -n "$problems" ]; then
        fail_boot "${problems}."
    fi
}


prepare_writable_directories() {
    mkdir -p \
        /var/www/html/storage/app/public \
        /var/www/html/storage/app/private \
        /var/www/html/storage/app/certs \
        /var/www/html/storage/framework/cache/data \
        /var/www/html/storage/framework/sessions \
        /var/www/html/storage/framework/views \
        /var/www/html/storage/logs \
        /var/www/html/bootstrap/cache \
        /tmp/nginx-client-body \
        /tmp/nginx-proxy \
        /tmp/nginx-fastcgi \
        /tmp/nginx-uwsgi \
        /tmp/nginx-scgi
}

render_nginx_config() {
    port="${PORT:-8080}"

    case "$port" in
        *[!0-9]*)
            fail_boot "PORT must be a number."
            ;;
    esac

    if [ "$port" -lt 1 ] || [ "$port" -gt 65535 ]; then
        fail_boot "PORT must be between 1 and 65535."
    fi

    export PORT="$port"
    sed "s/__PORT__/${PORT}/g" /var/www/html/docker/nginx.conf.template > /tmp/nginx.conf
}


cd /var/www/html

require_non_local_settings
prepare_writable_directories
render_nginx_config

php artisan storage:link --force --no-interaction
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi


# One write at startup so /health is not stale before the first scheduled minute.
# This shows the scheduler command ran. It does not show that the queue worker is consuming jobs.
php artisan portal:heartbeat --no-interaction

exec "$@"
