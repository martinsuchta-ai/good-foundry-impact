# CLAUDE.md — Good Foundry Impact Platform (Go Make Impact / GMI)

This file documents the data contract and architectural conventions
of the Impact Platform so future Claude Code sessions don't
accidentally regress patterns established during the Phase 0 build
(Jun 2026).

Architectural decision log: [docs/Affiliates/go_make_impact_brief.md]
in the WBM repo (cross-repo reference — Marty's call). The brief is
the source of truth for §3 tiers, §4 state machine, §5 lanes, §6
money guardrails, §6a location/map rules, §8 safeguarding.

---

## Stack

- Vanilla HTML / JS / PHP single-page admin (no build step, no framework)
- PHP 8.2 on SiteGround shared hosting
- MySQL 8 — dedicated database for Impact; never share with WBM or Affiliate
- GitHub Actions deploy via `lftp mirror --delete` (see `.github/workflows/deploy.yml`)
- Hosted at `www.impacts-foundry.com/`
- Mailbox: `info@impacts-foundry.com` (IMAP 993, SMTP 465, host `mail.impacts-foundry.com`)

## The two-platforms architecture (re-iterated)

GMI is **standalone**. Sister to WBM and Affiliate. Own DB, own admin,
own deploy. WBM is consumer #1 — embeds GMI widgets via the consumer
+ placement model. No hard dependency on WBM internals; no shared DB
with WBM or Affiliate.

| Platform | Domain | Repo | DB user |
|---|---|---|---|
| WBM | smart-tools-foundry.com/WBM/ | 00 WM Development | usm7x61zvflyw |
| Affiliate | www.affiliates-foundry.com | good-foundry-affiliates | uuuqoinmcysmx |
| **Impact (this repo)** | **www.impacts-foundry.com** | **good-foundry-impact** | (set during Phase 0 provisioning) |

## Where data lives

**Everything user-data is in MySQL.** A handful of file-shaped things
stay on disk:

| Lives in SQL | Lives on disk |
|---|---|
| Consumers (sites embedding Impact — WBM is consumer #1) | Static admin UI files (`app/`) |
| Placements (named slots on consumer sites) | Uploaded safeguarding evidence (read-restricted) |
| Impact projects (the core entity — §4 state machine) | |
| Contribution asks + pledges (§5 lanes) | |
| Safeguarding records (§8) | |
| Click / redirect events (privacy: SHA-256 hash only) | |
| Admin users + sessions | |

## Critical conventions

### 1. Always backtick column names

SiteGround's PHP/PDO combo silently 500s on the unquoted `role` column
(and others context-dependently). Wrap every column name in backticks
in every INSERT / UPDATE / SELECT / DELETE.

### 2. All timestamps are UTC

PHP's default tz is forced to UTC via `api/imp_bootstrap.php`. PDO
sessions force `time_zone = '+00:00'`. The frontend converts to the
consumer's tz at display time only.

The scheduler (§4 in the brief — auto-flips `planning → execution` at
`start_at` and `execution → done` at `end_at`) MUST treat every
project time as UTC. Never store a local-time string.

### 3. Migration runner pattern

- Schema files: `api/migrate/NNN_<name>.sql`, applied in lexical order
- Tracked in `schema_migrations` table
- Runner: `api/migrate/run.php?token=<IMP_MIGRATE_TOKEN>`
- **No transactions around DDL** — MySQL implicitly commits on every
  CREATE/ALTER, so wrapping a migration in a transaction throws "no
  active transaction" on commit. Use `IF NOT EXISTS` guards.

### 4. Every PHP endpoint that reads/writes data

```php
require_once __DIR__ . '/db.php';
$pdo = imp_db();
```

`db.php` handles connection caching, error paths, and the bootstrap
require chain. Don't `new PDO()` directly.

### 5. `db.php` reads credentials from `imp_secrets.php` ABOVE the webroot

The file lives at `/home/customer/www/impacts-foundry.com/imp_secrets.php`
— one level above `public_html`. NEVER in git, NEVER inside the
webroot, NEVER opened in a local IDE. (Note: SiteGround's filesystem
slug for the apex domain typically omits the `www.` prefix even
though the served URL keeps it — confirm at provisioning time.)

Format:
```php
<?php
putenv('IMP_DB_HOST=...');
putenv('IMP_DB_NAME=...');
putenv('IMP_DB_USER=...');
putenv('IMP_DB_PASS=...');
putenv('IMP_MIGRATE_TOKEN=...');
putenv('IMP_CRON_SECRET=...');
putenv('IMP_SESSION_SECRET=...');
// Phase B+ — outbound mail for sponsor / supporter notifications
putenv('IMP_SMTP_HOST=mail.impacts-foundry.com');
putenv('IMP_SMTP_PORT=465');
putenv('IMP_SMTP_USER=info@impacts-foundry.com');
putenv('IMP_SMTP_PASS=...');
// Phase B+ — mapping/geocoding provider (Q12 in §12 — TBD)
// putenv('IMP_GEOCODE_PROVIDER=...');
// putenv('IMP_GEOCODE_KEY=...');
```
Always **single quotes** to avoid `$` interpolation surprises.

Token responsibilities:
- `IMP_MIGRATE_TOKEN` — admin-paste secret for migration runner + probes
- `IMP_CRON_SECRET` — dedicated cron auth (scheduler + cleanup jobs)
- `IMP_SESSION_SECRET` — server-side session signing for admin auth +
                         the salt mixed into `imp_anonymise_ip()`

### 6. Cron auth: use `imp_cron_auth.php`

Every cron / scheduled endpoint authenticates via the shared helper:

```php
require_once __DIR__ . '/imp_cron_auth.php';
imp_cron_auth_check();
```

Behaviour:
- CLI invocation → returns immediately (trusted by filesystem perms)
- HTTP invocation → accepts `?token=<IMP_CRON_SECRET>` first, falls
  back to `?token=<IMP_MIGRATE_TOKEN>`, otherwise 403

**Prefer PHP-CLI cron** over `wget`/`curl` URL gating — keeps secrets
out of the SiteGround panel.

### 7. API key auth for consumer endpoints

Public consumer-facing endpoints (`/v1/consumers/me`, `/v1/projects`,
`/v1/map`) authenticate via `Authorization: Bearer <api_key>` OR
`?api_key=<key>`.

The api_key is the `imp_consumer.api_key` column — long random
string, rotatable, scoped per consumer. CORS preflight allows
origins from `imp_consumer.widget_origins` (JSON array).

### 8. Privacy — never store raw IPs or cookies

Per Q4 (Marty's locked answer): NO raw IPs stored, NO cookies. Click
+ redirect events stamp `anonymised_user_id = SHA-256(ip + session_secret + daily_salt)`
so attribution can be re-derived without retaining PII. Helper is
`imp_anonymise_ip($ip)` in `imp_bootstrap.php`.

User-Agent is stored truncated to 500 chars — useful for fraud
signals; not PII.

### 9. Location precision is enforced server-side (§6a HARD RULE)

The brief §6a is clear: exact coordinates for projects flagged
`involves_minors_or_vulnerable = true` MUST NEVER reach the public
map. Precision reduction (lat/long fuzzing or snapping) happens on
the server BEFORE the response leaves PHP — the client never sees
fine-grained coordinates it then has to round.

Helper goes in `api/imp_location.php` (Phase B). Reduction maps:
- `exact` → return lat/long as stored
- `suburb` → snap to ~3 decimal places (~110m)
- `region` → snap to 1 decimal place (~11km)
- `country` → return centroid only

For projects with `involves_minors_or_vulnerable = true`, the helper
hard-clamps precision to `suburb` regardless of the stored value
on public/consumer-facing endpoints. Admin-authenticated views can
see the stored value.

### 10. Money lane is route-only (§6 HARD RULE)

Phase 1 is route-only. NO processor integration, NO custody, NO
escrow, NO payouts. The only money artefact stored is:
- The ask's target (display only — number, not held)
- The sponsor-supplied `external_destination_url`
- A logged redirect event when a supporter clicks through

Any code that looks like it's about to hold funds, issue refunds, or
disburse → STOP and re-read brief §6.

## Deployment

- Push to `main` → GitHub Actions runs `lftp mirror --delete` against SiteGround
- After every schema change, visit `api/migrate/run.php?token=<IMP_MIGRATE_TOKEN>`
- The deploy doesn't run migrations automatically (set `IMP_MIGRATE_URL`
  as a repo secret to auto-trigger after each deploy if desired)

## What NOT to do

- Don't share the MySQL database with WBM or Affiliate — separate
  creds, separate DB, separate trust boundary
- Don't bypass `db.php` with raw PDO connections
- Don't store local-time strings — only UTC
- Don't commit anything to `imp_secrets.php` — must stay above webroot
- Don't store raw IPs or cookies on click/redirect events — SHA-256
  hash only
- Don't expose exact coordinates for minors/vulnerable-people projects
  on any public endpoint (§9)
- Don't scaffold a payment processor — money lane is route-only (§10)
- Don't reach into the WBM repo for shared code — copy patterns,
  don't import. Two-platforms architecture means firewall isolation.
- Don't disable hooks (`--no-verify`) when committing
- Don't run `git push --force` against `main`

## Phase plan (current)

| Phase | Status | Scope |
|---|---|---|
| 0 | **In progress** (this commit) | Foundation: repo + scaffolding + secrets + db.php + migration runner + admin login + first smoke-test API + initial schema (admin_user, admin_session, imp_consumer, imp_placement, impact_project base) |
| 1a | Pending Phase 0 done | Lifecycle state engine + background scheduler (auto transitions, restart reconciliation) |
| 1b | Pending Phase 1a | Contribution lanes — `contribution_ask` + `contribution_pledge`; effort/energy pledge→confirm→fulfil |
| 1c | Pending Phase 1b | Safeguarding — `safeguarding_record`, admin verification gate blocking planning/execution for flagged projects |
| 1d | Pending Phase 1c | Money lane — route-only ask + logged redirect endpoint to external destination |
| 1e | Pending Phase 1d | Moderation + reporting + tiered go-live gating by scale |
| 1f | Pending Phase 1e | Delivery layer — JSON API + redirect endpoint + embeddable card/CTA widget; wire WBM as consumer #1 |
| 1g | Pending Phase 1f | Map endpoint + map widget (§6a) — server-side geocoding + precision reduction |
| 1h | Pending Phase 1g | Rollover + impact reporting (per project, per lineage, per consumer) |

Cross-platform reference: Affiliate (sister platform) is in its own
hold-pattern pending Impact + ClickBank creds. The two run on
parallel sequences — see [project_affiliate_proposal_7day_hold] in
the WBM repo's memory.

## §12 open items (blocking before Phase 1b+)

The brief §12 lists items to confirm before/while building. They
DON'T block Phase 0 (this commit). They DO block specific later
phases:

| Open item | Blocks phase |
|---|---|
| Sponsor identity verification standard for macro/borderless | 1e (tier go-live gating) |
| Acceptable money rails (GoFundMe / ACNC / sponsor-owned Stripe) | 1d (money lane) |
| Registered origins/domains for initial consumers (CORS list) | 1f (delivery layer) — needed in Phase 0 too for the seeded WBM consumer's widget_origins JSON |
| Jurisdictions in scope at launch | 1c (safeguarding model) |
| In-kind tax notice on Effort/Energy | watch-item (not built) |
| Mapping/geocoding provider | 1g (map widget) |
