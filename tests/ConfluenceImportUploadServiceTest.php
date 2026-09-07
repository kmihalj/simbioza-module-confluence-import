<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use AaiEduHr\SimbiozaModuleConfluenceImport\ModuleSimbiozaConfluenceImport;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceArchive;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceExportReader;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceExportScanner;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportConfig;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportRepository;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportUploadService;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZipArchive;

#[CoversClass(ConfluenceImportUploadService::class)]
final class ConfluenceImportUploadServiceTest extends TestCase
{
    private string $directory;

    private Database $database;

    private ConfluenceImportRepository $repository;

    private ConfluenceImportUploadService $uploads;

    private ConfluenceImportConfig $config;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/simbioza-confluence-upload-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0770, true);
        $helper = new Helper();
        $configuration = new class ($helper, [
            'database' => ['connections' => ['default' => ['driver' => 'sqlite', 'database' => ':memory:']]],
            'confluence_import' => ['chunk_size' => 3, 'upload_ttl' => 60],
        ], $this->directory) extends Config {
            /** @param array<string,mixed> $data */
            public function __construct(Helper $helper, array $data, private readonly string $root)
            {
                parent::__construct($helper, $data);
            }

            public function getAppRootDir(): string
            {
                return $this->root;
            }
        };
        $this->database = new Database($configuration, $helper);
        $migration = require dirname(__DIR__) . '/resources/migrations/initial_simbioza_confluence_import_schema.php';
        $migration->up($this->database);
        $config = new ConfluenceImportConfig($configuration, dirname(__DIR__));
        $this->config = $config;
        $this->repository = new ConfluenceImportRepository($this->database);
        $archive = new ConfluenceArchive($config);
        $scanner = new ConfluenceExportScanner($archive, new ConfluenceExportReader($archive));
        $this->uploads = new ConfluenceImportUploadService($this->repository, $config, $scanner);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    /** HR: Dokazuje nastavak po offsetu i automatsko uklanjanje isteklog privremenog posla. EN: Proves offset-based resume and automatic cleanup of an expired temporary job. */
    public function testResumableChunksAndExpiredCleanup(): void
    {
        $job = $this->uploads->start('space.xml.zip', 6, 42);
        $first = fopen('php://temp', 'w+b');
        self::assertIsResource($first);
        fwrite($first, 'abc');
        rewind($first);
        $job = $this->uploads->append((string)$job['uuid'], 0, $first, 42);
        fclose($first);
        self::assertSame(3, $job['next_offset']);

        $second = fopen('php://temp', 'w+b');
        self::assertIsResource($second);
        fwrite($second, 'def');
        rewind($second);
        $job = $this->uploads->append((string)$job['uuid'], 3, $second, 42);
        fclose($second);
        self::assertSame(6, $job['next_offset']);
        self::assertFileExists((string)$job['archive_path']);

        $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
            ->where('id', '=', (int)$job['id'])
            ->update(['expires_at' => '2000-01-01 00:00:00']);
        self::assertSame(1, $this->uploads->cleanupExpired());
        self::assertFileDoesNotExist((string)$job['archive_path']);

        $this->expectException(ConfluenceImportException::class);
        $this->repository->jobByUuid((string)$job['uuid'], 42);
    }

    /** HR: Dokazuje da odustajanje briše i privremenu datoteku i posao. EN: Proves that cancellation deletes both the temporary file and job. */
    public function testAdministratorCanCancelOwnTransientUpload(): void
    {
        $job = $this->uploads->start('cancel.xml.zip', 6, 42);
        $path = (string)$job['archive_path'];
        self::assertFileExists($path);

        $cancelled = $this->uploads->cancel((string)$job['uuid'], 42);

        self::assertSame($job['uuid'], $cancelled['uuid']);
        self::assertFileDoesNotExist($path);
        $this->expectException(ConfluenceImportException::class);
        $this->repository->jobByUuid((string)$job['uuid'], 42);
    }

    /** HR: Dokazuje da se posao koji je počeo mijenjati sadržaj ne može ukloniti kao privremeni upload. EN: Proves that a content-mutating job cannot be removed as a transient upload. */
    public function testRunningImportCannotBeCancelled(): void
    {
        $job = $this->uploads->start('running.xml.zip', 6, 42);
        $path = (string)$job['archive_path'];
        $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
            ->where('id', '=', (int)$job['id'])
            ->update([
                'status' => 'running',
                'options_json' => '{}',
            ]);

        try {
            $this->uploads->cancel((string)$job['uuid'], 42);
            self::fail('A running import must not be cancellable.');
        } catch (ConfluenceImportException $exception) {
            self::assertStringContainsString('nije moguće otkazati', $exception->getMessage());
        }

        self::assertFileExists($path);
        self::assertSame('running', $this->repository->jobByUuid((string)$job['uuid'], 42)['status']);
    }

    /** HR: Dokazuje da je i neuspjela početna provjera privremena te se uklanja po isteku. EN: Proves that a failed preflight is still transient and removed on expiry. */
    public function testFailedPreflightUploadIsExpiredAndRemoved(): void
    {
        $job = $this->uploads->start('failed.xml.zip', 6, 42);
        $path = (string)$job['archive_path'];
        $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
            ->where('id', '=', (int)$job['id'])
            ->update([
                'status' => 'failed',
                'stage' => 'failed',
                'expires_at' => '2000-01-01 00:00:00',
            ]);

        self::assertSame(1, $this->uploads->cleanupExpired());
        self::assertFileDoesNotExist($path);
        $this->expectException(ConfluenceImportException::class);
        $this->repository->jobByUuid((string)$job['uuid'], 42);
    }

    /** HR: Dokazuje brisanje izvornog ZIP-a koje servis importa poziva nakon uspjeha. EN: Proves source-ZIP deletion invoked by the import service after success. */
    public function testSuccessfulImportArchiveCleanupDeletesSourceFile(): void
    {
        $job = $this->uploads->start('completed.xml.zip', 6, 42);
        $path = (string)$job['archive_path'];

        $this->repository->completeImport((int)$job['id'], 99, ['pages' => 1]);
        self::assertSame(
            $path,
            $this->repository->jobByUuid((string)$job['uuid'], 42)['archive_path'],
        );
        $this->uploads->deleteArchive($job);

        self::assertFileDoesNotExist($path);
        $completed = $this->repository->jobByUuid((string)$job['uuid'], 42);
        self::assertSame('completed', $completed['status']);
        self::assertSame('', $completed['archive_path']);
    }

    /** HR: Dokazuje ponovni cleanup dovršenog ZIP-a i staginga uz očuvanje privitaka. EN: Proves retry cleanup of a completed ZIP and staging while preserving attachments. */
    public function testCompletedArtifactCleanupRetriesOnlyTransientArtifacts(): void
    {
        $job = $this->uploads->start('retry-cleanup.xml.zip', 6, 42);
        $archivePath = (string)$job['archive_path'];
        $this->repository->completeImport((int)$job['id'], 99, ['pages' => 1]);

        $attachmentDirectory = $this->config->attachmentDirectory();
        mkdir($attachmentDirectory, 0770, true);
        $attachmentPath = $attachmentDirectory . '/unused.bin';
        file_put_contents($attachmentPath, 'unused');
        $stagingPath = $this->config->dataDirectory() . '/staging/' . $job['uuid'];
        mkdir($stagingPath . '/bodies', 0770, true);
        file_put_contents($stagingPath . '/bodies/page.html', 'staged');
        $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)->insert([
            'uuid' => 'unused-attachment',
            'job_id' => (int)$job['id'],
            'source_attachment_id' => 'unused-attachment',
            'logical_source_id' => 'unused-attachment',
            'source_page_id' => 'draft-page',
            'source_version' => 1,
            'original_name' => 'unused.bin',
            'storage_path' => $attachmentPath,
            'target_workspace_id' => 99,
            'target_node_id' => null,
            'target_document_key' => null,
            'status' => 'stored',
        ]);

        self::assertSame(2, $this->uploads->cleanupCompletedArtifacts());
        self::assertFileDoesNotExist($archivePath);
        self::assertDirectoryDoesNotExist($stagingPath);
        self::assertFileExists($attachmentPath);
        self::assertSame('', $this->repository->jobByUuid((string)$job['uuid'], 42)['archive_path']);
        self::assertNotNull($this->database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
            ->where('uuid', '=', 'unused-attachment')->first());
    }

    /** HR: Dokazuje da cleanup dovršenih artefakata ne dira nastavivi posao. EN: Proves completed-artifact cleanup never touches a resumable job. */
    public function testCompletedArtifactCleanupPreservesRunningImport(): void
    {
        $job = $this->uploads->start('running-cleanup.xml.zip', 6, 42);
        $archivePath = (string)$job['archive_path'];
        $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
            ->where('id', '=', (int)$job['id'])
            ->update([
                'status' => 'running',
                'stage' => 'attachments',
                'options_json' => '{}',
            ]);

        $attachmentDirectory = $this->config->attachmentDirectory();
        mkdir($attachmentDirectory, 0770, true);
        $attachmentPath = $attachmentDirectory . '/running.bin';
        file_put_contents($attachmentPath, 'running');
        $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)->insert([
            'uuid' => 'running-attachment',
            'job_id' => (int)$job['id'],
            'source_attachment_id' => 'running-attachment',
            'logical_source_id' => 'running-attachment',
            'source_page_id' => 'page-in-progress',
            'source_version' => 1,
            'original_name' => 'running.bin',
            'storage_path' => $attachmentPath,
            'target_workspace_id' => 99,
            'status' => 'stored',
        ]);

        self::assertSame(0, $this->uploads->cleanupCompletedArtifacts());
        self::assertFileExists($archivePath);
        self::assertFileExists($attachmentPath);
        self::assertNotNull($this->database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
            ->where('uuid', '=', 'running-attachment')->first());
    }

    /** HR: Batch popis prihvaća samo izravne XML ZIP datoteke, a uspjeh briše i upload poveznicu i izvor. EN: The batch inventory accepts direct XML ZIP files only, and success removes both the upload link and its source. */
    public function testBatchArchiveIsAdoptedWithoutCopyAndDeletedOnlyAfterSuccess(): void
    {
        $batchDirectory = $this->config->batchDirectory();
        mkdir($batchDirectory, 0770, true);
        $sourcePath = $batchDirectory . '/demo.xml.zip';
        $this->createSpaceArchive($sourcePath);
        file_put_contents($batchDirectory . '/ignored.zip', 'ignored');
        self::assertTrue(symlink($sourcePath, $batchDirectory . '/linked.xml.zip'));

        self::assertSame([[
            'name' => 'demo.xml.zip',
            'size' => filesize($sourcePath),
        ]], $this->uploads->batchArchives());

        $job = $this->uploads->adoptBatchArchive('demo.xml.zip', 42);
        $uploadPath = (string)$job['archive_path'];
        self::assertSame('batch_import', $job['operation']);
        self::assertSame('ready', $job['status']);
        self::assertFileExists($sourcePath);
        self::assertFileExists($uploadPath);
        self::assertSame(fileinode($sourcePath), fileinode($uploadPath));

        $this->repository->completeImport((int)$job['id'], 99, ['pages' => 1]);
        $this->uploads->deleteArchive($job);

        self::assertFileDoesNotExist($sourcePath);
        self::assertFileDoesNotExist($uploadPath);
        self::assertSame('', $this->repository->jobByUuid((string)$job['uuid'], 42)['archive_path']);
    }

    /** HR: Neispravna batch arhiva ostaje u ulaznom direktoriju za dijagnostiku i ponovni pokušaj. EN: An invalid batch archive remains in the input directory for diagnosis and retry. */
    public function testFailedBatchPreflightPreservesSourceArchive(): void
    {
        $batchDirectory = $this->config->batchDirectory();
        mkdir($batchDirectory, 0770, true);
        $sourcePath = $batchDirectory . '/broken.xml.zip';
        file_put_contents($sourcePath, 'not-a-zip');

        try {
            $this->uploads->adoptBatchArchive('broken.xml.zip', 42);
            self::fail('An invalid batch archive must fail preflight.');
        } catch (ConfluenceImportException) {
            self::assertFileExists($sourcePath);
            self::assertNull($this->repository->activeBatchJob(42));
        }
    }

    private function createSpaceArchive(string $path): void
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        self::assertTrue($zip->addFromString(
            'exportDescriptor.properties',
            "exportType=space\nspaceKey=DEMO\ncreatedByVersionNumber=10.2\nbackupAttachments=true\n",
        ));
        self::assertTrue($zip->addFromString('entities.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<hibernate-generic>
  <object class="Space" package="com.atlassian.confluence.spaces"><id name="id">1</id><property name="key">DEMO</property><property name="name">Demo Workspace</property><property name="spaceType">global</property><property name="homePage"><id>100</id></property></object>
  <object class="Page" package="com.atlassian.confluence.pages"><id name="id">100</id><property name="space"><id>1</id></property><property name="title">Home</property><property name="version">1</property><property name="contentStatus">current</property></object>
</hibernate-generic>
XML));
        self::assertTrue($zip->close());
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
