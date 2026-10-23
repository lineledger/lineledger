#!/usr/bin/env bash
# Deploy LineLedger to DigitalOcean App Platform with one command.
#
#   ./deploy.sh          first run: create everything and deploy
#   ./deploy.sh          later runs: deploy the newest image again
#
# The first run creates, in your DigitalOcean account:
#   - a managed MySQL cluster (NAME-db) with a lineledger database and user
#   - two Spaces buckets: a private one (attachments, backups, uploads waiting
#     for the queue) and a public-read one (organization logos), plus an access
#     key that can reach only those two
#   - the app, from app.yaml next to this script: web, queue worker, scheduler,
#     and a pre-deploy job that runs php artisan app:upgrade
# It generates APP_KEY, the OAuth signing keys and the passkey secret and
# stores them as encrypted app secrets. The only questions are mail settings.
#
# Options:
#   --name NAME            app name; also names the database and buckets (default: lineledger)
#   --region SLUG          datacenter for the app, database and buckets (default: tor1)
#   --spaces-region SLUG   put the buckets elsewhere, if Spaces isn't offered in --region
#   --country CA|US|GB     the country this install serves (default: CA)
#   --tag TAG              image tag of ghcr.io/lineledger/lineledger, first run only (default: latest)
#   --yes                  don't ask before creating billable resources
#
# Mail settings can come from the environment instead of prompts: MAIL_HOST,
# MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS, ALERT_EMAIL.
#
# Needs doctl (signed in: doctl auth init), jq, openssl and curl.

set -euo pipefail

name=lineledger
region=tor1
spaces_region=
country=CA
tag=latest
assume_yes=

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
warn() { printf 'WARNING: %s\n' "$*" >&2; }
step() { printf '\n==> %s\n' "$*"; }
usage() { awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "$0"; }

while [ $# -gt 0 ]; do
    case $1 in
        --name) name=${2:?--name needs a value}; shift 2 ;;
        --region) region=${2:?--region needs a value}; shift 2 ;;
        --spaces-region) spaces_region=${2:?--spaces-region needs a value}; shift 2 ;;
        --country) country=$(printf '%s' "${2:?--country needs a value}" | tr '[:lower:]' '[:upper:]'); shift 2 ;;
        --tag) tag=${2:?--tag needs a value}; shift 2 ;;
        --yes | -y) assume_yes=1; shift ;;
        -h | --help) usage; exit 0 ;;
        *) usage >&2; die "unknown option: $1" ;;
    esac
done

# Bucket names (NAME-files-xxxxxxxx) must fit in 63 characters.
[[ $name =~ ^[a-z][a-z0-9-]{0,22}[a-z0-9]$ ]] \
    || die "--name must be 2-24 lowercase letters, digits or hyphens, starting with a letter"
case $country in CA | US | GB) ;; *) die "--country must be CA, US or GB" ;; esac
spaces_region=${spaces_region:-$region}
app_region=$(printf '%s' "$region" | tr -d '0-9') # App Platform names the city: tor1 → tor
db_cluster="$name-db"

for bin in doctl jq openssl curl; do
    command -v "$bin" > /dev/null || die "$bin is required (macOS: brew install $bin)"
done

here=$(cd "$(dirname "$0")" && pwd)
template="$here/app.yaml"
[ -f "$template" ] || die "app.yaml must sit next to deploy.sh (download both from the same release)"

# First element of a doctl JSON result, which is sometimes a one-item array.
first() { jq -r "if type == \"array\" then .[0] else . end | $1"; }

account_uuid=$(doctl account get -o json 2> /dev/null | first .uuid) \
    || die "doctl isn't signed in. Run: doctl auth init"

live_url() { doctl apps get "$1" -o json | first '.live_url // .default_ingress // empty'; }
app_id_for() {
    doctl apps list -o json | jq -r --arg n "$1" '(. // [])[] | select(.spec.name == $n) | .id' | head -n 1
}

# ---------------------------------------------------------------------------
# Later runs: the app (and its secrets) already exist. Start a deployment,
# which pulls the image tag again and runs the migrate job before going live.
# ---------------------------------------------------------------------------
app_id=$(app_id_for "$name")
if [ -n "$app_id" ]; then
    [ "$tag" = latest ] || warn "--tag applies to the first run only; change the image tag under the app's Settings"
    step "App $name exists: deploying the newest image (migrations run first)"
    doctl apps create-deployment "$app_id" --wait > /dev/null \
        || die "the deployment failed. Logs: doctl apps logs $app_id --type deploy"
    printf '\nDeployed: %s\n' "$(live_url "$app_id")"
    exit 0
fi

# ---------------------------------------------------------------------------
# First run. Ask everything up front, so nothing is created and then abandoned
# halfway through a prompt.
# ---------------------------------------------------------------------------
ask() { # ask VAR "Prompt" [default] [secret|optional|secret-optional]
    local var=$1 prompt=$2 default=${3:-} mode=${4:-} current reply=''
    eval "current=\${$var:-}"
    [ -n "$current" ] && default=$current
    if [ -t 0 ]; then
        case $mode in
            secret*) read -r -s -p "  $prompt: " reply; echo ;;
            *) read -r -p "  $prompt${default:+ [$default]}: " reply ;;
        esac
    fi
    reply=${reply:-$default}
    if [ -z "$reply" ]; then
        case $mode in
            *optional) ;;
            *) die "$var is required (or set it in the environment to skip the prompt)" ;;
        esac
    fi
    eval "$var=\$reply"
}

step "Mail"
cat << 'EOF'
LineLedger emails sign-up verification links, invitations and reminders, and
nobody can finish signing up until mail works. Use any SMTP service (Postmark,
Mailgun, Amazon SES, your email host).
EOF
ask MAIL_HOST "SMTP host"
ask MAIL_PORT "SMTP port" 587
ask MAIL_USERNAME "SMTP username" '' optional
ask MAIL_PASSWORD "SMTP password" '' secret-optional
ask ALERT_EMAIL "Your email, for security and failed-job alerts"
ask MAIL_FROM_ADDRESS "Send mail from" "$ALERT_EMAIL"

suffix=$(printf '%s' "$account_uuid/$name" | openssl dgst -sha256 | awk '{ print $NF }' | cut -c1-8)
private_bucket="$name-files-$suffix"
public_bucket="$name-logos-$suffix"
endpoint="https://$spaces_region.digitaloceanspaces.com"

# Any HTTP answer means Spaces exists there; no answer means it isn't offered.
curl -s -o /dev/null --max-time 15 "$endpoint" \
    || die "Spaces isn't reachable at $endpoint. If it isn't offered in $spaces_region, pass --spaces-region (for example nyc3)."

step "About to create (billed to your DigitalOcean account)"
cat << EOF
  MySQL cluster   $db_cluster (1 node, db-s-1vcpu-1gb) in $region
  Spaces buckets  $private_bucket (private), $public_bucket (public read) in $spaces_region
  App             $name in $app_region: web, queue and scheduler (1 GB each),
                  image ghcr.io/lineledger/lineledger:$tag
EOF
if [ -z "$assume_yes" ]; then
    [ -t 0 ] || die "rerun with --yes to create these without a prompt"
    read -r -p "Continue? [y/N] " reply
    case $reply in y | Y | yes | YES) ;; *) die "nothing created" ;; esac
fi

work=$(mktemp -d)
bootstrap_key=
cleanup() {
    if [ -n "$bootstrap_key" ]; then
        doctl spaces keys delete "$bootstrap_key" < /dev/null > /dev/null 2>&1 \
            || warn "delete the temporary Spaces key $name-setup-$suffix in the control panel"
    fi
    rm -rf "$work"
}
trap cleanup EXIT
umask 077

# ---------------------------------------------------------------------------
# Database. Provisioning takes minutes, so start it now and set up Spaces
# while it boots.
# ---------------------------------------------------------------------------
step "MySQL cluster $db_cluster"
db_id=$(doctl databases list -o json | jq -r --arg n "$db_cluster" '(. // [])[] | select(.name == $n) | .id' | head -n 1)
if [ -z "$db_id" ]; then
    db_id=$(doctl databases create "$db_cluster" --engine mysql --region "$region" \
        --size db-s-1vcpu-1gb --num-nodes 1 -o json | first .id)
    echo "Provisioning in $region; this takes several minutes ..."
else
    engine=$(doctl databases get "$db_id" -o json | first .engine)
    [ "$engine" = mysql ] || die "a $engine cluster named $db_cluster already exists; pass a different --name"
    echo "Reusing the existing cluster."
fi

# ---------------------------------------------------------------------------
# Spaces. doctl can't create buckets, so this signs S3 requests with curl
# using a short-lived full-access key, deleted again on exit. The app gets its
# own key, limited to its two buckets.
# ---------------------------------------------------------------------------
step "Spaces buckets in $spaces_region"
key_json=$(doctl spaces keys create "$name-setup-$suffix" --grants 'bucket=;permission=fullaccess' -o json --show)
bootstrap_key=$(printf '%s' "$key_json" | first .access_key)
bootstrap_secret=$(printf '%s' "$key_json" | first .secret_key)
[ -n "$bootstrap_secret" ] && [ "$bootstrap_secret" != null ] || die "doctl didn't return the new Spaces key's secret"

spaces_request() { # spaces_request METHOD PATH [BODY] → HTTP status; response body in $work/response
    local hash
    hash=$(printf '%s' "${3:-}" | openssl dgst -sha256 | awk '{ print $NF }')
    curl -sS -o "$work/response" -w '%{http_code}' -X "$1" \
        --aws-sigv4 "aws:amz:$spaces_region:s3" --user "$bootstrap_key:$bootstrap_secret" \
        -H "x-amz-content-sha256: $hash" -H 'Content-Type:' \
        --data-binary "${3:-}" "$endpoint/$2"
}

create_bucket() {
    local status attempt
    for attempt in 1 2 3 4 5 6; do
        status=$(spaces_request PUT "$1") || die "couldn't reach $endpoint"
        case $status in
            200) echo "Created $1"; return ;;
            409)
                # Taken: by an earlier run of this script (fine), or by someone else.
                [ "$(spaces_request GET "$1?location")" = 200 ] && { echo "Reusing $1"; return; }
                die "the bucket name $1 belongs to another account; pass a different --name"
                ;;
            403) sleep 5 ;; # a brand-new key can take a few seconds to start working
            *) break ;;
        esac
    done
    die "creating bucket $1 failed (HTTP $status): $(cat "$work/response")"
}

create_bucket "$private_bucket"
create_bucket "$public_bucket"

# Logos are loaded by browsers straight from the bucket, so it needs public
# read. A bucket policy rather than per-object ACLs, as the README explains.
policy=$(jq -cn --arg b "$public_bucket" \
    '{Version: "2012-10-17", Statement: [{Sid: "PublicReadLogos", Effect: "Allow", Principal: "*", Action: "s3:GetObject", Resource: "arn:aws:s3:::\($b)/*"}]}')
status=$(spaces_request PUT "$public_bucket?policy" "$policy")
case $status in
    200 | 204) echo "Made $public_bucket publicly readable" ;;
    *) warn "couldn't make $public_bucket publicly readable (HTTP $status), so logos won't display until it is: $(cat "$work/response")" ;;
esac

# Drop app keys left by an earlier, unfinished run; their secrets are gone.
doctl spaces keys list -o json \
    | jq -r --arg n "$name-app-$suffix" '(. // [])[] | select(.name == $n) | .access_key' \
    | while read -r stale; do doctl spaces keys delete "$stale" < /dev/null > /dev/null; done
key_json=$(doctl spaces keys create "$name-app-$suffix" \
    --grants "bucket=$private_bucket;permission=readwrite,bucket=$public_bucket;permission=readwrite" -o json --show)
aws_key=$(printf '%s' "$key_json" | first .access_key)
aws_secret=$(printf '%s' "$key_json" | first .secret_key)

doctl spaces keys delete "$bootstrap_key" < /dev/null > /dev/null && bootstrap_key=

# ---------------------------------------------------------------------------
# Database, continued: wait until it's online, then add the app's database and
# user. App Platform reads the user's password itself.
# ---------------------------------------------------------------------------
step "Waiting for $db_cluster to come online"
for _ in $(seq 1 120); do
    [ "$(doctl databases get "$db_id" -o json | first .status)" = online ] && break
    sleep 15
done
[ "$(doctl databases get "$db_id" -o json | first .status)" = online ] || die "$db_cluster still isn't online after 30 minutes"

doctl databases db list "$db_id" -o json | jq -e '(. // [])[] | select(.name == "lineledger")' > /dev/null \
    || doctl databases db create "$db_id" lineledger > /dev/null
doctl databases user list "$db_id" -o json | jq -e '(. // [])[] | select(.name == "lineledger")' > /dev/null \
    || doctl databases user create "$db_id" lineledger > /dev/null
echo "Database lineledger and user lineledger are ready."

# ---------------------------------------------------------------------------
# Secrets, then the app.
# ---------------------------------------------------------------------------
step "Generating APP_KEY, OAuth keys and the passkey secret"
openssl genrsa -out "$work/oauth-private.key" 4096 2> /dev/null
openssl rsa -in "$work/oauth-private.key" -pubout -out "$work/oauth-public.key" 2> /dev/null
app_key="base64:$(openssl rand -base64 32)"

# Every value is JSON-quoted on the way in, which YAML reads as a plain
# double-quoted string, so passwords with ':' or '#' survive. Passport turns
# the literal \n in the keys back into newlines.
values=$(jq -n \
    --arg APP_NAME "$name" \
    --arg DO_REGION "$app_region" \
    --arg IMAGE_TAG "$tag" \
    --arg DB_CLUSTER "$db_cluster" \
    --arg COUNTRY "$country" \
    --arg APP_KEY "$app_key" \
    --arg PASSKEYS_USER_HANDLE_SECRET "$(openssl rand -hex 32)" \
    --arg PASSPORT_PRIVATE_KEY "$(cat "$work/oauth-private.key")" \
    --arg PASSPORT_PUBLIC_KEY "$(cat "$work/oauth-public.key")" \
    --arg MAIL_HOST "$MAIL_HOST" \
    --arg MAIL_PORT "$MAIL_PORT" \
    --arg MAIL_USERNAME "$MAIL_USERNAME" \
    --arg MAIL_PASSWORD "$MAIL_PASSWORD" \
    --arg MAIL_FROM_ADDRESS "$MAIL_FROM_ADDRESS" \
    --arg ALERT_EMAIL "$ALERT_EMAIL" \
    --arg AWS_ACCESS_KEY_ID "$aws_key" \
    --arg AWS_SECRET_ACCESS_KEY "$aws_secret" \
    --arg AWS_ENDPOINT "$endpoint" \
    --arg AWS_BUCKET "$private_bucket" \
    --arg AWS_PUBLIC_BUCKET "$public_bucket" \
    --arg AWS_PUBLIC_URL "https://$public_bucket.$spaces_region.digitaloceanspaces.com" \
    '$ARGS.named | .PASSPORT_PRIVATE_KEY |= gsub("\n"; "\\n") | .PASSPORT_PUBLIC_KEY |= gsub("\n"; "\\n")')

jq -Rrs --argjson v "$values" \
    'reduce ($v | to_entries[]) as $e (.; split("__\($e.key)__") | join($e.value | tojson))' \
    "$template" > "$work/app.yaml"
unfilled=$(grep -Eo '__[A-Z0-9_]+__' "$work/app.yaml" || true)
[ -z "$unfilled" ] || die "app.yaml has values deploy.sh doesn't fill in: $(printf '%s' "$unfilled" | tr '\n' ' ')"
doctl apps spec validate "$work/app.yaml" > /dev/null

step "Creating the app (the first deploy pulls the image and migrates the database, so it takes a while)"
if ! doctl apps create --spec "$work/app.yaml" --wait > /dev/null; then
    app_id=$(app_id_for "$name")
    [ -n "$app_id" ] || die "App Platform didn't create the app; see the error above"
    die "the app was created but its first deployment failed. Logs:
    doctl apps logs $app_id migrate --type deploy
    doctl apps logs $app_id web --type run
Fix the cause, then run ./deploy.sh again to redeploy (your secrets are kept)."
fi
app_id=$(app_id_for "$name")

cat << EOF

LineLedger is live at $(live_url "$app_id")
Register there: the first account becomes the site admin. Close registration
afterwards in /admin -> Site settings if this install is just for you.

Keep this APP_KEY somewhere safe, such as a password manager. It encrypts the
secrets stored in the database, so restoring a database dump needs it:

    $app_key

To use your own domain, add it under the app's Settings -> Domains; APP_URL
follows on the next deploy. To upgrade later, run ./deploy.sh again.
EOF
