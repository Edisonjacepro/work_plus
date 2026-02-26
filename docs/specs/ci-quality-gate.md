# CI Quality Gate

## Objective

Block regressions before merge with a mandatory quality gate on each push and pull request.

## Workflow

File: `.github/workflows/ci.yml`

Pipeline steps:

1. `composer validate --strict`
2. `composer install --prefer-dist --no-interaction --no-progress`
3. `composer run ci:lint`
4. `composer run ci:test`

Additional security workflow:

- `.github/workflows/secret-scan.yml` (Gitleaks on push/PR + daily schedule)

## Local commands

```bash
composer run ci:lint
composer run ci:test
composer run ci
```

Local env bootstrap (strict public mode):

```bash
cp .env.dist .env
```

Then keep real secrets in `.env.local` (untracked) or system env vars.

## Notes

- The CI runs on PHP 8.4.
- Concurrency is enabled to cancel older runs on the same branch.
- Configure branch protection in GitHub to require:
  - `CI / quality`
  - `Secret Scan / gitleaks`
