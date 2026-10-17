<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use AaiEduHr\SimbiozaModuleConfluenceImport\ModuleSimbiozaConfluenceImport as Module;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceArchive;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceExportReader;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceExportScanner;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportConfig;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportRepository;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportService;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportUploadService;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceInventoryReader;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteContentIndex;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteContentService;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteInventory;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSitePrincipals;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteAccessService;
use AaiEduHr\SimbiozaModuleUser\Service\PersonalWorkspaceService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ZipArchive;

/**
 * EN: Verifies review/queue trust boundaries in isolated SQLite without invoking content mutation services.
 * Real content processing is checked separately against the disposable complete application.
 *
 * HR: Provjerava granice pregleda/pokretanja u izoliranom SQLiteu bez poziva servisa promjene sadržaja.
 * Stvarna obrada sadržaja zasebno se provjerava u potrošnoj potpunoj aplikaciji.
 */
final class ConfluenceSiteContentReviewTest extends TestCase
{
    private string $root;
    private Database $database;
    private ConfluenceImportRepository $repository;
    private ConfluenceImportUploadService $uploads;
    private ConfluenceSiteContentService $service;
    private array $job;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/confluence-c3-review-' . bin2hex(random_bytes(6));
        $helper = new Helper();
        $config = new Config($helper, ['database' => ['connections' => ['default' => ['driver' => 'sqlite', 'database' => ':memory:']]]], $this->root);
        $moduleConfig = new ConfluenceImportConfig($config, dirname(__DIR__));
        mkdir($moduleConfig->uploadDirectory(), 0770, true);
        $this->database = new Database($config, $helper);
        (require __DIR__ . '/../resources/migrations/initial_simbioza_confluence_import_schema.php')->up($this->database);
        $this->repository = new ConfluenceImportRepository($this->database);
        $archive = new ConfluenceArchive($moduleConfig);
        $reader = new ConfluenceExportReader($archive);
        $inventory = new ConfluenceSiteInventory($archive, new ConfluenceInventoryReader($archive, $reader, $moduleConfig), $moduleConfig);
        $scanner = new ConfluenceExportScanner($archive, $reader, $inventory);
        $principals = new ConfluenceSitePrincipals($archive, $reader, $moduleConfig);
        $this->uploads = new ConfluenceImportUploadService($this->repository, $moduleConfig, $scanner);
        $this->service = new ConfluenceSiteContentService(
            $this->repository,
            new ConfluenceSiteContentIndex($archive, $reader, $moduleConfig),
            $scanner,
            $principals,
            (new ReflectionClass(ConfluenceImportService::class))->newInstanceWithoutConstructor(),
            $moduleConfig,
            (new ReflectionClass(WorkspaceRepository::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(PersonalWorkspaceService::class))->newInstanceWithoutConstructor()
        );
        $path = $moduleConfig->uploadDirectory() . '/site.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('exportDescriptor.properties', "exportType=site\nbackupAttachments=false\n");
        $zip->addFromString('entities.xml', '<hibernate-generic><object class="Space"><id>1</id><property name="key">ONE</property><property name="name">One</property></object><object class="Space"><id>2</id><property name="key">TWO</property><property name="name">Two</property></object></hibernate-generic>');
        $zip->close();
        $job = $this->repository->createUploadJob('site.zip', $path, filesize($path), 0, 1, time() + 3600);
        $scan = $scanner->scan($path);
        $this->repository->saveScan((int)$job['id'], $scan);
        $this->uploads->saveInventorySelection($job['uuid'], 1, $scan['source_sha256'], ['1', '2']);
        $principals->scan($path, $scan['source_sha256']);
        $this->job = $this->repository->jobByUuid($job['uuid']);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    /**
     * EN: A preview creates no targets and only its exact token can queue the parent.
     *
     * HR: Pregled ne stvara ciljeve i samo njegov točan token smije pokrenuti roditelja.
     */
    public function testReadOnlyReviewAndOwnedQueue(): void
    {
        $this->review();
        $state = $this->service->state($this->job['uuid'], 1);
        self::assertSame('ready', $state['job']['status']);
        self::assertCount(2, $state['plan']['decisions']);
        self::assertSame([], $this->database->table(Module::TABLE_SPACES)->get());
        $progress = $this->service->queue($this->job['uuid'], 1, $state['plan']['token']);
        self::assertSame('site_index', $progress['phase']);
        self::assertSame('site_import', $this->repository->jobByUuid($this->job['uuid'])['operation']);
        self::assertFalse($this->uploads->canCancel($this->repository->jobByUuid($this->job['uuid'])));
        self::assertFileExists($this->job['archive_path']);
    }

    /**
     * EN: Two future-owner sources may share a target only through explicit add-only choices.
     *
     * HR: Dva izvora budućeg vlasnika smiju dijeliti cilj samo uz izričite odabire dodavanja.
     */
    public function testRepeatedPersonalOwnerBuildsOrderedAdditiveChainWithoutWrites(): void
    {
        $this->personalSources();
        $this->service->review($this->job['uuid'], 1, ['source_url' => 'https://source.test',
            'spaces' => ['1' => ['strategy' => 'append'], '2' => ['strategy' => 'append']]], ['owner-key' => 'new:future']);
        $plan = $this->service->state($this->job['uuid'], 1)['plan']['decisions'];
        self::assertNull($plan['1']['append_after']);
        self::assertSame('1', $plan['2']['append_after']);
        self::assertSame('new:future', $plan['2']['append_owner']);
        self::assertNull($plan['2']['append_target']);
        self::assertSame([], $this->database->table(Module::TABLE_SPACES)->get());
    }

    /**
     * EN: Mixed replacement/new actions cannot hide a destructive merge behind an additive source.
     *
     * HR: Miješane radnje zamjene/novog cilja ne smiju sakriti destruktivno spajanje iza dodavanja.
     */
    public function testRepeatedPersonalOwnerRejectsNonAdditiveStrategies(): void
    {
        $this->personalSources();
        foreach ([['append', 'new'], ['new', 'append'], ['append', 'replace']] as [$first, $second]) {
            try {
                $this->service->review($this->job['uuid'], 1, ['source_url' => 'https://source.test',
                    'spaces' => ['1' => ['strategy' => $first], '2' => ['strategy' => $second]]], ['owner-key' => 'new:future']);
                self::fail('Non-additive merge accepted.');
            } catch (ConfluenceImportException) {
                self::assertSame([], $this->database->table(Module::TABLE_SPACES)->get());
            }
        }
    }

    /**
     * EN: Alters only this isolated inventory; no real source archive or HFC data is touched.
     *
     * HR: Mijenja samo ovaj izolirani pregled; ne dira stvarnu izvornu arhivu ni HFC podatke.
     */
    private function personalSources(): void
    {
        $summary = $this->job['summary'];
        foreach ($summary['spaces'] as &$space) {
            $space['type'] = 'personal';
            $space['owner_source_key'] = 'owner-key';
        }
        unset($space);
        $this->repository->updateJob((int)$this->job['id'], ['summary_json' => json_encode($summary, JSON_THROW_ON_ERROR)]);
    }

    /**
     * EN: Skipped disabled owners may use separate archival targets, never the actor's personal workspace.
     * The choice is inert and requires source evidence; it cannot override a mapped or active owner.
     *
     * HR: Preskočeni ugašeni vlasnici mogu dobiti zasebne arhivske ciljeve, ne administratorovo osobno područje.
     * Odabir ne izvršava uvoz i zahtijeva dokaz izvora; ne smije nadjačati mapiranog ili aktivnog vlasnika.
     */
    public function testExplicitCustodyKeepsSourcesSeparateWithoutAnIdentityOrAclMapping(): void
    {
        $this->personalSources();
        $path = $this->job['archive_path'] . '.principals.json';
        $catalog = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $catalog['users'] = [['source_id' => 'internal-user:owner', 'username' => 'archived-owner',
            'display_name' => 'Example Former Owner', 'account_active' => false, 'directory_active' => true,
            'aliases' => ['confluence-user:owner-key']]];
        file_put_contents($path, json_encode($catalog, JSON_THROW_ON_ERROR));
        $input = ['source_url' => 'https://source.test', 'spaces' => [
            '1' => ['owner_mode' => 'custody'], '2' => ['owner_mode' => 'custody']]];
        $this->service->review($this->job['uuid'], 1, $input, []);
        $state = $this->service->state($this->job['uuid'], 1);
        self::assertCount(2, $state['plan']['decisions']);
        foreach ($state['plan']['decisions'] as $decision) {
            self::assertSame('custody', $decision['owner_mode']);
            self::assertNull($decision['append_owner']);
            self::assertNull($decision['append_target']);
            self::assertSame('Example Former Owner', $decision['custody_source']['display_name']);
        }
        self::assertSame([], $this->database->table(Module::TABLE_SPACES)->get());
        self::assertNull($this->repository->mappedUserId('owner-key'));
        try {
            $this->service->review($this->job['uuid'], 1, $input, ['owner-key' => 'new:owner']);
            self::fail('Mapped owner cannot be replaced by custody.');
        } catch (ConfluenceImportException) {
            self::assertNull($this->repository->mappedUserId('owner-key'));
        }
        $catalog['users'][0]['account_active'] = true;
        file_put_contents($path, json_encode($catalog, JSON_THROW_ON_ERROR));
        $this->expectException(ConfluenceImportException::class);
        $this->service->review($this->job['uuid'], 1, $input, []);
    }

    /**
     * EN: Another administrator cannot review the owner's private archive.
     *
     * HR: Drugi administrator ne smije pregledavati vlasnikovu privatnu arhivu.
     */
    public function testWrongOwnerCannotReview(): void
    {
        $this->expectException(ConfluenceImportException::class);
        $this->service->review($this->job['uuid'], 2, ['source_url' => 'https://source.test']);
    }

    /**
     * EN: Unselected source IDs cannot enter a confirmation payload.
     *
     * HR: Neodabrani izvorni ID-jevi ne smiju ući u podatke potvrde.
     */
    public function testUnknownSpaceCannotBeSmuggledIntoReview(): void
    {
        $this->expectException(ConfluenceImportException::class);
        $this->review(['spaces' => ['unknown' => ['strategy' => 'replace']]]);
    }

    /**
     * EN: Expired tokens and source/mapping changes require another read-only review.
     *
     * HR: Istekli tokeni i promjene izvora/mapiranja zahtijevaju novi pregled bez upisa.
     */
    public function testChangedConfirmedIdentityMappingInvalidatesContentReview(): void
    {
        $this->review();
        $plan = $this->service->state($this->job['uuid'], 1)['plan'];
        $this->repository->mapIdentity(['source_key' => ConfluenceSiteAccessService::mappingKey('https://source.test', 'users', 'confluence-user:u1')], 42, true, 1);
        $this->expectException(ConfluenceImportException::class);
        $this->service->queue($this->job['uuid'], 1, $plan['token']);
    }

    /**
     * EN: Bad or expired confirmation never starts index preparation or content mutation.
     *
     * HR: Nevaljana ili istekla potvrda nikada ne pokreće pripremu indeksa ni promjenu sadržaja.
     */
    public function testExpiredReviewIsRejected(): void
    {
        $this->review();
        $job = $this->repository->jobByUuid($this->job['uuid']);
        $options = $job['options'];
        $options['site_content']['reviewed_at'] = time() - 1900;
        $this->repository->updateJob((int)$job['id'], ['options_json' => json_encode($options, JSON_THROW_ON_ERROR)]);
        $this->expectException(ConfluenceImportException::class);
        $this->service->queue($job['uuid'], 1, $options['site_content']['token']);
    }

    /**
     * EN: A completed child cleanup cannot delete the shared source used by its parent or siblings.
     *
     * HR: Čišćenje dovršenog podposla ne smije obrisati zajednički izvor roditelja ili drugih podposlova.
     */
    public function testSharedArchiveOwnershipSurvivesChildCleanup(): void
    {
        $child = $this->repository->createUploadJob('child', $this->job['archive_path'], 0, 0, 1, time() + 3600);
        $this->repository->updateJob((int)$child['id'], ['operation' => 'site_space', 'status' => 'completed']);
        $child = $this->repository->jobByUuid($child['uuid']);
        self::assertFalse($this->uploads->canCancel($child));
        $this->uploads->deleteArchive($child);
        $this->uploads->cleanupCompletedArtifacts();
        self::assertFileExists($this->job['archive_path']);
    }

    /**
     * EN: Cancelling preparation also removes only the owned, rebuildable content partitions.
     *
     * HR: Otkazivanje pripreme uklanja i samo vlastite, obnovljive podjele sadržaja.
     */
    public function testPreparationCancellationRemovesContentIndex(): void
    {
        $path = $this->job['archive_path'] . '.content';
        mkdir($path, 0770);
        file_put_contents($path . '/common.jsonl', '');
        file_put_contents($path . '/' . hash('sha256', '1') . '.jsonl', '');
        file_put_contents($path . '/manifest.json', '{}');
        $this->uploads->cancel($this->job['uuid'], 1);
        self::assertDirectoryDoesNotExist($path);
        self::assertFileDoesNotExist($this->job['archive_path']);
    }

    /**
     * EN: Starts a synthetic content review only.
     *
     * HR: Pokreće samo sintetički pregled sadržaja.
     *
     * @param array<string,mixed> $extra
     */
    private function review(array $extra = []): void
    {
        $this->service->review($this->job['uuid'], 1, ['source_url' => 'https://source.test', ...$extra]);
    }
}
