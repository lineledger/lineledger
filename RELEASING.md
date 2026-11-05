# Releasing LineLedger

How a maintainer cuts a release. `UPGRADING.md` is what operators do about one;
`README.md` → Release channels is how they choose what they get.

## Branches and channels

| Branch | Holds | Tags cut from it | Channel |
| --- | --- | --- | --- |
| `main` | the stable line (1.4.x today) | `vX.Y.Z` | stable and next |
| `next` | the next minor release (1.5.0 today) | `vX.Y.0-rc.N` | next only |

- **Fixes for the stable line** go to `main` as usual. After each stable
  release, merge `main` into `next` so the next release carries the fix too.
- **Work for the next release** goes to `next` by pull request. CI runs on both
  branches. Don't merge it into `main` early.
- **When the release is ready,** a pull request merges `next` into `main` and
  the final `vX.Y.0` is cut there. `next` then carries on as the line after it.

## What a tag publishes

| Tag | GitHub release | Image tags |
| --- | --- | --- |
| `v1.4.4` while 1.5.0 is a candidate | Latest | `1.4.4`, `1.4`, `latest` |
| `v1.5.0-rc.1` | Pre-release, not Latest | `1.5.0-rc.1`, `next` |
| `v1.5.0` | Latest | `1.5.0`, `1.5`, `latest`, `next` |

`latest` and `next` go by version, not by push order:
`.github/scripts/image-channels.sh` gives `latest` to the newest final release
and `next` to the newest release of any kind, and rebuilding an old tag moves
neither. `{{major}}.{{minor}}` never moves for a release candidate. A tag build
takes about 75 minutes because arm64 is emulated; until it finishes, the new
image tags don't exist.

Droplets and the installer read GitHub, not the image tags: stable takes the
release marked **Latest**, next takes the highest version of any published
release. So the release flags below matter as much as the tag.

## Cutting a stable release (`vX.Y.Z`, from `main`)

1. Branch `release/X.Y.Z` from `main` and land the fixes on it (or merge their
   branches into it).
2. The release commit, `release: LineLedger X.Y.Z`:
   - the version in `VERSION`, the fallback in `config/version.php`, both MCP
     servers (`app/Mcp/Servers/*Server.php`), `resources/api/openapi.yaml`,
     `docs/api-v1.md`, the comment URL in `docker/digitalocean/droplet/install.sh`,
     the in-app docs (`resources/views/pages/docs/⚡api.blade.php`,
     `⚡self-hosting.blade.php`) and their keys and values in `lang/fr.json`;
   - `README.md`: every `vX.Y.Z` URL and pin, and the Status paragraph;
   - `CHANGELOG.md`: move `[Unreleased]` into `## [X.Y.Z] - YYYY-MM-DD` and add
     its compare link (`ChangelogTest` checks the order and the links);
   - `UPGRADING.md`: a `## <previous> → X.Y.Z` section, and the overview's tag
     and backfill lines.
3. Verify locally: the full suite on MySQL **and** on SQLite, one after the
   other (never at the same time), plus `vendor/bin/pint --test`,
   `vendor/bin/phpstan analyse --memory-limit=1G`, `npm test`,
   `composer audit --locked` and `npm audit --audit-level=high`.
4. Open a pull request from `release/X.Y.Z` into `main`. Once the required checks
   pass, merge it with a **merge commit** titled `release: LineLedger X.Y.Z (#N)`.
5. Tag the merge commit and push the tag:

   ```bash
   git fetch origin
   git tag -a vX.Y.Z -m "LineLedger X.Y.Z" origin/main
   git push origin vX.Y.Z
   ```

6. Publish the GitHub release with the upgrade preamble, a `---`, the CHANGELOG
   section, and the user manual PDF:

   ```bash
   gh release create vX.Y.Z --verify-tag --title "LineLedger X.Y.Z" \
       --notes-file notes.md --latest lineledger-getting-started.pdf
   ```

   Use `--latest` only when this is the highest final version. A patch to an
   older line (1.4.5 once 1.5.0 is out) gets `--latest=false`, or every stable
   Droplet would move back to it.
7. Merge `main` into `next` by pull request.

## Cutting a release candidate (`vX.Y.0-rc.N`, from `next`)

The same steps, with these differences:

- Branch `release/X.Y.0-rc.N` from `next`, and open the pull request into `next`.
- Bump what names this build's own version to `X.Y.0-rc.N`: `VERSION`, the
  `config/version.php` fallback, both MCP servers, `openapi.yaml`,
  `docs/api-v1.md` and the API docs page (with its `fr.json` key), the
  `install.sh` comment URL, and the README's install URLs, pins and Status
  paragraph. Examples that explain stable pinning (the image-tag lists in the
  README, UPGRADING's overview and the self-hosting docs page) keep the stable
  version: a `X.Y` tag doesn't exist until the final release.
- The CHANGELOG gets a `## [X.Y.0-rc.N] - YYYY-MM-DD` section; at the final
  release, fold the candidates' sections into `## [X.Y.0]`.
- `UPGRADING.md` gets a `## <stable> → X.Y.0-rc.N` section. It says how to come
  from the newest stable release and from the previous candidate, and whether
  going back to stable needs a restore.
- Tag `vX.Y.0-rc.N` on the merge commit in `next`, and publish it as a
  pre-release that is never Latest:

  ```bash
  gh release create vX.Y.0-rc.N --verify-tag --title "LineLedger X.Y.0-rc.N" \
      --notes-file notes.md --prerelease --latest=false lineledger-getting-started.pdf
  ```

## Graduating `next` (`vX.Y.0`)

1. Branch `release/X.Y.0` from `next`, write the release commit (fold the
   candidates' CHANGELOG sections into `[X.Y.0]`; the UPGRADING section comes
   from the newest stable release), and open the pull request into **`main`**.
2. Merge, tag `vX.Y.0` on `main`, and publish it with `--latest` as above.
3. Merge `main` back into `next`, then start the following line's version on
   `next`.
