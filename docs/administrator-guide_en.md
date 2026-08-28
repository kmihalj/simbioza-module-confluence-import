# Administrator guide

Croatian version: [administrator-guide_hr.md](administrator-guide_hr.md)

## Export from Confluence

Create an XML export of exactly one Confluence space. Do not unpack or change the ZIP before upload. This importer does not use a Confluence administrator password and does not require access to the live source site.

## Import workflow

1. Open **Settings → Workspaces → Confluence import**.
2. Select the `.xml.zip` file and start the upload.
3. The browser uploads it in resumable chunks. Returning to the same browser session and selecting the same file continues from the server-confirmed offset.
4. The preflight reads metadata only and reports the space, content counts, users, groups, permissions, macros and attachment inventory.
5. Confirm the target Workspace name, slug and language. If this Confluence
   source was imported before, also choose **Replace the existing Workspace**
   or **Import a separate copy**. Replacement permanently removes the earlier
   imported Workspace before any new page is written. A copy keeps the
   existing Workspace unchanged and uses isolated source and attachment
   identities.
6. Keep the default current-content selection or explicitly enable history, drafts or deleted pages.
7. Review every proposed user and group mapping. For an unmapped user, keep
   access blocked or explicitly create an inactive staged account without a
   password or provider. A bulk option is available for multiple unmapped
   users, and each row can still be adjusted afterwards.
8. Start the confirmed import. The page shows the phase, percentage, and
   processed attachment/page counts. You may close it; reopening the same job
   continues from the last confirmed step.
9. Under **Recent Confluence imports**, open the durable report. It links every page whose unsupported content requires manual review.
10. Open the new Workspace and verify the reported pages.

A verified upload and its mappings remain available after leaving the page.
Before the real import starts, **Cancel import** immediately deletes the uploaded
archive and preparation data. An unfinished upload can also be cancelled from
the recent-jobs list.

The uploaded source archive is deleted automatically after a successful import.
A failed import that already started changing content cannot be cancelled as a
simple upload or retried in place: review the partial target, remove it when
appropriate, upload the source again and start a new controlled import.

The final phase reconciles internal and external references, stores the report,
and refreshes the derived search index exactly once. Pages can become visible
during processing, but the final report and complete search are authoritative
only after the job reaches **Completed**.

Roadmap Planner content is imported as an editable native **Timeline**. YouTube
widgets become privacy-enhanced responsive videos; Figma and Twitter/X widgets
become safe external-link cards. Any unsupported widget or invalid roadmap is
listed in the completed job report instead of silently executing source code.

The Workspace homepage follows the Confluence space `homePage` metadata. If an
export does not contain that reference, the importer selects the first current
root page and finally the first current page. This prevents a completed space
import from opening as an unconfigured Workspace.

Newly imported regular Workspaces hide the page contents panel by default. The
page tree remains available, and an administrator may change either display
preference later in Workspace settings.

## Personal spaces

A personal Confluence space requires a confirmed owner mapping. The importer reuses or creates the owner's Simbioza Personal Workspace and applies private owner-oriented permissions. If the owner has no account yet, the administrator may explicitly create an inactive staged account, but must connect it to a real sign-in method and activate it in Auth settings before that person can access the Personal Workspace.

## Mapping suggestions

Automatic suggestions use only a previously confirmed mapping or an exact
match after ignoring case and redundant whitespace:

- user: e-mail and aliases first, then login identifier;
- group: technical key first, then group name.

A similar name or ambiguous match is never selected automatically. For
example, an existing `srce-zaposlenici` group is suggested for the identical
Confluence group, but not for `srce-zaposlenik`.

Auth supports display name, first name and last name. The importer copies those
fields when the Confluence export contains them. Some Confluence exports retain
only a technical login and e-mail; an inactive staged account then uses that
value as a visible fallback until an administrator or the user's future sign-in
provider supplies real profile names.

## After import

The content language selected during preflight determines which language
variant receives the imported Workspace name and description and the imported
page titles. Slugs remain shared by all languages. Re-import and existing-content
matching therefore never depend on a translated title; the UI shows the active
language translation or falls back to the primary language when needed.

- inspect the page tree and homepage;
- verify restricted pages with a non-administrator account;
- open the report icon in **Recent Confluence imports** and review every linked page;
- confirm the paperclip lists Confluence files as real page attachments;
- confirm attachments download but do not execute inline;
- confirm large imported images load their web-sized cache while selecting the
  image still opens the untouched original;
- import any referenced external space so unresolved cross-space links can reconcile;
- check the Audit log when the optional Audit module is enabled.
