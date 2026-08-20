# Content conversion and links

Croatian version: [content-links_hr.md](content-links_hr.md)

## Pages and versions

The importer groups Confluence page objects by logical content ID. The latest published version becomes the current Simbioza document. The parent relation recreates the Workspace page tree. When selected, earlier published versions enter history, drafts remain drafts and deleted pages remain soft-deleted so an administrator can restore them.

The source space/page identifiers, version, status and conversion notes remain in module-owned tables. They are visible to importer administrators and do not add Confluence-only fields to ordinary Workspace forms.

## Links

The converter recognises modern `/spaces/SPACE/pages/ID/title`, legacy `/display/SPACE/title`, `viewpage.action?pageId=ID` and attachment URLs.

- Same-space links are replaced with the new Workspace route.
- Page fragments are retained.
- Links to an already imported space are resolved through source-ID mappings.
- Links to a space not imported yet use a stable resolver URL and keep the original destination.
- A later successful import runs reconciliation in both directions.
- External web links are preserved.

## Macros and tasks

Code, noformat, info, note, tip, warning and table-of-contents structures have safe HTML representations. Confluence task lists become read-only task-list markup in the document. Unsupported or application-specific macros keep a visible safe fallback and create an administrator warning rather than silently disappearing.

Calendar and Task modules remain the owners of live calendars and tasks. A Confluence macro is not silently converted to a live business object unless its complete data and ACL can be mapped safely; otherwise the static representation remains in the imported page.

## Comments and attachments

Comments are imported only when the Comment module exists, the target page exists and the author has a confirmed user mapping. Otherwise their source metadata remains available to the importer administrator.

The importer selects the requested attachment version or the highest physical version present in the ZIP. Missing or invalid files are reported without permitting traversal outside the archive.
