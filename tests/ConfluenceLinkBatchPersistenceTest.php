<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleConfluenceImport\ModuleSimbiozaConfluenceImport;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportRepository;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfluenceImportRepository::class)]
final class ConfluenceLinkBatchPersistenceTest extends TestCase
{
    /** HR: Velika stranica sprema sve poveznice i preko SQLite granice parametara. EN: A large page persists every link beyond SQLite's parameter limit. */
    public function testManyLinksArePersistedInBoundedBatches(): void
    {
        [$repository, $database] = $this->environment();
        $links = [];
        for ($index = 1; $index <= 175; ++$index) {
            $links[] = [
                'uuid' => $repository->newLinkUuid(),
                'source_page_id' => 'page-1',
                'source_space_key' => 'DOCS',
                'destination_space_key' => 'DOCS',
                'destination_page_id' => 'page-' . ($index + 1),
                'destination_page_title' => 'Page ' . ($index + 1),
                'original_target' => '/pages/' . ($index + 1),
                'resolved_target' => '/workspace/docs/page-' . ($index + 1),
                'status' => 'resolved',
            ];
        }

        $repository->recordLinks($links, 7);

        self::assertSame(
            175,
            count($database->table(ModuleSimbiozaConfluenceImport::TABLE_LINKS)
                ->where('job_id', '=', 7)
                ->get()),
        );
    }

    /** @return array{ConfluenceImportRepository,Database} */
    private function environment(): array
    {
        $helper = new Helper();
        $database = new Database(new Config($helper, [
            'database' => ['connections' => ['default' => ['driver' => 'sqlite', 'database' => ':memory:']]],
        ]), $helper);
        $migration = require dirname(__DIR__) . '/resources/migrations/initial_simbioza_confluence_import_schema.php';
        $migration->up($database);

        return [new ConfluenceImportRepository($database), $database];
    }
}
