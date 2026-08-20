# Developer integration

Croatian version: [developer-guide_hr.md](developer-guide_hr.md)

## Architecture

The module owns only Confluence-specific import state:

- jobs and resumable-upload metadata;
- source-space and source-content mappings;
- confirmed user/group mappings;
- unresolved/resolved cross-space link mappings;
- metadata for privately retained imported attachments.

Workspace, HTML Editor, Auth, Menu and Simbioza User remain owners of their records. Optional integrations are detected through installed public service contracts; this keeps the importer out of other modules' private schemas.

## Processing stages

1. `ConfluenceImportUploadService` creates a job and validates ordered chunks.
2. `ConfluenceArchive` checks ZIP limits and safe entry names.
3. `ConfluenceExportReader` streams `entities.xml` objects.
4. `ConfluenceExportScanner` builds a read-only inventory and candidate mappings.
5. `ConfluenceImportService` validates the administrator confirmation and creates target objects.
6. `ConfluenceHtmlConverter` produces portable HTML placeholders.
7. `ConfluenceReferenceResolver` replaces local targets and retains stable unresolved targets.
8. Optional Search, Audit, Comment and Backup integrations run through their public contracts.

## Backup contract

The optional site/component Backup providers export only completed jobs and their durable rows. They remove the original upload path, convert private attachment paths to portable records, and reconstruct the absolute private path during restore. A separate `simbioza-confluence-import-workspace` provider follows the `workspace-scope` provider and transfers only the selected Workspace's provenance and private attachment blobs. A copy restore generates new conflicting UUIDs and source identities, reconnects node/document references through the shared import state, and rewrites attachment links. Temporary uploads are not backup data.

## Extending macro support

Add a converter for the macro's storage-format payload, create a safe static fallback and add fixtures for malformed and complete data. Creating a live Calendar/Task object requires an explicit adapter in the owning module and complete ACL mapping. Never infer public access from a missing source principal.

## Schema ownership

The reversible module migration is a template. Installation copies it into the host application's migration history. New columns and tables must remain prefixed through the module constants and have MySQL, PostgreSQL and SQLite coverage in the host matrix.
