#!/bin/bash
# Last step of the Marketplace image build: leave nothing in the snapshot that
# belongs to the build Droplet (keys, logs, history, cloud-init state), so
# every customer Droplet starts clean and runs its first-boot setup.
set -euo pipefail

rm -rf /tmp/lineledger-src

# DigitalOcean's agents (Droplet Console, monitoring) are DigitalOcean's to add
# to each customer Droplet; the image check fails a snapshot that has them.
for agent in droplet-agent do-agent; do
    if dpkg -s "$agent" > /dev/null 2>&1; then
        apt-get -y purge "$agent"
    fi
done
rm -rf /opt/digitalocean

apt-get -y autoremove
apt-get -y autoclean
apt-get -y clean

# cloud-init: forget the build instance. (Not `cloud-init clean`, which also
# deletes /var/lib/cloud/scripts.) LineLedger's own first-boot setup is the
# lineledger-setup systemd service, which runs until /opt/lineledger/.env exists.
rm -rf /var/lib/cloud/instances/* /var/lib/cloud/instance
[ ! -e /opt/lineledger/.env ] || { echo "ERROR: /opt/lineledger/.env exists; the image would share secrets." >&2; exit 1; }

# SSH host keys are regenerated on first boot; build-time root keys must go.
rm -f /etc/ssh/ssh_host_*
rm -f /root/.ssh/authorized_keys

# Logs and shell history from the build.
find /var/log -type f -name '*.gz' -delete
find /var/log -type f -name '*.[0-9]' -delete
find /var/log -type f -exec truncate -s 0 {} \;
rm -f /root/.bash_history
unset HISTFILE

rm -rf /tmp/* /var/tmp/*

# Zero free space so the snapshot compresses well. dd stops when the disk is
# full, so its "No space left on device" is expected.
echo "Zeroing free space; ignore the 'No space left on device' that ends it."
dd if=/dev/zero of=/zerofile bs=4M status=none || true
rm -f /zerofile
sync
