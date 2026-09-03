# Installation and configuration

Croatian version: [installation_hr.md](installation_hr.md)

## Requirements

- PHP 8.2 or newer;
- DOM, Fileinfo, LibXML, XMLReader and ZIP extensions;
- the required packages listed in the root [README](../README.md);
- a writable application `data` directory;
- an application database supported by HeartPhrame ORM.

The module must appear after ORM, Menu, Auth, HTML Editor, Workspace and Simbioza User in `app.modules.enabled`.

## Install

```bash
composer require aaieduhr/simbioza-module-confluence-import:^0.1.8
vendor/bin/hph simbioza-confluence-import:install-migration
vendor/bin/hph orm-migrate up
```

The install command copies a timestamped reversible migration into the host application. Review the file with the other application migrations before running it.

## Configuration

Application values under `confluence_import.*` override module defaults:

| Key | Default | Purpose |
| --- | ---: | --- |
| `data_path` | `confluence-import` | Directory below the application `data` directory |
| `max_archive_size` | 8 GiB | Maximum uploaded ZIP size |
| `chunk_size` | 8 MiB | Resumable upload chunk size |
| `upload_ttl` | 24 hours | Retention of unfinished uploads |
| `max_entries` | 250,000 | ZIP entry limit |
| `max_uncompressed_size` | 16 GiB | Total uncompressed ZIP limit |
| `max_entry_size` | 4 GiB | Per-entry uncompressed limit |
| `max_compression_ratio` | 250 | ZIP-bomb protection |
| `import_execution_time_limit` | 900 seconds | Upper bound for a large-archive preflight or one import processing step |
| `default_language` | `hr` | Content language when the export has no locale |

Example host configuration:

```php
'confluence_import' => [
    'chunk_size' => 16 * 1024 * 1024,
    'max_archive_size' => 8 * 1024 * 1024 * 1024,
    'import_execution_time_limit' => 1200,
    'default_language' => 'hr',
],
```

Upload and content import both run in resumable phases. After mappings are
confirmed, the server prepares a stable plan and each subsequent request
processes a bounded number of attachments or pages. The browser displays
progress and requests the next step until finalization completes links, the
report, and derived indexes. Closing the browser does not undo completed steps;
reopening the job continues from stored state without duplicating content.

The web server and PHP therefore need to allow one processing step rather than
the complete multi-gigabyte import. The value must still be aligned with proxy
and PHP-FPM limits because initial archive preparation and finalization may be
more expensive than an ordinary batch.

An expired upload cannot be resumed. It is safely removed before a new upload
session is created. An administrator may remove it earlier with **Cancel
import**. This also applies to a failed preflight job if content import never
started. Completed imports and partially executed imports are never removed by
temporary-upload cleanup.
