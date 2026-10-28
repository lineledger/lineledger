# DigitalOcean Marketplace image

This builds the snapshot behind the **LineLedger** 1-Click App on the
DigitalOcean Marketplace: Ubuntu 24.04, Docker, and the LineLedger Compose
stack from `docker/` with its images pre-pulled. Nothing in the snapshot is
specific to one customer. On each new Droplet's first boot, the
`lineledger-setup` systemd service runs `lineledger setup`, which generates
`APP_KEY`, the database passwords and the passkey secret, points `APP_URL` at
the Droplet's IP and starts the stack. Email is set up later in the app
(Admin → Email).

The same `install.sh` also works as Droplet user data on a plain Ubuntu
Droplet; see README → Deploy on a DigitalOcean Droplet.

## Build a release image

From a checkout of the release tag (the image installs this checkout's
`docker/` files, and the release's published container image):

```bash
git checkout v1.2.3
cd docker/digitalocean/marketplace
packer init lineledger.pkr.hcl
DIGITALOCEAN_TOKEN=... packer build -var lineledger_version=1.2.3 lineledger.pkr.hcl
```

The build Droplet installs the pending Ubuntu updates and runs
`install.sh --image`. Then `cleanup.sh` removes DigitalOcean's agents, SSH
keys, logs, history and cloud-init state, and refuses to finish if an `.env`
(and so a set of secrets) exists. Last, the build runs DigitalOcean's image
validation script (`99-img-check.sh` from the
[marketplace-partners](https://github.com/digitalocean/marketplace-partners)
repository, pinned to a commit), and a failed check fails the build. Packer
then saves the snapshot to your account as `lineledger-<version>-<timestamp>`.
The zero-fill at the end of `cleanup.sh` prints "No space left on device";
that's how it finishes.

## Before submitting

1. Read the image check's summary near the end of the build output. Fix any
   `[WARN]` it reports unless it is intended. Don't re-run the check on a
   Droplet created from the snapshot: DigitalOcean adds your SSH key to that
   Droplet, which the check reports as a failure.
2. Create a Droplet from the snapshot and check the customer path: open
   `http://<ip>`, register (the account is signed in without email), save
   SMTP settings in Admin → Email and send the test email, then run
   `lineledger domain` with a real domain.
3. Submit the snapshot through the Marketplace vendor portal. The listing's
   getting-started text can reuse the README section and the login message in
   `docker/digitalocean/droplet/motd`.

Rebuild and resubmit for each release, so new Droplets start on it. Existing
Droplets upgrade themselves with `lineledger upgrade`.
