<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use ZipArchive;

use function array_filter;
use function basename;
use function fclose;
use function fopen;
use function is_array;
use function is_file;
use function is_resource;
use function max;
use function preg_match;
use function rtrim;
use function stream_copy_to_stream;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function strtolower;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;

/**
 * EN: Opens a Confluence ZIP without extracting it wholesale and rejects traversal, links, and ZIP bombs.
 *
 * HR: Otvara Confluence ZIP bez raspakiravanja cijelog arhiva i odbija traversal, linkove i ZIP bombe.
 */
final readonly class ConfluenceArchive
{
    public const ENTITIES_ENTRY = 'entities.xml';

    public const DESCRIPTOR_ENTRY = 'exportDescriptor.properties';

    /**
     * EN: Receives the central security limits.
     *
     * HR: Prima centralne sigurnosne limite.
     */
    public function __construct(private ConfluenceImportConfig $config)
    {
    }

    /**
     * EN: Returns validated metadata without extracting attachments.
     *
     * HR: Vraća provjerene metapodatke bez izdvajanja privitaka.
     *
     * @return array{path:string,size:int,entries:int,uncompressed_size:int,descriptor:array<string,string>,archive_type:string,attachment_files:int,plugin_data:bool}
     */
    public function inspect(string $archivePath): array
    {
        if (!is_file($archivePath)) {
            throw new ConfluenceImportException(__('Confluence ZIP arhiva nije pronađena.'));
        }

        $size = filesize($archivePath);
        if (!is_int($size) || $size < 1 || $size > $this->config->maxArchiveSize()) {
            throw new ConfluenceImportException(__('Confluence ZIP arhiva prelazi dopuštenu veličinu.'));
        }

        $zip = $this->open($archivePath);
        $uncompressed = 0;
        $required = [];
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > $this->config->maxEntries()) {
                throw new ConfluenceImportException(__('Broj datoteka u ZIP arhivi nije dopušten.'));
            }

            // EN: Read only the bounded descriptor before choosing the format-specific limits.
            //
            // HR: Prije odabira limita formata čitamo samo ograničeni descriptor.
            $descriptorStat = $zip->statName(self::DESCRIPTOR_ENTRY);
            if (!is_array($descriptorStat) || (int)$descriptorStat['size'] > 65536) {
                throw new ConfluenceImportException(__('Confluence descriptor nedostaje ili je prevelik.'));
            }
            $descriptor = $this->parseDescriptor($this->entryContents($zip, self::DESCRIPTOR_ENTRY));
            $exportType = strtolower($descriptor['exportType'] ?? '');
            if (!in_array($exportType, ['space', 'site'], true)) {
                throw new ConfluenceImportException(__('Nepodržana vrsta Confluence izvoza.'));
            }
            $spaceKeys = array_values(array_unique(array_filter(array_map(trim(...), explode(',', $descriptor['spaceKeys'] ?? '')))));
            $archiveType = $exportType === 'site' ? 'site' : (count($spaceKeys) > 1 ? 'multi_space' : 'space');
            $large = $archiveType !== 'space';
            $seen = [];
            $attachmentFiles = 0;
            $pluginData = false;

            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
                if (!is_array($stat)) {
                    throw new ConfluenceImportException(__('ZIP zapis nije moguće pročitati.'));
                }

                $name = (string)($stat['name'] ?? '');
                $this->assertSafeEntry($zip, $index, $name);
                if (isset($seen[$name])) {
                    throw new ConfluenceImportException(__('ZIP arhiva sadrži ponovljenu putanju.'));
                }
                $seen[$name] = true;
                $attachmentFiles += str_starts_with($name, 'attachments/') && !str_ends_with($name, '/') ? 1 : 0;
                $pluginData = $pluginData || str_starts_with($name, 'plugin-data/');
                $entrySize = (int)($stat['size'] ?? 0);
                $compressedSize = (int)($stat['comp_size'] ?? 0);
                if ($entrySize < 0 || $entrySize > ($large ? $this->config->maxSiteEntrySize() : $this->config->maxEntrySize())) {
                    throw new ConfluenceImportException(__('Pojedina datoteka u ZIP arhivi je prevelika.'));
                }

                $uncompressed += $entrySize;
                if ($uncompressed > ($large ? $this->config->maxSiteUncompressedSize() : $this->config->maxUncompressedSize())) {
                    throw new ConfluenceImportException(__('Raspakirani Confluence sadržaj prelazi dopuštenu veličinu.'));
                }

                if (
                    $entrySize > 1048576
                    && $compressedSize > 0
                    && $entrySize / $compressedSize > $this->config->maxCompressionRatio()
                ) {
                    throw new ConfluenceImportException(__('ZIP arhiva ima nedopušten omjer kompresije.'));
                }

                if ($name === self::ENTITIES_ENTRY || $name === self::DESCRIPTOR_ENTRY) {
                    $required[$name] = true;
                }
            }

            if (!isset($required[self::ENTITIES_ENTRY], $required[self::DESCRIPTOR_ENTRY])) {
                throw new ConfluenceImportException(__('ZIP nije potpuni Confluence XML space export.'));
            }

            return [
                'path' => $archivePath,
                'size' => $size,
                'entries' => $zip->numFiles,
                'uncompressed_size' => $uncompressed,
                'descriptor' => $descriptor,
                'archive_type' => $archiveType,
                'attachment_files' => $attachmentFiles,
                'plugin_data' => $pluginData,
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * EN: Streams only validated entities.xml in bounded chunks, without a temporary XML copy.
     * The ZIP and stream close even when the consumer stops early.
     *
     * HR: Čita samo provjereni entities.xml u ograničenim dijelovima, bez privremene XML kopije.
     * ZIP i stream zatvaraju se i kada čitač ranije prekine obradu.
     *
     * @return \Generator<int,string>
     */
    public function entityChunks(string $archivePath): \Generator
    {
        $this->inspect($archivePath);
        $zip = $this->open($archivePath);
        $stream = $zip->getStream(self::ENTITIES_ENTRY);
        try {
            if (!is_resource($stream)) {
                throw new ConfluenceImportException(__('Confluence XML stream nije dostupan.'));
            }
            $stat = $zip->statName(self::ENTITIES_ENTRY);
            $expected = is_array($stat) ? (int)$stat['size'] : -1;
            $bytes = 0;
            while (!feof($stream)) {
                $chunk = fread($stream, 1048576);
                if (!is_string($chunk) || ($chunk === '' && !feof($stream))) {
                    throw new ConfluenceImportException(__('Confluence XML stream je prekinut.'));
                }
                $bytes += strlen($chunk);
                if ($bytes > $expected) {
                    throw new ConfluenceImportException(__('Confluence XML veličina nije valjana.'));
                }
                if ($chunk !== '') {
                    yield $chunk;
                }
            }
            if ($bytes !== $expected) {
                throw new ConfluenceImportException(__('Confluence XML stream nije potpun.'));
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            $zip->close();
        }
    }

    /**
     * EN: Temporarily extracts only entities.xml for streaming parsing.
     *
     * HR: Privremeno izdvaja samo entities.xml radi stream parsiranja.
     */
    public function entitiesFile(string $archivePath): string
    {
        $this->inspect($archivePath);
        $zip = $this->open($archivePath);
        $source = $zip->getStream(self::ENTITIES_ENTRY);
        if (!is_resource($source)) {
            $zip->close();
            throw new ConfluenceImportException(__('Confluence entities.xml nije moguće otvoriti.'));
        }

        $targetPath = tempnam(sys_get_temp_dir(), 'simbioza-confluence-');
        if (!is_string($targetPath)) {
            fclose($source);
            $zip->close();
            throw new ConfluenceImportException(__('Privremenu XML datoteku nije moguće kreirati.'));
        }

        $target = fopen($targetPath, 'wb');
        try {
            if (!is_resource($target) || stream_copy_to_stream($source, $target) === false) {
                throw new ConfluenceImportException(__('Confluence XML nije moguće pripremiti za čitanje.'));
            }
        } catch (\Throwable $throwable) {
            @unlink($targetPath);
            throw $throwable;
        } finally {
            if (is_resource($target)) {
                fclose($target);
            }
            fclose($source);
            $zip->close();
        }

        return $targetPath;
    }

    /**
     * EN: Copies one validated attachment entry to a private target file.
     *
     * HR: Kopira jedan provjereni attachment zapis u privatnu ciljnu datoteku.
     */
    public function copyEntry(string $archivePath, string $entryName, string $targetPath): void
    {
        $zip = $this->open($archivePath);
        $index = $zip->locateName($entryName, ZipArchive::FL_UNCHANGED);
        if (!is_int($index) || $index < 0) {
            $zip->close();
            throw new ConfluenceImportException(__('Privitak nije pronađen u Confluence arhivi.'));
        }

        $this->assertSafeEntry($zip, $index, $entryName);
        $source = $zip->getStream($entryName);
        $target = fopen($targetPath, 'xb');
        try {
            if (!is_resource($source) || !is_resource($target) || stream_copy_to_stream($source, $target) === false) {
                throw new ConfluenceImportException(__('Confluence privitak nije moguće spremiti.'));
            }
        } catch (\Throwable $throwable) {
            @unlink($targetPath);
            throw $throwable;
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }
            $zip->close();
        }
    }

    /**
     * EN: Copies the requested or newest available binary attachment version.
     * Confluence may retain an XML version that differs from the physical entry.
     * Historical records may use the logical (original) attachment directory.
     *
     * HR: Kopira traženu ili najnoviju dostupnu binarnu verziju privitka.
     * Confluence ponekad u XML-u zadrži broj koji ne odgovara fizičkom zapisu.
     * Povijesni zapisi mogu koristiti direktorij logičkog (izvornog) privitka.
     */
    public function copyAttachment(
        string $archivePath,
        string $pageId,
        string $attachmentId,
        int $preferredVersion,
        string $targetPath,
        string $logicalAttachmentId = '',
    ): void {
        if (
            preg_match('/^[A-Za-z0-9._-]+$/', $pageId) !== 1
            || preg_match('/^[A-Za-z0-9._-]+$/', $attachmentId) !== 1
            || ($logicalAttachmentId !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $logicalAttachmentId) !== 1)
        ) {
            throw new ConfluenceImportException(__('Identifikator Confluence privitka nije valjan.'));
        }

        $prefixes = ['attachments/' . $pageId . '/' . $attachmentId . '/'];
        if ($logicalAttachmentId !== '' && $logicalAttachmentId !== $attachmentId) {
            $prefixes = ['attachments/' . $pageId . '/' . $logicalAttachmentId . '/', ...$prefixes];
        }
        $zip = $this->open($archivePath);
        $selected = '';
        $selectedVersion = 0;
        try {
            foreach ($prefixes as $prefix) {
                $preferred = $prefix . max(1, $preferredVersion);
                $preferredIndex = $zip->locateName($preferred, ZipArchive::FL_UNCHANGED);
                if (is_int($preferredIndex) && $preferredIndex >= 0) {
                    $this->assertSafeEntry($zip, $preferredIndex, $preferred);
                    $selected = $preferred;
                    break;
                }
            }
            foreach ($prefixes as $prefix) {
                if ($selected !== '') {
                    break;
                }
                for ($index = 0; $index < $zip->numFiles; ++$index) {
                    $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
                    if (!is_array($stat)) {
                        continue;
                    }
                    $name = (string)($stat['name'] ?? '');
                    if (!str_starts_with($name, $prefix)) {
                        continue;
                    }
                    $version = (int)rtrim(substr($name, strlen($prefix)), '/');
                    if ($version > $selectedVersion) {
                        $this->assertSafeEntry($zip, $index, $name);
                        $selected = $name;
                        $selectedVersion = $version;
                    }
                }
            }
        } finally {
            $zip->close();
        }

        if ($selected === '') {
            throw new ConfluenceImportException(__('Privitak nije pronađen u Confluence arhivi.'));
        }

        $this->copyEntry($archivePath, $selected, $targetPath);
    }

    /**
     * EN: Opens a ZIP and translates status into a business error.
     *
     * HR: Otvara ZIP i prevodi status u poslovnu pogrešku.
     */
    private function open(string $archivePath): ZipArchive
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
            throw new ConfluenceImportException(__('Confluence ZIP arhivu nije moguće otvoriti.'));
        }

        return $zip;
    }

    /**
     * EN: Rejects absolute, traversal, NUL, and symbolic ZIP entries.
     *
     * HR: Odbija apsolutne, traversal, NUL i simboličke ZIP zapise.
     */
    private function assertSafeEntry(ZipArchive $zip, int $index, string $name): void
    {
        $normalized = str_replace('\\', '/', trim($name));
        $segments = array_filter(explode('/', $normalized), static fn(string $part): bool => $part !== '');
        if (
            $normalized === ''
            || str_contains($normalized, "\0")
            || str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:\//', $normalized) === 1
            || in_array('..', $segments, true)
            || basename($normalized) === '..'
        ) {
            throw new ConfluenceImportException(__('ZIP sadrži nesigurnu putanju.'));
        }

        $operationsSystem = 0;
        $attributes = 0;
        if ($zip->getExternalAttributesIndex($index, $operationsSystem, $attributes)) {
            $mode = ($attributes >> 16) & 0xF000;
            if ($mode === 0xA000) {
                throw new ConfluenceImportException(__('ZIP simboličke poveznice nisu dopuštene.'));
            }
        }
    }

    /**
     * EN: Parses Confluence exportDescriptor.properties.
     *
     * HR: Raščlanjuje Confluence exportDescriptor.properties.
     *
     * @return array<string,string>
     */
    private function parseDescriptor(string $contents): array
    {
        $result = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key !== '') {
                $result[$key] = trim($value);
            }
        }

        return $result;
    }

    /**
     * EN: Reads a small required textual entry.
     *
     * HR: Čita mali obavezni tekstualni zapis.
     */
    private function entryContents(ZipArchive $zip, string $name): string
    {
        $contents = $zip->getFromName($name);
        if (!is_string($contents) || $contents === '') {
            throw new ConfluenceImportException(__('Confluence opis izvoza nije moguće pročitati.'));
        }

        return $contents;
    }
}
