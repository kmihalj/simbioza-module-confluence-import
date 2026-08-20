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

#[CoversClass(ConfluenceImportUploadService::class)]
final class ConfluenceImportUploadServiceTest extends TestCase
{
    private string $directory;

    private Database $database;

    private ConfluenceImportRepository $repository;

    private ConfluenceImportUploadService $uploads;

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
