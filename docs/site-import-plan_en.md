# Confluence site import implementation plan

[Croatian version](site-import-plan_hr.md)

Extend the existing single-space importer with a controlled migration of an entire
Confluence site or a multi-space export. The existing single-space workflow remains
available and retains its content conversion, permissions, attachments and reports.
Development and acceptance take place on HFC. Do not publish a release, update the
demo or change apps-test until the user approves the completed work.

## Progress

- [x] **C1 Archive recognition and inventory** — implemented and verified on HFC, 15 October 2026.
- [x] **C2 Identity and access migration** — users/groups/memberships implemented and accepted; sign-in and global administration remain in Auth.
- [x] **C3 Selected Workspace imports** — implemented and verified on HFC, 15 October 2026.
- [x] **C4 Calendar migration** — implemented and verified on HFC, 15 October 2026.
- [x] **C5 Reports and final acceptance** — implemented and locally verified, 15 October 2026; publication remains separate.
- [ ] **C6 One reviewed site workflow** — implemented; actual coordinator execution passed. Unlocked GUI review found calendar classification/ownership, palette and actor-subscription defects; see the acceptance follow-up below. No release yet.

## C6 One reviewed site workflow

### Current acceptance — 16 October 2026

#### Follow-up: calendar macros and inactive identities

- [x] Preserve every selected calendar in a multi-calendar macro, its supported presentation settings, partial resolution and separate missing-calendar warnings.
- [x] Read account and directory activity independently from portable archive metadata; show unknown states and never prefer an inactive directory merely because an account flag is active.
- [x] Default to no automatic new accounts for inactive/reference-only sources, retain explicit mappings and offer deliberate archival accounts or administrator custody without merging unrelated personal spaces or granting source ACLs through a fallback identity.
- [x] Verify focused quality/regression checks and the HFC preview without executing a new HFC import; keep the ten approved aliases and other saved selections intact.
- [x] Update separate English/Croatian documentation and examples; no source-specific usernames, directory names/IDs or calendar IDs in application rules.

Only the importer may change. Auth/framework code and the production wiki are
read-only; no import, commit, tag or release is authorized for this follow-up.

Implemented in `ConfluenceCalendarMacro`, the converter/resolver, `ConfluenceSourceActivity`,
the source catalog/planner/review and explicit per-space custody. The v2 private
identity catalog was rebuilt from the unchanged archive: 217 active identities,
108 disabled accounts, 44 accounts in a disabled directory and 73 historical-only
references. These are source-snapshot facts, not live production updates. The HFC
preview now plans 181 new inactive accounts, 65 mapped references and 196 skips;
two creation conflicts remain. All ten approved primary/alias pairs and every saved
draft/selection remain unchanged, as do protected Auth/calendar/subscription/content
and durable mapping rows. No HFC import has started.

Checks: PHPCS, Rector and PHPStan pass; **290 tests / 1,824 assertions** pass in
the source and verified dependency environment. **Five isolated GUI scenarios**
pass: combined multi-calendar/partial linking with separate reports, paged drafts,
additive duplicate personal spaces, explicit inactive-owner custody, and the existing
single-space ACL/private attachment path. HFC GUI shows separate activity flags,
inactive/historical/unknown filters and the additional archival-account choice.
Translation, bilingual-comment and separate-language documentation audits pass.
Personal calendar ownership still requires a deliberate owner choice; custody never
subscribes the importing actor or assigns every former user's calendar to them.

#### Ten confirmed duplicate pairs saved in the draft

The user confirmed the ten pairs with a matching full name and identifier. Their
primary-account and source-alias choices were saved through the HFC GUI, without
applying identities or starting the import. The remaining review has ten groups
(seven name-only and three differing-name/shared-identifier cases), including six
unresolved source creation conflicts. The draft now plans 371 new inactive primary
accounts and 65 mapped source references, including the ten aliases; six references
remain skipped pending review. These are planned actions, not newly created users.

A read-only before/after check confirms exactly the twenty approved draft entries
changed. All other identity choices, space selections, workflow/calendar settings,
existing Auth/user/group rows, calendars, subscriptions, workspace content and
durable import mappings remain unchanged. Recomputed calendar proposals have 101
unfinished choices after resolving the approved aliases; no calendar choice was
manually approved or changed. No Auth/framework code change, HFC import or release.

#### Read-only mapping proposals ready for human confirmation

The following records the proposal baseline before the ten-pair confirmation above.

The HFC job has a private owner-only proposal page (`mapping_review=1`), linked
from identity/calendar setup. It groups 20 potential duplicate sets: ten with
matching full name/identifier, seven name-only sets and three differing-name sets
sharing identifiers. The latter include service/shared-address cases; none is
automatically merged. All 26 source creation conflicts remain pending decisions.
The read-only calendar table covers all 107 unfinished choices: 65 proposed team,
39 proposed personal and three requiring a type decision. Nine proposed personal
calendars lack reliable owner evidence. Matching given name plus personal-space
login is explicitly weak; contradictory embedding-owner hints are suppressed.
No saved choices or HFC business rows are changed by this page. It has separate
search/pagination and edit links to the exact source calendar/filtered identity.
See the separate [mapping review guide](site-mapping-review_en.md).

Checks pass: PHPCS/Rector/PHPStan and **281 tests / 1,769 assertions**; four isolated
importer browser scenarios, including private proposal access, paged/searchable
review, exact calendar edit links, retained drafts, duplicate-personal import and
the unchanged single-space ACL/private-attachment path. Separate-language docs,
translations and bilingual-comment audits pass. HFC remains ready/inventory with
352 selected spaces, 361 planned new inactive accounts and 26 pending conflicts;
credentials/SSO/admin and original personal-content hashes remain unchanged, with
zero new notifications/email. No mapping approval, new HFC import or release.

#### User-requested full-source test — prepared, not imported

The user expanded the test to every source user, calendar and space. A fresh ZIP
was uploaded and verified through the HFC GUI: all **352 spaces / 44,594 current
pages** and **266 calendars** are selected in the draft. Identity rules select
55 mapped references, 361 new inactive accounts, nine existing and 11 new ordinary
groups, with memberships. **26 source account collisions still require review**;
they are not silently merged or duplicated. Seven personal spaces currently lack
a resolved owner. Duplicate source references can now explicitly use the same
existing or planned primary account; missing/cyclic relations fail before writes.
Two personal spaces of one owner are supported with add-only for every source,
one verified native Personal Workspace and separate source mappings/reports.
Their HFC draft uses add-only; existing content remains unchanged.

The calendar draft accepts 159 unique owner proposals (existing or planned source
accounts); **64 personal calendars still lack an explicit owner and 43 calendars
still lack a type**. No creator-to-owner fallback or automatic team classification
was used. Previously misclassified test targets also need an explicitly approved
cleanup/reimport decision; existing calendar ownership is not silently rewritten.
The full-source execution has **not started**. The administrator's working personal
calendar and seven active subscriptions remain outside any proposed cleanup.

The source has no attachment binaries. The shared-site ZIP/index and attachment
selection/registration/report tests were rerun: **13 tests / 66 assertions pass**.
This verifies synthetic binary preservation, not a real complete-site attachment
transfer. Keep C6 and publication open until the mapping decisions and real GUI
execution are complete. No release, Auth or framework code changes.

#### Unlocked visual review and calendar follow-up

**Duplicate/personal-space and pagination follow-up, 16 October:** importer-only
changes add explicit source-account aliases, primary-account creation once,
membership deduplication and grouped add-only personal-space settings. Later child
imports persist their verified target before entering the unchanged single-space
queue. A real isolated GUI fixture passes merge, author attribution, cross-space
links, private binary download and repeat-import preservation. No HFC import starts
as part of preparing this review. The **Needs type or owner confirmation** calendar
filter presents 107 unresolved rows; they still need human confirmation.

Space selection, selected-space settings and calendars now have independent
25/50/100-row paging above/below, compact numbered windows with ellipses, whole-list
filtering, selection counts and distinct current-page/all-filtered selection.
Off-page draft fields remain in the form and are saved together. Auth/framework,
credentials, memberships and existing HFC calendar subscriptions are not modified
by this review. The full-source import and publication remain pending.

Verified for this follow-up: importer PHPCS/Rector/PHPStan and **273 tests / 1,739
assertions** pass. The disposable application wrapper passes **four real browser
scenarios**: combined site reports, 131-space/132-calendar pagination with retained
drafts, additive duplicate-personal import/reimport, and the original single-space
ACL/private-binary flow. HFC shows 352/352 spaces and 266/266 calendar selections
with 25 visible rows per section; page changes retain unsaved content names.
Translation, bilingual-comment, separated-documentation and whitespace checks pass.
HFC credentials/SSO/admin hashes and original personal content remain unchanged;
there are zero new notifications/email and **16 applied / zero pending migrations**.
The pagination scenario also passes separately with native validation of an empty
required field on a hidden page: the invalid row is revealed and no partial draft
is submitted. Its disposable source is removed through the normal GUI cancellation.

**Preparation/search UX follow-up, 16 October:** preparation now publishes private,
source-bound phase/counter telemetry under the same worker lock. An authenticated,
owner-only read endpoint lets the GUI follow it automatically, including after a
reload or a lost preparation response. Status reads neither restart scanning nor
rotate mutation CSRF tokens. Unknown totals use an indeterminate bar; only event
partitioning has a measured phase percentage. Failure/interruption releases the
busy state and permits an explicit retry. Managed-source cleanup removes telemetry.

Calendar owners now use the existing asynchronous search/pagination picker, not a
full directory repeated per row. At most 25 results include existing inactive local
accounts and selected future inactive source accounts. Existing-user search runs
through Auth's existing database-paged lookup API without changing Auth. A local
owner can be explicitly selected independently of source mapping; it never creates
an identity relation or changes login/admin settings. Missing/deleted owners are
revalidated before writes. Personal type is no longer disabled for an empty saved
mapping list. The unit regression includes more than 10,000 existing accounts.

Verification after these changes: **262 tests / 1,672 assertions**, PHPCS, Rector
and PHPStan pass on PHP 8.5. Both isolated browser scenarios pass: the site workflow
follows a locked preparation across reload, displays measured partition progress,
automatically opens the preview and imports both a proposed future owner and a
searched existing local owner through the real Calendar API. The unchanged
single-space attachments/ACL scenario passes too. Live HFC shows zero disabled
personal options, loads 20 then 40 existing-user results with **Load more**, and
filters to one result. The owner-only status endpoint reports the ready 266-calendar
catalog. No new real HFC import, owner/access change or release occurred.

**Owner-review follow-up, 16 October:** `ConfluenceCalendarOwnerSuggestions.php`
now provides the actual importer/GUI proposals, not a development-only repair.
Full-name/login matches, duplicate targets, unmapped sources and weak personal-space
hints are separately reported; no creator-based ownership fallback exists. Manual
acceptance fills settings only, leaves calendar selection unchecked and preserves
existing choices during bulk acceptance. Completed imports expose the review
read-only. Search excludes unselected picker options; filters have visible counts.

The real source contains **226 personal-owner candidates**: 35 uniquely mapped,
125 unmapped, 11 ambiguous, 21 weak hints and 34 without a reliable proposal.
This deliberately includes 12 additional custom/name-based candidates beyond the
214 originally identified from names/personal-space associations. These are
proposals, not 226 asserted personal owners. Human confirmation remains outstanding.
A fresh archive upload and archive preflight completed through the signed-in HFC
GUI; both identity and calendar catalogs are ready in the new job. No new content,
calendar ownership or access has been applied before that confirmation. The next
actual run must also start and continue through GUI, not the coordinator CLI.

Focused quality verification passes on PHP 8.5 (252 tests / 1,592 assertions after
the personal-candidate checks), plus bilingual/documentation/translation audits.
The isolated browser test verifies accepting a proposal, retaining an unchecked
calendar selection, correct name filtering, the combined import and stable separate
reports. The normal single-space browser scenario also passes. No release.

The 43 earlier checks below prove execution and preservation of the chosen plan,
not the semantic correctness of all chosen calendar owners/types. Visual review
found 70 calendars named as personal work calendars still classified as team.
Many different people's calendars share one Confluence `CREATOR`; that field is
not proof of personal ownership. The earlier count of 64 mapped creators must
not be presented as 64 confirmed personal owners.

The importer now requires an explicit personal owner, never preselects the source
creator as one, suggests Personal for recognizable work/private/personal names and
requires a type choice for ambiguous rows. Named Confluence colors are preserved.
Bulk creation disables the importing actor's automatic subscription; permissions
and subscriptions remain separate. The three site reports now render their
metadata on normal theme cards, not over the hero background.

Without reimporting HFC, the public Calendar API corrected 162 source colors and
removed 162 import-created administrator subscriptions. The six earlier subscriptions
and the administrator's own imported personal calendar remain. Event rows, semantic
ACLs, owners/types, Auth/SSO and other users' subscriptions were verified unchanged;
no notifications or email were created. This intentionally does not reassign the
ambiguous personal calendars or rewrite historical reports as if the initial
selection had been correct.

- [x] Review the actual combined, identity and calendar reports on an unlocked Mac.
- [x] Verify My calendars uses subscriptions and administration retains access to other calendars.
- [x] Fix future importer palette, actor subscription and explicit personal-owner validation.
- [ ] Confirm actual personal owners/types before correcting the remaining HFC test metadata.
- [ ] Repeat the final full candidate/container verification after these additional changes.

Historical execution evidence follows; its counts describe the completed original
selection, not a newly approved owner/type plan.

The real HFC GUI uploaded the fresh archive and accepted the combined review.
The locked Mac blocked the native final confirmation. Following the user's
unattended-work instruction, the same frozen selection completed through the
normal application coordinator CLI with the real `/hfc` URL context before
content processing. This is not an end-to-end GUI-start success. Do not duplicate
the completed import merely to finish visual acceptance.

Actual results: 55 mapped identities, zero new users, eight new ordinary groups,
one mapped group, 105 memberships, 346 pages in 30 personal spaces (29 new and one
existing add-only target), 41 personal plus 122 team calendars, 32,755 imported
and 131 explicitly skipped events. All 32 reports completed. All 43 read-only
acceptance checks pass: preserved baseline IDs, source creator/editor mappings,
readable workspace-backed documents, mapped personal owners, restricted calendar
access and unchanged long source titles/location. Auth/SSO/admin state and the
original personal target's two nodes/documents/homepage/ACL are unchanged. No
notifications or mail were created. Exact IDs, hashes and report UUIDs stay private.

Reports retain 197 resolved and 656 unresolved links, 83 review issues and five
pending external-script issues. Unselected/unmapped targets are not silently
created; 679 source memberships remain outside the confirmed mapped scope.
This archive has no attachment binaries and the selected pages have no calendar
embeds; isolated single-space/real-service fixtures verify those paths, not this
actual source selection. One original personal document reference was already
unreadable before import and its hashes remain unchanged; final visible inspection
is still pending. No release, push, Auth or framework changes.

### Title-limit failure and clean retry

The first real GUI attempt stopped on Calendar's old `title VARCHAR(255)`.
The source has two longer titles (maximum 706 characters) and a location of 468
characters. The user approved title/location limits of 765/510 characters. Calendar
owns the fresh schema and lossless upgrade migration; Simbioza provides the updater
wrapper. API/ICS validation and source review reject/report excess without truncation.
A prominent accessible current-step progress bar now has a separate visible
percentage and indeterminate preparation/linking states.

Exact ownership was verified before deleting only that attempt's eight new groups,
105 memberships, 22 calendars, 4,025 events, 27 event types and new importer mappings.
Baseline row IDs/hashes were unchanged. Failed reports and original ZIPs are retained;
the failed parent is not relabelled as successful. The fresh retry completed without
an import error.

### Implemented coordinator and preservation boundaries

`ConfluenceSiteWorkflow.php` coordinates a frozen selection;
`ConfluenceSiteIdentityPlan.php` validates planned users without fake database IDs.
`views/settings/site-workflow.php` and the section forms save drafts before one final
review/confirmation. Independent component HTTP entry points cannot bypass a frozen run.

Separate identity/group/membership, calendar and per-space reports remain linked
from the parent. Current persisted link counts update older reports without
replacing UUIDs. Calendar linking visits earlier completed reports of the same
owner/source. Verified-source references support mixed standalone/site imports;
foreign/unknown origins and isolated copies remain separate.

Explicit add-only personal import preserves existing workspace settings, ACL,
homepage and pages. Repeated imports reuse source mappings without modifying old
documents, duplicating attachments/comments or replacing the workspace on retry.
Same-title native pages remain intact; new pages receive collision-free slugs.
Real-service fixtures pass for both HTML stores, including later new children/links.
The actual HFC existing personal target and its original hashes are preserved.

### Verification and remaining work

Current importer `composer on-commit` passes on PHP 8.2–8.5: **242 tests /
1,509 assertions**, PHPCS, Rector and PHPStan. The full application wrapper passes
**81/81 browser/API/performance tests in 23.2 minutes** after the title/progress
changes, including unchanged standalone ACL/attachment/search behavior. Earlier
failed runs remain recorded: the embedded-search source context and a test cleanup
navigation race were fixed without weakening isolation or disabling assertions.

The secured-FPM GUI upgrade passed with retained config/content. Current Docker
PostgreSQL verification reaches 16 migrations with zero pending, immutable code and
preserved persistent state. Full release evidence is in the application's separate
`docs/release-preflight-0.3.3_en.md` / `_hr.md` documents.

- [x] All site screens configure/review only before the final confirmation.
- [x] Freeze and validate identities, memberships, calendars, owners and spaces; reject stale/concurrent changes.
- [x] Run resumable idempotent identities, calendars, spaces and reconciliation under one parent.
- [x] Offer explicit personal/team choice with an explicitly selected existing local or mapped/planned source owner for personal calendars.
- [x] Reuse single-space content, authorship, ACL, attachment and bidirectional link handling.
- [x] Link supported calendar embeds; report unsupported references and unapproved scripts.
- [x] Provide visible combined progress and stable separate reports; exclude transient choices from backup.
- [x] Verify purity, inactive-user projections, stale reviews, concurrency/resume, add-only preservation and both HTML stores.
- [x] Complete the actual HFC coordinator import and reports/access/notifications checks; update separate EN/HR docs.
- [x] Complete read-only visual HFC acceptance with the Mac unlocked, without duplicating the completed import; verify native confirmation in isolated browser tests.
- [ ] Finish the calendar owner/type semantic follow-up described above before release approval.

Keep C6 open until that remaining interactive check is genuinely verified.
Publication remains a separate user-approved action.

## Source archives

The DABAR single-space export contains 253 current pages and 393 attachment files,
including other versions and deleted records. The site export identifies itself
as `exportType=site` and declares `backupAttachments=false`.

The inspected site snapshot contains 352 spaces (206 global and 146 personal),
44,594 current pages, 369 directory users, 442 Confluence identity references,
20 directory-group records with 19 distinct names, and 784 memberships. Accounts
and author references must be reconciled, not added together as different users.

The Active Objects package contains 266 original calendars, 102,636 event records,
subscriptions, event types and access restrictions. Its 5,752 subcalendar records
are not 5,752 different calendars. Most plugin rows are technical history rather
than migration content. The ZIP is about 3.76 GB and its uncompressed entries
total about 33.9 GB; avoid extracting the complete export merely to inventory it.

## C1 Archive recognition and inventory

- [x] Recognize single-space, multi-space and site archives from their descriptor
  and verified contents, not their filename. A site with one space remains a site.
- [x] Keep the current single-space scanner, mappings and confirmed-import path.
- [x] Build a bounded-memory inventory and a private on-disk index for a site or
  multi-space archive. Do not retain document bodies, passwords, tokens or full
  plugin rows in the inventory or application job summary.
- [x] Show per-space identifiers, names, types and content counts; allow an
  administrator to save a selection without creating users, groups or content.
- [x] Report omitted attachment binaries separately from broken archive contents.
- [x] Make site-specific size limits configurable under
  `HPH_CONFIG_PATH/confluence-import/confluence-import.php`; keep ZIP traversal,
  symlink, compression-ratio, DTD and external-entity protections.
- [x] Keep temporary indexes under private application data. Large test fixtures
  may use the explicitly authorized Seagate work disk; never delete source ZIPs.
- [x] Reject confirmed multi-space/site import until C2–C4 implement those writes;
  C1 must never silently import only the first space.
- [x] Test recognition, index caching, selection validation, malformed archives,
  resource limits and the unchanged single-space workflow. Verify the real site
  snapshot and the HFC UI. Record whether interrupted inventory restarts or resumes.

## C2 Identity and access migration

- [x] C2a Stream and validate a private identity/access catalog; merge only unambiguous author references.
- [x] C2b Review independent user/group/membership choices and source-site namespaces before writes.
- [x] C2c Apply confirmed choices transactionally and retain durable mappings/reports without changing Auth code.
- [x] C2d Verify idempotency, existing memberships, secret filtering, backup and the real HFC UI.

Auth and the framework remain unchanged. Reuse existing account/group services;
the importer's membership integration must preserve unrelated manual, automatic
and excluded memberships. No HFC SSO profile or existing sign-in policy is overwritten.

Introduce a source-instance identity and shared mappings for all selected spaces.
Distinguish Confluence user keys, directory user IDs and directory group IDs.
Resolve same-name groups across directories explicitly. Reuse existing users only
after an unambiguous mapping and preserve their current configuration.

Offer independent selections for users, groups and memberships. Create selected
unmapped accounts as inactive users without sign-in access. Use existing Auth account/group services and the importer's
additive relation adapter; do not replace unrelated memberships or infer them from
content permissions. Personal ownership and content restrictions remain C3 work.

### Accepted sign-in and administration boundary

`confluence-administrators` is an ordinary imported group. Assign global
administrator privileges explicitly in existing Auth administration. Configure
local/SSO sign-in in user settings or global Auth settings. Do not import password
hashes, provider configuration, tokens or sessions, and do not add conversion or
activation workflows to the importer. This is the accepted C2 scope, not deferred
C2 work. Auth code and current HFC providers remain unchanged.

## C3 Selected Workspace imports

- [x] C3a Prepare a bounded private shared content index for the selected spaces.
- [x] C3b Orchestrate owner-bound parent/child jobs, source namespaces and safe shared-archive cleanup.
- [x] C3c Reuse page/history/attachment conversion and ACL with C2 user/group mappings; require mapped personal owners.
- [x] C3d Review replace/copy decisions and reconcile cross-space links; expose interruption/resume/retry progress.
- [x] C3e Verify isolated fixtures, actual HFC archives and unchanged single-space imports; document actual limitations.

Use one parent site job and separately resumable child jobs for selected spaces.
Reuse the current converters and page/history/attachment/ACL rules with a
space-scoped indexed dataset. Make cross-space IDs and links share the correct
source namespace. Do not scan or extract the entire source ZIP again for each
child, and do not remove the shared ZIP after the first child completes.

Support current content by default and explicit history/draft/deleted choices.
Personal spaces require owner mapping. Existing targets require a reviewed
replace/copy decision. Do not overwrite unrelated workspaces or identities.
Keep background/CLI processing and browser progress safe for long migrations;
record interruption, retry and cancellation semantics before enabling the flow.

## C4 Calendar migration

- [x] C4a Bounded Active Objects reader and private calendar/event index; verify the real site archive.
- [x] C4b Review calendar selection, confirmed C2 principals, owner/access decisions and unsupported records.
- [x] C4c Resumable, idempotent Calendar API import without invitations, reminders or external fetches.
- [x] C4d Resolve imported page calendar references, retain reports and portable mappings.
- [x] C4e Focused/integrated tests, separate EN/HR documentation and HFC verification; no release.

Read supported Team Calendars tables from the Active Objects package, select
original calendars and distinguish subscriptions and event-type subcalendars.
Map owners, source IDs, types, access rules and page embeds through Calendar APIs.
Preserve supported timezone/all-day, UID, recurrence and exception semantics.
Report unsupported stores and missing space references. Do not send historical
invitations, reminders or external requests during migration. Re-running a job
must not duplicate calendars or events.

## C5 Reports and final acceptance

- [x] C5a Combined live/durable identity, space and calendar report with bounded queries.
- [x] C5b Explicit safe source cleanup, portable reports and recovery regression tests.
- [x] C5c Fresh local-source dependency install; SQLite/PostgreSQL/MySQL/MariaDB and both document stores.
- [x] C5d Real mod_php, ordinary/secured FPM, single-space/site/attachment/script regressions.
- [x] C5e HFC runtime verification, separate EN/HR documentation and recorded acceptance limits; no release.

Combine parent and child progress and retained reports. Reconcile cross-space
links and calendars after selected work is complete. Keep the approved-script
registry and review unknown scripts rather than executing imported code.
Use supported task/sidebar/template data only through deliberate conversions;
do not import audit/analytics history, application binaries or arbitrary AO tables.

Include durable mappings and reports in backup while excluding upload archives,
indexes and temporary data. Document cancellation and cleanup. Verify SQLite,
PostgreSQL and MySQL/MariaDB, filesystem and database document storage, secured FPM
and mod_php, single-space regressions and site imports with/without attachments.
Keep the framework unchanged. Release only after the user reviews HFC and approves.

## Completed work

### C1 completed on 15 October 2026

Implementation:
- New metadata pipeline: `ConfluenceXmlInventoryFramer.php`,
  `ConfluenceInventoryReader.php` and `ConfluenceSiteInventory.php`.
- Archive recognition, dedicated size limits and DI are in
  `ConfluenceArchive.php`, `ConfluenceImportConfig.php`,
  `ConfluenceExportScanner.php` and `config/services.php`.
- Job storage, validated selection, cancellation and cleanup are in
  `ConfluenceImportRepository.php` and `ConfluenceImportUploadService.php`.
  All three confirmed-import entry points reject inventory-only jobs.
- The administrator route/controller and `views/settings/site-inventory.php`
  show the catalog, filter it and save source IDs. EN/HR translations and the
  [inventory guide](site-inventory_en.md) describe the current behavior.
- HFC's actual runtime module was updated, not only the source repository.
  No framework, Auth settings, database schema, demo or apps-test change was needed.

Verification:
- 132 PHPUnit tests / 829 assertions passed, also with HFC's installed dependency
  autoloader. PHPCS, Rector dry-run, PHPStan and `git diff --check` passed.
- Existing application E2E `confluence-import.spec.js` passed on isolated SQLite,
  including single-space content, ACL and private attachments.
- The real full-site ZIP produced the audited 352-space / 44,594-current-page
  inventory and a reusable private index without materializing its XML.
  Standalone peak PHP memory was 8 MiB; the integrated HFC preparation peaked
  at 24 MiB. The original DABAR scanner retained its 253 current pages,
  393 attachment records and 332 logical current attachments.
- On HFC, filtering, selecting visible rows, saving and restoring selection after
  reload passed. Direct `queue()`, `queueBatch()` and `import()` calls all rejected
  the site preview before mutation. Auth configuration hash and counts of users,
  groups, memberships, workspaces, nodes, documents and assets stayed unchanged.
  Direct HTTP access to the private index returned 404.

Limits and next step: initial inventory restarts after interruption; only a
complete index is reusable. C1 does not convert Active Objects calendars or write
site content. PHP minimum remains 8.2; these local checks ran on PHP 8.5.11, not
a new 8.2–8.5 CI matrix. This is an unreleased HFC development change.
Later C2–C4 implementations and their checks are recorded below; C5 remains open.

## Core C2 implementation record — 15 October 2026

- `ConfluenceSitePrincipals.php` builds a bounded private metadata catalog;
  `ConfluenceSiteAuth.php` integrates existing Auth account/group services and
  additive memberships without modifying Auth. `ConfluenceSiteAccessService.php`
  validates source namespaces, owner-bound reviews, target snapshots and idempotent
  transactional application. Confirmed mappings are batch-read by the repository.
- `ConfluenceImportController.php`, the manifest and `config/services.php` expose
  authenticated preparation/review/apply/search. `views/settings/site-access.php`
  paginates users, preserves saved choices, lists every reviewed action and disables
  apply after edits. A separate report view records the completed identity step.
- Upload cleanup includes `.principals.json`; saving a space selection preserves
  identity choices but invalidates its unapplied preview. Cancelling preparation
  does not undo applied identities or delete their separate completed report.
- The [identity guide](site-identities_en.md), README, inventory, installation,
  developer and troubleshooting guides describe the current behavior in separate
  English/Croatian files. No new migration, Auth/provider or framework change.

Verification: focused tests cover create/map/skip, inactive targets, namespaces,
immutable mappings, additive automatic/manual/excluded memberships, rollback,
expired/stale targets, owner checks, secret filtering and portable backup foreign
keys. The existing single-space browser/API test passed on isolated SQLite.
The live HFC catalog contains 442 reconciled identity choices, 20 directory groups,
784 memberships and 35,924 permission records. Its initial scan took 106 seconds,
peaked around 34 MiB and produced a 6.1 MiB private index without extracting XML.
Reading a saved HFC review used six queries, not one per source user. Browser
search/mapping/review were checked without applying real source accounts. Auth
row/config hashes and content counts remained unchanged; migrations are 14 applied,
zero pending. Final checks: 155 PHPUnit tests / 933 assertions passed against
HFC dependencies; PHPCS, Rector dry-run, PHPStan, `git diff --check`, translation
coverage, documentation pairing and the scoped EN-before-HR comment audit passed.
Both English and Croatian HFC UI were checked without console errors.

No importer tag/release was published. C2 was accepted with the Auth boundary
above. Next is C3 selected-space content import; do not claim content/ACL/calendar
migration works yet.

### C2 interface and automatic planning refinement — 15 October 2026

- `ConfluenceSiteUserPagination.php` and `site-user-pagination.php` provide stable
  alphabetical pages, 25/50/100 rows, result ranges and a radius-two numbered
  window with first/previous/next/last symbols and ellipses. The rendered window
  remains bounded for 5,000 users and wraps inside a 320-pixel viewport.
- Mapping-status filters include mapped, unmapped, planned creation, automatic
  matches and conflicts. Filtering precedes pagination and never limits bulk scope.
- `ConfluenceSiteUserPlanner.php` indexes target logins and email aliases once.
  It proposes only unambiguous exact matches, reports source/target collisions,
  and optionally plans inactive account creation for the entire catalog.
  Manual choices (including skips) take precedence; a row can return to automatic
  rules. Auth and the framework are unchanged. Only explicit Apply writes accounts.
- `ConfluenceSiteAccessService.php`, controller and views retain provenance in
  previews/reports, preserve decisions from hidden pages, and keep unsaved choices
  after a failed save. The picker uses a native top-layer popover to escape the
  sticky header, shows the selected account/check mark and emits change for both
  keyboard/mouse selections. A same-URL policy change explicitly reloads the view.
- Verification: 178 PHPUnit tests / 1,179 assertions pass, also using HFC-installed
  dependencies; PHPCS, Rector dry-run, PHPStan, translation/documentation audits,
  scoped EN-before-HR comment checks and `git diff --check` pass. The isolated
  existing single-space browser/API scenario passes with ACL/private files.
  HFC browser checks cover filters, the full-catalog preview, selection/reopening,
  keyboard/mouse, save failure without navigation, first/middle/last pages and
  320-pixel layout. Auth row/configuration hashes and content counts stayed unchanged.
  The test-only creation option was disabled again; no real account Apply was run.

These user-requested C2 refinements preceded the C3 implementation recorded below.
No release has been published.

### C2 automatic group rules — 15 October 2026

`ConfluenceSiteGroupPlanner.php` indexes exact normalized target names once.
Global auto-map and optional missing-group creation cover the entire catalog;
manual map/create/skip choices take precedence and can return to automatic rules.
Duplicate source-directory names use directory-qualified names. Ambiguous,
system, disabled or invalid targets require manual review. Apply remains explicit.
Focused tests and the real HFC preview pass. The test-only creation flag was
disabled again and the original nine manual group choices were preserved. No real
identity Apply was performed during verification; Auth source is unchanged.

### C3 implementation and verification — 15 October 2026

- `ConfluenceSiteContentIndex.php` uses two shared ZIP passes and selected-space/
  version partitions. It filters unrelated records before DOM conversion, bounds
  resources, verifies source/policy caches and never extracts full XML. Ownership
  lookup skips large opaque CDATA respecting comment boundaries; selected content
  still uses original XML. Sensitive XML arguments are redacted in exception traces.
- `ConfluenceSiteContentService.php`, controller, DI, manifest and C3 views handle
  separate review/confirmation, parent/children, source namespaces, copy aliases,
  progress and recovery. The normal scanner/converter/history/attachment/ACL engine
  consumes confirmed C2 maps. Shared sources stay available for C4.
- Workspace purge retains site-child UUIDs/shared ZIPs, deleting only target
  mappings/staging. Retry rebuilds only a failed child's own partial target, not
  completed siblings. Backup strips local execution options, not durable provenance.
- Personal import returns the actual Workspace row instead of its mapping wrapper.
  Simbioza User's `ensureForUser()` allows an inactive owner only for explicit
  non-automatic provisioning by a different active administrator, without activating
  the account or changing credentials/providers. Auth/framework are unchanged.
- [C3 instructions](site-content_en.md) and the separate Croatian guide document
  binaries, history, source URLs, owner constraints, retry/cancel and C4/C5 limits.

Verified so far: importer PHPCS/Rector/PHPStan and 205 tests / 1,299 assertions before
the added large-CDATA regression; existing single-space browser/API ACL/private-file
scenario (1 passed, 58.6 s). Complete disposable HFC-dependency integration passed
two spaces with/without binaries, history, forward/backward links, sibling copies,
partial-copy retry, mapped group ACL and an inactive mapped personal owner. HFC
C3 review/disabled stale confirmation and English/Croatian UI were checked without
starting real content import. Auth row/config hashes and HFC content counts stayed
unchanged. Temporary trial spools were removed, not source archives.

Final large-source check passed after bounded CDATA ownership optimization:
selected DABAR from the real 28.6 GB XML, without extraction, took 261.33 seconds,
peaked at 466.02 MiB and wrote 7.3 MiB of private partitions. It retained 253 current
pages, 393 attachment records and 332 logical current attachments, matching the
single-space snapshot. A verified warm-cache scan took 0.29 seconds. Earlier trial
timeouts were not accepted as successful indexes. Original archives stayed intact.

Final importer checks: 206 tests / 1,301 assertions, PHPCS, Rector dry-run and PHPStan
pass. Simbioza User checks: 23 tests / 223 assertions and its code/style analyses pass.
The last single-space browser/API scenario passed in 56.9 seconds. Complete isolated
integration additionally verifies native cross-space includes (also sibling copies),
mapped group ACL, inactive-owner provisioning and confirmed personal replacement.
Separate EN/HR guides and HFC translations are synchronized. Real HFC business data
was not imported or replaced during these tests. No release, tag or push was made.
The subsequent C4 implementation is recorded below; full multi-database/deployment
acceptance remains explicitly in C5.

### C4 implementation and verification — 15 October 2026

- `ConfluenceTeamCalendarReader.php` streams only allowed Team Calendars tables
  from the AO ZIP entry, rejecting DTD/entities and bounding rows/fields.
  `ConfluenceSiteCalendarIndex.php` creates a private, source-verified catalog and
  per-original-calendar event partitions. It resolves internal subscriptions and
  event-type aliases without fetching external calendars or extracting full XML.
- `ConfluenceTeamCalendarConverter.php` preserves supported all-day/date/timezone,
  UID, recurrence, EXDATE and moved-instance semantics. Unsupported or ambiguous
  series are reported rather than silently flattened. ICS content cannot inject
  properties, execute HTML or send historical invitations/reminders.
- `ConfluenceSiteCalendarService.php`, controller/DI/routes and the new calendar
  settings/report views provide independent selection, expiring owner-bound review,
  explicit confirmation, 250-event checkpoints, repeat/resume/stop and page linking.
  Only confirmed C2 user/group mappings supply ownership/ACL. Unknown principals or
  unrepresentable subcalendar ACL never grant broad access. Existing mapped targets
  and manually edited events/permissions are preserved on repeated migration.
- The idempotent importer schema adds `simbioza_confluence_import_calendars`;
  backup includes durable target/source aliases and completed reports with foreign
  ID remapping, not private indexes, ZIP paths or review/execution tokens.
  C4 and C2/C3 mutations for the same source job cannot run concurrently.
- The public Calendar bulk API now retains canonical `TZID`, accepts optional
  `event_type_name` without per-event type queries, and participates in the caller's
  transaction. UTC recurrence limits are normalized for native expansion while
  retaining original ICS. Auth and the framework are unchanged. These Calendar
  changes are unreleased and must be included in C5 dependency/release validation.
- Separate [English](site-calendars_en.md) and [Croatian](site-calendars_hr.md)
  instructions cover the workflow, restrictions, recovery, storage and backup.

Verification:
- Importer: PHPCS, Rector dry-run, PHPStan and **217 tests / 1,370 assertions** pass.
  Calendar: the same analyses and **88 tests / 702 assertions** pass. Targeted
  bilingual comment, translation and separate-documentation audits pass.
- Disposable full-application integration exercises the real Calendar API, mapped
  owner/group ACL, event types, timezone/recurrence/moved instances, idempotent retry,
  rollback, C3 page references and repeat-safe linking. The existing single-space
  browser/API scenario passes. Repeated C3 integration also passes binaries/no
  binaries, history, cross-space links/includes, copies, recovery and personal owners.
- The supplied real source yields **266 original calendars / 102,636 event rows**:
  **101,523 supported and 1,113 skipped with reasons**, in 647 batches. Cold
  indexing/conversion took 87.39 seconds at 42 MiB peak; warm conversion took
  3.28 seconds at 40 MiB. It did not import those events into HFC.
- HFC runtime code and its new mapping schema are updated: **15 applied / zero
  pending migrations**. The Croatian UI selection/filter/review was checked with
  one source calendar (626 supported / 42 skipped). Confirmation was not executed.
  Existing Auth configuration/row hashes, content counts and six calendars/44
  events stayed unchanged. The disposable test installation was moved to Trash;
  original source archives and HFC private indexes were retained. No demo/apps-test
  update, tag, release or push.

The C5 local acceptance matrix is recorded below. The ignored module development
`composer.lock` is not a release artifact. Fresh Composer resolution was checked
against local candidate sources; newly published dependency tags and remote Git CI
remain publication gates, not a prerequisite for this explicitly unreleased step.

### C5 implementation and final local acceptance — 15 October 2026

This dated C5 evidence predates C6. For the completed real HFC import, current
migration/test counts and remaining GUI check, use the C6 section above.

- `ConfluenceSiteReportService.php`, bounded repository report queries and
  `site-report-overview.php` combine owner/source-verified identity, space and
  calendar runs. Pending, failed and partial steps remain visible; the overview
  counts warnings, attachment failures and unresolved scripts/review items without
  exposing imported code, secrets or private paths. Existing detailed reports
  continue to own their review/approval actions.
- Parent summaries retain report UUIDs. `ConfluenceSiteJobOptions.php` removes
  C2/C3/C4 local execution choices from portable metadata. Full cross-site backup
  and restore preserves the combined report and linked runs without source paths.
- `ConfluenceImportUploadService.php` provides an explicit owner/CSRF-protected
  source-cleanup action only after completed spaces and stopped workers. Content,
  calendar-index and database locks prevent races; unknown files/symlinks fail
  closed. Cleanup preserves imported content, binaries, mappings and reports.
  Automatic expiry no longer removes active or partially committed calendar jobs.
- Controller/routes/views/translations, the [report guide](site-reports_en.md),
  README and documentation index describe current behavior. No new C5 migration,
  Auth modification or framework change was required. Runtime code is on HFC.

Passed checks:
- Importer PHPCS, Rector dry-run, PHPStan and **222 tests / 1,398 assertions**;
  the tests also pass with HFC's installed dependency autoloader. Bilingual comment,
  translation, separate-documentation and whitespace checks pass.
- A fresh all-module local-source Composer installation, CLI, migrations and HTTP
  smoke test pass. The full application wrapper passes **80 browser/API/performance
  tests in 20 minutes**, including single-space attachments/ACL, scripts, backups,
  calendar APIs, editor storage, authorization and measured SQL budgets.
- The existing single-space browser/API test passes separately on SQLite,
  PostgreSQL 18, MySQL 8.4 and MariaDB 11.4. Synthetic C2/C3/C4 acceptance passes
  all **eight database × filesystem/database HTML-store combinations**, asserting
  the actual version storage driver, Calendar API/ACL/recurrence, repeat import,
  report portability and source cleanup. C3's richer binary/no-binary, history,
  cross-space link/include, sibling-copy, personal-owner and recovery regression
  also passes on PostgreSQL, MySQL and MariaDB.
- Localhost HTTP acceptance passes on **real mod_php (PHP 8.4)** and **ordinary
  and secured FPM (PHP 8.3)** with both HTML stores. Secured FPM has read-only code,
  an external writable configuration directory, writable data, explicit environment
  and restricted filesystem access. Test probes are restricted to disposable paths.
- A complete encrypted backup from one disposable site restores into another URL:
  four linked reports remain readable; local archive/execution paths are absent.
- HFC's report was checked in English and Croatian without starting the real site
  import or cleaning its archive. Migrations remain **15 applied / zero pending**;
  existing Auth configuration/row hashes and business-content/calendar counts are
  unchanged. Test containers/installations are removed; original ZIPs and HFC's
  reusable source/indexes remain.

Acceptance limits: the supplied real site has no attachment binaries; site binaries
are therefore verified with synthetic fixtures, while the actual single-space ZIP
was inventoried previously. The entire real 44,594-page site was not written to HFC.
Local tests are not a new PHP 8.2–8.5 CI matrix or verification of unpublished tags.
Review HFC first; release, dependency tags, remote CI, demo and apps-test remain
separate, explicitly authorized work. **No release, tag, commit or push was made.**
