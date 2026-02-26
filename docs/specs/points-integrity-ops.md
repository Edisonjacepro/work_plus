# Points Integrity Operations

## Purpose

Run `app:points:integrity:check` automatically and alert operations when anomalies are detected.

## Command

```bash
php bin/console app:points:integrity:check --json --sample-limit=20
```

- Exit code `0`: no anomaly.
- Exit code `1`: anomalies found or runtime crash.

## Unified scheduled command

```bash
php bin/console app:ops:scheduled-check
```

This command runs:

- `app:ops:health-check --json`
- `app:points:integrity:check --json --sample-limit=20`

Exit code:

- `0`: both commands succeeded.
- `1`: at least one command failed.

## Built-in safeguards

- Lock key: `points_integrity_check_command`
- Lock TTL: `900` seconds (configurable with `app.points_integrity.lock_ttl_seconds`)
- Concurrent execution: skipped (returns success, no duplicate run)
- Scheduler lock key: `ops_scheduled_check_command` (TTL `app.ops_health.lock_ttl_seconds`)

## Alerting configuration

Environment variables:

- `APP_POINTS_INTEGRITY_ALERT_FROM` (optional)
- `APP_POINTS_INTEGRITY_ALERT_TO` (optional)
- `APP_POINTS_INTEGRITY_ALERT_WEBHOOK_URL` (optional)
- `APP_OPS_ALERT_FROM` (optional)
- `APP_OPS_ALERT_TO` (optional)
- `APP_OPS_ALERT_WEBHOOK_URL` (optional)

Behavior:

- On anomaly: critical log + email (if `*_TO` set) + webhook POST (if webhook URL set)
- On crash: critical log + email/webhook with exception metadata

No PII is included in alert logs; only technical IDs and aggregate counts.

`app:ops:health-check` now follows the same alerting behavior on failure/crash.

## Scheduling examples

### Cron (every 15 minutes)

```cron
*/15 * * * * cd /var/www/work_plus && php bin/console app:ops:scheduled-check >> var/log/ops-scheduled-check.log 2>&1
```

### systemd timer

Use a dedicated service calling `app:ops:scheduled-check` and a timer with `OnCalendar=*:0/15`.

### GitHub Actions schedule

Workflow: `.github/workflows/ops-scheduled-check.yml`

Required secret:

- `OPS_DATABASE_URL`

Optional alerting secrets:

- `APP_POINTS_INTEGRITY_ALERT_FROM`
- `APP_POINTS_INTEGRITY_ALERT_TO`
- `APP_POINTS_INTEGRITY_ALERT_WEBHOOK_URL`
- `APP_OPS_ALERT_FROM`
- `APP_OPS_ALERT_TO`
- `APP_OPS_ALERT_WEBHOOK_URL`

## Operational recommendation

- Route command failures to your monitoring stack (Sentry/Datadog/Prometheus alertmanager).
- Keep `LOCK_DSN` on shared storage (e.g. PostgreSQL advisory lock) in multi-instance production.
