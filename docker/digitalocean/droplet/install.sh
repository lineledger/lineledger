#!/usr/bin/env bash
# Install LineLedger on a fresh Ubuntu 24.04 DigitalOcean Droplet.
#
# As Droplet user data (Create Droplet -> Advanced options -> Add
# initialization scripts), which installs and starts it on first boot:
#
#   #!/bin/bash
#   curl -fsSL https://raw.githubusercontent.com/lineledger/lineledger/v1.3.0/docker/digitalocean/droplet/install.sh | bash
#
# For the Marketplace image, Packer runs it with --image: everything except
# the per-Droplet setup (secrets and address), which then runs on each
# customer's first boot (the lineledger-setup systemd service).
#
# Environment:
#   LINELEDGER_VERSION   release to install (default: the newest release)
#   LINELEDGER_SRC       local copy of the repo's docker/ directory to install
#                        from instead of downloading (Packer uploads one)

set -euo pipefail

RELEASE=${LINELEDGER_VERSION:-}
SRC=${LINELEDGER_SRC:-}
DIR=/opt/lineledger
image_mode=
[ "${1:-}" = "--image" ] && image_mode=1

[ "$(id -u)" = 0 ] || { echo "Run as root." >&2; exit 1; }
# Read os-release in subshells: it defines VERSION, ID and friends.
os_id=$(. /etc/os-release && echo "${ID:-}")
codename=$(. /etc/os-release && echo "${VERSION_CODENAME:-}")
[ "$os_id" = ubuntu ] || echo "WARNING: built for Ubuntu 24.04; this is $(. /etc/os-release && echo "${PRETTY_NAME:-unknown}")." >&2

exec > >(tee -a /var/log/lineledger-install.log) 2>&1

if [ -z "$RELEASE" ]; then
    RELEASE=$(curl -fsSL https://api.github.com/repos/lineledger/lineledger/releases/latest \
        | grep -m 1 '"tag_name"' | sed -E 's/.*"tag_name": *"([^"]+)".*/\1/')
    [ -n "$RELEASE" ] || { echo "Couldn't look up the newest release; set LINELEDGER_VERSION." >&2; exit 1; }
fi
RELEASE=${RELEASE#v}
echo "==> Installing LineLedger $RELEASE ($(date -u +%FT%TZ))"

export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -yq ca-certificates curl gnupg jq ufw unattended-upgrades

# Docker Engine and the Compose plugin, from Docker's own repository.
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu ${codename} stable" \
    > /etc/apt/sources.list.d/docker.list
apt-get update -q
apt-get install -yq docker-ce docker-ce-cli containerd.io docker-compose-plugin
systemctl enable --now docker

# Security updates install themselves.
cat > /etc/apt/apt.conf.d/20auto-upgrades << 'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF

# Only SSH and the web are reachable. (Docker publishes only 80/443; MySQL
# stays on the internal network.)
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 443/udp
ufw --force enable

# The release's compose bundle, and the lineledger command.
mkdir -p "$DIR"
fetch() { # fetch PATH-UNDER-docker/ DESTINATION
    if [ -n "$SRC" ]; then
        cp "$SRC/$1" "$2"
    else
        curl -fsSL "https://raw.githubusercontent.com/lineledger/lineledger/v$RELEASE/docker/$1" -o "$2"
    fi
}
fetch docker-compose.yml "$DIR/docker-compose.yml"
fetch .env.example "$DIR/.env.example"
fetch backup.sh "$DIR/backup.sh"
fetch restore.sh "$DIR/restore.sh"
fetch digitalocean/droplet/lineledger /usr/local/bin/lineledger
fetch digitalocean/droplet/motd /etc/update-motd.d/99-lineledger
chmod 0755 "$DIR/backup.sh" "$DIR/restore.sh" /usr/local/bin/lineledger /etc/update-motd.d/99-lineledger
printf '%s\n' "$RELEASE" > "$DIR/VERSION"

# Pull the images now, so the first boot only has to start them.
docker pull -q "ghcr.io/lineledger/lineledger:$RELEASE"
docker pull -q mysql:8.4

if [ -n "$image_mode" ]; then
    # Each Droplet made from the image gets its own secrets on first boot: this
    # runs at boot until /opt/lineledger/.env exists, then never again.
    cat > /etc/systemd/system/lineledger-setup.service << 'EOF'
[Unit]
Description=LineLedger first-boot setup
Wants=network-online.target
After=network-online.target docker.service
Requires=docker.service
ConditionPathExists=!/opt/lineledger/.env

[Service]
Type=oneshot
ExecStart=/usr/local/bin/lineledger setup
StandardOutput=append:/var/log/lineledger-setup.log
StandardError=append:/var/log/lineledger-setup.log
TimeoutStartSec=15min

[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
    systemctl enable lineledger-setup.service
    echo "==> Image ready; LineLedger sets itself up on each Droplet's first boot."
else
    /usr/local/bin/lineledger setup 2>&1 | tee -a /var/log/lineledger-setup.log
fi
