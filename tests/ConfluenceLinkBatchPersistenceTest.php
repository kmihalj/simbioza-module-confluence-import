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

    /** HR: Ponovno usklađivanje obuhvaća i ranije razriješene veze. EN: Reconciliation includes links that were resolved previously. */
    public function testAllLinksCanBeRecheckedAndInvalidated(): void
    {
        [$repository] = $this->environment();
        $uuid = $repository->recordLink([
            'source_page_id' => 'source',
            'source_space_key' => 'SOURCE',
            'destination_space_key' => 'TARGET',
            'destination_page_id' => 'target',
            'resolved_target' => '/workspace/target/page',
            'status' => 'resolved',
        ], 1);

        $links = $repository->linksForReconciliation('TARGET');
        self::assertCount(1, $links);
        self::assertSame($uuid, $links[0]['uuid']);

        $repository->updateLinkResolution((int)$links[0]['id'], null);
        $updated = $repository->linkByUuid($uuid);
        self::assertSame('unresolved', $updated['status']);
        self::assertNull($updated['resolved_target']);
    }

    /** HR: Ključevi područja nisu osjetljivi na velika slova, a korijen vodi na uvezenu naslovnicu. EN: Space keys are case-insensitive and a root reference resolves to the imported homepage. */
    public function testSpaceAndHomepageMappingsAreCaseInsensitive(): void
    {
        [$repository, $database] = $this->environment();
        $now = gmdate('Y-m-d H:i:s');
        $database->table(ModuleSimbiozaConfluenceImport::TABLE_SPACES)->insert([
            'source_instance' => 'archive',
            'source_space_id' => '7',
            'source_space_key' => 'TARGET',
            'source_space_type' => 'global',
            'source_space_name' => 'Target',
            'target_workspace_id' => 12,
            'target_workspace_slug' => 'target',
            'job_id' => 4,
            'source_metadata_json' => json_encode(['home_page_id' => '99'], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $database->table(ModuleSimbiozaConfluenceImport::TABLE_CONTENT)->insert([
            'source_content_id' => '99',
            'logical_source_id' => '99',
            'source_space_key' => 'TARGET',
            'source_type' => 'page',
            'source_status' => 'current',
            'source_version' => 1,
            'source_title' => 'Home',
            'target_workspace_id' => 12,
            'target_node_id' => 21,
            'target_document_key' => 'doc-home',
            'target_slug' => 'home',
            'import_status' => 'imported',
            'job_id' => 4,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::assertSame(12, (int)($repository->spaceBySourceKey('target')['target_workspace_id'] ?? 0));
        self::assertSame('home', $repository->contentBySource('target', '99')['target_slug'] ?? null);
        self::assertSame('home', $repository->homepageContentBySpaceKey('target')['target_slug'] ?? null);
    }

    /** HR: Izvještaj dobiva samo aktualne nerazriješene poveznice svojega importa. EN: A report receives only its import's currently unresolved links. */
    public function testReportReadsOnlyCurrentUnresolvedLinks(): void
    {
        [$repository] = $this->environment();
        $repository->recordLink([
            'source_page_id' => 'source',
            'source_space_key' => 'SOURCE',
            'original_target' => 'https://wiki.example/x/MgL7Aw',
            'status' => 'unresolved',
        ], 5);
        $repository->recordLink([
            'source_page_id' => 'source',
            'source_space_key' => 'SOURCE',
            'original_target' => 'https://wiki.example/display/TARGET/Page',
            'resolved_target' => '/workspace/target/page',
            'status' => 'resolved',
        ], 5);
        $repository->recordLink([
            'source_page_id' => 'other',
            'source_space_key' => 'OTHER',
            'original_target' => 'https://wiki.example/x/qAD7Aw',
            'status' => 'unresolved',
        ], 6);

        $links = $repository->unresolvedLinksForJob(5);
        self::assertCount(1, $links);
        self::assertSame('https://wiki.example/x/MgL7Aw', $links[0]['original_target']);
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
