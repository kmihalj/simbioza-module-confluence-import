# Site import reports and final checks

[Croatian version](site-reports_hr.md)

## Combined overview

Open the prepared site job in **Settings → Confluence import**, then choose
**Open combined report**. The same overview appears below the import steps.
It works before content import finishes, after removing the working source, and
after restoring a complete backup. No archive scan or content write runs on GET.

Combined, identity and calendar reports place their source/status, explanations
and results inside the active theme's content card. They are not loose text over
the hero/header background; theme surfaces and text colors remain in control.

The overview lists the linked identity/membership runs, individual spaces and
calendar runs. An unfinished or failed linked run is not described as completed.
Stopped calendar runs are marked partial. Each completed row links to its detailed
report; source names, warnings and imported code are never inserted as raw HTML.
Counts describe recorded operations, not distinct objects across repeated imports.
Identity/group/membership reports, calendar reports and each space's existing
detailed report remain separate. The combined overview only links and summarizes
them. Resolved/unresolved link counters are read from current persisted link rows,
not copied historical counters. A later space that resolves links in an older space
therefore updates the older report as well, without changing its UUID or original
import counts. Calendar linking also updates the original owning page report.

Detailed space reports retain the existing attachment breakdown, unresolved links,
calendar macros and unknown-script approval workflow. Unknown scripts do not run
because a report was opened. Existing approved scripts still follow the registry's
per-page activation rules. The overview counts unresolved script/review items;
approvals and corrections stay in the owning detailed report and module GUI.
Calendar reports distinguish new, already present and skipped event records.
Unsupported recurrence is reported, not silently changed into one-off events.

Report links are owner/source-checked and fetched in batches of at most 20. The
combined report does not load all page issue lists from every space into one array.
Only administrators can read reports. Import mutation, retry and cleanup remain
restricted to the administrator who owns the job and use the normal CSRF checks.

## Source retention and explicit cleanup

The combined import shows a prominent **Current step progress** bar with a separate
percentage and the active phase. Calendar progress counts committed batches;
workspace progress follows saved child-job checkpoints. The counter below the bar
shows completed/selected calendars or workspaces. Percentages apply to the current
step, not elapsed time or the entire import, and restart when the step changes.
Preparation and final linking show an animated bar without an invented percentage.
A page refresh reads the saved progress; choose **Resume the entire import** to
continue. An error stops the animation and marks the bar red; only completion of
the entire workflow is reported as success.

Closing a browser tab is not cancellation: reopen the same job and choose
**Resume the entire import**. The coordinator resumes durable checkpoints in order:
identities/memberships, calendars, spaces, then reference linking. A failure stops
the run and preserves the source and completed/partial reports, not a false success.

After the entire workflow, including reference linking, completes, the owner may
choose **Remove working archive and indexes** and confirm the warning. This removes only the managed
uploaded ZIP, recognized private indexes and completed child staging. The original
archive outside the application is never deleted. Users, groups, calendars,
documents, attachments, durable mappings and reports remain.

This is not rollback. The same working copy cannot be imported again after
cleanup; upload the retained original for later migration. Automatic expiry applies
only to pre-import temporary jobs, never active/partially committed calendar jobs.
Unrecognized index files or symlinks stop cleanup instead of deleting arbitrary
paths. Busy workers prevent cleanup. A deleted or inaccessible linked report is
shown as unavailable, not counted as a successful step.

## Backup and another site

Use a complete site backup, or include the required owning components for a
component backup. Identity and calendar runs have independent completed report
jobs, so their durable mappings do not depend on an unfinished parent being
exported. A completed parent's summary retains report/child UUIDs across restore.
Native Calendar and Workspace/Editor providers own their actual content and IDs.
The importer provider remaps foreign IDs and strips uploaded ZIP paths, review
tokens and local execution choices. Private indexes are not portable configuration.

Restoring a backup restores reports, not an executable source job. Missing source
archives cannot be resumed on the destination. Apply the ordinary backup preflight
and source/destination URL rules; a different destination uses cross-site link
relocation. Inspect unresolved links and scripts after restore. Passwords/tokens,
SSO configuration and global administrator conversion are not Confluence imports.
Plugin binaries, audit/analytics history and arbitrary AO tables are not imported;
task, sidebar, template and macro content use only the existing supported converters.

## Reproducible development checks

Run module analyses/tests from the importer repository:

```sh
composer on-commit
```

Create an isolated all-module application from the Simbioza source checkout:

```sh
php scripts/run_e2e.php --local --keep --spec=confluence-import.spec.js
```

Use the printed disposable `e2e-all-*` directory, **never HFC**, with the fixture:

```sh
php tests/Fixtures/site_acceptance.php /absolute/temporary/heartphrame-clean-matrix/e2e-all-EXAMPLE filesystem
php tests/Fixtures/site_acceptance.php /absolute/temporary/heartphrame-clean-matrix/e2e-all-EXAMPLE database
php tests/Fixtures/site_workflow_acceptance.php /absolute/temporary/heartphrame-clean-matrix/e2e-all-EXAMPLE filesystem
php tests/Fixtures/site_workflow_acceptance.php /absolute/temporary/heartphrame-clean-matrix/e2e-all-EXAMPLE database
```

The fixtures reject ordinary installations. They create fictional identity/content/calendar data,
asserts the actual HTML version storage driver, verifies recurrence/mappings,
combined/portable reports and explicit source cleanup. Network database checks use
the existing runner's `--database=pgsql|mysql|mariadb` and `HPH_MATRIX_DB_*`
environment variables against a **new empty disposable database**.
The unified fixture also verifies preview purity, stale confirmation rejection,
concurrent request deferral, a future personal-calendar owner, repeated requests,
source authorship before content, links in both directions and a later calendar
automatically linked on an older page, with its existing report updated.

`site_backup_acceptance.php SOURCE TARGET` verifies cross-site restoration between
two different disposable clean-matrix installations. The source must first pass
`site_acceptance.php`; the destination may come from the clean-install matrix.
The separate `site_http_probe.php`, preparation helper and FPM/nginx/Apache fixtures
are restricted to `/srv/simbioza-c5-acceptance/` in a disposable Linux environment.
Never copy those unauthenticated synthetic probes into a public installation.

The implementation plan records the actual completed test matrix and any open
acceptance gates. Local-source development acceptance is not an assertion that
new package tags or Git CI have been published. No release is made by these checks.
