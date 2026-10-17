<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use AaiEduHr\SimbiozaModuleConfluenceImport\Value\ConfluenceObject;

/**
 * EN: Creates a bounded, secret-free site catalog and caches it in private application data.
 * It never creates identities, calendars, Workspaces or documents.
 *
 * HR: Izrađuje ograničeni pregled sitea bez tajni i sprema ga u privatne podatke aplikacije.
 * Nikada ne izrađuje identitete, kalendare, područja ni dokumente.
 */
final readonly class ConfluenceSiteInventory
{
    private const VERSION = 2;

    /**
     * EN: Receives validated archive access, the metadata reader and configurable limits.
     *
     * HR: Prima pristup provjerenoj arhivi, čitač metapodataka i podesive limite.
     */
    public function __construct(
        private ConfluenceArchive $archive,
        private ConfluenceInventoryReader $reader,
        private ConfluenceImportConfig $config,
    ) {
    }

    /**
     * EN: A complete index is atomic and tied to the source bytes; interrupted scans restart safely.
     * Optional cache paths are internal managed-upload paths, never browser-supplied paths.
     *
     * HR: Potpuni indeks je atomski i vezan uz bajtove izvora; prekinuti pregled sigurno kreće ispočetka.
     * Opcionalne putanje indeksa interne su putanje uploada, nikada putanje iz preglednika.
     *
     * @return array<string,mixed>
     */
    public function scan(string $archivePath, ?string $indexPath = null): array
    {
        $inspection = $this->archive->inspect($archivePath);
        $sourceStat = stat($archivePath);
        $hash = hash_file('sha256', $archivePath);
        if (!is_string($hash)) {
            throw new ConfluenceImportException(__('Izvornu arhivu nije moguće provjeriti.'));
        }
        $path = $indexPath ?? $this->config->dataDirectory() . '/inventory/' . $hash . '.json';
        $this->prepareIndexPath($path);
        if (is_file($path) && filesize($path) <= 16777216) {
            $cached = json_decode((string)file_get_contents($path), true);
            if (
                is_array($cached) && ($cached['index_version'] ?? null) === self::VERSION
                && ($cached['source_sha256'] ?? null) === $hash && is_array($cached['spaces'] ?? null)
            ) {
                $cached['inventory_cached'] = true;
                return $cached;
            }
        }

        $counts = $spaces = $content = [];
        $objects = 0;
        foreach ($this->reader->objects($archivePath) as $object) {
            ++$objects;
            $class = $object->className;
            $counts[$class] = ($counts[$class] ?? 0) + 1;
            if (count($counts) > 1000) {
                throw new ConfluenceImportException(__('Confluence inventar sadrži previše vrsta zapisa.'));
            }
            if ($class === 'Space') {
                $id = $object->string('id');
                if ($id === '' || strlen($id) > 190 || isset($spaces[$id])) {
                    throw new ConfluenceImportException(__('Confluence područje ima nevaljan ili ponovljen ID.'));
                }
                $spaces[$id] = $this->space($object);
                if (count($spaces) > $this->config->maxInventorySpaces()) {
                    throw new ConfluenceImportException(__('Broj Confluence područja prelazi dopušteni limit.'));
                }
            } elseif (in_array($class, ['Page', 'Attachment', 'BlogPost', 'Comment'], true)) {
                $spaceId = $object->reference('space');
                if ($spaceId === '') {
                    continue;
                }
                if (strlen($spaceId) > 190) {
                    throw new ConfluenceImportException(__('Confluence metapodaci područja prelaze dopuštenu veličinu.'));
                }
                if (!isset($content[$spaceId]) && count($content) >= $this->config->maxInventorySpaces()) {
                    throw new ConfluenceImportException(__('Broj Confluence područja prelazi dopušteni limit.'));
                }
                $status = strtolower($object->string('contentStatus') ?: 'current');
                $bucket = in_array($status, ['draft', 'deleted'], true) ? $status
                    : ($object->reference('originalVersion') !== '' ? 'history' : 'current');
                $content[$spaceId][$class][$bucket] = ($content[$spaceId][$class][$bucket] ?? 0) + 1;
            }
        }
        $declared = trim($inspection['descriptor']['totalObjectsCount'] ?? '');
        foreach ($spaces as $id => &$space) {
            $space['content_counts'] = $content[$id] ?? [];
        }
        unset($space);
        $type = (string)$inspection['archive_type'];
        $declaredKeys = array_values(array_unique(array_filter(array_map(trim(...), explode(',', $inspection['descriptor']['spaceKeys'] ?? '')))));
        $actualKeys = array_column($spaces, 'source_key');
        sort($declaredKeys);
        sort($actualKeys);
        if ($spaces === [] || ($type === 'multi_space' && (count($spaces) < 2 || $declaredKeys !== $actualKeys))) {
            throw new ConfluenceImportException(__('Confluence descriptor i broj područja nisu usklađeni.'));
        }
        $statuses = [];
        foreach ($content as $perClass) {
            foreach ($perClass['Page'] ?? [] as $status => $count) {
                $statuses[$status] = ($statuses[$status] ?? 0) + $count;
            }
        }
        ksort($counts);
        uasort($spaces, static fn(array $a, array $b): int => strnatcasecmp($a['source_key'], $b['source_key']));
        $warnings = [];
        if ($declared !== '' && ctype_digit($declared) && (int)$declared !== $objects) {
            $warnings[] = __('Broj pročitanih XML zapisa razlikuje se od broja u descriptoru; stariji izvozi različito broje povijest.');
        }
        $included = strtolower($inspection['descriptor']['backupAttachments'] ?? '') === 'true';
        if (!$included && (int)$inspection['attachment_files'] === 0) {
            $warnings[] = __('Izvoz ne uključuje datoteke privitaka. XML reference nisu stvarne datoteke.');
        } elseif ((int)$inspection['attachment_files'] === 0 && ($counts['Attachment'] ?? 0) > 0) {
            $warnings[] = __('Izvoz navodi privitke, ali njihove datoteke nisu pronađene.');
        }
        $result = [
            'index_version' => self::VERSION,
            'source_sha256' => $hash,
            'archive_type' => $type,
            'inventory_cached' => false,
            'inventory_only' => true,
            'objects_scanned' => $objects,
            'source' => [
                'confluence_version' => $inspection['descriptor']['createdByVersionNumber'] ?? '',
                'export_type' => $inspection['descriptor']['exportType'] ?? '',
                'attachments' => $included,
                'attachment_files' => $inspection['attachment_files'],
                'plugin_data' => $inspection['plugin_data'],
                'uncompressed_size' => $inspection['uncompressed_size'],
            ],
            'spaces' => array_values($spaces),
            'counts' => $counts,
            'statuses' => $statuses,
            'users' => [], 'groups' => [], 'calendars' => [], 'warnings' => $warnings,
            'attachment_counts' => ['source_records' => $counts['Attachment'] ?? 0,
                'files' => $inspection['attachment_files']],
        ];
        $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 16777216) {
            throw new ConfluenceImportException(__('Confluence inventar je prevelik.'));
        }
        // EN: Cancellation or source replacement must not publish an orphaned or stale index.
        //
        // HR: Odustajanje ili zamjena izvora ne smiju objaviti napušteni ili zastarjeli indeks.
        clearstatcache(true, $archivePath);
        $currentStat = is_file($archivePath) ? stat($archivePath) : false;
        foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $key) {
            if (!is_array($sourceStat) || !is_array($currentStat) || $sourceStat[$key] !== $currentStat[$key]) {
                throw new ConfluenceImportException(__('Izvornu arhivu nije moguće provjeriti.'));
            }
        }
        $this->prepareIndexPath($path);
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        try {
            if (
                file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)
                || !chmod($temporary, 0660) || !rename($temporary, $path)
            ) {
                throw new ConfluenceImportException(__('Confluence indeks nije moguće spremiti.'));
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        return $result;
    }

    /**
     * EN: Saves no source body, directory credential, token or plugin configuration.
     *
     * HR: Ne sprema tijelo izvora, vjerodajnice imenika, token ni konfiguraciju dodataka.
     *
     * @return array{source_id:string,source_key:string,name:string,type:string,status:string,home_page_id:string,owner_source_key:string}
     */
    private function space(ConfluenceObject $object): array
    {
        $space = [
            'source_id' => $object->string('id'),
            'source_key' => $object->string('key'),
            'name' => $object->string('name'),
            'type' => strtolower($object->string('spaceType') ?: 'global'),
            'status' => $object->string('spaceStatus'),
            'home_page_id' => $object->reference('homePage'),
            'owner_source_key' => $object->reference('creator'),
        ];
        // EN: Cap retained fields as well as record count, before building the catalog in memory.
        //
        // HR: Prije izrade pregleda u memoriji ograniči i polja i broj zadržanih zapisa.
        foreach (
            ['source_id' => 190, 'source_key' => 1024, 'name' => 2048, 'type' => 64,
            'status' => 64, 'home_page_id' => 190, 'owner_source_key' => 255] as $key => $limit
        ) {
            if (strlen($space[$key]) > $limit) {
                throw new ConfluenceImportException(__('Confluence metapodaci područja prelaze dopuštenu veličinu.'));
            }
        }
        return $space;
    }

    /**
     * EN: Indexes stay under private module data; symlinks cannot redirect a write.
     *
     * HR: Indeksi ostaju u privatnim podacima modula; symlink ne može preusmjeriti upis.
     */
    private function prepareIndexPath(string $path): void
    {
        $root = $this->config->dataDirectory();
        if (!is_dir($root) && !mkdir($root, 0770, true) && !is_dir($root)) {
            throw new ConfluenceImportException(__('Privatni direktorij importa nije moguće stvoriti.'));
        }
        $realRoot = realpath($root);
        $directory = dirname($path);
        if (!is_string($realRoot) || !str_starts_with($path, $root . '/') || is_link($path)) {
            throw new ConfluenceImportException(__('Putanja Confluence indeksa nije valjana.'));
        }
        $relative = substr($directory, strlen($root) + 1);
        $current = $root;
        foreach ($relative !== '' ? explode('/', $relative) : [] as $part) {
            if (in_array($part, ['', '.', '..'], true)) {
                throw new ConfluenceImportException(__('Putanja Confluence indeksa nije valjana.'));
            }
            $current .= '/' . $part;
            if (is_link($current) || (!is_dir($current) && !mkdir($current, 0770) && !is_dir($current))) {
                throw new ConfluenceImportException(__('Putanja Confluence indeksa nije valjana.'));
            }
        }
        $realDirectory = realpath($directory);
        if (!is_string($realDirectory) || !str_starts_with($realDirectory . '/', $realRoot . '/')) {
            throw new ConfluenceImportException(__('Putanja Confluence indeksa nije valjana.'));
        }
    }
}
