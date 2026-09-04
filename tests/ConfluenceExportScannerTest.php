<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceArchive;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceExportReader;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceExportScanner;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportConfig;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZipArchive;

#[CoversClass(ConfluenceExportScanner::class)]
final class ConfluenceExportScannerTest extends TestCase
{
    /** HR: Dokazuje inventar područja, povijesti, korisnika, ACL grupe i makroa jednim stream prolazom. EN: Proves Workspace, history, user, ACL-group, and macro inventory in one streaming pass. */
    public function testScansConfluenceSpaceInventory(): void
    {
        $directory = sys_get_temp_dir() . '/simbioza-confluence-scan-' . bin2hex(random_bytes(8));
        mkdir($directory, 0770, true);
        $path = $directory . '/space.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        self::assertTrue($zip->addFromString('exportDescriptor.properties', "exportType=space\nspaceKey=DEMO\ncreatedByVersionNumber=9.2\nbackupAttachments=true\n"));
        self::assertTrue($zip->addFromString('entities.xml', $this->entities()));
        self::assertTrue($zip->close());

        $config = new class (new Helper(), [], $directory) extends Config {
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
        $archive = new ConfluenceArchive(new ConfluenceImportConfig($config, dirname(__DIR__)));
        $scan = (new ConfluenceExportScanner($archive, new ConfluenceExportReader($archive)))->scan($path);

        self::assertSame('DEMO', $scan['spaces'][0]['source_key']);
        self::assertSame('Demo Workspace', $scan['spaces'][0]['name']);
        self::assertSame('100', $scan['spaces'][0]['home_page_id']);
        self::assertSame(1, $scan['statuses']['current']);
        self::assertSame(1, $scan['statuses']['history']);
        self::assertSame('team-demo', $scan['groups'][0]['source_name']);
        self::assertSame(1, $scan['macros']['info']);
        self::assertSame(1, $scan['macros']['jira']);
        self::assertSame(1, $scan['macros']['calendar']);
        self::assertSame([[
            'source_id' => 'c1',
            'name' => 'Demo team calendar',
            'source_uuid' => '3c1a6576-55e6-4776-9296-b95a00f980b7',
        ]], $scan['calendars']);
        self::assertCount(2, $scan['warnings']);
        self::assertCount(1, $scan['users']);

        unlink($path);
        rmdir($directory);
    }

    private function entities(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<hibernate-generic>
  <object class="Space" package="com.atlassian.confluence.spaces"><id name="id">1</id><property name="key">DEMO</property><property name="name">Demo Workspace</property><property name="spaceType">global</property><property name="homePage"><id>100</id></property><property name="creator"><id>u1</id></property></object>
  <object class="ConfluenceUserImpl" package="com.atlassian.confluence.user"><id name="id">u1</id><property name="username">demo@example.test</property><property name="displayName">Demo User</property><property name="emailAddress">demo@example.test</property></object>
  <object class="Page" package="com.atlassian.confluence.pages"><id name="id">100</id><property name="space"><id>1</id></property><property name="title">Home</property><property name="version">2</property><property name="contentStatus">current</property></object>
  <object class="Page" package="com.atlassian.confluence.pages"><id name="id">90</id><property name="originalVersion"><id>100</id></property><property name="space"><id>1</id></property><property name="title">Home</property><property name="version">1</property><property name="contentStatus">current</property></object>
  <object class="BodyContent" package="com.atlassian.confluence.core"><id name="id">b1</id><property name="content"><id>100</id></property><property name="body">&lt;ac:structured-macro ac:name="info"/&gt;&lt;ac:structured-macro ac:name="jira"/&gt;&lt;ac:structured-macro ac:name="calendar"&gt;&lt;ac:parameter ac:name="id"&gt;3c1a6576-55e6-4776-9296-b95a00f980b7&lt;/ac:parameter&gt;&lt;/ac:structured-macro&gt;</property></object>
  <object class="CustomContentEntityObject" package="com.atlassian.confluence.content"><id name="id">c1</id><property name="title">Demo team calendar</property><property name="pluginModuleKey">com.atlassian.confluence.extra.team-calendars:calendar-content-type</property></object>
  <object class="ContentProperty" package="com.atlassian.confluence.core"><id name="id">cp1</id><property name="content"><id>c1</id></property><property name="name">subCalendarId</property><property name="stringValue">3c1a6576-55e6-4776-9296-b95a00f980b7</property></object>
  <object class="SpacePermission" package="com.atlassian.confluence.security"><id name="id">p1</id><property name="space"><id>1</id></property><property name="type">VIEWSPACE</property><property name="group">team-demo</property></object>
</hibernate-generic>
XML;
    }
}
