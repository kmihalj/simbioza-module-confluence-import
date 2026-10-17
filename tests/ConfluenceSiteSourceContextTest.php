<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportRepository;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteAccessService;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\TestCase;

/**
 * EN: Keeps equal source IDs in different sites isolated without changing ordinary space imports.
 *
 * HR: Odvaja jednake izvorne ID-jeve različitih siteova bez promjene običnog uvoza područja.
 */
final class ConfluenceSiteSourceContextTest extends TestCase
{
    private ConfluenceImportRepository $repository;

    protected function setUp(): void
    {
        $helper = new Helper();
        $database = new Database(new Config($helper, ['database' => ['connections' => ['default' => ['driver' => 'sqlite', 'database' => ':memory:']]]]), $helper);
        (require __DIR__ . '/../resources/migrations/initial_simbioza_confluence_import_schema.php')->up($database);
        $this->repository = new ConfluenceImportRepository($database);
    }

    /**
     * EN: The same keys and numeric IDs can map independently in two sites and the standalone flow.
     *
     * HR: Isti ključevi i brojčani ID-jevi neovisno se mapiraju u dva sitea i samostalnom tijeku.
     */
    public function testSourceIsolationAndContextRestoration(): void
    {
        $this->map('standalone', 1);
        foreach (['https://one.test' => 2, 'https://two.test' => 3] as $url => $id) {
            $this->repository->withSiteContext($url, [], function () use ($id): void {
                self::assertNull($this->repository->contentByAnySourceId('10'));
                $this->map('site-' . $id, $id);
                self::assertSame($id, (int)$this->repository->contentBySource('AREA', '10')['target_workspace_id']);
                self::assertSame($id, (int)$this->repository->contentByAnySourceId('10')['target_workspace_id']);
            });
        }
        self::assertSame(1, (int)$this->repository->contentByAnySourceId('10')['target_workspace_id']);
        $this->repository->withSiteContext('https://one.test', [], function (): void {
            self::assertSame(2, (int)$this->repository->spaceBySourceKey('AREA')['target_workspace_id']);
            self::assertSame(['AREA' => 'site-2'], $this->repository->workspaceSlugsBySourceKey());
        });
        self::assertSame(1, (int)$this->repository->spaceBySourceId('1')['target_workspace_id']);
    }

    /**
     * EN: C2 author aliases and explicitly mapped directory groups are reused without writing new identities.
     *
     * HR: C2 aliasi autora i izričito mapirane grupe imenika koriste se bez upisa novih identiteta.
     */
    public function testConfirmedIdentityContextAndFailureRestoration(): void
    {
        $key = ConfluenceSiteAccessService::mappingKey('https://one.test', 'users', 'confluence-user:user-a');
        $this->repository->mapIdentity(['source_key' => $key], 12, true, 1);
        $this->repository->mapIdentity(['source_key' => 'user-a'], 13, true, 1);
        try {
            $this->repository->withSiteContext('https://one.test', ['staff' => 14], function (): never {
                self::assertSame(12, $this->repository->mappedUserId('user-a'));
                self::assertSame(14, $this->repository->mappedGroupId('staff'));
                self::assertNull($this->repository->mappedUserId('unknown'));
                throw new \RuntimeException('Fixture interruption');
            });
        } catch (\RuntimeException) {
            self::assertSame(13, $this->repository->mappedUserId('user-a'));
            self::assertNull($this->repository->mappedGroupId('staff'));
        }
    }

    /**
     * EN: Reconciliation cannot accidentally resolve another site's links with the same IDs.
     *
     * HR: Usklađivanje ne smije slučajno razriješiti poveznice drugog sitea s istim ID-jevima.
     */
    public function testLinkReconciliationStaysInItsSourceNamespace(): void
    {
        foreach (['https://one.test', 'https://two.test'] as $url) {
            $this->repository->withSiteContext($url, [], function (): void {
                $this->repository->recordLink(['source_page_id' => '10', 'source_space_key' => 'A', 'destination_space_key' => 'B', 'destination_page_id' => '20'], 1);
                self::assertCount(1, $this->repository->linksForReconciliationBatch('B', 0, 100));
                self::assertSame(1, $this->repository->countLinksForReconciliation('B'));
            });
        }
        self::assertCount(0, $this->repository->linksForReconciliationBatch('B', 0, 100));
    }

    /**
     * EN: Copy aliases resolve sibling copies, never capture the canonical pages or their incoming links.
     *
     * HR: Aliasi kopija razrješavaju pripadajuće kopije, ne preuzimaju glavne stranice ni njihove dolazne poveznice.
     */
    public function testCopyAliasesIsolateMappingsAndReconciliation(): void
    {
        $url = 'https://copy.test';
        $this->repository->withSiteContext($url, [], function (): void {
            $this->map('original', 1);
            $this->repository->recordLink(['source_page_id' => '10', 'source_space_key' => 'AREA', 'destination_space_key' => 'AREA', 'destination_page_id' => '10'], 1);
        });
        $this->repository->withSiteContext($url, [], function (): void {
            self::assertNull($this->repository->contentByAnySourceId('10'));
            $this->map('copy', 2);
            self::assertSame(['AREA' => 'copy'], $this->repository->workspaceSlugsBySourceKey());
            self::assertSame(2, (int)$this->repository->contentByAnySourceId('10')['target_workspace_id']);
            self::assertCount(0, $this->repository->linksForReconciliationBatch('AREA', 0, 100));
            $this->repository->recordLink(['source_page_id' => '10', 'source_space_key' => 'AREA', 'destination_space_key' => 'AREA', 'destination_page_id' => '10'], 2);
            self::assertCount(1, $this->repository->linksForReconciliationBatch('AREA', 0, 100));
        }, ['AREA' => 'AREA#copy-2']);
        $this->repository->withSiteContext($url, [], function (): void {
            self::assertSame(1, (int)$this->repository->contentBySource('AREA', '10')['target_workspace_id']);
        });
    }

    /**
     * EN: A verified earlier space import participates in both directions, but unknown/foreign origins do not.
     * Explicit stored namespace references also remain usable outside an import worker.
     *
     * HR: Potvrđeni raniji uvoz područja sudjeluje u oba smjera, ali nepoznati/tuđi izvori ne sudjeluju.
     * Izričite spremljene reference s izvornim prostorom ostaju uporabive i izvan radnika importa.
     */
    public function testVerifiedStandaloneReferencesBridgeWithoutMovingMappings(): void
    {
        $job = $this->repository->createUploadJob('space.zip', '/fixture.zip', 1, 0, 1, time() + 3600);
        $this->repository->updateJob((int)$job['id'], ['status' => 'completed', 'operation' => 'import',
            'options_json' => json_encode(['source_base_url' => 'https://one.test/'])]);
        $this->map('standalone', 1);
        $this->repository->recordLink(['source_page_id' => '10', 'source_space_key' => 'AREA', 'destination_space_key' => 'NEXT', 'destination_page_id' => '20'], (int)$job['id']);
        $this->repository->withSiteContext('https://one.test', [], function () use ($job): void {
            self::assertSame(1, (int)$this->repository->contentBySource('AREA', '10')['target_workspace_id']);
            self::assertSame(1, (int)$this->repository->contentByTitle('AREA', 'Home')['target_workspace_id']);
            self::assertSame(1, (int)$this->repository->contentByAnySourceId('10')['target_workspace_id']);
            self::assertSame(['AREA' => 'standalone'], $this->repository->workspaceSlugsBySourceKey());
            self::assertCount(1, $this->repository->linksForReconciliationBatch('NEXT', 0, 100));
            self::assertSame(1, $this->repository->countLinksForReconciliation('NEXT'));
            self::assertSame($job['uuid'], $this->repository->nextSourceSpaceReport('https://one.test', 1, 0)['uuid']);
            $this->repository->mapSpace(['source_id' => '2', 'source_key' => 'NEXT'], ['id' => 2, 'slug' => 'next'], (int)$job['id']);
            $this->repository->mapContent(['source_id' => '20', 'title' => 'Next'], ['source_space_key' => 'NEXT', 'workspace_id' => 2, 'slug' => 'next'], (int)$job['id']);
            self::assertArrayHasKey('2', $this->repository->spacesBySourceIds(['2']));
        });
        $this->repository->withReferenceContext('https://one.test', function (): void {
            self::assertSame(2, (int)$this->repository->contentBySource('NEXT', '20')['target_workspace_id']);
            self::assertSame(2, (int)$this->repository->contentByAnySourceId('20')['target_workspace_id']);
        });
        $this->repository->withSiteContext('https://two.test', [], function (): void {
            self::assertNull($this->repository->contentBySource('AREA', '10'));
            self::assertNull($this->repository->contentByAnySourceId('10'));
            self::assertSame([], $this->repository->workspaceSlugsBySourceKey());
            self::assertSame(0, $this->repository->countLinksForReconciliation('NEXT'));
        });
        $this->repository->withSiteContext('https://one.test', [], function (): void {
            self::assertNull($this->repository->contentBySource('AREA', '10'));
            self::assertNull($this->repository->contentByAnySourceId('10'));
            self::assertSame([], $this->repository->workspaceSlugsBySourceKey());
        }, ['AREA' => 'AREA#copy-2']);
        self::assertSame(1, (int)$this->repository->contentBySource('AREA', '10')['target_workspace_id']);
        self::assertSame(2, (int)$this->repository->contentBySource('site:' . hash('sha256', 'https://one.test') . ':NEXT', '20')['target_workspace_id']);
        self::assertNull($this->repository->contentByAnySourceId('20'));
        $this->repository->updateJob((int)$job['id'], ['options_json' => '{}']);
        $this->repository->withSiteContext('https://one.test', [], function (): void {
            self::assertNull($this->repository->contentBySource('AREA', '10'));
        });
    }

    /**
     * EN: Creates only isolated mapping rows, not real application Workspaces.
     *
     * HR: Izrađuje samo izolirane redove mapiranja, ne stvarna područja aplikacije.
     */
    private function map(string $slug, int $target): void
    {
        $this->repository->mapSpace(['source_id' => '1', 'source_key' => 'AREA'], ['id' => $target, 'slug' => $slug], 1);
        $this->repository->mapContent(['source_id' => '10', 'title' => 'Home'], ['source_space_key' => 'AREA', 'workspace_id' => $target, 'slug' => 'home'], 1);
    }
}
