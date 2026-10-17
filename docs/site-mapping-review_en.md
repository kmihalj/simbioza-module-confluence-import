# Review duplicate accounts and calendar proposals

[Croatian version](site-mapping-review_hr.md)

After preparing identities and calendars, save any changed draft settings and open
**Review duplicate account and calendar proposals** in either section. This is a
private, owner-only, read-only page. Opening, searching or paging it does not save
choices, merge accounts, grant access, subscribe to calendars or start an import.
It uses the prepared source catalogs and current saved draft, not a new ZIP scan.

## Accounts

Each group shows source names, logins, emails, directories, original active status,
existing target labels, shared evidence and a proposed primary where appropriate.
The primary preference is an existing mapped target, then an account in an enabled
directory, then unknown activity, and finally a disabled/reference-only source;
a short login breaks ties only. Account and directory flags are shown separately.
Directory names and installation-specific project names are not rules. This is
only a suggestion; source active status never enables
sign-in in Simbioza. New imported users still follow the inactive-account policy.

- Matching full name and identifier: confirm the same person before linking sources.
- Only the full name matches: inspect different emails/directories and confirm
  identity individually. Equal names or accent folding are not identity proof.
- Shared identifier with different names: check for service accounts, shared email
  and changed names. Do not join all accounts merely because they form one group.

Name-only candidates are included even if automatic user creation found no login/
email collision. Sources already resolved to one target are omitted from pending
groups. **Edit choice** opens the ordinary identity editor filtered to that login.
Choose the primary and explicitly link aliases as described in the
[identity guide](site-identities_en.md#duplicate-source-accounts). Save and reopen
the proposal page to see remaining groups. Two existing Auth accounts are not merged.

## Calendars

The second table includes calendars without a type or personal calendars without
an accepted owner. It separates **Saved type** from **Proposed type and reason**;
suggestions never replace the saved type. Both lists have separate search and
25/50/100-row pagination with compact numbered navigation above and below.

Shared room/vehicle/resource or project/team names propose **Team**, even when
embedded on personal pages. A general-space association is marked as weaker
evidence. Full personal names/logins and official/private labels propose
**Personal**, but require a confirmed owner. Unknown mixed contexts remain
**Decision required**. These are review heuristics, not authoritative source types.

Owner candidates show exact-name/login evidence or explicitly weaker personal-space
hints. Matching a given name and a linked source login can help review a different
surname; it is not automatically accepted. An embedding hint contradicting an
explicit personal calendar name is suppressed rather than recommending the wrong
page owner. The calendar creator is never ownership proof. Missing reliable owners
remain unresolved: choose an existing/planned account manually or skip for now.

**Edit choice** opens the exact calendar by its source ID in the normal editor.
Confirm identity relations first, then choose the correct type and owner and save
calendar settings. Team proposals need no personal owner, but their access rules
still require review. Only the final combined review and confirmation execute the
selected import; this proposal page has no import or approval button.
