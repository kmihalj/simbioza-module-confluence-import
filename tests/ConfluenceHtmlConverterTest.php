<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorHtmlChartService;
use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorHtmlRoadmapService;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceHtmlConverter;
use AaiEduHr\SimbiozaModuleConfluenceImport\Value\ConfluenceMacroContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfluenceHtmlConverter::class)]
final class ConfluenceHtmlConverterTest extends TestCase
{
    /** HR: Naslov informativnog makroa ostaje vidljiv i kada je tijelo prazno. EN: An information macro title remains visible when its body is empty. */
    public function testPreservesTipTitleWhenRichBodyIsEmpty(): void
    {
        $body = <<<'XML'
<ac:structured-macro ac:name="tip"><ac:parameter ac:name="title">Dokumentacija sustava Dabar</ac:parameter><ac:rich-text-body><p><br /></p></ac:rich-text-body></ac:structured-macro>
XML;

        $result = (new ConfluenceHtmlConverter())->convert($body, 'DABAR', '6817280');

        self::assertStringContainsString('alert alert-success', $result->html);
        self::assertStringContainsString('Dokumentacija sustava Dabar', $result->html);
        self::assertSame([], $result->unsupportedMacros);
    }

    /** HR: Pretvara interne URL-ove, privitke, task-listu i podržani makro, a vanjski URL ostavlja netaknut. EN: Converts internal URLs, attachments, a task list, and a supported macro while preserving an external URL. */
    public function testConvertsStorageFormatAndKeepsPortableReferences(): void
    {
        $body = <<<'XML'
<p><a href="https://wiki.example/spaces/DEMO/pages/42/Child+Page#part-1">Child</a></p>
<p><a href="https://outside.example/path">External</a></p>
<p><img src="/download/attachments/10/manual.pdf" /></p>
<ac:task-list><ac:task><ac:task-status>complete</ac:task-status><ac:task-body>Done</ac:task-body></ac:task></ac:task-list>
<ac:structured-macro ac:name="code"><ac:plain-text-body><![CDATA[echo "ok";]]></ac:plain-text-body></ac:structured-macro>
XML;

        $result = (new ConfluenceHtmlConverter())->convert($body, 'DEMO', '10');

        self::assertStringContainsString('__SIMBIOZA_CONFLUENCE_LINK__', $result->html);
        self::assertStringContainsString('__SIMBIOZA_CONFLUENCE_ATTACHMENT__', $result->html);
        self::assertStringContainsString('https://outside.example/path', $result->html);
        self::assertStringContainsString('data-editor-html-task-list="1"', $result->html);
        self::assertStringContainsString('data-task-initial-completed="1"', $result->html);
        self::assertStringNotContainsString('<input', $result->html);
        self::assertSame(1, substr_count($result->html, 'Done'));
        self::assertStringContainsString('<pre><code>echo "ok";</code></pre>', $result->html);
        self::assertSame('42', $result->links[0]['destination_page_id']);
        self::assertSame('part-1', $result->links[0]['fragment']);
        self::assertSame('manual.pdf', $result->attachments[0]['filename']);
        self::assertSame([], $result->unsupportedMacros);
    }

    /** HR: Čuva status, bogati sadržaj i ugniježđenost Confluence zadataka bez udvostručavanja teksta. EN: Preserves status, rich content, and nesting of Confluence tasks without duplicating text. */
    public function testPreservesNestedConfluenceTaskLists(): void
    {
        $body = <<<'XML'
<ac:task-list><ac:task><ac:task-id>21</ac:task-id><ac:task-uuid>parent-uuid</ac:task-uuid>
<ac:task-status>complete</ac:task-status><ac:task-body>Prilog <strong>DABAR-1122</strong>
<ac:task-list><ac:task><ac:task-id>22</ac:task-id><ac:task-status>incomplete</ac:task-status>
<ac:task-body>Provjeriti <a href="https://outside.example/task">poveznicu</a></ac:task-body></ac:task></ac:task-list>
</ac:task-body></ac:task></ac:task-list>
XML;

        $converter = new ConfluenceHtmlConverter();
        $result = $converter->convert($body, 'DABAR', '10');
        $summaries = $converter->taskSummaries($body);

        self::assertSame(1, substr_count($result->html, 'Prilog'));
        self::assertSame(1, substr_count($result->html, 'Provjeriti'));
        self::assertStringContainsString('data-task-initial-completed="1"', $result->html);
        self::assertStringContainsString('data-task-initial-completed="0"', $result->html);
        self::assertStringContainsString('data-task-depth="1"', $result->html);
        self::assertStringContainsString('Prilog <strong>DABAR-1122</strong>', $result->html);
        self::assertStringContainsString(
            'Provjeriti <a href="https://outside.example/task">poveznicu</a>',
            $result->html,
        );
        self::assertStringContainsString('id="confluence-task-parent-uuid"', $result->html);
        self::assertCount(2, $summaries);
        self::assertSame('Prilog DABAR-1122', $summaries[0]['text']);
        self::assertTrue($summaries[0]['complete']);
        self::assertSame('Provjeriti poveznicu', $summaries[1]['text']);
        self::assertFalse($summaries[1]['complete']);
    }

    /** HR: Tekst predloška nije objavljeni zadatak i ne smije ući u stranicu ni izvještaj. EN: Template placeholder text is not a published task and must not enter the page or report. */
    public function testOmitsConfluenceTemplatePlaceholderTasks(): void
    {
        $body = <<<'XML'
<p><ac:placeholder>Set goals, objectives or some context for this meeting.</ac:placeholder></p>
<ac:task-list>
<ac:task><ac:task-id>1</ac:task-id><ac:task-status>incomplete</ac:task-status><ac:task-body><ac:placeholder ac:type="mention">Type your task here. Use "@" to assign a user.</ac:placeholder></ac:task-body></ac:task>
<ac:task><ac:task-id>2</ac:task-id><ac:task-status>incomplete</ac:task-status><ac:task-body>Stvarni zadatak</ac:task-body></ac:task>
</ac:task-list>
XML;

        $converter = new ConfluenceHtmlConverter();
        $result = $converter->convert($body, 'DABAR', '10');
        $summaries = $converter->taskSummaries($body);

        self::assertStringNotContainsString('Set goals', $result->html);
        self::assertStringNotContainsString('Type your task here', $result->html);
        self::assertStringContainsString('Stvarni zadatak', $result->html);
        self::assertCount(1, $summaries);
        self::assertSame('Stvarni zadatak', $summaries[0]['text']);
    }

    /** HR: tasks-report-macro postaje samostalna nativna tablica zadataka s običnim lokalnim poveznicama. EN: tasks-report-macro becomes an independent native task table with regular local links. */
    public function testConvertsTaskReportMacroToStaticLocalTable(): void
    {
        $body = <<<'XML'
<ac:structured-macro ac:name="tasks-report-macro">
<ac:parameter ac:name="pageSize">1</ac:parameter><ac:parameter ac:name="status">incomplete</ac:parameter>
<ac:parameter ac:name="labels">meeting-notes</ac:parameter></ac:structured-macro>
XML;
        $context = new ConfluenceMacroContext('10', [
            '11' => [
                'title' => 'Bilješke sastanka',
                'path' => '/workspace/dabar/biljeske-sastanka',
                'parent_id' => '',
                'sort_order' => 1,
                'workspace_slug' => 'dabar',
                'node_slug' => 'biljeske-sastanka',
                'labels' => ['meeting-notes'],
                'tasks' => [
                    ['id' => 'task-1', 'native_uuid' => 'e3d1be37-04fd-4f69-ad6e-39fa9f085223', 'text' => 'Otvoreni zadatak', 'complete' => false, 'due_date' => '2026-09-10', 'assignee' => 'user-1'],
                    ['id' => 'task-4', 'native_uuid' => 'a03d850b-2d60-491c-a659-ed4a3fbc646c', 'text' => 'Drugi otvoreni zadatak', 'complete' => false, 'due_date' => '', 'assignee' => ''],
                    ['id' => 'task-2', 'native_uuid' => '875b30c0-4767-4e51-b5d9-5a7c76de7c70', 'text' => 'Dovršeni zadatak', 'complete' => true, 'due_date' => '', 'assignee' => ''],
                ],
            ],
            '12' => [
                'title' => 'Bez oznake',
                'path' => '/workspace/dabar/bez-oznake',
                'parent_id' => '',
                'sort_order' => 2,
                'workspace_slug' => 'dabar',
                'node_slug' => 'bez-oznake',
                'labels' => [],
                'tasks' => [
                    ['id' => 'task-3', 'native_uuid' => '7067149f-9adf-479f-b40b-62f1ab53f555', 'text' => 'Pogrešna oznaka', 'complete' => false, 'due_date' => '', 'assignee' => ''],
                ],
            ],
        ], [], ['user-1' => 'Dario Pinturić']);

        $result = (new ConfluenceHtmlConverter())->convert($body, 'DABAR', '10', $context);

        self::assertStringContainsString('class="table-responsive"', $result->html);
        self::assertStringContainsString('data-editor-html-task-list="1"', $result->html);
        self::assertStringContainsString('data-task-list-view="table"', $result->html);
        self::assertMatchesRegularExpression('/data-task-list-uuid="[0-9a-f-]{36}"/', $result->html);
        self::assertMatchesRegularExpression('/data-task-uuid="[0-9a-f-]{36}"/', $result->html);
        self::assertStringNotContainsString('data-editor-html-task-report', $result->html);
        self::assertStringNotContainsString('data-task-source-', $result->html);
        self::assertStringNotContainsString('data-task-report-uuid', $result->html);
        self::assertStringContainsString('Otvoreni zadatak', $result->html);
        self::assertStringContainsString('2026-09-10', $result->html);
        self::assertStringContainsString('Dario Pinturić', $result->html);
        self::assertStringContainsString('Drugi otvoreni zadatak', $result->html);
        self::assertStringContainsString('/workspace/dabar/biljeske-sastanka#confluence-task-task-1', $result->html);
        self::assertStringNotContainsString('Dovršeni zadatak', $result->html);
        self::assertStringNotContainsString('Pogrešna oznaka', $result->html);
        self::assertSame([], $result->unsupportedMacros);
    }

    /** HR: Čuva sva valjana Unicode slova i emoji u URL-ovima. EN: Preserves all valid Unicode letters and emoji in URLs. */
    public function testPreservesUnicodeInPlainUrlsAndReferenceTokens(): void
    {
        $body = <<<'XML'
<p><a href="https://wiki.example/spaces/HR/pages/42/Uređivanje-ČĆĐŠŽ-日本語-😀#odjeljak-đ-日本語-😀">Moderna</a></p>
<p><a href="https://wiki.example/display/HR/Čćđšž+日本語+😀">Legacy</a></p>
<p><img src="/download/attachments/10/izvještaj-Đ-日本語-😀.pdf" /></p>
<p><ac:image><ri:url ri:value="https://cdn.example/čćđšž/slika-日本語-😀.png" /></ac:image></p>
XML;

        $result = (new ConfluenceHtmlConverter())->convert($body, 'HR', '10');

        self::assertTrue(mb_check_encoding($result->html, 'UTF-8'));
        self::assertSame('Uređivanje-ČĆĐŠŽ-日本語-😀', $result->links[0]['destination_page_title']);
        self::assertSame('odjeljak-đ-日本語-😀', $result->links[0]['fragment']);
        self::assertSame('Čćđšž 日本語 😀', $result->links[1]['destination_page_title']);
        self::assertSame('izvještaj-Đ-日本語-😀.pdf', $result->attachments[0]['filename']);
        self::assertStringContainsString('alt="slika-日本語-😀.png"', $result->html);
        self::assertStringContainsString(
            'https://cdn.example/%C4%8D%C4%87%C4%91%C5%A1%C5%BE/slika-%E6%97%A5%E6%9C%AC%E8%AA%9E-%F0%9F%98%80.png',
            $result->html,
        );
    }

    /** HR: Normalizira nestandardni CDATA završetak i HTML entitete iz stvarnih Atlassian XML izvoza. EN: Normalizes the non-standard CDATA terminator and HTML entities found in real Atlassian XML exports. */
    public function testNormalizesAtlassianExportStorageQuirks(): void
    {
        $body = <<<'XML'
<p>Prvi&nbsp;redak</p>
<ac:link><ri:page ri:content-title="Druga stranica" /><ac:plain-text-link-body><![CDATA[Otvori stranicu]] ></ac:plain-text-link-body></ac:link>
<ac:structured-macro ac:name="code"><ac:plain-text-body><![CDATA[Primjer s doslovnim <![CDATA[ početkom]] ></ac:plain-text-body></ac:structured-macro>
<ac:structured-macro ac:name="code"><ac:plain-text-body><![CDATA[Nepravilan Confluence završetak]] ]></ac:plain-text-body></ac:structured-macro>
<p><a href="https://wiki.example/display/DEMO/Druga+stranica">Obična interna poveznica</a></p>
<p>Nepoznati entitet ostaje vidljiv: &unknown;</p>
XML;

        $result = (new ConfluenceHtmlConverter())->convert($body, 'DEMO', '10');

        self::assertStringContainsString('Prvi redak', $result->html);
        self::assertStringContainsString('Otvori stranicu', $result->html);
        self::assertStringContainsString('Primjer s doslovnim &lt;![CDATA[ početkom', $result->html);
        self::assertStringContainsString('Nepravilan Confluence završetak', $result->html);
        self::assertStringContainsString('__SIMBIOZA_CONFLUENCE_LINK__', $result->html);
        self::assertCount(2, $result->links);
        self::assertStringContainsString('&amp;unknown;', $result->html);
        self::assertNotContains('invalid-storage-format', $result->unsupportedMacros);
    }

    /** HR: Pretvara makroe stabla, privitaka, multimedije, statusa i sidra stvarnim lokalnim podacima. EN: Converts tree, attachment, multimedia, status, and anchor macros with real local data. */
    public function testConvertsContextAwareMacros(): void
    {
        $body = <<<'XML'
<ac:structured-macro ac:name="children"><ac:parameter ac:name="all">true</ac:parameter></ac:structured-macro>
<ac:structured-macro ac:name="attachments" />
<ac:structured-macro ac:name="multimedia"><ac:parameter ac:name="name"><ri:attachment ri:filename="demo.mp4" /></ac:parameter></ac:structured-macro>
<ac:structured-macro ac:name="status"><ac:parameter ac:name="colour">Green</ac:parameter><ac:parameter ac:name="title">OK</ac:parameter></ac:structured-macro>
<ac:structured-macro ac:name="anchor"><ac:parameter ac:name="">section 1</ac:parameter></ac:structured-macro>
XML;
        $context = new ConfluenceMacroContext('10', [
            '10' => ['title' => 'Root', 'path' => '/workspace/demo/root', 'parent_id' => '', 'sort_order' => 1],
            '11' => ['title' => 'Child', 'path' => '/workspace/demo/child', 'parent_id' => '10', 'sort_order' => 1],
            '12' => ['title' => 'Grandchild', 'path' => '/workspace/demo/grandchild', 'parent_id' => '11', 'sort_order' => 1],
        ], ['demo.mp4' => '/confluence-import/attachments/demo']);

        $result = (new ConfluenceHtmlConverter())->convert($body, 'DEMO', '10', $context);

        self::assertStringContainsString('<ul><li><a href="/workspace/demo/child">Child</a>', $result->html);
        self::assertStringContainsString('/workspace/demo/grandchild', $result->html);
        self::assertStringContainsString('<a href="/confluence-import/attachments/demo" download="demo.mp4">demo.mp4</a>', $result->html);
        self::assertStringContainsString('<video', $result->html);
        self::assertStringContainsString('text-bg-success', $result->html);
        self::assertStringContainsString('id="section-1"', $result->html);
        self::assertSame([], $result->unsupportedMacros);
    }

    /** HR: Include postaje dinamička referenca, a neispravan graf čuva tablicu i upozorenje. EN: Include becomes a dynamic reference, while an invalid chart keeps its table and warning. */
    public function testUsesSafeFallbacksForIncludeAndChart(): void
    {
        $body = <<<'XML'
<ac:structured-macro ac:name="include"><ac:parameter ac:name=""><ac:link><ri:page ri:content-title="Restricted page" /></ac:link></ac:parameter></ac:structured-macro>
<ac:structured-macro ac:name="include"><ac:parameter ac:name=""><ac:link><ri:page ri:space-key="OTHER" ri:content-id="77" ri:content-title="External page" /></ac:link></ac:parameter></ac:structured-macro>
<ac:structured-macro ac:name="chart"><ac:parameter ac:name="title">Results</ac:parameter><ac:rich-text-body><table><tr><td>42</td></tr></table></ac:rich-text-body></ac:structured-macro>
XML;

        $result = (new ConfluenceHtmlConverter())->convert($body, 'DEMO', '10');

        self::assertStringContainsString('data-simbioza-confluence-include-token', $result->html);
        self::assertSame('Restricted page', $result->includes[0]['destination_page_title']);
        self::assertSame('DEMO', $result->includes[0]['destination_space_key']);
        self::assertSame('OTHER', $result->includes[1]['destination_space_key']);
        self::assertSame('77', $result->includes[1]['destination_page_id']);
        self::assertStringContainsString(
            '<div class="table-responsive"><table class="table table-bordered table-striped table-hover">',
            $result->html,
        );
        self::assertStringContainsString('Results', $result->html);
        self::assertSame(['chart'], $result->unsupportedMacros);
    }

    /** HR: Valjani Confluence chart postaje uređivi nativni grafikon bez upozorenja. EN: A valid Confluence chart becomes an editable native chart without a warning. */
    public function testConvertsConfluenceChartToNativeEditorChart(): void
    {
        $body = <<<'XML'
<ac:structured-macro ac:name="chart">
<ac:parameter ac:name="type">bar</ac:parameter><ac:parameter ac:name="orientation">vertical</ac:parameter>
<ac:parameter ac:name="3D">true</ac:parameter><ac:parameter ac:name="columns">1,2</ac:parameter>
<ac:parameter ac:name="title">Produkcijske usluge</ac:parameter>
<ac:parameter ac:name="xLabel">Protokoli</ac:parameter><ac:parameter ac:name="yLabel">Broj usluga</ac:parameter>
<ac:rich-text-body><table><tbody><tr><th>Protokol</th><th>Ukupno</th></tr>
<tr><td>SAML (519)</td><td>519</td></tr><tr><td>CAS (33)</td><td>33</td></tr>
<tr><td>OIDC (84)</td><td>84</td></tr></tbody></table></ac:rich-text-body>
</ac:structured-macro>
XML;

        $result = (new ConfluenceHtmlConverter(new EditorHtmlChartService()))
            ->convert($body, 'DEMO', '10');

        self::assertStringContainsString('data-editor-html-chart="1"', $result->html);
        self::assertStringContainsString('data-chart-config=', $result->html);
        self::assertStringContainsString('Produkcijske usluge', $result->html);
        self::assertStringNotContainsString('<table>', $result->html);
        self::assertSame([], $result->unsupportedMacros);
    }

    /** HR: Čuva raspored, slike i poveznice, a izvorni TOC prepušta nativnom prikazu. EN: Preserves layout, images, and links while leaving the source TOC to the native view. */
    public function testPreservesPagePresentationDetails(): void
    {
        $body = <<<'XML'
<ac:layout><ac:layout-section ac:type="two_left_sidebar">
<ac:layout-cell><h2>Introduction</h2><p><ac:image ac:width="320" ac:height="180"><ri:attachment ri:filename="diagram.png" /></ac:image></p></ac:layout-cell>
<ac:layout-cell><h3>Details</h3><ac:structured-macro ac:name="toc" /><ac:link><ri:page ri:content-title="Target" /><ac:link-body><strong>Open target</strong></ac:link-body></ac:link></ac:layout-cell>
</ac:layout-section></ac:layout>
<p><ac:image><ri:url ri:value="https://wiki.example/download/thumbnails/10/remote.png?version=1" /></ac:image></p>
<ac:structured-macro ac:name="code"><ac:parameter ac:name="language">php</ac:parameter><ac:parameter ac:name="title">Example</ac:parameter><ac:plain-text-body><![CDATA[echo 'ok';]]></ac:plain-text-body></ac:structured-macro>
XML;

        $result = (new ConfluenceHtmlConverter())->convert($body, 'DEMO', '10');

        self::assertStringContainsString('<div class="w-100"><div class="row g-3">', $result->html);
        self::assertStringContainsString('col-12 col-lg-4', $result->html);
        self::assertStringContainsString('col-12 col-lg-8', $result->html);
        self::assertMatchesRegularExpression('/<img[^>]+width="320"[^>]+height="180"/', $result->html);
        self::assertStringNotContainsString('Table of Contents', $result->html);
        self::assertStringNotContainsString('Table of Contents', $result->html);
        self::assertStringContainsString('<h2>Introduction</h2>', $result->html);
        self::assertStringContainsString('<h3>Details</h3>', $result->html);
        self::assertStringContainsString('Open target', $result->html);
        self::assertStringContainsString('<figcaption class="fw-semibold mb-2">Example</figcaption>', $result->html);
        self::assertStringContainsString('class="language-php"', $result->html);
        self::assertSame('remote.png', $result->attachments[1]['filename']);
        self::assertSame([], $result->unsupportedMacros);
    }

    /** HR: Slika unutar Confluence poveznice ostaje vidljiva i klikabilna. EN: An image inside a Confluence link remains visible and clickable. */
    public function testPreservesLinkedAttachmentImage(): void
    {
        $body = <<<'XML'
<ac:structured-macro ac:name="panel"><ac:parameter ac:name="title">SUSTAV ZA WEBINARE</ac:parameter><ac:rich-text-body>
<p><ac:link><ri:page ri:content-title="Sustav za webinare" /><ac:link-body><ac:image ac:align="center" ac:title="Sustav za webinare" ac:thumbnail="true" ac:width="200"><ri:attachment ri:filename="05_PDO_webinar.png" /></ac:image></ac:link-body></ac:link></p>
</ac:rich-text-body></ac:structured-macro>
XML;

        $result = (new ConfluenceHtmlConverter())->convert($body, 'CEU', '133010769');

        self::assertMatchesRegularExpression(
            '/<a href="__SIMBIOZA_CONFLUENCE_LINK__[^\"]+"><img[^>]+src="__SIMBIOZA_CONFLUENCE_ATTACHMENT__[^\"]+"/',
            $result->html,
        );
        self::assertStringContainsString('alt="Sustav za webinare"', $result->html);
        self::assertStringContainsString('class="img-fluid d-block mx-auto"', $result->html);
        self::assertStringContainsString('width="200"', $result->html);
        self::assertSame('05_PDO_webinar.png', $result->attachments[0]['filename']);
        self::assertSame('133010769', $result->attachments[0]['source_page_id']);
        self::assertSame([], $result->unsupportedMacros);
    }

    /** HR: Expand postaje nativni accordion i čuva izvornu vrstu liste. EN: Expand becomes a native accordion and keeps the original list type. */
    public function testConvertsExpandMacroWithItsTitle(): void
    {
        $body = <<<'XML'
<ac:structured-macro ac:name="expand"><ac:parameter ac:name="title">Aktivnosti Srca na projektu</ac:parameter><ac:rich-text-body><p>Multiplier event</p><ul><li>Barcelona</li></ul></ac:rich-text-body></ac:structured-macro>
XML;

        $result = (new ConfluenceHtmlConverter())->convert($body, 'CEU', '10');

        self::assertStringContainsString('data-editor-html-accordion="1"', $result->html);
        self::assertStringContainsString('<summary class="editor-html-accordion__title">Aktivnosti Srca na projektu</summary>', $result->html);
        self::assertStringContainsString('<p>Multiplier event</p>', $result->html);
        self::assertStringContainsString('<ul><li>Barcelona</li></ul>', $result->html);
        self::assertStringNotContainsString('<ol>', $result->html);
        self::assertSame([], $result->unsupportedMacros);
    }

    /** HR: Samo uzastopni Expand makroi ulaze u istu accordion grupu. EN: Only consecutive Expand macros join the same accordion group. */
    public function testGroupsOnlyAdjacentExpandMacros(): void
    {
        $body = <<<'XML'
<ac:structured-macro ac:name="expand"><ac:parameter ac:name="title">Prvi</ac:parameter><ac:rich-text-body><p>A</p></ac:rich-text-body></ac:structured-macro>
<ac:structured-macro ac:name="expand"><ac:parameter ac:name="title">Drugi</ac:parameter><ac:rich-text-body><p>B</p></ac:rich-text-body></ac:structured-macro>
<p>Razdjelnik</p>
<ac:structured-macro ac:name="expand"><ac:parameter ac:name="title">Treći</ac:parameter><ac:rich-text-body><p>C</p></ac:rich-text-body></ac:structured-macro>
XML;

        $result = (new ConfluenceHtmlConverter())->convert($body, 'CEU', '10');

        self::assertSame(2, substr_count($result->html, 'data-editor-html-accordion="1"'));
        self::assertSame(3, substr_count($result->html, 'class="editor-html-accordion__item"'));
        self::assertStringNotContainsString(' name=', $result->html);
        self::assertLessThan(strpos($result->html, 'Razdjelnik'), strpos($result->html, 'Drugi'));
        self::assertLessThan(strpos($result->html, 'Treći'), strpos($result->html, 'Razdjelnik'));
    }

    /** HR: Stari Section/Column makroi postaju responsivne kartice koje čuvaju omjere. EN: Legacy Section/Column macros become responsive cards that retain their proportions. */
    public function testConvertsLegacySectionAndColumnsToResponsiveCards(): void
    {
        $body = <<<'XML'
<ac:structured-macro ac:name="section"><ac:parameter ac:name="border">true</ac:parameter><ac:rich-text-body>
<ac:structured-macro ac:name="column"><ac:parameter ac:name="width">60%</ac:parameter><ac:rich-text-body><p><strong>Lijevo</strong></p></ac:rich-text-body></ac:structured-macro>
<ac:structured-macro ac:name="column"><ac:parameter ac:name="width">40%</ac:parameter><ac:rich-text-body><p>Desno</p></ac:rich-text-body></ac:structured-macro>
</ac:rich-text-body></ac:structured-macro>
XML;

        $result = (new ConfluenceHtmlConverter())->convert($body, 'CEU', '10');

        self::assertStringContainsString('row g-3 mb-3', $result->html);
        self::assertStringContainsString('col-12 col-lg-7', $result->html);
        self::assertStringContainsString('col-12 col-lg-5', $result->html);
        self::assertSame(2, substr_count($result->html, '<section class="card h-100">'));
        self::assertSame([], $result->unsupportedMacros);
    }

    /** HR: Section bez širina ravnomjerno raspoređuje kartice i uklanja prazne pokazivače. EN: A section without widths distributes cards evenly and removes empty cursor paragraphs. */
    public function testDistributesColumnsWithoutWidthsAndRemovesEmptyCursorParagraphs(): void
    {
        $body = <<<'XML'
<ac:structured-macro ac:name="section"><ac:rich-text-body>
<ac:structured-macro ac:name="column"><ac:rich-text-body><p>Prvi</p></ac:rich-text-body></ac:structured-macro><p><br /></p>
<ac:structured-macro ac:name="column"><ac:rich-text-body><p>Drugi</p></ac:rich-text-body></ac:structured-macro>
</ac:rich-text-body></ac:structured-macro>
XML;

        $result = (new ConfluenceHtmlConverter())->convert($body, 'CEU', '10');

        self::assertSame(2, substr_count($result->html, 'col-12 col-lg-6'));
        self::assertStringNotContainsString('<p><br></p>', $result->html);
        self::assertSame([], $result->unsupportedMacros);
    }

    /** HR: HTML makro čuva siguran H5P iframe, ali ne i proizvoljnu skriptu. EN: An HTML macro keeps a safe H5P iframe but never arbitrary script markup. */
    public function testConvertsHtmlMacroToCanonicalSafeIframeEmbed(): void
    {
        $body = <<<'XML'
<ac:structured-macro ac:name="html"><ac:plain-text-body><![CDATA[<iframe src="https://h5p.org/h5p/embed/509150" width="1090" height="81" frameborder="0" allowfullscreen="allowfullscreen"></iframe><script src="https://h5p.org/sites/all/modules/h5p/library/js/h5p-resizer.js" charset="UTF-8"></script>]]></ac:plain-text-body></ac:structured-macro>
XML;

        $result = (new ConfluenceHtmlConverter())->convert($body, 'CEU', '10');

        self::assertStringContainsString('data-editor-html-iframe="1"', $result->html);
        self::assertStringContainsString('data-editor-html-h5p-resizer="1"', $result->html);
        self::assertStringContainsString('src="https://h5p.org/h5p/embed/509150"', $result->html);
        self::assertStringContainsString('width="100%"', $result->html);
        self::assertStringContainsString('height="81"', $result->html);
        self::assertStringContainsString('allowfullscreen="allowfullscreen"', $result->html);
        self::assertStringNotContainsString('<script', $result->html);
        self::assertSame([], $result->unsupportedMacros);
    }

    /** HR: Facebook iframe ne treba skriptu i čuva potrebne mogućnosti uz responzivnu širinu. EN: A Facebook iframe needs no script and retains required features with responsive width. */
    public function testConvertsFacebookIframeWithoutJavascript(): void
    {
        $body = <<<'XML'
<ac:structured-macro ac:name="html"><ac:plain-text-body><![CDATA[<iframe src="https://www.facebook.com/plugins/post.php?href=https%3A%2F%2Fwww.facebook.com%2Fexample%2Fposts%2F123&amp;show_text=true&amp;width=500" width="500" height="736" style="border:none;overflow:hidden" scrolling="no" frameborder="0" allowfullscreen="true" allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share"></iframe>]]></ac:plain-text-body></ac:structured-macro>
XML;

        $result = (new ConfluenceHtmlConverter())->convert($body, 'CEU', '10');

        self::assertStringContainsString('src="https://www.facebook.com/plugins/post.php?', $result->html);
        self::assertStringContainsString('width="100%"', $result->html);
        self::assertStringContainsString('height="736"', $result->html);
        self::assertStringContainsString('allowfullscreen="allowfullscreen"', $result->html);
        self::assertStringContainsString('autoplay; clipboard-write; encrypted-media;', $result->html);
        self::assertStringNotContainsString('scrolling=', $result->html);
        self::assertStringNotContainsString('style=', $result->html);
        self::assertSame([], $result->unsupportedMacros);
    }

    /** HR: Siguran HTML gumb postaje funkcionalna poveznica bez izvornog stila ili JS-a. EN: A safe HTML button becomes a functional link without source styling or JavaScript. */
    public function testConvertsSafeHtmlButtonLink(): void
    {
        $body = <<<'XML'
<ac:structured-macro ac:name="html"><ac:plain-text-body><![CDATA[<a href="https://www.srce.unizg.hr/ceu/tjedan-ceu-2024"><button class="aui-button" style="width: 320px" onclick="alert(1)">Povratak na web stranicu</button></a>]]></ac:plain-text-body></ac:structured-macro>
XML;

        $result = (new ConfluenceHtmlConverter())->convert($body, 'CEU', '194311723');

        self::assertStringContainsString(
            '<a class="btn btn-primary mb-3" href="https://www.srce.unizg.hr/ceu/tjedan-ceu-2024">Povratak na web stranicu</a>',
            $result->html,
        );
        self::assertStringNotContainsString('onclick=', $result->html);
        self::assertStringNotContainsString('style=', $result->html);
        self::assertStringNotContainsString('<button', $result->html);
        self::assertSame([], $result->unsupportedMacros);
    }

    /** HR: Nesigurna shema HTML gumba ostaje označena za ručni pregled. EN: An unsafe HTML button scheme remains marked for manual review. */
    public function testRejectsUnsafeHtmlButtonLink(): void
    {
        $body = <<<'XML'
<ac:structured-macro ac:name="html"><ac:plain-text-body><![CDATA[<a href="javascript:alert(1)"><button>Otvori</button></a>]]></ac:plain-text-body></ac:structured-macro>
XML;

        $result = (new ConfluenceHtmlConverter())->convert($body, 'CEU', '194311723');

        self::assertStringContainsString('Confluence makro: html', $result->html);
        self::assertSame(['html'], $result->unsupportedMacros);
    }

    /** HR: Prazan izvorni stupac ne smanjuje širinu stvarnoga sadržaja. EN: An empty source column does not narrow the real content. */
    public function testRemovesEmptyLayoutCellsAndUsesAvailableWidth(): void
    {
        $body = <<<'XML'
<ac:layout><ac:layout-section ac:type="two_equal">
<ac:layout-cell><p>Galerija i sadržaj</p><table><tbody><tr><td>Podatak</td></tr></tbody></table></ac:layout-cell>
<ac:layout-cell><p><br /></p></ac:layout-cell>
</ac:layout-section></ac:layout>
XML;

        $result = (new ConfluenceHtmlConverter())->convert($body, 'CEU', '163283311');

        self::assertStringContainsString('class="w-100"', $result->html);
        self::assertSame(1, substr_count($result->html, 'col-12 col-lg-12'), $result->html);
        self::assertStringNotContainsString('col-12 col-lg-6', $result->html);
        self::assertStringNotContainsString('<p><br></p>', $result->html);
        self::assertStringNotContainsString('confluence-import-', $result->html);
        self::assertSame([], $result->unsupportedMacros);
    }

    /** HR: Nepoznati JavaScript ostaje vidljiv u izvještaju umjesto tihog odbacivanja. EN: Unknown JavaScript remains visible in the report instead of being silently discarded. */
    public function testReportsHtmlMacroWithUnknownJavascript(): void
    {
        $body = <<<'XML'
<ac:structured-macro ac:name="html"><ac:plain-text-body><![CDATA[<iframe src="https://widgets.example/embed/42"></iframe><script src="https://widgets.example/required.js"></script>]]></ac:plain-text-body></ac:structured-macro>
XML;

        $result = (new ConfluenceHtmlConverter())->convert($body, 'CEU', '10');

        self::assertSame(['html'], $result->unsupportedMacros);
        self::assertStringContainsString('Confluence makro: html', $result->html);
        self::assertStringNotContainsString('data-editor-html-iframe="1"', $result->html);
        self::assertStringNotContainsString('<script', $result->html);
    }

    /** HR: Više iframeova u jednom HTML makrou ne gubi se djelomičnim uvozom. EN: Multiple iframes in one HTML macro are not silently reduced to a partial import. */
    public function testReportsHtmlMacroWithMultipleIframes(): void
    {
        $body = <<<'XML'
<ac:structured-macro ac:name="html"><ac:plain-text-body><![CDATA[<iframe src="https://widgets.example/embed/1"></iframe><iframe src="https://widgets.example/embed/2"></iframe>]]></ac:plain-text-body></ac:structured-macro>
XML;

        $result = (new ConfluenceHtmlConverter())->convert($body, 'CEU', '10');

        self::assertSame(['html'], $result->unsupportedMacros);
        self::assertStringContainsString('Confluence makro: html', $result->html);
        self::assertStringNotContainsString('data-editor-html-iframe="1"', $result->html);
    }

    /** HR: Confluence file-list makroi postaju običan statički HTML. EN: Confluence file-list macros become ordinary static HTML. */
    public function testConvertsFileListMacrosToOrdinaryStaticHtml(): void
    {
        $body = <<<'XML'
<ac:structured-macro ac:name="create-from-template">
<ac:parameter ac:name="blueprintModuleCompleteKey">com.atlassian.confluence.plugins.confluence-create-content-plugin:file-list-blueprint</ac:parameter>
<ac:parameter ac:name="createButtonLabel">Create a file list</ac:parameter>
</ac:structured-macro>
<ac:structured-macro ac:name="create-from-template">
<ac:parameter ac:name="blueprintModuleCompleteKey">com.atlassian.confluence.plugins.confluence-business-blueprints:meeting-notes-blueprint</ac:parameter>
<ac:parameter ac:name="createButtonLabel">Create meeting note</ac:parameter>
</ac:structured-macro>
<ac:structured-macro ac:name="content-report-table">
<ac:parameter ac:name="labels">file-list</ac:parameter>
<ac:parameter ac:name="blankTitle">File lists</ac:parameter>
</ac:structured-macro>
XML;
        $context = new ConfluenceMacroContext('10', [
            '10' => [
                'title' => 'File lists',
                'path' => '/workspace/demo/file-lists',
                'parent_id' => '',
                'sort_order' => 1,
                'labels' => [],
                'updated_at' => '2026-08-21 10:00:00',
            ],
            '11' => [
                'title' => 'Shared files',
                'path' => '/workspace/demo/shared-files',
                'parent_id' => '10',
                'sort_order' => 2,
                'labels' => ['file-list'],
                'updated_at' => '2026-08-21 11:00:00',
            ],
            '12' => [
                'title' => 'Ordinary page',
                'path' => '/workspace/demo/ordinary',
                'parent_id' => '10',
                'sort_order' => 3,
                'labels' => [],
                'updated_at' => '2026-08-21 12:00:00',
            ],
        ], []);
        $result = (new ConfluenceHtmlConverter())->convert($body, 'DEMO', '10', $context);

        self::assertStringNotContainsString('Create a file list', $result->html);
        self::assertStringNotContainsString('Create meeting note', $result->html);
        self::assertStringNotContainsString('data-workspace-', $result->html);
        self::assertStringContainsString(
            '<table class="table table-bordered table-striped table-hover">',
            $result->html,
        );
        self::assertStringContainsString('<thead class="table-light">', $result->html);
        self::assertStringContainsString('/workspace/demo/shared-files', $result->html);
        self::assertStringContainsString('Shared files', $result->html);
        self::assertStringNotContainsString('Ordinary page', $result->html);
        self::assertSame([], $result->unsupportedMacros);
    }

    /** HR: Blueprint izvještaj čuva autora i prikazuje zadnje izmijenjene stranice prve. EN: A blueprint report preserves the creator and lists most recently modified pages first. */
    public function testContentReportIncludesCreatorAndSortsNewestFirst(): void
    {
        $body = <<<'XML'
<ac:structured-macro ac:name="content-report-table"><ac:parameter ac:name="labels">meeting-notes</ac:parameter></ac:structured-macro>
XML;
        $context = new ConfluenceMacroContext('10', [
            '11' => [
                'title' => 'Starija bilješka',
                'path' => '/workspace/dabar/starija',
                'parent_id' => '',
                'sort_order' => 1,
                'labels' => ['meeting-notes'],
                'creator' => 'Stari autor',
                'updated_at' => '2026-01-01 09:00:00',
            ],
            '12' => [
                'title' => 'Novija bilješka',
                'path' => '/workspace/dabar/novija',
                'parent_id' => '',
                'sort_order' => 2,
                'labels' => ['meeting-notes'],
                'creator' => 'Novi autor',
                'updated_at' => '2026-04-02 10:00:00',
            ],
        ], []);

        $result = (new ConfluenceHtmlConverter())->convert($body, 'DABAR', '10', $context);

        self::assertStringContainsString('Autor', $result->html);
        self::assertStringContainsString('Novi autor', $result->html);
        self::assertStringContainsString('Stari autor', $result->html);
        self::assertLessThan(
            strpos($result->html, 'Starija bilješka'),
            strpos($result->html, 'Novija bilješka'),
        );
    }

    /** HR: SOKI makroi postaju nativni dinamički blokovi, a Page Properties strukturirani podaci. EN: SOKI macros become native dynamic blocks and Page Properties become structured data. */
    public function testConvertsSokiDashboardMacrosAndProperties(): void
    {
        $body = <<<'XML'
<ac:structured-macro ac:name="gallery"><ac:parameter ac:name="sort">date</ac:parameter></ac:structured-macro>
<ac:structured-macro ac:name="panel"><ac:parameter ac:name="title">Poslovi 2026.</ac:parameter><ac:rich-text-body>
<ac:structured-macro ac:name="detailssummary"><ac:parameter ac:name="cql">label = "2026" and space = currentSpace()</ac:parameter><ac:parameter ac:name="headings">Status, Summary, Owner</ac:parameter><ac:parameter ac:name="firstcolumn">Posao</ac:parameter><ac:parameter ac:name="sortBy">Status</ac:parameter><ac:parameter ac:name="reverseSort">true</ac:parameter><ac:parameter ac:name="pageSize">25</ac:parameter></ac:structured-macro>
</ac:rich-text-body></ac:structured-macro>
<ac:structured-macro ac:name="livesearch" />
<ac:structured-macro ac:name="recently-updated"><ac:parameter ac:name="max">7</ac:parameter></ac:structured-macro>
<ac:structured-macro ac:name="profile"><ac:parameter ac:name="user"><ri:user ri:userkey="user-key-1" /></ac:parameter></ac:structured-macro>
<ac:structured-macro ac:name="details"><ac:parameter ac:name="hidden">true</ac:parameter><ac:rich-text-body>
<table><tbody><tr><th>Status</th><td><ac:structured-macro ac:name="status"><ac:parameter ac:name="title">TRAJE</ac:parameter></ac:structured-macro></td></tr><tr><th>Summary</th><td>Opis posla</td></tr></tbody></table>
</ac:rich-text-body></ac:structured-macro>
XML;

        $context = new ConfluenceMacroContext(
            '143180505',
            [],
            [],
            ['user-key-1' => 'Test User'],
        );
        $result = (new ConfluenceHtmlConverter())->convert($body, 'SOKI', '143180505', $context);

        self::assertSame(4, substr_count($result->html, 'data-editor-html-workspace-block="1"'));
        self::assertStringContainsString('data-workspace-block-kind="attachment-gallery"', $result->html);
        self::assertStringContainsString('data-workspace-block-kind="page-report"', $result->html);
        self::assertStringContainsString('data-workspace-block-kind="workspace-search"', $result->html);
        self::assertStringContainsString('data-workspace-block-kind="recent-changes"', $result->html);
        self::assertStringContainsString('Poslovi 2026.', $result->html);
        self::assertStringContainsString('Test User', $result->html);
        self::assertMatchesRegularExpression('/data-workspace-block-config="([^"]+)"/', $result->html);
        preg_match('/data-workspace-block-kind="page-report"[^>]+data-workspace-block-config="([^"]+)"/', $result->html, $matches);
        $encoded = strtr($matches[1] ?? '', '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $configuration = json_decode((string)base64_decode($encoded, true), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('2026', $configuration['label']);
        self::assertSame(['Status', 'Summary', 'Owner'], $configuration['columns']);
        self::assertSame('Posao', $configuration['first_column']);
        self::assertSame('property:status', $configuration['sort']);
        self::assertSame('desc', $configuration['direction']);
        self::assertSame(25, $configuration['limit']);
        self::assertSame('status', $result->properties[0]['key']);
        self::assertSame('status', $result->properties[0]['type']);
        self::assertSame('TRAJE', $result->properties[0]['value']);
        self::assertSame('summary', $result->properties[1]['key']);
        self::assertSame('Opis posla', $result->properties[1]['value']);
        self::assertSame([], $result->unsupportedMacros);
    }

    /** HR: SOKI horizontalna Page Properties tablica zadržava sva četiri polja. EN: A horizontal SOKI Page Properties table preserves all four fields. */
    public function testConvertsHorizontalSokiPageProperties(): void
    {
        $body = <<<'XML'
<ac:structured-macro ac:name="details"><ac:parameter ac:name="hidden">true</ac:parameter><ac:rich-text-body>
<table><tbody>
<tr><th>Summary</th><th>Status</th><th>Owner</th><th>Iz tima SOKI</th></tr>
<tr><td>UX, UI</td><td><ac:structured-macro ac:name="status"><ac:parameter ac:name="title">TRAJE</ac:parameter></ac:structured-macro></td><td>Draženko</td><td>Aleks, Jasmina</td></tr>
</tbody></table>
</ac:rich-text-body></ac:structured-macro>
XML;

        $result = (new ConfluenceHtmlConverter())->convert($body, 'SOKI', '143180505');

        self::assertSame(['summary', 'status', 'owner', 'iz-tima-soki'], array_column($result->properties, 'key'));
        self::assertSame(['UX, UI', 'TRAJE', 'Draženko', 'Aleks, Jasmina'], array_column($result->properties, 'value'));
        self::assertSame('status', $result->properties[1]['type']);
    }

    /** HR: Korisničke reference u Page Properties tablici koriste mapirano ime umjesto prazne poveznice. EN: User references in Page Properties use the mapped name instead of an empty link. */
    public function testResolvesUsersInsideSokiPageProperties(): void
    {
        $body = <<<'XML'
<ac:structured-macro ac:name="details"><ac:parameter ac:name="hidden">true</ac:parameter><ac:rich-text-body>
<table><tbody>
<tr><th>Summary</th><th>Owner</th><th>Iz tima SOKI</th></tr>
<tr><td>Održavanje sustava</td><td><ac:link><ri:user ri:userkey="owner-key" /></ac:link></td><td><ac:link><ri:user ri:userkey="member-key" /></ac:link>, Jasmina</td></tr>
</tbody></table>
</ac:rich-text-body></ac:structured-macro>
XML;
        $context = new ConfluenceMacroContext(
            '143180505',
            [],
            [],
            ['owner-key' => 'Aleksandar Tomić', 'member-key' => 'Mirna Granatir'],
        );

        $result = (new ConfluenceHtmlConverter())->convert($body, 'SOKI', '143180505', $context);

        self::assertSame(
            ['Održavanje sustava', 'Aleksandar Tomić', 'Mirna Granatir, Jasmina'],
            array_column($result->properties, 'value'),
        );
        self::assertStringNotContainsString('Poveznica', implode(' ', array_column($result->properties, 'value')));
    }

    /** HR: Poslužiteljski dodaci bez posebne lokalne logike postaju običan HTML. EN: Server-side add-ons without distinct local logic become ordinary HTML. */
    public function testConvertsFileViewerTableEnhancerAndPageTreeSearchWithoutUnsupportedBoxes(): void
    {
        $body = <<<'XML'
<ac:structured-macro ac:name="view-file"><ac:parameter ac:name="name"><ri:attachment ri:filename="plan.docx" /></ac:parameter></ac:structured-macro>
<ac:structured-macro ac:name="tableenhancer"><ac:rich-text-body><table><tbody><tr><th>Naziv</th></tr><tr><td>Vrijednost</td></tr></tbody></table></ac:rich-text-body></ac:structured-macro>
<ac:structured-macro ac:name="pagetreesearch" />
XML;
        $context = new ConfluenceMacroContext('10', [], ['plan.docx' => '/asset/plan']);

        $result = (new ConfluenceHtmlConverter())->convert($body, 'SOKI', '10', $context);

        self::assertStringContainsString('<a href="/asset/plan">plan.docx</a>', $result->html);
        self::assertStringContainsString(
            '<div class="table-responsive"><table class="table table-bordered table-striped table-hover">',
            $result->html,
        );
        self::assertSame(1, substr_count($result->html, 'class="table-responsive"'));
        self::assertStringContainsString('data-workspace-block-kind="workspace-search"', $result->html);
        self::assertSame([], $result->unsupportedMacros);
    }

    /** HR: Obične Confluence tablice dobivaju Editorov tematski i responzivni HTML ugovor. EN: Ordinary Confluence tables receive Editor's themed responsive HTML contract. */
    public function testNormalizesOrdinaryTablesToEditorMarkup(): void
    {
        $body = <<<'XML'
<table class="confluenceTable"><tbody>
<tr><th>Naslov</th><th>Vrijednost</th></tr>
<tr><td>Prvi redak</td><td>42</td></tr>
</tbody></table>
XML;

        $result = (new ConfluenceHtmlConverter())->convert($body, 'SOKI', '10');

        self::assertStringContainsString('<div class="table-responsive">', $result->html);
        self::assertStringContainsString(
            '<table class="table table-bordered table-striped table-hover confluenceTable">',
            $result->html,
        );
        self::assertStringContainsString('<thead class="table-light"><tr><th>Naslov</th>', $result->html);
        self::assertStringContainsString('<tr><td>Prvi redak</td><td>42</td></tr>', $result->html);
    }

    /** HR: Roadmap Planner postaje uređivi vremenski plan, a podržani widgeti siguran sadržaj. EN: Roadmap Planner becomes an editable timeline and supported widgets become safe content. */
    public function testConvertsRoadmapAndSupportedWidgets(): void
    {
        $source = rawurlencode(json_encode([
            'title' => 'Plan razvoja',
            'timeline' => [
                'startDate' => '2026-01-01 00:00:00',
                'endDate' => '2026-12-31 00:00:00',
                'displayOption' => 'MONTH',
            ],
            'lanes' => [[
                'title' => 'Razvoj',
                'color' => ['lane' => '#e9ecef', 'bar' => '#0d6efd', 'text' => '#ffffff'],
                'bars' => [[
                    'id' => 'release-a',
                    'title' => 'Izdanje A',
                    'description' => 'Prvo izdanje',
                    'startDate' => '2026-02-01 00:00:00',
                    'duration' => 2,
                    'rowIndex' => 0,
                ]],
            ]],
            'markers' => [['title' => 'Rok', 'markerDate' => '2026-04-01 00:00:00']],
        ], JSON_THROW_ON_ERROR));
        $body = '<ac:structured-macro ac:name="roadmap"><ac:parameter ac:name="source">'
            . $source . '</ac:parameter></ac:structured-macro>'
            . '<ac:structured-macro ac:name="widget"><ac:parameter ac:name="url">'
            . '<ri:url ri:value="https://www.youtube.com/watch?v=dQw4w9WgXcQ&amp;t=98s" />'
            . '</ac:parameter></ac:structured-macro>'
            . '<ac:structured-macro ac:name="widget"><ac:parameter ac:name="url">'
            . '<ri:url ri:value="https://www.figma.com/proto/demo" />'
            . '</ac:parameter></ac:structured-macro>';

        $result = (new ConfluenceHtmlConverter(
            new EditorHtmlChartService(),
            new EditorHtmlRoadmapService(),
        ))->convert($body, 'SOKI', '10');

        self::assertStringContainsString('data-editor-html-roadmap="1"', $result->html);
        self::assertStringContainsString('youtube-nocookie.com/embed/dQw4w9WgXcQ?start=98', $result->html);
        self::assertStringContainsString('https://www.figma.com/proto/demo', $result->html);
        self::assertSame([], $result->unsupportedMacros);
    }
}
