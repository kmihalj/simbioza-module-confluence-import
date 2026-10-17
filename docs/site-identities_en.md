# Site users, groups and memberships

[Croatian version](site-identities_hr.md)

Configure identities alongside the [site inventory](site-inventory_en.md),
[content](site-content_en.md) and [calendars](site-calendars_en.md).
All sections are preparation: one final confirmation executes the selected site
import in dependency order. Single-space imports, Auth code and framework remain unchanged.

## Account activity, directory activity and historical authors

The export is a snapshot, not a live directory query. **Account** and **Directory**
activity are separate facts from `InternalUser.active` and `DirectoryImpl.active`.
An active account in a disabled directory is not an active source identity. Missing
or unrecognized flags are **Unknown**, never inferred from a directory name, ID,
connector implementation or email format. Historical `ConfluenceUserImpl` entries
without an unambiguous directory account are labeled as author references only.
The user filters include inactive sources, historical references and unknown activity.

Automatic creation skips known inactive accounts/directories and historical references
by default. To deliberately retain archival accounts, enable both automatic creation
and **Also include inactive source accounts, disabled directories and historical
author references in automatic creation**. New accounts still have no password,
login provider or administrator permission. Existing confirmed mappings and explicit
per-row choices are retained. A unique match to an existing target can still preserve
the historical identity; a shared email or matching name never merges two sources.
The duplicate review prefers an enabled source over a disabled directory, but never
changes approved primary/alias choices. Unknown activity does not by itself block
creation of an inactive target; inspect it before confirming the import.

Skipping a source account does **not** map it, its memberships or permissions to the
administrator. Unmapped page authors use the importing actor while original creator
and modifier keys remain in retained source metadata. Selected inactive personal
spaces may instead use explicit [administrator custody](site-content_en.md#inactive-owners-and-administrator-custody).
Calendars still require an explicit owner; neither creator nor administrator is an
implicit fallback. The identity report records skipped archival sources and reasons.

## Review and apply

1. Upload a site/multi-space XML ZIP in **Settings → Workspaces → Confluence import**.
   Selecting spaces does not start an import.
2. Under **Site users, groups and memberships**, choose **Prepare identity catalog**.
   A large archive can take minutes. This only reads metadata. An interrupted
   initial scan restarts; only a completed private index is reused.
3. Enter the original site's base URL, such as `https://wiki.example.org/confluence`.
   Use the same URL for subsequent imports. Host case, default ports and trailing
   slashes are normalized; credentials, queries and fragments are rejected.
   The URL identifies a mapping namespace; the importer never fetches it.
4. **Automatically match by a unique username or email** proposes existing
   accounts, including inactive ones. Exact case-insensitive login matches and
   email/alias matches must identify the same single target. Display names are
   not identity evidence. Conflicting or ambiguous matches require manual review.
   Each row and the review/report show whether the choice came from username,
   email, both, a previous confirmed mapping or a manual choice. These are
   proposed choices, not confirmed writes until the final site confirmation.
   You can manually skip, select an existing account or create an inactive one.
   **Use automatic rules** removes that row's manual override. Skip never means
   the importing administrator.
   Optionally enable **Automatically create inactive users for all unmapped users**.
   This plans creation across the entire source catalog, independently of the
   current page/search/status filter. Existing/manual choices and explicit manual
   skips take precedence. Duplicate source/target logins/emails and invalid new
   account fields are reported for manual review, never silently duplicated.
   Disabling the option removes automatically planned creation, not manual choices.
5. **Automatically map groups by an unambiguous name** proposes exact,
   case-insensitive target group names across the entire catalog. Optionally enable
   **Automatically create all groups that do not exist**. Creation is only planned
   until the final site confirmation; the preview/report shows automatic matches/creation and conflicts.
   Both policies are independent of user policies. Manual skips/maps take precedence;
   **Use automatic rules** removes a group override. Turning creation off removes
   automatic creation without discarding manual choices.
   Independently skip/create/map source groups. Only enabled ordinary target groups
   are offered, never the system Administrator group. Same-name source groups in
   different directories remain distinct; new groups receive a `[directory ID]`
   suffix when necessary; the qualified name is used for automatic matching too.
   Duplicate targets and names reserved by disabled/system groups require manual
   review, not duplicate creation. The exact new name is shown before applying.
6. Optionally select **Add selected users to selected groups**. Both endpoints
   must be selected. Unresolved memberships and nested groups are counted, not
   silently flattened or inferred from content permissions.
7. Users are sorted alphabetically and shown in groups of 25, 50 (default) or 100.
   Navigation above and below the table includes first/previous/next/last controls,
   compact `« ‹ … › »` controls, the active page and the result range. Like the
   Auth user list, at most two neighboring pages on each side plus the first/last
   page are shown, with `…` gaps. Controls wrap inside the card on narrow screens;
   thousands of users do not produce hundreds of buttons. Searching or changing
   page size returns to the first result page. Changed choices automatically
   save to the review before navigating to another page or search. This creates
   no users, groups or memberships. Failed saves keep the current page and
   choices available for correction/retry. To review without navigating, choose
   **Save user and group settings**.
   The mapping picker displays the selected user's name and login. Reopening or
   searching marks the selected result with a highlight/check mark; keyboard and
   mouse selections invalidate the previous combined review.
   **Mapping status** filters all, mapped, unmapped, planned creation, automatic
   matches or manual-review conflicts. Counts reflect the current text search;
   filtering happens before pagination and preserves choices on hidden pages.
   Review the total counts and complete source/action/target decision table.
   Saving a review changes only the preparation job, not accounts or memberships.
8. Configure spaces and calendars, then choose **Review the entire import** and
   **Confirm and start the entire import**. No section has an independent Apply
   action. Identity creation/mapping and memberships run before calendars and
   content, so selected users already exist when authorship and ACLs are assigned.
   The combined review expires after 30 minutes. Edited selections, changed
   source/target directories and stale targets require a new review. Repeating
   the same confirmation returns the existing run without duplicate writes.
9. The durable report is available under **Recent Confluence imports**. It lists
   created/mapped identities, added/existing/excluded memberships, unresolved cases
   and source permission counts retained for C3.

If a draft becomes invalid, correct it and review again, or **Reset unapplied
choices**. Confirmed mappings/accounts are not deleted. An existing login/email
or group-name collision requires explicit mapping; alternatively first create/edit
the intended target in Auth settings. Existing profiles are never overwritten.

## Duplicate source accounts

Use the [read-only proposal review](site-mapping-review_en.md) to inspect grouped
duplicates, original directories/active status, shared identifiers, weaker name-only
matches and service-account warnings before choosing the primary account.

A person may have an old short login and a newer email-shaped login in different
Confluence directories. A shared email is a review hint, not automatic permission
to merge accounts: a service/admin account may use another person's address.

1. Decide which source account is the primary one. Map it to an existing Simbioza
   user, or choose **Create inactive user without sign-in**, then save the settings.
2. For another source account belonging to the same person, open its target picker,
   search for the primary account and choose **Same user as source account: …**.
   This can refer to an already mapped primary or to a new account not yet created.
   Results remain paginated: at most 20 existing users plus five source choices.
3. Save and inspect the complete source/action/target preview. For example,
   `old-login → current-login → new inactive account` creates just one account,
   while preserving both source references, authorship and memberships. No real
   user is created before the final combined confirmation.

The importer resolves chains independently of source order. Missing/skipped
primaries, self-links and cycles are rejected before writes. A confirmed mapping
cannot be redirected to another target through a source link. This is source
reconciliation, not merging/deleting two existing Auth accounts; their attributes,
credentials, profiles and sign-in policies remain unchanged. To keep distinct
people/service accounts separate when they share an email, resolve their intended
target accounts explicitly rather than joining them automatically.

## Account and access policy

New users are inactive, non-administrative, have no password and have all sign-in
providers disabled. Profile fields pass through existing Auth account services.
Mapped users retain credentials, active/admin flags, providers and attributes.
A source name such as `confluence-administrators` cannot promote anyone.

New groups are ordinary enabled Auth groups. Membership is additive: unrelated
rows survive, automatic assignments do not become manual, and explicit exclusions
prevent restored access. The importer uses existing account/group services; its
membership adapter adds only missing relation rows and emits standard
`AuthUserGroupsChanged` events after commit. Auth itself is not modified.
Any failure rolls back that attempt's accounts, groups, memberships, mappings and
report. Prepared choices remain for another review.

Directory accounts and author keys merge only when their name relationship is
unambiguous. Orphan/ambiguous author references remain separate choices. Confirmed
mappings use source URL plus source IDs, not globally shared group names.

## Storage and backup

Configure policy in `HPH_CONFIG_PATH/confluence-import/confluence-import.php`:

| Key | Default | Meaning |
| --- | ---: | --- |
| `max_site_identity_records` | 100,000 | Retained identity, directory, membership and permission objects |
| `max_site_identity_index_size` | 32 MiB | Maximum complete identity index |
| `max_inventory_object_size` | 16 MiB | Maximum retained XML metadata object |
| `import_execution_time_limit` | 900 seconds | PHP scan window; align FPM/proxy limits separately |

The temporary `<managed-upload>.zip.principals.json` is private data under
`data/confluence-import/uploads/` (or configured `data_path`). Only allowlisted
identity/access metadata is stored, never password hashes, tokens, SSO secrets or
page bodies. The first scan verifies SHA-256; cached reads require the same hash
and unchanged file identity/stat. A normal GET never scans the ZIP.

Cancellation/expiry remove the temporary catalog and upload, not already applied
C2 accounts or mappings. Applied changes have a separate completed `site_access`
report. Site/component backups include its mappings even while content preparation
is unfinished. Temporary parent jobs, ZIPs and indexes are excluded. Target
user/group/job IDs use existing backup foreign-key remapping; report JSON stores
portable labels rather than unmapped local target IDs.

## Sign-in and administration remain in Auth

The importer does not convert Confluence password hashes, provider configuration,
tokens, sessions or administrator privileges. This is the agreed importer boundary,
not unfinished C2 work. `confluence-administrators` remains an ordinary group.
Configure activation, local/SSO sign-in and administrator rights in existing
user/global Auth settings. C3 consumes confirmed mappings for owners/content ACLs.
