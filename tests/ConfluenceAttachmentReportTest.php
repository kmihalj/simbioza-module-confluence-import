<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceAttachmentReport;
use PHPUnit\Framework\TestCase;

final class ConfluenceAttachmentReportTest extends TestCase
{
    /** HR: Stare greške nisu gubitak aktualnih privitaka, ali aktualne se ne skrivaju. EN: Historical failures are not missing current attachments, but current failures are never hidden. */
    public function testCurrentAndHistoricalFailuresAreSeparated(): void
    {
        $records = [
            ['source_attachment_id' => '202', 'logical_source_id' => '201', 'source_version' => 1, 'status' => 'failed', 'original_name' => 'old.png', 'error_message' => 'missing old binary'],
            ['source_attachment_id' => '201', 'logical_source_id' => '201', 'source_version' => 2, 'status' => 'registered'],
            ['source_attachment_id' => '300', 'source_version' => 1, 'status' => 'failed', 'original_name' => '<img>.png', 'error_message' => 'missing current binary'],
            ['source_attachment_id' => '400', 'source_version' => 1, 'status' => 'stored'],
            ['source_attachment_id' => '500', 'source_version' => 1, 'status' => 'pending'],
        ];
        $report = ConfluenceAttachmentReport::summarize($records);
        self::assertSame(4, $report['current_total']);
        self::assertSame(2, $report['current_imported']);
        self::assertSame(1, $report['current_failed']);
        self::assertSame(1, $report['historical_total']);
        self::assertSame(1, $report['historical_failed']);
        self::assertSame('<img>.png', $report['current_failures'][0]['name']);
        self::assertSame('old.png', $report['historical_failures'][0]['name']);
    }
}
