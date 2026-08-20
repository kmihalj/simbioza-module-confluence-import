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
 * HR: Otvara Confluence ZIP bez raspakiravanja cijelog arhiva i odbija traversal, linkove i ZIP bombe.
 * EN: Opens a Confluence ZIP without extracting it wholesale and rejects traversal, links, and ZIP bombs.
 */
final readonly class ConfluenceArchive
{
    public const ENTITIES_ENTRY = 'entities.xml';

    public const DESCRIPTOR_ENTRY = 'exportDescriptor.properties';

    /** HR: Prima centralne sigurnosne limite. EN: Receives the central security limits. */
    public function __construct(private ConfluenceImportConfig $config)
    {
    }

    /**
     * HR: Vraća provjerene metapodatke bez izdvajanja privitaka.
     * EN: Returns validated metadata without extracting attachments.
     *
     * @return array{path:string,size:int,entries:int,uncompressed_size:int,descriptor:array<string,string>}
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

            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
                if (!is_array($stat)) {
                    throw new ConfluenceImportException(__('ZIP zapis nije moguće pročitati.'));
                }

                $name = (string)($stat['name'] ?? '');
                $this->assertSafeEntry($zip, $index, $name);
                $entrySize = (int)($stat['size'] ?? 0);
                $compressedSize = (int)($stat['comp_size'] ?? 0);
                if ($entrySize < 0 || $entrySize > $this->config->maxEntrySize()) {
                    throw new ConfluenceImportException(__('Pojedina datoteka u ZIP arhivi je prevelika.'));
                }

                $uncompressed += $entrySize;
                if ($uncompressed > $this->config->maxUncompressedSize()) {
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

            $descriptor = $this->parseDescriptor($this->entryContents($zip, self::DESCRIPTOR_ENTRY));
            if (strtolower($descriptor['exportType'] ?? '') !== 'space') {
                throw new ConfluenceImportException(__('Podržan je samo Confluence izvoz područja.'));
            }

            return [
                'path' => $archivePath,
                'size' => $size,
                'entries' => $zip->numFiles,
                'uncompressed_size' => $uncompressed,
                'descriptor' => $descriptor,
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * HR: Privremeno izdvaja samo entities.xml radi stream parsiranja.
     * EN: Temporarily extracts only entities.xml for streaming parsing.
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
     * HR: Kopira jedan provjereni attachment zapis u privatnu ciljnu datoteku.
     * EN: Copies one validated attachment entry to a private target file.
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
     * HR: Kopira traženu ili najnoviju dostupnu binarnu verziju privitka.
     * Confluence ponekad u XML-u zadrži broj koji ne odgovara fizičkom zapisu.
     * EN: Copies the requested or newest available binary attachment version.
     * Confluence may retain an XML version that differs from the physical entry.
     */
    public function copyAttachment(
        string $archivePath,
        string $pageId,
        string $attachmentId,
        int $preferredVersion,
        string $targetPath,
    ): void {
        if (
            preg_match('/^[A-Za-z0-9._-]+$/', $pageId) !== 1
            || preg_match('/^[A-Za-z0-9._-]+$/', $attachmentId) !== 1
        ) {
            throw new ConfluenceImportException(__('Identifikator Confluence privitka nije valjan.'));
        }

        $prefix = 'attachments/' . $pageId . '/' . $attachmentId . '/';
        $preferred = $prefix . max(1, $preferredVersion);
        $zip = $this->open($archivePath);
        $selected = '';
        $selectedVersion = 0;
        try {
            $preferredIndex = $zip->locateName($preferred, ZipArchive::FL_UNCHANGED);
            if (is_int($preferredIndex) && $preferredIndex >= 0) {
                $this->assertSafeEntry($zip, $preferredIndex, $preferred);
                $selected = $preferred;
            } else {
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

    /** HR: Otvara ZIP i prevodi status u poslovnu pogrešku. EN: Opens a ZIP and translates status into a business error. */
    private function open(string $archivePath): ZipArchive
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
            throw new ConfluenceImportException(__('Confluence ZIP arhivu nije moguće otvoriti.'));
        }

        return $zip;
    }

    /** HR: Odbija apsolutne, traversal, NUL i simboličke ZIP zapise. EN: Rejects absolute, traversal, NUL, and symbolic ZIP entries. */
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
     * HR: Raščlanjuje Confluence exportDescriptor.properties.
     * EN: Parses Confluence exportDescriptor.properties.
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

    /** HR: Čita mali obavezni tekstualni zapis. EN: Reads a small required textual entry. */
    private function entryContents(ZipArchive $zip, string $name): string
    {
        $contents = $zip->getFromName($name);
        if (!is_string($contents) || $contents === '') {
            throw new ConfluenceImportException(__('Confluence opis izvoza nije moguće pročitati.'));
        }

        return $contents;
    }
}
