<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceArchive;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceExportReader;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceExportScanner;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportConfig;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteContentIndex;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * EN: Verifies out-of-order relations and source attachment preservation in an isolated site ZIP.
 *
 * HR: Provjerava veze neovisne o redoslijedu i očuvanje izvornih privitaka u izoliranom ZIP-u sitea.
 */
final class ConfluenceSiteContentIndexTest extends TestCase
{
    private string $root;
    private string $path;
    private ConfluenceSiteContentIndex $index;
    private ConfluenceArchive $archive;
    private ConfluenceExportScanner $scanner;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/confluence-c3-index-' . bin2hex(random_bytes(6));
        $config = new ConfluenceImportConfig(new Config(new Helper(), [], $this->root), dirname(__DIR__));
        mkdir($config->uploadDirectory(), 0770, true);
        $this->path = $config->uploadDirectory() . '/source.zip';
        $this->archive = new ConfluenceArchive($config);
        $reader = new ConfluenceExportReader($this->archive);
        $this->scanner = new ConfluenceExportScanner($this->archive, $reader);
        $this->index = new ConfluenceSiteContentIndex($this->archive, $reader, $config);
        $zip = new ZipArchive();
        $zip->open($this->path, ZipArchive::CREATE);
        $zip->addFromString('exportDescriptor.properties', "exportType=site\nbackupAttachments=true\n");
        $zip->addFromString('attachments/10/20/1', 'private binary payload');
        $zip->addFromString('entities.xml', '<hibernate-generic>'
            . $this->object('BodyContent', '100', ['content_ref' => '10', 'body' => '<p>Body A</p>'])
            . $this->object('BodyContent', '101', ['content_ref' => '11', 'body' => '<p>Body B</p>'])
            . $this->object('BodyContent', '102', ['content_ref' => '21', 'body' => 'Comment body'])
            . $this->object('ContentPermission', '32', ['owningSet_ref' => '10', 'userSubject_ref' => 'user-a'])
            . $this->object('ContentPermissionSet', '10', ['owningContent_ref' => '10', 'type' => 'View'])
            . $this->object('ContentProperty', '60', ['content_ref' => '20', 'name' => 'FILESIZE', 'longValue' => '22'])
            . $this->object('Labelling', '70', ['content_ref' => '10', 'label_ref' => '71'])
            . $this->object('Label', '71', ['name' => 'example'])
            . $this->object('SpacePermission', '30', ['space_ref' => '1', 'type' => 'VIEWSPACE', 'group' => 'staff'])
            . $this->object('Attachment', '20', ['containerContent_ref' => '10', 'title' => 'file.txt', 'version' => '1'])
            . $this->object('Comment', '21', ['containerContent_ref' => '20', 'creator_ref' => 'user-a'])
            . $this->object('Page', '10', ['space_ref' => '1', 'title' => 'A', 'version' => '1'])
            . $this->object('Page', '11', ['space_ref' => '2', 'title' => 'B', 'version' => '1'])
            . $this->object('Space', '1', ['key' => 'A', 'name' => 'Area A', 'spaceType' => 'global'])
            . $this->object('Space', '2', ['key' => 'B', 'name' => 'Area B', 'spaceType' => 'global'])
            . $this->object('InternalUser', '40', ['password' => 'SECRET-PASSWORD'])
            . $this->object('PluginSecret', '50', ['token' => 'SECRET-TOKEN']) . '</hibernate-generic>');
        $zip->close();
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    /**
     * EN: Indirect bodies/attachments/comments/ACL survive unordered source records without leaking other spaces.
     *
     * HR: Posredna tijela/privici/komentari/ACL preživljavaju neuređene izvorne zapise bez curenja drugih područja.
     */
    public function testScopedObjectsAndOrdinaryScannerAreEquivalent(): void
    {
        $hash = hash_file('sha256', $this->path);
        $this->index->prepare($this->path, $hash);
        $objects = iterator_to_array($this->index->objects($this->path, '1'));
        $classes = array_count_values(array_map(static fn(\AaiEduHr\SimbiozaModuleConfluenceImport\Value\ConfluenceObject $object): string => $object->className, $objects));
        self::assertSame(2, $classes['BodyContent']);
        self::assertSame(1, $classes['Attachment']);
        self::assertSame(1, $classes['Comment']);
        self::assertSame(1, $classes['ContentPermission']);
        self::assertSame(1, $classes['ContentPermissionSet']);
        $scan = $this->scanner->scanObjects($objects, ['descriptor' => ['backupAttachments' => 'true']]);
        self::assertSame('A', $scan['spaces'][0]['source_key']);
        self::assertSame('A', $scan['pages'][0]['title']);
        self::assertSame(['example'], $scan['page_labels'][10]);
        self::assertSame('20', $scan['attachments'][0]['source_id']);
        self::assertSame($hash, hash_file('sha256', $this->path));
        $content = file_get_contents($this->path . '.content/' . hash('sha256', '1') . '.jsonl');
        self::assertStringNotContainsString('SECRET-', $content);
        self::assertStringNotContainsString('Body B', $content);
        self::assertSame($this->index->prepare($this->path, $hash), $this->index->prepare($this->path, $hash));
    }

    /**
     * EN: The shared original ZIP still supplies exactly the original attachment binary.
     *
     * HR: Zajednički izvorni ZIP i dalje daje točno izvorni binarni privitak.
     */
    public function testOriginalAttachmentRemainsAvailableAfterIndexing(): void
    {
        $this->index->prepare($this->path, hash_file('sha256', $this->path));
        $target = $this->root . '/attachment.bin';
        $this->archive->copyAttachment($this->path, '10', '20', 1, $target);
        self::assertSame('private binary payload', file_get_contents($target));
    }

    /**
     * EN: A complete manifest alone is insufficient after a partition is removed.
     *
     * HR: Potpuna manifest datoteka nije dovoljna nakon uklanjanja podjele.
     */
    public function testMissingCachedPartitionIsRebuilt(): void
    {
        $hash = hash_file('sha256', $this->path);
        $this->index->prepare($this->path, $hash, ['1']);
        $file = $this->path . '.content/' . hash('sha256', '1') . '.jsonl';
        unlink($file);
        $this->index->prepare($this->path, $hash, ['1']);
        self::assertFileExists($file);
        self::assertNotEmpty(iterator_to_array($this->index->objects($this->path, '1')));
    }

    /**
     * EN: Large opaque bodies never impersonate references, while selected CDATA stays byte-complete.
     * Apparent CDATA inside XML comments is not a real literal boundary.
     *
     * HR: Velika neprozirna tijela ne oponašaju reference, a odabrana CDATA ostaje potpuna.
     * Prividna CDATA u XML komentaru nije stvarna granica literala.
     */
    public function testLargeCdataOwnershipLookupPreservesSelectedBody(): void
    {
        $body = '<p>Odabrano žćš</p>' . str_repeat('opaque text ', 200000);
        $fake = str_repeat('not selected ', 200000) . '<property name="content"><id>10</id></property>';
        $zip = new ZipArchive();
        $zip->open($this->path);
        $xml = $zip->getFromName('entities.xml');
        foreach (['100' => ['10', $body, 'Body A'], '101' => ['11', $fake, 'Body B']] as $id => [$owner, $html, $old]) {
            $original = $this->object('BodyContent', (string)$id, ['content_ref' => $owner, 'body' => '<p>' . $old . '</p>']);
            $replacement = '<object class="BodyContent"><id>' . $id . '</id><!-- <![CDATA[example]]> -->'
                . '<property name="body"><![CDATA[' . $html . ']]></property>'
                . '<property name="content"><id>' . $owner . '</id></property></object>';
            $xml = str_replace($original, $replacement, $xml);
        }
        $zip->addFromString('entities.xml', $xml);
        $zip->setCompressionName('entities.xml', ZipArchive::CM_STORE);
        $zip->close();
        $this->index->prepare($this->path, hash_file('sha256', $this->path), ['1']);
        $bodies = [];
        foreach ($this->index->objects($this->path, '1') as $object) {
            if ($object->className === 'BodyContent') {
                $bodies[$object->string('id')] = $object->string('body');
            }
        }
        self::assertSame(hash('sha256', trim($body)), hash('sha256', $bodies['100']));
        self::assertArrayNotHasKey('101', $bodies);
    }

    /**
     * EN: Incomplete preparation is never an importable index; replacement bytes fail their old fingerprint.
     *
     * HR: Nepotpuna priprema nikada nije upotrebljiv indeks; zamijenjeni bajtovi ne prolaze stari otisak.
     */
    public function testSourceReplacementAndIncompleteIndexAreRejected(): void
    {
        $hash = hash_file('sha256', $this->path);
        $zip = new ZipArchive();
        $zip->open($this->path);
        $zip->addFromString('changed.txt', 'changed');
        $zip->close();
        $this->expectException(ConfluenceImportException::class);
        $this->index->prepare($this->path, $hash);
    }

    /**
     * EN: Filesystem links cannot redirect index writes outside private module data.
     *
     * HR: Filesystem poveznice ne smiju preusmjeriti upis indeksa izvan privatnih podataka modula.
     */
    public function testLinkedPartitionIsRejected(): void
    {
        mkdir($this->path . '.content', 0770);
        symlink($this->root . '/outside', $this->path . '.content/objects.jsonl');
        $this->expectException(ConfluenceImportException::class);
        $this->index->prepare($this->path, hash_file('sha256', $this->path));
    }

    /**
     * EN: Default indexing never spools unselected spaces or historical body text; an explicit history policy rebuilds it.
     *
     * HR: Zadano indeksiranje ne zapisuje neodabrana područja ni povijesna tijela; izričito pravilo povijesti obnavlja indeks.
     */
    public function testSelectedSpaceAndBodyPolicyArePartOfTheCacheIdentity(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->path);
        $xml = $zip->getFromName('entities.xml');
        $extra = $this->object('Page', '12', ['space_ref' => '1', 'title' => 'Old A', 'version' => '1', 'originalVersion_ref' => '10'])
            . $this->object('BodyContent', '103', ['content_ref' => '12', 'body' => 'Historical body must not be staged by default']);
        $zip->addFromString('entities.xml', str_replace('</hibernate-generic>', $extra . '</hibernate-generic>', $xml));
        $zip->close();
        $hash = hash_file('sha256', $this->path);
        $first = $this->index->prepare($this->path, $hash, ['1']);
        $file = $this->path . '.content/' . hash('sha256', '1') . '.jsonl';
        self::assertCount(1, $first['counts']);
        self::assertFileDoesNotExist($this->path . '.content/objects.jsonl');
        self::assertFileDoesNotExist($this->path . '.content/' . hash('sha256', '2') . '.jsonl');
        self::assertStringNotContainsString('Historical body', file_get_contents($file));
        self::assertStringNotContainsString('Body B', file_get_contents($file));
        self::assertSame(0660, fileperms($file) & 0777);
        $second = $this->index->prepare($this->path, $hash, ['1'], ['include_history' => true]);
        self::assertNotSame($first['scope_sha256'], $second['scope_sha256']);
        self::assertStringContainsString('Historical body', file_get_contents($file));
        self::assertSame($hash, hash_file('sha256', $this->path));
    }

    /**
     * EN: Encodes a small isolated Hibernate object, never live source content.
     *
     * HR: Kodira mali izolirani Hibernate objekt, nikada živi izvorni sadržaj.
     *
     * @param array<string,string> $values
     */
    private function object(string $class, string $id, array $values): string
    {
        $xml = '<object class="' . $class . '"><id name="id">' . $id . '</id>';
        foreach ($values as $key => $value) {
            $ref = str_ends_with($key, '_ref');
            $xml .= '<property name="' . ($ref ? substr($key, 0, -4) : $key) . '"' . ($ref ? ' class="Reference"' : '') . '>'
                . ($ref ? '<id name="id">' . $value . '</id>' : htmlspecialchars($value, ENT_XML1)) . '</property>';
        }
        return $xml . '</object>';
    }
}
