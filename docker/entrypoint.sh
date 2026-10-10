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

# Rebuild PEM from dashboard values that flatten newlines or store the two
# characters "\n" instead of a line break. Never print the input.
normalize_certificate_pem() {
    awk '
BEGIN { raw = "" }
{
    if (NR == 1) {
        raw = $0
    } else {
        raw = raw "\n" $0
    }
}
END {
    text = raw
    gsub(/\\r\\n/, "\n", text)
    gsub(/\\n/, "\n", text)
    gsub(/\\r/, "\n", text)
    gsub(/\r/, "\n", text)

    begin_marker = "-----BEGIN CERTIFICATE-----"
    end_marker = "-----END CERTIFICATE-----"
    rest = text
    blocks = 0
    output = ""

    while (1) {
        start = index(rest, begin_marker)
        if (start == 0) {
            break
        }

        rest = substr(rest, start + length(begin_marker))
        stop = index(rest, end_marker)
        if (stop == 0) {
            exit 3
        }

        body = substr(rest, 1, stop - 1)
        rest = substr(rest, stop + length(end_marker))
        gsub(/[ \t\r\n]/, "", body)

        if (body ~ "[^A-Za-z0-9+/=]") {
            exit 3
        }

        if (body == "" || (length(body) % 4) != 0) {
            exit 3
        }

        output = output begin_marker "\n"
        remainder = body
        while (length(remainder) > 64) {
            output = output substr(remainder, 1, 64) "\n"
            remainder = substr(remainder, 65)
        }
        output = output remainder "\n" end_marker "\n"
        blocks++
    }

    if (blocks == 0) {
        exit 2
    }

    printf "%s", output
}
'
}

write_database_ca() {
    certificate="${DB_SSL_CA:-}"

    if [ -z "$certificate" ]; then
        return 0
    fi

    case "$certificate" in
        \"*\")
            certificate=${certificate#\"}
            certificate=${certificate%\"}
            ;;
    esac

    certificate_directory="${MYSQL_CA_DIRECTORY:-/var/www/html/storage/app/certs}"
    certificate_path="${certificate_directory}/mysql-ca.pem"

    status=0
    normalized=$(printf '%s' "$certificate" | normalize_certificate_pem 2>/dev/null) || status=$?

    if [ "$status" -eq 0 ]; then
        mkdir -p "$certificate_directory"
        # Command substitution strips trailing newlines. Put one back.
        printf '%s\n' "$normalized" > "$certificate_path"
        chmod 600 "$certificate_path"

        if ! command -v openssl >/dev/null 2>&1; then
            rm -f "$certificate_path"
            fail_boot "DB_SSL_CA could not be checked because the openssl command is missing. The value was not logged."
        fi

        if ! openssl crl2pkcs7 -nocrl -certfile "$certificate_path" -out /dev/null >/dev/null 2>&1; then
            rm -f "$certificate_path"
            fail_boot "DB_SSL_CA is not a valid certificate. Paste the Aiven CA again. The value was not logged."
        fi

        block_count=$(printf '%s' "$normalized" | awk 'BEGIN { count = 0 } /-----BEGIN CERTIFICATE-----/ { count++ } END { print count }')
        export DB_SSL_CA="$certificate_path"
        printf 'Database CA certificate written (%s block(s)).\n' "$block_count" >&2
        return 0
    fi

    if [ "$status" -eq 2 ]; then
        if [ -f "$certificate" ] && [ -r "$certificate" ]; then
            export DB_SSL_CA="$certificate"
            printf 'Database CA certificate file is readable.\n' >&2
            return 0
        fi

        fail_boot "DB_SSL_CA must be a PEM certificate, including the BEGIN CERTIFICATE and END CERTIFICATE lines, or a readable file inside the container. The value was not logged."
    fi

    if [ "$status" -eq 127 ]; then
        fail_boot "DB_SSL_CA could not be checked because awk is missing. The value was not logged."
    fi

    fail_boot "DB_SSL_CA has a certificate header but the body is not valid PEM. Paste the Aiven CA again. The value was not logged."
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

# Never let a bootstrap failure stop nginx. One warning line, no secrets.
run_super_admin_bootstrap() {
    app_env=$(printf '%s' "${APP_ENV:-production}" | tr -d '[:space:]')

    if [ "$app_env" != "production" ]; then
        return 0
    fi

    if [ -z "${BOOTSTRAP_SUPER_ADMIN_EMAIL:-}" ] || [ -z "${BOOTSTRAP_SUPER_ADMIN_PASSWORD_HASH:-}" ]; then
        return 0
    fi

    set +e
    php artisan create-super-admin --no-interaction
    status=$?
    set -e

    if [ "$status" -ne 0 ]; then
        printf '%s\n' 'Warning: Super Admin bootstrap did not finish. The web server will still start.' >&2
    fi

    return 0
}

if [ "${1:-}" = "--write-database-ca" ]; then
    write_database_ca
    exit 0
fi

if [ "${1:-}" = "--bootstrap-super-admin" ]; then
    run_super_admin_bootstrap
    exit 0
fi

cd /var/www/html

require_non_local_settings
write_database_ca
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

run_super_admin_bootstrap

exec "$@"
