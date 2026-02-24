# Points Integrity Operations

## Purpose

Run `app:points:integrity:check` automatically and alert operations when anomalies are detected.

## Command

```bash
php bin/console app:points:integrity:check --json --sample-limit=20
```

- Exit code `0`: no anomaly.
- Exit code `1`: anomalies found or runtime crash.

## Built-in safeguards

- Lock key: `points_integrity_check_command`
- Lock TTL: `900` seconds (configurable with `app.points_integrity.lock_ttl_seconds`)
- Concurrent execution: skipped (returns success, no duplicate run)

## Alerting configuration

Environment variables:

- `APP_POINTS_INTEGRITY_ALERT_FROM` (optional)
- `APP_POINTS_INTEGRITY_ALERT_TO` (optional)
- `APP_POINTS_INTEGRITY_ALERT_WEBHOOK_URL` (optional)

Behavior:

- On anomaly: critical log + email (if `*_TO` set) + webhook POST (if webhook URL set)
- On crash: critical log + email/webhook with exception metadata

No PII is included in alert logs; only technical IDs and aggregate counts.

## Scheduling examples

### Cron (every 15 minutes)

```cron
*/15 * * * * cd /var/www/work_plus && php bin/console app:points:integrity:check --json --sample-limit=20 >> var/log/points-integrity.log 2>&1
```

### systemd timer

Use a dedicated service calling the same command and a timer with `OnCalendar=*:0/15`.

## Operational recommendation

- Route command failures to your monitoring stack (Sentry/Datadog/Prometheus alertmanager).
- Keep `LOCK_DSN` on shared storage (e.g. PostgreSQL advisory lock) in multi-instance production.
