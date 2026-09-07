<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\HeartPhrameModuleBackup\Service\BackupFilesystem;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleConfluenceImport\Listener\PurgeConfluenceWorkspaceImport;
use AaiEduHr\SimbiozaModuleConfluenceImport\ModuleSimbiozaConfluenceImport;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportConfig;
use AaiEduHr\SimbiozaModuleWorkspace\Event\WorkspacePermanentlyDeleting;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PurgeConfluenceWorkspaceImport::class)]
final class PurgeConfluenceWorkspaceImportTest extends TestCase
{
    private string $directory;

    /** HR: Čisti izolirano privatno spremište nakon testa. EN: Cleans isolated private storage after the test. */
    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            (new BackupFilesystem())->removeDirectory($this->directory);
        }
    }

    /** HR: Dokazuje da zamjena područja uklanja ZIP, privitke i nastavivi staging njegova posla. EN: Proves Workspace replacement removes the job ZIP, attachments, and resumable staging. */
    public function testWorkspacePurgeRemovesAllManagedJobArtifacts(): void
    {
        $this->directory = sys_get_temp_dir() . '/simbioza-confluence-workspace-purge-'
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

        mkdir($importConfig->uploadDirectory(), 0770, true);
        mkdir($importConfig->attachmentDirectory(), 0770, true);
        $jobUuid = '8d64ab41-c85a-4934-969f-d10d9086b005';
        $archivePath = $importConfig->uploadDirectory() . '/source.zip';
        $attachmentPath = $importConfig->attachmentDirectory() . '/staged.bin';
        $stagingPath = $importConfig->dataDirectory() . '/staging/' . $jobUuid;
        mkdir($stagingPath . '/bodies', 0770, true);
        file_put_contents($archivePath, 'archive');
        file_put_contents($attachmentPath, 'attachment');
        file_put_contents($stagingPath . '/state.json', '{}');
        file_put_contents($stagingPath . '/bodies/100.html', '<p>Body</p>');

        $database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)->insert([
            'uuid' => $jobUuid,
            'operation' => 'import',
            'status' => 'failed',
            'stage' => 'failed',
            'original_name' => 'source.zip',
            'archive_path' => $archivePath,
            'archive_size' => 7,
            'workspace_id' => 44,
            'actor_user_id' => 1,
        ]);
        $job = $database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
            ->where('uuid', '=', $jobUuid)->first();
        self::assertIsArray($job);
        $database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)->insert([
            'uuid' => 'staged-attachment',
            'job_id' => (int)$job['id'],
            'source_attachment_id' => 'staged-attachment',
            'logical_source_id' => 'staged-attachment',
            'source_page_id' => '100',
            'source_version' => 1,
            'original_name' => 'staged.bin',
            'storage_path' => $attachmentPath,
            'target_workspace_id' => 44,
            'status' => 'stored',
        ]);

        $listener = new PurgeConfluenceWorkspaceImport($database, $importConfig);
        $listener(new WorkspacePermanentlyDeleting(44, 'replaced', [], [], 1));

        self::assertFileDoesNotExist($archivePath);
        self::assertFileDoesNotExist($attachmentPath);
        self::assertDirectoryDoesNotExist($stagingPath);
        self::assertNull($database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
            ->where('uuid', '=', $jobUuid)->first());
        self::assertNull($database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
            ->where('uuid', '=', 'staged-attachment')->first());
    }
}
