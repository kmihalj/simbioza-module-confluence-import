<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceXmlInventoryFramer;
use PHPUnit\Framework\TestCase;

final class ConfluenceXmlInventoryFramerTest extends TestCase
{
    /**
     * EN: Literal markup, comments, UTF-8 and every small chunk boundary preserve real records only.
     *
     * HR: Doslovni markup, komentari, UTF-8 i svaka mala granica dijela čuvaju samo stvarne zapise.
     */
    public function testLiteralObjectsNeverBecomeInventoryRecords(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><hibernate-generic>'
            . '<!-- <object class="Fake"></object> -->'
            . '<object class="BodyContent"><property name="body"><![CDATA['
            . '<object class="Fake"></object><!DOCTYPE fake></hibernate-generic>'
            . ']]></property></object>'
            . '<object package="demo" class="Space"><id name="id">10</id>'
            . '<property name="name"><![CDATA[Članovi & područja]]></property></object>'
            . '<object class="Secrets"><property name="value">never-retain-me</property></object>'
            . '</hibernate-generic>';
        foreach ([1, 2, 3, 7, 8, 9, 16, 31, 64, 1024] as $size) {
            $frames = iterator_to_array((new ConfluenceXmlInventoryFramer(4096))->frames(str_split($xml, $size)));
            self::assertSame(['BodyContent', 'Space', 'Secrets'], array_column($frames, 'class'));
            self::assertNull($frames[0]['xml']);
            self::assertStringContainsString('Članovi & područja', $frames[1]['xml']);
            self::assertNull($frames[2]['xml']);
        }
    }

    /**
     * EN: A large skipped body does not consume the selected metadata allowance.
     *
     * HR: Veliko preskočeno tijelo ne troši dopuštenu veličinu odabranih metapodataka.
     */
    public function testSkippedPayloadIsNotBuffered(): void
    {
        $chunks = (static function (): \Generator {
            yield '<hibernate-generic><object class="BodyContent"><property name="body"><![CDATA[';
            for ($i = 0; $i < 100; ++$i) {
                yield str_repeat('body-data ', 10000);
            }
            yield ']]></property></object><object class="Space"/></hibernate-generic>';
        })();
        $frames = iterator_to_array((new ConfluenceXmlInventoryFramer(100))->frames($chunks));
        self::assertCount(2, $frames);
        self::assertNull($frames[0]['xml']);
        self::assertSame('<object class="Space"/>', $frames[1]['xml']);
    }

    /**
     * EN: Invalid envelopes and declarations fail without parsing external entities.
     *
     * HR: Nevaljane omotnice i deklaracije odbijaju se bez parsiranja vanjskih entiteta.
     */
    public function testRejectsMalformedAndEntityBearingEnvelopes(): void
    {
        foreach (
            [
            '<!DOCTYPE hibernate-generic [<!ENTITY e SYSTEM "file:///etc/passwd">]><hibernate-generic/>',
            '<other><object class="Space"/></other>',
            '<hibernate-generic><object class="Space">',
            '<hibernate-generic><object class="BodyContent"><![CDATA[unterminated',
            '<hibernate-generic><object class="Secrets"><!DOCTYPE bad></object></hibernate-generic>',
            '<hibernate-generic><object class="Space"><object class="Space"></object></object></hibernate-generic>',
            '<hibernate-generic></hibernate-generic><object class="Space"/>',
            ] as $xml
        ) {
            try {
                iterator_to_array((new ConfluenceXmlInventoryFramer(4096))->frames(str_split($xml, 7)));
                self::fail('Invalid XML envelope was accepted.');
            } catch (ConfluenceImportException $error) {
                self::assertStringNotContainsString('/etc/passwd', $error->getMessage());
            }
        }
    }

    /**
     * EN: Selected oversized metadata fails before it can become a job summary.
     *
     * HR: Preveliki odabrani metapodaci odbijaju se prije spremanja u sažetak posla.
     */
    public function testSelectedObjectLimit(): void
    {
        $this->expectException(ConfluenceImportException::class);
        iterator_to_array((new ConfluenceXmlInventoryFramer(100))->frames([
            '<hibernate-generic><object class="Space"><property name="name">' . str_repeat('x', 300) . '</property></object></hibernate-generic>',
        ]));
    }
}
