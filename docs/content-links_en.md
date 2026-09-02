# Content conversion and links

Croatian version: [content-links_hr.md](content-links_hr.md)

## Pages and versions

The importer groups Confluence page objects by logical content ID. The latest published version becomes the current Simbioza document. The parent relation recreates the Workspace page tree. When selected, earlier published versions enter history, drafts remain drafts and deleted pages remain soft-deleted so an administrator can restore them.

The source space/page identifiers, version, status and conversion notes remain in module-owned tables. They are visible to importer administrators and do not add Confluence-only fields to ordinary Workspace forms.

A Confluence XML export can encode a CDATA terminator as `]] >` or `]] ]>` and
can use HTML entities outside XML's built-in entity set. The importer
normalises each complete `plain-text` body before DOM conversion. Code samples,
including a literal `<![CDATA[` string, therefore remain code instead of
turning the rest of the page into escaped Confluence markup.

## Links

The converter recognises modern `/spaces/SPACE/pages/ID/title`, legacy `/display/SPACE/title`, `viewpage.action?pageId=ID` and attachment URLs.

- Same-space links are replaced with the new Workspace route.
- Page fragments are retained.
- Links to an already imported space are resolved through source-ID mappings.
- Links to a space not imported yet use a stable resolver URL and keep the original destination.
- A later successful import runs reconciliation in both directions.
- External web links are preserved.

## Macros and tasks

Code, noformat, info, note, tip and warning structures have safe HTML representations. Confluence task lists become native interactive Task lists. Their imported completion value is the initial state until a Simbioza user changes it; rich content, HTTP(S) links, source nesting, and stable source anchors are retained. Unsupported or application-specific macros keep a visible safe fallback and create an administrator warning rather than silently disappearing.

Calendar and Task modules remain the owners of live calendars and tasks. Imported tasks use the Task module's normal ACL, CSRF protection, state storage, and audit trail. A different Confluence macro is not silently converted to a live business object unless its complete data and ACL can be mapped safely; otherwise the static representation remains in the imported page.

### Supported macro conversion

- `details` / Page Properties becomes the page's native structured property
  set. Both vertical key-value and horizontal heading-value layouts are
  supported. User references use the confirmed mapped name, and a hidden
  Confluence table is not duplicated in the document body.
- `detailssummary` and `contentbylabel` become the native **Page report**.
  The importer carries the CQL label, columns, first-column heading, ordering,
  and result limit. When property columns are requested, only pages that
  actually contain structured properties are included. The report updates
  dynamically after import and reapplies ACL every time.
- `gallery` becomes a native gallery of real Editor attachments on the current page.
- `livesearch` and `pagetreesearch` are not embedded in content. In Confluence
  they filter an adjacent tree or report, while Simbioza already provides a
  search that users can scope to a Workspace.
- `recently-updated` becomes an ACL-safe list of recent published changes.
- `panel` becomes a themed card. Legacy `section` and `column` macros become a
  responsive row of cards: percentage widths map to the Bootstrap grid, and
  every column occupies the full width on a narrow screen.
- `expand` becomes a static block with its source title above the body. The
  list type is not changed: `ul` remains bulleted and `ol` remains numbered.
- `html` containing one safe HTTPS iframe becomes a canonical 100%-wide Editor
  embed. It retains height, `allowfullscreen`, and restricted `allow`
  capabilities. The official H5P resizer is recognized and loaded in a
  controlled way in views and exports. Any other script is not executed and
  the entire macro enters the manual-review report.
  An HTML macro containing only a safe HTTP(S) button link becomes an ordinary
  theme-aware Simbioza button; source styles and JavaScript handlers are discarded.
- `profile` becomes a static rendering of the mapped Auth name. If an
  administrator created an inactive staged account, the importer uses a safe
  inferred name instead of the raw login identifier. It does not impersonate
  a Confluence profile or its authorization.
- `children` becomes local links to direct children; the Confluence `all=true` option includes descendants.
- `pagetree` becomes a hierarchical list of local pages from the configured root; `@self` means the current page.
- `attachments` becomes a read-only list of attachments that were actually imported.
- `multimedia` becomes a safe HTML audio/video preview when the attachment was imported.
- `view-file` becomes an ordinary link to the same local ACL-protected
  attachment when the physical file exists in the archive.
- `tableenhancer` retains its source table as ordinary responsive HTML because
  the add-on does not represent a separate business object. Its table, like
  every ordinary imported table, receives the HTML Editor's standard bordered,
  striped, hoverable classes and responsive wrapper, so it follows the active
  theme and wide content scrolls inside the page.
- `status` and `anchor` become a native badge and HTML anchor.
- `toc` is omitted from the imported document because Simbioza builds its native table of contents from the page headings.
- `include` becomes Editor's native dynamic **Include page content** reference. The importer resolves same-space forward references after all pages exist, reconnects already imported cross-space targets immediately, and reconciles older unresolved references when their space is imported later. Content remains dynamic and the target page's ACL is rechecked on every view.
- `chart` becomes Editor's native editable chart when the macro contains a
  usable table. The importer maps type, orientation, selected columns, title,
  axis labels, legend, and the Confluence 3D option. Invalid or incomplete
  source data keeps its table as a safe static fallback and creates a report
  notice.
- `roadmap` becomes Editor's native editable **Timeline**. The importer maps
  the date range, day/week/month/quarter scale, lanes and colors, activity
  bars, descriptions, markers, and safe HTTP(S) links. Invalid Roadmap Planner
  JSON keeps a visible fallback and creates a report notice.
- `widget` converts only providers with an explicit safe policy. YouTube uses
  a responsive `youtube-nocookie.com` iframe with restricted capabilities;
  Figma and Twitter/X become theme-aware external-link cards. Unknown providers
  keep a visible fallback and create a report notice. Provider scripts are
  never copied from Confluence.
- `create-from-template` is omitted because its Confluence editing action
  (including file-list and meeting-notes blueprints) is not applicable to an
  imported page.
- `content-report-table` becomes an ordinary editable HTML table containing
  the matching page links known at import time. It is not a template or a
  dynamic Workspace component. Source labels remain portable import metadata.
- `tasks-report-macro` becomes a native task report table. Its first column is
  the same interactive task stored on the imported source page, not a copied
  checkbox; changing it updates the source task and its audit trail. The report
  reapplies source-page ACL and completion-status filters on every view, while
  retaining the imported due date, mapped assignee, and local source link.
  Confluence's `pageSize` controlled only source pagination, so every matched
  row is retained. Reimport is needed when source definitions or report filters
  change, not merely when a task is checked or reopened in Simbioza.

If a published source page is a child of a draft or deleted intermediary that
was not selected for import, that intermediary is skipped and the page remains
under its nearest selected ancestor. Such children are therefore not promoted
incorrectly to the Workspace root.

Confluence two- and three-column layouts become responsive Bootstrap rows and
full-width columns. Empty source columns are removed before proportions are
calculated, while multiple meaningful columns retain their layout and stack on
narrow screens. Code/noformat blocks preserve their optional title and safe language
class, imported images retain numeric width/height hints, and rich link labels
remain readable. The native Simbioza table of contents continues to use the imported page headings.

Stored content uses standard Bootstrap classes and canonical
`editor-html-*` / `data-editor-html-*` markers only where live Editor behavior
requires them. The importer does not retain `confluence-import-*` classes as a
parallel document format; provenance stays in the module-owned tables and the
import report.

Other macros that create dynamic Confluence content or editing actions remain explicitly marked as unsupported; source data is never silently discarded.

## Comments and attachments

Comments are imported only when the Comment module exists, the target page
exists and the author has a confirmed user mapping. An attachment comment is
assigned to the page that owns the attachment; a historical comment that
cannot be associated with one page unambiguously is skipped and counted in the
final summary. In other cases, its source metadata remains available to the
importer administrator.

The importer selects the requested attachment version or the highest physical version present in the ZIP. It stages the verified binary only until the target document exists, then registers it with the same stable UUID as a native Editor attachment and removes the staging copy. The page body and paperclip therefore reference one ACL-protected asset. Missing or invalid files are reported without permitting traversal outside the archive.

Each completed job retains a report in **Recent Confluence imports**. Unsupported macros are grouped by target page, and every report row links directly to that Simbioza page. A report with no rows explicitly confirms that no manual content review was requested.
