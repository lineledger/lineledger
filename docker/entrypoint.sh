#!/bin/bash
set -euo pipefail

ROLE="${CONTAINER_ROLE:-app}"

# A platform that runs a component's command through the image ENTRYPOINT
# would otherwise run this script twice (DigitalOcean's app spec names it
# explicitly, so it works either way).
case "${1:-}" in
    entrypoint.sh | /usr/local/bin/entrypoint.sh) shift ;;
esac

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
    echo "On a platform without a .env file, set APP_KEY as an encrypted" >&2
    echo "environment variable instead (docker/digitalocean/deploy.sh does this" >&2
    echo "for DigitalOcean App Platform)." >&2
    echo "" >&2
    echo "Keep that value backed up: it encrypts sessions and secrets, and" >&2
    echo "losing it makes that data unrecoverable." >&2
    exit 1
fi

# Managed MySQL (DigitalOcean and others) requires TLS and hands over its CA
# certificate as an env var; PDO wants a file. config/database.php reads the
# path from MYSQL_ATTR_SSL_CA.
if [ -n "${DB_CA_CERT:-}" ] && [ -z "${MYSQL_ATTR_SSL_CA:-}" ]; then
    printf '%s\n' "$DB_CA_CERT" > /tmp/db-ca.pem
    export MYSQL_ATTR_SSL_CA=/tmp/db-ca.pem
fi

echo "Waiting for database at ${DB_HOST:-mysql}:${DB_PORT:-3306} ..."
for i in $(seq 1 60); do
    if db_error=$(php -r '
        $ca = getenv("MYSQL_ATTR_SSL_CA");
        try {
            new PDO(
                sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST") ?: "mysql", getenv("DB_PORT") ?: "3306", getenv("DB_DATABASE") ?: "lineledger"),
                getenv("DB_USERNAME") ?: "lineledger",
                getenv("DB_PASSWORD") ?: "",
                $ca ? [Pdo\Mysql::ATTR_SSL_CA => $ca] : []
            );
            exit(0);
        } catch (Throwable $e) {
            echo $e->getMessage();
            exit(1);
        }'); then
        break
    fi
    if [ "$i" = "60" ]; then
        echo "ERROR: database never became ready: ${db_error}" >&2
        exit 1
    fi
    # Say why on the first miss, too: a platform health check may kill the
    # container long before the last attempt.
    if [ "$i" = "1" ]; then
        echo "Database not reachable yet: ${db_error}" >&2
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
    # app:upgrade = migrations + every post-upgrade step. MIGRATE_ON_BOOT=false
    # lets an operator take a backup first and run it by hand.
    case "$(printf '%s' "${MIGRATE_ON_BOOT:-true}" | tr '[:upper:]' '[:lower:]')" in
        false|0|no)
            echo "MIGRATE_ON_BOOT=false: skipping migrations and post-upgrade steps. Run php artisan app:upgrade yourself (docker compose exec app php artisan app:upgrade), unless a release job does it."
            ;;
        *)
            php artisan app:upgrade
            ;;
    esac
    php artisan storage:link
    # PASSPORT_PRIVATE_KEY / PASSPORT_PUBLIC_KEY in the environment win over the
    # key files. A platform without persistent storage must use them, or every
    # deploy would mint new keys and sign out every OAuth client.
    if [ ! -f storage/oauth-private.key ] && [ -z "${PASSPORT_PRIVATE_KEY:-}" ]; then
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
