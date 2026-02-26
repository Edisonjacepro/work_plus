# Incident Runbook (MVP)

## Scope

Operational checklist for the main failure modes of Work+ production.

References:

- Symfony Console: https://symfony.com/doc/current/console.html
- Symfony Lock: https://symfony.com/doc/current/components/lock.html
- Symfony Mailer: https://symfony.com/doc/current/mailer.html

## 1) CI / quality is red

Symptoms:

- GitHub check `CI / quality` fails on PR.

Actions:

1. Open failed job logs in GitHub Actions.
2. Reproduce locally with:
   - `composer validate --strict`
   - `composer run ci`
3. Fix root cause (lint, test, config).
4. Push fix to PR branch and re-check status.

## 2) Points integrity check fails

Symptoms:

- `app:points:integrity:check` exit code is `1`.
- Alert email/webhook triggered.

Actions:

1. Run detailed report:
   - `php bin/console app:points:integrity:check --json --sample-limit=50`
2. Identify issue class:
   - missing credit
   - orphan credit
   - mismatch points
   - duplicate credits
3. Open incident ticket with report payload and timestamp.
4. Decide remediation path:
   - data repair script
   - code bugfix and redeploy
5. Re-run integrity check and close incident only when clean.

## 3) GDPR retention fails

Symptoms:

- `app:gdpr:retention --force` returns non-zero.

Actions:

1. Run safe preview:
   - `php bin/console app:gdpr:retention --dry-run`
2. Check filesystem permissions for:
   - `var/uploads/applications`
   - `public/uploads/cv`
   - `var/exports/gdpr`
3. Verify DB connectivity and transaction rollback behavior.
4. Re-run force mode when root cause is fixed.
5. Run integrity check after purge:
   - `php bin/console app:points:integrity:check --json --sample-limit=20`

## 4) Global ops health check

Command:

```bash
php bin/console app:ops:health-check --json
```

Interpretation:

- Exit `0`: no critical failure.
- Exit `1`: at least one critical check failed.

Recommended cron:

```cron
*/15 * * * * cd /var/www/work_plus && php bin/console app:ops:health-check --json >> var/log/ops-health.log 2>&1
```
