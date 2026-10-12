<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorExternalScripts;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceExternalScriptReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfluenceExternalScriptReport::class)]
final class ConfluenceExternalScriptReportTest extends TestCase
{
    /**
     * EN: Repeated macros/history on one page count once; other pages retain their identities.
     *
     * HR: Ponovljeni makroi/povijest jedne stranice broje se jednom; druge zadržavaju identitet.
     */
    public function testGroupsIdenticalScriptAcrossDistinctPages(): void
    {
        $definition = EditorExternalScripts::definition(['name' => 'Shared', 'type' => 'inline', 'value' => 'window.shared = 1;']);
        $issue = ['type' => 'external_script', 'marker' => $definition['id'], 'script' => $definition];
        $groups = ConfluenceExternalScriptReport::groups([
            ['source_page_id' => '1', 'title' => 'One', 'url' => '/one', 'issues' => [$issue, $issue]],
            ['source_page_id' => '1', 'title' => 'One', 'url' => '/one', 'issues' => [$issue]],
            ['source_page_id' => '2', 'title' => 'Two', 'url' => '/two', 'issues' => [$issue]],
            ['source_page_id' => '3', 'issues' => [['type' => 'external_script', 'source' => 'blocked']]],
        ]);
        self::assertCount(1, $groups);
        self::assertSame(['1', '2'], array_column($groups[$definition['id']]['pages'], 'id'));
        self::assertSame($definition, $groups[$definition['id']]['definition']);
    }
}
