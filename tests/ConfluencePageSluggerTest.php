<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluencePageSlugger;
use PHPUnit\Framework\TestCase;

/** HR: Provjerava ograničenje i jedinstvenost URL slugova. EN: Verifies URL slug limits and uniqueness. */
final class ConfluencePageSluggerTest extends TestCase
{
    /** HR: Dugi DABAR naslov ostaje čitljiv i stane u shemu. EN: A long DABAR title remains readable and fits the schema. */
    public function testShortensLongDabarSlugAtWordBoundary(): void
    {
        $slug = '2024-09-19-sastanak-urednika-repozitorija-koji-pohranjuju-multimedijalne-objekte-na-temu-revidiranja-metapodatkovnih-opisa-multimedijskih-objekata';

        $short = (new ConfluencePageSlugger())->shorten($slug);

        self::assertSame(
            '2024-09-19-sastanak-urednika-repozitorija-koji-pohranjuju-multimedijalne-objekte-na-temu-revidiranja-metapodatkovnih-opisa',
            $short,
        );
        self::assertLessThanOrEqual(ConfluencePageSlugger::MAX_LENGTH, strlen($short));
    }

    /** HR: Nastavak za sudar također ostaje unutar 128 znakova. EN: A collision suffix also remains within 128 characters. */
    public function testKeepsCollisionSuffixWithinSchemaLimit(): void
    {
        $slugger = new ConfluencePageSlugger();
        $used = [];
        $base = str_repeat('very-long-segment-', 12);

        $first = $slugger->unique($base, 'page-1', $used);
        $second = $slugger->unique($base, 'page-2', $used);
        $third = $slugger->unique($base, 'page-3', $used);

        self::assertLessThanOrEqual(ConfluencePageSlugger::MAX_LENGTH, strlen($first));
        self::assertLessThanOrEqual(ConfluencePageSlugger::MAX_LENGTH, strlen($second));
        self::assertLessThanOrEqual(ConfluencePageSlugger::MAX_LENGTH, strlen($third));
        self::assertStringEndsWith('-2', $second);
        self::assertStringEndsWith('-3', $third);
        self::assertCount(3, array_unique([$first, $second, $third]));
    }

    /** HR: Slug bez riječi sigurno se reže na tvrdo ograničenje. EN: A slug without words is safely cut at the hard limit. */
    public function testHardCutsSingleSegmentAndUsesFallback(): void
    {
        $slugger = new ConfluencePageSlugger();
        $used = [];

        self::assertSame(str_repeat('a', 128), $slugger->shorten(str_repeat('a', 150)));
        self::assertSame('page-42', $slugger->unique('', 'page-42', $used));
    }

    /** HR: Čista instalacija može izraditi slug servis iz modulske konfiguracije. EN: A clean installation can build the slug service from module configuration. */
    public function testModuleConfigurationRegistersSlugger(): void
    {
        $services = require dirname(__DIR__) . '/config/services.php';

        self::assertIsArray($services);
        self::assertArrayHasKey(ConfluencePageSlugger::class, $services);
        self::assertInstanceOf(ConfluencePageSlugger::class, $services[ConfluencePageSlugger::class]());
    }
}
