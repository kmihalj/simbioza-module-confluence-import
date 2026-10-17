# Confluence site inventory

[Croatian version](site-inventory_hr.md)

The development version recognizes single-space, multi-space and full-site XML
ZIP exports. Single-space imports retain their existing workflow. Multi-space
and site exports support an inventory, a saved space selection, and an
[identity setup](site-identities_en.md) for one combined, reviewed import. Space selection itself does
**not** create accounts, groups, memberships, permissions, calendars or content.
The remaining implementation is tracked in the [site import plan](site-import-plan_en.md).

## Inspect and select spaces

1. Open **Settings → Workspaces → Confluence import** and upload the XML ZIP.
   The descriptor inside the archive determines its type, not its filename.
   An export marked `site` remains a site even when it contains only one space.
2. Wait for the read-only inventory. For a site or multi-space export, the screen
   shows spaces, current page counts, directory accounts, author references,
   group and membership record counts, actual attachment files and plugin-data
   presence. Plugin data is not converted during this step.
3. Search the space table and select individual spaces or **Select visible spaces**.
   Tables show 25, 50 or 100 rows per page, with result/selection counts and compact
   `« ‹ 1 2 … N › »` navigation above and below. Search filters the whole catalog,
   not just the current page. **Select visible spaces** affects only the current
   page; **Select all filtered spaces** includes matches on every page.
   Paging and filtering retain selections and do not submit or start an import.
   **Clear selection** removes all selections, including hidden rows.
4. Choose **Save space selection**. Reopening the job restores the selection.
   Saving only updates the import job; it does not create a Workspace or grant access.
5. **Cancel import** removes the temporary upload, its index and the preparation job.
   It does not delete the original archive on your computer. Already applied C2
   identities and their durable report remain; cancellation is not an undo.

Directory accounts and Confluence author identity references are different record
types and must not be added together as new users. Directory-group rows can have
the same name in different directories. Reconciliation, mapping to existing
accounts and reviewed membership migration are covered in the C2 guide. An XML attachment record is
not an attachment binary: an export without binaries can still list attachments.

## CLI inspection

From the application directory:

```bash
vendor/bin/hph simbioza-confluence-import:inspect /srv/import/confluence-site.xml.zip
```

This prints the inventory without creating users, groups, calendars or content.
It saves a private cache, but does not create a browser import job. Output contains
space names and source identifiers, so treat it as private migration metadata.
It contains no document bodies, password hashes, bearer tokens or plugin settings.

## Storage and limits

Policy overrides belong in
`HPH_CONFIG_PATH/confluence-import/confluence-import.php`. The module uses package
defaults when a key is omitted. Uploads and indexes are data, not configuration:

- Browser uploads: `<application>/data/confluence-import/uploads/`.
- Browser index: `<upload-id>.zip.inventory.json` beside its managed ZIP.
- CLI index: `<application>/data/confluence-import/inventory/<source-sha256>.json`.

These paths use the configured `data_path` when it differs from `confluence-import`.
Browser indexes share their upload's cancellation/expiry cleanup. CLI indexes are
rebuildable and can be removed when no inspection is running. They are not durable
backup content. Completed indexes are written atomically with mode `0660` and
verified against a fresh SHA-256 of the ZIP before reuse.

The site reader streams `entities.xml` directly from the ZIP in bounded chunks.
It validates the XML envelope and selected metadata objects, counts other object
types without deserializing their payloads, and discards bodies and credentials.
It does not extract the full XML or parse Active Objects tables during C1.
DTD and external-entity declarations are not accepted. ZIP traversal, duplicate
paths, symlinks, entry counts and compression-ratio protections still apply.

| Key | Default | Applies to |
| --- | ---: | --- |
| `max_site_entry_size` | 64 GiB | One uncompressed site/multi-space ZIP entry |
| `max_site_uncompressed_size` | 128 GiB | All uncompressed site/multi-space entries |
| `max_inventory_object_size` | 16 MiB | One retained metadata object |
| `max_inventory_spaces` | 10,000 | Spaces and space references retained in the inventory |

The existing upload limit of 8 GiB and ZIP-bomb checks are unchanged. Existing
single-space limits remain 4 GiB per entry and 16 GiB total. See
[installation and configuration](installation_en.md) for all policy keys.

## Interruptions and scope

Uploads remain resumable. The **initial inventory is one streaming pass**: if it
is interrupted, no partial index is accepted and a fresh preparation starts from
the beginning. Cancel the failed/stuck preparation and upload again; do not assume
that content-import resume also resumes the initial inventory. A completed CLI
inventory can reuse its verified cache. Align PHP/FPM and proxy timeouts with
`import_execution_time_limit`; larger archives may need a longer timeout.

The single-space entry points reject a site inventory, even with only one space:
a site must never silently be imported as its first space. Selected content uses
the separate [C3 review/confirmation](site-content_en.md). Initial inventory does
not need new migrations or alter Auth configuration or sign-in providers.

## Verified source snapshots

The supplied full-site export was scanned without extracting its roughly
28.6 GB `entities.xml`: 5,813,804 objects, 352 spaces (206 global, 146 personal),
44,594 current pages, 369 directory accounts, 20 group rows and 784 memberships.
It has no attachment files. Calendar records in its plugin package use the separate
[C4 import](site-calendars_en.md); inventory alone does not import them. The supplied DABAR single-space
export still follows the existing scanner: 253 current pages, 393 attachment
records and 332 logical current attachments.
