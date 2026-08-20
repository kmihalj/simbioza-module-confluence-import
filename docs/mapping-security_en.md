# Identity mapping, ACL and security

Croatian version: [mapping-security_hr.md](mapping-security_hr.md)

## Users

Confluence user records are source identities, not authentication accounts. Preflight may suggest an existing Simbioza account by login, e-mail or a previously confirmed mapping, but an administrator must confirm the choice. The importer does not create local passwords or synthetic users.

For a SAML/OIDC/OAuth/CAS deployment, first let the real person obtain or link their account through the configured provider, then map the Confluence identity to that existing account.

## Groups

A source group can be mapped to an existing ordinary group or explicitly created as a new ordinary group. Confluence memberships and administrator status are not copied automatically; this prevents a stale export from escalating privileges.

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

When installed, Audit records administrator, source space, target Workspace and outcome. Technical exceptions use the application logger without placing secrets or file contents in the message. Backup contains completed durable mappings and private imported files, never temporary uploads or technical logs. A scoped Workspace backup exports only the selected Workspace's mappings and encrypted private attachment blobs; a copy restore regenerates UUIDs before links are exposed.
