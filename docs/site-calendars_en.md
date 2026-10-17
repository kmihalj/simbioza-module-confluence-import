# Importing calendars from a Confluence site

[Croatian version](site-calendars_hr.md)

## Workflow

1. Open the prepared **site** import in Settings → Confluence import. The existing
   single-space import and its manual ICS/calendar resolution remain unchanged.
2. Prepare users/groups and configure mappings. A saved preview is not an import.
   Identities execute first as part of the single confirmed site workflow.
3. Under **Import site calendars**, click **Prepare calendar preview**. This reads
   the Active Objects plugin entry once and stores a private, complete catalog.
   It does not create calendars, accounts, groups, subscriptions or events.
   The progress panel shows archive verification, reading, event partitioning and
   finalization. Reading shows processed records and elapsed time, not an invented
   overall percentage. Partitioning shows its measured count and percentage. The
   preview appears automatically when ready. Reloading reconnects to a running
   preparation; repeated clicks do not launch another scan. A stopped/failed worker
   can be retried instead of displaying an endless busy message. Temporary connection
   failures retry status checks, never the preparation itself.
4. Use the source base URL entered in the identity section. Filter/select calendars
   independently of the selected spaces. Choose **Personal calendar** or **Team
   calendar** for each selected row. A personal calendar also requires an explicit
   owner. Search the owner picker by name, login or email; it loads at most 25 results
   per request, with **Load more**, using database-side search for existing users.
   Existing inactive accounts are included, as are new inactive accounts already
   selected in the identity settings. Existing local users can be chosen without a
   source identity mapping. This assigns calendar ownership only: it never creates
   a source identity mapping or changes sign-in/admin settings. The creator is
   not necessarily the personal owner and is never preselected as one. Names such
   as “official”, “private” or “personal”, and personal-space associations suggest
   **Personal** only; ambiguous rows require an explicit type choice, not a default
   **Team** classification. No calendar
   is selected automatically. “Select visible” applies only to the current filtered
   page; **Select all filtered calendars** includes matches on all pages.
   Independent 25/50/100-row pagination above/below the table uses a bounded number
   window with ellipses. Search/owner filters apply before paging. Selection counts
   include off-page rows, and unsaved types/owners survive navigation. Saving sends
   the entire selected calendar draft, not just the current page.
5. **Save calendar settings** stores choices only. Choose **Review the entire
   import**, expand calendar details and inspect supported/skipped counts,
   owners/access and warnings before **Confirm and start the entire import**.
6. The workflow creates/maps identities, then calendars, then spaces. Each calendar request commits at most 250 events through
   Calendar's bulk ICS API. Keep the page open. If interrupted, reopen the same
   job and use **Resume the entire import**; committed batches are not repeated.
7. Calendar linking runs automatically after content. It considers calendar
   placeholders in completed owned reports of the same verified source, including
   earlier imports. A later calendar-only import can therefore link older pages. The
   existing page resolver publishes a new version; drafts or changed placeholders
   are left for manual review. This does not change arbitrary pages on the site.

The Calendar module is optional. Without it, calendar import is unavailable but
ordinary content import continues to work. Use the development Calendar code
with this C4 implementation: the bulk API now preserves `TZID` and accepts an
optional `event_type_name`. No release has been published for C4 yet.

## Pages with several calendars in one macro

A source `calendar` macro may select several comma-separated calendar IDs. The
converter retains their source order, removes duplicate references and creates a
separate report issue/marker for each source ID. It does not treat the entire list
as one calendar ID or replace an empty selection with the viewer's subscriptions.
IDs are not assumed to match a specific site's UUID pattern; each reference is
bounded to 190 safe identifier characters, at most 1,000 unique selections.
Malformed or excessive selections remain visible for manual review.

Resolved references from the same source macro accumulate in **one native Calendar
block**, preserving the supported `month`, `week` or `day` view. Missing/deleted
references remain separate visible placeholders and unresolved report items; they
do not prevent neighboring calendars from linking. A later import of the same
source can resolve them without duplicating the existing block. Distinct macros
remain distinct. Unsupported presentation options, such as hiding weekends, are
not claimed to be reproduced; native Calendar behavior applies.

Linking still checks publication, read permissions and the original marker.
An unpublished draft or edited/missing marker requires manual review, not an
overwrite. Labels are escaped, optional display hints; very large label sets may
be omitted while the complete UUID selection remains. Runtime display and access
checks use Calendar's current data and the viewer's rights. No subscriptions are
created by this conversion or by resolving a macro.

## What is migrated

### Reviewing proposed personal owners

The separate [mapping proposal review](site-mapping-review_en.md) shows incomplete
calendar choices with saved/proposed types, type reasons and candidate evidence.
It is read-only and distinguishes shared room/vehicle/team resources from personal
calendars without treating an embedded personal page as proof of ownership.

The calendar table shows **Suggested owner and reason**, with filters for personal
calendar candidates, uniquely mapped proposals, planned accounts, unmapped users,
ambiguous matches, weak personal-space hints and missing proposals. Search covers
calendar names, proposed candidates and the selected owner, not every hidden option
in every owner picker. The visible-row counter remains available in read-only mode.

**Type or owner confirmation required** shows rows without a chosen type or with
a personal type but no selected owner. It updates immediately as choices change;
it does not assign missing owners. Reconcile duplicate source accounts in the
identity settings first: explicit relations to the same new/existing user remove
false duplicate-owner proposals without relying on the calendar creator. Genuine
ambiguities and weak hints still require a human decision before import.

Proposals compare full names, usernames and emails appearing as complete parts of
the calendar name with the source directory. Case, separators and Croatian accents
are normalized only for suggestions, never for account identity mapping. Names can
be duplicated: different target users remain ambiguous. Two selected source identities
for the same target do not create a false conflict. The source calendar creator is
never evidence of personal ownership. A single linked personal space is only a weak
hint; a page can embed someone else's calendar.

**Accept suggested owner** fills the owner/type fields only. It does not select the
calendar checkbox, create an account, grant access, or run an import. The bulk button
applies only unique mapped/planned proposals in visible rows and never replaces an
already chosen owner. Unmapped source proposals must first be mapped/explicitly planned
in the identity section to use the proposal button. Alternatively, explicitly choose
an existing local owner in the searchable picker. Weak/ambiguous proposals require manual review and selection.
The final combined preview and confirmation remain mandatory.

Completed imports expose this same table read-only while their private source index
is retained; the previous owner selection and current proposal are separate columns.
Historical choices/results are not overwritten by a new suggestion. Prepare a new
GUI import to use newly confirmed mappings. Removing the working archive/index also
removes the ability to recompute suggestions, not the durable detailed reports.

Calendar preserves event titles up to 765 Unicode characters and locations up to
510 characters. Longer source values are listed as skipped events with a reason
in the preview/report, never silently shortened or sent to a failing SQL insert.
Use the accompanying Calendar schema migration when upgrading an existing site.

Supported `AO_950DC3_TC_*` tables describe native calendars, event-type children,
events, recurrence exclusions, access restrictions and source-space references.
Internal subscriptions are aliases of the original calendar, not duplicate
calendars. External/Jira sources are not fetched. Credentials, plugin settings,
reminders, remote subscription URLs and unrelated Active Objects tables are not
retained in the catalog. User/group mappings come exclusively from confirmed C2
relations; C4 does not create or activate accounts, modify Auth or change SSO.

A native source calendar becomes the **personal or team calendar explicitly chosen
in the preview**; its name or creator alone does not determine its type. Personal
requires an explicitly selected existing local account or a source user mapped to
an existing/planned new inactive account. Execution confirms the real ID before creating the
calendar. The server rechecks this even for manually crafted requests: it never
substitutes the importing administrator for a missing owner. The row shows the
selected owner's label; review and durable reports record the chosen type. Changing
the choice invalidates the current review and requires a new preview. Personal
calendars use Calendar's standard owner-prefixed custom name, with a stable short
source-ID suffix to avoid collisions between equally named imported calendars.

The mapped creator receives read/write access. Explicit mapped VIEW restrictions become read rules. EDIT rules
are applied only where doing so cannot bypass separate VIEW restrictions. Unknown
or unmapped principals are reported and not granted access. Calendar administrators
and the protected Calendars manager group retain their normal access. Missing source restrictions do **not** enable anonymous
or authenticated-wide access. If child calendars have separate ACLs that cannot
be represented by one native calendar, it remains accessible only to calendar managers for review.
Existing imported calendar settings/ACLs are not overwritten on a repeat import.
An existing target with a different type or personal owner is rejected, not
converted or reassigned. Correct the selection or explicitly manage/remove the
target before a fresh import; never patch partially imported event rows.

Event UIDs, plain-text descriptions/locations, all-day exclusive end dates,
calendar timezones, supported recurrence rules, EXDATE and moved occurrences are
preserved. Source event-type labels are reused as native types rather than creating
one type per event. ICS text is escaped; HTML is not executed. Imported events are
attributed to the importing administrator. Original organizers/invitees are not
turned into live meeting invitations or user subscription preferences. The importing
administrator is not subscribed to imported calendars merely because they can read
or manage them. A personal owner keeps their normal owner subscription. Other
calendars remain available through administration or an explicit subscription;
they do not appear in **My calendars** just because they are readable.
Source hexadecimal colors and Confluence's named `subcalendar-*` palette are
preserved; unknown colors use the default with a preview/report warning. No historical
invitations, reminders or per-event notifications are sent.

The current native recurrence engine supports daily/weekly/monthly/yearly patterns.
C4 accepts only patterns it can faithfully reproduce (including a matching single
weekly weekday and supported ordinal monthly weekdays). Unsupported clauses,
ambiguous duplicate UIDs, orphan exceptions and inconsistent dates are skipped
with reasons, not silently converted into different events. Confluence `COUNT=-1`
means unlimited and is removed. Warnings include at most 200 example event IDs per
calendar; the skipped count covers all records. Retain the source archive for
manual handling of these records. A calendar can legitimately import zero events.

## Consistency, storage and backup

The review expires after 30 minutes and is tied to its administrator, source hash,
confirmed C2 maps and existing target calendar snapshots. Changes require a new
review. Once confirmed, independent component edits/execution are blocked for the same job.
Do not change mapped users/groups during the import. An unexpected failure rolls
back the target/event writes, source mappings and cursor together. Repeating the
same source URL/calendar reuses its mapped calendar and skips existing UIDs; it
does not overwrite manually changed events or remove events missing from a later
export. This is migration, not continuous synchronization.

An unexpected error stops the combined run and preserves completed/partial reports.
It is not a rollback of earlier committed batches. During development acceptance,
remove only that attempt's objects, correct the procedure and start a fresh import.

Private indexes live beside managed uploads under
`data/confluence-import/uploads/<managed>.zip.calendars/`, never `HPH_CONFIG_PATH`.
Configuration remains under `HPH_CONFIG_PATH/confluence-import/`. No public download
route exposes this index. Limits include 2 million selected AO rows, 20,000
subcalendars, 512 MiB retained event data, 64 MiB/100,000 source events per converted
calendar, and bounded XML values/JSON records. No complete multi-GB XML is extracted.
Incomplete scans restart; a complete index can be reused. Prepare again to rebuild
missing/corrupt partitions. A preparation lock prevents concurrent rebuilds.
The sibling `.calendars.progress.json` contains only source digest, phase, counters
and start time, never people/events or credentials. Atomic snapshots are throttled;
the live lock, not an arbitrary age timeout or the mere existence of a lock file,
determines whether preparation is running. These transient files are removed with
the managed source and are not portable backup data.

The importer-owned `simbioza_confluence_import_calendars` table maps source-site
hashes and calendar/subcalendar IDs to native calendar IDs. The initial schema
installer also creates this table when rerun on an existing development install:

```sh
php vendor/bin/hph simbioza-confluence-import:install-migration
php vendor/bin/hph orm-migrate:up
php vendor/bin/hph orm-migrate:status
```

Check migration status before testing. No Auth or framework migration is required.
The report is a separate completed `site_calendars` job and survives even when C3
has not run. Full-site/component backup includes durable calendar mappings and
reports, with Calendar/Auth/job IDs remapped on restore. Include the Calendar
component when restoring these mappings. Native Calendar backup owns the actual
calendars/events; page embeds use stable calendar UUIDs. Transient indexes, review
tokens, local C2 execution choices and source ZIP paths are not portable state.

## Verification

Focused fixtures cover DTD rejection, filtering unrelated plugin secrets, hierarchy
and aliases, partition tampering, timezone/date/recurrence conversion, C2 owner/group
reuse, review ownership, transactional rollback, resumability and repeat imports.
The complete disposable application additionally verifies the real Calendar API
and unchanged single-space browser/API import. Development is applied to HFC only;
demo, apps-test, Auth, framework and published tags are not changed.
