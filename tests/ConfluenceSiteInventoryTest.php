<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceArchive;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceExportReader;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceExportScanner;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportConfig;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceInventoryReader;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteInventory;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ConfluenceSiteInventoryTest extends TestCase
{
    private string $root;
    private ConfluenceImportConfig $config;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/confluence-site-c1-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0770);
        $this->config = new ConfluenceImportConfig(new Config(new Helper(), [], $this->root), dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    /**
     * EN: The descriptor, not a misleading name or the number of spaces, identifies a site.
     *
     * HR: Descriptor, ne zavaravajući naziv ni broj područja, identificira site.
     */
    public function testSiteRecognitionAndSecretFreeCachedInventory(): void
    {
        $path = $this->fixture('site', 2);
        $service = $this->service();
        $scan = $service->scan($path);
        self::assertSame('site', $scan['archive_type']);
        self::assertTrue($scan['inventory_only']);
        self::assertCount(2, $scan['spaces']);
        self::assertSame(1, $scan['statuses']['current']);
        self::assertSame(1, $scan['counts']['InternalUser']);
        self::assertSame(0, $scan['source']['attachment_files']);
        self::assertFalse($scan['source']['attachments']);
        self::assertFalse($scan['inventory_cached']);
        $json = json_encode($scan, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret-password-hash', $json);
        self::assertStringNotContainsString('secret-token', $json);
        self::assertStringNotContainsString('document-body', $json);
        self::assertTrue($service->scan($path)['inventory_cached']);
        $indexes = glob($this->config->dataDirectory() . '/inventory/*.json');
        self::assertCount(1, $indexes);
        self::assertSame(0660, fileperms($indexes[0]) & 0777);
        self::assertStringNotContainsString('secret-', file_get_contents($indexes[0]));
    }

    /**
     * EN: A one-space site stays a site, whereas a multi-space descriptor opens a catalog.
     *
     * HR: Site s jednim područjem ostaje site, a descriptor više područja otvara pregled.
     */
    public function testSingleSpaceSiteAndMultiSpaceExport(): void
    {
        self::assertSame('site', $this->service()->scan($this->fixture('site', 1))['archive_type']);
        $scan = $this->service()->scan($this->fixture('space', 2));
        self::assertSame('multi_space', $scan['archive_type']);
        self::assertSame(1, $scan['spaces'][0]['content_counts']['Page']['current']);
    }

    /**
     * EN: A cache cannot be redirected through an existing filesystem link.
     *
     * HR: Postojeća filesystem poveznica ne može preusmjeriti indeks.
     */
    public function testRejectsLinkedIndex(): void
    {
        $path = $this->fixture('site', 1);
        $cache = $this->config->dataDirectory() . '/inventory';
        mkdir($cache, 0770, true);
        symlink($this->root . '/outside', $cache . '/linked.json');
        $this->expectException(ConfluenceImportException::class);
        $this->inventory()->scan($path, $cache . '/linked.json');
    }

    /**
     * EN: Changed source bytes use a different index and never reuse the previous inventory.
     *
     * HR: Promijenjeni bajtovi izvora koriste drugi indeks, ne prethodni pregled.
     */
    public function testChangedSourceInvalidatesCache(): void
    {
        $path = $this->fixture('site', 1);
        $first = $this->service()->scan($path);
        $zip = new ZipArchive();
        $zip->open($path);
        $zip->addFromString('extra.txt', 'changed');
        $zip->close();
        $next = $this->service()->scan($path);
        self::assertNotSame($first['source_sha256'], $next['source_sha256']);
        self::assertFalse($next['inventory_cached']);
    }

    /**
     * EN: All existing confirmed-import entry points share a fail-closed inventory guard.
     *
     * HR: Svi postojeći ulazi potvrđenog importa dijele zatvorenu zaštitu inventara.
     */
    public function testConfirmedSpacePathRejectsSitePreview(): void
    {
        $reflection = new \ReflectionClass(\AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $guard = $reflection->getMethod('assertSingleSpaceInventory');
        $guard->invoke($service, ['spaces' => [['source_id' => '1']]]);
        $this->expectException(ConfluenceImportException::class);
        $guard->invoke($service, ['inventory_only' => true, 'spaces' => [['source_id' => '1']]]);
    }

    /**
     * EN: Multi-space descriptors must identify the actual spaces, not only their count.
     *
     * HR: Descriptor više područja mora navesti stvarna područja, ne samo njihov broj.
     */
    public function testRejectsMismatchedMultiSpaceDescriptor(): void
    {
        $path = $this->fixture('space', 2);
        $zip = new ZipArchive();
        $zip->open($path);
        $zip->addFromString('exportDescriptor.properties', "exportType=space\nspaceKeys=SPACE1,OTHER\n");
        $zip->close();
        $this->expectException(ConfluenceImportException::class);
        $this->service()->scan($path);
    }

    /**
     * EN: Physical attachment files are counted independently of XML attachment records.
     *
     * HR: Stvarne datoteke privitaka broje se odvojeno od XML zapisa privitaka.
     */
    public function testSiteWithAttachmentFiles(): void
    {
        $path = $this->fixture('site', 1);
        $zip = new ZipArchive();
        $zip->open($path);
        $zip->addFromString('exportDescriptor.properties', "exportType=site\nbackupAttachments=true\n");
        $zip->addFromString('attachments/10/20/1', 'binary-content');
        $zip->close();
        $scan = $this->service()->scan($path);
        self::assertTrue($scan['source']['attachments']);
        self::assertSame(1, $scan['source']['attachment_files']);
        self::assertSame(0, $scan['attachment_counts']['source_records']);
    }

    /**
     * EN: Administrator policy bounds both uncompressed site entries and retained spaces.
     *
     * HR: Administratorska politika ograničava raspakirane zapise sitea i zadržana područja.
     */
    public function testConfiguredSiteLimitsAreEnforced(): void
    {
        $path = $this->fixture('site', 2);
        foreach ([['max_site_entry_size' => 100], ['max_inventory_spaces' => 1]] as $limits) {
            $this->config = new ConfluenceImportConfig(new Config(new Helper(), [
                'confluence-import' => ['confluence-import' => $limits],
            ], $this->root), dirname(__DIR__));
            try {
                $this->service()->scan($path);
                self::fail('Configured site limit was ignored.');
            } catch (ConfluenceImportException) {
                self::assertTrue(true);
            }
        }
    }

    /**
     * EN: A small space count must not allow unbounded names into the retained catalog.
     *
     * HR: Mali broj područja ne smije dopustiti neograničene nazive u zadržanom pregledu.
     */
    public function testRetainedSpaceFieldsAreBounded(): void
    {
        $path = $this->fixture('site', 1);
        $zip = new ZipArchive();
        $zip->open($path);
        $zip->addFromString('entities.xml', '<hibernate-generic><object class="Space"><id name="id">1</id><property name="name">' . str_repeat('x', 2049) . '</property></object></hibernate-generic>');
        $zip->close();
        $this->expectException(ConfluenceImportException::class);
        $this->service()->scan($path);
    }

    private function inventory(): ConfluenceSiteInventory
    {
        $archive = new ConfluenceArchive($this->config);
        $reader = new ConfluenceExportReader($archive);
        return new ConfluenceSiteInventory($archive, new ConfluenceInventoryReader($archive, $reader, $this->config), $this->config);
    }

    private function service(): ConfluenceExportScanner
    {
        $archive = new ConfluenceArchive($this->config);
        return new ConfluenceExportScanner($archive, new ConfluenceExportReader($archive), $this->inventory());
    }

    private function fixture(string $type, int $spaces): string
    {
        $path = $this->root . '/' . bin2hex(random_bytes(4)) . '-misleading-space-name.zip';
        $xml = '<hibernate-generic>';
        $keys = [];
        for ($i = 1; $i <= $spaces; ++$i) {
            $keys[] = 'SPACE' . $i;
            $xml .= '<object class="Space"><id name="id">' . $i . '</id><property name="key">SPACE' . $i . '</property><property name="name">Space ' . $i . '</property><property name="spaceType">' . ($i === 1 ? 'global' : 'personal') . '</property></object>';
        }
        $xml .= '<object class="Page"><id name="id">10</id><property name="space"><id>1</id></property><property name="contentStatus">current</property></object>'
            . '<object class="InternalUser"><property name="credential">secret-password-hash</property></object>'
            . '<object class="Secrets"><property name="token">secret-token</property></object>'
            . '<object class="BodyContent"><property name="body"><![CDATA[document-body<object class="Fake"/>]]></property></object>'
            . '</hibernate-generic>';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE));
        $zip->addFromString('exportDescriptor.properties', 'exportType=' . $type . "\nspaceKeys=" . implode(',', $keys) . "\nbackupAttachments=false\n");
        $zip->addFromString('entities.xml', $xml);
        $zip->close();
        return $path;
    }
}
