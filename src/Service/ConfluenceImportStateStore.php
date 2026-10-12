<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;

use function array_diff_key;
use function array_fill_keys;
use function array_intersect_key;
use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function random_bytes;
use function rename;
use function unlink;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const LOCK_EX;

/**
 * EN: Separates the large immutable import manifest from small resumable state.
 *
 * HR: Odvaja veliki nepromjenjivi manifest importa od malog nastavivog stanja.
 */
final class ConfluenceImportStateStore
{
    private const STATE_VERSION = 2;

    private const STATE_FILE = 'state.json';

    private const MANIFEST_FILE = 'manifest.json';

    /** @var list<string> */
    private const IMMUTABLE_KEYS = [
        'archive_path',
        'space',
        'workspace',
        'workspace_manager_user_id',
        'options',
        'dataset',
        'pages',
        'targets',
        'render_context',
    ];

    /**
     * EN: The initial write stores the large manifest once and progress separately.
     *
     * HR: Prvi zapis sprema veliki manifest samo jednom, a napredak zasebno.
     *
     * @param array<string,mixed> $state
     */
    public function initialize(string $staging, array $state): void
    {
        $keys = array_fill_keys(self::IMMUTABLE_KEYS, true);
        $manifest = array_intersect_key($state, $keys);
        $dynamic = array_diff_key($state, $keys);
        $dynamic['version'] = self::STATE_VERSION;

        $this->writeJson($this->manifestPath($staging), $manifest);
        $this->writeJson($this->statePath($staging), $dynamic);
    }

    /**
     * EN: Checks whether resumable state exists.
     *
     * HR: Provjerava postoji li nastavivo stanje.
     */
    public function exists(string $staging): bool
    {
        return is_file($this->statePath($staging));
    }

    /**
     * EN: Loads only current split state; external Confluence archive formats are unaffected.
     *
     * HR: Učitava samo aktualno podijeljeno stanje; vanjski formati Confluence arhiva ostaju podržani.
     *
     * @return array<string,mixed>
     */
    public function load(string $staging): array
    {
        $state = $this->readJson($this->statePath($staging));
        if (($state['version'] ?? null) !== self::STATE_VERSION) {
            throw new ConfluenceImportException('Unsupported import-job state version. Start a new import.');
        }

        $manifest = $this->readJson($this->manifestPath($staging));

        return [...$manifest, ...$state];
    }

    /**
     * EN: For the new format, rewrites only mutable progress.
     *
     * HR: Kod novog formata ponovno zapisuje samo promjenjivi napredak.
     *
     * @param array<string,mixed> $state
     */
    public function save(string $staging, array $state): void
    {
        if (($state['version'] ?? null) !== self::STATE_VERSION) {
            throw new ConfluenceImportException('Unsupported import-job state version. Start a new import.');
        }
        $state = array_diff_key($state, array_fill_keys(self::IMMUTABLE_KEYS, true));

        $this->writeJson($this->statePath($staging), $state);
    }

    /**
     * EN: Returns the mutable-state path.
     *
     * HR: Vraća putanju malog promjenjivog stanja.
     */
    public function statePath(string $staging): string
    {
        return $staging . DIRECTORY_SEPARATOR . self::STATE_FILE;
    }

    /**
     * EN: Returns the immutable-manifest path.
     *
     * HR: Vraća putanju velikog nepromjenjivog manifesta.
     */
    public function manifestPath(string $staging): string
    {
        return $staging . DIRECTORY_SEPARATOR . self::MANIFEST_FILE;
    }

    /**
     * EN: Reads and validates an import state or manifest JSON file.
     *
     * HR: Čita i provjerava JSON stanje ili manifest importa.
     *
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        $json = file_get_contents($path);
        $decoded = is_string($json) && $json !== ''
            ? json_decode($json, true, 512, JSON_THROW_ON_ERROR)
            : null;
        if (!is_array($decoded)) {
            throw new ConfluenceImportException(__('Stanje Confluence importa nije moguće učitati.'));
        }

        return $decoded;
    }

    /**
     * EN: Atomically writes an import state or manifest JSON file.
     *
     * HR: Atomski zapisuje JSON stanje ili manifest importa.
     *
     * @param array<string,mixed> $value
     */
    private function writeJson(string $path, array $value): void
    {
        $json = json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            throw new ConfluenceImportException(__('Stanje Confluence importa nije moguće spremiti.'));
        }
    }
}
