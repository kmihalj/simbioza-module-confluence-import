<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use HeartPhrame\Config\ConfigInterface;

use function is_array;
use function is_numeric;
use function is_scalar;
use function rtrim;
use function trim;

/** HR: Čita prenosive sigurnosne i storage postavke importa. EN: Reads portable import security and storage settings. */
final readonly class ConfluenceImportConfig
{
    /** @var array<string,mixed> */
    private array $defaults;

    /** HR: Prima aplikacijsku konfiguraciju i korijen modula. EN: Receives application config and the module root. */
    public function __construct(private ConfigInterface $config, string $moduleRoot)
    {
        $loaded = require rtrim($moduleRoot, DIRECTORY_SEPARATOR) . '/config/confluence_import.php';
        $this->defaults = is_array($loaded) ? $loaded : [];
    }

    /** HR: Vraća direktorij kojim upravlja modul. EN: Returns the module-managed data directory. */
    public function dataDirectory(): string
    {
        $path = $this->string('data_path', 'confluence-import');

        return rtrim($this->config->getAppRootDir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . trim($path, '/\\');
    }

    /** HR: Vraća upload staging direktorij. EN: Returns the upload staging directory. */
    public function uploadDirectory(): string
    {
        return $this->dataDirectory() . DIRECTORY_SEPARATOR . 'uploads';
    }

    /** HR: Vraća privatni direktorij uvezenih privitaka. EN: Returns the private imported-attachment directory. */
    public function attachmentDirectory(): string
    {
        return $this->dataDirectory() . DIRECTORY_SEPARATOR . 'attachments';
    }

    /** HR: Najveća dopuštena ZIP arhiva. EN: Maximum allowed ZIP archive. */
    public function maxArchiveSize(): int
    {
        return $this->integer('max_archive_size', 4294967296);
    }

    /** HR: Veličina jednog nastavivog prijenosnog dijela. EN: Size of one resumable upload chunk. */
    public function chunkSize(): int
    {
        return $this->integer('chunk_size', 8388608);
    }

    /** HR: Vrijeme zadržavanja nezavršenog uploada. EN: Unfinished-upload retention time. */
    public function uploadTtl(): int
    {
        return $this->integer('upload_ttl', 86400);
    }

    /** HR: Najveći broj ZIP zapisa. EN: Maximum ZIP entry count. */
    public function maxEntries(): int
    {
        return $this->integer('max_entries', 250000);
    }

    /** HR: Najveća ukupna raspakirana veličina. EN: Maximum total uncompressed size. */
    public function maxUncompressedSize(): int
    {
        return $this->integer('max_uncompressed_size', 17179869184);
    }

    /** HR: Najveća pojedinačna raspakirana datoteka. EN: Maximum single uncompressed entry. */
    public function maxEntrySize(): int
    {
        return $this->integer('max_entry_size', 4294967296);
    }

    /** HR: Najveći dopušteni omjer kompresije. EN: Maximum allowed compression ratio. */
    public function maxCompressionRatio(): int
    {
        return $this->integer('max_compression_ratio', 250);
    }

    /** HR: Jezik sadržaja kada Confluence export nema jezični metapodatak. EN: Content locale when the export has no locale metadata. */
    public function defaultLanguage(): string
    {
        $language = strtolower($this->string('default_language', 'hr'));

        return preg_match('/^[a-z]{2}(?:-[a-z0-9]{2,8})?$/', $language) === 1 ? $language : 'hr';
    }

    /** HR: Čita cijeli broj iz aplikacijske postavke ili modulske zadane vrijednosti. EN: Reads an integer from application config or module defaults. */
    private function integer(string $key, int $fallback): int
    {
        $value = $this->config->get('confluence_import.' . $key, $this->defaults[$key] ?? $fallback);

        return is_numeric($value) && (int)$value > 0 ? (int)$value : $fallback;
    }

    /** HR: Čita tekstualnu postavku. EN: Reads a string setting. */
    private function string(string $key, string $fallback): string
    {
        $value = $this->config->get('confluence_import.' . $key, $this->defaults[$key] ?? $fallback);

        return is_scalar($value) && trim((string)$value) !== '' ? trim((string)$value) : $fallback;
    }
}
