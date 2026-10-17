<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use AaiEduHr\SimbiozaModuleConfluenceImport\ModuleSimbiozaConfluenceImport;
use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;

use function array_chunk;
use function array_fill;
use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function bin2hex;
use function gmdate;
use function implode;
use function is_array;
use function is_numeric;
use function is_scalar;
use function json_decode;
use function json_encode;
use function max;
use function min;
use function preg_match;
use function random_bytes;
use function strtolower;
use function trim;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * EN: Sole persistence boundary for jobs and source Confluence mappings.
 *
 * HR: Jedina persistence granica za poslove i izvorna Confluence mapiranja.
 */
final class ConfluenceImportRepository
{
    /** @var array<string,mixed> */
    private array $siteContext = [];

    private bool $workflowContext = false;

    private string $referenceUrl = '';

    /** @var list<int> */
    private array $referenceJobs = [];

    /**
     * EN: Bridges references to earlier space imports only when their saved source URL matches.
     * This read context changes neither identity mappings nor the standalone import's storage format.
     * Unknown origins and other sites remain isolated; no application records are moved or rewritten.
     *
     * HR: Povezuje reference s ranijim uvozima područja samo kada im odgovara spremljeni izvorni URL.
     * Kontekst čitanja ne mijenja mapiranja identiteta ni format spremanja samostalnog uvoza.
     * Nepoznati izvori i drugi siteovi ostaju odvojeni; zapisi aplikacije ne premještaju se niti prepisuju.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function withReferenceContext(string $url, callable $callback): mixed
    {
        if (trim($url) === '') {
            return $callback();
        }
        if (ConfluenceSiteAccessService::sourceUrl($url) === $this->referenceUrl) {
            return $callback();
        }
        $previous = [$this->referenceUrl, $this->referenceJobs];
        $this->referenceUrl = ConfluenceSiteAccessService::sourceUrl($url);
        $this->referenceJobs = [];
        try {
            $after = 0;
            do {
                $rows = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
                ->select(['id', 'options_json'])->where('id', '>', $after)->where('operation', '=', 'import')
                ->whereIn('status', ['running', 'completed'])->orderBy('id')->limit(200)->get();
                foreach ($rows as $row) {
                    $after = (int)$row['id'];
                    $options = json_decode((string)($row['options_json'] ?? ''), true) ?? [];
                    $source = $options['source_base_url'] ?? '';
                    try {
                        if (is_string($source) && $source !== '' && ConfluenceSiteAccessService::sourceUrl($source) === $this->referenceUrl) {
                            $this->referenceJobs[] = $after;
                        }
                    } catch (ConfluenceImportException) {
                        // EN: Invalid historical metadata does not establish a trusted origin.
                        //
                        // HR: Neispravni povijesni metapodaci ne potvrđuju pouzdan izvor.
                    }
                }
            } while (count($rows) === 200);
            return $callback();
        } finally {
            [$this->referenceUrl, $this->referenceJobs] = $previous;
        }
    }

    /**
     * EN: Only database-derived integer job IDs enter the SQL list; URLs always use bound parameters.
     *
     * HR: U SQL popis ulaze samo cijeli ID-jevi poslova iz baze; URL-ovi uvijek koriste vezane parametre.
     *
     * @return array{string,list<string>}
     */
    private function referenceScope(string $column = 'source_space_key', string $jobColumn = 'job_id', string $explicitKey = ''): array
    {
        $prefix = (string)($this->siteContext['prefix'] ?? '');
        if ($prefix === '' && $this->referenceUrl === '' && preg_match('/^site:[a-f0-9]{64}:/D', $explicitKey) === 1) {
            return ['1 = 1', []];
        }
        if (($this->siteContext['copy_scope'] ?? false) === true) {
            $keys = array_map($this->siteKey(...), array_values($this->siteContext['aliases']));
            return [$column . ' IN (' . implode(',', array_fill(0, count($keys), '?')) . ')', $keys];
        }
        if ($this->referenceUrl === '') {
            return [$column . ($prefix !== '' ? ' LIKE ?' : ' NOT LIKE ?'), [$prefix !== '' ? $prefix . '%' : 'site:%']];
        }
        $prefix = 'site:' . hash('sha256', $this->referenceUrl) . ':';
        $legacy = $this->referenceJobs === [] ? '0 = 1' : $jobColumn . ' IN (' . implode(',', $this->referenceJobs) . ')';
        return ['(' . $column . ' LIKE ? OR (' . $column . ' NOT LIKE ? AND ' . $legacy . '))', [$prefix . '%', 'site:%']];
    }

    /**
     * EN: Canonical namespaced references take precedence over verified standalone mappings; copies never fall back.
     *
     * HR: Kanonske reference s izvornim prostorom imaju prednost pred potvrđenim samostalnim mapiranjima; kopije nemaju zamjenski cilj.
     *
     * @return list<string>
     */
    private function referenceKeys(string $key): array
    {
        $qualified = $this->siteKey($key);
        if ($this->referenceUrl === '' || ($this->siteContext['copy_scope'] ?? false)) {
            return [$qualified];
        }
        $prefix = 'site:' . hash('sha256', $this->referenceUrl) . ':';
        $raw = str_starts_with($qualified, $prefix) ? substr($qualified, strlen($prefix)) : $qualified;
        if (str_starts_with($raw, 'site:')) {
            return [$qualified];
        }
        return array_values(array_unique([$prefix . $raw, $raw]));
    }

    /**
     * EN: Only the server-side coordinator may advance component steps in an executing site workflow.
     *
     * HR: Samo poslužiteljski koordinator smije napredovati komponentnim koracima pokrenutog uvoza sitea.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function withWorkflowContext(callable $callback): mixed
    {
        $previous = $this->workflowContext;
        $this->workflowContext = true;
        try {
            return $callback();
        } finally {
            $this->workflowContext = $previous;
        }
    }

    /**
     * EN: A direct legacy endpoint cannot edit or advance one part of a frozen unified import.
     *
     * HR: Izravna stara krajnja točka ne smije urediti ili pokrenuti dio zaključanog zajedničkog uvoza.
     *
     * @param array<string,mixed> $job
     */
    public function assertSiteSetupMutable(array $job): void
    {
        $phase = $job['options']['site_workflow']['phase'] ?? 'draft';
        if (!$this->workflowContext && !in_array($phase, ['draft', 'review'], true)) {
            throw new ConfluenceImportException(__('Uvoz sitea je zaključan. Nastavite zajednički postupak uvoza.'));
        }
    }

    /**
     * EN: Runs one site child with an isolated source namespace and confirmed group mappings.
     * Context is restored even after failure; ordinary single-space calls have no context.
     *
     * HR: Izvodi jedan podposao sitea s odvojenim izvorom i potvrđenim mapiranjima grupa.
     * Kontekst vraća i nakon greške; obični pozivi jednog područja nemaju kontekst.
     *
     * @template T
     * @param array<string,int> $groupMap
     * @param array<string,string> $spaceAliases
     * @param callable():T $callback
     * @return T
     */
    public function withSiteContext(string $url, array $groupMap, callable $callback, array $spaceAliases = []): mixed
    {
        $old = $this->siteContext;
        $maps = $this->siteMappings('c2:' . hash('sha256', ConfluenceSiteAccessService::sourceUrl($url)) . ':');
        $normalizedGroups = [];
        foreach ($groupMap as $name => $id) {
            $normalizedGroups[mb_strtolower($name)] = $id;
        }
        $this->siteContext = ['url' => ConfluenceSiteAccessService::sourceUrl($url), 'groups' => $normalizedGroups, 'users' => $maps['users'], 'aliases' => $spaceAliases,
            'prefix' => 'site:' . hash('sha256', ConfluenceSiteAccessService::sourceUrl($url)) . ':',
            'copy_scope' => array_filter($spaceAliases, static fn(string $value, string $key): bool => $value !== $key, ARRAY_FILTER_USE_BOTH) !== []];
        try {
            return $this->withReferenceContext($url, $callback);
        } finally {
            $this->siteContext = $old;
        }
    }

    /**
     * EN: Qualifies source space identifiers, never local IDs or attachment archive paths.
     *
     * HR: Razlučuje identifikatore izvornog područja, ne lokalne ID-jeve ni arhivske putanje privitaka.
     */
    private function siteKey(string $key, bool $spaceId = false): string
    {
        $prefix = (string)($this->siteContext['prefix'] ?? '');
        if ($key === '' || $prefix === '' || str_starts_with($key, $prefix)) {
            return $key;
        }
        if (!$spaceId) {
            $key = $this->siteContext['aliases'][$key] ?? $key;
        }
        if (strlen($prefix . $key) > 190) {
            throw new ConfluenceImportException(__('Confluence metapodaci područja prelaze dopuštenu veličinu.'));
        }
        return $prefix . $key;
    }

    /**
     * EN: Persists a source-qualified include reference so rendering cannot fall back to another site.
     *
     * HR: Sprema referencu uključivanja s izvornim prostorom kako prikaz ne bi posegnuo za drugim siteom.
     */
    public function referenceSpaceKey(string $key): string
    {
        return $this->siteKey($key);
    }
    /**
     * EN: Receives the portable ORM.
     *
     * HR: Prima prenosivi ORM.
     */
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * EN: Checks whether the complete module schema is installed.
     *
     * HR: Provjerava je li instalirana cijela modulska shema.
     */
    public function tablesReady(): bool
    {
        $schema = $this->database->schema();

        return $schema->hasTable(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
            && $schema->hasTable(ModuleSimbiozaConfluenceImport::TABLE_SPACES)
            && $schema->hasTable(ModuleSimbiozaConfluenceImport::TABLE_CONTENT)
            && $schema->hasTable(ModuleSimbiozaConfluenceImport::TABLE_IDENTITIES)
            && $schema->hasTable(ModuleSimbiozaConfluenceImport::TABLE_CALENDARS)
            && $schema->hasTable(ModuleSimbiozaConfluenceImport::TABLE_GROUPS)
            && $schema->hasTable(ModuleSimbiozaConfluenceImport::TABLE_LINKS)
            && $schema->hasTable(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS);
    }

    /**
     * EN: Opens a new resumable-upload job.
     *
     * HR: Otvara novi posao nastavivog uploada.
     *
     * @return array<string,mixed>
     */
    public function createUploadJob(
        string $originalName,
        string $archivePath,
        int $archiveSize,
        int $chunkSize,
        int $actorUserId,
        int $expiresAt,
    ): array {
        $this->assertReady();
        $uuid = $this->uuid();
        $now = gmdate('Y-m-d H:i:s');
        $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)->insert([
            'uuid' => $uuid,
            'operation' => 'import',
            'status' => 'uploading',
            'stage' => 'upload',
            'original_name' => $originalName,
            'archive_path' => $archivePath,
            'archive_size' => $archiveSize,
            'next_offset' => 0,
            'chunk_size' => $chunkSize,
            'actor_user_id' => $actorUserId,
            'expires_at' => gmdate('Y-m-d H:i:s', $expiresAt),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->jobByUuid($uuid, $actorUserId);
    }

    /**
     * EN: Fetches a job by UUID and optionally restricts its owner.
     *
     * HR: Dohvaća posao prema UUID-u i po izboru ograničava vlasnika.
     *
     * @return array<string,mixed>
     */
    public function jobByUuid(string $uuid, ?int $actorUserId = null): array
    {
        $this->assertReady();
        $query = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
            ->where('uuid', '=', trim($uuid));
        if ($actorUserId !== null) {
            $query->where('actor_user_id', '=', $actorUserId);
        }

        $row = $query->first();
        if (!is_array($row)) {
            throw new ConfluenceImportException(__('Confluence import posao nije pronađen.'));
        }

        return $this->normalizeRow($row);
    }

    /**
     * EN: Locks an upload job and runs a strictly sequential mutation.
     * Nested importer steps join the caller's transaction without committing or rolling it back themselves.
     *
     * HR: Zaključava upload posao i izvodi strogo sekvencijalnu izmjenu.
     * Ugniježđeni koraci importera koriste transakciju pozivatelja bez vlastitog commita ili rollbacka.
     *
     * @template TResult of array<array-key,mixed>
     * @param callable(array<string,mixed>,Database):TResult $callback
     * @return TResult
     */
    public function withLockedJob(string $uuid, int $actorUserId, callable $callback): array
    {
        $run = function (Database $database) use ($uuid, $actorUserId, $callback): array {
            $row = $database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
                ->select(['id', 'uuid', 'operation', 'original_name', 'status', 'stage', 'archive_path', 'archive_size', 'next_offset', 'chunk_size',
                    'actor_user_id', 'expires_at', 'options_json', 'workspace_id'])
                ->where('uuid', '=', trim($uuid))
                ->where('actor_user_id', '=', $actorUserId)
                ->lockForUpdate()
                ->first();
            if (!is_array($row)) {
                throw new ConfluenceImportException(__('Confluence import posao nije pronađen.'));
            }

            return $callback($this->normalizeRow($row), $database);
        };
        return $this->database->getConnection()->inTransaction() ? $run($this->database) : $this->database->transaction($run);
    }

    /**
     * EN: Returns stable native calendar targets for one source site, including subcalendar aliases.
     *
     * HR: Vraća stabilne nativne kalendare jednog izvornog sitea, uključujući zamjenske ID-jeve podkalendara.
     *
     * @return array<string,int>
     */
    public function calendarMappings(string $url): array
    {
        $result = [];
        foreach (
            $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_CALENDARS)
            ->where('source_instance', '=', hash('sha256', ConfluenceSiteAccessService::sourceUrl($url)))->get() as $row
        ) {
            $result[(string)$row['source_calendar_id']] = (int)$row['target_calendar_id'];
        }
        return $result;
    }

    /**
     * EN: Stores source provenance in the same transaction as Calendar API writes and the C4 checkpoint.
     *
     * HR: Sprema podrijetlo u istoj transakciji kao Calendar API zapise i C4 kontrolnu točku.
     */
    public function mapCalendar(string $url, string $source, int $target, int $job): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_CALENDARS)->upsert([[
            'source_instance' => hash('sha256', ConfluenceSiteAccessService::sourceUrl($url)),
            'source_calendar_id' => $source, 'target_calendar_id' => $target, 'job_id' => $job,
            'created_at' => $now, 'updated_at' => $now,
        ]], ['source_instance', 'source_calendar_id'], ['target_calendar_id', 'job_id', 'updated_at']);
    }

    /**
     * EN: Updates selected job fields and its modification time.
     *
     * HR: Ažurira odabrana polja posla i vrijeme izmjene.
     *
     * @param array<string,mixed> $values
     */
    public function updateJob(int $jobId, array $values): void
    {
        $values['updated_at'] = gmdate('Y-m-d H:i:s');
        $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
            ->where('id', '=', $jobId)
            ->update($values);
    }

    /**
     * EN: Batch-reads child progress without loading page reports or querying once per space.
     *
     * HR: Skupno čita napredak podposlova bez učitavanja izvještaja stranica ni upita po području.
     *
     * @param list<string> $uuids
     * @return list<array<string,mixed>>
     */
    public function jobsByUuids(array $uuids, int $actor): array
    {
        $result = [];
        foreach (array_chunk($uuids, 200) as $chunk) {
            $query = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
                ->select(['uuid', 'status', 'source_space_key', 'workspace_id'])
                ->where('actor_user_id', '=', $actor)
                ->whereRaw('uuid IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk);
            foreach ($query->orderBy('id', 'ASC')->get() as $row) {
                $result[] = $this->normalizeRow($row);
            }
        }
        return $result;
    }

    /**
     * EN: Reads linked report summaries in bounded batches, not one query per child or all site reports at once.
     * The parent's owner/source fingerprint is verified again by the reporting service.
     *
     * HR: Čita povezane sažetke izvještaja u ograničenim paketima, ne upit po podposlu ili sve izvještaje odjednom.
     * Servis izvještaja ponovno provjerava vlasnika roditelja i otisak izvora.
     *
     * @param list<string> $uuids
     * @return iterable<array<string,mixed>>
     */
    public function reportJobs(array $uuids, int $actor): iterable
    {
        foreach (array_chunk(array_values(array_unique($uuids)), 20) as $chunk) {
            $rows = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
                ->select(['id', 'uuid', 'actor_user_id', 'operation', 'status', 'stage', 'sha256', 'source_space_key', 'summary_json'])
                ->where('actor_user_id', '=', $actor)
                ->whereRaw('uuid IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk)
                ->orderBy('id', 'ASC')->get();
            $linkCounts = $this->reportLinkCounts(array_map(intval(...), array_column($rows, 'id')));
            foreach ($rows as $row) {
                yield [...$this->normalizeRow($row), 'link_counts' => $linkCounts[(int)$row['id']] ?? ['links_resolved' => 0, 'links_unresolved' => 0]];
            }
        }
    }

    /**
     * EN: Aggregates current link states in bounded queries, including resolutions from later imports.
     * Historical import counters are preserved; no page body or private link target enters the overview.
     *
     * HR: Zbraja aktualna stanja linkova ograničenim upitima, uključujući razrješenja kasnijih uvoza.
     * Povijesna brojanja ostaju sačuvana; pregled ne izlaže tijelo stranice ni privatni cilj poveznice.
     *
     * @param list<int> $ids
     * @return array<int,array<string,int>>
     */
    private function reportLinkCounts(array $ids): array
    {
        $counts = [];
        foreach (array_chunk($ids, 20) as $chunk) {
            $rows = $this->database->fetchAll('SELECT job_id, status, COUNT(*) AS total FROM '
                . ModuleSimbiozaConfluenceImport::TABLE_LINKS . ' WHERE job_id IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ') GROUP BY job_id, status', $chunk);
            foreach ($rows as $row) {
                $key = $row['status'] === 'resolved' ? 'links_resolved' : 'links_unresolved';
                $counts[(int)$row['job_id']][$key] = (int)$row['total'];
            }
        }
        return $counts;
    }

    /**
     * EN: Selects one completed, owned space report in the verified source namespace, including older runs.
     * Content provenance excludes same-named spaces from other sources and reports without imported pages.
     *
     * HR: Odabire jedan dovršen vlastiti izvještaj područja iz provjerenog izvora, uključujući ranije uvoze.
     * Podrijetlo sadržaja isključuje istoimena područja drugih izvora i izvještaje bez uvezenih stranica.
     *
     * @return array<string,mixed>|null
     */
    public function nextSourceSpaceReport(string $url, int $actor, int $after): ?array
    {
        return $this->withReferenceContext($url, function () use ($actor, $after): ?array {
            [$scope, $bindings] = $this->referenceScope('c.source_space_key', 'c.job_id');
            $row = $this->database->fetchOne(
                'SELECT j.* FROM ' . ModuleSimbiozaConfluenceImport::TABLE_JOBS . ' j'
                . ' WHERE j.actor_user_id = ? AND j.status = ? AND j.operation IN (?, ?) AND j.id > ?'
                . ' AND EXISTS (SELECT 1 FROM ' . ModuleSimbiozaConfluenceImport::TABLE_CONTENT
                . ' c WHERE c.job_id = j.id AND ' . $scope . ') ORDER BY j.id ASC LIMIT 1',
                [$actor, 'completed', 'site_space', 'import', max(0, $after), ...$bindings]
            );
            return is_array($row) ? $this->normalizeRow($row) : null;
        });
    }

    /**
     * EN: Returns an administrator's unfinished batch job so two requests cannot start two Workspaces in parallel.
     *
     * HR: Vraća nedovršeni batch posao administratora kako dva zahtjeva ne bi paralelno pokrenula dva područja.
     *
     * @return array<string,mixed>|null
     */
    public function activeBatchJob(int $actorUserId): ?array
    {
        $row = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
            ->select(['id', 'uuid', 'status', 'original_name', 'options_json'])
            ->where('operation', '=', 'batch_import')
            ->where('actor_user_id', '=', $actorUserId)
            ->whereRaw("status IN ('uploading', 'scanning', 'ready', 'running')")
            ->orderBy('id', 'DESC')
            ->first();

        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    /**
     * EN: Updates the publicly readable stage of a long-running import.
     *
     * HR: Ažurira javno čitljivu fazu dugotrajnog importa.
     */
    public function setStage(int $jobId, string $stage): void
    {
        $this->updateJob($jobId, ['stage' => trim($stage)]);
    }

    /**
     * EN: Stores the preflight inventory and opens mapping.
     *
     * HR: Sprema rezultat preflight pregleda i otvara mapiranje.
     *
     * @param array<string,mixed> $scan
     */
    public function saveScan(int $jobId, array $scan): void
    {
        // EN: The database job retains only the inventory; large records are read from the ZIP again.
        //
        // HR: Posao u bazi čuva samo inventar; velike zapise ponovno čitamo iz ZIP-a.
        unset($scan['pages'], $scan['attachments']);
        $space = is_array($scan['spaces'][0] ?? null) ? $scan['spaces'][0] : [];
        $inventory = ($scan['inventory_only'] ?? false) === true;
        $this->updateJob($jobId, [
            'status' => 'ready',
            'stage' => $inventory ? 'inventory' : 'mapping',
            'source_space_key' => $inventory ? '' : $this->string($space['source_key'] ?? ''),
            'source_space_name' => $inventory ? __('Pregled Confluence izvoza') : $this->string($space['name'] ?? ''),
            'source_space_type' => $inventory ? $this->string($scan['archive_type'] ?? '') : $this->string($space['type'] ?? 'global'),
            'summary_json' => $this->json($scan),
            'error_message' => null,
        ]);
    }

    /**
     * EN: Marks a job as running and stores confirmed options.
     *
     * HR: Označava posao pokrenutim i sprema potvrđene opcije.
     *
     * @param array<string,mixed> $options
     */
    public function startImport(int $jobId, array $options): void
    {
        $this->updateJob($jobId, [
            'status' => 'running',
            'stage' => 'workspace',
            'options_json' => $this->json($options),
            'error_message' => null,
        ]);
    }

    /**
     * EN: Completes a job while retaining its source path until the storage
     *     service confirms that the archive was actually removed.
     *
     * HR: Dovršava posao, ali zadržava izvornu putanju dok storage servis ne
     *     potvrdi da je arhiva stvarno uklonjena.
     *
     * @param array<string,mixed> $summary
     */
    public function completeImport(int $jobId, int $workspaceId, array $summary): void
    {
        $this->updateJob($jobId, [
            'status' => 'completed',
            'stage' => 'completed',
            'workspace_id' => $workspaceId,
            'summary_json' => $this->json($summary),
            'expires_at' => null,
            'error_message' => null,
        ]);
    }

    /**
     * EN: Forgets the path only after confirmed archive deletion.
     *
     * HR: Zaboravlja putanju tek nakon potvrđenog brisanja arhive.
     */
    public function clearArchivePath(int $jobId): void
    {
        if ($jobId <= 0) {
            return;
        }

        $this->updateJob($jobId, ['archive_path' => '']);
    }

    /**
     * EN: Returns completed jobs and their transient artifacts for a safe
     *     cleanup retry.
     *
     * HR: Vraća dovršene poslove i njihove privremene artefakte za siguran
     *     ponovni pokušaj čišćenja.
     *
     * @return list<array<string,mixed>>
     */
    public function completedJobsForStorageCleanup(): array
    {
        $rows = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
            ->select(['id', 'uuid', 'archive_path', 'operation'])
            ->where('status', '=', 'completed')
            ->orderBy('id', 'ASC')
            ->get();
        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = $this->normalizeRow($row);
            }
        }

        return $result;
    }

    /**
     * EN: Records an error without losing the preflight summary.
     *
     * HR: Bilježi pogrešku bez gubitka preflight sažetka.
     */
    public function failImport(int $jobId, string $message): void
    {
        $this->updateJob($jobId, [
            'status' => 'failed',
            'stage' => 'failed',
            'error_message' => $message,
        ]);
    }

    /**
     * EN: Returns jobs expired before a real import began; completed and
     *     partially executed imports are never automatic-cleanup candidates.
     *
     * HR: Vraća istekle poslove prije početka stvarnog importa; dovršeni i
     *     djelomično izvedeni importi nikada nisu kandidati za automatsko brisanje.
     *
     * @return list<array<string,mixed>>
     */
    public function expiredTransientJobs(string $now): array
    {
        $rows = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
            ->select(['id', 'uuid', 'actor_user_id', 'operation', 'status', 'archive_path', 'options_json', 'workspace_id'])
            ->where('expires_at', '<', trim($now))
            ->orderBy('id', 'ASC')
            ->get();
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !$this->isTransientJobRow($row)) {
                continue;
            }

            $result[] = $this->normalizeRow($row);
        }

        return $result;
    }

    /**
     * EN: Deletes one proven temporary job.
     *
     * HR: Briše jedan dokazano privremeni posao.
     */
    public function deleteTransientJob(int $jobId): void
    {
        $row = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
            ->select(['operation', 'status', 'options_json', 'workspace_id'])
            ->where('id', '=', $jobId)
            ->first();
        if (!is_array($row) || !$this->isTransientJobRow($row)) {
            return;
        }

        $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
            ->where('id', '=', $jobId)
            ->delete();
    }

    /**
     * EN: Recognizes a job that has not started content import. An inventory
     * selection is not confirmed content-import options.
     *
     * HR: Prepoznaje posao koji nije započeo sadržajni import. Odabir inventara
     * nije potvrda opcija sadržajnog importa.
     *
     * @param array<mixed,mixed> $row
     */
    private function isTransientJobRow(array $row): bool
    {
        $decoded = json_decode((string)($row['options_json'] ?? ''), true);
        if (
            in_array($row['operation'] ?? '', ['site_import', 'site_space'], true)
            || in_array($decoded['site_calendars']['phase'] ?? '', ['running', 'completed', 'interrupted'], true)
        ) {
            return false;
        }
        if (in_array($row['status'] ?? null, ['uploading', 'scanning', 'ready'], true)) {
            return true;
        }

        $optionsJson = is_scalar($row['options_json'] ?? null) ? trim((string)$row['options_json']) : '';
        $options = $optionsJson !== '' ? json_decode($optionsJson, true) : null;
        $inventorySelectionOnly = is_array($options) && array_keys($options) === ['inventory_selection'];

        return ($row['status'] ?? null) === 'failed'
            && ($optionsJson === '' || $inventorySelectionOnly)
            && (!is_numeric($row['workspace_id'] ?? null) || (int)$row['workspace_id'] <= 0);
    }

    /**
     * EN: Saves or refreshes a source-space to target-Workspace mapping.
     *
     * HR: Sprema ili osvježava izvorno područje i ciljnu Workspace vezu.
     *
     * @param array<string,mixed> $space
     * @param array<string,mixed> $workspace
     */
    public function mapSpace(array $space, array $workspace, int $jobId): void
    {
        $sourceId = $this->siteKey($this->string($space['source_id'] ?? ''), true);
        $existing = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_SPACES)
            ->where('source_instance', '=', 'archive')
            ->where('source_space_id', '=', $sourceId)
            ->first();
        $now = gmdate('Y-m-d H:i:s');
        $values = [
            'source_space_key' => $this->siteKey($this->string($space['source_key'] ?? '')),
            'source_space_type' => $this->string($space['type'] ?? 'global'),
            'source_space_name' => $this->string($space['name'] ?? ''),
            'source_owner_key' => $this->nullableString($space['owner_source_key'] ?? null),
            'target_workspace_id' => $this->integer($workspace['id'] ?? 0),
            'target_workspace_slug' => $this->string($workspace['slug'] ?? ''),
            'job_id' => $jobId,
            'source_metadata_json' => $this->json($space),
            'updated_at' => $now,
        ];
        if (is_array($existing)) {
            $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_SPACES)
                ->where('id', '=', $this->integer($existing['id'] ?? 0))
                ->update($values);
            return;
        }

        $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_SPACES)->insert([
            'source_instance' => 'archive',
            'source_space_id' => $sourceId,
            'created_at' => $now,
            ...$values,
        ]);
    }

    /**
     * EN: Returns an earlier import of the same source Confluence space.
     *
     * HR: Vraća raniji import istog izvornog Confluence područja.
     *
     * @return array<string,mixed>|null
     */
    public function spaceBySourceId(string $sourceId): ?array
    {
        $sourceId = $this->siteKey(trim($sourceId), true);
        if ($sourceId === '') {
            return null;
        }

        $row = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_SPACES)
            ->where('source_instance', '=', 'archive')
            ->where('source_space_id', '=', $sourceId)
            ->first();

        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    /**
     * EN: Batch-loads selected source targets for site review without an N+1 mapping lookup.
     *
     * HR: Skupno učitava odabrane izvorne ciljeve za pregled sitea bez N+1 dohvata mapiranja.
     *
     * @param list<string> $ids
     * @return array<string,array<string,mixed>>
     */
    public function spacesBySourceIds(array $ids): array
    {
        $result = [];
        foreach (array_chunk($ids, 200) as $chunk) {
            $keys = array_map(fn(string $id): string => $this->siteKey($id, true), $chunk);
            foreach (
                $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_SPACES)
                ->where('source_instance', '=', 'archive')->whereIn('source_space_id', $keys)->get() as $row
            ) {
                $row = $this->normalizeRow($row);
                $key = (string)$row['source_space_id'];
                $prefix = (string)($this->siteContext['prefix'] ?? '');
                $result[$prefix !== '' && str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key] = $row;
            }
        }
        return $result;
    }

    /**
     * EN: Resolves a Confluence space key to the latest stored local mapping.
     *
     * HR: Razrješava Confluence ključ područja u zadnje spremljeno lokalno mapiranje.
     *
     * @return array<string,mixed>|null
     */
    public function spaceBySourceKey(string $sourceKey): ?array
    {
        if (trim($sourceKey) === '') {
            return null;
        }
        [$scope, $bindings] = $this->referenceScope('source_space_key', 'job_id', $sourceKey);
        foreach ($this->referenceKeys(trim($sourceKey)) as $key) {
            $row = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_SPACES)
                ->where('source_instance', '=', 'archive')->whereRaw($scope, $bindings)
                ->whereRaw('LOWER(source_space_key) = LOWER(?)', [$key])->orderBy('updated_at', 'DESC')->first();
            if (is_array($row)) {
                return $this->normalizeRow($row);
            }
        }
        return null;
    }

    /**
     * EN: Returns every durable Confluence-key to local-Workspace-slug mapping
     *     in one query so imported content can be materialized locally.
     *
     * HR: Jednim upitom vraća sva trajna mapiranja Confluence ključeva na
     *     lokalne Workspace slugove za materijalizaciju uvezenog sadržaja.
     *
     * @return array<string,string>
     */
    public function workspaceSlugsBySourceKey(): array
    {
        $result = [];
        [$scope, $bindings] = $this->referenceScope();
        foreach (
            $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_SPACES)
                ->where('source_instance', '=', 'archive')
                ->whereRaw($scope, $bindings)
                ->orderBy('updated_at', 'ASC')
                ->get() as $row
        ) {
            if (!is_array($row)) {
                continue;
            }
            $sourceKey = strtoupper($this->string($row['source_space_key'] ?? ''));
            $prefix = $this->referenceUrl !== '' ? 'site:' . hash('sha256', $this->referenceUrl) . ':' : (string)($this->siteContext['prefix'] ?? '');
            if ($prefix !== '' && str_starts_with($sourceKey, strtoupper($prefix))) {
                $sourceKey = substr($sourceKey, strlen($prefix));
                foreach ($this->siteContext['aliases'] ?? [] as $originalKey => $alias) {
                    if (strtoupper((string)$originalKey) === $sourceKey && strtoupper($alias) !== $sourceKey) {
                        continue 2;
                    }
                }
                $original = array_search($sourceKey, array_map(strtoupper(...), $this->siteContext['aliases'] ?? []), true);
                if ($original !== false) {
                    $sourceKey = strtoupper((string)$original);
                }
            }
            $workspaceSlug = $this->string($row['target_workspace_slug'] ?? '');
            if ($sourceKey !== '' && $workspaceSlug !== '') {
                $result[$sourceKey] = $workspaceSlug;
            }
        }

        return $result;
    }

    /**
     * EN: Stores an explicit source-user mapping.
     *
     * HR: Sprema izričito mapiranje izvornog korisnika.
     *
     * @param array<string,mixed> $user
     */
    public function mapIdentity(array $user, ?int $targetUserId, bool $confirmed, int $jobId): void
    {
        $sourceKey = $this->string($user['source_key'] ?? '');
        if ($sourceKey === '') {
            return;
        }

        $existing = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_IDENTITIES)
            ->where('source_user_key', '=', $sourceKey)
            ->first();
        $now = gmdate('Y-m-d H:i:s');
        $existingTargetId = is_array($existing) && is_numeric($existing['target_user_id'] ?? null)
            ? (int)$existing['target_user_id']
            : null;
        $existingConfirmed = is_array($existing)
            && (bool)($existing['confirmed'] ?? false)
            && ($existing['mapping_status'] ?? '') === 'mapped'
            && $existingTargetId !== null
            && $existingTargetId > 0;
        if ($existingConfirmed && $targetUserId !== null && $targetUserId !== $existingTargetId) {
            throw new ConfluenceImportException(
                __('Confluence identitet već je potvrđeno povezan s drugim korisnikom. Postojeće mapiranje nije promijenjeno.'),
            );
        }
        if ($existingConfirmed) {
            // EN: A repeated import of another Workspace must not clear or redirect the global account mapping.
            //
            // HR: Ponovljeni import drugog područja ne smije poništiti ili preusmjeriti globalno mapiranje računa.
            $targetUserId = $existingTargetId;
            $confirmed = true;
        }
        $values = [
            'source_username' => $this->nullableString($user['username'] ?? null),
            'source_display_name' => $this->nullableString($user['display_name'] ?? null),
            'source_email' => $this->nullableString($user['email'] ?? null),
            'target_user_id' => $targetUserId,
            'mapping_status' => $targetUserId !== null ? 'mapped' : 'unresolved',
            'confirmed' => $confirmed,
            'updated_at' => $now,
        ];
        if (is_array($existing)) {
            $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_IDENTITIES)
                ->where('id', '=', $this->integer($existing['id'] ?? 0))
                ->update($values);
            return;
        }

        $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_IDENTITIES)->insert([
            'source_user_key' => $sourceKey,
            'job_id' => $jobId,
            'created_at' => $now,
            ...$values,
        ]);
    }

    /**
     * EN: Returns a confirmed target user ID or null.
     *
     * HR: Vraća potvrđeni ciljni user ID ili null.
     */
    public function mappedUserId(string $sourceKey): ?int
    {
        if (trim($sourceKey) === '') {
            return null;
        }

        if ($this->siteContext !== []) {
            $sourceKey = str_starts_with($sourceKey, 'confluence-user:') || str_starts_with($sourceKey, 'internal-user:')
                ? $sourceKey : 'confluence-user:' . $sourceKey;
            return $this->siteContext['users'][ConfluenceSiteAccessService::mappingKey((string)$this->siteContext['url'], 'users', $sourceKey)] ?? null;
        }
        $row = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_IDENTITIES)
            ->where('source_user_key', '=', trim($sourceKey))
            ->where('mapping_status', '=', 'mapped')
            ->where('confirmed', '=', true)
            ->first();
        $id = is_array($row) ? $this->integer($row['target_user_id'] ?? 0) : 0;

        return $id > 0 ? $id : null;
    }

    /**
     * EN: Stores a confirmed or unresolved Confluence-group mapping.
     *
     * HR: Sprema potvrđeno ili neriješeno mapiranje Confluence grupe.
     */
    public function mapGroup(string $sourceName, ?int $targetGroupId, bool $confirmed, int $jobId): void
    {
        $sourceName = trim($sourceName);
        if ($sourceName === '') {
            return;
        }

        $existing = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_GROUPS)
            ->where('source_group_name', '=', $sourceName)
            ->first();
        $now = gmdate('Y-m-d H:i:s');
        $existingTargetId = is_array($existing) && is_numeric($existing['target_group_id'] ?? null)
            ? (int)$existing['target_group_id']
            : null;
        $existingConfirmed = is_array($existing)
            && (bool)($existing['confirmed'] ?? false)
            && ($existing['mapping_status'] ?? '') === 'mapped'
            && $existingTargetId !== null
            && $existingTargetId > 0;
        if ($existingConfirmed && $targetGroupId !== null && $targetGroupId !== $existingTargetId) {
            throw new ConfluenceImportException(
                __('Confluence grupa već je potvrđeno povezana s drugom grupom. Postojeće mapiranje nije promijenjeno.'),
            );
        }
        if ($existingConfirmed) {
            $targetGroupId = $existingTargetId;
            $confirmed = true;
        }
        $values = [
            'target_group_id' => $targetGroupId,
            'mapping_status' => $targetGroupId !== null ? 'mapped' : 'unresolved',
            'confirmed' => $confirmed,
            'updated_at' => $now,
        ];
        if (is_array($existing)) {
            $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_GROUPS)
                ->where('id', '=', $this->integer($existing['id'] ?? 0))
                ->update($values);
            return;
        }

        $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_GROUPS)->insert([
            'source_group_name' => $sourceName,
            'job_id' => $jobId,
            'created_at' => $now,
            ...$values,
        ]);
    }

    /**
     * EN: Returns a previously confirmed target-group mapping.
     *
     * HR: Vraća prethodno potvrđeno mapiranje ciljne grupe.
     */
    public function mappedGroupId(string $sourceName): ?int
    {
        if ($this->siteContext !== []) {
            return $this->siteContext['groups'][mb_strtolower($sourceName)] ?? null;
        }
        $sourceName = trim($sourceName);
        if ($sourceName === '') {
            return null;
        }

        $row = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_GROUPS)
            ->where('source_group_name', '=', $sourceName)
            ->where('mapping_status', '=', 'mapped')
            ->where('confirmed', '=', true)
            ->first();
        $id = is_array($row) ? $this->integer($row['target_group_id'] ?? 0) : 0;

        return $id > 0 ? $id : null;
    }

    /**
     * EN: Loads confirmed site mappings in two queries, never one query per source principal.
     * The prefix is an importer-generated hash, not a free-form SQL pattern.
     *
     * HR: Učitava potvrđena mapiranja sitea u dva upita, ne jednim upitom po identitetu.
     * Prefiks je hash koji generira importer, a ne slobodno unesen SQL uzorak.
     *
     * @return array{users:array<string,int>,groups:array<string,int>}
     */
    public function siteMappings(string $prefix): array
    {
        if (preg_match('/^c2:[a-f0-9]{64}:$/D', $prefix) !== 1) {
            throw new ConfluenceImportException(__('Izvorni site nije valjan.'));
        }
        $result = ['users' => [], 'groups' => []];
        foreach (
            [['users', ModuleSimbiozaConfluenceImport::TABLE_IDENTITIES, 'source_user_key', 'target_user_id'],
            ['groups', ModuleSimbiozaConfluenceImport::TABLE_GROUPS, 'source_group_name', 'target_group_id']] as [$kind, $table, $source, $target]
        ) {
            foreach (
                $this->database->table($table)->select([$source, $target])->where($source, 'LIKE', $prefix . '%')
                ->where('confirmed', '=', true)->where('mapping_status', '=', 'mapped')->get() as $row
            ) {
                if ((int)$row[$target] > 0) {
                    $result[$kind][(string)$row[$source]] = (int)$row[$target];
                }
            }
        }
        return $result;
    }

    /**
     * EN: Stores the mapping between a source page and target node.
     *
     * HR: Sprema vezu izvorne stranice i ciljnog čvora.
     *
     * @param array<string,mixed> $page
     * @param array<string,mixed> $target
     */
    public function mapContent(array $page, array $target, int $jobId, string $status = 'imported'): void
    {
        $spaceKey = $this->siteKey($this->string($target['source_space_key'] ?? ''));
        $sourceId = $this->string($page['source_id'] ?? '');
        $existing = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_CONTENT)
            ->where('source_space_key', '=', $spaceKey)
            ->where('source_content_id', '=', $sourceId)
            ->first();
        $now = gmdate('Y-m-d H:i:s');
        $values = [
            'logical_source_id' => $this->string($page['logical_source_id'] ?? $sourceId),
            'source_type' => $this->string($page['source_type'] ?? 'page'),
            'source_status' => $this->string($page['status'] ?? 'current'),
            'source_version' => $this->integer($page['version'] ?? 1),
            'source_parent_id' => $this->nullableString($page['parent_id'] ?? null),
            'source_title' => $this->nullableString($page['title'] ?? null),
            'target_workspace_id' => $this->integer($target['workspace_id'] ?? 0),
            'target_node_id' => $this->positiveOrNull($target['node_id'] ?? null),
            'target_document_key' => $this->nullableString($target['document_key'] ?? null),
            'target_slug' => $this->nullableString($target['slug'] ?? null),
            'import_status' => $status,
            'job_id' => $jobId,
            'source_metadata_json' => $this->json($page),
            'note' => $this->nullableString($target['note'] ?? null),
            'updated_at' => $now,
        ];
        if (is_array($existing)) {
            $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_CONTENT)
                ->where('id', '=', $this->integer($existing['id'] ?? 0))
                ->update($values);
            return;
        }

        $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_CONTENT)->insert([
            'source_space_key' => $spaceKey,
            'source_content_id' => $sourceId,
            'created_at' => $now,
            ...$values,
        ]);
    }

    /**
     * EN: Finds an imported page by space key and source ID.
     *
     * HR: Traži uvezenu stranicu po ključu područja i izvornom ID-u.
     *
     * @return array<string,mixed>|null
     */
    public function contentBySource(string $spaceKey, string $sourceId): ?array
    {
        [$scope, $bindings] = $this->referenceScope('source_space_key', 'job_id', $spaceKey);
        foreach ($this->referenceKeys(trim($spaceKey)) as $key) {
            $row = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_CONTENT)
                ->whereRaw($scope, $bindings)->whereRaw('LOWER(source_space_key) = LOWER(?)', [$key])
                ->where('logical_source_id', '=', trim($sourceId))->where('import_status', '=', 'imported')
                ->orderBy('source_version', 'DESC')->first();
            if (is_array($row)) {
                return $this->normalizeRow($row);
            }
        }
        return null;
    }

    /**
     * EN: Finds the target document even when a stored link points at a
     *     concrete historical-version ID instead of the logical page ID.
     *
     * HR: Pronalazi ciljni dokument i kada spremljena poveznica pokazuje na
     *     ID konkretne povijesne verzije umjesto logičkog ID-a stranice.
     *
     * @return array<string,mixed>|null
     */
    public function contentBySourceReference(string $spaceKey, string $sourceId): ?array
    {
        $spaceKey = trim($spaceKey);
        $sourceId = trim($sourceId);
        if ($spaceKey === '' || $sourceId === '') {
            return null;
        }

        [$scope, $bindings] = $this->referenceScope('source_space_key', 'job_id', $spaceKey);
        foreach ($this->referenceKeys($spaceKey) as $key) {
            $row = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_CONTENT)
                ->whereRaw($scope, $bindings)->whereRaw('LOWER(source_space_key) = LOWER(?)', [$key])
                ->whereRaw('(logical_source_id = ? OR source_content_id = ?)', [$sourceId, $sourceId])
                ->where('import_status', '=', 'imported')->orderBy('source_version', 'DESC')->first();
            if (is_array($row)) {
                return $this->normalizeRow($row);
            }
        }
        return null;
    }

    /**
     * EN: Resolves a Workspace-root reference to its imported homepage.
     *
     * HR: Razrješava korijensku poveznicu područja u njegovu uvezenu naslovnicu.
     *
     * @return array<string,mixed>|null
     */
    public function homepageContentBySpaceKey(string $spaceKey): ?array
    {
        $space = $this->spaceBySourceKey($spaceKey);
        if (!is_array($space)) {
            return null;
        }

        $canonicalKey = $this->string($space['source_space_key'] ?? $spaceKey);
        $metadata = is_array($space['source_metadata'] ?? null) ? $space['source_metadata'] : [];
        $homePageId = $this->string($metadata['home_page_id'] ?? $metadata['homepage_id'] ?? '');
        if ($homePageId !== '') {
            $mapping = $this->contentBySource($canonicalKey, $homePageId);
            if (is_array($mapping)) {
                return $mapping;
            }
        }

        [$scope, $bindings] = $this->referenceScope('source_space_key', 'job_id', $canonicalKey);
        $row = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_CONTENT)
            ->whereRaw($scope, $bindings)
            ->whereRaw('LOWER(source_space_key) = LOWER(?)', [$canonicalKey])
            ->where('import_status', '=', 'imported')
            ->whereNull('source_parent_id')
            ->orderBy('source_version', 'DESC')
            ->first();

        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    /**
     * EN: Returns the newest page mapping from one exact import.
     *
     * HR: Vraća najnovije mapiranje stranice iz točno određenog importa.
     *
     * @return array<string,mixed>|null
     */
    public function contentForJobSource(int $jobId, string $sourceId): ?array
    {
        if ($jobId <= 0 || trim($sourceId) === '') {
            return null;
        }

        $row = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_CONTENT)
            ->where('job_id', '=', $jobId)
            ->where('logical_source_id', '=', trim($sourceId))
            ->where('import_status', '=', 'imported')
            ->orderBy('source_version', 'DESC')
            ->first();

        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    /**
     * EN: Updates only the durable report of an already completed import.
     *
     * HR: Ažurira samo trajni izvještaj već dovršenog importa.
     *
     * @param array<string,mixed> $summary
     */
    public function updateCompletedSummary(int $jobId, array $summary): void
    {
        if ($jobId <= 0) {
            throw new ConfluenceImportException(__('Confluence import posao nije pronađen.'));
        }

        $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
            ->where('id', '=', $jobId)
            ->where('status', '=', 'completed')
            ->update([
                'summary_json' => $this->json($summary),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
    }

    /**
     * EN: Resolves a pageId URL when the source URL does not contain a space key.
     *
     * HR: Razrješava pageId URL kada izvorni URL ne sadrži ključ područja.
     *
     * @return array<string,mixed>|null
     */
    public function contentByAnySourceId(string $sourceId): ?array
    {
        $sourceId = trim($sourceId);
        if ($sourceId === '') {
            return null;
        }

        $query = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_CONTENT)
            ->where('logical_source_id', '=', $sourceId)
            ->where('import_status', '=', 'imported');
        [$scope, $bindings] = $this->referenceScope();
        $query->whereRaw($scope, $bindings);
        if (($this->siteContext['copy_scope'] ?? false) === true) {
            $query->whereIn('source_space_key', array_map($this->siteKey(...), array_values($this->siteContext['aliases'])));
        }
        $row = $query->orderBy('source_version', 'DESC')->first();

        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    /**
     * EN: Finds an imported page by its source title.
     *
     * HR: Traži uvezenu stranicu po izvornom naslovu.
     *
     * @return array<string,mixed>|null
     */
    public function contentByTitle(string $spaceKey, string $title): ?array
    {
        [$scope, $bindings] = $this->referenceScope('source_space_key', 'job_id', $spaceKey);
        foreach ($this->referenceKeys(trim($spaceKey)) as $key) {
            $row = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_CONTENT)
                ->whereRaw($scope, $bindings)->whereRaw('LOWER(source_space_key) = LOWER(?)', [$key])
                ->where('source_title', '=', trim($title))->where('import_status', '=', 'imported')
                ->orderBy('source_version', 'DESC')->first();
            if (is_array($row)) {
                return $this->normalizeRow($row);
            }
        }
        return null;
    }

    /**
     * EN: Preserves public page slugs before a replacement import so existing
     *     cross-Workspace links keep pointing at the same URL.
     *
     * HR: Čuva javne slugove stranica prije zamjenskog importa kako bi stare
     *     međupodručne poveznice nastavile voditi na isti URL.
     *
     * @return array<string,string>
     */
    public function pageSlugsByWorkspace(int $workspaceId): array
    {
        if ($workspaceId <= 0) {
            return [];
        }

        $result = [];
        foreach (
            $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_CONTENT)
                ->where('target_workspace_id', '=', $workspaceId)
                ->where('import_status', '=', 'imported')
                ->orderBy('source_version', 'ASC')
                ->get() as $row
        ) {
            if (!is_array($row)) {
                continue;
            }
            $sourceId = $this->string($row['logical_source_id'] ?? '');
            $slug = $this->string($row['target_slug'] ?? '');
            if ($sourceId !== '' && $slug !== '') {
                $result[$sourceId] = $slug;
            }
        }

        return $result;
    }

    /**
     * EN: Reads one add-only target snapshot, including disabled nodes whose slugs
     * remain occupied. Only mappings from the verified source may identify old pages.
     * No Workspace or Editor records are written through this integration boundary.
     *
     * HR: Čita jedan snimak cilja za dodavanje, uključujući isključene čvorove čiji
     * slugovi ostaju zauzeti. Stare stranice određuju samo mapiranja potvrđenog izvora.
     * Ova integracijska granica ne zapisuje Workspace ni Editor podatke.
     *
     * @return array{nodes:list<array<string,mixed>>,mappings:list<array<string,mixed>>}
     */
    public function personalAppendSnapshot(string $spaceKey, int $workspaceId): array
    {
        [$scope, $bindings] = $this->referenceScope('source_space_key', 'job_id', $spaceKey);
        $keys = array_map(strtolower(...), $this->referenceKeys(trim($spaceKey)));
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $mappings = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_CONTENT)
            ->where('source_type', '=', 'page')
            ->where('target_workspace_id', '=', $workspaceId)->whereRaw($scope, $bindings)
            ->whereRaw('LOWER(source_space_key) IN (' . $placeholders . ')', $keys)
            ->orderBy('source_version', 'ASC')->get();
        $nodes = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
            ->where('workspace_id', '=', $workspaceId)->get();
        return ['nodes' => $nodes, 'mappings' => $mappings];
    }

    /**
     * EN: Returns the source mapping for a target Workspace.
     *
     * HR: Vraća izvorno mapiranje ciljnog područja.
     *
     * @return array<string,mixed>|null
     */
    public function spaceByWorkspaceId(int $workspaceId): ?array
    {
        if ($workspaceId <= 0) {
            return null;
        }

        $row = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_SPACES)
            ->where('target_workspace_id', '=', $workspaceId)
            ->first();

        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    /**
     * EN: Stores an unresolved or resolved source link.
     *
     * HR: Sprema neriješenu ili riješenu izvornu poveznicu.
     *
     * @param array<string,mixed> $link
     */
    public function recordLink(array $link, int $jobId): string
    {
        $uuid = $this->newLinkUuid();
        $link['uuid'] = $uuid;
        $this->recordLinks([$link], $jobId);

        return $uuid;
    }

    /**
     * EN: Generates a link identity before bulk persistence.
     *
     * HR: Generira identitet poveznice prije skupnog spremanja.
     */
    public function newLinkUuid(): string
    {
        return $this->uuid();
    }

    /**
     * EN: Stores multiple links in bounded batches so link-heavy pages do not
     *     execute one SQL statement per link.
     *
     * HR: Sprema više poveznica u ograničenim skupinama kako stranice s mnogo
     *     poveznica ne bi pokretale jedan SQL upit za svaku poveznicu.
     *
     * @param list<array<string,mixed>> $links
     */
    public function recordLinks(array $links, int $jobId): void
    {
        if ($links === []) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $rows = [];
        foreach ($links as $link) {
            $rows[] = [
                'uuid' => $this->string($link['uuid'] ?? $this->newLinkUuid()),
                'job_id' => $jobId,
                'source_page_id' => $this->string($link['source_page_id'] ?? ''),
                'source_space_key' => $this->siteKey($this->string($link['source_space_key'] ?? '')),
                'destination_space_key' => $this->nullableString($this->siteKey($this->string($link['destination_space_key'] ?? ''))),
                'destination_page_id' => $this->nullableString($link['destination_page_id'] ?? null),
                'destination_page_title' => $this->nullableString($link['destination_page_title'] ?? null),
                'original_target' => $this->nullableString($link['original_target'] ?? null),
                'resolved_target' => $this->nullableString($link['resolved_target'] ?? null),
                'status' => $this->string($link['status'] ?? 'unresolved'),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 50) as $batch) {
            $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_LINKS)
                ->upsert($batch, 'uuid', []);
        }
    }

    /**
     * EN: Fetches a stored link by UUID.
     *
     * HR: Dohvaća spremljenu poveznicu po UUID-u.
     *
     * @return array<string,mixed>
     */
    public function linkByUuid(string $uuid): array
    {
        $row = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_LINKS)
            ->where('uuid', '=', trim($uuid))
            ->first();
        if (!is_array($row)) {
            throw new ConfluenceImportException(__('Uvezena poveznica nije pronađena.'));
        }

        return $this->normalizeRow($row);
    }

    /**
     * EN: Returns unresolved links plus every link to the Workspace that just changed.
     *
     * HR: Vraća neriješene poveznice i sve poveznice prema upravo promijenjenom području.
     *
     * @return list<array<string,mixed>>
     */
    public function linksForReconciliation(string $destinationSpaceKey = ''): array
    {
        $rows = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_LINKS)
            ->where('status', '=', 'unresolved')
            ->orderBy('id', 'ASC')
            ->get();
        if (trim($destinationSpaceKey) !== '') {
            $rows = [
                ...$rows,
                ...$this->database->table(ModuleSimbiozaConfluenceImport::TABLE_LINKS)
                    ->whereRaw('LOWER(destination_space_key) = LOWER(?)', [trim($destinationSpaceKey)])
                    ->whereRaw('status <> ?', ['manually_resolved'])
                    ->orderBy('id', 'ASC')
                    ->get(),
            ];
        }
        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $normalized = $this->normalizeRow($row);
                $result[$this->integer($normalized['id'] ?? 0)] = $normalized;
            }
        }

        return array_values($result);
    }

    /**
     * EN: Reads the next bounded link batch by ID; changing processed link
     *     statuses must not shift the following result page.
     *
     * HR: Čita sljedeći ograničeni skup veza po ID-u; promjena statusa već
     *     obrađenih veza ne smije pomaknuti stranicu rezultata.
     *
     * @return list<array<string,mixed>>
     */
    public function linksForReconciliationBatch(string $destinationSpaceKey, int $afterId, int $limit): array
    {
        $destinationKeys = $this->referenceKeys(trim($destinationSpaceKey));
        $query = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_LINKS)
            ->where('id', '>', max(0, $afterId));
        [$scope, $bindings] = $this->referenceScope();
        $query->whereRaw($scope, $bindings);
        if (($this->siteContext['copy_scope'] ?? false) === true) {
            $query->whereIn('source_space_key', array_map($this->siteKey(...), array_values($this->siteContext['aliases'])));
        }
        if ($destinationSpaceKey === '') {
            $query->where('status', '=', 'unresolved');
        } else {
            $query->whereRaw(
                "(status = 'unresolved' OR ((" . implode(' OR ', array_fill(0, count($destinationKeys), 'LOWER(destination_space_key) = LOWER(?)')) . ") AND status <> 'manually_resolved'))",
                $destinationKeys,
            );
        }

        $result = [];
        foreach ($query->orderBy('id', 'ASC')->limit(max(1, min(1000, $limit)))->get() as $row) {
            if (is_array($row)) {
                $result[] = $this->normalizeRow($row);
            }
        }

        return $result;
    }

    /**
     * EN: Counts links for finalization progress.
     *
     * HR: Broji veze za prikaz završnog napretka.
     */
    public function countLinksForReconciliation(string $destinationSpaceKey): int
    {
        $destinationKeys = $this->referenceKeys(trim($destinationSpaceKey));
        $where = "status = 'unresolved'";
        $bindings = [];
        if ($destinationSpaceKey !== '') {
            $where = "(status = 'unresolved' OR ((" . implode(' OR ', array_fill(0, count($destinationKeys), 'LOWER(destination_space_key) = LOWER(?)')) . ") AND status <> 'manually_resolved'))";
            $bindings = $destinationKeys;
        }
        [$scope, $scopeBindings] = $this->referenceScope();
        $where = '(' . $where . ') AND ' . $scope;
        $bindings = [...$bindings, ...$scopeBindings];
        if (($this->siteContext['copy_scope'] ?? false) === true) {
            $keys = array_map($this->siteKey(...), array_values($this->siteContext['aliases']));
            $where .= ' AND source_space_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')';
            $bindings = [...$bindings, ...$keys];
        }
        $row = $this->database->fetchOne(
            'SELECT COUNT(*) AS total FROM ' . ModuleSimbiozaConfluenceImport::TABLE_LINKS . ' WHERE ' . $where,
            $bindings,
        );

        return is_array($row) ? (int)($row['total'] ?? 0) : 0;
    }

    /**
     * EN: Returns only the currently unresolved links for one report.
     *
     * HR: Vraća samo trenutačno nerazriješene poveznice jednog izvještaja.
     *
     * @return list<array<string,mixed>>
     */
    public function unresolvedLinksForJob(int $jobId): array
    {
        if ($jobId <= 0) {
            return [];
        }

        $result = [];
        foreach (
            $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_LINKS)
                ->where('job_id', '=', $jobId)
                ->where('status', '=', 'unresolved')
                ->orderBy('source_page_id', 'ASC')
                ->orderBy('id', 'ASC')
                ->get() as $row
        ) {
            if (is_array($row)) {
                $result[] = $this->normalizeRow($row);
            }
        }

        return $result;
    }

    /**
     * EN: Stores the current link resolution or marks it unresolved again.
     *
     * HR: Sprema aktualno razrješenje poveznice ili je ponovno označava neriješenom.
     */
    public function updateLinkResolution(int $linkId, ?string $target): void
    {
        $target = trim((string)$target);
        $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_LINKS)
            ->where('id', '=', $linkId)
            ->update([
                'resolved_target' => $target !== '' ? $target : null,
                'status' => $target !== '' ? 'resolved' : 'unresolved',
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
    }

    /**
     * EN: Persists a small set of resolutions in one SQL statement instead
     *     of thousands of individual UPDATE statements during a large import.
     *
     * HR: Jednim SQL upitom sprema mali skup razrješenja, bez tisuća
     *     pojedinačnih UPDATE upita tijekom velikog importa.
     *
     * @param array<int,?string> $targetsById
     */
    public function updateLinkResolutions(array $targetsById): void
    {
        foreach (array_chunk($targetsById, 100, true) as $batch) {
            $targetCases = [];
            $statusCases = [];
            $targetBindings = [];
            $statusBindings = [];
            $ids = [];
            foreach ($batch as $id => $target) {
                $id = (int)$id;
                if ($id <= 0) {
                    continue;
                }
                $target = trim((string)$target);
                $ids[] = $id;
                $targetCases[] = 'WHEN ? THEN ?';
                $targetBindings[] = $id;
                $targetBindings[] = $target !== '' ? $target : null;
                $statusCases[] = 'WHEN ? THEN ?';
                $statusBindings[] = $id;
                $statusBindings[] = $target !== '' ? 'resolved' : 'unresolved';
            }
            if ($ids === []) {
                continue;
            }

            $this->database->execute(
                'UPDATE ' . ModuleSimbiozaConfluenceImport::TABLE_LINKS
                    . ' SET resolved_target = CASE id ' . implode(' ', $targetCases) . ' END,'
                    . ' status = CASE id ' . implode(' ', $statusCases) . ' END,'
                    . ' updated_at = ? WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
                [...$targetBindings, ...$statusBindings, gmdate('Y-m-d H:i:s'), ...$ids],
            );
        }
    }

    /**
     * EN: Permanently excludes manually corrected links from reports and future automatic reconciliation.
     *
     * HR: Trajno izuzima ručno korigirane poveznice iz izvještaja i budućih automatskih usklađivanja.
     *
     * @param list<string> $uuids
     */
    public function markLinksManuallyResolved(int $jobId, array $uuids): int
    {
        $uuids = array_values(array_unique(array_filter(array_map(
            static fn(mixed $uuid): string => is_scalar($uuid) ? trim((string)$uuid) : '',
            $uuids,
        ))));
        if ($jobId <= 0 || $uuids === []) {
            return 0;
        }

        return $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_LINKS)
            ->where('job_id', '=', $jobId)
            ->where('status', '=', 'unresolved')
            ->whereIn('uuid', $uuids)
            ->update([
                'status' => 'manually_resolved',
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
    }

    /**
     * EN: Marks one unsupported-macro warning as manually corrected in the durable report.
     *
     * HR: Označava jedno upozorenje nepodržanog makroa ručno korigiranim u trajnom izvještaju.
     */
    public function markReviewIssueManuallyResolved(
        int $jobId,
        string $sourcePageId,
        string $issueType,
        string $macro,
        string $marker,
        int $actorUserId,
    ): bool {
        if ($jobId <= 0 || trim($sourcePageId) === '' || trim($issueType) !== 'unsupported_macro') {
            return false;
        }

        $row = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
            ->where('id', '=', $jobId)
            ->where('status', '=', 'completed')
            ->first();
        if (!is_array($row)) {
            return false;
        }

        $job = $this->normalizeRow($row);
        $summary = is_array($job['summary'] ?? null) ? $job['summary'] : [];
        foreach (is_array($summary['review_pages'] ?? null) ? $summary['review_pages'] : [] as $pageIndex => $page) {
            if (!is_array($page) || $this->string($page['source_page_id'] ?? '') !== trim($sourcePageId)) {
                continue;
            }
            foreach (is_array($page['issues'] ?? null) ? $page['issues'] : [] as $issueIndex => $issue) {
                if (!is_array($issue) || (bool)($issue['resolved'] ?? false)) {
                    continue;
                }
                if (
                    $this->string($issue['type'] ?? '') !== trim($issueType)
                    || $this->string($issue['macro'] ?? '') !== trim($macro)
                    || (trim($marker) !== '' && $this->string($issue['marker'] ?? '') !== trim($marker))
                ) {
                    continue;
                }

                $summary['review_pages'][$pageIndex]['issues'][$issueIndex] = [
                    ...$issue,
                    'resolved' => true,
                    'resolution_mode' => 'manual_content_correction',
                    'resolved_at' => gmdate('Y-m-d H:i:s'),
                    'resolved_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
                ];
                $this->updateCompletedSummary($jobId, $summary);

                return true;
            }
        }

        return false;
    }

    /**
     * EN: Preserves Editor attachment UUIDs before a replacement import so all
     *     existing links to them remain valid.
     *
     * HR: Čuva UUID-e Editor privitaka prije zamjenskog importa kako bi svi
     *     postojeći linkovi na njih ostali valjani.
     *
     * @return array<string,string>
     */
    public function attachmentUuidsByWorkspace(int $workspaceId): array
    {
        if ($workspaceId <= 0) {
            return [];
        }

        $result = [];
        foreach (
            $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
                ->where('target_workspace_id', '=', $workspaceId)
                ->get() as $row
        ) {
            if (!is_array($row)) {
                continue;
            }
            $sourceId = $this->string($row['source_attachment_id'] ?? '');
            $version = max(1, $this->integer($row['source_version'] ?? 1));
            $uuid = strtolower($this->string($row['uuid'] ?? ''));
            if ($sourceId !== '' && preg_match('/^[0-9a-f-]{36}$/', $uuid) === 1) {
                $result[$sourceId . ':' . $version] = $uuid;
            }
        }

        return $result;
    }

    /**
     * EN: Stores metadata for a private imported attachment.
     *
     * HR: Sprema metapodatke privatnog uvezenog privitka.
     *
     * @param array<string,mixed> $attachment
     * @return array<string,mixed>
     */
    public function recordAttachment(array $attachment, int $jobId): array
    {
        $sourceId = $this->string($attachment['source_attachment_id'] ?? '');
        $version = $this->integer($attachment['source_version'] ?? 1);
        $existing = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
            ->where('job_id', '=', $jobId)
            ->where('source_attachment_id', '=', $sourceId)
            ->where('source_version', '=', $version)
            ->first();
        $now = gmdate('Y-m-d H:i:s');
        $values = [
            'logical_source_id' => $this->string($attachment['logical_source_id'] ?? $sourceId),
            'source_page_id' => $this->string($attachment['source_page_id'] ?? ''),
            'source_creator_key' => $this->nullableString($attachment['source_creator_key'] ?? null),
            'source_created_at' => $this->nullableString($attachment['source_created_at'] ?? null),
            'original_name' => $this->string($attachment['original_name'] ?? 'attachment'),
            'mime_type' => $this->string($attachment['mime_type'] ?? 'application/octet-stream'),
            'file_size' => $this->integer($attachment['file_size'] ?? 0),
            'storage_path' => $this->nullableString($attachment['storage_path'] ?? null),
            'target_workspace_id' => $this->positiveOrNull($attachment['workspace_id'] ?? null),
            'target_node_id' => $this->positiveOrNull($attachment['node_id'] ?? null),
            'target_document_key' => $this->nullableString($attachment['document_key'] ?? null),
            'status' => $this->string($attachment['status'] ?? 'pending'),
            'error_message' => $this->nullableString($attachment['error_message'] ?? null),
            'job_id' => $jobId,
            'updated_at' => $now,
        ];
        if (is_array($existing)) {
            $id = $this->integer($existing['id'] ?? 0);
            $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
                ->where('id', '=', $id)
                ->update($values);
            return $this->attachmentById($id);
        }

        $uuid = strtolower($this->string($attachment['uuid'] ?? ''));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid) !== 1) {
            $uuid = $this->uuid();
        }
        $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)->insert([
            'uuid' => $uuid,
            'source_attachment_id' => $sourceId,
            'source_version' => $version,
            'created_at' => $now,
            ...$values,
        ]);

        return $this->attachmentById((int)$this->database->lastInsertId());
    }

    /**
     * EN: Returns an already stored attachment version so a retried import does
     *     not duplicate a large binary file.
     *
     * HR: Vraća već spremljenu verziju privitka kako ponovljeni import ne bi
     *     duplicirao veliku binarnu datoteku.
     *
     * @return array<string,mixed>|null
     */
    public function attachmentBySourceVersion(int $jobId, string $sourceId, int $version): ?array
    {
        $row = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
            ->where('job_id', '=', $jobId)
            ->where('source_attachment_id', '=', trim($sourceId))
            ->where('source_version', '=', max(1, $version))
            ->first();

        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    /**
     * EN: Fetches an available private attachment by UUID.
     *
     * HR: Dohvaća dostupni privatni privitak po UUID-u.
     *
     * @return array<string,mixed>
     */
    public function attachmentByUuid(string $uuid): array
    {
        $row = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
            ->where('uuid', '=', trim($uuid))
            ->where('status', '=', 'stored')
            ->first();
        if (!is_array($row)) {
            throw new ConfluenceImportException(__('Uvezeni privitak nije pronađen.'));
        }

        return $this->normalizeRow($row);
    }

    /**
     * EN: After node creation, binds staged private attachments to the real ACL target.
     *
     * HR: Nakon izrade čvora veže ranije spremljene privatne privitke uz stvarni ACL cilj.
     */
    public function attachImportedAttachmentsToPage(
        string $sourcePageId,
        int $workspaceId,
        int $nodeId,
        string $documentKey,
    ): void {
        if ($sourcePageId === '' || $workspaceId <= 0 || $nodeId <= 0 || $documentKey === '') {
            return;
        }

        $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
            ->where('source_page_id', '=', $sourcePageId)
            ->where('target_workspace_id', '=', $workspaceId)
            ->update([
                'target_node_id' => $nodeId,
                'target_document_key' => $documentKey,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
    }

    /**
     * EN: Returns staged attachments for one target page.
     *
     * HR: Vraća privremeno spremljene privitke jedne ciljne stranice.
     *
     * @return list<array<string,mixed>>
     */
    public function storedAttachmentsForPage(string $sourcePageId, int $workspaceId): array
    {
        if (trim($sourcePageId) === '' || $workspaceId <= 0) {
            return [];
        }

        $rows = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
            ->where('source_page_id', '=', trim($sourcePageId))
            ->where('target_workspace_id', '=', $workspaceId)
            ->where('status', '=', 'stored')
            ->orderBy('id', 'ASC')
            ->get();
        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = $this->normalizeRow($row);
            }
        }

        return $result;
    }

    /**
     * EN: Fetches stored and already registered attachments for all source-page versions in one query.
     *
     * HR: Jednim upitom vraća spremljene i već registrirane privitke svih izvornih verzija stranice.
     *
     * @param list<string> $sourcePageIds
     * @return list<array<string,mixed>>
     */
    public function importedAttachmentsForPages(array $sourcePageIds, int $workspaceId): array
    {
        $sourcePageIds = array_values(array_unique(array_filter(array_map(
            static fn(mixed $sourcePageId): string => is_scalar($sourcePageId)
                ? trim((string)$sourcePageId)
                : '',
            $sourcePageIds,
        ))));
        if ($sourcePageIds === [] || $workspaceId <= 0) {
            return [];
        }

        $rows = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
            ->whereIn('source_page_id', $sourcePageIds)
            ->where('target_workspace_id', '=', $workspaceId)
            ->whereIn('status', ['stored', 'registered'])
            ->orderBy('id', 'ASC')
            ->get();
        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = $this->normalizeRow($row);
            }
        }

        return $result;
    }

    /**
     * EN: Reads attachment outcomes without modifying an old report or imported content.
     *
     * HR: Čita ishode privitaka bez promjene starog izvještaja ili uvezenog sadržaja.
     *
     * @return array<string,mixed>
     */
    public function attachmentReport(int $jobId): array
    {
        $rows = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
            ->select(['source_attachment_id', 'logical_source_id', 'source_version', 'original_name', 'status', 'error_message'])
            ->where('job_id', '=', $jobId)
            ->orderBy('id', 'ASC')
            ->get();

        return ConfluenceAttachmentReport::summarize($rows);
    }

    /**
     * EN: Transfers file ownership to the Editor module after safe registration.
     *
     * HR: Nakon sigurnog prijenosa predaje vlasništvo nad datotekom Editor modulu.
     */
    public function markAttachmentRegistered(int $attachmentId, int $nodeId, string $documentKey): void
    {
        if ($attachmentId <= 0 || $nodeId <= 0 || trim($documentKey) === '') {
            return;
        }

        $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
            ->where('id', '=', $attachmentId)
            ->update([
                'storage_path' => null,
                'target_node_id' => $nodeId,
                'target_document_key' => trim($documentKey),
                'status' => 'registered',
                'error_message' => null,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
    }

    /**
     * EN: Hands several successfully copied attachments to Editor with one batched update.
     *
     * HR: Nakon uspješnog kopiranja jednim skupnim upisom predaje više privitaka Editoru.
     *
     * @param list<int> $attachmentIds
     */
    public function markAttachmentsRegistered(array $attachmentIds, int $nodeId, string $documentKey): void
    {
        $attachmentIds = array_values(array_unique(array_filter(
            $attachmentIds,
            static fn(mixed $id): bool => is_numeric($id) && (int)$id > 0,
        )));
        if ($attachmentIds === [] || $nodeId <= 0 || trim($documentKey) === '') {
            return;
        }

        foreach (array_chunk($attachmentIds, 500) as $ids) {
            $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
                ->whereIn('id', array_map(static fn(mixed $id): int => (int)$id, $ids))
                ->update([
                    'storage_path' => null,
                    'target_node_id' => $nodeId,
                    'target_document_key' => trim($documentKey),
                    'status' => 'registered',
                    'error_message' => null,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
        }
    }

    /**
     * EN: Returns a bounded list of recent jobs.
     *
     * HR: Vraća ograničeni popis najnovijih poslova.
     *
     * @return list<array<string,mixed>>
     */
    public function recentJobs(int $limit = 100): array
    {
        $rows = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
            // EN: The list never needs preflight JSON, which can span tens of MB.
            //
            // HR: Popis ne treba preflight JSON; velik posao može imati desetke MB.
            ->select(['id', 'uuid', 'original_name', 'source_space_name', 'source_space_key',
                'status', 'stage', 'archive_size', 'next_offset', 'chunk_size', 'created_at',
                'error_message', 'actor_user_id', 'workspace_id', 'options_json'])
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(100, $limit)))
            ->get();
        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = $this->normalizeRow($row);
            }
        }

        return $result;
    }

    /**
     * EN: Fetches an internal attachment record by numeric ID.
     *
     * HR: Dohvaća interni zapis privitka po brojčanom ID-u.
     *
     * @return array<string,mixed>
     */
    private function attachmentById(int $id): array
    {
        $row = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
            ->where('id', '=', $id)
            ->first();
        if (!is_array($row)) {
            throw new ConfluenceImportException(__('Zapis uvezenog privitka nije moguće učitati.'));
        }

        return $this->normalizeRow($row);
    }

    /**
     * EN: Requires the module's complete schema to be installed.
     *
     * HR: Zahtijeva instaliranu cjelovitu shemu modula.
     */
    private function assertReady(): void
    {
        if (!$this->tablesReady()) {
            throw new ConfluenceImportException(__('Confluence import migracija nije pokrenuta.'));
        }
    }

    /**
     * EN: Keeps string keys only and decodes known JSON fields.
     *
     * HR: Čuva samo string ključeve i dekodira poznata JSON polja.
     *
     * @param array<mixed,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeRow(array $row): array
    {
        $result = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        foreach (['options_json' => 'options', 'summary_json' => 'summary', 'source_metadata_json' => 'source_metadata'] as $jsonKey => $targetKey) {
            $decoded = is_scalar($result[$jsonKey] ?? null)
                ? json_decode((string)$result[$jsonKey], true)
                : null;
            if (is_array($decoded)) {
                $result[$targetKey] = $decoded;
            }
        }

        $prefix = (string)($this->siteContext['prefix'] ?? '');
        foreach (['source_space_id', 'source_space_key', 'destination_space_key'] as $key) {
            if ($prefix !== '' && is_string($result[$key] ?? null) && str_starts_with($result[$key], $prefix)) {
                $result[$key] = substr($result[$key], strlen($prefix));
            }
        }

        return $result;
    }

    /**
     * EN: Encodes portable JSON while preserving Unicode.
     *
     * HR: Kodira prenosivi JSON uz očuvanje Unicodea.
     *
     * @param array<string,mixed> $value
     */
    private function json(array $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * EN: Normalizes a required text value.
     *
     * HR: Normalizira obaveznu tekstualnu vrijednost.
     */
    private function string(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * EN: Normalizes empty text to null.
     *
     * HR: Normalizira prazni tekst u null.
     */
    private function nullableString(mixed $value): ?string
    {
        $value = $this->string($value);

        return $value !== '' ? $value : null;
    }

    /**
     * EN: Normalizes an integer value.
     *
     * HR: Normalizira cjelobrojnu vrijednost.
     */
    private function integer(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * EN: Keeps only a positive ID.
     *
     * HR: Zadržava samo pozitivan ID.
     */
    private function positiveOrNull(mixed $value): ?int
    {
        $value = $this->integer($value);

        return $value > 0 ? $value : null;
    }

    /**
     * EN: Generates a random UUIDv4 without an external library.
     *
     * HR: Generira nasumični UUIDv4 bez vanjske biblioteke.
     */
    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
