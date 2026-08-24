<?php

declare(strict_types=1);

return [
    // HR: Veliki exporti dolaze u dijelovima; ograničenja sprječavaju ZIP bombe.
    // EN: Large exports arrive in chunks; limits protect against ZIP bombs.
    'data_path' => 'confluence-import',
    'max_archive_size' => 8 * 1024 * 1024 * 1024,
    'chunk_size' => 8 * 1024 * 1024,
    'upload_ttl' => 86400,
    'max_entries' => 250000,
    'max_uncompressed_size' => 16 * 1024 * 1024 * 1024,
    'max_entry_size' => 4 * 1024 * 1024 * 1024,
    'max_compression_ratio' => 250,
    // HR: Stvarni import velikog područja smije trajati do 15 minuta.
    // EN: A real large-Workspace import may run for up to 15 minutes.
    'import_execution_time_limit' => 900,
    'default_language' => 'hr',
    'attachment_policy' => 'download_only',
];
