<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorHtmlSanitizer;
use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceCalendarMacro;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceCalendarResolutionService;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceHtmlConverter;
use DOMDocument;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * EN: Synthetic macro IDs and UUIDs verify portability, incremental linking and sanitized round trips.
 * No archive, calendar, user or subscription in the development installation is changed.
 *
 * HR: Sintetički ID-jevi makroa i UUID-jevi provjeravaju prenosivost, postupno povezivanje i sanitizaciju.
 * Nijedna arhiva, kalendar, korisnik ili pretplata razvojne instalacije ne mijenja se.
 */
final class ConfluenceCalendarMacroTest extends TestCase
{
    public function testSelectionKeepsOrderAndDoesNotRequireSiteSpecificUuidSyntax(): void
    {
        self::assertSame(['native:two', 'calendar-one', '123'], ConfluenceCalendarMacro::ids(' native:two,calendar-one,123,native:two '));
        self::assertSame([''], ConfluenceCalendarMacro::ids(''));
    }

    public function testMalformedAndExcessiveSelectionsStayVisibleInsteadOfBecomingViewerCalendars(): void
    {
        foreach (['<script>', str_repeat('x', 191), implode(',', range(1, 1001)), str_repeat('x', 191001)] as $raw) {
            try {
                ConfluenceCalendarMacro::ids($raw);
                self::fail('Invalid selection accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $result = (new ConfluenceHtmlConverter())->convert($this->macro('&lt;script&gt;'), 'EXAMPLE', '42');
        self::assertTrue($result->reviewIssues[0]['invalid_selection']);
        self::assertSame('', $result->reviewIssues[0]['source_calendar_id']);
        self::assertStringNotContainsString('data-editor-html-calendar', $result->html);
    }

    public function testPartialResolutionAccumulatesOneEmbedAndKeepsMissingReferencesAfterSanitization(): void
    {
        $converted = (new ConfluenceHtmlConverter())->convert($this->macro('one,missing,two,one', 'week'), 'EXAMPLE', '42');
        self::assertSame(['one', 'missing', 'two'], array_column($converted->reviewIssues, 'source_calendar_id'));
        self::assertCount(1, array_unique(array_column($converted->reviewIssues, 'group_marker')));
        self::assertCount(3, array_unique(array_column($converted->reviewIssues, 'marker')));
        self::assertSame(['week', 'week', 'week'], array_column($converted->reviewIssues, 'calendar_view'));
        $sanitizer = new EditorHtmlSanitizer();
        $html = $sanitizer->sanitize($converted->html);
        $group = $converted->reviewIssues[0]['group_marker'];
        $a = '00000000-0000-4000-8000-000000000001';
        $b = '00000000-0000-4000-8000-000000000002';
        $html = $sanitizer->sanitize($this->replace($html, $converted->reviewIssues[2]['marker'], [$b => 'Second'], $group));
        self::assertStringContainsString($converted->reviewIssues[0]['marker'], $html);
        $html = $sanitizer->sanitize($this->replace($html, $converted->reviewIssues[0]['marker'], [$a => '<First>', $b => 'Second'], $group));
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        $embed = $dom->getElementById($group . '-embed');
        self::assertNotNull($embed);
        self::assertSame($a . ',' . $b, $embed->getAttribute('data-calendar-uuids'));
        self::assertSame('week', $embed->getAttribute('data-calendar-view'));
        self::assertSame([$a => '<First>', $b => 'Second'], json_decode($embed->getAttribute('data-calendar-labels'), true));
        self::assertSame(1, substr_count($html, 'data-editor-html-calendar="1"'));
        self::assertStringContainsString($converted->reviewIssues[1]['marker'], $html);
        self::assertStringNotContainsString('id="' . $converted->reviewIssues[0]['marker'] . '"', $html);
        self::assertSame($html, $sanitizer->sanitize($html));
    }

    public function testSeparateMacrosNeverShareTheAccumulatedEmbed(): void
    {
        $result = (new ConfluenceHtmlConverter())->convert($this->macro('one') . $this->macro('one'), 'OTHER', '91');
        self::assertNotSame($result->reviewIssues[0]['group_marker'], $result->reviewIssues[1]['group_marker']);
        $this->expectException(ConfluenceImportException::class);
        $this->replace($result->html, $result->reviewIssues[0]['marker'], ['a' => 'Calendar'], $result->reviewIssues[1]['group_marker']);
    }

    public function testRepeatedSourceMacroIdentifiersDoNotJoinSeparateMacroPositions(): void
    {
        $macro = str_replace('<ac:structured-macro ', '<ac:structured-macro ac:macro-id="copied-id" ', $this->macro('one,two'));
        $result = (new ConfluenceHtmlConverter())->convert($macro . $macro, 'OTHER', '91');
        self::assertCount(4, $result->reviewIssues);
        self::assertNotSame($result->reviewIssues[0]['group_marker'], $result->reviewIssues[2]['group_marker']);
    }

    private function macro(string $ids, string $view = 'month'): string
    {
        return '<ac:structured-macro ac:name="calendar"><ac:parameter ac:name="id">' . $ids
            . '</ac:parameter><ac:parameter ac:name="defaultView">' . $view . '</ac:parameter></ac:structured-macro>';
    }

    /** @param array<string,string> $calendars */
    private function replace(string $html, string $marker, array $calendars, string $group): string
    {
        $service = (new ReflectionClass(ConfluenceCalendarResolutionService::class))->newInstanceWithoutConstructor();
        return (new ReflectionMethod($service, 'replaceMarker'))->invoke($service, $html, $marker, $calendars, $group, 'week');
    }
}
