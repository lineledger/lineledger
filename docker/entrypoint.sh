#!/bin/bash
set -euo pipefail

ROLE="${CONTAINER_ROLE:-app}"

if [ -z "${APP_KEY:-}" ]; then
    # Let the fix-it command itself through (it needs no key, DB, or storage).
    case "$*" in *key:generate*)
        exec "$@"
    esac
    echo "ERROR: APP_KEY is not set." >&2
    echo "" >&2
    echo "Generate one with:" >&2
    echo "    docker compose run --rm --no-deps app php artisan key:generate --show" >&2
    echo "then add it to your .env file as APP_KEY=base64:..." >&2
    echo "" >&2
    echo "Keep that value backed up: it encrypts sessions and secrets, and" >&2
    echo "losing it makes that data unrecoverable." >&2
    exit 1
fi

echo "Waiting for database at ${DB_HOST:-mysql}:${DB_PORT:-3306} ..."
for i in $(seq 1 60); do
    if php -r '
        try {
            new PDO(
                sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST") ?: "mysql", getenv("DB_PORT") ?: "3306", getenv("DB_DATABASE") ?: "lineledger"),
                getenv("DB_USERNAME") ?: "lineledger",
                getenv("DB_PASSWORD") ?: ""
            );
            exit(0);
        } catch (Throwable $e) {
            exit(1);
        }'; then
        break
    fi
    if [ "$i" = "60" ]; then
        echo "ERROR: database never became ready" >&2
        exit 1
    fi
    sleep 2
done

# The storage named volume is seeded from the image only on FIRST use; a
# directory added in a later release must be created here too. Keep this
# list in sync with the roles in config/filesystems.php.
mkdir -p \
    storage/app/private/attachments \
    storage/app/private/backups \
    storage/app/private/livewire-tmp \
    storage/app/private/restores \
    storage/app/public \
    storage/app/proof \
    storage/app/slip-templates \
    storage/fonts \
    storage/logs \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/testing

if [ "$ROLE" = "app" ]; then
    php artisan migrate --force
    php artisan storage:link
    if [ ! -f storage/oauth-private.key ]; then
        php artisan passport:keys
    fi
fi

# Cache from the current environment on every start so .env changes
# take effect on restart. bootstrap/cache is container-local.
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "LineLedger ready (role: ${ROLE})"
exec "$@"
