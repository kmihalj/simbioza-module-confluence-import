<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceArchive;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceExportReader;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportConfig;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSitePrincipals;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ConfluenceSitePrincipalsTest extends TestCase
{
    private string $root;
    private ConfluenceImportConfig $config;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/confluence-site-identities-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/data/confluence-import/uploads', 0770, true);
        $this->config = new ConfluenceImportConfig(new Config(new Helper(), [], $this->root), dirname(__DIR__));
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    /**
     * EN: Directory accounts and Confluence author keys describe the same person only when unambiguous.
     * All credential, token and body canaries must disappear from the private index as well.
     *
     * HR: Račun imenika i Confluence autorski ključ opisuju istu osobu samo pri jednoznačnom povezivanju.
     * Sve oznake vjerodajnica, tokena i tijela moraju izostati i iz privatnog indeksa.
     */
    public function testAccountsAliasesMembershipsAndSecretFiltering(): void
    {
        $path = $this->fixture();
        $hash = (string)hash_file('sha256', $path);
        self::assertNull($this->service()->cached($path, $hash));
        $catalog = $this->service()->scan($path, $hash);
        self::assertCount(2, $catalog['users']);
        self::assertSame(['confluence-user:key-alice'], $catalog['users'][0]['aliases']);
        self::assertSame('Primary directory', $catalog['users'][0]['directory_name']);
        self::assertTrue($catalog['users'][1]['reference_only']);
        self::assertCount(2, $catalog['groups']);
        self::assertSame('staff', $catalog['groups'][0]['name']);
        self::assertSame('staff', $catalog['groups'][1]['name']);
        self::assertNotSame($catalog['groups'][0]['source_id'], $catalog['groups'][1]['source_id']);
        self::assertSame('internal-user:1', $catalog['memberships'][0]['user_id']);
        self::assertSame('internal-group:11', $catalog['memberships'][0]['group_id']);
        self::assertSame('VIEWSPACE', $catalog['permissions'][0]['type']);
        self::assertSame($catalog, $this->service()->cached($path, $hash));
        $json = (string)file_get_contents($path . '.principals.json');
        self::assertStringNotContainsString('CANARY', $json);
        self::assertSame(0660, fileperms($path . '.principals.json') & 0777);
    }

    /**
     * EN: Identical names from different directories cannot absorb an author's identity automatically.
     *
     * HR: Ista imena različitih imenika ne mogu automatski preuzeti identitet autora.
     */
    public function testAmbiguousDirectoryNamesRequireAnExplicitAuthorMapping(): void
    {
        $path = $this->fixture($this->object('InternalUser', '2', ['name' => 'alice'], ['directory' => '20']));
        $catalog = $this->service()->scan($path, (string)hash_file('sha256', $path));
        self::assertCount(4, $catalog['users']);
        self::assertSame([], $catalog['users'][0]['aliases']);
        self::assertSame([], $catalog['users'][1]['aliases']);
        self::assertSame('confluence-user:key-alice', $catalog['users'][2]['source_id']);
    }

    /**
     * EN: A changed source invalidates a complete index before any identity can be selected.
     *
     * HR: Promijenjeni izvor poništava potpun indeks prije odabira identiteta.
     */
    public function testChangedArchiveAndIncorrectHashAreRejected(): void
    {
        $path = $this->fixture();
        $hash = (string)hash_file('sha256', $path);
        $this->service()->scan($path, $hash);
        $zip = new ZipArchive();
        $zip->open($path);
        $zip->addFromString('changed.txt', 'changed');
        $zip->close();
        self::assertNull($this->service()->cached($path, $hash));
        $this->expectException(ConfluenceImportException::class);
        $this->service()->scan($path, $hash);
    }

    /**
     * EN: The index must never follow a pre-existing link outside private import data.
     *
     * HR: Indeks nikada ne smije pratiti postojeću poveznicu izvan privatnih podataka importa.
     */
    public function testLinkedSidecarIsRejected(): void
    {
        $path = $this->fixture();
        symlink($this->root . '/outside.json', $path . '.principals.json');
        $this->expectException(ConfluenceImportException::class);
        $this->service()->scan($path, (string)hash_file('sha256', $path));
    }

    /**
     * EN: Duplicate access-record IDs are invalid even when identity/account IDs are distinct.
     *
     * HR: Ponovljeni ID-jevi zapisa pristupa nisu valjani ni kada su ID-jevi računa različiti.
     */
    public function testDuplicatePermissionRecordIdsAreRejected(): void
    {
        $path = $this->fixture($this->object('SpacePermission', '40', ['type' => 'ADMINSPACE']));
        $this->expectException(ConfluenceImportException::class);
        $this->service()->scan($path, (string)hash_file('sha256', $path));
    }

    private function service(): ConfluenceSitePrincipals
    {
        $archive = new ConfluenceArchive($this->config);
        return new ConfluenceSitePrincipals($archive, new ConfluenceExportReader($archive), $this->config);
    }

    /**
     * EN: Account flags do not override disabled directories; missing directory flags stay unknown.
     *
     * HR: Oznake računa ne nadjačavaju ugašene imenike; nedostajuće oznake imenika ostaju nepoznate.
     */
    public function testActivityComesFromExportedFlagsNotDirectoryNamesOrAccountFlagsAlone(): void
    {
        $path = $this->fixture($this->object('DirectoryImpl', '20', ['name' => 'Arbitrary external directory', 'active' => 'false', 'type' => 'CONNECTOR'])
            . $this->object('DirectoryImpl', '21', ['name' => 'Another directory', 'active' => 'true'])
            . $this->object('InternalUser', '30', ['name' => 'raw-active', 'active' => 'true'], ['directory' => '20'])
            . $this->object('InternalUser', '31', ['name' => 'disabled', 'active' => 'false'], ['directory' => '21'])
            . $this->object('InternalUser', '32', ['name' => 'enabled', 'active' => 'true'], ['directory' => '21']));
        $catalog = $this->service()->scan($path, (string)hash_file('sha256', $path));
        $users = array_column($catalog['users'], null, 'source_id');
        self::assertTrue($users['internal-user:30']['account_active']);
        self::assertFalse($users['internal-user:30']['directory_active']);
        self::assertFalse($users['internal-user:30']['active']);
        self::assertFalse($users['internal-user:31']['active']);
        self::assertTrue($users['internal-user:32']['active']);
        self::assertNull($users['internal-user:1']['directory_active']);
        self::assertNull($users['internal-user:1']['active']);
        self::assertFalse($catalog['groups'][1]['active']);
        self::assertNull($users['confluence-user:key-orphan']['account_active']);
    }

    private function fixture(string $extra = ''): string
    {
        $path = $this->config->uploadDirectory() . '/site.xml.zip';
        $xml = '<hibernate-generic>'
            . $this->object('DirectoryImpl', '10', ['name' => 'Primary directory', 'credential' => 'CANARY-directory'])
            . $this->object('InternalUser', '1', ['name' => 'alice', 'displayName' => 'Alice', 'active' => 'true',
                'emailAddress' => 'alice@example.invalid', 'credential' => 'CANARY-password'], ['directory' => '10'])
            . $extra
            . $this->object('ConfluenceUserImpl', 'key-alice', ['name' => 'ALICE'])
            . $this->object('ConfluenceUserImpl', 'key-orphan', ['name' => 'orphan'])
            . $this->object('InternalGroup', '11', ['name' => 'staff', 'active' => 'true'], ['directory' => '10'])
            . $this->object('InternalGroup', '12', ['name' => 'staff', 'active' => 'true'], ['directory' => '20'])
            . $this->object('HibernateMembership', '30', [], ['parentGroup' => '11', 'userMember' => '1'])
            . $this->object('SpacePermission', '40', ['type' => 'VIEWSPACE', 'group' => 'staff'], ['space' => '100'])
            . $this->object('BodyContent', '50', ['body' => 'CANARY-body'])
            . $this->object('AccessToken', '60', ['token' => 'CANARY-token']) . '</hibernate-generic>';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('exportDescriptor.properties', "exportType=site\nbackupAttachments=false\n");
        $zip->addFromString('entities.xml', $xml);
        $zip->close();
        return $path;
    }

    /** @param array<string,string> $values @param array<string,string> $references */
    private function object(string $class, string $id, array $values, array $references = []): string
    {
        $xml = '<object class="' . $class . '"><id name="id">' . $id . '</id>';
        foreach ($values as $key => $value) {
            $xml .= '<property name="' . $key . '">' . htmlspecialchars($value, ENT_XML1) . '</property>';
        }
        foreach ($references as $key => $value) {
            $xml .= '<property name="' . $key . '" class="Reference"><id name="id">' . $value . '</id></property>';
        }
        return $xml . '</object>';
    }
}
