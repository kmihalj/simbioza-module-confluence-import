# Developer integration

Croatian version: [developer-guide_hr.md](developer-guide_hr.md)

## Architecture

The module owns only Confluence-specific import state:

- jobs and resumable-upload metadata;
- source-space and source-content mappings;
- confirmed user/group mappings;
- unresolved/resolved cross-space link mappings;
- provenance metadata for attachments registered as native Editor assets.

The unique source-attachment key is `(job_id, source_attachment_id,
source_version)`. Confluence identifiers are stable only inside the selected
export/import context; the generated Editor UUID therefore belongs to one
import job and cannot collide with a replacement or a separately imported copy.

Workspace, HTML Editor, Auth, Menu and Simbioza User remain owners of their records. Optional integrations are detected through installed public service contracts; this keeps the importer out of other modules' private schemas.

## Processing stages

1. `ConfluenceImportUploadService` creates a job and validates ordered chunks.
2. `ConfluenceArchive` checks ZIP limits and safe entry names.
3. `ConfluenceExportReader` streams `entities.xml` objects.
4. `ConfluenceExportScanner` builds a read-only inventory and candidate mappings.
5. `ConfluencePrincipalMatcher` strictly suggests exact existing Auth users
   and groups; it performs no fuzzy matching.
6. `ConfluenceImportService` validates administrator confirmation, uses the
   public Auth service to create explicitly requested inactive staged accounts,
   and creates the target objects.
7. `ConfluenceHtmlConverter` produces portable HTML placeholders.
8. `ConfluenceReferenceResolver` replaces local targets and retains stable unresolved targets.
9. Optional Search, Audit, Comment and Backup integrations run through their public contracts.

The importer never writes Auth tables directly. `AuthUserService` enforces the
shared rules for provider-less inactive accounts, activation, local passwords,
and mandatory changes of administrator-set temporary passwords. The same
lifecycle therefore applies to this importer and any future migration tool.

Bulk page, workflow and ACL writes run through the public Workspace `WorkspaceContentChangeBatch` service. Source events are collected during that unit and one `bulk_content_changed` event is emitted per changed Workspace at the end. This prevents backlink and Search listeners from rebuilding derived data after every individual page. The `finally` completion deliberately emits the consolidated event after a partial failure as well, so derived indexes do not remain older than source content that was actually stored.

`confluence_import.import_execution_time_limit` is the upper bound for one
processing step. `queue()` stores the plan and state, while locked `process()`
calls handle a bounded attachment or page batch and atomically persist the new
offset. The final step reconciles references, the report, and derived indexes
exactly once. A repeated or concurrent call returns current state instead of
duplicating content. A fatal PHP termination marks the job as failed and keeps
enough metadata for administrator diagnostics.

Before `startImport()`, re-import preparation resolves the canonical source
mapping. `replace` permanently deletes the earlier imported Workspace through
the public Maintenance service, while `copy` suffixes source identities and
creates a separate Workspace. Before deletion, replacement captures the public
page slugs and native attachment UUIDs and applies them to the same logical
source records. Finalization rechecks resolved as well as unresolved imported
links, so replacement cannot leave another imported Workspace pointing at stale
targets. Page creation marks the logical Confluence
`homePage` as the Workspace homepage, with a current root-page fallback.

Cancellation operates only on an actor-owned locked transient job. The service
first verifies that content import has not started, deletes only a file inside
the managed private upload directory, and then removes the job row. This keeps
unfinished uploads from leaving archives behind without misrepresenting a
partially executed import as safely cancelled.

## Backup contract

The optional site/component Backup providers export only completed jobs and their durable rows, including the structured per-page review report. A separate `simbioza-confluence-import-workspace` provider follows the `workspace-scope` provider and transfers only the selected Workspace's provenance. Registered attachment bytes belong to Editor and travel through Editor's normal backup provider, avoiding duplicate blobs. A copy restore reconnects source identities and document references through shared import state. Temporary uploads and staging files are not backup data.

## Permanent Workspace cleanup

The module conditionally listens to Workspace's public permanent-deletion event.
Before Workspace removes its own rows, the listener deletes only the matching
completed/import job metadata, content/link/identity mappings and managed files
whose canonical paths remain inside the configured Confluence data directory.
Source ZIP uploads are already removed after successful import.

The module also listens to the batched `WorkspacePagesPermanentlyDeleting`
event. It is emitted before a never-published page or an old soft-deleted page
is permanently removed by Maintenance. The listener removes only the targeted
pages' Confluence rows and private files. Outgoing links from a removed page are
deleted, while remaining incoming links become unresolved so a later import can
reconcile them again. Ordinary soft deletion does not clean provenance because
the page can still be restored.

## Extending macro support

Add a converter for the macro's storage-format payload, create a safe static
fallback and add fixtures for malformed and complete data. Creating a live
Calendar/Task object requires an explicit adapter through the owning module's
public service. If XML omits events or a complete ACL, as with Team Calendars,
the durable report must require a conscious administrator choice of ICS/target,
type, and initial visibility. Never infer public access from a missing source
principal.

The `chart` adapter is a reference implementation for native Editor content:
it reads the macro table and presentation parameters, passes a normalized
definition to `EditorHtmlChartService`, and falls back to the source table when
the payload is incomplete. The imported chart then follows Editor API, Backup,
page export, and Workspace export behaviour without Confluence-specific code.

The `roadmap` adapter follows the same ownership rule. It decodes Roadmap
Planner JSON, normalizes it through `EditorHtmlRoadmapService`, and stores the
same canonical block created by the Editor UI. Rendering, API blocks, Backup,
page export, and Workspace export therefore remain Editor responsibilities.
The `widget` adapter is intentionally allowlist-based: add a provider only with
a bounded URL parser, safe output, malformed-input tests, and no copied remote
script. Unknown widgets must continue through the reportable fallback.

Before serialization, `ConfluenceHtmlConverter` normalizes every remaining
table to the HTML Editor contract: `table table-bordered table-striped
table-hover`, a `table-light` header, semantic table sections, and exactly one
`table-responsive` wrapper. Macro-specific converters therefore provide table
data and semantics, not competing presentation rules.

## Schema ownership

The reversible module migration is a template. Installation copies it into the host application's migration history. New columns and tables must remain prefixed through the module constants and have MySQL, PostgreSQL and SQLite coverage in the host matrix.
