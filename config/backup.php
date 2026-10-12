<?php

declare(strict_types=1);

// EN: Confluence provenance metadata and private attachments belong to Workspaces.
//
// HR: Confluence metapodaci i privatni privitci pripadaju cjelini Područja.
return ['providers' => [
    [
        'service' => 'heartphrame.backup.provider.simbioza-confluence-import',
        'requires' => [
            'aaieduhr/heartphrame-module-auth',
            'aaieduhr/simbioza-module-workspace',
        ],
    ],
    [
        'service' => 'heartphrame.backup.provider.simbioza-confluence-import-files',
        'requires' => ['aaieduhr/simbioza-module-confluence-import'],
    ],
    [
        'service' => 'heartphrame.backup.provider.simbioza-confluence-import-workspace',
        'requires' => [
            'aaieduhr/heartphrame-module-backup',
            'aaieduhr/heartphrame-module-editor-html',
            'aaieduhr/simbioza-module-workspace',
        ],
    ],
]];
