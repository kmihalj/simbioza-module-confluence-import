# Deferred full-site import development

Full Confluence site migration is deferred as of 17 October 2026. This work is
preserved on `confluence-site`, not approved for a release or merged into `main`.
The supported release path remains the existing single-space importer.

## Preserved work

- Site inventory, selected spaces, shared archives and attachments.
- User/group mappings, duplicate identities and inactive-directory/account policy.
- Personal/team calendars, explicit owners, colors and subscription handling.
- One reviewed workflow, progress, separate reports and cross-space resolution.
- Add-only personal-space imports, calendar macros, tests and separate EN/HR guides.

Related snapshots use the same branch name in Simbioza, Calendar and Simbioza User.
Auth and Framework are not part of this development snapshot. Local HFC drafts,
mapping evidence and source archives are private runtime data, not Git fixtures.
Test fixtures committed here are synthetic.

## Before resuming

1. Read `site-import-plan_en.md`; completed implementation is not acceptance of
   source-specific identity and ownership proposals.
2. Review current account/directory activity and explicit archival-account choices.
3. Refresh calendar owner projections from current identity choices. A saved
   nonempty owner reference is not sufficient when its account will be skipped.
4. Obtain human confirmation for ambiguous identities, calendar types and owners.
   Editing permission or the page embedding a calendar does not prove ownership.
5. Recreate a disposable HFC test draft, then repeat single-space regression,
   full-site GUI, attachment, backup/restore and deployment checks.
6. Reconcile candidate dependency constraints with actual published versions before
   any future release; the candidate versions in this branch are not releases.

No automatic source-specific rules, production changes or new release are implied
by preserving this branch. Existing single-space imports remain on `main`.
