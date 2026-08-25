# Simbioza Module Confluence Import

Croatian version: [README_hr.md](README_hr.md)

Simbioza-specific module for safely importing a Confluence XML space backup into a Workspace. It preserves the page tree, current content, attachments, internal links and explicitly mapped access rules. Page history, drafts and deleted pages are optional and disabled by default.

## Dependencies

Required and loaded before this module:

- `aaieduhr/heartphrame-framework`
- `aaieduhr/heartphrame-module-orm`
- `aaieduhr/heartphrame-module-menu`
- `aaieduhr/heartphrame-module-auth`
- `aaieduhr/heartphrame-module-editor-html`
- `aaieduhr/heartphrame-module-workspace`
- `aaieduhr/simbioza-module-user`

Optional integrations:

- Audit records administrator decisions and import results.
- Full-site and Workspaces component backups include durable import mappings; Editor backup providers include the native page attachments.
- A backup of one Workspace includes only that Workspace's Confluence provenance while its real attachments travel with the Editor documents.
- Comment imports comments whose authors and pages are mapped.
- Workspace Search rebuilds its derived index automatically after import.

All internal packages follow `dev-main`; this development module does not commit a `composer.lock`.

## What the importer does

- accepts large `.xml.zip` exports through resumable chunked upload;
- performs a read-only preflight before changes are allowed;
- proposes the Confluence space name and key as Workspace name and slug;
- assigns the Confluence home page as the Workspace homepage, with the first
  current root page as a deterministic fallback when the export omits it;
- hides the page contents panel by default in newly imported Workspaces while
  keeping the page tree available;
- detects an already imported source and requires an explicit choice between
  replacing that Workspace and importing an isolated copy;
- imports current pages by default and recreates their tree;
- optionally imports history, drafts and soft-deleted pages;
- rewrites same-space links and reconciles cross-space links after later imports;
- registers every imported file as a real private Editor page attachment and serves it through current Workspace/page ACL; source attachment identity is isolated per import job so a later copy or replacement never reuses another document's UUID;
- prepares cached web-sized copies of imported JPEG, PNG, and WebP attachments
  after they are registered; originals remain unchanged and available on click;
- safely suggests exact existing user and group matches for mapping;
- lets an administrator explicitly create an inactive Auth staged account
  without a password or provider for an otherwise unmapped identity;
- reuses a previously confirmed account mapping across later space imports
  without changing that account, its groups, providers, or rights;
- preserves mapped Confluence creators and last modifiers as document/version
  authors while the importing administrator remains the operational actor;
- applies unresolved ACL identities fail-closed;
- maps a personal Confluence space to the confirmed owner's Personal Workspace;
- records unsupported macros and other decisions in a durable per-import report linked from **Recent Confluence imports**;
- allows an unfinished import to be cancelled, immediately deleting its uploaded archive and preparation data;
- removes the uploaded source archive after a successful import.
- processes a large confirmed import in bounded resumable batches and
  reconciles links, the report, and search index exactly once at the end.

The converter renders `children` and `pagetree` as local ACL-protected page
links, attachment lists and multimedia from native Editor attachments, responsive
Confluence layouts, status badges, anchors, code metadata, and local table-of-
contents links. Confluence `include` becomes Editor's native dynamic **Include
page content** reference, so every view receives current target content only
after its own ACL check. A valid Confluence `chart` becomes an editable native
Editor chart, while incomplete chart data keeps its source table and a report
notice. Roadmap Planner becomes Editor's editable native **Timeline**, preserving
its range, scale, lanes, colors, activity bars, markers, descriptions, and safe
links. A supported Confluence widget never carries provider scripts into
Simbioza: YouTube becomes a privacy-enhanced responsive video, while Figma and
Twitter/X become theme-aware external-link cards. Unsupported widget providers
remain visible in the import report. For a Confluence file-list page, the Confluence-only creation action is
omitted and `content-report-table` is materialized as ordinary editable HTML
containing links known at import time. No Workspace template or dynamic
component is created. Permanently deleting the
imported Workspace also removes its provenance, mapping, report, and managed
attachment provenance. Permanently purging one imported page removes only that
page's provenance and outgoing-link mappings; Editor owns removal of its native attachments;
incoming mappings become unresolved and the rest of the import remains intact.

Vertical and horizontal Confluence Page Properties tables and Page Properties
Report map to native Workspace properties and the dynamic page report. User
references in those properties render the mapped name. Gallery uses real
attachments, Live Search and Page Tree Search remain scoped to the current
Workspace, and Recently Updated uses ACL-safe published changes. Simple panels,
layouts, and enhanced tables remain ordinary responsive HTML rather than
dedicated Confluence-macro components. Every imported table is normalized to
the same bordered, striped, hoverable, responsive markup produced by the HTML
Editor, so the active theme controls its header, rows, borders, and colors.

## Quick start

```bash
composer require aaieduhr/simbioza-module-confluence-import:dev-main
vendor/bin/hph simbioza-confluence-import:install-migration
vendor/bin/hph orm-migrate up
```

Enable the package after all required modules, then open **Settings → Workspaces → Confluence import**. Upload one Confluence XML ZIP space export, review the preflight, confirm user/group mappings or explicitly select inactive staged-account creation, and start the import.

Read-only CLI inspection:

```bash
vendor/bin/hph simbioza-confluence-import:inspect /srv/import/team-space.xml.zip
```

## Documentation

- [Documentation index](docs/index_en.md)
- [Installation and configuration](docs/installation_en.md)
- [Administrator guide](docs/administrator-guide_en.md)
- [Identity mapping, ACL and security](docs/mapping-security_en.md)
- [Content conversion and links](docs/content-links_en.md)
- [Developer integration](docs/developer-guide_en.md)
- [Testing and troubleshooting](docs/testing-troubleshooting_en.md)

## Licence

This work is published under the European Union Public License (EUPL) v1.2.
