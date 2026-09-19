#!/usr/bin/env bash
#
# LineLedger Docker backup: database dump + storage volume + .env.
#
# Run it from (or point --dir at) the directory holding docker-compose.yml and
# .env, with the stack up (the mysql service must be running). It writes three
# timestamped files into the backup directory:
#
#   lineledger-db-<ts>.sql.gz        mysqldump of the app database
#   lineledger-storage-<ts>.tar.gz   the storage volume: attachments, logos,
#                                    in-app backups, Passport keys, ...
#   lineledger-env-<ts>              a copy of .env (holds APP_KEY; mode 600)
#
# The database password is never expanded on the host: the dump runs inside
# the mysql container, which already has MYSQL_ROOT_PASSWORD in its
# environment. Restore with restore.sh — the exact command is printed at the
# end.
#
# The backup directory gets a `.gitignore` containing `*` the first time it is
# used, so when it sits inside a git checkout (the default ./backups does, on
# a git-clone install) git never lists or adds the dumps or the .env copy.
# Prefer an out-of-tree BACKUP_DIR such as /var/backups/lineledger anyway.
set -euo pipefail

usage() {
    cat <<'USAGE'
Usage: backup.sh [--dir <compose-dir>] [--out <backup-dir>]

  --dir <path>   Directory holding docker-compose.yml and .env
                 (default: the directory this script lives in)
  --out <path>   Where to write the backup files
                 (default: $BACKUP_DIR from the environment, else BACKUP_DIR
                 from .env, else ./backups inside the compose directory —
                 a directory outside any git checkout is the better choice)
  -h, --help     Show this help
USAGE
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
COMPOSE_DIR="$SCRIPT_DIR"
OUT_DIR="${BACKUP_DIR:-}"

while [ $# -gt 0 ]; do
    case "$1" in
        --dir)     COMPOSE_DIR="${2:?--dir needs a path}"; shift 2 ;;
        --dir=*)   COMPOSE_DIR="${1#--dir=}"; shift ;;
        --out)     OUT_DIR="${2:?--out needs a path}"; shift 2 ;;
        --out=*)   OUT_DIR="${1#--out=}"; shift ;;
        -h|--help) usage; exit 0 ;;
        *)         echo "ERROR: unknown argument: $1" >&2; usage >&2; exit 2 ;;
    esac
done

cd "$COMPOSE_DIR"
COMPOSE_DIR="$PWD"

if [ ! -f docker-compose.yml ]; then
    echo "ERROR: no docker-compose.yml in $COMPOSE_DIR (use --dir)." >&2
    exit 1
fi
if [ ! -f .env ]; then
    echo "ERROR: no .env in $COMPOSE_DIR — refusing to back up a stack without its configuration." >&2
    exit 1
fi

# Absolute path of a directory: the tar step bind-mounts it into a container.
abs_dir() {
    if command -v realpath >/dev/null 2>&1; then
        realpath "$1"
    else
        (cd "$1" && pwd -P)
    fi
}

if [ -z "$OUT_DIR" ]; then
    # Fall back to BACKUP_DIR from .env so on-demand backups land beside the
    # scheduled dumps of the compose backup profile.
    OUT_DIR="$(sed -n 's/^BACKUP_DIR=//p' .env | tail -n 1 | tr -d "\"'")"
fi
OUT_DIR="${OUT_DIR:-./backups}"
mkdir -p "$OUT_DIR"
OUT_DIR="$(abs_dir "$OUT_DIR")"
if [ ! -w "$OUT_DIR" ]; then
    echo "ERROR: $OUT_DIR is not writable by $(id -un) — if the backup profile created it, fix its ownership or use --out." >&2
    exit 1
fi

# Make the directory invisible to git wherever it lives: a `.gitignore` of `*`
# ignores everything in it (itself included), so a `git add -A` in a git-clone
# install can never sweep up a ledger dump or the .env copy with APP_KEY.
if [ ! -e "$OUT_DIR/.gitignore" ]; then
    printf '*\n' > "$OUT_DIR/.gitignore"
    chmod 644 "$OUT_DIR/.gitignore"
fi

if [ -z "$(docker compose ps --status running -q mysql)" ]; then
    echo "ERROR: the mysql service is not running (docker compose up -d mysql)." >&2
    exit 1
fi

# Resolve the storage volume's real name from the compose config instead of
# hardcoding "<project>_storage": the project name comes from the top-level
# name: key and can be overridden with COMPOSE_PROJECT_NAME or -p.
compose_json="$(docker compose config --format json)"
if command -v jq >/dev/null 2>&1; then
    STORAGE_VOLUME="$(printf '%s' "$compose_json" | jq -r '.volumes.storage.name // empty')"
elif command -v python3 >/dev/null 2>&1; then
    STORAGE_VOLUME="$(printf '%s' "$compose_json" | python3 -c 'import json, sys; print(json.load(sys.stdin)["volumes"]["storage"].get("name", ""))')"
else
    # No JSON parser on the host: compose emits the project name as the first
    # "name" key and names volumes <project>_<key>.
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

ts="$(date -u +%Y%m%d-%H%M%S)"
db_file="lineledger-db-$ts.sql.gz"
storage_file="lineledger-storage-$ts.tar.gz"
env_file="lineledger-env-$ts"

# Backups hold financial data and APP_KEY: owner-only from the moment they exist.
umask 077

# A dump is written under a .part name and renamed only once it completed, so
# an interrupted run never leaves a truncated file that looks like a backup.
cleanup() { rm -f "$OUT_DIR/$db_file.part"; }
trap cleanup EXIT

echo "==> Dumping database to $OUT_DIR/$db_file"
docker compose exec -T mysql sh -c \
    'exec mysqldump --single-transaction --triggers --routines --events -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
    | gzip > "$OUT_DIR/$db_file.part"
mv "$OUT_DIR/$db_file.part" "$OUT_DIR/$db_file"

echo "==> Archiving volume $STORAGE_VOLUME to $OUT_DIR/$storage_file"
# A throwaway container reads the volume as root (the files belong to the app
# user inside the image), then hands the archive to the invoking host user so
# it can be managed and deleted without sudo. Compiled views and the framework
# cache are left out: the entrypoint recreates and re-warms them on every boot.
docker run --rm \
    -v "$STORAGE_VOLUME:/from:ro" \
    -v "$OUT_DIR:/to" \
    -e "PART=/to/$storage_file.part" \
    -e "FINAL=/to/$storage_file" \
    -e "OWNER=$(id -u):$(id -g)" \
    alpine:3 sh -c '
        if tar czf "$PART" --exclude=./framework/views --exclude=./framework/cache -C /from . && chmod 600 "$PART"; then
            chown "$OWNER" "$PART" 2>/dev/null || true
            mv "$PART" "$FINAL"
        else
            rm -f "$PART"
            exit 1
        fi'

echo "==> Copying .env to $OUT_DIR/$env_file"
cp .env "$OUT_DIR/$env_file"
chmod 600 "$OUT_DIR/$env_file"

size() { du -h "$1" | cut -f1; }

cat <<SUMMARY

Backup complete ($ts UTC) in $OUT_DIR
  $db_file  ($(size "$OUT_DIR/$db_file"))
  $storage_file  ($(size "$OUT_DIR/$storage_file"))
  $env_file  (contains APP_KEY — keep it private)

To restore this backup (stops the app, replaces the database and the storage
volume, restarts, then verifies):

  $SCRIPT_DIR/restore.sh --dir "$COMPOSE_DIR" "$OUT_DIR/$db_file" "$OUT_DIR/$storage_file"

Copy $env_file back to $COMPOSE_DIR/.env first if the current .env has
changed since — APP_KEY in particular must match the dump.
SUMMARY
