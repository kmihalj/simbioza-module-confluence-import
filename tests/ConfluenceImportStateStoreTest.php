<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportStateStore;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(ConfluenceImportStateStore::class)]
final class ConfluenceImportStateStoreTest extends TestCase
{
    private string $directory = '';

    protected function tearDown(): void
    {
        if ($this->directory === '' || !is_dir($this->directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->directory);
    }

    /** HR: Veliki manifest se ne prepisuje pri svakom koraku. EN: Large manifest is not rewritten on every step. */
    public function testSeparatesImmutableManifestFromMutableProgress(): void
    {
        $store = new ConfluenceImportStateStore();
        $directory = $this->temporaryDirectory();
        $store->initialize($directory, [
            'version' => 1,
            'phase' => 'attachments',
            'dataset' => ['attachments' => array_fill(0, 100, ['id' => 'asset'])],
            'pages' => ['page-1' => [['title' => 'One']]],
            'targets' => ['page-1' => ['slug' => 'one']],
            'render_context' => ['macro_pages' => ['page-1' => ['title' => 'One']]],
            'attachment_offset' => 0,
        ]);

        $dynamic = json_decode((string)file_get_contents($store->statePath($directory)), true);
        self::assertIsArray($dynamic);
        self::assertSame(2, $dynamic['version'] ?? null);
        self::assertArrayNotHasKey('dataset', $dynamic);
        self::assertArrayNotHasKey('pages', $dynamic);
        self::assertFileExists($store->manifestPath($directory));
        self::assertLessThan(
            filesize($store->manifestPath($directory)),
            filesize($store->statePath($directory)),
        );

        $loaded = $store->load($directory);
        self::assertCount(100, $loaded['dataset']['attachments'] ?? []);
        $manifestInode = fileinode($store->manifestPath($directory));
        self::assertIsInt($manifestInode);

        $loaded['phase'] = 'pages';
        $loaded['attachment_offset'] = 100;
        $store->save($directory, $loaded);

        self::assertSame($manifestInode, fileinode($store->manifestPath($directory)));
        $resumed = $store->load($directory);
        self::assertSame('pages', $resumed['phase'] ?? null);
        self::assertSame(100, $resumed['attachment_offset'] ?? null);
        self::assertCount(100, $resumed['dataset']['attachments'] ?? []);
    }

    /** HR: Nedovršeni posao starog izdanja ostaje nastaviv. EN: An unfinished legacy job remains resumable. */
    public function testLoadsAndSavesLegacySingleFileState(): void
    {
        $store = new ConfluenceImportStateStore();
        $directory = $this->temporaryDirectory();
        file_put_contents($store->statePath($directory), json_encode([
            'version' => 1,
            'phase' => 'pages',
            'dataset' => ['pages' => ['legacy']],
        ], JSON_THROW_ON_ERROR));

        $loaded = $store->load($directory);
        self::assertSame(['pages' => ['legacy']], $loaded['dataset'] ?? null);
        $loaded['phase'] = 'finalizing';
        $store->save($directory, $loaded);

        self::assertSame('finalizing', $store->load($directory)['phase'] ?? null);
        self::assertFileDoesNotExist($store->manifestPath($directory));
    }

    private function temporaryDirectory(): string
    {
        $this->directory = sys_get_temp_dir() . '/simbioza_confluence_state_' . uniqid('', true);
        self::assertTrue(mkdir($this->directory, 0777, true));

        return $this->directory;
    }
}
