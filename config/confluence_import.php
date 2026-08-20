<?php

declare(strict_types=1);

return [
    // HR: Veliki exporti dolaze u dijelovima; ograničenja sprječavaju ZIP bombe.
    // EN: Large exports arrive in chunks; limits protect against ZIP bombs.
    'data_path' => 'confluence-import',
    'max_archive_size' => 4 * 1024 * 1024 * 1024,
    'chunk_size' => 8 * 1024 * 1024,
    'upload_ttl' => 86400,
    'max_entries' => 250000,
    'max_uncompressed_size' => 16 * 1024 * 1024 * 1024,
    'max_entry_size' => 4 * 1024 * 1024 * 1024,
    'max_compression_ratio' => 250,
    'default_language' => 'hr',
    'attachment_policy' => 'download_only',
];
