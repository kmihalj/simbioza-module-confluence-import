# Identity mapping, ACL and security

Croatian version: [mapping-security_hr.md](mapping-security_hr.md)

## Users

Confluence user records are source identities, not authentication accounts. Preflight may suggest an existing Simbioza account by login, e-mail or a previously confirmed mapping, but an administrator must confirm the choice. Matching is strict and an ambiguous result remains unmapped.

A confirmed source-account mapping is global to this importer and is reused when the same Confluence account appears in another space archive. A later import cannot clear it or redirect it to a different account. Reuse does not modify the target account, authentication providers, activation state, group memberships, administrator flag, or permissions from earlier Workspaces.

For an identity without a target account, the administrator may keep access blocked or explicitly create an inactive Auth staged account. That record has no password, no allowed provider, and cannot sign in. Auth remains the sole owner of later activation: an active account needs a provider, an active local-only account needs an administrator-set temporary password, and that password must be changed at the first local sign-in. An external-provider user may receive local access without a local password and then set one privately.

For a SAML/OIDC/OAuth/CAS deployment, first let the real person obtain or link their account through the configured provider, then map the Confluence identity to that existing account.

## Groups

A source group can be mapped to an existing ordinary group or explicitly created as a new ordinary group. An exact existing group key or name is suggested automatically; similar and ambiguous names are not. The supported single-space XML exports contain group references in permissions, but the tested archives do not contain a reliable user-to-group membership list. Membership is therefore never inferred from ACL entries, and Confluence memberships and administrator status are not copied. A future full-site/API importer may add only explicit, additive memberships through Auth's public contract.

The import itself runs under the importing administrator so creation and workflow checks remain authorized. After every successful write, Editor records the mapped Confluence creator and last modifier on the document and its exact version. Unmapped authors fall back to the importing administrator. This attribution never changes document ownership or ACL.

## Permission rule

Permissions use a fail-closed rule:

- mapped principals receive only the imported right;
- unresolved user/group restrictions remain restrictive;
- view restrictions never turn a private page public;
- edit-only restrictions keep inherited view access while narrowing edit access;
- an administrator should re-check representative restricted pages after import.

## Attachments

All MIME types may be retained because a private knowledge system can legitimately archive source code, executables or uncommon formats. Files live outside public assets, access is checked against the mapped page on every request and the response forces download. A filename or MIME value from the source never makes a file executable.

Archive inspection rejects path traversal, excessive entry count, excessive expanded size, excessive individual entries and suspicious compression ratios.

## Logs and backups

When installed, Audit records administrator, source space, target Workspace and outcome. Technical exceptions use the application logger without placing secrets or file contents in the message. The completed job stores a structured page-review report. Backup contains durable mappings and that report, never temporary uploads or technical logs. Native imported attachments are protected and backed up by Editor; Confluence Import retains provenance only and therefore does not duplicate their blobs.
