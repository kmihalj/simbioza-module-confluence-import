<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceHtmlConverter;
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
        self::assertStringContainsString('<pre><code>echo "ok";</code></pre>', $result->html);
        self::assertSame('42', $result->links[0]['destination_page_id']);
        self::assertSame('part-1', $result->links[0]['fragment']);
        self::assertSame('manual.pdf', $result->attachments[0]['filename']);
        self::assertSame([], $result->unsupportedMacros);
    }
}
