# DigitalOcean Marketplace image for LineLedger: Ubuntu 24.04, Docker, and the
# LineLedger Compose stack with its images pre-pulled. Each Droplet made from
# the snapshot generates its own secrets on first boot (lineledger setup).
#
#   cd docker/digitalocean/marketplace
#   packer init lineledger.pkr.hcl
#   DIGITALOCEAN_TOKEN=... packer build -var lineledger_version=1.2.3 lineledger.pkr.hcl
#
# It installs from this checkout's docker/ directory, so build from the
# release tag you are publishing.

packer {
  required_plugins {
    digitalocean = {
      version = ">= 1.4.0"
      source  = "github.com/digitalocean/digitalocean"
    }
  }
}

variable "do_token" {
  type      = string
  default   = env("DIGITALOCEAN_TOKEN")
  sensitive = true
}

# The release being published, e.g. 1.2.3. No default, so a build always
# says which release it packages.
variable "lineledger_version" {
  type = string
}

variable "region" {
  type    = string
  default = "tor1"
}

locals {
  docker_dir = "${path.root}/../.."
  stage      = "/tmp/lineledger-src"
}

source "digitalocean" "lineledger" {
  api_token     = var.do_token
  image         = "ubuntu-24-04-x64"
  region        = var.region
  size          = "s-1vcpu-2gb"
  ssh_username  = "root"
  snapshot_name = "lineledger-${var.lineledger_version}-${formatdate("YYYYMMDDhhmm", timestamp())}"
}

build {
  sources = ["source.digitalocean.lineledger"]

  provisioner "shell" {
    inline = ["cloud-init status --wait", "mkdir -p ${local.stage}/digitalocean/droplet"]
  }

  # Bring the base image up to date: DigitalOcean's image check fails a
  # snapshot with security updates pending.
  provisioner "shell" {
    environment_vars = ["DEBIAN_FRONTEND=noninteractive"]
    inline = [
      "apt-get -qq -o DPkg::Lock::Timeout=300 update",
      "apt-get -qqy -o DPkg::Lock::Timeout=300 -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold full-upgrade",
    ]
  }

  # Only the files the install needs: never the whole directory, which may
  # hold a developer's .env.
  provisioner "file" {
    sources = [
      "${local.docker_dir}/docker-compose.yml",
      "${local.docker_dir}/.env.example",
      "${local.docker_dir}/backup.sh",
      "${local.docker_dir}/restore.sh",
    ]
    destination = "${local.stage}/"
  }

  provisioner "file" {
    sources = [
      "${local.docker_dir}/digitalocean/droplet/install.sh",
      "${local.docker_dir}/digitalocean/droplet/lineledger",
      "${local.docker_dir}/digitalocean/droplet/motd",
    ]
    destination = "${local.stage}/digitalocean/droplet/"
  }

  provisioner "shell" {
    environment_vars = [
      "LINELEDGER_VERSION=${var.lineledger_version}",
      "LINELEDGER_SRC=${local.stage}",
    ]
    inline = ["bash ${local.stage}/digitalocean/droplet/install.sh --image"]
  }

  provisioner "shell" {
    script = "${path.root}/cleanup.sh"
  }

  # DigitalOcean's own Marketplace image check (pinned), on the cleaned build
  # Droplet as its Marketplace templates run it. A failed check fails the build.
  provisioner "shell" {
    inline = [
      "curl -fsSL https://raw.githubusercontent.com/digitalocean/marketplace-partners/735b351eb3aa244b610287ef17d9e70b002f83a1/scripts/99-img-check.sh -o /root/99-img-check.sh",
      "status=0; bash /root/99-img-check.sh || status=$?",
      "rm -f /root/99-img-check.sh",
      "exit $status",
    ]
  }
}
