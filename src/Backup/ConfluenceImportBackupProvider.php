<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Backup;

use AaiEduHr\HeartPhrameModuleBackup\Service\DatabaseTableBackupProvider;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupExportContext;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupImportContext;
use AaiEduHr\HeartPhrameModuleBackup\Value\BackupProviderMetadata;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleConfluenceImport\ModuleSimbiozaConfluenceImport;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportConfig;

use function basename;

/**
 * HR: Prenosi samo trajna mapiranja dovršenih importa i uklanja privremene upload putanje.
 * EN: Transfers only durable mappings from completed imports and removes temporary upload paths.
 */
final class ConfluenceImportBackupProvider extends DatabaseTableBackupProvider
{
    /**
     * HR: Prima privatni korijen kako apsolutna putanja nikada ne bi prešla u prenosivi backup.
     * EN: Receives the private root so an absolute path never leaks into a portable backup.
     *
     * @param list<array<string,mixed>> $definitions
     */
    public function __construct(
        Database $database,
        BackupProviderMetadata $providerMetadata,
        array $definitions,
        private readonly ConfluenceImportConfig $config,
    ) {
        parent::__construct($database, $providerMetadata, $definitions);
    }

    /**
     * HR: Ne izvozi nedovršene poslove ni njihove djelomične metapodatke.
     * EN: Does not export unfinished jobs or their partial metadata.
     *
     * @param array<string,mixed> $definition
     * @return iterable<array<string,mixed>>
     */
    protected function exportRows(array $definition, BackupExportContext $context): iterable
    {
        $table = (string)($definition['table'] ?? '');
        if ($table === ModuleSimbiozaConfluenceImport::TABLE_JOBS) {
            return $this->cursor($table, ' WHERE status = ?', ['completed']);
        }

        return $this->cursor(
            $table,
            ' WHERE job_id IN (SELECT id FROM '
                . $this->database->getDialect()->quoteIdentifier(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
                . ' WHERE status = ?)',
            ['completed'],
        );
    }

    /**
     * HR: Uklanja lokalne privremene putanje iz trajnog zapisa backupa.
     * EN: Removes local transient paths from the durable backup record.
     *
     * @param array<string,mixed> $definition
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    protected function transformExportRecord(
        array $definition,
        array $record,
        BackupExportContext $context,
    ): array {
        if (($definition['dataset'] ?? '') === 'jobs') {
            $record['archive_path'] = '';
            $record['next_offset'] = $record['archive_size'] ?? 0;
            $record['expires_at'] = null;
            $record['error_message'] = null;
        }

        if (($definition['dataset'] ?? '') === 'attachments') {
            $path = is_string($record['storage_path'] ?? null) ? $record['storage_path'] : '';
            $record['storage_path'] = $path !== '' ? basename($path) : null;
        }

        return parent::transformExportRecord($definition, $record, $context);
    }

    /**
     * HR: Nakon prenosivog mapiranja čvora osvježava lokalni dokument ključ i slug.
     * EN: Refreshes the local document key and slug after portable node mapping.
     *
     * @param array<string,mixed> $definition
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    protected function transformImportRecord(
        array $definition,
        array $record,
        BackupImportContext $context,
    ): array {
        $record = parent::transformImportRecord($definition, $record, $context);
        $dataset = $definition['dataset'] ?? '';
        if ($record === [] || !in_array($dataset, ['content', 'attachments'], true)) {
            return $record;
        }

        if ($dataset === 'attachments') {
            $storedName = is_string($record['storage_path'] ?? null)
                ? basename($record['storage_path'])
                : '';
            $record['storage_path'] = $storedName !== ''
                ? $this->config->attachmentDirectory() . DIRECTORY_SEPARATOR . $storedName
                : null;
        }

        $nodeId = is_numeric($record['target_node_id'] ?? null) ? (int)$record['target_node_id'] : 0;
        $node = $nodeId > 0
            ? $this->database->table(
                \AaiEduHr\HeartPhrameModuleWorkspace\ModuleWorkspace::TABLE_WORKSPACE_NODES,
            )->where('id', '=', $nodeId)->first()
            : null;
        $record['target_document_key'] = is_array($node) && is_scalar($node['document_key'] ?? null)
            ? (string)$node['document_key']
            : null;
        if ($dataset === 'content' && is_array($node) && is_scalar($node['slug'] ?? null)) {
            $record['target_slug'] = (string)$node['slug'];
        }

        return $record;
    }
}
