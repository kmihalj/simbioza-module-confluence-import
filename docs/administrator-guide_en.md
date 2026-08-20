# Administrator guide

Croatian version: [administrator-guide_hr.md](administrator-guide_hr.md)

## Export from Confluence

Create an XML export of exactly one Confluence space. Do not unpack or change the ZIP before upload. This importer does not use a Confluence administrator password and does not require access to the live source site.

## Import workflow

1. Open **Settings → Workspaces → Confluence import**.
2. Select the `.xml.zip` file and start the upload.
3. The browser uploads it in resumable chunks. Returning to the same browser session and selecting the same file continues from the server-confirmed offset.
4. The preflight reads metadata only and reports the space, content counts, users, groups, permissions, macros and attachment inventory.
5. Confirm the target Workspace name, slug and language.
6. Keep the default current-content selection or explicitly enable history, drafts or deleted pages.
7. Review every proposed user and group mapping. Unmapped entries remain blocked.
8. Start the confirmed import and leave the page open until completion.
9. Open the new Workspace and review the import warnings.

The uploaded source archive is deleted after success. A failed non-idempotent import cannot be retried in place: review the partial target, remove it when appropriate, upload the source again and start a new controlled import.

## Personal spaces

A personal Confluence space requires a confirmed owner mapping. The importer reuses or creates the owner's Simbioza Personal Workspace and applies private owner-oriented permissions. It never creates a local user merely because a Confluence identity exists.

## After import

- inspect the page tree and homepage;
- verify restricted pages with a non-administrator account;
- review unsupported-macro warnings;
- confirm attachments download but do not execute inline;
- import any referenced external space so unresolved cross-space links can reconcile;
- check the Audit log when the optional Audit module is enabled.
