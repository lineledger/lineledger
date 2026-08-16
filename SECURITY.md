# Security Policy

LineLedger handles financial and accounting data, so we take security reports
seriously and appreciate responsible disclosure.

## Reporting a vulnerability

**Please do not report security vulnerabilities through public GitHub issues,
pull requests, or discussions.** Public disclosure before a fix is available puts
users at risk.

Instead, report privately by either:

- Using GitHub's **[Private vulnerability reporting](../../security/advisories/new)**
  (the "Report a vulnerability" button under the repository's *Security* tab), or
- Emailing hello@lineledger.ca.

Please include as much as you can:

- A description of the vulnerability and its potential impact.
- Steps to reproduce, or a proof of concept.
- Affected version, component, or endpoint.
- Any suggested remediation, if you have one.

## What to expect

- We will acknowledge your report within **3 business days**.
- We will investigate, keep you updated on our assessment, and let you know when
  a fix is released.
- We aim to triage valid reports promptly and to resolve serious issues as quickly
  as we reasonably can, coordinating a disclosure timeline with you.
- We're grateful to security researchers who report responsibly and are happy to
  credit you in the release notes for the fix, unless you'd prefer to remain
  anonymous.

## Safe harbor

**Local Foundry Inc.**, which operates the hosted service, considers security
research and vulnerability disclosure conducted in good faith and in accordance with
this policy to be authorized. We will not pursue or support legal action against you
for such research, provided that you:

- Make a good-faith effort to avoid privacy violations, data destruction, and
  interruption or degradation of the service while testing.
- Only interact with accounts you own or have explicit permission to access, and
  do not access, modify, or exfiltrate other users' data.
- Give us a reasonable opportunity to resolve the issue before any public
  disclosure.
- Do not exploit the issue beyond the minimum necessary to demonstrate it.

If you are unsure whether a specific test is acceptable, ask us first at
hello@lineledger.ca.

## Scope

**In scope:**

- The LineLedger source code in this repository.
- The official hosted service operated by Local Foundry Inc. LineLedger runs two
  regional deployments and **both are in scope**:
  - `books.lineledger.ca` (Canada)
  - `books.lineledger.com` (United States)

**Supported versions:** security fixes are issued for the **latest release** only.
The current release is listed in [CHANGELOG.md](CHANGELOG.md); if you're self-hosting
an older version, upgrade before reporting a bug that may already be fixed.

**Out of scope:**

- Third-party dependencies — please report those to their respective maintainers.
- Third-party services we rely on (for example our payment processor and
  infrastructure providers) — report those to the relevant vendor.
- Any LineLedger instance hosted by someone other than Local Foundry Inc.;
  self-hosted deployments are the responsibility of whoever operates them.
- Findings that require physical access, social engineering of our staff, or
  denial-of-service testing.
