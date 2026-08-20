<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\HeartPhrameModuleBackup\Service\BackupArchiveReader;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupArchiveWriter;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupConfig;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupFilesystem;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupExportContext;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupImportContext;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupImportState;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope;
use AaiEduHr\HeartPhrameModuleEditorHtml\ModuleEditorHtml;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;
use AaiEduHr\HeartPhrameModuleWorkspace\ModuleWorkspace;
use AaiEduHr\SimbiozaModuleConfluenceImport\Backup\ConfluenceImportWorkspaceBackupProvider;
use AaiEduHr\SimbiozaModuleConfluenceImport\ModuleSimbiozaConfluenceImport;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportConfig;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(ConfluenceImportWorkspaceBackupProvider::class)]
final class ConfluenceImportWorkspaceBackupProviderTest extends TestCase
{
    private string $directory;

    private Database $database;

    private ConfluenceImportConfig $importConfig;

    private BackupConfig $backupConfig;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/simbioza-confluence-workspace-backup-'
            . bin2hex(random_bytes(8));
        mkdir($this->directory, 0770, true);
        $helper = new Helper();
        $configuration = new class ($helper, [
            'database' => ['connections' => ['default' => ['driver' => 'sqlite', 'database' => ':memory:']]],
            'backup' => [
                'archive_dir' => $this->directory . '/backups',
                'staging_dir' => $this->directory . '/staging',
            ],
        ], $this->directory) extends Config {
            /**
             * HR: Prima testnu konfiguraciju i izolirani korijen.
             * EN: Receives test configuration and an isolated root.
             *
             * @param array<string,mixed> $data
             */
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
        $this->database = new Database($configuration, $helper);
        $this->createWorkspaceTables();
        $migration = require dirname(__DIR__) . '/resources/migrations/initial_simbioza_confluence_import_schema.php';
        $migration->up($this->database);
        $this->importConfig = new ConfluenceImportConfig($configuration, dirname(__DIR__));
        $backupModuleRoot = dirname((string)(new ReflectionClass(BackupConfig::class))->getFileName(), 3);
        $this->backupConfig = new BackupConfig($configuration, new ComposerBridge(), $backupModuleRoot);
    }

    protected function tearDown(): void
    {
        (new BackupFilesystem())->removeDirectory($this->directory);
    }

    /**
     * HR: Dokazuje da selektivni backup područja sadrži privatni blob, ali ne i lokalnu putanju.
     * EN: Proves that a scoped Workspace backup contains the private blob but not its local path.
     */
    public function testWorkspaceExportContainsPortableAttachment(): void
    {
        $this->seedImportedWorkspace();
        $filesystem = new BackupFilesystem();
        $provider = new ConfluenceImportWorkspaceBackupProvider(
            $this->database,
            $this->importConfig,
            $filesystem,
        );
        self::assertSame([BackupScope::WORKSPACE], $provider->metadata()->scopes);
        self::assertSame(['workspace-scope'], $provider->metadata()->dependencies);

        $scope = new BackupScope(BackupScope::WORKSPACE, 'test-space');
        $writer = new BackupArchiveWriter(
            $this->backupConfig,
            $filesystem,
            $scope,
            ['application' => 'tests'],
            'Confluence Workspace test',
            'test-passphrase',
        );
        $writer->beginProvider($provider->metadata());
        $provider->export(new BackupExportContext($scope, [], passphrase: 'test-passphrase'), $writer);
        $archive = $writer->finish();

        $reader = new BackupArchiveReader($archive, $this->backupConfig, $filesystem, 'test-passphrase');
        $attachments = iterator_to_array($reader->records($provider->metadata()->id, 'attachments'), false);
        self::assertCount(1, $attachments);
        self::assertArrayNotHasKey('storage_path', $attachments[0]);
        self::assertSame('node-uuid', $attachments[0]['node_uuid'] ?? null);
        $blob = is_array($attachments[0]['blob'] ?? null) ? $attachments[0]['blob'] : [];
        self::assertSame('private Confluence attachment', file_get_contents(
            $reader->blobPath((string)($blob['sha256'] ?? '')),
        ));
        $reader->close();
    }

    /**
     * HR: Dokazuje da obnova kao kopija stvara novi privitak i prepisuje URL u dokumentu.
     * EN: Proves that a copy restore creates a new attachment and rewrites its document URL.
     */
    public function testCopyRestoreRegeneratesAttachmentAndRewritesDocument(): void
    {
        $this->seedImportedWorkspace();
        $filesystem = new BackupFilesystem();
        $provider = new ConfluenceImportWorkspaceBackupProvider(
            $this->database,
            $this->importConfig,
            $filesystem,
        );
        $sourceScope = new BackupScope(BackupScope::WORKSPACE, 'test-space');
        $writer = new BackupArchiveWriter(
            $this->backupConfig,
            $filesystem,
            $sourceScope,
            ['application' => 'tests'],
            'Confluence Workspace copy test',
            'test-passphrase',
        );
        $writer->beginProvider($provider->metadata());
        $provider->export(new BackupExportContext($sourceScope, [], passphrase: 'test-passphrase'), $writer);
        $archive = $writer->finish();

        $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)->insert([
            'uuid' => 'target-workspace-uuid',
            'slug' => 'copied-space',
            'name' => 'Copied Space',
        ]);
        $targetWorkspaceId = (int)$this->database->lastInsertId();
        $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)->insert([
            'uuid' => 'target-node-uuid',
            'workspace_id' => $targetWorkspaceId,
            'document_key' => 'copied-document',
            'slug' => 'test-page',
        ]);
        $targetNodeId = (int)$this->database->lastInsertId();
        $oldUuid = '00000000-0000-4000-8000-000000000002';
        $this->database->table(ModuleEditorHtml::TABLE_DOCUMENT_VERSIONS)->insert([
            'document_id' => 900,
            'content_html' => '<a href="/confluence-import/attachment/' . $oldUuid . '">File</a>',
        ]);

        $state = new BackupImportState();
        $state->map('workspace.id', 1, $targetWorkspaceId);
        $state->map('workspace.slug', 'test-space', 'copied-space');
        $state->map('workspace.node-id', 'node-uuid', $targetNodeId);
        $state->map('editor.document-key', 'test-document', 'copied-document');
        $state->map('editor.document-id-by-key', 'test-document', 900);
        $context = new BackupImportContext(
            new BackupScope(BackupScope::WORKSPACE, 'copied-space'),
            BackupImportContext::CONFLICT_COPY,
            [],
            actorUserId: 77,
            passphrase: 'test-passphrase',
            state: $state,
        );
        $reader = new BackupArchiveReader($archive, $this->backupConfig, $filesystem, 'test-passphrase');
        $portableAttachments = iterator_to_array(
            $reader->records($provider->metadata()->id, 'attachments'),
            false,
        );
        self::assertCount(1, $portableAttachments, json_encode($portableAttachments));
        self::assertSame('node-uuid', $portableAttachments[0]['node_uuid'] ?? null, json_encode($portableAttachments));
        self::assertSame(
            'test-document',
            $portableAttachments[0]['target_document_key'] ?? null,
            json_encode($portableAttachments),
        );
        $preflight = $provider->preflight($context, $reader);
        self::assertTrue($preflight->isAllowed(), implode(' | ', $preflight->errors));
        $provider->import($context, $reader);
        $provider->finalizeImport($context, $reader);
        $provider->completeImport($context);

        $restored = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
            ->where('target_workspace_id', '=', $targetWorkspaceId)
            ->first();
        self::assertIsArray($restored);
        self::assertNotSame($oldUuid, $restored['uuid'] ?? null);
        self::assertSame('copied-document', $restored['target_document_key'] ?? null);
        self::assertSame('private Confluence attachment', file_get_contents((string)$restored['storage_path']));
        $version = $this->database->table(ModuleEditorHtml::TABLE_DOCUMENT_VERSIONS)
            ->where('document_id', '=', 900)
            ->first();
        self::assertIsArray($version);
        self::assertStringContainsString((string)$restored['uuid'], (string)$version['content_html']);
        self::assertStringNotContainsString($oldUuid, (string)$version['content_html']);
        $reader->close();
    }

    /** HR: Kreira minimalne Workspace tablice koje provider čita. EN: Creates the minimal Workspace tables read by the provider. */
    private function createWorkspaceTables(): void
    {
        $this->database->schema()->create(ModuleWorkspace::TABLE_WORKSPACES, static function (Blueprint $table): void {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->string('slug', 190)->unique();
            $table->string('name', 255);
        });
        $this->database->schema()->create(
            ModuleWorkspace::TABLE_WORKSPACE_NODES,
            static function (Blueprint $table): void {
                $table->id();
                $table->string('uuid', 36)->unique();
                $table->bigInteger('workspace_id')->unsigned()->index();
                $table->string('document_key', 190)->nullable();
                $table->string('slug', 190);
            },
        );
        $this->database->schema()->create(
            ModuleEditorHtml::TABLE_DOCUMENT_VERSIONS,
            static function (Blueprint $table): void {
                $table->id();
                $table->bigInteger('document_id')->unsigned()->index();
                $table->longText('content_html');
            },
        );
    }

    /** HR: Sprema jedno uvezeno područje i njegov privatni privitak. EN: Stores one imported Workspace and its private attachment. */
    private function seedImportedWorkspace(): void
    {
        $now = '2026-08-20 12:00:00';
        $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)->insert([
            'uuid' => 'workspace-uuid',
            'slug' => 'test-space',
            'name' => 'Test Space',
        ]);
        $workspaceId = (int)$this->database->lastInsertId();
        $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)->insert([
            'uuid' => 'node-uuid',
            'workspace_id' => $workspaceId,
            'document_key' => 'test-document',
            'slug' => 'test-page',
        ]);
        $nodeId = (int)$this->database->lastInsertId();
        $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)->insert([
            'uuid' => '00000000-0000-4000-8000-000000000001',
            'operation' => 'import',
            'status' => 'completed',
            'stage' => 'completed',
            'original_name' => 'test.xml.zip',
            'archive_path' => '',
            'archive_size' => 10,
            'next_offset' => 10,
            'chunk_size' => 10,
            'source_space_key' => 'TEST',
            'source_space_name' => 'Test Space',
            'source_space_type' => 'global',
            'workspace_id' => $workspaceId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $jobId = (int)$this->database->lastInsertId();
        $directory = $this->importConfig->attachmentDirectory();
        mkdir($directory, 0770, true);
        $path = $directory . '/private.bin';
        file_put_contents($path, 'private Confluence attachment');
        $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)->insert([
            'uuid' => '00000000-0000-4000-8000-000000000002',
            'job_id' => $jobId,
            'source_attachment_id' => '200',
            'logical_source_id' => '200',
            'source_page_id' => '100',
            'source_version' => 1,
            'original_name' => 'private.bin',
            'mime_type' => 'application/octet-stream',
            'file_size' => filesize($path),
            'storage_path' => $path,
            'target_workspace_id' => $workspaceId,
            'target_node_id' => $nodeId,
            'target_document_key' => 'test-document',
            'status' => 'stored',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
