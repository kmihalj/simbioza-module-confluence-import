<?php

declare(strict_types=1);

// HR: Confluence metapodaci i privatni privitci pripadaju cjelini Područja.
// EN: Confluence provenance metadata and private attachments belong to Workspaces.
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
