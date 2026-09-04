<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use AaiEduHr\SimbiozaModuleConfluenceImport\ModuleSimbiozaConfluenceImport;

use function array_chunk;
use function bin2hex;
use function gmdate;
use function is_array;
use function is_numeric;
use function is_scalar;
use function json_decode;
use function json_encode;
use function preg_match;
use function random_bytes;
use function strtolower;
use function trim;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/** HR: Jedina persistence granica za poslove i izvorna Confluence mapiranja. EN: Sole persistence boundary for jobs and source Confluence mappings. */
final readonly class ConfluenceImportRepository
{
    /** HR: Prima prenosivi ORM. EN: Receives the portable ORM. */
    public function __construct(private Database $database)
    {
    }

    /** HR: Provjerava je li instalirana cijela modulska shema. EN: Checks whether the complete module schema is installed. */
    public function tablesReady(): bool
    {
        $schema = $this->database->schema();

        return $schema->hasTable(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
            && $schema->hasTable(ModuleSimbiozaConfluenceImport::TABLE_SPACES)
            && $schema->hasTable(ModuleSimbiozaConfluenceImport::TABLE_CONTENT)
            && $schema->hasTable(ModuleSimbiozaConfluenceImport::TABLE_IDENTITIES)
            && $schema->hasTable(ModuleSimbiozaConfluenceImport::TABLE_GROUPS)
            && $schema->hasTable(ModuleSimbiozaConfluenceImport::TABLE_LINKS)
            && $schema->hasTable(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS);
    }

    /**
     * HR: Otvara novi posao nastavivog uploada.
     * EN: Opens a new resumable-upload job.
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
     * HR: Dohvaća posao prema UUID-u i po izboru ograničava vlasnika.
     * EN: Fetches a job by UUID and optionally restricts its owner.
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
     * HR: Zaključava upload posao i izvodi strogo sekvencijalnu izmjenu.
     * EN: Locks an upload job and runs a strictly sequential mutation.
     *
     * @param callable(array<string,mixed>,Database):array<string,mixed> $callback
     * @return array<string,mixed>
     */
    public function withLockedJob(string $uuid, int $actorUserId, callable $callback): array
    {
        return $this->database->transaction(function (Database $database) use ($uuid, $actorUserId, $callback): array {
            $row = $database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
                ->where('uuid', '=', trim($uuid))
                ->where('actor_user_id', '=', $actorUserId)
                ->lockForUpdate()
                ->first();
            if (!is_array($row)) {
                throw new ConfluenceImportException(__('Confluence import posao nije pronađen.'));
            }

            return $callback($this->normalizeRow($row), $database);
        });
    }

    /**
     * HR: Ažurira odabrana polja posla i vrijeme izmjene.
     * EN: Updates selected job fields and its modification time.
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

    /** HR: Ažurira javno čitljivu fazu dugotrajnog importa. EN: Updates the publicly readable stage of a long-running import. */
    public function setStage(int $jobId, string $stage): void
    {
        $this->updateJob($jobId, ['stage' => trim($stage)]);
    }

    /**
     * HR: Sprema rezultat preflight pregleda i otvara mapiranje.
     * EN: Stores the preflight inventory and opens mapping.
     *
     * @param array<string,mixed> $scan
     */
    public function saveScan(int $jobId, array $scan): void
    {
        $space = is_array($scan['spaces'][0] ?? null) ? $scan['spaces'][0] : [];
        $this->updateJob($jobId, [
            'status' => 'ready',
            'stage' => 'mapping',
            'source_space_key' => $this->string($space['source_key'] ?? ''),
            'source_space_name' => $this->string($space['name'] ?? ''),
            'source_space_type' => $this->string($space['type'] ?? 'global'),
            'summary_json' => $this->json($scan),
            'error_message' => null,
        ]);
    }

    /**
     * HR: Označava posao pokrenutim i sprema potvrđene opcije.
     * EN: Marks a job as running and stores confirmed options.
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
     * HR: Dovršava posao i uklanja privremenu izvornu putanju.
     * EN: Completes a job and removes its transient source path.
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
            'archive_path' => '',
            'expires_at' => null,
            'error_message' => null,
        ]);
    }

    /** HR: Bilježi pogrešku bez gubitka preflight sažetka. EN: Records an error without losing the preflight summary. */
    public function failImport(int $jobId, string $message): void
    {
        $this->updateJob($jobId, [
            'status' => 'failed',
            'stage' => 'failed',
            'error_message' => $message,
        ]);
    }

    /**
     * HR: Vraća istekle poslove prije početka stvarnog importa; dovršeni i
     *     djelomično izvedeni importi nikada nisu kandidati za automatsko brisanje.
     * EN: Returns jobs expired before a real import began; completed and
     *     partially executed imports are never automatic-cleanup candidates.
     *
     * @return list<array<string,mixed>>
     */
    public function expiredTransientJobs(string $now): array
    {
        $rows = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
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

    /** HR: Briše jedan dokazano privremeni posao. EN: Deletes one proven temporary job. */
    public function deleteTransientJob(int $jobId): void
    {
        $row = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
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
     * HR: Prepoznaje posao koji nije započeo sadržajni import. Neuspjela
     * početna provjera ostaje privremena dok nema spremljenih import opcija.
     * EN: Recognizes a job that has not started content import. A failed
     * preflight remains transient while no import options have been stored.
     *
     * @param array<mixed,mixed> $row
     */
    private function isTransientJobRow(array $row): bool
    {
        if (in_array($row['status'] ?? null, ['uploading', 'scanning', 'ready'], true)) {
            return true;
        }

        return ($row['status'] ?? null) === 'failed'
            && (!is_scalar($row['options_json'] ?? null) || trim((string)$row['options_json']) === '')
            && (!is_numeric($row['workspace_id'] ?? null) || (int)$row['workspace_id'] <= 0);
    }

    /**
     * HR: Sprema ili osvježava izvorno područje i ciljnu Workspace vezu.
     * EN: Saves or refreshes a source-space to target-Workspace mapping.
     *
     * @param array<string,mixed> $space
     * @param array<string,mixed> $workspace
     */
    public function mapSpace(array $space, array $workspace, int $jobId): void
    {
        $sourceId = $this->string($space['source_id'] ?? '');
        $existing = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_SPACES)
            ->where('source_instance', '=', 'archive')
            ->where('source_space_id', '=', $sourceId)
            ->first();
        $now = gmdate('Y-m-d H:i:s');
        $values = [
            'source_space_key' => $this->string($space['source_key'] ?? ''),
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
     * HR: Vraća raniji import istog izvornog Confluence područja.
     * EN: Returns an earlier import of the same source Confluence space.
     *
     * @return array<string,mixed>|null
     */
    public function spaceBySourceId(string $sourceId): ?array
    {
        $sourceId = trim($sourceId);
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
     * HR: Razrješava Confluence ključ područja u zadnje spremljeno lokalno mapiranje.
     * EN: Resolves a Confluence space key to the latest stored local mapping.
     *
     * @return array<string,mixed>|null
     */
    public function spaceBySourceKey(string $sourceKey): ?array
    {
        $sourceKey = trim($sourceKey);
        if ($sourceKey === '') {
            return null;
        }

        $row = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_SPACES)
            ->where('source_instance', '=', 'archive')
            ->where('source_space_key', '=', $sourceKey)
            ->orderBy('updated_at', 'DESC')
            ->first();

        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    /**
     * HR: Sprema izričito mapiranje izvornog korisnika.
     * EN: Stores an explicit source-user mapping.
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
            // HR: Ponovljeni import drugog područja ne smije poništiti ili preusmjeriti globalno mapiranje računa.
            // EN: A repeated import of another Workspace must not clear or redirect the global account mapping.
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

    /** HR: Vraća potvrđeni ciljni user ID ili null. EN: Returns a confirmed target user ID or null. */
    public function mappedUserId(string $sourceKey): ?int
    {
        if (trim($sourceKey) === '') {
            return null;
        }

        $row = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_IDENTITIES)
            ->where('source_user_key', '=', trim($sourceKey))
            ->where('mapping_status', '=', 'mapped')
            ->where('confirmed', '=', true)
            ->first();
        $id = is_array($row) ? $this->integer($row['target_user_id'] ?? 0) : 0;

        return $id > 0 ? $id : null;
    }

    /** HR: Sprema potvrđeno ili neriješeno mapiranje Confluence grupe. EN: Stores a confirmed or unresolved Confluence-group mapping. */
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

    /** HR: Vraća prethodno potvrđeno mapiranje ciljne grupe. EN: Returns a previously confirmed target-group mapping. */
    public function mappedGroupId(string $sourceName): ?int
    {
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
     * HR: Sprema vezu izvorne stranice i ciljnog čvora.
     * EN: Stores the mapping between a source page and target node.
     *
     * @param array<string,mixed> $page
     * @param array<string,mixed> $target
     */
    public function mapContent(array $page, array $target, int $jobId, string $status = 'imported'): void
    {
        $spaceKey = $this->string($target['source_space_key'] ?? '');
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
     * HR: Traži uvezenu stranicu po ključu područja i izvornom ID-u.
     * EN: Finds an imported page by space key and source ID.
     *
     * @return array<string,mixed>|null
     */
    public function contentBySource(string $spaceKey, string $sourceId): ?array
    {
        $row = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_CONTENT)
            ->where('source_space_key', '=', trim($spaceKey))
            ->where('logical_source_id', '=', trim($sourceId))
            ->where('import_status', '=', 'imported')
            ->orderBy('source_version', 'DESC')
            ->first();

        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    /**
     * HR: Vraća najnovije mapiranje stranice iz točno određenog importa.
     * EN: Returns the newest page mapping from one exact import.
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
     * HR: Ažurira samo trajni izvještaj već dovršenog importa.
     * EN: Updates only the durable report of an already completed import.
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
     * HR: Razrješava pageId URL kada izvorni URL ne sadrži ključ područja.
     * EN: Resolves a pageId URL when the source URL does not contain a space key.
     *
     * @return array<string,mixed>|null
     */
    public function contentByAnySourceId(string $sourceId): ?array
    {
        $sourceId = trim($sourceId);
        if ($sourceId === '') {
            return null;
        }

        $row = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_CONTENT)
            ->where('logical_source_id', '=', $sourceId)
            ->where('import_status', '=', 'imported')
            ->orderBy('source_version', 'DESC')
            ->first();

        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    /**
     * HR: Traži uvezenu stranicu po izvornom naslovu.
     * EN: Finds an imported page by its source title.
     *
     * @return array<string,mixed>|null
     */
    public function contentByTitle(string $spaceKey, string $title): ?array
    {
        $row = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_CONTENT)
            ->where('source_space_key', '=', trim($spaceKey))
            ->where('source_title', '=', trim($title))
            ->where('import_status', '=', 'imported')
            ->orderBy('source_version', 'DESC')
            ->first();

        return is_array($row) ? $this->normalizeRow($row) : null;
    }

    /**
     * HR: Čuva javne slugove stranica prije zamjenskog importa kako bi stare
     *     međupodručne poveznice nastavile voditi na isti URL.
     * EN: Preserves public page slugs before a replacement import so existing
     *     cross-Workspace links keep pointing at the same URL.
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
     * HR: Vraća izvorno mapiranje ciljnog područja.
     * EN: Returns the source mapping for a target Workspace.
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
     * HR: Sprema neriješenu ili riješenu izvornu poveznicu.
     * EN: Stores an unresolved or resolved source link.
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

    /** HR: Generira identitet poveznice prije skupnog spremanja. EN: Generates a link identity before bulk persistence. */
    public function newLinkUuid(): string
    {
        return $this->uuid();
    }

    /**
     * HR: Sprema više poveznica u ograničenim skupinama kako stranice s mnogo
     *     poveznica ne bi pokretale jedan SQL upit za svaku poveznicu.
     * EN: Stores multiple links in bounded batches so link-heavy pages do not
     *     execute one SQL statement per link.
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
                'source_space_key' => $this->string($link['source_space_key'] ?? ''),
                'destination_space_key' => $this->nullableString($link['destination_space_key'] ?? null),
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
     * HR: Dohvaća spremljenu poveznicu po UUID-u.
     * EN: Fetches a stored link by UUID.
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
     * HR: Vraća neriješene poveznice i sve poveznice prema upravo promijenjenom području.
     * EN: Returns unresolved links plus every link to the Workspace that just changed.
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
                    ->where('destination_space_key', '=', trim($destinationSpaceKey))
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

    /** HR: Sprema aktualno razrješenje poveznice ili je ponovno označava neriješenom. EN: Stores the current link resolution or marks it unresolved again. */
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
     * HR: Čuva UUID-e Editor privitaka prije zamjenskog importa kako bi svi
     *     postojeći linkovi na njih ostali valjani.
     * EN: Preserves Editor attachment UUIDs before a replacement import so all
     *     existing links to them remain valid.
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
     * HR: Sprema metapodatke privatnog uvezenog privitka.
     * EN: Stores metadata for a private imported attachment.
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
     * HR: Vraća već spremljenu verziju privitka kako ponovljeni import ne bi
     *     duplicirao veliku binarnu datoteku.
     * EN: Returns an already stored attachment version so a retried import does
     *     not duplicate a large binary file.
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
     * HR: Dohvaća dostupni privatni privitak po UUID-u.
     * EN: Fetches an available private attachment by UUID.
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

    /** HR: Nakon izrade čvora veže ranije spremljene privatne privitke uz stvarni ACL cilj. EN: After node creation, binds staged private attachments to the real ACL target. */
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
     * HR: Vraća privremeno spremljene privitke jedne ciljne stranice.
     * EN: Returns staged attachments for one target page.
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
     * HR: Nakon sigurnog prijenosa predaje vlasništvo nad datotekom Editor modulu.
     * EN: Transfers file ownership to the Editor module after safe registration.
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
     * HR: Vraća ograničeni popis najnovijih poslova.
     * EN: Returns a bounded list of recent jobs.
     *
     * @return list<array<string,mixed>>
     */
    public function recentJobs(int $limit = 20): array
    {
        $rows = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
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
     * HR: Dohvaća interni zapis privitka po brojčanom ID-u.
     * EN: Fetches an internal attachment record by numeric ID.
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

    /** HR: Zahtijeva instaliranu cjelovitu shemu modula. EN: Requires the module's complete schema to be installed. */
    private function assertReady(): void
    {
        if (!$this->tablesReady()) {
            throw new ConfluenceImportException(__('Confluence import migracija nije pokrenuta.'));
        }
    }

    /**
     * HR: Čuva samo string ključeve i dekodira poznata JSON polja.
     * EN: Keeps string keys only and decodes known JSON fields.
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

        return $result;
    }

    /**
     * HR: Kodira prenosivi JSON uz očuvanje Unicodea.
     * EN: Encodes portable JSON while preserving Unicode.
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

    /** HR: Normalizira obaveznu tekstualnu vrijednost. EN: Normalizes a required text value. */
    private function string(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /** HR: Normalizira prazni tekst u null. EN: Normalizes empty text to null. */
    private function nullableString(mixed $value): ?string
    {
        $value = $this->string($value);

        return $value !== '' ? $value : null;
    }

    /** HR: Normalizira cjelobrojnu vrijednost. EN: Normalizes an integer value. */
    private function integer(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }

    /** HR: Zadržava samo pozitivan ID. EN: Keeps only a positive ID. */
    private function positiveOrNull(mixed $value): ?int
    {
        $value = $this->integer($value);

        return $value > 0 ? $value : null;
    }

    /** HR: Generira nasumični UUIDv4 bez vanjske biblioteke. EN: Generates a random UUIDv4 without an external library. */
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
