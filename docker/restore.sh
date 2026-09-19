#!/usr/bin/env bash
#
# LineLedger Docker restore: load a backup.sh database dump — and optionally
# its storage archive — back into the stack.
#
#   restore.sh [--dir <compose-dir>] [--yes] <lineledger-db-*.sql.gz> [lineledger-storage-*.tar.gz]
#
# In order:
#   1. stops app, queue and scheduler (mysql stays up)
#   2. drops and recreates the database, then loads the dump as root — the
#      audit-log immutability triggers carry a root DEFINER, so the app user
#      could not recreate them
#   3. with a storage archive: empties the storage volume and unpacks the
#      archive into it
#   4. docker compose up -d --wait, then php artisan app:upgrade --verify
#
# If .env has changed since the backup, copy the matching lineledger-env-<ts>
# file back over .env first — APP_KEY in particular must match the dump.
set -euo pipefail

usage() {
    cat <<'USAGE'
Usage: restore.sh [--dir <compose-dir>] [--yes] <db-dump.sql.gz> [storage.tar.gz]

  <db-dump.sql.gz>   lineledger-db-<ts>.sql.gz written by backup.sh
  [storage.tar.gz]   lineledger-storage-<ts>.tar.gz from the same run
                     (omit to restore the database only)
  --dir <path>       Directory holding docker-compose.yml and .env
                     (default: the directory this script lives in)
  -y, --yes          Skip the confirmation prompt
  -h, --help         Show this help
USAGE
}

# Absolute path of a file, resolved before any cd so relative arguments mean
# what the caller expects.
abs_file() {
    local dir
    dir="$(cd "$(dirname "$1")" && pwd -P)"
    printf '%s/%s\n' "$dir" "$(basename "$1")"
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
COMPOSE_DIR="$SCRIPT_DIR"
DUMP=""
STORAGE=""
ASSUME_YES=0

while [ $# -gt 0 ]; do
    case "$1" in
        --dir)     COMPOSE_DIR="${2:?--dir needs a path}"; shift 2 ;;
        --dir=*)   COMPOSE_DIR="${1#--dir=}"; shift ;;
        -y|--yes)  ASSUME_YES=1; shift ;;
        -h|--help) usage; exit 0 ;;
        -*)        echo "ERROR: unknown option: $1" >&2; usage >&2; exit 2 ;;
        *)
            if [ -z "$DUMP" ]; then
                DUMP="$1"
            elif [ -z "$STORAGE" ]; then
                STORAGE="$1"
            else
                echo "ERROR: too many arguments." >&2; usage >&2; exit 2
            fi
            shift ;;
    esac
done

if [ -z "$DUMP" ]; then
    usage >&2
    exit 2
fi

# The argument order matters (the first is piped into mysql), so refuse
# anything that does not look like what it should be.
case "$DUMP" in
    *.sql.gz) ;;
    *) echo "ERROR: first argument must be a .sql.gz database dump: $DUMP" >&2; exit 2 ;;
esac
if [ -n "$STORAGE" ]; then
    case "$STORAGE" in
        *.tar.gz) ;;
        *) echo "ERROR: second argument must be a .tar.gz storage archive: $STORAGE" >&2; exit 2 ;;
    esac
fi

[ -r "$DUMP" ] || { echo "ERROR: cannot read $DUMP" >&2; exit 1; }
DUMP="$(abs_file "$DUMP")"
if [ -n "$STORAGE" ]; then
    [ -r "$STORAGE" ] || { echo "ERROR: cannot read $STORAGE" >&2; exit 1; }
    STORAGE="$(abs_file "$STORAGE")"
fi

cd "$COMPOSE_DIR"
COMPOSE_DIR="$PWD"

if [ ! -f docker-compose.yml ]; then
    echo "ERROR: no docker-compose.yml in $COMPOSE_DIR (use --dir)." >&2
    exit 1
fi
if [ ! -f .env ]; then
    echo "ERROR: no .env in $COMPOSE_DIR — restore the lineledger-env-<ts> copy first." >&2
    exit 1
fi

if [ -z "$(docker compose ps --status running -q mysql)" ]; then
    echo "ERROR: the mysql service is not running (docker compose up -d mysql)." >&2
    exit 1
fi

# Same lookup as backup.sh: the real volume name, not a hardcoded guess.
STORAGE_VOLUME=""
if [ -n "$STORAGE" ]; then
    compose_json="$(docker compose config --format json)"
    if command -v jq >/dev/null 2>&1; then
        STORAGE_VOLUME="$(printf '%s' "$compose_json" | jq -r '.volumes.storage.name // empty')"
    elif command -v python3 >/dev/null 2>&1; then
        STORAGE_VOLUME="$(printf '%s' "$compose_json" | python3 -c 'import json, sys; print(json.load(sys.stdin)["volumes"]["storage"].get("name", ""))')"
    else
        project="$(printf '%s' "$compose_json" | sed -n 's/^[[:space:]]*"name":[[:space:]]*"\([^"]*\)".*/\1/p' | head -n 1)"
        STORAGE_VOLUME="${project:+${project}_storage}"
    fi
    if [ -z "$STORAGE_VOLUME" ]; then
        echo "ERROR: could not resolve the storage volume name from 'docker compose config'." >&2
        exit 1
    fi
    if ! docker volume inspect "$STORAGE_VOLUME" >/dev/null 2>&1; then
        echo "ERROR: volume '$STORAGE_VOLUME' does not exist — has the stack ever been started?" >&2
        exit 1
    fi
fi

# Prove the archives are intact before anything is stopped or dropped.
echo "==> Checking $DUMP"
gzip -t "$DUMP"
if [ -n "$STORAGE" ]; then
    echo "==> Checking $STORAGE"
    gzip -t "$STORAGE"
fi

if [ "$ASSUME_YES" != 1 ]; then
    if [ ! -t 0 ]; then
        echo "ERROR: stdin is not a terminal; pass --yes to skip the confirmation." >&2
        exit 1
    fi
    echo
    echo "This will, in $COMPOSE_DIR:"
    echo "  - stop app, queue and scheduler"
    echo "  - DROP and recreate the database, then load $DUMP"
    if [ -n "$STORAGE" ]; then
        echo "  - ERASE the storage volume ($STORAGE_VOLUME) and unpack $STORAGE into it"
    fi
    echo "  - start the stack and run php artisan app:upgrade --verify"
    echo
    read -r -p "Type 'restore' to continue: " answer
    if [ "$answer" != "restore" ]; then
        echo "Aborted."
        exit 1
    fi
fi

echo "==> Stopping app, queue and scheduler"
docker compose stop app queue scheduler
trap 'echo "ERROR: restore failed — the app services are still stopped. Inspect, then: docker compose up -d" >&2' ERR

echo "==> Recreating the database and loading $DUMP"
docker compose exec -T mysql sh -c \
    'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS \`$MYSQL_DATABASE\`; CREATE DATABASE \`$MYSQL_DATABASE\`;"'
gunzip -c "$DUMP" | docker compose exec -T mysql sh -c \
    'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'

if [ -n "$STORAGE" ]; then
    echo "==> Replacing the contents of volume $STORAGE_VOLUME with $STORAGE"
    storage_dir="$(dirname "$STORAGE")"
    storage_name="$(basename "$STORAGE")"
    docker run --rm \
        -v "$STORAGE_VOLUME:/to" \
        -v "$storage_dir:/from:ro" \
        -e "ARCHIVE=/from/$storage_name" \
        alpine:3 sh -ec 'find /to -mindepth 1 -delete && tar xzf "$ARCHIVE" -C /to'
fi

echo "==> Starting the stack"
docker compose up -d --wait

echo "==> Verifying"
docker compose exec -T app php artisan app:upgrade --verify

trap - ERR
echo
echo "Restore complete."
