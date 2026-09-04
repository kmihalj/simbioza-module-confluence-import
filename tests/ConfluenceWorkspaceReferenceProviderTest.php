<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportRepository;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceWorkspaceReferenceProvider;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfluenceWorkspaceReferenceProvider::class)]
#[UsesClass(ConfluenceImportRepository::class)]
final class ConfluenceWorkspaceReferenceProviderTest extends TestCase
{
    /** HR: Ključ ostaje neriješen do stvarnog uvoza ciljnog područja. EN: A key remains unresolved until the target Workspace is actually imported. */
    public function testReferenceStartsResolvingAfterSpaceMappingIsStored(): void
    {
        $helper = new Helper();
        $database = new Database(new Config($helper, [
            'database' => ['connections' => ['default' => ['driver' => 'sqlite', 'database' => ':memory:']]],
        ]), $helper);
        $migration = require dirname(__DIR__) . '/resources/migrations/initial_simbioza_confluence_import_schema.php';
        $migration->up($database);
        $repository = new ConfluenceImportRepository($database);
        $provider = new ConfluenceWorkspaceReferenceProvider($repository);

        self::assertSame('confluence', $provider->provider());
        self::assertNull($provider->resolve('AAIUPUTE'));

        $repository->mapSpace(
            [
                'source_id' => '59867161',
                'source_key' => 'AAIUPUTE',
                'name' => 'AAI EduHr upute',
                'type' => 'global',
            ],
            ['id' => 17, 'slug' => 'aaiupute'],
            1,
        );

        self::assertSame(
            ['slug' => 'aaiupute', 'title' => 'AAI EduHr upute'],
            $provider->resolve('AAIUPUTE'),
        );
    }
}
