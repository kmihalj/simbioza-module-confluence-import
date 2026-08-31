<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Backup;

use AaiEduHr\HeartPhrameModuleBackup\Contract\BackupCommitAwareProviderInterface;
use AaiEduHr\HeartPhrameModuleBackup\Contract\BackupProviderInterface;
use AaiEduHr\HeartPhrameModuleBackup\Exception\BackupException;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupArchiveReader;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupArchiveWriter;
use AaiEduHr\HeartPhrameModuleBackup\Service\BackupFilesystem;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupComponentGroup;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupExportContext;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupImportContext;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupPreflightResult;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupProviderMetadata;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupValue;
use AaiEduHr\HeartPhrameModuleEditorHtml\ModuleEditorHtml;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;
use AaiEduHr\SimbiozaModuleConfluenceImport\ModuleSimbiozaConfluenceImport;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportConfig;
use Throwable;

use function array_reverse;
use function basename;
use function bin2hex;
use function date;
use function dirname;
use function is_array;
use function is_file;
use function is_numeric;
use function is_scalar;
use function random_bytes;
use function rawurlencode;
use function rename;
use function rtrim;
use function str_replace;
use function trim;

/**
 * HR: Prenosi Confluence izvorna mapiranja i privatne privitke koji pripadaju
 *     samo jednom području. Provider radi nakon dokumenta i stabla kako bi
 *     brojčane identitete ponovno povezao preko prenosivih ključeva i UUID-ova.
 *
 * EN: Transfers Confluence provenance mappings and private attachments that
 *     belong to one Workspace only. The provider runs after documents and the
 *     tree so numeric identities can be reconnected through portable keys and UUIDs.
 */
final class ConfluenceImportWorkspaceBackupProvider implements
    BackupProviderInterface,
    BackupCommitAwareProviderInterface
{
    private const ID = 'simbioza-confluence-import-workspace';

    /** @var list<array{stage:string,target:string}> */
    private array $pendingFiles = [];

    /** @var list<array{target:string,rollback:string|null}> */
    private array $publishedFiles = [];

    /** @var list<string> */
    private array $obsoleteFiles = [];

    /** @var list<array{target:string,rollback:string}> */
    private array $removedFiles = [];

    /**
     * HR: Prima bazu te ograničene privatne direktorije Backup i import modula.
     * EN: Receives the database and scoped private directories of Backup and the import module.
     */
    public function __construct(
        private readonly Database $database,
        private readonly ConfluenceImportConfig $config,
        private readonly BackupFilesystem $filesystem,
    ) {
    }

    /** HR: Opisuje Confluence podatke jednog područja. EN: Describes one Workspace's Confluence data. */
    public function metadata(): BackupProviderMetadata
    {
        return new BackupProviderMetadata(
            self::ID,
            ModuleSimbiozaConfluenceImport::PACKAGE_NAME,
            1,
            ['hr' => 'Confluence podaci i privitci područja', 'en' => 'Workspace Confluence data and attachments'],
            ['workspace-scope'],
            [BackupScope::WORKSPACE],
            true,
            true,
            [ModuleWorkspace::PACKAGE_NAME],
            componentGroups: [BackupComponentGroup::WORKSPACES],
        );
    }

    /**
     * HR: Izvozi samo dovršene poslove, mapiranja i privatne datoteke odabranog područja.
     * EN: Exports completed jobs, mappings, and private files for the selected Workspace only.
     */
    public function export(BackupExportContext $context, BackupArchiveWriter $writer): void
    {
        $workspace = $this->workspace((string)$context->scope->identifier);
        $workspaceId = BackupValue::integer($workspace['id'], 'workspace.id');
        $writer->writeRecord(self::ID, 'scope', [
            'source_workspace_id' => $workspaceId,
            'workspace_slug' => BackupValue::string($workspace['slug'], 'workspace.slug'),
        ]);

        $nodes = $this->nodes($workspaceId);
        $jobs = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
            ->where('workspace_id', '=', $workspaceId)
            ->where('status', '=', 'completed')
            ->orderBy('id')
            ->get();
        $jobIds = [];
        foreach ($jobs as $job) {
            $jobId = BackupValue::integer($job['id'], 'job.id');
            $jobIds[] = $jobId;
            $writer->writeRecord(self::ID, 'jobs', [
                'source_id' => $jobId,
                'uuid' => BackupValue::string($job['uuid'], 'job.uuid'),
                'operation' => $job['operation'] ?? 'import',
                'status' => 'completed',
                'stage' => $job['stage'] ?? 'completed',
                'original_name' => $job['original_name'] ?? 'confluence-export.xml.zip',
                'archive_size' => $job['archive_size'] ?? 0,
                'sha256' => $job['sha256'] ?? null,
                'source_space_key' => $job['source_space_key'] ?? null,
                'source_space_name' => $job['source_space_name'] ?? null,
                'source_space_type' => $job['source_space_type'] ?? null,
                'options_json' => $job['options_json'] ?? null,
                'summary_json' => $job['summary_json'] ?? null,
                'created_at' => $job['created_at'] ?? null,
                'updated_at' => $job['updated_at'] ?? null,
            ]);
        }

        if ($jobIds === []) {
            return;
        }

        $this->exportSpaces($workspaceId, $jobIds, $writer);
        $this->exportContent($workspaceId, $jobIds, $nodes, $writer);
        $this->exportLinks($jobIds, $writer);
        $this->exportAttachments($workspaceId, $jobIds, $nodes, $writer);
    }

    /** HR: Provjerava prenosive reference i svaki privatni blob. EN: Validates portable references and every private blob. */
    public function preflight(BackupImportContext $context, BackupArchiveReader $reader): BackupPreflightResult
    {
        $scope = $this->singleRecord($reader, 'scope');
        if ($scope === null) {
            return new BackupPreflightResult(['Confluence Workspace backup is missing its scope record.']);
        }

        $errors = [];
        $attachments = 0;
        foreach ($reader->records(self::ID, 'attachments') as $attachment) {
            ++$attachments;
            $nodeUuid = trim(BackupValue::string($attachment['node_uuid'] ?? '', 'attachment.node_uuid'));
            $documentKey = trim(BackupValue::string(
                $attachment['target_document_key'] ?? '',
                'attachment.target_document_key',
            ));
            if ($nodeUuid === '' || $documentKey === '') {
                $errors[] = 'A Confluence attachment is missing its portable page reference (node UUID or document key).';
            }

            $blob = is_array($attachment['blob'] ?? null) ? $attachment['blob'] : null;
            if (($attachment['status'] ?? '') === 'stored' && !is_string($blob['sha256'] ?? null)) {
                $errors[] = 'A stored Confluence attachment is missing its binary blob.';
                continue;
            }

            if (is_string($blob['sha256'] ?? null)) {
                try {
                    $reader->blobPath($blob['sha256']);
                } catch (Throwable $throwable) {
                    $errors[] = $throwable->getMessage();
                }
            }
        }

        return new BackupPreflightResult(
            array_values(array_unique($errors)),
            $context->conflictMode === BackupImportContext::CONFLICT_COPY
                ? ['Copied Confluence attachment UUIDs and source identities will be regenerated.']
                : [],
            ['attachments' => $attachments],
        );
    }

    /**
     * HR: Za replace uklanja samo module-owned zapise ciljnog područja, dok se
     *     stare datoteke čuvaju do potvrđenog DB commita.
     * EN: For replace, removes only module-owned target Workspace records while
     *     preserving old files until the database commit is confirmed.
     */
    public function prepareImport(BackupImportContext $context, BackupArchiveReader $reader): void
    {
        if ($context->conflictMode !== BackupImportContext::CONFLICT_REPLACE) {
            return;
        }

        $workspace = $this->workspace((string)$context->scope->identifier, false);
        if ($workspace === null) {
            return;
        }

        $workspaceId = BackupValue::integer($workspace['id'], 'workspace.id');
        $jobRows = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
            ->select(['id'])
            ->where('workspace_id', '=', $workspaceId)
            ->get();
        $jobIds = array_map(
            static fn(array $row): int => BackupValue::integer($row['id'], 'job.id'),
            $jobRows,
        );
        foreach (
            $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
            ->where('target_workspace_id', '=', $workspaceId)->get() as $attachment
        ) {
            $this->rememberObsoleteFile($attachment['storage_path'] ?? null);
        }

        $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
            ->where('target_workspace_id', '=', $workspaceId)
            ->delete();
        $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_CONTENT)
            ->where('target_workspace_id', '=', $workspaceId)
            ->delete();
        $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_SPACES)
            ->where('target_workspace_id', '=', $workspaceId)
            ->delete();
        if ($jobIds !== []) {
            $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_LINKS)->whereIn('job_id', $jobIds)->delete();
            $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_IDENTITIES)
                ->whereIn('job_id', $jobIds)->delete();
            $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_GROUPS)->whereIn('job_id', $jobIds)->delete();
            $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)->whereIn('id', $jobIds)->delete();
        }
    }

    /** HR: Vraća Confluence zapise i priprema privatne datoteke za atomsku objavu. EN: Restores Confluence records and stages private files for atomic publication. */
    public function import(BackupImportContext $context, BackupArchiveReader $reader): void
    {
        $scope = $this->singleRecord($reader, 'scope');
        if ($scope === null) {
            throw new BackupException('Confluence Workspace scope record is missing.');
        }

        $sourceWorkspaceId = BackupValue::integer($scope['source_workspace_id'], 'scope.source_workspace_id');
        $workspaceId = BackupValue::integer(
            $context->state->require('workspace.id', $sourceWorkspaceId),
            'state.workspace.id',
        );
        $sourceSlug = BackupValue::string($scope['workspace_slug'], 'scope.workspace_slug');
        $targetSlug = BackupValue::string(
            $context->state->require('workspace.slug', $sourceSlug),
            'state.workspace.slug',
        );
        $copySuffix = $context->conflictMode === BackupImportContext::CONFLICT_COPY
            ? '@simbioza-copy-' . $workspaceId
            : '';

        foreach ($reader->records(self::ID, 'jobs') as $row) {
            $sourceId = BackupValue::integer($row['source_id'], 'job.source_id');
            $uuid = $context->conflictMode === BackupImportContext::CONFLICT_COPY
                ? $this->uuid()
                : BackupValue::string($row['uuid'], 'job.uuid');
            $values = [
                'uuid' => $uuid,
                'operation' => $row['operation'] ?? 'restore',
                'status' => 'completed',
                'stage' => 'completed',
                'original_name' => $row['original_name'] ?? 'confluence-workspace-backup.zip',
                'archive_path' => '',
                'archive_size' => $row['archive_size'] ?? 0,
                'next_offset' => $row['archive_size'] ?? 0,
                'chunk_size' => 0,
                'sha256' => $row['sha256'] ?? null,
                'source_space_key' => $this->withSuffix($row['source_space_key'] ?? null, $copySuffix),
                'source_space_name' => $row['source_space_name'] ?? null,
                'source_space_type' => $row['source_space_type'] ?? null,
                'options_json' => $row['options_json'] ?? null,
                'summary_json' => $row['summary_json'] ?? null,
                'error_message' => null,
                'workspace_id' => $workspaceId,
                'actor_user_id' => $context->actorUserId,
                'expires_at' => null,
                'created_at' => $row['created_at'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $row['updated_at'] ?? date('Y-m-d H:i:s'),
            ];
            $jobId = $this->upsertAndId(
                ModuleSimbiozaConfluenceImport::TABLE_JOBS,
                ['uuid' => $uuid],
                $values,
                'job',
            );
            $context->state->map('simbioza-confluence-import.workspace-job', $sourceId, $jobId);
        }

        $this->importSpaces($context, $reader, $workspaceId, $targetSlug, $copySuffix);
        $this->importContent($context, $reader, $workspaceId, $targetSlug, $copySuffix);
        $attachmentMap = $this->importAttachments($context, $reader, $workspaceId, $copySuffix);
        $this->importLinks($context, $reader, $copySuffix);
        $this->rewriteDocuments($context, $reader, $sourceSlug, $targetSlug, $attachmentMap);
    }

    /** HR: Atomski objavljuje nove i sklanja zamijenjene privatne datoteke. EN: Atomically publishes new and retires replaced private files. */
    public function finalizeImport(BackupImportContext $context, BackupArchiveReader $reader): void
    {
        $removed = [];
        $published = [];
        try {
            foreach (array_values(array_unique($this->obsoleteFiles)) as $target) {
                if (!is_file($target)) {
                    continue;
                }
                $rollback = dirname($target) . '/.backup-rollback-confluence-' . bin2hex(random_bytes(8));
                if (!rename($target, $rollback)) {
                    throw new BackupException('Unable to preserve a replaced Confluence attachment.');
                }
                $removed[] = ['target' => $target, 'rollback' => $rollback];
            }

            foreach ($this->pendingFiles as $file) {
                $this->filesystem->ensureDirectory(dirname($file['target']));
                $rollback = null;
                if (is_file($file['target'])) {
                    $rollback = dirname($file['target']) . '/.backup-rollback-confluence-'
                        . bin2hex(random_bytes(8));
                    if (!rename($file['target'], $rollback)) {
                        throw new BackupException('Unable to preserve an existing Confluence attachment.');
                    }
                }
                if (!rename($file['stage'], $file['target'])) {
                    if ($rollback !== null && is_file($rollback)) {
                        @rename($rollback, $file['target']);
                    }
                    throw new BackupException('Unable to publish a restored Confluence attachment.');
                }
                $published[] = ['target' => $file['target'], 'rollback' => $rollback];
            }
        } catch (Throwable $throwable) {
            $this->restoreFiles($published, $removed);
            throw $throwable;
        }

        $this->publishedFiles = $published;
        $this->removedFiles = $removed;
    }

    /** HR: Vraća datoteke nakon neuspjelog DB importa. EN: Restores files after a failed database import. */
    public function abortImport(BackupImportContext $context): void
    {
        $this->restoreFiles($this->publishedFiles, $this->removedFiles);
        foreach ($this->pendingFiles as $file) {
            if (is_file($file['stage'])) {
                @unlink($file['stage']);
            }
        }
        $this->resetFileState();
    }

    /** HR: Nakon DB commita briše rollback kopije. EN: Deletes rollback copies after the database commit. */
    public function completeImport(BackupImportContext $context): void
    {
        foreach ([...$this->publishedFiles, ...$this->removedFiles] as $file) {
            if (is_string($file['rollback']) && is_file($file['rollback'])) {
                @unlink($file['rollback']);
            }
        }
        $this->resetFileState();
    }

    /**
     * HR: Izvozi izvorno mapiranje područja bez lokalnih brojčanih identiteta.
     * EN: Exports the source Workspace mapping without local numeric identities.
     *
     * @param list<int> $jobIds
     */
    private function exportSpaces(int $workspaceId, array $jobIds, BackupArchiveWriter $writer): void
    {
        foreach (
            $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_SPACES)
            ->where('target_workspace_id', '=', $workspaceId)->whereIn('job_id', $jobIds)->orderBy('id')->get() as $row
        ) {
            unset($row['id'], $row['target_workspace_id'], $row['target_workspace_slug']);
            $row['source_job_id'] = $row['job_id'];
            unset($row['job_id']);
            $writer->writeRecord(self::ID, 'spaces', $row);
        }
    }

    /**
     * HR: Izvozi mapiranja stranica odabranog područja.
     * EN: Exports page mappings for the selected Workspace.
     *
     * @param list<int> $jobIds
     * @param array<int,array<string,mixed>> $nodes
     */
    private function exportContent(
        int $workspaceId,
        array $jobIds,
        array $nodes,
        BackupArchiveWriter $writer,
    ): void {
        foreach (
            $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_CONTENT)
            ->where('target_workspace_id', '=', $workspaceId)->whereIn('job_id', $jobIds)->orderBy('id')->get() as $row
        ) {
            $nodeId = is_numeric($row['target_node_id'] ?? null) ? (int)$row['target_node_id'] : 0;
            unset($row['id'], $row['target_workspace_id'], $row['target_node_id'], $row['target_slug']);
            $row['source_job_id'] = $row['job_id'];
            $row['node_uuid'] = $nodes[$nodeId]['uuid'] ?? null;
            unset($row['job_id']);
            $writer->writeRecord(self::ID, 'content', $row);
        }
    }

    /**
     * HR: Izvozi riješene i neriješene poveznice poslova odabranog područja.
     * EN: Exports resolved and unresolved links for the selected Workspace jobs.
     *
     * @param list<int> $jobIds
     */
    private function exportLinks(array $jobIds, BackupArchiveWriter $writer): void
    {
        foreach (
            $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_LINKS)
            ->whereIn('job_id', $jobIds)->orderBy('id')->get() as $row
        ) {
            unset($row['id']);
            $row['source_job_id'] = $row['job_id'];
            unset($row['job_id']);
            $writer->writeRecord(self::ID, 'links', $row);
        }
    }

    /**
     * HR: Izvozi metapodatke i binarne privatne privitke područja.
     * EN: Exports Workspace private attachment metadata and binaries.
     *
     * @param list<int> $jobIds
     * @param array<int,array<string,mixed>> $nodes
     */
    private function exportAttachments(
        int $workspaceId,
        array $jobIds,
        array $nodes,
        BackupArchiveWriter $writer,
    ): void {
        foreach (
            $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
            ->where('target_workspace_id', '=', $workspaceId)->whereIn('job_id', $jobIds)->orderBy('id')->get() as $row
        ) {
            $nodeId = is_numeric($row['target_node_id'] ?? null) ? (int)$row['target_node_id'] : 0;
            $blob = null;
            $path = is_scalar($row['storage_path'] ?? null) ? (string)$row['storage_path'] : '';
            if (($row['status'] ?? '') === 'stored') {
                if (!$this->isPrivateAttachment($path) || !is_file($path)) {
                    throw new BackupException('Stored Confluence attachment bytes are unavailable.');
                }
                $blob = $writer->addBlobFile(
                    $path,
                    is_scalar($row['mime_type'] ?? null) ? (string)$row['mime_type'] : null,
                    is_scalar($row['original_name'] ?? null) ? (string)$row['original_name'] : null,
                );
            }
            unset($row['id'], $row['target_workspace_id'], $row['target_node_id'], $row['storage_path']);
            $row['source_job_id'] = $row['job_id'];
            $row['node_uuid'] = $nodes[$nodeId]['uuid'] ?? null;
            $row['blob'] = $blob;
            unset($row['job_id']);
            $writer->writeRecord(self::ID, 'attachments', $row);
        }
    }

    /** HR: Vraća izvorno mapiranje područja na novi cilj. EN: Restores the source Workspace mapping onto the new target. */
    private function importSpaces(
        BackupImportContext $context,
        BackupArchiveReader $reader,
        int $workspaceId,
        string $targetSlug,
        string $copySuffix,
    ): void {
        foreach ($reader->records(self::ID, 'spaces') as $row) {
            $jobId = $this->jobId($context, $row['source_job_id'] ?? null);
            $sourceInstance = $this->withSuffix($row['source_instance'] ?? 'archive', $copySuffix);
            $sourceSpaceId = $this->withSuffix($row['source_space_id'] ?? '', $copySuffix);
            $values = $row;
            unset($values['source_job_id']);
            $values['source_instance'] = $sourceInstance;
            $values['source_space_id'] = $sourceSpaceId;
            $values['source_space_key'] = $this->withSuffix($row['source_space_key'] ?? '', $copySuffix);
            $values['target_workspace_id'] = $workspaceId;
            $values['target_workspace_slug'] = $targetSlug;
            $values['job_id'] = $jobId;
            $this->upsertAndId(
                ModuleSimbiozaConfluenceImport::TABLE_SPACES,
                ['source_instance' => $sourceInstance, 'source_space_id' => $sourceSpaceId],
                $values,
                'space',
            );
        }
    }

    /** HR: Vraća mapiranja Confluence sadržaja na obnovljene stranice. EN: Restores Confluence content mappings onto restored pages. */
    private function importContent(
        BackupImportContext $context,
        BackupArchiveReader $reader,
        int $workspaceId,
        string $targetSlug,
        string $copySuffix,
    ): void {
        foreach ($reader->records(self::ID, 'content') as $row) {
            $sourceKey = BackupValue::string($row['target_document_key'] ?? '', 'content.target_document_key');
            $nodeUuid = BackupValue::string($row['node_uuid'] ?? '', 'content.node_uuid');
            $spaceKey = $this->withSuffix($row['source_space_key'] ?? '', $copySuffix);
            $contentId = $this->withSuffix($row['source_content_id'] ?? '', $copySuffix);
            $values = $row;
            unset($values['source_job_id'], $values['node_uuid']);
            $values['source_space_key'] = $spaceKey;
            $values['source_content_id'] = $contentId;
            $values['logical_source_id'] = $this->withSuffix($row['logical_source_id'] ?? $contentId, $copySuffix);
            $values['target_workspace_id'] = $workspaceId;
            $values['target_node_id'] = $this->mappedInteger($context, 'workspace.node-id', $nodeUuid);
            $values['target_document_key'] = BackupValue::string(
                $context->state->require('editor.document-key', $sourceKey),
                'state.editor.document-key',
            );
            $node = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
                ->where('id', '=', $values['target_node_id'])->first();
            $values['target_slug'] = is_array($node) ? ($node['slug'] ?? $targetSlug) : $targetSlug;
            $values['job_id'] = $this->jobId($context, $row['source_job_id'] ?? null);
            $this->upsertAndId(
                ModuleSimbiozaConfluenceImport::TABLE_CONTENT,
                ['source_space_key' => $spaceKey, 'source_content_id' => $contentId],
                $values,
                'content',
            );
        }
    }

    /**
     * HR: Vraća privatne privitke i mapu starih i novih UUID-ova.
     * EN: Restores private attachments and returns the old-to-new UUID map.
     *
     * @return array<string,string>
     */
    private function importAttachments(
        BackupImportContext $context,
        BackupArchiveReader $reader,
        int $workspaceId,
        string $copySuffix,
    ): array {
        $uuidMap = [];
        foreach ($reader->records(self::ID, 'attachments') as $row) {
            $sourceUuid = BackupValue::string($row['uuid'], 'attachment.uuid');
            $targetUuid = $context->conflictMode === BackupImportContext::CONFLICT_COPY
                ? $this->uuid()
                : $sourceUuid;
            $sourceKey = BackupValue::string($row['target_document_key'] ?? '', 'attachment.target_document_key');
            $nodeUuid = BackupValue::string($row['node_uuid'] ?? '', 'attachment.node_uuid');
            $blob = is_array($row['blob'] ?? null) ? $row['blob'] : null;
            $targetPath = null;
            if (is_string($blob['sha256'] ?? null)) {
                $targetPath = rtrim($this->config->attachmentDirectory(), DIRECTORY_SEPARATOR)
                    . DIRECTORY_SEPARATOR . bin2hex(random_bytes(24)) . '.bin';
                $stage = dirname($targetPath) . '/.backup-stage-confluence-' . bin2hex(random_bytes(12));
                $this->filesystem->copy($reader->blobPath($blob['sha256']), $stage);
                $this->pendingFiles[] = ['stage' => $stage, 'target' => $targetPath];
            }

            $values = $row;
            unset($values['source_job_id'], $values['node_uuid'], $values['blob']);
            $values['uuid'] = $targetUuid;
            $values['source_attachment_id'] = $this->withSuffix(
                $row['source_attachment_id'] ?? '',
                $copySuffix,
            );
            $values['logical_source_id'] = $this->withSuffix(
                $row['logical_source_id'] ?? $row['source_attachment_id'] ?? '',
                $copySuffix,
            );
            $values['target_workspace_id'] = $workspaceId;
            $values['target_node_id'] = $this->mappedInteger($context, 'workspace.node-id', $nodeUuid);
            $values['target_document_key'] = BackupValue::string(
                $context->state->require('editor.document-key', $sourceKey),
                'state.editor.document-key',
            );
            $values['storage_path'] = $targetPath;
            $values['job_id'] = $this->jobId($context, $row['source_job_id'] ?? null);
            $existing = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
                ->where('uuid', '=', $targetUuid)->first();
            if (is_array($existing)) {
                $this->rememberObsoleteFile($existing['storage_path'] ?? null);
            }
            $this->upsertAndId(
                ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS,
                ['uuid' => $targetUuid],
                $values,
                'attachment',
            );
            $uuidMap[$sourceUuid] = $targetUuid;
        }
        return $uuidMap;
    }

    /** HR: Vraća trajne zapise unutarnjih i međupodručnih poveznica. EN: Restores durable internal and cross-Workspace link records. */
    private function importLinks(
        BackupImportContext $context,
        BackupArchiveReader $reader,
        string $copySuffix,
    ): void {
        foreach ($reader->records(self::ID, 'links') as $row) {
            $uuid = $context->conflictMode === BackupImportContext::CONFLICT_COPY
                ? $this->uuid()
                : BackupValue::string($row['uuid'], 'link.uuid');
            $values = $row;
            unset($values['source_job_id']);
            $values['uuid'] = $uuid;
            $values['job_id'] = $this->jobId($context, $row['source_job_id'] ?? null);
            $values['source_space_key'] = $this->withSuffix($row['source_space_key'] ?? '', $copySuffix);
            $this->upsertAndId(
                ModuleSimbiozaConfluenceImport::TABLE_LINKS,
                ['uuid' => $uuid],
                $values,
                'link',
            );
        }
    }

    /**
     * HR: U kopiji područja prepisuje slug područja i UUID-ove privatnih privitaka.
     * EN: Rewrites the Workspace slug and private attachment UUIDs in a Workspace copy.
     *
     * @param array<string,string> $attachmentMap
     */
    private function rewriteDocuments(
        BackupImportContext $context,
        BackupArchiveReader $reader,
        string $sourceSlug,
        string $targetSlug,
        array $attachmentMap,
    ): void {
        $documentIds = [];
        foreach ($reader->records(self::ID, 'content') as $row) {
            $sourceKey = BackupValue::string($row['target_document_key'] ?? '', 'content.target_document_key');
            $documentIds[] = $this->mappedInteger($context, 'editor.document-id-by-key', $sourceKey);
        }
        foreach ($reader->records(self::ID, 'attachments') as $row) {
            $sourceKey = BackupValue::string($row['target_document_key'] ?? '', 'attachment.target_document_key');
            $documentIds[] = $this->mappedInteger($context, 'editor.document-id-by-key', $sourceKey);
        }
        $documentIds = array_values(array_unique($documentIds));
        if ($documentIds === []) {
            return;
        }

        $search = [];
        $replace = [];
        foreach ($attachmentMap as $sourceUuid => $targetUuid) {
            if ($sourceUuid === $targetUuid) {
                continue;
            }
            $search[] = rawurlencode($sourceUuid);
            $replace[] = rawurlencode($targetUuid);
        }
        if ($sourceSlug !== $targetSlug) {
            $search[] = '/workspace/' . rawurlencode($sourceSlug) . '/';
            $replace[] = '/workspace/' . rawurlencode($targetSlug) . '/';
        }
        if ($search === []) {
            return;
        }

        foreach (
            $this->database->table(ModuleEditorHtml::TABLE_DOCUMENT_VERSIONS)
            ->whereIn('document_id', $documentIds)->get() as $version
        ) {
            $html = is_scalar($version['content_html'] ?? null) ? (string)$version['content_html'] : '';
            $rewritten = str_replace($search, $replace, $html);
            if ($rewritten !== $html) {
                $this->database->table(ModuleEditorHtml::TABLE_DOCUMENT_VERSIONS)
                    ->where('id', '=', BackupValue::integer($version['id'], 'version.id'))
                    ->update(['content_html' => $rewritten]);
            }
        }
    }

    /**
     * HR: Učitava stranice područja jednom kako bi izvoz izbjegao N+1 upite.
     * EN: Loads Workspace pages once so the export avoids N+1 queries.
     *
     * @return array<int,array<string,mixed>>
     */
    private function nodes(int $workspaceId): array
    {
        $nodes = [];
        foreach (
            $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
            ->where('workspace_id', '=', $workspaceId)->get() as $node
        ) {
            $nodes[BackupValue::integer($node['id'], 'node.id')] = $node;
        }
        return $nodes;
    }

    /**
     * HR: Čita prvi zapis skupa koji po ugovoru smije sadržavati samo jedan redak.
     * EN: Reads the first record from a dataset that is contractually singular.
     *
     * @return array<string,mixed>|null
     */
    private function singleRecord(BackupArchiveReader $reader, string $dataset): ?array
    {
        foreach ($reader->records(self::ID, $dataset) as $record) {
            return $record;
        }
        return null;
    }

    /**
     * HR: Dohvaća područje prema stabilnom slugu ili UUID-u.
     * EN: Finds a Workspace by stable slug or UUID.
     *
     * @return array<string,mixed>|null
     */
    private function workspace(string $identifier, bool $required = true): ?array
    {
        $identifier = trim($identifier);
        $row = $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)
            ->where('slug', '=', $identifier)->first()
            ?? $this->database->table(ModuleWorkspace::TABLE_WORKSPACES)->where('uuid', '=', $identifier)->first();
        if (!is_array($row) && $required) {
            throw new BackupException('Workspace does not exist: ' . $identifier);
        }
        return is_array($row) ? $row : null;
    }

    /**
     * HR: Umeće ili osvježava zapis prema stabilnom poslovnom ključu.
     * EN: Inserts or updates a record by its stable business key.
     *
     * @param array<string,mixed> $match
     * @param array<string,mixed> $values
     */
    private function upsertAndId(string $table, array $match, array $values, string $label): int
    {
        $query = $this->database->table($table);
        foreach ($match as $column => $value) {
            $query->where($column, '=', $value);
        }
        $existing = $query->first();
        if (is_array($existing)) {
            $id = BackupValue::integer($existing['id'], $label . '.id');
            $this->database->table($table)->where('id', '=', $id)->update($values);
            return $id;
        }
        $this->database->table($table)->insert($values);
        $created = $this->database->table($table);
        foreach ($match as $column => $value) {
            $created->where($column, '=', $value);
        }
        $row = $created->select(['id'])->first();
        if (!is_array($row)) {
            throw new BackupException('Unable to resolve imported Confluence ' . $label . '.');
        }
        return BackupValue::integer($row['id'], $label . '.id');
    }

    /** HR: Razrješava novi brojčani identitet import posla. EN: Resolves the imported job's new numeric identity. */
    private function jobId(BackupImportContext $context, mixed $source): int
    {
        return $this->mappedInteger(
            $context,
            'simbioza-confluence-import.workspace-job',
            BackupValue::integer($source, 'record.source_job_id'),
        );
    }

    /** HR: Zahtijeva cjelobrojno mapiranje prenosivog identiteta. EN: Requires an integer mapping for a portable identity. */
    private function mappedInteger(BackupImportContext $context, string $namespace, int|string $source): int
    {
        return BackupValue::integer($context->state->require($namespace, $source), 'state.' . $namespace);
    }

    /** HR: Kopiji dodaje izolacijski sufiks izvornog identiteta. EN: Adds a source-identity isolation suffix to a copy. */
    private function withSuffix(mixed $value, string $suffix): string
    {
        return (is_scalar($value) ? (string)$value : '') . $suffix;
    }

    /** HR: Pamti samo postojeću datoteku unutar privatnog korijena modula. EN: Remembers only an existing file inside the module's private root. */
    private function rememberObsoleteFile(mixed $path): void
    {
        if (is_scalar($path) && $this->isPrivateAttachment((string)$path) && is_file((string)$path)) {
            $this->obsoleteFiles[] = (string)$path;
        }
    }

    /** HR: Provjerava pripada li putanja privatnom direktoriju privitaka. EN: Checks whether a path belongs to the private attachment directory. */
    private function isPrivateAttachment(string $path): bool
    {
        $root = rtrim($this->config->attachmentDirectory(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return $path !== '' && str_starts_with($path, $root);
    }

    /**
     * HR: Uklanja objavljene datoteke i vraća njihove rollback kopije.
     * EN: Removes published files and restores their rollback copies.
     *
     * @param list<array{target:string,rollback:string|null}> $published
     * @param list<array{target:string,rollback:string}> $removed
     */
    private function restoreFiles(array $published, array $removed): void
    {
        foreach (array_reverse($published) as $file) {
            if (is_file($file['target'])) {
                @unlink($file['target']);
            }
            if ($file['rollback'] !== null && is_file($file['rollback'])) {
                @rename($file['rollback'], $file['target']);
            }
        }
        foreach (array_reverse($removed) as $file) {
            if (is_file($file['rollback'])) {
                @rename($file['rollback'], $file['target']);
            }
        }
    }

    /** HR: Čisti kratkotrajno stanje nakon potvrde ili rollbacka. EN: Clears transient state after commit or rollback. */
    private function resetFileState(): void
    {
        $this->pendingFiles = [];
        $this->publishedFiles = [];
        $this->obsoleteFiles = [];
        $this->removedFiles = [];
    }

    /** HR: Stvara RFC 4122 UUID v4 za izoliranu kopiju. EN: Creates an RFC 4122 UUID v4 for an isolated copy. */
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
