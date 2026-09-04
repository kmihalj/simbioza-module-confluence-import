<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportRepository;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfluenceImportRepository::class)]
final class ConfluenceAttachmentRegistrationTest extends TestCase
{
    /**
     * HR: Dokazuje prijelaz privitka iz privremene Confluence pohrane u vlasništvo Editora.
     * EN: Proves the attachment transition from Confluence staging to Editor ownership.
     */
    public function testMarksStagedAttachmentAsRegisteredEditorAsset(): void
    {
        $repository = $this->repository();
        $saved = $repository->recordAttachment([
            'source_attachment_id' => 'attachment-1',
            'logical_source_id' => 'attachment-1',
            'source_page_id' => 'page-1',
            'source_version' => 1,
            'original_name' => 'demo.tar.gz',
            'mime_type' => 'application/gzip',
            'file_size' => 17,
            'storage_path' => '/private/staged.bin',
            'workspace_id' => 9,
            'status' => 'stored',
        ], 1);

        $repository->attachImportedAttachmentsToPage('page-1', 9, 12, 'document-key');
        $staged = $repository->storedAttachmentsForPage('page-1', 9);
        self::assertCount(1, $staged);
        self::assertSame((string)$saved['uuid'], (string)$staged[0]['uuid']);

        $repository->markAttachmentRegistered((int)$staged[0]['id'], 12, 'document-key');

        self::assertSame([], $repository->storedAttachmentsForPage('page-1', 9));
        $registered = $repository->attachmentBySourceVersion(1, 'attachment-1', 1);
        self::assertIsArray($registered);
        self::assertSame('registered', $registered['status']);
        self::assertNull($registered['storage_path']);
        self::assertSame(12, (int)$registered['target_node_id']);
        self::assertSame('document-key', $registered['target_document_key']);
    }

    /**
     * HR: Dokazuje da dva odvojena importa istog Confluence privitka dobivaju
     *     zasebne identitete i ne mogu preuzeti vlasništvo jedan drugome.
     * EN: Proves that two separate imports of the same Confluence attachment
     *     receive distinct identities and cannot take ownership from each other.
     */
    public function testScopesAttachmentIdentityToImportJob(): void
    {
        $repository = $this->repository();
        $first = $repository->recordAttachment([
            'source_attachment_id' => 'attachment-1',
            'logical_source_id' => 'attachment-1',
            'source_page_id' => 'page-1',
            'source_version' => 1,
            'original_name' => 'demo.tar.gz',
            'mime_type' => 'application/gzip',
            'file_size' => 17,
            'storage_path' => '/private/first.bin',
            'workspace_id' => 9,
            'status' => 'stored',
        ], 1);
        $second = $repository->recordAttachment([
            'source_attachment_id' => 'attachment-1',
            'logical_source_id' => 'attachment-1',
            'source_page_id' => 'page-1',
            'source_version' => 1,
            'original_name' => 'demo.tar.gz',
            'mime_type' => 'application/gzip',
            'file_size' => 17,
            'storage_path' => '/private/second.bin',
            'workspace_id' => 10,
            'status' => 'stored',
        ], 2);

        self::assertNotSame((string)$first['uuid'], (string)$second['uuid']);
        self::assertSame((int)$first['id'], (int)$repository->attachmentBySourceVersion(1, 'attachment-1', 1)['id']);
        self::assertSame((int)$second['id'], (int)$repository->attachmentBySourceVersion(2, 'attachment-1', 1)['id']);
    }

    /** HR: Zamjenski import može sačuvati javne identitete stranica i privitaka. EN: A replacement import can preserve public page and attachment identities. */
    public function testExposesAndAcceptsReplacementIdentities(): void
    {
        $repository = $this->repository();
        $uuid = '123e4567-e89b-42d3-a456-426614174000';
        $repository->mapContent([
            'source_id' => 'page-version-2',
            'logical_source_id' => 'page-1',
            'source_type' => 'page',
            'status' => 'current',
            'version' => 2,
            'title' => 'Renamed page',
        ], [
            'source_space_key' => 'DOCS',
            'workspace_id' => 9,
            'node_id' => 12,
            'document_key' => 'document-key',
            'slug' => 'stable-page',
        ], 1);
        $saved = $repository->recordAttachment([
            'uuid' => $uuid,
            'source_attachment_id' => 'attachment-version-3',
            'logical_source_id' => 'attachment-1',
            'source_page_id' => 'page-1',
            'source_version' => 3,
            'original_name' => 'demo.pdf',
            'workspace_id' => 9,
            'status' => 'registered',
        ], 1);

        self::assertSame(['page-1' => 'stable-page'], $repository->pageSlugsByWorkspace(9));
        self::assertSame(
            ['attachment-version-3:3' => $uuid],
            $repository->attachmentUuidsByWorkspace(9),
        );
        self::assertSame($uuid, $saved['uuid']);
    }

    private function repository(): ConfluenceImportRepository
    {
        $helper = new Helper();
        $database = new Database(new Config($helper, [
            'database' => ['connections' => ['default' => ['driver' => 'sqlite', 'database' => ':memory:']]],
        ]), $helper);
        $migration = require dirname(__DIR__) . '/resources/migrations/initial_simbioza_confluence_import_schema.php';
        $migration->up($database);

        return new ConfluenceImportRepository($database);
    }
}
