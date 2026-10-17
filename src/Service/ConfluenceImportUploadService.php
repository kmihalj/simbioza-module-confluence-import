<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\LocaleSorter;
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
use function hash_equals;
use function is_dir;
use function is_file;
use function is_link;
use function gmdate;
use function is_int;
use function is_resource;
use function is_string;
use function link;
use function max;
use function mkdir;
use function rename;
use function rmdir;
use function scandir;
use function set_time_limit;
use function usort;
use function strtolower;
use function str_ends_with;
use function time;
use function trim;
use function unlink;

/**
 * EN: Receives large Confluence archives in validated resumable chunks.
 *
 * HR: Prima velike Confluence arhive u provjerenim nastavivim dijelovima.
 */
final readonly class ConfluenceImportUploadService
{
    /**
     * EN: Receives the repository, limits, and preflight scanner.
     *
     * HR: Prima repozitorij, limite i preflight scanner.
     */
    public function __construct(
        private ConfluenceImportRepository $repository,
        private ConfluenceImportConfig $config,
        private ConfluenceExportScanner $scanner,
    ) {
    }

    /**
     * EN: Returns direct XML ZIP files placed by an administrator for batch import.
     *
     * HR: Vraća izravne XML ZIP datoteke koje je administrator postavio za batch import.
     *
     * @return list<array{name:string,size:int}>
     */
    public function batchArchives(): array
    {
        $directory = $this->config->batchDirectory();
        $this->ensureDirectory($directory);
        $names = [];
        foreach (scandir($directory) ?: [] as $name) {
            if (
                $name === '.'
                || $name === '..'
                || basename($name) !== $name
                || !str_ends_with(strtolower($name), '.xml.zip')
            ) {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (is_link($path) || !is_file($path)) {
                continue;
            }
            $size = filesize($path);
            if (is_int($size) && $size > 0 && $size <= $this->config->maxArchiveSize()) {
                $names[] = $name;
            }
        }
        usort($names, static fn(string $left, string $right): int => LocaleSorter::compare($left, $right));

        return array_map(
            static fn(string $name): array => [
                'name' => $name,
                'size' => (int)filesize($directory . DIRECTORY_SEPARATOR . $name),
            ],
            $names,
        );
    }

    /**
     * EN: Prepares one server-side batch archive through the same preflight path as
     * a manual upload. The source remains in the batch directory until completion,
     * while a hard link avoids temporarily duplicating large archives.
     *
     * HR: Priprema jednu serversku batch arhivu istim preflight putem kao ručni upload.
     * Izvor ostaje u batch direktoriju do uspješnog završetka, a tvrda poveznica
     * izbjegava privremeno udvostručavanje velikih arhiva.
     *
     * @return array<string,mixed>
     */
    public function adoptBatchArchive(string $name, int $actorUserId): array
    {
        $this->cleanupExpired();
        $active = $this->repository->activeBatchJob($actorUserId);
        if (is_array($active)) {
            throw new ConfluenceImportException(__('Prethodni batch import još nije dovršen.'));
        }

        $directory = $this->config->batchDirectory();
        $this->ensureDirectory($directory);
        $name = trim($name);
        if (
            $name === ''
            || basename($name) !== $name
            || !str_ends_with(strtolower($name), '.xml.zip')
        ) {
            throw new ConfluenceImportException(__('Odaberite Confluence XML ZIP arhivu iz batch direktorija.'));
        }

        $candidate = $directory . DIRECTORY_SEPARATOR . $name;
        $root = realpath($directory);
        $source = realpath($candidate);
        if (
            !is_string($root)
            || !is_string($source)
            || is_link($candidate)
            || !is_file($source)
            || dirname($source) !== $root
        ) {
            throw new ConfluenceImportException(__('Batch Confluence arhiva nije pronađena.'));
        }
        $size = filesize($source);
        if (!is_int($size) || $size < 1 || $size > $this->config->maxArchiveSize()) {
            throw new ConfluenceImportException(__('Veličina Confluence arhive nije dopuštena.'));
        }

        $job = $this->start($name, $size, $actorUserId);
        $temporaryPath = (string)($job['archive_path'] ?? '');
        try {
            if (!unlink($temporaryPath) || !link($source, $temporaryPath)) {
                throw new ConfluenceImportException(
                    __('Batch arhivu nije moguće sigurno pripremiti bez udvostručavanja datoteke.'),
                );
            }
            $this->repository->updateJob((int)$job['id'], [
                'operation' => 'batch_import',
                'next_offset' => $size,
            ]);

            return $this->finish((string)$job['uuid'], $actorUserId);
        } catch (\Throwable $throwable) {
            $current = $this->repository->jobByUuid((string)$job['uuid'], $actorUserId);
            if ($this->canCancel($current)) {
                $this->cancel((string)$job['uuid'], $actorUserId);
            }
            throw $throwable;
        }
    }

    /**
     * EN: Starts a new resumable upload after validating its name and size.
     *
     * HR: Otvara novi nastavivi upload nakon provjere naziva i veličine.
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
     * EN: Writes exactly the next chunk; a different offset is rejected while preserving resumable state.
     *
     * HR: Upisuje točno sljedeći dio; različiti offset odbija i vraća sigurno stanje za nastavak.
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
     * EN: Finalizes upload, validates ZIP, and immediately runs a read-only preflight.
     *
     * HR: Zaključuje upload, provjerava ZIP i odmah radi read-only preflight.
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
            $scan = $this->scanner->scan($finalPath, $finalPath . '.inventory.json');
            /*
             * EN: Individual pages and attachments are read from the archive
             *     again during import. Mapping only needs summaries; retaining
             *     tens of thousands of records in the job exhausts FPM memory.
             *
             * HR: Pojedinačne stranice i privitke ponovno čitamo iz arhive tek
             *     pri importu. Za mapiranje su potrebni samo sažeci; spremanje
             *     desetaka tisuća zapisa u posao bi iscrpilo FPM memoriju.
             */
            unset($scan['pages'], $scan['attachments']);
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
     * EN: Gives a large final preflight the same configurable execution window
     *     as a confirmed import instead of the web request's default 30 seconds.
     *
     * HR: Velikoj završnoj provjeri daje isti konfigurabilni vremenski okvir kao
     *     potvrđenom importu, umjesto zadanih 30 sekundi web zahtjeva.
     */
    private function extendPreflightExecutionTime(): void
    {
        if (!function_exists('set_time_limit')) {
            return;
        }

        try {
            set_time_limit($this->config->importExecutionTimeLimit());
        } catch (\Throwable) {
            // EN: Continue with the server limit; the scanner can still finish
            //     when PHP is configured without an execution-time limit.
            //
            // HR: Nastavljamo s poslužiteljskim limitom; scanner i dalje može
            //     završiti kada je PHP konfiguriran bez vremenskog ograničenja.
        }
    }

    /**
     * EN: Removes only expired temporary jobs that have not started mutating
     *     application content.
     *
     * HR: Uklanja samo istekle privremene poslove koji još nisu započeli
     *     mijenjati aplikacijski sadržaj.
     */
    public function cleanupExpired(): int
    {
        $removed = 0;
        foreach ($this->repository->expiredTransientJobs(gmdate('Y-m-d H:i:s')) as $job) {
            $result = $this->repository->withLockedJob((string)$job['uuid'], (int)$job['actor_user_id'], function (array $current): array {
                if (!$this->canCancel($current) || empty($current['expires_at']) || $current['expires_at'] >= gmdate('Y-m-d H:i:s')) {
                    return ['removed' => false];
                }
                $this->deleteManagedTransientFile($current);
                $this->repository->deleteTransientJob((int)$current['id']);
                return ['removed' => true];
            });
            $removed += (int)$result['removed'];
        }

        // EN: A new upload also retries safe cleanup for completed jobs when a
        //     previous request stopped between finalization and deletion.
        //
        // HR: Novi prijenos ujedno ponavlja sigurno čišćenje dovršenih poslova
        //     ako je prethodni zahtjev prekinut između finalizacije i brisanja.
        $this->cleanupCompletedArtifacts();

        return $removed;
    }

    /**
     * EN: Removes only transient artifacts of completed jobs. Resumable or
     *     failed imports remain untouched because their data is needed for recovery.
     *
     * HR: Uklanja samo privremene artefakte dovršenih poslova. Ne dira
     *     nastavive ili neuspjele importe jer njihovi podaci trebaju za oporavak.
     */
    public function cleanupCompletedArtifacts(): int
    {
        $removed = 0;
        foreach ($this->repository->completedJobsForStorageCleanup() as $job) {
            if (in_array($job['operation'] ?? '', ['site_space', 'site_import'], true)) {
                continue;
            }
            $path = is_scalar($job['archive_path'] ?? null) ? trim((string)$job['archive_path']) : '';
            $jobId = is_numeric($job['id'] ?? null) ? (int)$job['id'] : 0;
            if ($path !== '') {
                $this->deleteManagedFile($path . '.inventory.json', $this->config->uploadDirectory());
                $this->deleteManagedFile($path . '.principals.json', $this->config->uploadDirectory());
            }
            if ($jobId > 0 && $path !== '' && $this->deleteManagedFile($path, $this->config->uploadDirectory())) {
                $this->repository->clearArchivePath($jobId);
                ++$removed;
            }
            $uuid = is_scalar($job['uuid'] ?? null) ? trim((string)$job['uuid']) : '';
            if ($uuid !== '' && $this->deleteManagedStagingDirectory($uuid)) {
                ++$removed;
            }
        }

        return $removed;
    }

    /**
     * EN: Checks whether an administrator may safely cancel before the import
     * starts mutating application content. A failed preflight is also transient
     * while no confirmed options have been stored.
     *
     * HR: Provjerava može li administrator sigurno odustati prije nego što je
     * import počeo mijenjati aplikacijski sadržaj. Neuspjela početna provjera
     * također je privremeni posao ako potvrđene opcije još nisu spremljene.
     *
     * @param array<string,mixed> $job
     */
    public function canCancel(array $job): bool
    {
        if (!in_array($job['options']['site_workflow']['phase'] ?? 'draft', ['draft', 'review'], true)) {
            return false;
        }
        if (in_array($job['options']['site_calendars']['phase'] ?? '', ['running', 'completed', 'interrupted'], true)) {
            return false;
        }
        if (($job['operation'] ?? '') === 'site_space' || (($job['operation'] ?? '') === 'site_import' && ($job['options']['site_content']['phase'] ?? 'review') !== 'review')) {
            return false;
        }
        $status = is_scalar($job['status'] ?? null) ? trim((string)$job['status']) : '';
        if (in_array($status, ['uploading', 'scanning', 'ready'], true)) {
            return true;
        }

        $options = is_array($job['options'] ?? null) ? $job['options'] : [];
        $optionsStarted = is_scalar($job['options_json'] ?? null)
            && trim((string)$job['options_json']) !== ''
            && !isset($options['inventory_selection']);

        return $status === 'failed'
            && !$optionsStarted
            && (!is_numeric($job['workspace_id'] ?? null) || (int)$job['workspace_id'] <= 0);
    }

    /**
     * EN: Explicitly releases only a completed site's managed source/indexes; reports and imported data remain.
     * The same file lock as C3 plus the owner row lock prevent cleanup racing a content/calendar worker.
     *
     * HR: Izričito uklanja samo upravljani izvor/indekse dovršenog sitea; izvještaji i uvezeni podaci ostaju.
     * Ista datotečna brava kao u C3 te brava vlasničkog retka sprječavaju utrku s obradom sadržaja/kalendara.
     *
     * @return array<string,mixed>
     */
    public function releaseSiteSource(string $uuid, int $actor): array
    {
        $this->repository->jobByUuid($uuid, $actor);
        $lockPath = $this->config->uploadDirectory() . '/' . $uuid . '.site.lock';
        if (is_link($lockPath)) {
            throw new ConfluenceImportException(__('Confluence import nije moguće zaključati.'));
        }
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new ConfluenceImportException(__('Uvoz je već pokrenut. Najprije ga dovršite.'));
        }
        try {
            return $this->repository->withLockedJob($uuid, $actor, function () use ($uuid, $actor): array {
                $job = $this->repository->jobByUuid($uuid, $actor);
                if (
                    $job['operation'] !== 'site_import' || $job['status'] !== 'completed'
                    || ($job['options']['site_calendars']['phase'] ?? '') === 'running'
                    || ($job['options']['site_workflow']['phase'] ?? 'completed') !== 'completed'
                ) {
                    throw new ConfluenceImportException(__('Radni izvor možete ukloniti tek nakon dovršetka uvoza područja i zaustavljanja svih obrada.'));
                }
                $children = $this->repository->jobsByUuids(array_values($job['options']['site_content']['children'] ?? []), $actor);
                if (count($children) !== count($job['options']['site_content']['children'] ?? [])) {
                    throw new ConfluenceImportException(__('Radni izvor možete ukloniti tek nakon dovršetka uvoza područja i zaustavljanja svih obrada.'));
                }
                foreach ($children as $child) {
                    if ($child['status'] !== 'completed') {
                        throw new ConfluenceImportException(__('Radni izvor možete ukloniti tek nakon dovršetka uvoza područja i zaustavljanja svih obrada.'));
                    }
                }
                if ($job['archive_path'] !== '') {
                    $calendarLockPath = $job['archive_path'] . '.calendars.lock';
                    if (is_link($calendarLockPath)) {
                        throw new ConfluenceImportException(__('Putanja Confluence indeksa nije valjana.'));
                    }
                    $calendarLock = fopen($calendarLockPath, 'c+b');
                    if ($calendarLock === false || !flock($calendarLock, LOCK_EX | LOCK_NB)) {
                        if (is_resource($calendarLock)) {
                            fclose($calendarLock);
                        }
                        throw new ConfluenceImportException(__('Uvoz je već pokrenut. Najprije ga dovršite.'));
                    }
                    try {
                        $this->deleteManagedTransientFile($job);
                    } finally {
                        flock($calendarLock, LOCK_UN);
                        fclose($calendarLock);
                    }
                }
                foreach ($children as $child) {
                    $this->repository->clearArchivePath((int)$this->repository->jobByUuid($child['uuid'], $actor)['id']);
                    $this->deleteManagedStagingDirectory($child['uuid']);
                }
                $summary = $job['summary'];
                $summary['source_released'] = true;
                $this->repository->updateJob((int)$job['id'], ['archive_path' => '', 'summary_json' => json_encode($summary, JSON_THROW_ON_ERROR)]);
                return ['released' => true];
            });
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * EN: Cancels the actor's transient job, deletes its uploaded archive or
     * incomplete `.upload` file, and only then removes the job record. A job
     * that started creating content cannot be cancelled through this path.
     *
     * HR: Otkazuje vlastiti privremeni posao, briše prenesenu arhivu ili
     * nedovršenu `.upload` datoteku i tek potom uklanja zapis posla. Posao koji
     * je počeo izrađivati sadržaj nije dopušteno otkazati ovim putem.
     *
     * @return array<string,mixed>
     */
    public function cancel(string $uuid, int $actorUserId): array
    {
        return $this->repository->withLockedJob(
            $uuid,
            $actorUserId,
            function (array $job, Database $database): array {
                $this->repository->assertSiteSetupMutable($job);
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
     * EN: Stores only a reviewed inventory selection; never starts a content import.
     * The owner, stage, source fingerprint and every selected source ID are rechecked.
     *
     * HR: Sprema samo pregledani odabir inventara; nikada ne pokreće uvoz sadržaja.
     * Ponovno provjerava vlasnika, fazu, otisak izvora i svaki odabrani izvorni ID.
     *
     * @param array<mixed> $spaceIds
     * @return list<string>
     */
    public function saveInventorySelection(string $uuid, int $actorUserId, string $sourceHash, array $spaceIds): array
    {
        return $this->repository->withLockedJob($uuid, $actorUserId, function () use ($uuid, $actorUserId, $sourceHash, $spaceIds): array {
            $job = $this->repository->jobByUuid($uuid, $actorUserId);
            $this->repository->assertSiteSetupMutable($job);
            $scan = $job['summary'] ?? [];
            if (
                ($job['status'] ?? '') !== 'ready' || ($scan['inventory_only'] ?? false) !== true
                || !is_string($scan['source_sha256'] ?? null) || !hash_equals($scan['source_sha256'], $sourceHash)
            ) {
                throw new ConfluenceImportException(__('Pregled sitea nije spreman ili je odabir zastario.'));
            }
            if (count($spaceIds) > $this->config->maxInventorySpaces()) {
                throw new ConfluenceImportException(__('Odabir područja nije valjan.'));
            }
            $allowed = array_fill_keys(array_column($scan['spaces'], 'source_id'), true);
            $selected = [];
            foreach ($spaceIds as $id) {
                if ((!is_string($id) && !is_int($id)) || !isset($allowed[(string)$id])) {
                    throw new ConfluenceImportException(__('Odabir područja nije valjan.'));
                }
                $selected[] = (string)$id;
            }
            $selected = array_values(array_unique($selected));
            $options = is_array($job['options'] ?? null) ? $job['options'] : [];
            $options['inventory_selection'] = ['space_ids' => $selected, 'source_sha256' => $sourceHash];
            unset($options['site_access']['preview']);
            unset($options['site_content']);
            $this->repository->updateJob((int)$job['id'], ['options_json' => json_encode($options, JSON_THROW_ON_ERROR)]);
            return $selected;
        });
    }

    /**
     * EN: Deletes the completed source archive after a successful import.
     *
     * HR: Briše završenu izvornu arhivu nakon uspješnog importa.
     *
     * @param array<string,mixed> $job
     */
    public function deleteArchive(array $job): void
    {
        if (in_array($job['operation'] ?? '', ['site_space', 'site_import'], true)) {
            return;
        }
        $path = is_scalar($job['archive_path'] ?? null) ? trim((string)$job['archive_path']) : '';
        $jobId = is_numeric($job['id'] ?? null) ? (int)$job['id'] : 0;
        $batchPath = $this->config->batchDirectory() . DIRECTORY_SEPARATOR
            . basename(is_scalar($job['original_name'] ?? null) ? (string)$job['original_name'] : '');
        $batchHash = ($job['operation'] ?? '') === 'batch_import' && is_file($batchPath)
            ? hash_file('sha256', $batchPath)
            : false;
        $expectedHash = is_scalar($job['sha256'] ?? null) ? trim((string)$job['sha256']) : '';
        if (
            $jobId > 0
            && $this->deleteManagedFile($path, $this->config->uploadDirectory())
        ) {
            $this->repository->clearArchivePath($jobId);
            if (
                is_string($batchHash)
                && $expectedHash !== ''
                && hash_equals($expectedHash, $batchHash)
            ) {
                $this->deleteManagedFile($batchPath, $this->config->batchDirectory());
            }
        }
    }

    /**
     * EN: Removes a file only from the explicitly supplied managed directory.
     *     A file that is already absent is considered cleaned.
     *
     * HR: Uklanja datoteku samo iz izričito zadanog upravljanog direktorija.
     *     Datoteka koja je već nestala smatra se očišćenom.
     */
    private function deleteManagedFile(string $path, string $directory): bool
    {
        if (is_link($path)) {
            return false;
        }
        if ($path === '' || !is_file($path)) {
            return true;
        }

        $root = realpath($directory);
        $real = realpath($path);
        if (
            !is_string($root)
            || !is_string($real)
            || !str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
        ) {
            return false;
        }

        return unlink($real) || !is_file($real);
    }

    /**
     * EN: Removes completed staging only from the direct directory of a known job.
     *
     * HR: Uklanja završeni staging samo iz izravnog direktorija poznatog posla.
     */
    private function deleteManagedStagingDirectory(string $jobUuid): bool
    {
        $stagingRoot = rtrim($this->config->dataDirectory(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'staging';
        $path = $stagingRoot . DIRECTORY_SEPARATOR . $jobUuid;
        if (!is_dir($path)) {
            return false;
        }

        $root = realpath($stagingRoot);
        $real = realpath($path);
        if (
            !is_string($root)
            || !is_string($real)
            || !str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
        ) {
            return false;
        }

        $this->removeManagedDirectory($real);

        return !is_dir($real);
    }

    /**
     * EN: Recursively removes an already verified staging directory.
     *
     * HR: Rekurzivno uklanja već provjeren staging direktorij.
     */
    private function removeManagedDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_link($path) || is_file($path)) {
                if (!unlink($path) && is_file($path)) {
                    throw new ConfluenceImportException(__('Privremenu datoteku dovršenog importa nije moguće ukloniti.'));
                }
                continue;
            }
            if (is_dir($path)) {
                $this->removeManagedDirectory($path);
            }
        }
        if (!rmdir($directory) && is_dir($directory)) {
            throw new ConfluenceImportException(__('Privremeni direktorij dovršenog importa nije moguće ukloniti.'));
        }
    }

    /**
     * EN: Deletes only a real file located inside the private upload directory.
     *
     * HR: Briše isključivo stvarnu datoteku unutar privatnog upload direktorija.
     *
     * @param array<string,mixed> $job
     */
    private function deleteManagedTransientFile(array $job): void
    {
        $path = is_scalar($job['archive_path'] ?? null) ? trim((string)$job['archive_path']) : '';
        if ($path === '') {
            return;
        }
        $this->deleteManagedContentIndex($path);
        if (!is_file($path)) {
            $this->deleteManagedFile($path . '.inventory.json', $this->config->uploadDirectory());
            $this->deleteManagedFile($path . '.principals.json', $this->config->uploadDirectory());
            return;
        }

        $root = realpath($this->config->uploadDirectory());
        $real = realpath($path);
        if (
            !is_string($root)
            || !is_string($real)
            || is_link($path)
            || !str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
        ) {
            throw new ConfluenceImportException(__('Datoteka importa nije unutar privatnog upload direktorija.'));
        }

        if (!unlink($real)) {
            throw new ConfluenceImportException(__('Prenesenu Confluence arhivu nije moguće obrisati.'));
        }
        $this->deleteManagedFile($path . '.inventory.json', $this->config->uploadDirectory());
        $this->deleteManagedFile($path . '.principals.json', $this->config->uploadDirectory());
    }

    /**
     * EN: Cleans only recognized flat content partitions beside a cancelled private upload.
     * Linked directories/files and unexpected contents fail closed; source archives are handled separately.
     *
     * HR: Čisti samo poznate ravne podjele sadržaja uz otkazani privatni prijenos.
     * Povezani direktoriji/datoteke i nepoznati sadržaj odbijaju se; izvorne arhive obrađuju se odvojeno.
     */
    private function deleteManagedContentIndex(string $archive): void
    {
        $this->deleteManagedFlatIndex($archive . '.content', '/^(?:[a-f0-9]{64}\.jsonl|common\.jsonl|manifest\.(?:json|tmp))$/D');
        $this->deleteManagedFlatIndex($archive . '.calendars', '/^(?:[a-f0-9]{64}\.jsonl|events\.tmp|catalog\.(?:json|tmp))$/D');
        $this->deleteManagedFile($archive . '.calendars.lock', $this->config->uploadDirectory());
        $this->deleteManagedFile($archive . '.calendars.progress.json', $this->config->uploadDirectory());
        $this->deleteManagedFile($archive . '.calendars.progress.json.tmp', $this->config->uploadDirectory());
    }

    /**
     * EN: Deletes only recognized private flat index files, never links or arbitrary descendants.
     *
     * HR: Briše samo poznate privatne ravne datoteke indeksa, nikada poveznice ili proizvoljne poddirektorije.
     */
    private function deleteManagedFlatIndex(string $directory, string $pattern): void
    {
        if (!is_dir($directory) && !is_link($directory)) {
            return;
        }
        $root = realpath($this->config->uploadDirectory());
        if (is_link($directory) || !is_string($root) || realpath(dirname($directory)) !== $root) {
            throw new ConfluenceImportException(__('Putanja Confluence indeksa nije valjana.'));
        }
        $paths = [];
        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . '/' . $name;
            if (is_link($path) || !is_file($path) || preg_match($pattern, $name) !== 1) {
                throw new ConfluenceImportException(__('Putanja Confluence indeksa nije valjana.'));
            }
            $paths[] = $path;
        }
        foreach ($paths as $path) {
            $this->deleteManagedFile($path, $this->config->uploadDirectory());
        }
        if (!rmdir($directory)) {
            throw new ConfluenceImportException(__('Confluence indeks nije moguće spremiti.'));
        }
    }

    /**
     * EN: Creates the private upload directory.
     *
     * HR: Stvara privatni direktorij uploada.
     */
    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new ConfluenceImportException(__('Direktorij Confluence importa nije moguće kreirati.'));
        }
    }

    /**
     * EN: Rejects continuation of an expired upload.
     *
     * HR: Odbija nastavak isteklog uploada.
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
