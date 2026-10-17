<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use AaiEduHr\SimbiozaModuleConfluenceImport\Value\ConfluenceObject;

/**
 * EN: Partitions selected site content into private streams with two shared ZIP passes.
 * First resolve ownership, then retain only requested bodies; historical bodies are not spooled by default.
 * Binary attachments remain in the shared source ZIP until the normal importer copies them.
 *
 * HR: Dvama zajedničkim prolazima ZIP-a razdvaja odabrani sadržaj u privatne tokove.
 * Prvo razrješava vlasništvo, zatim čuva samo tražena tijela; povijest se zadano ne zapisuje.
 * Binarni privici ostaju u zajedničkom ZIP-u dok ih obični importer ne kopira.
 */
final readonly class ConfluenceSiteContentIndex
{
    private const VERSION = 2;
    private const MAX_OBJECT_BYTES = 67108864;
    private const MAX_RELATIONS = 2000000;
    private const CLASSES = ['Space', 'Page', 'Attachment', 'BlogPost', 'Comment', 'BodyContent',
        'ContentProperty', 'ContentPermissionSet', 'ContentPermission', 'SpacePermission',
        'Label', 'Labelling', 'CustomContentEntityObject'];

    /**
     * EN: Uses the existing safe ZIP access and object parser, without an additional database extension.
     *
     * HR: Koristi postojeći sigurni ZIP pristup i parser objekata, bez dodatnog proširenja baze.
     */
    public function __construct(private ConfluenceArchive $archive, private ConfluenceExportReader $reader, private ConfluenceImportConfig $config)
    {
    }

    /**
     * EN: Publishes only a complete, fingerprint-bound partition; interrupted preparation restarts.
     *
     * HR: Objavljuje samo potpunu podjelu vezanu uz otisak izvora; prekinuta priprema kreće ispočetka.
     *
     * @return array<string,mixed>
     * @param list<string> $spaceIds
     * @param array<string,mixed> $options
     */
    public function prepare(string $archivePath, string $hash, array $spaceIds = [], array $options = []): array
    {
        $this->ensureMemory();
        $directory = $this->directory($archivePath);
        $before = $this->sourceStat($archivePath);
        $manifestPath = $directory . '/manifest.json';
        sort($spaceIds);
        $policy = [];
        foreach (['include_history', 'include_drafts', 'include_deleted', 'include_comments'] as $flag) {
            $policy[$flag] = (bool)($options[$flag] ?? ($flag === 'include_comments'));
        }
        $scopeHash = hash('sha256', json_encode([$spaceIds, $policy], JSON_THROW_ON_ERROR));
        if (is_file($manifestPath) && !is_link($manifestPath)) {
            $cached = json_decode((string)file_get_contents($manifestPath), true);
            if (
                is_array($cached) && ($cached['version'] ?? null) === self::VERSION
                && ($cached['source_sha256'] ?? '') === $hash && ($cached['source_stat'] ?? []) === $before
                && ($cached['scope_sha256'] ?? '') === $scopeHash && $this->partitionsExist($directory, $spaceIds, $cached)
            ) {
                return $cached;
            }
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1 || !hash_equals($hash, (string)hash_file('sha256', $archivePath))) {
            throw new ConfluenceImportException(__('Izvornu arhivu nije moguće provjeriti.'));
        }
        if (function_exists('set_time_limit')) {
            set_time_limit($this->config->importExecutionTimeLimit());
        }
        $this->archive->inspect($archivePath);
        $this->reset($directory);
        $relations = $spaces = $bodyCandidates = [];
        $metadata = ['Space', 'Page', 'Attachment', 'BlogPost', 'Comment', 'CustomContentEntityObject', 'ContentPermissionSet'];
        $framer = new ConfluenceXmlInventoryFramer($this->config->maxInventoryObjectSize(), $metadata);
        foreach ($framer->frames($this->archive->entityChunks($archivePath)) as $frame) {
            if ($frame['xml'] === null) {
                continue;
            }
            $object = $this->reader->parseObject($frame['xml']);
            $id = $object->string('id');
            if ($id === '' || strlen($id) > 190) {
                throw new ConfluenceImportException(__('Confluence sadržaj ima nevaljan ID.'));
            }
            if ($object->className === 'Space') {
                $spaces[$id] = true;
                continue;
            }
            $relationId = ($object->className === 'ContentPermissionSet' ? 'set:' : 'content:') . $id;
            if (isset($relations[$relationId])) {
                throw new ConfluenceImportException(__('Confluence sadržaj ima ponovljen ID.'));
            }
            $relations[$relationId] = [$object->reference('space'), $this->parentId($object)];
            if (count($relations) > self::MAX_RELATIONS) {
                throw new ConfluenceImportException(__('Broj veza Confluence sadržaja prelazi dopušteni limit.'));
            }
            if ($object->className === 'Page') {
                $status = strtolower($object->string('contentStatus') ?: 'current');
                if (
                    ($status !== 'draft' || $policy['include_drafts']) && ($status !== 'deleted' || $policy['include_deleted'])
                    && ($object->reference('originalVersion') === '' || $policy['include_history'] || $status === 'draft')
                ) {
                    $bodyCandidates[$id] = true;
                }
            } elseif ($object->className === 'Comment' && $policy['include_comments']) {
                $bodyCandidates[$id] = true;
            }
        }
        $selected = $spaceIds !== [] ? array_fill_keys($spaceIds, true) : $spaces;
        if (array_diff_key($selected, $spaces) !== []) {
            throw new ConfluenceImportException(__('Odabir područja nije valjan.'));
        }
        foreach (['common', ...array_map(static fn(string|int $id): string => hash('sha256', (string)$id), array_keys($selected))] as $name) {
            $file = $directory . '/' . $name . '.jsonl';
            if (is_link($file) || file_put_contents($file, '') === false || !chmod($file, 0660)) {
                throw new ConfluenceImportException(__('Confluence indeks nije moguće spremiti.'));
            }
        }
        $counts = [];
        $framer = new ConfluenceXmlInventoryFramer(self::MAX_OBJECT_BYTES, self::CLASSES);
        foreach ($framer->frames($this->archive->entityChunks($archivePath)) as $frame) {
            if ($frame['xml'] === null) {
                continue;
            }
            if ($frame['class'] === 'BodyContent') {
                $contentId = $this->bodyOwner($frame['xml']);
                if (!isset($bodyCandidates[$contentId]) || !isset($selected[$this->owner('content:' . $contentId, $relations)])) {
                    continue;
                }
            } elseif ($frame['class'] !== 'Label' && !isset($selected[$this->frameOwner($frame['xml'], $frame['class'], $relations)])) {
                continue;
            }
            $object = $this->reader->parseObject($frame['xml']);
            $space = $object->className === 'Space' ? $object->string('id') : $object->reference('space');
            if ($space === '') {
                $space = $this->owner($this->parentId($object), $relations);
            }
            if ($object->className !== 'Label' && !isset($selected[$space])) {
                continue;
            }
            $name = $object->className === 'Label' ? 'common' : hash('sha256', $space);
            $file = $directory . '/' . $name . '.jsonl';
            $line = json_encode([$object->className, $object->packageName, $object->values], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
            if (is_link($file) || file_put_contents($file, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
                throw new ConfluenceImportException(__('Confluence indeks nije moguće spremiti.'));
            }
            $counts[$space][$object->className] = ($counts[$space][$object->className] ?? 0) + 1;
        }
        if ($before !== $this->sourceStat($archivePath)) {
            throw new ConfluenceImportException(__('Izvornu arhivu nije moguće provjeriti.'));
        }
        unset($counts['']);
        $result = ['version' => self::VERSION, 'source_sha256' => $hash, 'source_stat' => $before, 'scope_sha256' => $scopeHash, 'counts' => $counts];
        $temporary = $directory . '/manifest.tmp';
        if (
            is_link($temporary) || file_put_contents($temporary, json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX) === false
            || !chmod($temporary, 0660) || !rename($temporary, $manifestPath)
        ) {
            throw new ConfluenceImportException(__('Confluence indeks nije moguće spremiti.'));
        }
        return $result;
    }

    /**
     * EN: Rejects unrelated records before building their DOM (site exports may contain millions of properties).
     * Only direct metadata properties are considered; text or CDATA cannot impersonate a reference.
     *
     * HR: Odbacuje nepovezane zapise prije DOM obrade (site izvoz može sadržavati milijune svojstava).
     * Razmatra samo izravna svojstva metapodataka; tekst ili CDATA ne mogu oponašati referencu.
     *
     * @param array<string,array{string,string}> $relations
     */
    private function frameOwner(#[\SensitiveParameter] string $xml, string $class, array $relations): string
    {
        $reader = new \XMLReader();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$reader->XML($this->metadataXml($xml), null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
                throw new ConfluenceImportException(__('Confluence XML objekt nije valjan.'));
            }
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->depth !== 1) {
                    continue;
                }
                if ($reader->name === 'id') {
                    $id = trim($reader->readString());
                    if ($class === 'Space') {
                        return $id;
                    }
                    $relation = ($class === 'ContentPermissionSet' ? 'set:' : 'content:') . $id;
                    if (in_array($class, ['Page', 'Attachment', 'BlogPost', 'Comment', 'CustomContentEntityObject', 'ContentPermissionSet'], true)) {
                        return $this->owner($relation, $relations);
                    }
                } elseif ($reader->name === 'property') {
                    $name = $reader->getAttribute('name');
                    if ($name === 'space') {
                        return trim($reader->readString());
                    }
                    if (in_array($name, ['content', 'containerContent', 'owningContent', 'owningSet', 'labelableId'], true)) {
                        return $this->owner(($name === 'owningSet' ? 'set:' : 'content:') . trim($reader->readString()), $relations);
                    }
                }
            }
            return '';
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * EN: Skips large opaque CDATA payloads during ownership lookup, never during selected-content conversion.
     * Lexical comment/CDATA boundaries are respected; XMLReader still parses the actual reference structure.
     * Short metadata IDs (including whitespace-padded IDs) survive; no document text is reinterpreted as XML.
     *
     * HR: Preskače velike neprozirne CDATA sadržaje pri traženju vlasništva, nikada pri pretvorbi odabranog sadržaja.
     * Poštuje granice komentara/CDATA-e; XMLReader i dalje parsira stvarnu strukturu referenci.
     * Kratki ID-evi (i s okolnim razmacima) ostaju; tekst dokumenta ne tumači se kao XML.
     */
    private function metadataXml(#[\SensitiveParameter] string $xml): string
    {
        $position = $from = 0;
        $result = '';
        while (($at = strpos($xml, '<!', $position)) !== false) {
            if (substr_compare($xml, '<!--', $at, 4) === 0) {
                $end = strpos($xml, '-->', $at + 4);
                if ($end === false) {
                    throw new ConfluenceImportException(__('Confluence XML objekt nije valjan.'));
                }
                $position = $end + 3;
            } elseif (substr_compare($xml, '<![CDATA[', $at, 9) === 0) {
                $end = strpos($xml, ']]>', $at + 9);
                if ($end === false) {
                    throw new ConfluenceImportException(__('Confluence XML objekt nije valjan.'));
                }
                if ($end - $at > 2057) {
                    $value = trim(substr($xml, $at + 9, $end - $at - 9));
                    $result .= substr($xml, $from, $at - $from) . '<![CDATA[' . (strlen($value) <= 190 ? $value : '') . ']]>';
                    $from = $end + 3;
                }
                $position = $end + 3;
            } else {
                throw new ConfluenceImportException(__('Confluence XML objekt nije valjan.'));
            }
        }
        return $from === 0 ? $xml : $result . substr($xml, $from);
    }

    /**
     * EN: Raises only this administrative request's memory limit; locked FPM limits fail before writes.
     *
     * HR: Podiže memorijski limit samo ovog administratorskog zahtjeva; zaključani FPM limit odbija prije upisa.
     */
    private function ensureMemory(): void
    {
        $required = $this->config->importMemoryLimitMb();
        $limit = ini_get('memory_limit');
        if ($limit !== '-1' && ini_parse_quantity($limit) < $required * 1048576) {
            @ini_set('memory_limit', $required . 'M');
            $limit = ini_get('memory_limit');
            if ($limit !== '-1' && ini_parse_quantity($limit) < $required * 1048576) {
                throw new ConfluenceImportException(sprintf(__('Za Confluence import potreban je PHP memory_limit od najmanje %d MB.'), $required));
            }
        }
    }

    /**
     * EN: Never reuse an incomplete cache after a missing or symlinked partition.
     *
     * HR: Nikada ne koristi nepotpuni cache s nestalom ili symlink podjelom.
     *
     * @param list<string> $ids
     * @param array<string,mixed> $manifest
     */
    private function partitionsExist(string $directory, array $ids, array $manifest): bool
    {
        $names = ['common', ...array_map(static fn(string|int $id): string => hash('sha256', (string)$id), $ids ?: array_keys($manifest['counts'] ?? []))];
        foreach ($names as $name) {
            $path = $directory . '/' . $name . '.jsonl';
            if (is_link($path) || !is_file($path)) {
                return false;
            }
        }
        return true;
    }

    /**
     * EN: Reads a body owner without materializing its unselected text in a DOM tree.
     * XML parsing, not a text regex, keeps apparent references inside CDATA harmless.
     *
     * HR: Čita vlasnika tijela bez DOM učitavanja njegova neodabranog teksta.
     * XML parsiranje, ne tekstualni regex, čini prividne reference u CDATA-i bezopasnima.
     */
    private function bodyOwner(#[\SensitiveParameter] string $xml): string
    {
        $reader = new \XMLReader();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$reader->XML($this->metadataXml($xml), null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
                throw new ConfluenceImportException(__('Confluence XML objekt nije valjan.'));
            }
            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 'property' && $reader->getAttribute('name') === 'content') {
                    return trim($reader->readString());
                }
            }
            return '';
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * EN: Follows bounded ownership chains even when metadata precedes or follows its content.
     *
     * HR: Slijedi ograničene veze vlasništva bez obzira prethode li metapodaci sadržaju ili slijede iza njega.
     *
     * @param array<string,array{string,string}> $relations
     */
    private function owner(string $id, array $relations): string
    {
        $seen = [];
        while ($id !== '' && isset($relations[$id]) && !isset($seen[$id])) {
            $seen[$id] = true;
            [$space, $id] = $relations[$id];
            if ($space !== '') {
                return $space;
            }
        }
        return '';
    }

    /**
     * EN: Deletes only known temporary partition files of this exact private job directory.
     * No source ZIP, imported document or arbitrary directory is removed.
     *
     * HR: Briše samo poznate privremene datoteke podjele točno ovog privatnog direktorija posla.
     * Ne uklanja izvorni ZIP, uvezeni dokument ni proizvoljni direktorij.
     */
    private function reset(string $directory): void
    {
        foreach (new \DirectoryIterator($directory) as $file) {
            if ($file->isDot()) {
                continue;
            }
            if ($file->isLink() || !$file->isFile() || preg_match('/^(?:[a-f0-9]{64}\.jsonl|common\.jsonl|objects\.jsonl|manifest\.(?:json|tmp))$/D', $file->getFilename()) !== 1) {
                throw new ConfluenceImportException(__('Putanja Confluence indeksa nije valjana.'));
            }
            if (!unlink($file->getPathname())) {
                throw new ConfluenceImportException(__('Confluence indeks nije moguće spremiti.'));
            }
        }
    }

    /**
     * EN: Reads only a completed partition of the requested space; binary paths are never rewritten.
     *
     * HR: Čita samo dovršenu podjelu traženog područja; binarne putanje nikada se ne prepravljaju.
     *
     * @return \Generator<int,ConfluenceObject>
     */
    public function objects(string $archivePath, string $spaceId): \Generator
    {
        $directory = $this->directory($archivePath);
        $manifestFile = $directory . '/manifest.json';
        $manifest = is_file($manifestFile) && !is_link($manifestFile) ? json_decode((string)file_get_contents($manifestFile), true) : null;
        if (!is_array($manifest) || ($manifest['version'] ?? null) !== self::VERSION || ($manifest['source_stat'] ?? []) !== $this->sourceStat($archivePath)) {
            throw new ConfluenceImportException(__('Zajednički indeks sadržaja nije spreman.'));
        }
        foreach ([$directory . '/common.jsonl', $directory . '/' . hash('sha256', $spaceId) . '.jsonl'] as $file) {
            foreach ($this->read($file) as $object) {
                yield $object;
            }
        }
    }

    /**
     * EN: Resolves ownership rather than relying on XML record order.
     *
     * HR: Razrješava vlasništvo bez oslanjanja na redoslijed XML zapisa.
     */
    private function parentId(ConfluenceObject $object): string
    {
        foreach (['content', 'containerContent', 'owningContent', 'owningSet', 'originalVersion'] as $field) {
            if ($object->reference($field) !== '') {
                return ($field === 'owningSet' ? 'set:' : 'content:') . $object->reference($field);
            }
        }
        return $object->string('labelableId') !== '' ? 'content:' . $object->string('labelableId') : '';
    }

    /**
     * EN: Bounds each line and rejects linked, malformed or missing index files.
     *
     * HR: Ograničava svaki redak i odbija povezane, neispravne ili nestale indeksne datoteke.
     *
     * @return \Generator<int,ConfluenceObject>
     */
    private function read(string $path): \Generator
    {
        $stream = !is_link($path) && is_file($path) ? fopen($path, 'rb') : false;
        if (!is_resource($stream)) {
            throw new ConfluenceImportException(__('Zajednički indeks sadržaja nije spreman.'));
        }
        try {
            while (($line = fgets($stream, self::MAX_OBJECT_BYTES * 2)) !== false) {
                if (!str_ends_with($line, "\n")) {
                    throw new ConfluenceImportException(__('Confluence indeks nije valjan.'));
                }
                $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($record) || count($record) !== 3 || !is_string($record[0]) || !is_string($record[1]) || !is_array($record[2])) {
                    throw new ConfluenceImportException(__('Confluence indeks nije valjan.'));
                }
                yield new ConfluenceObject($record[0], $record[1], $record[2]);
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * EN: Places every partition beside its managed private upload and rejects symlink ancestors.
     *
     * HR: Svaku podjelu smješta uz upravljani privatni upload i odbija symlink pretke.
     */
    private function directory(string $archivePath): string
    {
        $root = realpath($this->config->uploadDirectory());
        $source = realpath($archivePath);
        if (!is_string($root) || !is_string($source) || is_link($archivePath) || dirname($source) !== $root) {
            throw new ConfluenceImportException(__('Datoteka importa nije unutar privatnog upload direktorija.'));
        }
        $directory = $archivePath . '.content';
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0770)) || realpath(dirname($directory)) !== $root) {
            throw new ConfluenceImportException(__('Putanja Confluence indeksa nije valjana.'));
        }
        return $directory;
    }

    /**
     * EN: Detects replacement or deletion before publishing a reusable index.
     *
     * HR: Otkriva zamjenu ili brisanje prije objave ponovno upotrebljivog indeksa.
     *
     * @return array<string,int>
     */
    private function sourceStat(string $path): array
    {
        clearstatcache(true, $path);
        $stat = is_file($path) ? stat($path) : false;
        if (!is_array($stat)) {
            throw new ConfluenceImportException(__('Izvornu arhivu nije moguće provjeriti.'));
        }
        return array_intersect_key($stat, array_fill_keys(['dev', 'ino', 'size', 'mtime', 'ctime'], true));
    }
}
