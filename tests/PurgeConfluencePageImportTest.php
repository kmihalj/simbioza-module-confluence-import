<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\HeartPhrameModuleBackup\Service\BackupFilesystem;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleWorkspace\Event\WorkspacePagesPermanentlyDeleting;
use AaiEduHr\SimbiozaModuleConfluenceImport\Listener\PurgeConfluencePageImport;
use AaiEduHr\SimbiozaModuleConfluenceImport\ModuleSimbiozaConfluenceImport;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportConfig;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PurgeConfluencePageImport::class)]
final class PurgeConfluencePageImportTest extends TestCase
{
    private string $directory;

    /** HR: Čisti izolirano privatno spremište nakon testa. EN: Cleans isolated private storage after the test. */
    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            (new BackupFilesystem())->removeDirectory($this->directory);
        }
    }

    /**
     * HR: Dokazuje da trajno brisanje jedne stranice ne uklanja ostatak uvezenog područja.
     * EN: Proves permanently deleting one page does not remove the rest of the imported Workspace.
     */
    public function testOnlyDeletedPageMappingsLinksAndFilesArePurged(): void
    {
        $this->directory = sys_get_temp_dir() . '/simbioza-confluence-page-purge-'
            . bin2hex(random_bytes(8));
        mkdir($this->directory, 0770, true);
        $helper = new Helper();
        $config = new class ($helper, [
            'database' => ['connections' => ['default' => ['driver' => 'sqlite', 'database' => ':memory:']]],
        ], $this->directory) extends Config {
            /** @param array<string,mixed> $data */
            public function __construct(Helper $helper, array $data, private readonly string $root)
            {
                parent::__construct($helper, $data);
            }

            /** HR: Vraća izolirani aplikacijski korijen. EN: Returns the isolated application root. */
            public function getAppRootDir(): string
            {
                return $this->root;
            }
        };
        $database = new Database($config, $helper);
        $migration = require dirname(__DIR__) . '/resources/migrations/initial_simbioza_confluence_import_schema.php';
        $migration->up($database);
        $importConfig = new ConfluenceImportConfig($config, dirname(__DIR__));
        mkdir($importConfig->attachmentDirectory(), 0770, true);
        $deletedFile = $importConfig->attachmentDirectory() . '/deleted.bin';
        $retainedFile = $importConfig->attachmentDirectory() . '/retained.bin';
        file_put_contents($deletedFile, 'deleted');
        file_put_contents($retainedFile, 'retained');

        $this->seed($database, $deletedFile, $retainedFile);
        $listener = new PurgeConfluencePageImport($database, $importConfig);
        $listener(new WorkspacePagesPermanentlyDeleting([[
            'workspace_id' => 44,
            'node_id' => 101,
            'document_key' => 'imported-a',
        ]], 1));

        self::assertFalse(is_file($deletedFile));
        self::assertTrue(is_file($retainedFile));
        self::assertNull($database->table(ModuleSimbiozaConfluenceImport::TABLE_CONTENT)
            ->where('source_space_key', '=', 'AAIUPUTE')
            ->where('source_content_id', '=', 'page-a')->first());
        self::assertNotNull($database->table(ModuleSimbiozaConfluenceImport::TABLE_CONTENT)
            ->where('source_space_key', '=', 'OTHER')
            ->where('source_content_id', '=', 'page-a')->first());
        self::assertNotNull($database->table(ModuleSimbiozaConfluenceImport::TABLE_CONTENT)
            ->where('source_content_id', '=', 'page-b')->first());
        self::assertNull($database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
            ->where('source_attachment_id', '=', 'attachment-a')->first());
        self::assertNotNull($database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
            ->where('source_attachment_id', '=', 'attachment-b')->first());
        self::assertNotNull($database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
            ->where('source_attachment_id', '=', 'attachment-collision')->first());
        self::assertNull($database->table(ModuleSimbiozaConfluenceImport::TABLE_LINKS)
            ->where('uuid', '=', 'outgoing-a')->first());

        $incoming = $database->table(ModuleSimbiozaConfluenceImport::TABLE_LINKS)
            ->where('uuid', '=', 'incoming-a')->first();
        self::assertIsArray($incoming);
        self::assertSame('unresolved', $incoming['status'] ?? null);
        self::assertNull($incoming['resolved_target'] ?? null);
        self::assertNotNull($database->table(ModuleSimbiozaConfluenceImport::TABLE_LINKS)
            ->where('uuid', '=', 'unrelated')->first());
        self::assertNotNull($database->table(ModuleSimbiozaConfluenceImport::TABLE_LINKS)
            ->where('uuid', '=', 'same-id-other-job')->first());
        self::assertNotNull($database->table(ModuleSimbiozaConfluenceImport::TABLE_LINKS)
            ->where('uuid', '=', 'same-id-other-space')->first());
    }

    /** HR: Sprema dvije stranice i njihove neovisne veze. EN: Seeds two pages and their independent links. */
    private function seed(Database $database, string $deletedFile, string $retainedFile): void
    {
        foreach (
            [
            ['page-a', 'AAIUPUTE', 1, 101, 'imported-a'],
            ['page-b', 'AAIUPUTE', 1, 102, 'imported-b'],
            ['page-a', 'OTHER', 2, 202, 'other-a'],
            ] as [$sourceId, $spaceKey, $jobId, $nodeId, $documentKey]
        ) {
            $database->table(ModuleSimbiozaConfluenceImport::TABLE_CONTENT)->insert([
                'source_content_id' => $sourceId,
                'logical_source_id' => $sourceId,
                'source_space_key' => $spaceKey,
                'target_workspace_id' => 44,
                'target_node_id' => $nodeId,
                'target_document_key' => $documentKey,
                'job_id' => $jobId,
            ]);
        }
        foreach (
            [
            ['attachment-a', 1, 'page-a', 101, 'imported-a', $deletedFile],
            ['attachment-b', 1, 'page-b', 102, 'imported-b', $retainedFile],
            ['attachment-collision', 2, 'page-a', 202, 'other-a', $retainedFile],
            ] as [$attachmentId, $jobId, $pageId, $nodeId, $documentKey, $path]
        ) {
            $database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)->insert([
                'uuid' => $attachmentId,
                'job_id' => $jobId,
                'source_attachment_id' => $attachmentId,
                'logical_source_id' => $attachmentId,
                'source_page_id' => $pageId,
                'original_name' => $attachmentId . '.bin',
                'storage_path' => $path,
                'target_workspace_id' => 44,
                'target_node_id' => $nodeId,
                'target_document_key' => $documentKey,
            ]);
        }
        foreach (
            [
            ['outgoing-a', 1, 'AAIUPUTE', 'page-a', 'AAIUPUTE', 'page-b', '/workspace/aaiupute/page-b'],
            ['incoming-a', 1, 'AAIUPUTE', 'page-b', 'AAIUPUTE', 'page-a', '/workspace/aaiupute/page-a'],
            ['unrelated', 1, 'AAIUPUTE', 'page-b', 'AAIUPUTE', 'page-c', '/workspace/aaiupute/page-c'],
            ['same-id-other-job', 2, 'OTHER', 'page-a', 'OTHER', 'page-b', '/workspace/other/page-b'],
            ['same-id-other-space', 2, 'OTHER', 'page-b', 'OTHER', 'page-a', '/workspace/other/page-a'],
            ] as [$uuid, $jobId, $sourceSpace, $sourceId, $destinationSpace, $destinationId, $target]
        ) {
            $database->table(ModuleSimbiozaConfluenceImport::TABLE_LINKS)->insert([
                'uuid' => $uuid,
                'job_id' => $jobId,
                'source_page_id' => $sourceId,
                'source_space_key' => $sourceSpace,
                'destination_space_key' => $destinationSpace,
                'destination_page_id' => $destinationId,
                'resolved_target' => $target,
                'status' => 'resolved',
            ]);
        }
    }
}
