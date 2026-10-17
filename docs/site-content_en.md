# Import selected Confluence site spaces

[Croatian version](site-content_hr.md)

This unreleased HFC development feature extends the existing single-space importer.
It does not change that workflow. [Calendars](site-calendars_en.md) are configured
separately but execute as part of the same final site confirmation.

## Review and start

1. Upload a site or multi-space XML ZIP and wait for its [inventory](site-inventory_en.md).
2. Select the required spaces and choose **Save space selection**. Selection alone
   creates no content. You do not need to select every space in a site export.
3. Prepare [identities](site-identities_en.md) and configure the desired users,
   groups and memberships. Saving settings does not create them. The combined
   preview can validate a newly planned inactive user without inventing a numeric ID.
   Execution applies identities before importing any content or calendars.
   Unmapped ACL subjects remain fail-closed; unselected authors use the importing actor.
4. Enter the source Confluence base URL once in the identity section, including
   its context path if any. All sections use it for the same source namespace.
   Changing it creates a different mapping namespace.
   Earlier independent single-space imports keep their original targets and
   mappings. References may use them only when their saved source base URL
   matches exactly after normalization. A matching name or numeric ID alone is
   insufficient; unknown origins and other Confluence sites remain isolated.
5. Check each target name, slug and action. **New Workspace** is for an unmapped
   source; an already mapped source requires **Replace previously imported
   Workspace** or **New copy**. Replacement permanently deletes that mapped
   target, including local edits, before rebuilding it. It cannot target an
   unrelated Workspace. Copies receive isolated mappings and link to sibling
   copies when those spaces are copied together.
6. Choose attachments/comments as needed (both default on). History, drafts and
   deleted pages default off. History controls page and attachment history.
   Personal spaces require a selected owner (existing or planned inactive user).
   Multiple personal source spaces may belong to the same target user. For every
   such source, and for an existing Personal Workspace, select **Add only new pages;
   existing pages stay unchanged**. The grouped-owner preview offers a button to
   set this action for all displayed sources belonging to that owner.
   Personal spaces cannot be copied.
7. **Save content settings** stores configuration only. Configure calendars as
   needed, then **Review the entire import** and **Confirm and start the entire
   import**. The owner-bound review is valid for 30 minutes and validates every
   selected section before business writes. Changes require another combined review.

## Inactive owners and administrator custody

Normally a personal source requires a mapped owner, including a deliberately
created inactive archival user. If you instead skip a known inactive owner, choose
**Separate archival Workspace managed by the administrator** in that space's
settings. This is an explicit per-space choice, never the default. Evidence must
come from exported account/directory inactivity or a historical-only author entry;
an unknown or active owner cannot silently be reassigned.

Custody creates a separate restricted regular Workspace for each selected source.
It never adds all former users' pages to the administrator's Personal Workspace,
creates an identity mapping to the administrator, or transfers memberships through
such a fallback. Source name/key, original owner and author metadata remain in the
import/report; unmapped creators and modifiers use the importing actor in Simbioza.
Mapped authors keep their mapped attribution. Other explicitly mapped source ACLs
still follow normal fail-closed permission conversion.

Use **New Workspace** (or **New copy** for an already imported source). Custody
cannot append to a Personal Workspace, replace an existing target, or override a
selected/confirmed owner. Existing content remains untouched. The combined preview
validates these boundaries before any writes. Personal calendars are not reassigned
by this content option: select a valid calendar owner separately or leave them out.

## Add to an existing Personal Workspace

The selected-space settings have independent search and 25/50/100-row pagination
above and below the table. Search includes the source name/key and edited target
name/slug. Paging/filtering retain unsaved target names, slugs and actions from
every page. **Save content settings** saves the whole selected draft, not just the
visible page. If a required name/slug is empty on another page, validation reveals
that row before submission; it does not silently drop it.

If old/new source logins belong to one person, first reconcile them in the
[identity review](site-identities_en.md#duplicate-source-accounts). Their selected
personal spaces then appear under **Multiple personal spaces belonging to one
owner**. Choose **Set add-only for these personal spaces**, save and review the
combined import. A new owner gets one Personal Workspace; later source spaces add
to that same target. Each source keeps its own source mapping and detailed report,
so links between both original space keys resolve to the shared workspace.

This requires add-only for every member of the group, never replacement/copy.
Execution binds a later source only to the verified owner's completed earlier
child in the same workflow. An unrelated/concurrently changed target is rejected.
Repeating the same sources retains prior pages, document IDs and attachment URLs;
new source pages use available slugs, not title-based overwrites.

The add-only action uses the mapped owner's native Personal Workspace; it never
replaces that workspace. Its name, slug, homepage, menus, themes, permissions and
existing page content remain unchanged. The source space ACL does not overwrite
the existing workspace ACL, so its existing access boundary also applies to new pages.

Previously imported pages are recognized by their verified Confluence source
identity, not by title. Their HTML, versions, metadata, comments and attachments
are not updated, even if the new export has changed them. Only genuinely new
source pages and their selected attachments/comments are imported. New children
can use an already imported parent. A native page with the same title is preserved;
the new source page receives a free slug. Repeating the same export adds nothing.
Deleted personal workspaces and inconsistent source mappings require review rather
than silent restoration or replacement. The report counts preserved source pages
separately from newly imported pages; unrelated native pages are not counted as imports.

The confirmation fingerprints the target. An add-only failure never enters the
whole-workspace replacement/retry path. Inspect only the new objects owned by
that attempt before a fresh import; never delete the pre-existing personal workspace
or unrelated pages as cleanup. The ordinary single-space import remains unchanged.

## Attachments, content and reports

The shared index retains only the selected spaces and requested content versions.
The normal conversion engine imports each space's tree, pages, history, comments,
ACL, macros, external-script review and native private Editor attachments. Binary
paths keep the original Confluence page/attachment/version IDs; the source ZIP is
not rewritten. Safe page and attachment links use the existing converter/resolver.
Cross-space page links resolve in the same source namespace, including forward
references resolved after a later space finishes, including earlier imports of
the same site. Their existing reports show the new link state. Links to spaces not yet
imported remain in the normal unresolved-link report. The same verified-source
rule supports links in both directions between standalone and site imports,
without rewriting their mapping keys. Existing per-space reports show later
resolutions. Copies keep their isolated scope and never fall back to originals.
This reference bridge does not silently adopt a standalone target for replacement.

An export without attachment binaries may still contain attachment XML records.
The content import continues with a clear source warning and does not pretend
those records are transferred files. When binaries are present, they follow the
ordinary attachment/history and per-page ACL rules. A partially populated archive
reports genuine missing current files through the existing per-space report.

One parent report links the usual report of every child space, including attachment,
link, macro and script decisions. External scripts do not bypass the Editor registry.
Identity migration does not grant global administrator privileges or change sign-in
providers; `confluence-administrators` remains an ordinary group.

## Progress and recovery

The first content step builds a private index with two shared streaming passes over
`entities.xml`: ownership metadata, then selected content. It does **not** extract
the full XML or write every historical body to disk by default. Large source files
can still take several minutes; align PHP/FPM and proxy timeouts with
`import_execution_time_limit` and allow `import_memory_limit_mb` (default 512 MiB).
A locked lower FPM memory limit is reported before indexing. CLI `inspect` remains
read-only inventory, not a new command for launching content imports.

After indexing, each selected space has its own resumable child job. The browser
shows completed/total spaces and the current child's progress. **Resume the entire import**
resumes the parent after refresh or a lost request. Concurrent parent processing
is locked. A complete source/policy-bound index is reusable; interrupted initial
indexing restarts rather than accepting an incomplete cache.

An explicitly failed combined job stops and retains the completed reports and
partial content; it is never presented as a successful import. It does not patch
partially imported records or silently retry a failed component. During development
acceptance remove only that attempt's imported objects, correct the procedure and
start a fresh import. Cancellation is unavailable after the final confirmation.

The parent owns and retains the shared ZIP and private `*.zip.content/` partitions
until the combined workflow and reference linking finish. A completed or purged
child never deletes that shared source or its siblings. Do not manually remove it
while execution still needs it.
Temporary indexes, executable job-review tokens and local add-only workspace/parent
node IDs are not portable backup data;
durable mappings and completed reports remain included in existing backup providers.

Selecting history for many spaces can still require substantial private disk space:
those requested historical bodies are genuine migration input, not a disposable
all-site intermediate spool. Check available space before enabling that option.

## Verification boundary

Tests cover multiple spaces, included/omitted binaries, history, cross-space links,
copies, explicit retry, ownership and unchanged single-space behavior. The supplied
real full-site archive has **no binaries**; future site exports with binaries are
verified with isolated fixtures, not falsely described as tested on that snapshot.
Calendar migration uses C4; final cross-database/full-site acceptance remains C5.
