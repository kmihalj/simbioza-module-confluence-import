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
- Full-site and Workspaces component backups include durable import mappings and all imported private files.
- A backup of one Workspace includes only that Workspace's Confluence provenance and private attachments; restoring as a copy regenerates conflicting UUIDs and rewrites attachment links.
- Comment imports comments whose authors and pages are mapped.
- Workspace Search rebuilds its derived index automatically after import.

All internal packages follow `dev-main`; this development module does not commit a `composer.lock`.

## What the importer does

- accepts large `.xml.zip` exports through resumable chunked upload;
- performs a read-only preflight before changes are allowed;
- proposes the Confluence space name and key as Workspace name and slug;
- imports current pages by default and recreates their tree;
- optionally imports history, drafts and soft-deleted pages;
- rewrites same-space links and reconciles cross-space links after later imports;
- stores all attachment types privately and serves them as downloads;
- maps existing users and groups explicitly without creating fake accounts;
- applies unresolved ACL identities fail-closed;
- maps a personal Confluence space to the confirmed owner's Personal Workspace;
- records unsupported macros and other decisions for administrator review;
- removes the uploaded source archive after a successful import.

## Quick start

```bash
composer require aaieduhr/simbioza-module-confluence-import:dev-main
vendor/bin/hph simbioza-confluence-import:install-migration
vendor/bin/hph orm-migrate up
```

Enable the package after all required modules, then open **Settings → Workspaces → Confluence import**. Upload one Confluence XML ZIP space export, review the preflight, confirm user/group mappings and start the import.

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
