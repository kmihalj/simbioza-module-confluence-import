<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;

use function basename;
use function fclose;
use function feof;
use function fflush;
use function file_get_contents;
use function filesize;
use function flock;
use function fopen;
use function function_exists;
use function fread;
use function fseek;
use function fwrite;
use function hash_file;
use function is_dir;
use function is_file;
use function gmdate;
use function is_int;
use function is_resource;
use function is_string;
use function max;
use function mkdir;
use function rename;
use function set_time_limit;
use function strtolower;
use function str_ends_with;
use function time;
use function trim;
use function unlink;

/** HR: Prima velike Confluence arhive u provjerenim nastavivim dijelovima. EN: Receives large Confluence archives in validated resumable chunks. */
final readonly class ConfluenceImportUploadService
{
    /** HR: Prima repozitorij, limite i preflight scanner. EN: Receives the repository, limits, and preflight scanner. */
    public function __construct(
        private ConfluenceImportRepository $repository,
        private ConfluenceImportConfig $config,
        private ConfluenceExportScanner $scanner,
    ) {
    }

    /**
     * HR: Otvara novi nastavivi upload nakon provjere naziva i veličine.
     * EN: Starts a new resumable upload after validating its name and size.
     *
     * @return array<string,mixed>
     */
    public function start(string $originalName, int $size, int $actorUserId): array
    {
        $this->cleanupExpired();

        if ($size < 1 || $size > $this->config->maxArchiveSize()) {
            throw new ConfluenceImportException(__('Veličina Confluence arhive nije dopuštena.'));
        }

        $originalName = basename(trim($originalName));
        if ($originalName === '' || !str_ends_with(strtolower($originalName), '.zip')) {
            throw new ConfluenceImportException(__('Odaberite Confluence XML ZIP arhivu.'));
        }

        $this->ensureDirectory($this->config->uploadDirectory());
        $temporaryPath = $this->config->uploadDirectory() . DIRECTORY_SEPARATOR
            . bin2hex(random_bytes(16)) . '.upload';
        $handle = fopen($temporaryPath, 'xb');
        if (!is_resource($handle)) {
            throw new ConfluenceImportException(__('Privremenu datoteku uploada nije moguće kreirati.'));
        }
        fclose($handle);

        try {
            return $this->repository->createUploadJob(
                $originalName,
                $temporaryPath,
                $size,
                $this->config->chunkSize(),
                $actorUserId,
                time() + $this->config->uploadTtl(),
            );
        } catch (\Throwable $throwable) {
            @unlink($temporaryPath);
            throw $throwable;
        }
    }

    /**
     * HR: Upisuje točno sljedeći dio; različiti offset odbija i vraća sigurno stanje za nastavak.
     * EN: Writes exactly the next chunk; a different offset is rejected while preserving resumable state.
     *
     * @param resource $stream
     * @return array<string,mixed>
     */
    public function append(string $uuid, int $offset, $stream, int $actorUserId): array
    {
        if (!is_resource($stream)) {
            throw new ConfluenceImportException(__('Dio uploada nije moguće pročitati.'));
        }

        return $this->repository->withLockedJob(
            $uuid,
            $actorUserId,
            function (array $job, Database $database) use ($offset, $stream): array {
                $this->assertNotExpired($job);
                if (($job['status'] ?? '') !== 'uploading') {
                    throw new ConfluenceImportException(__('Upload više ne prima dijelove.'));
                }

                $nextOffset = (int)($job['next_offset'] ?? -1);
                if ($offset !== $nextOffset) {
                    throw new ConfluenceImportException(sprintf(
                        __('Nastavite upload od bajta %d.'),
                        $nextOffset,
                    ));
                }

                $size = (int)($job['archive_size'] ?? 0);
                $limit = min((int)($job['chunk_size'] ?? $this->config->chunkSize()), $size - $nextOffset);
                if ($limit <= 0) {
                    throw new ConfluenceImportException(__('Upload je već dovršen.'));
                }

                $path = (string)($job['archive_path'] ?? '');
                clearstatcache(true, $path);
                if (!is_file($path) || filesize($path) !== $nextOffset) {
                    throw new ConfluenceImportException(__('Privremena upload datoteka nije u očekivanom stanju.'));
                }

                $target = fopen($path, 'c+b');
                if (!is_resource($target) || !flock($target, LOCK_EX) || fseek($target, $nextOffset) !== 0) {
                    if (is_resource($target)) {
                        fclose($target);
                    }
                    throw new ConfluenceImportException(__('Upload datoteku nije moguće zaključati.'));
                }

                $written = 0;
                try {
                    while (!feof($stream)) {
                        $remaining = $limit - $written;
                        $buffer = fread($stream, max(1, min(1048576, $remaining + 1)));
                        if (!is_string($buffer)) {
                            throw new ConfluenceImportException(__('Dio uploada nije moguće pročitati.'));
                        }
                        if ($buffer === '') {
                            break;
                        }
                        if (strlen($buffer) > $remaining) {
                            throw new ConfluenceImportException(__('Dio uploada prelazi dogovorenu veličinu.'));
                        }

                        $count = fwrite($target, $buffer);
                        if (!is_int($count) || $count !== strlen($buffer)) {
                            throw new ConfluenceImportException(__('Cijeli dio uploada nije moguće spremiti.'));
                        }
                        $written += $count;
                    }
                    fflush($target);
                } finally {
                    flock($target, LOCK_UN);
                    fclose($target);
                }

                if ($written < 1) {
                    throw new ConfluenceImportException(__('Prazan dio uploada nije dopušten.'));
                }

                $newOffset = $nextOffset + $written;
                $database->table(\AaiEduHr\SimbiozaModuleConfluenceImport\ModuleSimbiozaConfluenceImport::TABLE_JOBS)
                    ->where('id', '=', (int)$job['id'])
                    ->update([
                        'next_offset' => $newOffset,
                        'expires_at' => gmdate('Y-m-d H:i:s', time() + $this->config->uploadTtl()),
                        'updated_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                $job['next_offset'] = $newOffset;

                return $job;
            },
        );
    }

    /**
     * HR: Zaključuje upload, provjerava ZIP i odmah radi read-only preflight.
     * EN: Finalizes upload, validates ZIP, and immediately runs a read-only preflight.
     *
     * @return array<string,mixed>
     */
    public function finish(string $uuid, int $actorUserId): array
    {
        $this->extendPreflightExecutionTime();

        $job = $this->repository->jobByUuid($uuid, $actorUserId);
        $this->assertNotExpired($job);
        if (($job['status'] ?? '') !== 'uploading') {
            throw new ConfluenceImportException(__('Upload nije u stanju za završetak.'));
        }

        $path = (string)($job['archive_path'] ?? '');
        clearstatcache(true, $path);
        $actualSize = is_file($path) ? filesize($path) : false;
        if (
            !is_int($actualSize)
            || $actualSize !== (int)($job['archive_size'] ?? 0)
            || $actualSize !== (int)($job['next_offset'] ?? -1)
        ) {
            throw new ConfluenceImportException(__('Upload Confluence arhive nije potpun.'));
        }

        $signature = file_get_contents($path, false, null, 0, 4);
        if (!is_string($signature) || !in_array($signature, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true)) {
            throw new ConfluenceImportException(__('Uploadana datoteka nije ZIP arhiva.'));
        }

        $finalPath = substr($path, 0, -7) . '.zip';
        if (!rename($path, $finalPath)) {
            throw new ConfluenceImportException(__('Završenu Confluence arhivu nije moguće objaviti.'));
        }

        try {
            $this->repository->updateJob((int)$job['id'], [
                'archive_path' => $finalPath,
                'sha256' => hash_file('sha256', $finalPath),
                'status' => 'scanning',
                'stage' => 'preflight',
            ]);
            $scan = $this->scanner->scan($finalPath);
            $this->repository->saveScan((int)$job['id'], $scan);
        } catch (\Throwable $throwable) {
            $this->repository->failImport((int)$job['id'], $throwable->getMessage());
            throw $throwable;
        }

        $ready = $this->repository->jobByUuid($uuid, $actorUserId);
        $ready['scan'] = $scan;

        return $ready;
    }

    /**
     * HR: Velikoj završnoj provjeri daje isti konfigurabilni vremenski okvir kao
     *     potvrđenom importu, umjesto zadanih 30 sekundi web zahtjeva.
     * EN: Gives a large final preflight the same configurable execution window
     *     as a confirmed import instead of the web request's default 30 seconds.
     */
    private function extendPreflightExecutionTime(): void
    {
        if (!function_exists('set_time_limit')) {
            return;
        }

        try {
            set_time_limit($this->config->importExecutionTimeLimit());
        } catch (\Throwable) {
            // HR: Nastavljamo s poslužiteljskim limitom; scanner i dalje može
            //     završiti kada je PHP konfiguriran bez vremenskog ograničenja.
            // EN: Continue with the server limit; the scanner can still finish
            //     when PHP is configured without an execution-time limit.
        }
    }

    /**
     * HR: Uklanja samo istekle privremene poslove koji još nisu započeli
     *     mijenjati aplikacijski sadržaj.
     * EN: Removes only expired temporary jobs that have not started mutating
     *     application content.
     */
    public function cleanupExpired(): int
    {
        $removed = 0;
        foreach ($this->repository->expiredTransientJobs(gmdate('Y-m-d H:i:s')) as $job) {
            $path = is_scalar($job['archive_path'] ?? null) ? trim((string)$job['archive_path']) : '';
            if (
                $path !== ''
                && str_starts_with($path, rtrim($this->config->uploadDirectory(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
                && is_file($path)
            ) {
                @unlink($path);
            }

            $jobId = is_numeric($job['id'] ?? null) ? (int)$job['id'] : 0;
            if ($jobId > 0) {
                $this->repository->deleteTransientJob($jobId);
                ++$removed;
            }
        }

        return $removed;
    }

    /**
     * HR: Provjerava može li administrator sigurno odustati prije nego što je
     * import počeo mijenjati aplikacijski sadržaj. Neuspjela početna provjera
     * također je privremeni posao ako potvrđene opcije još nisu spremljene.
     * EN: Checks whether an administrator may safely cancel before the import
     * starts mutating application content. A failed preflight is also transient
     * while no confirmed options have been stored.
     *
     * @param array<string,mixed> $job
     */
    public function canCancel(array $job): bool
    {
        $status = is_scalar($job['status'] ?? null) ? trim((string)$job['status']) : '';
        if (in_array($status, ['uploading', 'scanning', 'ready'], true)) {
            return true;
        }

        $optionsStarted = is_scalar($job['options_json'] ?? null)
            && trim((string)$job['options_json']) !== '';

        return $status === 'failed'
            && !$optionsStarted
            && (!is_numeric($job['workspace_id'] ?? null) || (int)$job['workspace_id'] <= 0);
    }

    /**
     * HR: Otkazuje vlastiti privremeni posao, briše prenesenu arhivu ili
     * nedovršenu `.upload` datoteku i tek potom uklanja zapis posla. Posao koji
     * je počeo izrađivati sadržaj nije dopušteno otkazati ovim putem.
     * EN: Cancels the actor's transient job, deletes its uploaded archive or
     * incomplete `.upload` file, and only then removes the job record. A job
     * that started creating content cannot be cancelled through this path.
     *
     * @return array<string,mixed>
     */
    public function cancel(string $uuid, int $actorUserId): array
    {
        return $this->repository->withLockedJob(
            $uuid,
            $actorUserId,
            function (array $job, Database $database): array {
                if (!$this->canCancel($job)) {
                    throw new ConfluenceImportException(
                        __('Import koji je već počeo mijenjati sadržaj nije moguće otkazati.'),
                    );
                }

                $this->deleteManagedTransientFile($job);
                $database->table(
                    \AaiEduHr\SimbiozaModuleConfluenceImport\ModuleSimbiozaConfluenceImport::TABLE_JOBS,
                )
                    ->where('id', '=', (int)$job['id'])
                    ->delete();

                return $job;
            },
        );
    }

    /**
     * HR: Briše završenu izvornu arhivu nakon uspješnog importa.
     * EN: Deletes the completed source archive after a successful import.
     *
     * @param array<string,mixed> $job
     */
    public function deleteArchive(array $job): void
    {
        $path = is_scalar($job['archive_path'] ?? null) ? trim((string)$job['archive_path']) : '';
        if ($path !== '' && is_file($path) && str_starts_with($path, $this->config->uploadDirectory())) {
            @unlink($path);
        }
    }

    /**
     * HR: Briše isključivo stvarnu datoteku unutar privatnog upload direktorija.
     * EN: Deletes only a real file located inside the private upload directory.
     *
     * @param array<string,mixed> $job
     */
    private function deleteManagedTransientFile(array $job): void
    {
        $path = is_scalar($job['archive_path'] ?? null) ? trim((string)$job['archive_path']) : '';
        if ($path === '' || !is_file($path)) {
            return;
        }

        $root = realpath($this->config->uploadDirectory());
        $real = realpath($path);
        if (
            !is_string($root)
            || !is_string($real)
            || !str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
        ) {
            throw new ConfluenceImportException(__('Datoteka importa nije unutar privatnog upload direktorija.'));
        }

        if (!unlink($real)) {
            throw new ConfluenceImportException(__('Prenesenu Confluence arhivu nije moguće obrisati.'));
        }
    }

    /** HR: Stvara privatni direktorij uploada. EN: Creates the private upload directory. */
    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new ConfluenceImportException(__('Direktorij Confluence importa nije moguće kreirati.'));
        }
    }

    /**
     * HR: Odbija nastavak isteklog uploada.
     * EN: Rejects continuation of an expired upload.
     *
     * @param array<string,mixed> $job
     */
    private function assertNotExpired(array $job): void
    {
        $expiresAt = is_scalar($job['expires_at'] ?? null) ? trim((string)$job['expires_at']) : '';
        if ($expiresAt !== '' && $expiresAt < gmdate('Y-m-d H:i:s')) {
            throw new ConfluenceImportException(__('Upload je istekao. Pokrenite novi prijenos arhive.'));
        }
    }
}
