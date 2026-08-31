<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Listener;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleWorkspace\Event\WorkspacePagesPermanentlyDeleting;
use AaiEduHr\SimbiozaModuleConfluenceImport\ModuleSimbiozaConfluenceImport;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportConfig;
use RuntimeException;

use function array_unique;
use function array_values;
use function date;
use function is_array;
use function is_file;
use function is_numeric;
use function is_scalar;
use function realpath;
use function rtrim;
use function str_starts_with;
use function unlink;

/**
 * HR: Čisti samo Confluence metapodatke stranica koje se trajno uklanjaju.
 * EN: Cleans only Confluence metadata for pages being permanently removed.
 */
final readonly class PurgeConfluencePageImport
{
    /** HR: Prima ORM i granicu privatnog spremišta modula. EN: Receives the ORM and module-private storage boundary. */
    public function __construct(
        private Database $database,
        private ConfluenceImportConfig $config,
    ) {
    }

    /**
     * HR: Uklanja mapiranja, izlazne veze i privatne privitke samo ciljanih stranica.
     * EN: Removes mappings, outgoing links, and private attachments only for targeted pages.
     */
    public function __invoke(WorkspacePagesPermanentlyDeleting $event): void
    {
        $nodeIds = [];
        $documentKeys = [];
        foreach ($event->pages as $page) {
            $nodeId = is_numeric($page['node_id'] ?? null) ? (int)$page['node_id'] : 0;
            $documentKey = is_scalar($page['document_key'] ?? null)
                ? (string)$page['document_key']
                : '';
            if ($nodeId > 0) {
                $nodeIds[] = $nodeId;
            }
            if ($documentKey !== '') {
                $documentKeys[] = $documentKey;
            }
        }
        $nodeIds = array_values(array_unique($nodeIds));
        $documentKeys = array_values(array_unique($documentKeys));
        if ($nodeIds === [] && $documentKeys === []) {
            return;
        }

        $contentRows = $this->targetRows(
            ModuleSimbiozaConfluenceImport::TABLE_CONTENT,
            $nodeIds,
            $documentKeys,
        );
        $sourcePagesByJob = [];
        $sourcePagesBySpace = [];
        foreach ($contentRows as $row) {
            $jobId = is_numeric($row['job_id'] ?? null) ? (int)$row['job_id'] : 0;
            $spaceKey = is_scalar($row['source_space_key'] ?? null)
                ? (string)$row['source_space_key']
                : '';
            foreach (['source_content_id', 'logical_source_id'] as $column) {
                if (is_scalar($row[$column] ?? null) && (string)$row[$column] !== '') {
                    $sourcePageId = (string)$row[$column];
                    if ($jobId > 0) {
                        $sourcePagesByJob[$jobId][] = $sourcePageId;
                    }
                    if ($spaceKey !== '') {
                        $sourcePagesBySpace[$spaceKey][] = $sourcePageId;
                    }
                }
            }
        }
        foreach ($sourcePagesByJob as $jobId => $sourcePageIds) {
            $sourcePagesByJob[$jobId] = array_values(array_unique($sourcePageIds));
        }
        foreach ($sourcePagesBySpace as $spaceKey => $sourcePageIds) {
            $sourcePagesBySpace[$spaceKey] = array_values(array_unique($sourcePageIds));
        }

        $attachmentRows = $this->targetRows(
            ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS,
            $nodeIds,
            $documentKeys,
        );
        foreach ($sourcePagesByJob as $jobId => $sourcePageIds) {
            foreach (
                $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
                ->where('job_id', '=', $jobId)
                ->whereIn('source_page_id', $sourcePageIds)
                ->get() as $row
            ) {
                if (is_array($row) && is_numeric($row['id'] ?? null)) {
                    $attachmentRows[(int)$row['id']] = $row;
                }
            }
        }

        $attachmentIds = [];
        $managedFiles = [];
        foreach ($attachmentRows as $row) {
            if (is_numeric($row['id'] ?? null)) {
                $attachmentIds[] = (int)$row['id'];
            }
            if (is_scalar($row['storage_path'] ?? null) && (string)$row['storage_path'] !== '') {
                $managedFiles[] = (string)$row['storage_path'];
            }
        }
        $contentIds = [];
        foreach ($contentRows as $row) {
            if (is_numeric($row['id'] ?? null)) {
                $contentIds[] = (int)$row['id'];
            }
        }

        $this->database->transaction(function (Database $database) use (
            $attachmentIds,
            $contentIds,
            $sourcePagesByJob,
            $sourcePagesBySpace,
        ): void {
            if ($attachmentIds !== []) {
                $database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
                    ->whereIn('id', array_values(array_unique($attachmentIds)))
                    ->delete();
            }
            foreach ($sourcePagesByJob as $jobId => $sourcePageIds) {
                $database->table(ModuleSimbiozaConfluenceImport::TABLE_LINKS)
                    ->where('job_id', '=', $jobId)
                    ->whereIn('source_page_id', $sourcePageIds)
                    ->delete();
            }
            foreach ($sourcePagesBySpace as $spaceKey => $sourcePageIds) {
                $database->table(ModuleSimbiozaConfluenceImport::TABLE_LINKS)
                    ->where('destination_space_key', '=', $spaceKey)
                    ->whereIn('destination_page_id', $sourcePageIds)
                    ->update([
                        'resolved_target' => null,
                        'status' => 'unresolved',
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
            }
            if ($contentIds !== []) {
                $database->table(ModuleSimbiozaConfluenceImport::TABLE_CONTENT)
                    ->whereIn('id', array_values(array_unique($contentIds)))
                    ->delete();
            }
        });

        foreach (array_unique($managedFiles) as $path) {
            $this->deleteManagedFile($path);
        }
    }

    /**
     * HR: Vraća jedinstvene retke povezane identifikatorom čvora ili dokumenta.
     * EN: Returns unique rows linked by either node or document identifier.
     *
     * @param list<int> $nodeIds
     * @param list<string> $documentKeys
     * @return array<int, array<string, mixed>>
     */
    private function targetRows(string $table, array $nodeIds, array $documentKeys): array
    {
        $rows = [];
        if ($nodeIds !== []) {
            foreach ($this->database->table($table)->whereIn('target_node_id', $nodeIds)->get() as $row) {
                if (is_array($row) && is_numeric($row['id'] ?? null)) {
                    $rows[(int)$row['id']] = $row;
                }
            }
        }
        if ($documentKeys !== []) {
            foreach (
                $this->database->table($table)
                ->whereIn('target_document_key', $documentKeys)
                ->get() as $row
            ) {
                if (is_array($row) && is_numeric($row['id'] ?? null)) {
                    $rows[(int)$row['id']] = $row;
                }
            }
        }

        return $rows;
    }

    /** HR: Briše samo datoteku ispod direktorija ovog modula. EN: Deletes only a file below this module's directory. */
    private function deleteManagedFile(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $root = realpath($this->config->dataDirectory());
        $real = realpath($path);
        if (
            $root === false
            || $real === false
            || !str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
        ) {
            throw new RuntimeException('Confluence import file is outside the managed directory.');
        }

        if (!unlink($real)) {
            throw new RuntimeException('Confluence import file cannot be permanently deleted.');
        }
    }
}
