<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceArchive;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportConfig;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZipArchive;

#[CoversClass(ConfluenceArchive::class)]
final class ConfluenceArchiveTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/simbioza-confluence-archive-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0770, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            is_dir($path) ? rmdir($path) : unlink($path);
        }
        @rmdir($this->directory);
    }

    /** HR: Čita valjani export i pronalazi fizičku verziju privitka kada XML broj odstupa. EN: Reads a valid export and finds the physical attachment version when its XML number differs. */
    public function testInspectAndAttachmentVersionFallback(): void
    {
        $path = $this->archive([
            'entities.xml' => '<hibernate-generic/>',
            'exportDescriptor.properties' => "exportType=space\nspaceKey=TEST\n",
            'attachments/10/20/1' => 'attachment-body',
        ]);
        $archive = $this->service();

        $inspection = $archive->inspect($path);
        self::assertSame('TEST', $inspection['descriptor']['spaceKey']);

        $target = $this->directory . '/copied.bin';
        $archive->copyAttachment($path, '10', '20', 99, $target);
        self::assertSame('attachment-body', file_get_contents($target));
    }

    /** HR: Odbija traversal zapis prije ikakva izdvajanja. EN: Rejects a traversal entry before extracting anything. */
    public function testRejectsTraversalEntry(): void
    {
        $path = $this->archive([
            'entities.xml' => '<hibernate-generic/>',
            'exportDescriptor.properties' => "exportType=space\n",
            '../outside.txt' => 'unsafe',
        ]);

        $this->expectException(ConfluenceImportException::class);
        $this->service()->inspect($path);
    }

    /** @param array<string,string> $entries */
    private function archive(array $entries): string
    {
        $path = $this->directory . '/export-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($entries as $name => $contents) {
            self::assertTrue($zip->addFromString($name, $contents));
        }
        self::assertTrue($zip->close());

        return $path;
    }

    private function service(): ConfluenceArchive
    {
        $config = new class (new Helper(), [], $this->directory) extends Config {
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

        return new ConfluenceArchive(new ConfluenceImportConfig($config, dirname(__DIR__)));
    }
}
