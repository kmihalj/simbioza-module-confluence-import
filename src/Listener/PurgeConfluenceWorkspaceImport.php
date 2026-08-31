<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Listener;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleWorkspace\Event\WorkspacePermanentlyDeleting;
use AaiEduHr\SimbiozaModuleConfluenceImport\ModuleSimbiozaConfluenceImport;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportConfig;
use RuntimeException;

use function array_filter;
use function array_unique;
use function array_values;
use function is_array;
use function is_file;
use function is_numeric;
use function is_scalar;
use function realpath;
use function rtrim;
use function str_starts_with;
use function unlink;

/** HR: Uklanja Confluence mapiranja i privatne staging datoteke trajno obrisanog područja. EN: Removes Confluence mappings and private staging files for a permanently deleted Workspace. */
final readonly class PurgeConfluenceWorkspaceImport
{
    /** HR: Prima ORM i sigurnu granicu privatnog spremišta. EN: Receives the ORM and private-storage safety boundary. */
    public function __construct(
        private Database $database,
        private ConfluenceImportConfig $config,
    ) {
    }

    /** HR: Čisti samo poslove i mapiranja koji ciljaju zadano područje. EN: Cleans only jobs and mappings targeting the supplied Workspace. */
    public function __invoke(WorkspacePermanentlyDeleting $event): void
    {
        $jobIds = [];
        foreach (
            $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
            ->select(['id'])
            ->where('workspace_id', '=', $event->workspaceId)
            ->get() as $row
        ) {
            if (is_array($row) && is_numeric($row['id'] ?? null)) {
                $jobIds[] = (int)$row['id'];
            }
        }
        foreach (
            $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_SPACES)
            ->select(['job_id'])
            ->where('target_workspace_id', '=', $event->workspaceId)
            ->get() as $row
        ) {
            if (is_array($row) && is_numeric($row['job_id'] ?? null)) {
                $jobIds[] = (int)$row['job_id'];
            }
        }
        $jobIds = array_values(array_filter(array_unique($jobIds)));

        $managedFiles = [];
        $attachmentQuery = $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
            ->where('target_workspace_id', '=', $event->workspaceId);
        foreach ($attachmentQuery->get() as $row) {
            if (is_array($row) && is_scalar($row['storage_path'] ?? null)) {
                $managedFiles[] = (string)$row['storage_path'];
            }
        }
        if ($jobIds !== []) {
            foreach (
                $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
                ->whereIn('id', $jobIds)
                ->get() as $row
            ) {
                if (is_array($row) && is_scalar($row['archive_path'] ?? null)) {
                    $managedFiles[] = (string)$row['archive_path'];
                }
            }
            foreach (
                $this->database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
                ->whereIn('job_id', $jobIds)
                ->get() as $row
            ) {
                if (is_array($row) && is_scalar($row['storage_path'] ?? null)) {
                    $managedFiles[] = (string)$row['storage_path'];
                }
            }
        }

        $this->database->transaction(function (Database $database) use ($event, $jobIds): void {
            $database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
                ->where('target_workspace_id', '=', $event->workspaceId)
                ->delete();
            $database->table(ModuleSimbiozaConfluenceImport::TABLE_CONTENT)
                ->where('target_workspace_id', '=', $event->workspaceId)
                ->delete();
            $database->table(ModuleSimbiozaConfluenceImport::TABLE_SPACES)
                ->where('target_workspace_id', '=', $event->workspaceId)
                ->delete();
            if ($jobIds === []) {
                return;
            }

            $database->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)
                ->whereIn('job_id', $jobIds)
                ->delete();
            $database->table(ModuleSimbiozaConfluenceImport::TABLE_LINKS)
                ->whereIn('job_id', $jobIds)
                ->delete();
            $database->table(ModuleSimbiozaConfluenceImport::TABLE_CONTENT)
                ->whereIn('job_id', $jobIds)
                ->delete();
            $database->table(ModuleSimbiozaConfluenceImport::TABLE_IDENTITIES)
                ->whereIn('job_id', $jobIds)
                ->delete();
            $database->table(ModuleSimbiozaConfluenceImport::TABLE_GROUPS)
                ->whereIn('job_id', $jobIds)
                ->delete();
            $database->table(ModuleSimbiozaConfluenceImport::TABLE_SPACES)
                ->whereIn('job_id', $jobIds)
                ->delete();
            $database->table(ModuleSimbiozaConfluenceImport::TABLE_JOBS)
                ->whereIn('id', $jobIds)
                ->delete();
        });

        foreach (array_unique($managedFiles) as $path) {
            $this->deleteManagedFile($path);
        }
    }

    /** HR: Briše samo datoteku koja se stvarno nalazi ispod direktorija ovog modula. EN: Deletes only a file that actually resides below this module's data directory. */
    private function deleteManagedFile(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $root = realpath($this->config->dataDirectory());
        $real = realpath($path);
        if ($root === false || $real === false || !str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Confluence import file is outside the managed directory.');
        }

        if (!unlink($real)) {
            throw new RuntimeException('Confluence import file cannot be permanently deleted.');
        }
    }
}
