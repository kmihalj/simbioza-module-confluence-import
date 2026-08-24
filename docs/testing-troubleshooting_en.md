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

### I uploaded an archive but do not want to import it

Open the saved mapping or find the job under **Recent Confluence imports** and
select **Cancel import**. Confirmation immediately deletes the archive and its
preparation data. This action is available only before content import starts.

### Import button is unavailable

The preflight must finish successfully first. A personal space also needs a confirmed owner mapping.

### A restricted page is missing for a user

Check its source users/groups and confirm the mapping. This is a safe failure: unresolved restrictions deny access instead of opening the page.

### An existing group was not suggested automatically

The suggestion requires an exact technical key or exact name of an enabled
group, ignoring case and redundant whitespace. A similar name, disabled group,
or ambiguous result deliberately remains unmapped. Check the key in Auth
settings and select a target manually only when it represents the same group.

### An imported inactive user cannot sign in

This is expected. The staged account has no password or provider. In Auth
settings, an administrator must enable a real provider and activate it. A
local-only account needs a temporary password; with external and local access
combined, the user may set a local password privately after external sign-in.

### A cross-space link still opens the resolver

Import the referenced space. The resolver keeps the source page ID and retries reconciliation after each successful import. If the source points to a deleted/non-exported page, the original URL remains available.

### A file was not imported

Review the job summary for a missing physical ZIP entry, invalid identifier or ZIP safety limit. Correct the limit only after verifying the archive, then upload it as a new job. Never expose the staging directory through the web server.

### Confirmed import failed halfway

Do not retry the same job because page creation is not idempotent. Inspect and remove the partial Workspace when appropriate, fix the reported cause and upload the source again. Safely stored binary attachment versions are reused and are not duplicated on disk. The source archive remains on the server after failure for diagnosis and must be protected by filesystem permissions.

Large-archive preflight and confirmed imports use `confluence_import.import_execution_time_limit` (900 seconds by default). Import refreshes backlinks and the search index only once per Workspace. If PHP still terminates with a fatal error, the job is marked as failed and a safe message appears in the job list; technical details remain in the technical log.
