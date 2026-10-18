#!/bin/sh
#
# Run Pest inside the dev container with phpunit.xml's test environment.
#
#   docker compose exec app sh docker/pest.sh            # MySQL lineledger_test
#   docker compose exec app sh docker/pest.sh --sqlite   # CI-exact SQLite :memory:
#
# docker-compose.yml exports its own settings and every .env value as real
# environment variables. PHPUnit never overrides a variable that is already set,
# so without this the suite would run against the dev database (RefreshDatabase
# wipes it) with local mail, queue and API settings.
set -eu

for name in $(sed -n 's/.*<env name="\([A-Z0-9_]*\)".*/\1/p' phpunit.xml); do
    unset "$name"
done

# phpunit.xml's DB_HOST is 127.0.0.1; inside the stack MySQL is the `mysql` service.
export DB_HOST=mysql

if [ "${1:-}" = "--sqlite" ]; then
    shift
    export DB_CONNECTION=sqlite DB_DATABASE=':memory:'
fi

exec ./vendor/bin/pest "$@"
