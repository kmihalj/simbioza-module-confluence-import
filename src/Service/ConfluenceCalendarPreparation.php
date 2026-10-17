<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;

/**
 * EN: Publishes bounded, private preparation telemetry, never source records or credentials.
 * The calendar lock, not a timestamp or this file, determines whether a worker is alive.
 *
 * HR: Objavljuje ograničene privatne podatke o pripremi, nikada izvorne zapise ili vjerodajnice.
 * Zaključavanje kalendara, ne vrijeme ili ova datoteka, određuje radi li postupak još uvijek.
 */
final class ConfluenceCalendarPreparation
{
    private float $lastWrite = 0;
    private string $phase = '';
    private readonly int $started;

    /**
     * EN: Binds telemetry to the importer-owned upload and its previously verified digest.
     *
     * HR: Veže podatke uz prijenos kojim upravlja importer i njegov prethodno provjereni sažetak.
     */
    public function __construct(private readonly string $path, private readonly string $hash)
    {
        $this->started = time();
    }

    /**
     * EN: Throttles counters but immediately publishes phase transitions with atomic replacement.
     * A percentage is meaningful only inside a phase with a known total.
     *
     * HR: Ograničava učestalost upisa brojača, a promjene faze objavljuje odmah atomskom zamjenom.
     * Postotak ima smisla samo unutar faze s poznatim ukupnim brojem.
     */
    public function update(string $phase, int $done = 0, ?int $total = null): void
    {
        $now = microtime(true);
        if ($phase === $this->phase && $now - $this->lastWrite < 0.5) {
            return;
        }
        $file = $this->path . '.calendars.progress.json';
        $temporary = $file . '.tmp';
        $json = json_encode(['source_sha256' => $this->hash, 'phase' => $phase,
            'done' => $done, 'total' => $total, 'started_at' => $this->started], JSON_THROW_ON_ERROR);
        if (
            is_link($file) || is_link($temporary) || file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)
            || !chmod($temporary, 0660) || !rename($temporary, $file)
        ) {
            throw new ConfluenceImportException(__('Indeks kalendara nije moguće spremiti.'));
        }
        $this->lastWrite = $now;
        $this->phase = $phase;
    }

    /**
     * EN: Reads a tiny allowlisted snapshot without hashing or rescanning the source archive.
     *
     * HR: Čita mali provjereni snimak bez hashiranja ili ponovnog čitanja izvorne arhive.
     *
     * @return array{phase:string,done:int,total:?int,started_at:int}|null
     */
    public static function read(string $path, string $hash): ?array
    {
        $file = $path . '.calendars.progress.json';
        clearstatcache(true, $file);
        if (is_link($file) || !is_file($file) || filesize($file) > 4096) {
            return null;
        }
        $data = json_decode((string)file_get_contents($file), true);
        if (
            !is_array($data) || ($data['source_sha256'] ?? '') !== $hash
            || !in_array($data['phase'] ?? '', ['verify', 'scan', 'partition', 'finalize', 'ready', 'failed'], true)
            || !is_int($data['done'] ?? null) || $data['done'] < 0
            || (($data['total'] ?? null) !== null && (!is_int($data['total']) || $data['total'] < $data['done']))
            || !is_int($data['started_at'] ?? null)
        ) {
            return null;
        }
        return ['phase' => $data['phase'], 'done' => $data['done'], 'total' => $data['total'] ?? null, 'started_at' => $data['started_at']];
    }
}
