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
composer require aaieduhr/simbioza-module-confluence-import:^0.3.1
vendor/bin/hph simbioza-confluence-import:install-migration
vendor/bin/hph orm-migrate up
```

The install command copies a timestamped reversible migration into the host application. Review the file with the other application migrations before running it.

## Configuration

Put installation overrides in
`HPH_CONFIG_PATH/confluence-import/confluence-import.php`. Without an environment
override, the path is `<application>/config/confluence-import/confluence-import.php`.
The unchanged framework loads these PHP arrays and ordered
`HPH_CONFIG_OVERRIDE_PATHS` layers before constructing module services. Omitted
keys keep package defaults; an empty higher-layer array resets this policy.
The old flat `confluence_import` tree is not used.

| Key | Default | Purpose |
| --- | ---: | --- |
| `data_path` | `confluence-import` | Directory below the application `data` directory |
| `max_archive_size` | 8 GiB | Maximum uploaded ZIP size |
| `chunk_size` | 8 MiB | Resumable upload chunk size |
| `upload_ttl` | 24 hours | Retention of unfinished uploads |
| `max_entries` | 250,000 | ZIP entry limit |
| `max_uncompressed_size` | 16 GiB | Single-space total uncompressed ZIP limit |
| `max_entry_size` | 4 GiB | Single-space per-entry uncompressed limit |
| `max_site_uncompressed_size` | 128 GiB | Site/multi-space total uncompressed ZIP limit |
| `max_site_entry_size` | 64 GiB | Site/multi-space per-entry uncompressed limit |
| `max_inventory_object_size` | 16 MiB | Retained site metadata object limit |
| `max_inventory_spaces` | 10,000 | Retained space/reference count limit |
| `max_site_identity_records` | 100,000 | Retained identity/directory/membership/permission objects |
| `max_site_identity_index_size` | 32 MiB | Maximum complete private identity catalog |
| `max_compression_ratio` | 250 | ZIP-bomb protection |
| `import_execution_time_limit` | 900 seconds | Upper bound for a large-archive preflight or one import processing step |
| `import_memory_limit_mb` | 512 MiB | Memory requested only during administrator import processing |
| `default_language` | `hr` | Content language when the export has no locale |
| `source_base_url` | empty | Initial source Confluence URL; the administrator confirms or changes it for each individual or batch import |

Example file (not an additional wrapper array):

```php
<?php

return [
    'chunk_size' => 16 * 1024 * 1024,
    'max_archive_size' => 8 * 1024 * 1024 * 1024,
    'import_execution_time_limit' => 1200,
    'default_language' => 'hr',
    'source_base_url' => 'https://wiki.example.org',
];
```

`source_base_url` is not a fixed installation setting. It only pre-fills the
administrator field, while the actual value is stored with every started import.
`data_path` must be a relative subdirectory without `..`, absolute paths or
backslashes. Uploads and import state stay under the application's private `data`.
The existing GUI, per-job mapping and source choices remain unchanged.

Upload and confirmed single-space content import run in resumable phases. After mappings are
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

Development adds [site/multi-space inventory](site-inventory_en.md), separate
[identity review/import](site-identities_en.md) and [selected content](site-content_en.md).
Interrupted initial scans restart; only complete indexes are reusable. C3 keeps
selected-content partitions beside the private ZIP and retains the shared source
for C4. None of these steps needs a new migration. Allow time and memory for two
shared XML passes, then bounded per-space processing; see the C3 recovery guide.
