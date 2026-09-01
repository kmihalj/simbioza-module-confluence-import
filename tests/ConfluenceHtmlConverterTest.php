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
        self::assertStringContainsString('confluence-task-list', $result->html);
        self::assertMatchesRegularExpression('/<input[^>]+checked(?:="checked")?[^>]*>/', $result->html);
        self::assertSame(1, substr_count($result->html, 'Done'));
        self::assertStringContainsString('<pre class="confluence-import-code"><code>echo "ok";</code></pre>', $result->html);
        self::assertSame('42', $result->links[0]['destination_page_id']);
        self::assertSame('part-1', $result->links[0]['fragment']);
        self::assertSame('manual.pdf', $result->attachments[0]['filename']);
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

        self::assertStringContainsString('confluence-import-children', $result->html);
        self::assertStringContainsString('/workspace/demo/grandchild', $result->html);
        self::assertStringContainsString('confluence-import-attachment-list', $result->html);
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

        self::assertStringContainsString('confluence-import-layout', $result->html);
        self::assertStringContainsString('col-12 col-lg-4', $result->html);
        self::assertStringContainsString('col-12 col-lg-8', $result->html);
        self::assertMatchesRegularExpression('/<img[^>]+width="320"[^>]+height="180"/', $result->html);
        self::assertStringNotContainsString('confluence-import-toc', $result->html);
        self::assertStringNotContainsString('Table of Contents', $result->html);
        self::assertStringContainsString('<h2>Introduction</h2>', $result->html);
        self::assertStringContainsString('<h3>Details</h3>', $result->html);
        self::assertStringContainsString('Open target', $result->html);
        self::assertStringContainsString('<figcaption class="fw-semibold mb-2">Example</figcaption>', $result->html);
        self::assertStringContainsString('class="language-php"', $result->html);
        self::assertSame('remote.png', $result->attachments[1]['filename']);
        self::assertSame([], $result->unsupportedMacros);
    }

    /** HR: Confluence file-list makroi postaju običan statički HTML. EN: Confluence file-list macros become ordinary static HTML. */
    public function testConvertsFileListMacrosToOrdinaryStaticHtml(): void
    {
        $body = <<<'XML'
<ac:structured-macro ac:name="create-from-template">
<ac:parameter ac:name="blueprintModuleCompleteKey">com.atlassian.confluence.plugins.confluence-create-content-plugin:file-list-blueprint</ac:parameter>
<ac:parameter ac:name="createButtonLabel">Create a file list</ac:parameter>
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
