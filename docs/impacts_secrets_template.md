# impacts_secrets.php — template

This file lives ABOVE the webroot (one level up from `public_html`)
on the SiteGround server:

```
/home/customer/www/impacts-foundry.com/impacts_secrets.php
```

It is **never** in git, **never** in the working tree at deploy time,
**never** opened in a local IDE (that leaks contents via IDE selection
context to any AI assistant).

## Format

```php
<?php
// SiteGround MySQL — from SiteGround panel → MySQL → Manage
putenv('IMPACTS_DB_HOST=localhost');
putenv('IMPACTS_DB_NAME=...');
putenv('IMPACTS_DB_USER=...');
putenv('IMPACTS_DB_PASS=...');

// Migration runner gate (admin-paste secret)
putenv('IMPACTS_MIGRATE_TOKEN=...');

// Cron auth (preferred for HTTP-gated crons; CLI invocations bypass)
putenv('IMPACTS_CRON_SECRET=...');

// Server-side session signing + click-event IP anonymisation salt
putenv('IMPACTS_SESSION_SECRET=...');

// Phase B+ — outbound mail for sponsor / supporter notifications
// putenv('IMPACTS_SMTP_HOST=mail.impacts-foundry.com');
// putenv('IMPACTS_SMTP_PORT=465');
// putenv('IMPACTS_SMTP_USER=info@impacts-foundry.com');
// putenv('IMPACTS_SMTP_PASS=...');

// Phase B+ — mapping/geocoding provider (Q12 in brief §12 — TBD)
// putenv('IMPACTS_GEOCODE_PROVIDER=...');
// putenv('IMPACTS_GEOCODE_KEY=...');
```

Always use **single quotes** so PHP doesn't interpolate `$`.

## Token rotation

- `IMPACTS_MIGRATE_TOKEN` — admin-paste secret. Rotate independently
  by editing this file. Update the GitHub secret `IMPACTS_MIGRATE_URL`
  to match (full URL including the new token) so the auto-migration
  step still fires after deploys.
- `IMPACTS_CRON_SECRET` — dedicated cron auth. Rare to rotate; if you
  do, update any SiteGround Cron Jobs that pass `?token=`.
- `IMPACTS_SESSION_SECRET` — rotating this **invalidates every active
  admin session + every existing IP anonymisation hash** (so
  yesterday's clicks can no longer be re-derived from today's IP).
  Only rotate during a planned incident.

## DB candidate paths

`api/db.php` tries these paths in order — first one that exists wins:

1. `__DIR__ . '/../../impacts_secrets.php'` — expected (two levels up from `api/db.php`)
2. `__DIR__ . '/../impacts_secrets.php'` — one-level fallback
3. `/home/customer/www/impacts-foundry.com/impacts_secrets.php` — absolute SG layout

If none load, `impacts_db()` throws `database credentials missing —
impacts_secrets.php not loaded?` on the first connection attempt.
