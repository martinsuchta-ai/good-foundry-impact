# Good Foundry Impact Platform

**Working name:** Go Make Impact (GMI)
**Tagline:** *"It's not about the money, honey — it's about making an impact."*

A standalone platform that lets people (sponsors) create and run
time-boxed Impact Projects, and lets others (supporters) commit
Effort, Energy, or Money to them. WBM is consumer #1; the platform
is multi-tenant from day one.

GMI is **not** a feature inside WBM. It is its own application with
its own datastore, admin, and delivery layer. Sister to the Affiliate
Product Service and Wellbeing Matters.

## Production URL

- App: https://www.impacts-foundry.com/
- Admin: https://www.impacts-foundry.com/app/admin/login.html
- Smoke (Phase 0): `https://www.impacts-foundry.com/api/v1/consumers_me.php?api_key=<key>`
- Mail: `info@impacts-foundry.com` (IMAP 993 / SMTP 465 / host `mail.impacts-foundry.com`)

## Brief / source of truth

The architectural brief lives in the WBM repo:

- `docs/Affiliates/go_make_impact_brief.md` — Phase 1 scope, state
  machine, contribution lanes, safeguarding, money guardrails, map rules

The cross-platform v3 proposal (two-platforms decision) lives at:

- `docs/Affiliates/good_foundry_v3_two_platforms_2026-06-16.md`

## Local development

This repo has no build step. PHP runs the way SiteGround hosts it.
For local work, point PHP's built-in server at the root:

```bash
cd /path/to/good-foundry-impact
php -S localhost:8080 -t .
```

`api/db.php` won't connect locally until you create a sibling
`imp_secrets.php` ONE level above this directory (so PHP can
`require_once __DIR__ . '/../../imp_secrets.php'` from `api/db.php`).
The format is in `docs/imp_secrets_template.md`.

## Deploy

Push to `main`. GitHub Actions runs `lftp mirror --delete` against
SiteGround. Required repo secrets:

| Secret | What |
|---|---|
| `SG_HOST` | SiteGround FTP host (e.g. `ftp.impacts-foundry.com`) |
| `SG_USER` | FTP user |
| `SG_PASS` | FTP password |
| `IMP_MIGRATE_URL` (optional) | Full URL `https://www.impacts-foundry.com/api/migrate/run.php?token=<IMP_MIGRATE_TOKEN>` — if set, the deploy fires it after mirror to apply any new migrations |

The mirror EXCLUDES: `.git/`, `.github/`, `.gitignore`, `CLAUDE.md`,
`README.md`, `docs/`, `imp_secrets.php`, `*.log`.

## Conventions

See [CLAUDE.md](CLAUDE.md). The short version:

- All timestamps UTC (PHP + MySQL session forced to `+00:00`)
- Backtick every column name in every SQL statement
- Always go through `imp_db()` — never new PDO directly
- Money lane is **route-only** in Phase 1 (no processor, no custody)
- Exact coordinates for projects involving minors NEVER leave the
  server — precision reduction is server-side
- No raw IPs, no cookies — SHA-256 anonymisation only
