# Simbioza Confluence Import documentation

Croatian index: [index_hr.md](index_hr.md)

Use this module when an administrator needs to migrate one Confluence XML space export into Simbioza. It is intentionally not a generic Confluence synchronisation client and it never imports directly from a live Confluence instance.

## Choose your guide

- **First installation:** [Installation and configuration](installation_en.md)
- **Site or multi-space export:** [Inventory and saved selection](site-inventory_en.md)
- **Site migration progress:** [C1–C6 implementation plan](site-import-plan_en.md)
- **Site users/groups/memberships:** [C2 review and import](site-identities_en.md)
- **Pending identities and calendar ownership:** [Read-only mapping proposals](site-mapping-review_en.md)
- **Selected site spaces, attachments and recovery:** [C3 content import](site-content_en.md)
- **Site calendars, permissions and page linking:** [C4 calendar import](site-calendars_en.md)
- **Combined reports, source cleanup and acceptance:** [Site reports](site-reports_en.md)
- **Running an import:** [Administrator guide](administrator-guide_en.md)
- **Reviewing users, groups and permissions:** [Identity mapping, ACL and security](mapping-security_en.md)
- **Understanding pages, macros, files and links:** [Content conversion and links](content-links_en.md)
- **Extending or embedding the module:** [Developer integration](developer-guide_en.md)
- **Quality checks and failures:** [Testing and troubleshooting](testing-troubleshooting_en.md)

## Safe default

The default import includes current pages and attachments. History, drafts and deleted pages stay disabled until an administrator explicitly selects them. An unresolved user or group never grants access.
