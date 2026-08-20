# Testing and troubleshooting

Croatian version: [testing-troubleshooting_hr.md](testing-troubleshooting_hr.md)

## Local quality suite

Install current development dependencies, then run:

```bash
composer validate --strict
composer on-commit
```

The suite checks PSR-12, Rector dry-run, PHPStan level 7 and PHPUnit. Unit tests cover safe ZIP handling, real Confluence entity scanning, storage-format conversion and reversible SQLite schema creation.

Before releasing the host application, also run its full MySQL, PostgreSQL and SQLite E2E matrix with this module enabled.

## Read-only diagnosis

```bash
vendor/bin/hph simbioza-confluence-import:inspect /srv/import/source.xml.zip
```

The JSON output is useful for checking space identity, object counts, statuses, groups and warning categories without modifying application data.

## Common failures

### Upload does not continue

Use the same browser session and select the same local file. If the source name or size differs, a new upload is required. An upload older than `upload_ttl` is not reusable and is removed automatically when a new upload starts.

### Import button is unavailable

The preflight must finish successfully first. A personal space also needs a confirmed owner mapping.

### A restricted page is missing for a user

Check its source users/groups and confirm the mapping. This is a safe failure: unresolved restrictions deny access instead of opening the page.

### A cross-space link still opens the resolver

Import the referenced space. The resolver keeps the source page ID and retries reconciliation after each successful import. If the source points to a deleted/non-exported page, the original URL remains available.

### A file was not imported

Review the job summary for a missing physical ZIP entry, invalid identifier or ZIP safety limit. Correct the limit only after verifying the archive, then upload it as a new job. Never expose the staging directory through the web server.

### Confirmed import failed halfway

Do not retry the same job because creation is not idempotent. Inspect and remove the partial Workspace when appropriate, fix the reported cause and upload the source again. The source archive remains on the server after failure for diagnosis and must be protected by filesystem permissions.
