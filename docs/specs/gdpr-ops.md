# GDPR Operations

## Scope

MVP GDPR operations for DSAR export and anonymization.

Commands:

- `php bin/console app:gdpr:export --user-id=<id>`
- `php bin/console app:gdpr:export --company-id=<id>`
- `php bin/console app:gdpr:anonymize --user-id=<id> --dry-run`
- `php bin/console app:gdpr:anonymize --user-id=<id> --force`
- `php bin/console app:gdpr:anonymize --company-id=<id> --dry-run`
- `php bin/console app:gdpr:anonymize --company-id=<id> --force`

## Export behavior

- DSAR exports are written as JSON files in `var/exports/gdpr` by default.
- Use `--output-dir` to override target path.
- Output includes data sections linked to the requested subject (user/company).

## Anonymization behavior

- `--dry-run` computes impacted rows without writing changes.
- `--force` executes SQL updates inside a DB transaction.
- Logs only technical IDs and row counts (no PII in log messages).

## Safety notes

- Always run dry-run first and archive result in ticket/audit notes.
- Run anonymization during low-traffic periods.
- Validate integrity checks after execution:

```bash
php bin/console app:points:integrity:check --json --sample-limit=20
```
