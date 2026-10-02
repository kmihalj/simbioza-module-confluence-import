<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceAttachmentSelector;
use PHPUnit\Framework\TestCase;

final class ConfluenceAttachmentSelectionTest extends TestCase
{
    /** HR: Zadani import prenosi samo aktualne privitke; povijest zahtijeva odabir. EN: Default imports transfer only current attachments; history requires an explicit choice. */
    public function testHistoryRequiresExplicitSelection(): void
    {
        $records = [
            ['source_id' => '201', 'logical_source_id' => '201', 'version' => 2, 'status' => 'current'],
            ['source_id' => '202', 'logical_source_id' => '201', 'version' => 1, 'status' => 'current'],
            ['source_id' => '300', 'version' => 1, 'status' => 'current'],
        ];
        self::assertSame([$records[0], $records[2]], ConfluenceAttachmentSelector::forImport($records));
        self::assertSame($records, ConfluenceAttachmentSelector::forImport($records, true));
    }
}
