#!/usr/bin/env bash
# Which moving image tags a release tag gets, printed as GitHub Actions outputs:
#
#   latest=true|false   the tag is a final release, and the newest final one
#   next=true|false     the tag is the newest release of any kind, release
#                       candidates included
#
# "Newest" means semver precedence over every v* tag, not push order: a 1.4.x
# patch tagged after 1.5.0-rc.1 moves latest but not next, and rebuilding an
# old tag moves neither.
#
#   .github/scripts/image-channels.sh v1.5.0-rc.1        compare with `git tag -l 'v*'`
#   printf 'v1.4.3\n' | .github/scripts/image-channels.sh v1.5.0-rc.1 --tags-from-stdin

set -euo pipefail

tag=${1:?usage: image-channels.sh vX.Y.Z[-pre] [--tags-from-stdin]}

# Semver precedence for release tags, in jq. Kept identical in
# .github/scripts/image-channels.sh, docker/digitalocean/droplet/install.sh and
# docker/digitalocean/droplet/lineledger; ReleaseChannelsTest checks that.
SEMVER_JQ='
def valid: test("^v?[0-9]+\\.[0-9]+\\.[0-9]+(-[0-9A-Za-z.-]+)?$");
def semver: ltrimstr("v") | capture("^(?<core>[0-9]+\\.[0-9]+\\.[0-9]+)(-(?<pre>.+))?$")
    | [(.core | split(".") | map(tonumber)), (.pre == null), ((.pre // "") | split(".") | map(tonumber? // .))];
'

if [ "${2:-}" = --tags-from-stdin ]; then
    tags=$(cat)
else
    tags=$(git tag -l 'v*')
fi

printf '%s\n' "$tags" | jq -R -s -r --arg tag "$tag" "$SEMVER_JQ"'
    if ($tag | valid) then . else error("\($tag) is not a release tag (vX.Y.Z or vX.Y.Z-pre)") end
    | [split("\n")[] | select(valid)] + [$tag]
    | map(semver) as $all
    | ($tag | semver) as $this
    | ($all | max) as $newest
    | ([$all[] | select(.[1])] | max) as $newest_final
    | "latest=\($this[1] and $this == $newest_final)", "next=\($this == $newest)"
'
