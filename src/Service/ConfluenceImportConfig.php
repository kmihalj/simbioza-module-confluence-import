<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use HeartPhrame\Config\ConfigArrayMerger;
use HeartPhrame\Config\ConfigInterface;
use RuntimeException;

use function in_array;
use function is_array;
use function is_numeric;
use function is_scalar;
use function parse_url;
use function rtrim;
use function strtolower;
use function trim;

use const PHP_URL_HOST;
use const PHP_URL_SCHEME;

/**
     * EN: Reads portable import security and storage settings.
     *
     * HR: Čita prenosive sigurnosne i storage postavke importa.
     */
final readonly class ConfluenceImportConfig
{
    /** @var array<string,mixed> */
    private array $settings;

    /**
     * EN: Receives application config and the module root.
     *
     * HR: Prima aplikacijsku konfiguraciju i korijen modula.
     */
    public function __construct(private ConfigInterface $config, string $moduleRoot)
    {
        $loaded = (static fn(string $file): mixed => require $file)(
            rtrim($moduleRoot, DIRECTORY_SEPARATOR) . '/config/confluence_import.php',
        );
        $override = $config->get('confluence-import.confluence-import', []);
        if (!is_array($loaded) || !is_array($override)) {
            throw new RuntimeException('Confluence Import configuration must be an array.');
        }
        /** @var array<string,mixed> $loaded */
        /** @var array<string,mixed> $override */
        $this->settings = ConfigArrayMerger::merge($loaded, $override);
    }

    /**
     * EN: Returns the module-managed data directory.
     *
     * HR: Vraća direktorij kojim upravlja modul.
     */
    public function dataDirectory(): string
    {
        $path = $this->string('data_path', 'confluence-import');
        if (preg_match('~\A[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*\z~D', $path) !== 1) {
            throw new RuntimeException('Confluence Import data_path must stay inside the application data directory.');
        }

        return rtrim($this->config->getAppRootDir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . trim($path, '/\\');
    }

    /**
     * EN: Returns the upload staging directory.
     *
     * HR: Vraća upload staging direktorij.
     */
    public function uploadDirectory(): string
    {
        return $this->dataDirectory() . DIRECTORY_SEPARATOR . 'uploads';
    }

    /**
     * EN: Returns the administrator-managed sequential batch-import directory.
     *
     * HR: Vraća administratorski direktorij za sekvencijalni batch import.
     */
    public function batchDirectory(): string
    {
        return $this->dataDirectory() . DIRECTORY_SEPARATOR . 'batch-import';
    }

    /**
     * EN: Returns the private imported-attachment directory.
     *
     * HR: Vraća privatni direktorij uvezenih privitaka.
     */
    public function attachmentDirectory(): string
    {
        return $this->dataDirectory() . DIRECTORY_SEPARATOR . 'attachments';
    }

    /**
     * EN: Maximum allowed ZIP archive.
     *
     * HR: Najveća dopuštena ZIP arhiva.
     */
    public function maxArchiveSize(): int
    {
        return $this->integer('max_archive_size', 8589934592);
    }

    /**
     * EN: Size of one resumable upload chunk.
     *
     * HR: Veličina jednog nastavivog prijenosnog dijela.
     */
    public function chunkSize(): int
    {
        return $this->integer('chunk_size', 8388608);
    }

    /**
     * EN: Unfinished-upload retention time.
     *
     * HR: Vrijeme zadržavanja nezavršenog uploada.
     */
    public function uploadTtl(): int
    {
        return $this->integer('upload_ttl', 86400);
    }

    /**
     * EN: Maximum ZIP entry count.
     *
     * HR: Najveći broj ZIP zapisa.
     */
    public function maxEntries(): int
    {
        return $this->integer('max_entries', 250000);
    }

    /**
     * EN: Maximum total uncompressed size.
     *
     * HR: Najveća ukupna raspakirana veličina.
     */
    public function maxUncompressedSize(): int
    {
        return $this->integer('max_uncompressed_size', 17179869184);
    }

    /**
     * EN: Maximum single uncompressed entry.
     *
     * HR: Najveća pojedinačna raspakirana datoteka.
     */
    public function maxEntrySize(): int
    {
        return $this->integer('max_entry_size', 4294967296);
    }

    /**
     * EN: Site and multi-space exports have separate bounded XML/plugin limits.
     *
     * HR: Izvozi sitea i više područja imaju zasebne ograničene XML/plugin limite.
     */
    public function maxSiteEntrySize(): int
    {
        return $this->integer('max_site_entry_size', 68719476736);
    }

    /**
     * EN: Limits declared uncompressed site data without extracting it.
     *
     * HR: Ograničava deklarirane raspakirane podatke sitea bez njihova izdvajanja.
     */
    public function maxSiteUncompressedSize(): int
    {
        return $this->integer('max_site_uncompressed_size', 137438953472);
    }

    /**
     * EN: Bounds one selected metadata object and the space catalog in memory.
     *
     * HR: Ograničava jedan odabrani objekt metapodataka i popis područja u memoriji.
     */
    public function maxInventoryObjectSize(): int
    {
        return $this->integer('max_inventory_object_size', 16777216);
    }

    /**
     * EN: Bounds the inventory catalog independently of the number of documents.
     *
     * HR: Ograničava pregled područja neovisno o broju dokumenata.
     */
    public function maxInventorySpaces(): int
    {
        return $this->integer('max_inventory_spaces', 10000);
    }

    /**
     * EN: Bounds the retained identity/access metadata of one site export.
     *
     * HR: Ograničava zadržane metapodatke identiteta i pristupa jednog izvoza sitea.
     */
    public function maxSiteIdentityRecords(): int
    {
        return $this->integer('max_site_identity_records', 100000);
    }

    /**
     * EN: Caps a private identity index before parsing or publishing it.
     *
     * HR: Ograničava privatni indeks identiteta prije čitanja ili objave.
     */
    public function maxSiteIdentityIndexSize(): int
    {
        return $this->integer('max_site_identity_index_size', 33554432);
    }

    /**
     * EN: Maximum allowed compression ratio.
     *
     * HR: Najveći dopušteni omjer kompresije.
     */
    public function maxCompressionRatio(): int
    {
        return $this->integer('max_compression_ratio', 250);
    }

    /**
     * EN: Maximum preflight or confirmed-import execution time in seconds.
     *
     * HR: Najdulje trajanje provjere ili potvrđenog importa u sekundama.
     */
    public function importExecutionTimeLimit(): int
    {
        return $this->integer('import_execution_time_limit', 900);
    }

    /**
     * EN: Memory limit for one administrator import step in MB.
     *
     * HR: Memorijski limit jednog administratorskog koraka importa u MB.
     */
    public function importMemoryLimitMb(): int
    {
        return $this->integer('import_memory_limit_mb', 512);
    }

    /**
     * EN: Content locale when the export has no locale metadata.
     *
     * HR: Jezik sadržaja kada Confluence export nema jezični metapodatak.
     */
    public function defaultLanguage(): string
    {
        $language = strtolower($this->string('default_language', 'hr'));

        return preg_match('/^[a-z]{2}(?:-[a-z0-9]{2,8})?$/', $language) === 1 ? $language : 'hr';
    }

    /**
     * EN: Returns only the initial source URL value that an administrator may change per import.
     *
     * HR: Vraća samo početnu vrijednost URL-a koji administrator može promijeniti po importu.
     */
    public function sourceBaseUrl(): string
    {
        $url = rtrim($this->string('source_base_url', ''), '/');
        $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
        $host = trim((string)(parse_url($url, PHP_URL_HOST) ?? ''));

        return in_array($scheme, ['http', 'https'], true) && $host !== '' ? $url : '';
    }

    /**
     * EN: Reads an integer from application config or module defaults.
     *
     * HR: Čita cijeli broj iz aplikacijske postavke ili modulske zadane vrijednosti.
     */
    private function integer(string $key, int $fallback): int
    {
        $value = $this->settings[$key] ?? $fallback;

        return is_numeric($value) && (int)$value > 0 ? (int)$value : $fallback;
    }

    /**
     * EN: Reads a string setting.
     *
     * HR: Čita tekstualnu postavku.
     */
    private function string(string $key, string $fallback): string
    {
        $value = $this->settings[$key] ?? $fallback;

        return is_scalar($value) && trim((string)$value) !== '' ? trim((string)$value) : $fallback;
    }
}
