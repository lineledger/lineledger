#!/bin/bash
set -euo pipefail

ROLE="${CONTAINER_ROLE:-app}"

if [ ! -f .env ]; then
    echo "No .env found — copying .env.example"
    cp .env.example .env
fi

wait_for_mysql() {
    echo "Waiting for database at ${DB_HOST:-mysql}:${DB_PORT:-3306} ..."
    for i in $(seq 1 60); do
        if php -r '
            try {
                new PDO(
                    sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST") ?: "mysql", getenv("DB_PORT") ?: "3306", getenv("DB_DATABASE") ?: "lineledger"),
                    getenv("DB_USERNAME") ?: "root",
                    getenv("DB_PASSWORD") ?: ""
                );
                exit(0);
            } catch (Throwable $e) {
                exit(1);
            }'; then
            return 0
        fi
        if [ "$i" = "60" ]; then
            echo "ERROR: database never became ready" >&2
            exit 1
        fi
        sleep 2
    done
}

# Docker env_file can export APP_KEY= (empty), which shadows the value
# artisan just wrote into .env. Always re-export a non-empty key from the file.
sync_app_key() {
    local current
    current="$(grep -E '^APP_KEY=' .env | cut -d= -f2- | tr -d '[:space:]' || true)"
    if [ -z "$current" ]; then
        php artisan key:generate --force
        current="$(grep -E '^APP_KEY=' .env | cut -d= -f2- | tr -d '[:space:]')"
    fi
    export APP_KEY="$current"
}

case "$ROLE" in
    app)
        composer install --no-interaction --prefer-dist
        npm install
        if [ "${DB_CONNECTION:-mysql}" = "sqlite" ]; then
            sync_app_key
        else
            wait_for_mysql
            sync_app_key
            php artisan migrate --force
        fi
        # Relative link so it resolves both in the container (/app) and on the host.
        ln -sfn ../storage/app/public public/storage
        if [ ! -f storage/oauth-private.key ]; then
            php artisan passport:keys --no-interaction
        fi
        ;;
    queue|scheduler)
        if [ ! -f vendor/autoload.php ]; then
            echo "ERROR: vendor/ is missing. Start the app service first (it runs composer install)." >&2
            exit 1
        fi
        wait_for_mysql
        sync_app_key
        ;;
    vite)
        if [ ! -d node_modules ]; then
            echo "ERROR: node_modules/ is missing. Start the app service first (it runs npm install)." >&2
            exit 1
        fi
        ;;
    *)
        echo "ERROR: unknown CONTAINER_ROLE='$ROLE'" >&2
        exit 1
        ;;
esac

echo "LineLedger ready (role: ${ROLE})"
exec "$@"
