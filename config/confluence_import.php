<?php

declare(strict_types=1);

return [
    // EN: Large exports arrive in chunks; limits protect against ZIP bombs.
    //
    // HR: Veliki exporti dolaze u dijelovima; ograničenja sprječavaju ZIP bombe.
    'data_path' => 'confluence-import',
    'max_archive_size' => 8 * 1024 * 1024 * 1024,
    'chunk_size' => 8 * 1024 * 1024,
    'upload_ttl' => 86400,
    'max_entries' => 250000,
    'max_uncompressed_size' => 16 * 1024 * 1024 * 1024,
    'max_entry_size' => 4 * 1024 * 1024 * 1024,
    // EN: Site/multi-space XML may exceed a single-space limit; ZIP safety checks still apply.
    // The inventory skips document bodies and never extracts these complete entries.
    //
    // HR: XML sitea/više područja može premašiti limit jednog područja; ZIP zaštite i dalje vrijede.
    // Pregled preskače tijela dokumenata i nikada ne izdvaja ove potpune zapise.
    'max_site_entry_size' => 64 * 1024 * 1024 * 1024,
    'max_site_uncompressed_size' => 128 * 1024 * 1024 * 1024,
    'max_inventory_object_size' => 16 * 1024 * 1024,
    'max_inventory_spaces' => 10000,
    // EN: Bound retained site identities, memberships and permission metadata, not all XML objects.
    // Credentials, tokens and document bodies are never written to this index.
    //
    // HR: Ograniči zadržane identitete, članstva i ovlasti sitea, ne sve XML objekte.
    // Vjerodajnice, tokeni i tijela dokumenata nikada se ne zapisuju u ovaj indeks.
    'max_site_identity_records' => 100000,
    'max_site_identity_index_size' => 32 * 1024 * 1024,
    'max_compression_ratio' => 250,
    // EN: A real large-Workspace import may run for up to 15 minutes.
    //
    // HR: Stvarni import velikog područja smije trajati do 15 minuta.
    'import_execution_time_limit' => 900,
    // EN: Large manifests need more memory only during administrator import steps.
    //
    // HR: Veliki manifesti traže više memorije samo tijekom administratorskog importa.
    'import_memory_limit_mb' => 512,
    'default_language' => 'hr',
    // EN: Only the initial value of the administrator's per-import choice.
    //
    // HR: Samo početna vrijednost administratorskog odabira po importu.
    'source_base_url' => '',
    'attachment_policy' => 'download_only',
];
