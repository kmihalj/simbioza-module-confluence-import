<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\SimbiozaModuleConfluenceImport\Support\Utf8Url;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthGroupService;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthUserAttributeService;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthUserService;
use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorApiActorContext;
use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorDocumentIncludeService;
use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorImportAttachmentService;
use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorImportAttributionService;
use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorImageVariantService;
use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorService;
use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorWorkspaceIntegration;
use AaiEduHr\SimbiozaModuleWorkspace\Event\WorkspaceContentChanged;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceContentChangeBatch;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceMaintenanceService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceWorkflowService;
use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use AaiEduHr\SimbiozaModuleConfluenceImport\Value\ConfluenceObject;
use AaiEduHr\SimbiozaModuleConfluenceImport\Value\ConfluenceMacroContext;
use AaiEduHr\SimbiozaModuleUser\Service\PersonalWorkspaceService;
use HeartPhrame\Routing\UrlGenerator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_pad;
use function array_reverse;
use function array_unique;
use function array_values;
use function basename;
use function count;
use function dirname;
use function error_get_last;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function fclose;
use function finfo_file;
use function finfo_open;
use function flock;
use function fopen;
use function function_exists;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_numeric;
use function is_object;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function max;
use function mkdir;
use function parse_url;
use function preg_replace;
use function preg_split;
use function rawurlencode;
use function register_shutdown_function;
use function rmdir;
use function ucfirst;
use function set_time_limit;
use function sort;
use function str_contains;
use function str_starts_with;
use function strip_tags;
use function strtolower;
use function trim;
use function unlink;
use function usort;

use const FILEINFO_MIME_TYPE;
use const E_COMPILE_ERROR;
use const E_CORE_ERROR;
use const E_ERROR;
use const E_PARSE;
use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;

/**
 * HR: Orkestrira kontrolirani import područja, stranica, povijesti, ACL-a, privitaka i opcionalnih komentara.
 * EN: Orchestrates controlled import of spaces, pages, history, ACL, attachments, and optional comments.
 */
final readonly class ConfluenceImportService
{
    private const COMMENT_SERVICE = \AaiEduHr\HeartPhrameModuleComment\Service\CommentService::class;

    private const AUDIT_LOG = \AaiEduHr\HeartPhrameModuleAudit\Service\AuditLogService::class;

    /** HR: Prima samo javne servise obaveznih modula i container za opcionalne integracije. EN: Receives public required-module services and a container for optional integrations. */
    public function __construct(
        private ConfluenceImportRepository $repository,
        private ConfluenceImportUploadService $uploads,
        private ConfluenceExportReader $reader,
        private ConfluenceArchive $archive,
        private ConfluenceImportConfig $config,
        private ConfluenceHtmlConverter $converter,
        private ConfluenceReferenceResolver $references,
        private ConfluencePageSlugger $pageSlugger,
        private ConfluencePageHierarchy $pageHierarchy,
        private WorkspaceRepository $workspaces,
        private WorkspaceContentChangeBatch $workspaceChanges,
        private WorkspaceWorkflowService $workflow,
        private WorkspaceMaintenanceService $workspaceMaintenance,
        private EditorService $editor,
        private EditorDocumentIncludeService $documentIncludes,
        private EditorWorkspaceIntegration $editorWorkspace,
        private EditorApiActorContext $editorActors,
        private EditorImportAttachmentService $importedAttachmentService,
        private EditorImportAttributionService $importAttribution,
        private EditorImageVariantService $imageVariants,
        private AuthUserService $users,
        private AuthUserAttributeService $userAttributes,
        private AuthGroupService $groups,
        private ConfluencePrincipalMatcher $principalMatcher,
        private PersonalWorkspaceService $personalWorkspaces,
        private UrlGenerator $urls,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * HR: Vraća preflight, prijedloge identiteta i postojeće korisnike bez izmjene sadržaja.
     * EN: Returns preflight, identity suggestions, and existing users without changing content.
     *
     * @return array<string,mixed>
     */
    public function preparation(string $jobUuid, int $actorUserId): array
    {
        $job = $this->repository->jobByUuid($jobUuid, $actorUserId);
        $scan = is_array($job['summary'] ?? null) ? $job['summary'] : [];
        $targetUsers = array_values($this->users->listUsersForSetup());
        $targetUsersById = [];
        foreach ($targetUsers as $targetUser) {
            if (is_array($targetUser) && is_numeric($targetUser['id'] ?? null)) {
                $targetUsersById[(int)$targetUser['id']] = $targetUser;
            }
        }
        $suggestions = [];
        foreach ($this->rows($scan['users'] ?? []) as $sourceUser) {
            $sourceKey = $this->text($sourceUser['source_key'] ?? '');
            $mappedUserId = $sourceKey !== '' ? $this->repository->mappedUserId($sourceKey) : null;
            $candidateId = $mappedUserId !== null && isset($targetUsersById[$mappedUserId])
                ? $mappedUserId
                : $this->principalMatcher->suggestUserId($sourceUser, $targetUsers);
            if ($sourceKey !== '' && $candidateId !== null) {
                $suggestions[$sourceKey] = $candidateId;
            }
        }

        $targetGroups = array_values($this->groups->listGroups());
        $sourceSpace = is_array($scan['spaces'][0] ?? null) ? $scan['spaces'][0] : [];
        $existingImport = null;
        $mappedSpace = $this->repository->spaceBySourceId($this->text($sourceSpace['source_id'] ?? ''));
        if (is_array($mappedSpace)) {
            $workspaceId = (int)($mappedSpace['target_workspace_id'] ?? 0);
            $workspace = $workspaceId > 0
                ? $this->workspaces->findWorkspaceById($workspaceId, true)
                : null;
            if (is_array($workspace)) {
                $existingImport = [
                    'workspace_id' => $workspaceId,
                    'workspace_name' => $this->text($workspace['name'] ?? ''),
                    'workspace_slug' => $this->text($workspace['slug'] ?? ''),
                    'is_deleted' => (bool)($workspace['is_deleted'] ?? false),
                ];
            }
        }

        return [
            'job' => $job,
            'scan' => $scan,
            'target_users' => $targetUsers,
            'target_groups' => $targetGroups,
            'identity_suggestions' => $suggestions,
            'group_suggestions' => $this->groupSuggestions($scan, $targetGroups),
            'existing_import' => $existingImport,
        ];
    }

    /**
     * HR: Predlaže postojeća mapiranja grupa bez automatskog stvaranja.
     * EN: Suggests existing group mappings without automatic creation.
     *
     * @param array<string,mixed> $scan
     * @param list<array<string,mixed>> $targetGroups
     * @return array<string,int>
     */
    private function groupSuggestions(array $scan, array $targetGroups): array
    {
        $suggestions = [];
        $targetIds = array_values(array_filter(array_map(
            static fn(array $group): int => is_numeric($group['id'] ?? null) ? (int)$group['id'] : 0,
            $targetGroups,
        )));
        foreach ($this->rows($scan['groups'] ?? []) as $sourceGroup) {
            $name = $this->text($sourceGroup['source_name'] ?? '');
            $targetId = $name !== '' ? $this->repository->mappedGroupId($name) : null;
            if ($targetId !== null && !in_array($targetId, $targetIds, true)) {
                $targetId = null;
            }
            $targetId ??= $this->principalMatcher->suggestGroupId($name, $targetGroups);
            if ($targetId !== null) {
                $suggestions[$name] = $targetId;
            }
        }

        return $suggestions;
    }

    /**
     * HR: Priprema potvrđeni import i sprema nastavivo stanje prije prvog
     *     ograničenog procesnog koraka.
     * EN: Prepares a confirmed import and stores resumable state before the
     *     first bounded processing step.
     *
     * @param array<string,mixed> $options
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function queue(string $jobUuid, array $options, array $actor): array
    {
        $actorUserId = $this->positiveInt($actor['id'] ?? null, __('Prijavljeni administrator nije pronađen.'));
        $job = $this->repository->jobByUuid($jobUuid, $actorUserId);
        $staging = $this->stagingDirectory($jobUuid);
        if (($job['status'] ?? '') === 'running' && is_file($this->statePath($staging))) {
            return $this->progress($this->loadState($staging));
        }
        if (($job['status'] ?? '') !== 'ready') {
            throw new ConfluenceImportException(__('Confluence import nije spreman za pokretanje.'));
        }

        $archivePath = $this->text($job['archive_path'] ?? '');
        $scan = is_array($job['summary'] ?? null) ? $job['summary'] : [];
        $space = is_array($scan['spaces'][0] ?? null) ? $scan['spaces'][0] : null;
        if (!is_array($space) || !is_file($archivePath)) {
            throw new ConfluenceImportException(__('Preflight podaci ili izvorna arhiva više nisu dostupni.'));
        }

        $normalized = $this->normalizeOptions($options, $space);
        $jobId = (int)$job['id'];
        $workspace = null;
        try {
            $this->extendExecutionTime();
            $normalized = $this->prepareReimport($space, $normalized, $actorUserId, $jobId);
            $this->repository->startImport($jobId, $normalized);
            $this->repository->setStage($jobId, 'identity_mapping');
            $this->saveIdentityMappings($scan, $normalized, $jobId);
            $normalized['group_map'] = $this->saveGroupMappings($scan, $normalized, $jobId);
            $this->repository->setStage($jobId, 'workspace');
            $workspaceManagerUserId = $this->workspaceManagerUserId($space, $actorUserId);
            $workspace = $this->workspace(
                $space,
                $normalized,
                $actorUserId,
                $workspaceManagerUserId,
            );
            $workspaceId = $this->positiveInt($workspace['id'] ?? null, __('Ciljno područje nije moguće kreirati.'));
            $this->repository->mapSpace($this->mappingSpace($space, $normalized), $workspace, $jobId);
            $this->applyWorkspaceAcl($workspaceId, $workspaceManagerUserId, $scan, $normalized);

            $this->repository->setStage($jobId, 'preparing_content');
            $dataset = $this->stageDataset($archivePath, $staging);
            // HR: Korisnički zapisi iz preflighta mali su i čuvaju se uz fazno
            //     stanje kako bi makroi profila prikazali stvarna imena.
            // EN: Preflight user records are small and are retained in the
            //     phased state so profile macros can show real names.
            $dataset['users'] = $this->rows($scan['users'] ?? []);
            $dataset['calendars'] = $this->rows($scan['calendars'] ?? []);
            $pages = $this->selectedPageGroups($dataset['pages'], $normalized);
            $targets = $this->plannedTargets($pages, $workspace, $dataset['pages']);
            $phase = $normalized['include_attachments'] ? 'attachments' : 'pages';
            $state = [
                'version' => 1,
                'phase' => $phase,
                'job_id' => $jobId,
                'job_uuid' => $jobUuid,
                'actor_user_id' => $actorUserId,
                'archive_path' => $archivePath,
                'space' => $space,
                'workspace' => $workspace,
                'workspace_manager_user_id' => $workspaceManagerUserId,
                'options' => $normalized,
                'dataset' => $dataset,
                'pages' => $pages,
                'targets' => $targets,
                'attachment_offset' => 0,
                'attachment_result' => [
                    'urls' => [],
                    'imported' => 0,
                    'failed' => 0,
                    'warnings' => [],
                    'total' => $normalized['include_attachments'] ? count($dataset['attachments']) : 0,
                ],
                'page_result' => [
                    'imported' => 0,
                    'history' => 0,
                    'drafts' => 0,
                    'deleted' => 0,
                    'warnings' => [],
                    'review_pages' => [],
                    'nodes_by_source' => [],
                    'documents_by_source' => [],
                ],
            ];
            $this->repository->setStage($jobId, $phase);
            $this->saveState($staging, $state);

            return $this->progress($state);
        } catch (\Throwable $throwable) {
            $this->recordFailure($jobId, $jobUuid, $actorUserId, $space, $workspace, $throwable);
            throw $throwable;
        }
    }

    /**
     * HR: Obrađuje jedan mali dio nastavivog importa. Istodobni poziv ne
     *     duplicira sadržaj nego dobiva trenutačno stanje.
     * EN: Processes one small part of a resumable import. A concurrent call
     *     returns current state instead of duplicating content.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function process(string $jobUuid, array $actor): array
    {
        $actorUserId = $this->positiveInt($actor['id'] ?? null, __('Prijavljeni administrator nije pronađen.'));
        $job = $this->repository->jobByUuid($jobUuid, $actorUserId);
        if (($job['status'] ?? '') === 'completed') {
            return [
                'completed' => true,
                'progress' => 100,
                'phase' => 'completed',
                'summary' => is_array($job['summary'] ?? null) ? $job['summary'] : [],
            ];
        }
        if (($job['status'] ?? '') !== 'running') {
            throw new ConfluenceImportException(__('Confluence import nije u tijeku.'));
        }

        $staging = $this->stagingDirectory($jobUuid);
        $lock = $this->stateLock($staging);
        if (!is_resource($lock)) {
            $progress = $this->progress($this->loadState($staging));
            $progress['busy'] = true;
            return $progress;
        }

        $state = [];
        try {
            $state = $this->loadState($staging);
            $phase = $this->text($state['phase'] ?? '');
            if ($phase === 'attachments') {
                $result = $this->importAttachments(
                    $this->text($state['archive_path'] ?? ''),
                    is_array($state['dataset'] ?? null) ? $state['dataset'] : [],
                    is_array($state['workspace'] ?? null) ? $state['workspace'] : [],
                    (int)$state['job_id'],
                    (int)($state['attachment_offset'] ?? 0),
                    25,
                );
                $stored = is_array($state['attachment_result'] ?? null) ? $state['attachment_result'] : [];
                $state['attachment_result'] = [
                    'urls' => $this->mergeAttachmentUrls($stored['urls'] ?? [], $result['urls'] ?? []),
                    'imported' => (int)($stored['imported'] ?? 0) + (int)($result['imported'] ?? 0),
                    'failed' => (int)($stored['failed'] ?? 0) + (int)($result['failed'] ?? 0),
                    'warnings' => array_values(array_unique([
                        ...$this->strings($stored['warnings'] ?? []),
                        ...$this->strings($result['warnings'] ?? []),
                    ])),
                    'total' => (int)($result['total'] ?? $stored['total'] ?? 0),
                ];
                $state['attachment_offset'] = (int)($result['next_offset'] ?? 0);
                if ($state['attachment_offset'] >= (int)$state['attachment_result']['total']) {
                    $state['phase'] = 'pages';
                    $this->repository->setStage((int)$state['job_id'], 'pages');
                }
            } elseif ($phase === 'pages') {
                $pageResult = is_array($state['page_result'] ?? null) ? $state['page_result'] : [];
                /*
                 * HR: Svaki HTTP korak sprema mali broj stranica, ali skupe
                 *     izvedene indekse osvježavamo tek u završnoj fazi.
                 * EN: Each HTTP step stores a small number of pages, while
                 *     expensive derived indexes refresh only in finalization.
                 */
                $batch = $this->workspaceChanges->runDeferred(fn(): array => $this->editorActors->runAs(
                    $actor,
                    fn(): array => $this->importPages(
                        is_array($state['pages'] ?? null) ? $state['pages'] : [],
                        is_array($state['dataset'] ?? null) ? $state['dataset'] : [],
                        is_array($state['targets'] ?? null) ? $state['targets'] : [],
                        is_array($state['attachment_result']['urls'] ?? null) ? $state['attachment_result']['urls'] : [],
                        is_array($state['space'] ?? null) ? $state['space'] : [],
                        is_array($state['workspace'] ?? null) ? $state['workspace'] : [],
                        (int)$state['job_id'],
                        $actorUserId,
                        is_array($state['options'] ?? null) ? $state['options'] : [],
                        is_array($pageResult['nodes_by_source'] ?? null) ? $pageResult['nodes_by_source'] : [],
                        is_array($pageResult['documents_by_source'] ?? null) ? $pageResult['documents_by_source'] : [],
                        5,
                    ),
                ));
                $state['page_result'] = $this->mergePageResults($pageResult, $batch);
                if (count($state['page_result']['documents_by_source']) >= count($state['pages'])) {
                    $state['phase'] = 'finalizing';
                    $this->repository->setStage((int)$state['job_id'], 'finalizing');
                }
            } elseif ($phase === 'finalizing') {
                return $this->finalizeQueuedImport($staging, $state);
            } else {
                throw new ConfluenceImportException(__('Spremljena faza Confluence importa nije valjana.'));
            }

            $this->saveState($staging, $state);
            return $this->progress($state);
        } catch (\Throwable $throwable) {
            $space = is_array($state['space'] ?? null) ? $state['space'] : [];
            $workspace = is_array($state['workspace'] ?? null) ? $state['workspace'] : null;
            $this->recordFailure((int)($state['job_id'] ?? $job['id'] ?? 0), $jobUuid, $actorUserId, $space, $workspace, $throwable);
            throw $throwable;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * HR: Izvodi potvrđeni import. Arhivu briše tek nakon završetka, a na pogrešci je čuva za dijagnostiku.
     * EN: Runs a confirmed import. The archive is deleted only after completion and retained on failure for diagnosis.
     *
     * @param array<string,mixed> $options
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function import(string $jobUuid, array $options, array $actor): array
    {
        $actorUserId = $this->positiveInt($actor['id'] ?? null, __('Prijavljeni administrator nije pronađen.'));
        $job = $this->repository->jobByUuid($jobUuid, $actorUserId);
        if (($job['status'] ?? '') !== 'ready') {
            throw new ConfluenceImportException(__('Confluence import nije spreman za pokretanje.'));
        }

        $archivePath = $this->text($job['archive_path'] ?? '');
        $scan = is_array($job['summary'] ?? null) ? $job['summary'] : [];
        $space = is_array($scan['spaces'][0] ?? null) ? $scan['spaces'][0] : null;
        if (!is_array($space) || !is_file($archivePath)) {
            throw new ConfluenceImportException(__('Preflight podaci ili izvorna arhiva više nisu dostupni.'));
        }

        $normalized = $this->normalizeOptions($options, $space);
        $this->extendExecutionTime();
        $jobId = (int)$job['id'];
        $shutdownHandled = false;
        $this->registerFatalFailureHandler($jobId, $jobUuid, $shutdownHandled);
        $staging = $this->stagingDirectory($jobUuid);
        $workspace = null;
        try {
            $normalized = $this->prepareReimport($space, $normalized, $actorUserId, $jobId);
            $this->repository->startImport($jobId, $normalized);
            $this->repository->setStage($jobId, 'identity_mapping');
            $this->saveIdentityMappings($scan, $normalized, $jobId);
            $normalized['group_map'] = $this->saveGroupMappings($scan, $normalized, $jobId);
            $this->repository->setStage($jobId, 'workspace');
            $workspaceManagerUserId = $this->workspaceManagerUserId($space, $actorUserId);
            $workspace = $this->workspace(
                $space,
                $normalized,
                $actorUserId,
                $workspaceManagerUserId,
            );
            $workspaceId = $this->positiveInt($workspace['id'] ?? null, __('Ciljno područje nije moguće kreirati.'));
            $this->repository->mapSpace($this->mappingSpace($space, $normalized), $workspace, $jobId);
            $this->applyWorkspaceAcl($workspaceId, $workspaceManagerUserId, $scan, $normalized);

            $dataset = $this->stageDataset($archivePath, $staging);
            // HR: I sinkroni import treba isti kontekst korisnika kao fazni.
            // EN: A synchronous import needs the same user context as a phased import.
            $dataset['users'] = $this->rows($scan['users'] ?? []);
            $dataset['calendars'] = $this->rows($scan['calendars'] ?? []);
            $pages = $this->selectedPageGroups($dataset['pages'], $normalized);
            $targets = $this->plannedTargets($pages, $workspace, $dataset['pages']);
            $this->repository->setStage($jobId, 'attachments');
            $attachmentResult = $normalized['include_attachments']
                ? $this->importAttachments(
                    $archivePath,
                    $dataset,
                    $workspace,
                    $jobId,
                )
                : ['urls' => [], 'imported' => 0, 'failed' => 0, 'warnings' => []];
            $attachments = $attachmentResult['urls'];

            /*
             * HR: Stranice, workflow i ACL mijenjaju se u jednom skupnom
             *     prozoru. Backlink i Search listeneri zato rade samo jednom,
             *     nakon što je izvorni sadržaj spreman.
             * EN: Pages, workflow, and ACL are changed in one bulk window.
             *     Backlink and Search listeners therefore run only once after
             *     the source content is ready.
             */
            $contentResult = $this->workspaceChanges->run(function () use (
                $actor,
                $pages,
                $dataset,
                $targets,
                $attachments,
                $space,
                $workspace,
                $jobId,
                $actorUserId,
                $normalized,
                $workspaceId,
                $workspaceManagerUserId,
            ): array {
                $this->repository->setStage($jobId, 'pages');
                $pageResult = $this->editorActors->runAs(
                    $actor,
                    fn(): array => $this->importPages(
                        $pages,
                        $dataset,
                        $targets,
                        $attachments,
                        $space,
                        $workspace,
                        $jobId,
                        $actorUserId,
                        $normalized,
                    ),
                );
                $this->repository->setStage($jobId, 'acl');
                $this->applyNodeAcl(
                    $workspaceId,
                    $workspaceManagerUserId,
                    $dataset,
                    $pageResult['nodes_by_source'],
                    $normalized,
                );
                $this->repository->setStage($jobId, 'comments');
                $commentResult = $normalized['include_comments']
                    ? $this->importComments(
                        $dataset,
                        $pageResult['documents_by_source'],
                        $jobId,
                        $this->text($normalized['mapping_space_key'] ?? $space['source_key'] ?? ''),
                        $workspaceId,
                        $this->text($normalized['language'] ?? $this->config->defaultLanguage()),
                    )
                    : ['imported' => 0, 'skipped' => 0];

                return ['pages' => $pageResult, 'comments' => $commentResult];
            });
            $pageResult = $contentResult['pages'];
            $commentResult = $contentResult['comments'];
            $this->repository->setStage($jobId, 'links_and_search');
            $reconciled = $this->reconcileLinks();
            $includesReconciled = $this->reconcileIncludes(
                $this->text($space['source_key'] ?? ''),
                $targets,
                $pageResult['documents_by_source'],
            );
            $summary = [
                'workspace_id' => $workspaceId,
                'workspace_slug' => $this->text($workspace['slug'] ?? ''),
                'pages_imported' => $pageResult['imported'],
                'history_versions_imported' => $pageResult['history'],
                'drafts_imported' => $pageResult['drafts'],
                'deleted_pages_imported' => $pageResult['deleted'],
                'attachments_imported' => $attachmentResult['imported'],
                'attachments_failed' => $attachmentResult['failed'],
                'comments_imported' => $commentResult['imported'],
                'comments_skipped' => $commentResult['skipped'],
                'links_reconciled' => $reconciled,
                'includes_reconciled' => $includesReconciled,
                'review_pages' => $pageResult['review_pages'],
                'warnings' => array_values(array_unique([
                    ...$pageResult['warnings'],
                    ...$dataset['warnings'],
                    ...$attachmentResult['warnings'],
                ])),
            ];
            $this->repository->completeImport($jobId, $workspaceId, $summary);
            $this->uploads->deleteArchive($job);
            $this->removeDirectory($staging);
            $this->audit('simbioza_confluence_import.completed', [
                'action' => 'import_completed',
                'actor_user_id' => $actorUserId,
                'workspace_id' => $workspaceId,
                'target_type' => 'confluence_space',
                'target_id' => $this->text($space['source_key'] ?? ''),
                'target_label' => $this->text($space['name'] ?? ''),
                'metadata' => [
                    'pages_imported' => $summary['pages_imported'],
                    'attachments_imported' => $summary['attachments_imported'],
                ],
            ]);
            $shutdownHandled = true;

            return $summary;
        } catch (\Throwable $throwable) {
            $this->repository->failImport($jobId, $throwable->getMessage());
            $shutdownHandled = true;
            $this->audit('simbioza_confluence_import.failed', [
                'action' => 'import_failed',
                'outcome' => 'failure',
                'actor_user_id' => $actorUserId,
                'workspace_id' => is_array($workspace) && is_numeric($workspace['id'] ?? null)
                    ? (int)$workspace['id']
                    : null,
                'target_type' => 'confluence_space',
                'target_id' => $this->text($space['source_key'] ?? ''),
                'target_label' => $this->text($space['name'] ?? ''),
                'metadata' => ['error_class' => $throwable::class],
            ]);
            $this->logger->error('Confluence import failed.', [
                'module' => 'simbioza-confluence-import',
                'job_uuid' => $jobUuid,
                'workspace_id' => is_array($workspace) ? $workspace['id'] ?? null : null,
                'exception' => $throwable,
            ]);
            throw $throwable;
        }
    }

    /**
     * HR: Dovršava ACL, komentare i izvedene poveznice tek nakon svih stranica.
     * EN: Finalizes ACL, comments, and derived links only after all pages exist.
     *
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function finalizeQueuedImport(string $staging, array $state): array
    {
        $jobId = (int)($state['job_id'] ?? 0);
        $actorUserId = (int)($state['actor_user_id'] ?? 0);
        $space = is_array($state['space'] ?? null) ? $state['space'] : [];
        $workspace = is_array($state['workspace'] ?? null) ? $state['workspace'] : [];
        $options = is_array($state['options'] ?? null) ? $state['options'] : [];
        $dataset = is_array($state['dataset'] ?? null) ? $state['dataset'] : [];
        $pageResult = is_array($state['page_result'] ?? null) ? $state['page_result'] : [];
        $workspaceId = (int)($workspace['id'] ?? 0);
        $workspaceManagerUserId = (int)($state['workspace_manager_user_id'] ?? 0);

        $contentResult = $this->workspaceChanges->run(function () use (
            $workspaceId,
            $workspaceManagerUserId,
            $dataset,
            $pageResult,
            $options,
            $jobId,
            $space,
            $actorUserId,
        ): array {
            $this->repository->setStage($jobId, 'acl');
            $this->applyNodeAcl(
                $workspaceId,
                $workspaceManagerUserId,
                $dataset,
                is_array($pageResult['nodes_by_source'] ?? null) ? $pageResult['nodes_by_source'] : [],
                $options,
            );
            $this->repository->setStage($jobId, 'comments');
            $comments = ($options['include_comments'] ?? false)
                ? $this->importComments(
                    $dataset,
                    is_array($pageResult['documents_by_source'] ?? null) ? $pageResult['documents_by_source'] : [],
                    $jobId,
                    $this->text($options['mapping_space_key'] ?? $space['source_key'] ?? ''),
                    $workspaceId,
                    $this->text($options['language'] ?? $this->config->defaultLanguage()),
                )
                : ['imported' => 0, 'skipped' => 0];

            // HR: Nastavivi koraci namjerno su odbacili međusignale. Ovaj
            // završni signal jednom obnavlja Search i druge izvedene indekse.
            // EN: Resumable steps intentionally discarded intermediate
            // signals. This final signal rebuilds Search and other indexes once.
            $this->workspaceChanges->publish(new WorkspaceContentChanged(
                $workspaceId,
                'bulk_content_changed',
                null,
                null,
                $actorUserId > 0 ? $actorUserId : null,
            ));

            return ['comments' => $comments];
        });

        $this->repository->setStage($jobId, 'links_and_search');
        $reconciled = $this->reconcileLinks();
        $includesReconciled = $this->reconcileIncludes(
            $this->text($space['source_key'] ?? ''),
            is_array($state['targets'] ?? null) ? $state['targets'] : [],
            is_array($pageResult['documents_by_source'] ?? null) ? $pageResult['documents_by_source'] : [],
        );
        $attachments = is_array($state['attachment_result'] ?? null) ? $state['attachment_result'] : [];
        $comments = is_array($contentResult['comments'] ?? null) ? $contentResult['comments'] : [];
        $summary = [
            'workspace_id' => $workspaceId,
            'workspace_slug' => $this->text($workspace['slug'] ?? ''),
            'pages_imported' => (int)($pageResult['imported'] ?? 0),
            'history_versions_imported' => (int)($pageResult['history'] ?? 0),
            'drafts_imported' => (int)($pageResult['drafts'] ?? 0),
            'deleted_pages_imported' => (int)($pageResult['deleted'] ?? 0),
            'attachments_imported' => (int)($attachments['imported'] ?? 0),
            'attachments_failed' => (int)($attachments['failed'] ?? 0),
            'comments_imported' => (int)($comments['imported'] ?? 0),
            'comments_skipped' => (int)($comments['skipped'] ?? 0),
            'links_reconciled' => $reconciled,
            'includes_reconciled' => $includesReconciled,
            'review_pages' => array_values(is_array($pageResult['review_pages'] ?? null) ? $pageResult['review_pages'] : []),
            'warnings' => array_values(array_unique([
                ...$this->strings($pageResult['warnings'] ?? []),
                ...$this->strings($dataset['warnings'] ?? []),
                ...$this->strings($attachments['warnings'] ?? []),
            ])),
        ];
        $this->repository->completeImport($jobId, $workspaceId, $summary);
        $job = $this->repository->jobByUuid($this->text($state['job_uuid'] ?? ''));
        $this->uploads->deleteArchive($job);
        $this->audit('simbioza_confluence_import.completed', [
            'action' => 'import_completed',
            'actor_user_id' => $actorUserId,
            'workspace_id' => $workspaceId,
            'target_type' => 'confluence_space',
            'target_id' => $this->text($space['source_key'] ?? ''),
            'target_label' => $this->text($space['name'] ?? ''),
            'metadata' => [
                'pages_imported' => $summary['pages_imported'],
                'attachments_imported' => $summary['attachments_imported'],
            ],
        ]);
        $this->removeDirectory($staging);

        return [
            'completed' => true,
            'progress' => 100,
            'phase' => 'completed',
            'summary' => $summary,
        ];
    }

    /**
     * HR: Spaja rezultate dvaju skupova stranica bez gubitka izvornih mapa.
     * EN: Merges two page batches without losing source mappings.
     *
     * @param array<string,mixed> $stored
     * @param array<string,mixed> $batch
     * @return array<string,mixed>
     */
    private function mergePageResults(array $stored, array $batch): array
    {
        $review = [];
        foreach ([...$this->rows($stored['review_pages'] ?? []), ...$this->rows($batch['review_pages'] ?? [])] as $page) {
            $key = $this->text($page['source_page_id'] ?? $page['url'] ?? '');
            if ($key !== '') {
                $review[$key] = $page;
            }
        }

        $nodes = is_array($stored['nodes_by_source'] ?? null) ? $stored['nodes_by_source'] : [];
        foreach (is_array($batch['nodes_by_source'] ?? null) ? $batch['nodes_by_source'] : [] as $sourceId => $nodeId) {
            $nodes[(string)$sourceId] = $nodeId;
        }
        $documents = is_array($stored['documents_by_source'] ?? null) ? $stored['documents_by_source'] : [];
        foreach (is_array($batch['documents_by_source'] ?? null) ? $batch['documents_by_source'] : [] as $sourceId => $documentKey) {
            $documents[(string)$sourceId] = $documentKey;
        }

        return [
            'imported' => (int)($stored['imported'] ?? 0) + (int)($batch['imported'] ?? 0),
            'history' => (int)($stored['history'] ?? 0) + (int)($batch['history'] ?? 0),
            'drafts' => (int)($stored['drafts'] ?? 0) + (int)($batch['drafts'] ?? 0),
            'deleted' => (int)($stored['deleted'] ?? 0) + (int)($batch['deleted'] ?? 0),
            'warnings' => array_values(array_unique([
                ...$this->strings($stored['warnings'] ?? []),
                ...$this->strings($batch['warnings'] ?? []),
            ])),
            'review_pages' => array_values($review),
            'nodes_by_source' => $nodes,
            'documents_by_source' => $documents,
        ];
    }

    /**
     * HR: Spaja URL-ove privitaka po stranici i izvornom nazivu.
     * EN: Merges attachment URLs by page and original filename.
     *
     * @return array<string,array<string,string>>
     */
    private function mergeAttachmentUrls(mixed $stored, mixed $batch): array
    {
        $result = is_array($stored) ? $stored : [];
        foreach (is_array($batch) ? $batch : [] as $pageId => $urls) {
            if (!is_array($urls)) {
                continue;
            }
            $key = (string)$pageId;
            $result[$key] = [
                ...(is_array($result[$key] ?? null) ? $result[$key] : []),
                ...$urls,
            ];
        }

        return $result;
    }

    /**
     * HR: Vraća poslovni napredak bez izlaganja velikog internog stanja.
     * EN: Returns business progress without exposing large internal state.
     *
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function progress(array $state): array
    {
        $phase = $this->text($state['phase'] ?? '');
        $attachmentTotal = max(1, (int)($state['attachment_result']['total'] ?? 0));
        $attachmentDone = min($attachmentTotal, (int)($state['attachment_offset'] ?? 0));
        $pageTotal = max(1, count(is_array($state['pages'] ?? null) ? $state['pages'] : []));
        $pageDone = count(is_array($state['page_result']['documents_by_source'] ?? null)
            ? $state['page_result']['documents_by_source']
            : []);
        $percent = match ($phase) {
            'attachments' => 5 + (int)round(30 * $attachmentDone / $attachmentTotal),
            'pages' => 35 + (int)round(55 * min($pageTotal, $pageDone) / $pageTotal),
            'finalizing' => 95,
            'completed' => 100,
            default => 2,
        };

        return [
            'completed' => $phase === 'completed',
            'progress' => max(0, min(100, $percent)),
            'phase' => $phase,
            'attachments_done' => $attachmentDone,
            'attachments_total' => (int)($state['attachment_result']['total'] ?? 0),
            'pages_done' => $pageDone,
            'pages_total' => count(is_array($state['pages'] ?? null) ? $state['pages'] : []),
        ];
    }

    /**
     * HR: Sprema nastavivo stanje uz ekskluzivni upis.
     * EN: Stores resumable state with an exclusive write.
     * @param array<string,mixed> $state
     */
    private function saveState(string $staging, array $state): void
    {
        $json = json_encode(
            $state,
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if (file_put_contents($this->statePath($staging), $json, LOCK_EX) === false) {
            throw new ConfluenceImportException(__('Stanje Confluence importa nije moguće spremiti.'));
        }
    }

    /**
     * HR: Učitava i provjerava spremljeno stanje faznog importa.
     * EN: Loads and validates persisted phased-import state.
     *
     * @return array<string,mixed>
     */
    private function loadState(string $staging): array
    {
        $json = file_get_contents($this->statePath($staging));
        $state = is_string($json) && $json !== '' ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : null;
        if (!is_array($state)) {
            throw new ConfluenceImportException(__('Stanje Confluence importa nije moguće učitati.'));
        }

        return $state;
    }

    /** HR: Vraća datoteku stanja jednog posla. EN: Returns one job's state file. */
    private function statePath(string $staging): string
    {
        return $staging . DIRECTORY_SEPARATOR . 'state.json';
    }

    /** HR: Zaključava jedan procesni korak bez čekanja. EN: Locks one processing step without waiting. */
    private function stateLock(string $staging): mixed
    {
        $handle = fopen($staging . DIRECTORY_SEPARATOR . 'state.lock', 'c+b');
        if (!is_resource($handle)) {
            throw new ConfluenceImportException(__('Confluence import nije moguće zaključati.'));
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        return $handle;
    }

    /**
     * HR: Normalizira mješoviti popis u neprazne tekstne vrijednosti.
     * EN: Normalizes a mixed list into non-empty string values.
     *
     * @return list<string>
     */
    private function strings(mixed $values): array
    {
        $result = [];
        foreach (is_array($values) ? $values : [] as $value) {
            if (is_scalar($value) && trim((string)$value) !== '') {
                $result[] = trim((string)$value);
            }
        }

        return $result;
    }

    /**
     * HR: Jednoliko bilježi neuspjeli sinkroni ili nastavivi import.
     * EN: Consistently records a failed synchronous or resumable import.
     *
     * @param array<string,mixed> $space
     * @param array<string,mixed>|null $workspace
     */
    private function recordFailure(
        int $jobId,
        string $jobUuid,
        int $actorUserId,
        array $space,
        ?array $workspace,
        \Throwable $throwable,
    ): void {
        if ($jobId > 0) {
            $this->repository->failImport($jobId, $throwable->getMessage());
        }
        $this->audit('simbioza_confluence_import.failed', [
            'action' => 'import_failed',
            'outcome' => 'failure',
            'actor_user_id' => $actorUserId,
            'workspace_id' => is_array($workspace) && is_numeric($workspace['id'] ?? null)
                ? (int)$workspace['id']
                : null,
            'target_type' => 'confluence_space',
            'target_id' => $this->text($space['source_key'] ?? ''),
            'target_label' => $this->text($space['name'] ?? ''),
            'metadata' => ['error_class' => $throwable::class],
        ]);
        $this->logger->error('Confluence import failed.', [
            'module' => 'simbioza-confluence-import',
            'job_uuid' => $jobUuid,
            'workspace_id' => is_array($workspace) ? $workspace['id'] ?? null : null,
            'exception' => $throwable,
        ]);
    }

    /**
     * HR: Ako PHP prekine zahtjev fatalnom pogreškom prije catch bloka, posao
     *     više ne ostaje zauvijek u stanju „u tijeku”.
     * EN: If PHP terminates the request with a fatal error before the catch
     *     block, the job no longer remains permanently in a running state.
     */
    private function registerFatalFailureHandler(int $jobId, string $jobUuid, bool &$handled): void
    {
        register_shutdown_function(function () use ($jobId, $jobUuid, &$handled): void {
            if ($handled) {
                return;
            }

            $error = error_get_last();
            if (!is_array($error)) {
                return;
            }

            $type = $error['type'];
            if (!in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            $message = __('PHP je neočekivano prekinuo Confluence import. Pojedinosti su zapisane u tehničkom logu.');
            try {
                $this->repository->failImport($jobId, $message);
            } catch (\Throwable) {
                // HR: Izvorna fatalna pogreška ostaje mjerodavna.
                // EN: The original fatal error remains authoritative.
            }
            $this->logger->critical('Confluence import was terminated by a fatal PHP error.', [
                'module' => 'simbioza-confluence-import',
                'job_uuid' => $jobUuid,
                'error_type' => $type,
                'error_message' => $error['message'],
                'error_file' => $error['file'],
                'error_line' => $error['line'],
            ]);
        });
    }

    /**
     * HR: Velikom potvrđenom importu daje vlastiti, konfigurabilni vremenski
     *     okvir umjesto zadanih 30 sekundi web zahtjeva.
     * EN: Gives a large confirmed import its own configurable execution window
     *     instead of the web request's default 30 seconds.
     */
    private function extendExecutionTime(): void
    {
        if (!function_exists('set_time_limit')) {
            return;
        }

        try {
            if (!set_time_limit($this->config->importExecutionTimeLimit())) {
                $this->logger->warning('Confluence import execution time limit could not be extended.', [
                    'module' => 'simbioza-confluence-import',
                ]);
            }
        } catch (\Throwable $throwable) {
            $this->logger->warning('Confluence import execution time limit could not be extended.', [
                'module' => 'simbioza-confluence-import',
                'exception' => $throwable,
            ]);
        }
    }

    /**
     * HR: Pronalazi ili izrađuje ciljano obično ili osobno područje.
     * EN: Finds or creates the target regular or Personal Workspace.
     *
     * @param array<string,mixed> $space
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function workspace(
        array $space,
        array $options,
        int $actorUserId,
        int $workspaceManagerUserId,
    ): array {
        if (($space['type'] ?? '') === 'personal') {
            $workspace = $this->personalWorkspaces->ensureForUser(
                $workspaceManagerUserId,
                $actorUserId,
                false,
            );
            if (!is_array($workspace)) {
                throw new ConfluenceImportException(__('Osobno područje ciljnog korisnika nije moguće pripremiti.'));
            }

            return $workspace;
        }

        $language = $this->text($options['language'] ?? $this->config->defaultLanguage());
        $name = $this->text($options['workspace_name'] ?? '');
        $description = sprintf(
            __('Uvezeno iz Confluence područja %1$s (%2$s).'),
            $this->text($space['name'] ?? ''),
            $this->text($space['source_key'] ?? ''),
        );

        return $this->workspaces->saveWorkspace([
            'name' => $name,
            'name_translations' => [$language => $name],
            'slug' => $options['workspace_slug'],
            'description' => $description,
            'description_translations' => [$language => $description],
            'visibility' => 'restricted',
            'tree_visibility' => 'inherit',
            'contents_visibility' => 'hidden',
        ], $actorUserId);
    }

    /**
     * HR: Određuje korisnika koji kroz obični ACL upravlja ciljanim područjem.
     * EN: Resolves the user who manages the target Workspace through regular ACL.
     *
     * @param array<string,mixed> $space
     */
    private function workspaceManagerUserId(array $space, int $actorUserId): int
    {
        if (($space['type'] ?? '') !== 'personal') {
            return $actorUserId;
        }

        $sourceOwner = $this->text($space['owner_source_key'] ?? '');
        $managerUserId = $this->repository->mappedUserId($sourceOwner);
        if ($managerUserId === null) {
            throw new ConfluenceImportException(
                __('Vlasnik osobnog Confluence područja mora biti potvrđeno mapiran.'),
            );
        }

        return $managerUserId;
    }

    /**
     * HR: Sprema samo mapiranja korisnika koja je administrator potvrdio.
     * EN: Stores only user mappings confirmed by the administrator.
     *
     * @param array<string,mixed> $scan
     * @param array<string,mixed> $options
     */
    private function saveIdentityMappings(array $scan, array $options, int $jobId): void
    {
        $map = is_array($options['identity_map'] ?? null) ? $options['identity_map'] : [];
        $create = is_array($options['identity_create'] ?? null) ? $options['identity_create'] : [];
        $targetUsers = array_values($this->users->listUsersForSetup());
        foreach ($this->rows($scan['users'] ?? []) as $sourceUser) {
            $sourceKey = $this->text($sourceUser['source_key'] ?? '');
            if ($sourceKey === '') {
                continue;
            }
            $targetId = is_numeric($map[$sourceKey] ?? null) && (int)$map[$sourceKey] > 0
                ? (int)$map[$sourceKey]
                : null;
            $mappedId = $this->repository->mappedUserId($sourceKey);
            if ($mappedId !== null) {
                if ($targetId !== null && $targetId !== $mappedId) {
                    throw new ConfluenceImportException(
                        __('Confluence identitet već je potvrđeno povezan s drugim korisnikom. Postojeće mapiranje nije promijenjeno.'),
                    );
                }
                // HR: Isti Confluence račun može se pojaviti u više space backupa; postojeće povezivanje uvijek se ponovno koristi.
                // EN: The same Confluence account can occur in multiple space backups; its existing mapping is always reused.
                $targetId = $mappedId;
            }
            if ($targetId !== null && !is_array($this->users->findByIdIncludingInactive($targetId))) {
                $targetId = null;
            }
            if ($mappedId === null && $targetId === null && $this->boolean($create[$sourceKey] ?? false)) {
                $existingId = $this->principalMatcher->suggestUserId($sourceUser, $targetUsers);
                if ($existingId !== null) {
                    throw new ConfluenceImportException(
                        __('Korisnik s istim emailom ili login oznakom već postoji. Odaberite postojeći račun umjesto izrade novoga.'),
                    );
                }

                [$firstName, $lastName] = $this->sourceUserNames($sourceUser);
                $created = $this->users->createInactiveUserWithoutLogin(
                    $this->principalMatcher->inactiveLoginIdentifier($sourceUser),
                    $this->sourceUserDisplayName($sourceUser),
                    $firstName,
                    $lastName,
                    $this->text($sourceUser['email'] ?? ''),
                );
                $targetId = is_numeric($created['id'] ?? null) ? (int)$created['id'] : null;
                if ($targetId === null || $targetId <= 0) {
                    throw new ConfluenceImportException(__('Neaktivnog korisnika nije moguće izraditi.'));
                }
                $targetUsers[] = $created;
            }
            if ($targetId !== null) {
                $this->enrichImportedUserProfile($targetId, $sourceUser);
            }
            $this->repository->mapIdentity($sourceUser, $targetId, $targetId !== null, $jobId);
        }
    }

    /**
     * HR: Vraća najbolje dostupno prikazno ime izvornog korisnika.
     * EN: Returns the best available source-user display name.
     *
     * @param array<string,mixed> $sourceUser
     */
    private function sourceUserDisplayName(array $sourceUser): string
    {
        [$firstName, $lastName] = $this->sourceUserNames($sourceUser);
        $fullName = trim($firstName . ' ' . $lastName);
        if ($fullName !== '') {
            return $fullName;
        }

        foreach (['display_name', 'username', 'email', 'source_key'] as $field) {
            $value = $this->text($sourceUser[$field] ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        return __('Uvezeni Confluence korisnik');
    }

    /**
     * HR: Vraća izvorno ime i prezime, a kod starijih Confluence backupa bez
     *     tih polja koristi čitljive dijelove adrese e-pošte.
     * EN: Returns the source first and last name, falling back to readable
     *     email-address parts for older Confluence backups without those fields.
     *
     * @param array<string,mixed> $sourceUser
     * @return array{0:string,1:string}
     */
    private function sourceUserNames(array $sourceUser): array
    {
        $firstName = $this->text($sourceUser['first_name'] ?? '');
        $lastName = $this->text($sourceUser['last_name'] ?? '');
        if ($firstName !== '' || $lastName !== '') {
            return [$firstName, $lastName];
        }

        $email = strtolower($this->text($sourceUser['email'] ?? ''));
        $localPart = explode('@', $email, 2)[0] ?? '';
        $parts = array_values(array_filter(preg_split('/[._-]+/', $localPart) ?: []));
        if (count($parts) < 2) {
            return ['', ''];
        }

        return [ucfirst($parts[0]), ucfirst($parts[count($parts) - 1])];
    }

    /**
     * HR: Dopunjava samo neaktivni migracijski račun kojem nedostaju prikazni
     *     podaci; postojeći stvarni profil korisnika nikada se ne prepisuje.
     * EN: Enriches only an inactive migration account with missing display
     *     data; an existing real user profile is never overwritten.
     *
     * @param array<string,mixed> $sourceUser
     */
    private function enrichImportedUserProfile(int $targetUserId, array $sourceUser): void
    {
        $target = $this->users->findByIdIncludingInactive($targetUserId);
        if (
            !is_array($target)
            || (bool)($target['is_active'] ?? true)
            || $this->text($target['auth_source'] ?? '') !== AuthUserService::AUTH_SOURCE_PENDING
        ) {
            return;
        }

        [$firstName, $lastName] = $this->sourceUserNames($sourceUser);
        $values = [];
        if ($this->text($target['first_name'] ?? '') === '' && $firstName !== '') {
            $values['first_name'] = $firstName;
        }
        if ($this->text($target['last_name'] ?? '') === '' && $lastName !== '') {
            $values['last_name'] = $lastName;
        }
        $displayName = trim($firstName . ' ' . $lastName);
        if (
            $displayName !== ''
            && in_array(
                $this->text($target['display_name'] ?? ''),
                ['', $this->text($target['login_identifier'] ?? '')],
                true,
            )
        ) {
            $values['display_name'] = $displayName;
        }
        if ($values !== []) {
            $this->userAttributes->saveValuesForUser($targetUserId, $values);
        }
    }

    /**
     * HR: Potvrđuje postojeće mapiranje ili izričito izrađuje običnu ciljnu grupu.
     * Nikada ne prenosi Confluence članstva niti dodjeljuje administratorski status.
     * EN: Confirms an existing mapping or explicitly creates a regular target
     * group. It never transfers Confluence memberships or grants administrator status.
     *
     * @param array<string,mixed> $scan
     * @param array<string,mixed> $options
     * @return array<string,int>
     */
    private function saveGroupMappings(array $scan, array $options, int $jobId): array
    {
        $requested = is_array($options['group_map'] ?? null) ? $options['group_map'] : [];
        $create = is_array($options['group_create'] ?? null) ? $options['group_create'] : [];
        $available = [];
        foreach ($this->groups->listGroups() as $group) {
            $id = is_numeric($group['id'] ?? null) ? (int)$group['id'] : 0;
            if ($id > 0) {
                $available[$id] = true;
            }
        }

        $resolved = [];
        foreach ($this->rows($scan['groups'] ?? []) as $sourceGroup) {
            $name = $this->text($sourceGroup['source_name'] ?? '');
            if ($name === '') {
                continue;
            }

            $targetId = is_numeric($requested[$name] ?? null) ? (int)$requested[$name] : 0;
            $mappedId = $this->repository->mappedGroupId($name);
            if ($mappedId !== null) {
                if ($targetId > 0 && $targetId !== $mappedId) {
                    throw new ConfluenceImportException(
                        __('Confluence grupa već je potvrđeno povezana s drugom grupom. Postojeće mapiranje nije promijenjeno.'),
                    );
                }
                $targetId = $mappedId;
            }
            if ($targetId > 0 && !isset($available[$targetId])) {
                $targetId = 0;
            }
            if ($mappedId === null && $targetId <= 0 && $this->boolean($create[$name] ?? false)) {
                $created = $this->groups->createGroupForApi($name, true);
                $targetId = is_numeric($created['id'] ?? null) ? (int)$created['id'] : 0;
                if ($targetId > 0) {
                    $available[$targetId] = true;
                }
            }

            $confirmed = $targetId > 0;
            $this->repository->mapGroup($name, $confirmed ? $targetId : null, $confirmed, $jobId);
            if ($confirmed) {
                $resolved[$name] = $targetId;
            }
        }

        return $resolved;
    }

    /**
     * HR: Pretvara izvorne ovlasti područja u zatvoreni ciljni ACL.
     * EN: Converts source-space permissions into a fail-closed target ACL.
     *
     * @param array<string,mixed> $scan
     * @param array<string,mixed> $options
     */
    private function applyWorkspaceAcl(int $workspaceId, int $managerUserId, array $scan, array $options): void
    {
        // HR: Upravitelj je sigurni fail-closed subjekt. Njegov ACL red osigurava da
        // područje s potpuno neriješenim Confluence identitetima ne postane
        // dostupno drugim korisnicima kroz nasljeđivanje.
        // EN: The manager is the safe fail-closed subject. Their ACL row ensures a
        // space with entirely unresolved Confluence identities never becomes
        // accessible to other users through inheritance.
        $acl = [
            WorkspaceRepository::SUBJECT_USER => [
                $managerUserId => $this->completeRights([
                    'can_view' => true,
                    'can_add' => true,
                    'can_edit' => true,
                    'can_publish' => true,
                    'can_delete' => true,
                    'can_manage' => true,
                ]),
            ],
        ];
        foreach ($this->rows($scan['space_permissions'] ?? []) as $permission) {
            $rights = $this->spacePermissionRights($this->text($permission['type'] ?? ''));
            if ($rights === []) {
                continue;
            }

            $sourceUser = $this->text($permission['user_source_key'] ?? '');
            $groupName = $this->text($permission['group_name'] ?? '');
            $type = '';
            $id = 0;
            if ($sourceUser !== '') {
                $type = WorkspaceRepository::SUBJECT_USER;
                $id = $this->repository->mappedUserId($sourceUser) ?? 0;
            } elseif ($groupName !== '') {
                $type = WorkspaceRepository::SUBJECT_GROUP;
                $groupMap = is_array($options['group_map'] ?? null) ? $options['group_map'] : [];
                $id = is_numeric($groupMap[$groupName] ?? null) ? (int)$groupMap[$groupName] : 0;
            } elseif (strtoupper($this->text($permission['type'] ?? '')) === 'VIEWSPACE') {
                $type = WorkspaceRepository::SUBJECT_PUBLIC;
                $id = WorkspaceRepository::BUILT_IN_SUBJECT_ID;
                $rights = ['can_view' => true];
            }

            if ($type === '' || $id <= 0) {
                continue;
            }

            $acl[$type][$id] = $this->mergeRights($acl[$type][$id] ?? [], $rights);
        }

        // HR: Stranična ograničenja mogu navesti subjekt koji nema zaseban
        // Confluence SpacePermission redak. Dajemo mu samo bazni pregled kako
        // bi ga kasnije page ACL mogao dodatno ograničiti, nikada proširiti.
        // EN: Page restrictions may name a subject without a separate
        // Confluence SpacePermission row. Grant only base view so page ACL can
        // restrict it further, never broaden it.
        foreach ($this->rows($scan['content_permissions'] ?? []) as $permission) {
            $sourceUser = $this->text($permission['user_source_key'] ?? '');
            $groupName = $this->text($permission['group_name'] ?? '');
            if ($sourceUser !== '') {
                $id = $this->repository->mappedUserId($sourceUser) ?? 0;
                if ($id > 0) {
                    $acl[WorkspaceRepository::SUBJECT_USER][$id] = $this->mergeRights(
                        $acl[WorkspaceRepository::SUBJECT_USER][$id] ?? [],
                        ['can_view' => true],
                    );
                }
            } elseif ($groupName !== '') {
                $groupMap = is_array($options['group_map'] ?? null) ? $options['group_map'] : [];
                $id = is_numeric($groupMap[$groupName] ?? null) ? (int)$groupMap[$groupName] : 0;
                if ($id > 0) {
                    $acl[WorkspaceRepository::SUBJECT_GROUP][$id] = $this->mergeRights(
                        $acl[WorkspaceRepository::SUBJECT_GROUP][$id] ?? [],
                        ['can_view' => true],
                    );
                }
            }
        }

        $this->workspaces->replaceWorkspaceAcl($workspaceId, $acl);
    }

    /**
     * HR: Jednim prolazom priprema tijela i povezane objekte za uvoz.
     * EN: Stages bodies and related objects for import in one pass.
     *
     * @return array{pages:list<array<string,mixed>>,attachments:list<array<string,mixed>>,properties:array<string,array<string,string>>,comments:list<array<string,mixed>>,permission_sets:list<array<string,mixed>>,permissions:array<string,array<string,mixed>>,page_labels:array<string,list<string>>,body_directory:string,warnings:list<string>}
     */
    private function stageDataset(string $archivePath, string $staging): array
    {
        $bodyDirectory = $staging . DIRECTORY_SEPARATOR . 'bodies';
        $this->ensureDirectory($bodyDirectory);
        $pages = [];
        $attachments = [];
        $properties = [];
        $comments = [];
        $permissionSets = [];
        $permissions = [];
        $labels = [];
        $labellings = [];
        $warnings = [];

        foreach ($this->reader->objects($archivePath) as $object) {
            if ($object->className === 'BodyContent') {
                $contentId = $object->reference('content');
                if ($contentId !== '') {
                    file_put_contents($bodyDirectory . DIRECTORY_SEPARATOR . $this->safeSourceId($contentId) . '.html', $object->string('body'), LOCK_EX);
                }
                continue;
            }

            if ($object->className === 'Page') {
                $pages[] = $this->page($object);
                continue;
            }

            if ($object->className === 'Attachment') {
                $attachments[] = $this->attachment($object);
                continue;
            }

            if ($object->className === 'ContentProperty') {
                $contentId = $object->reference('content');
                $name = strtoupper($object->string('name'));
                $value = $object->string('stringValue') ?: $object->string('longValue');
                if ($contentId !== '' && $name !== '') {
                    $properties[$contentId][$name] = $value;
                }
                continue;
            }

            if ($object->className === 'Comment') {
                $comments[] = $this->comment($object);
                continue;
            }

            if ($object->className === 'ContentPermissionSet') {
                $permissionSets[] = [
                    'source_id' => $object->string('id'),
                    'type' => strtolower($object->string('type')),
                    'page_id' => $object->reference('owningContent'),
                    'permission_ids' => $object->references('contentPermissions'),
                ];
                continue;
            }

            if ($object->className === 'ContentPermission') {
                $permissions[$object->string('id')] = [
                    'source_id' => $object->string('id'),
                    'set_id' => $object->reference('owningSet'),
                    'user_source_key' => $object->reference('userSubject'),
                    'group_name' => $object->string('group') ?: $object->string('groupName'),
                ];
                continue;
            }

            if ($object->className === 'Label') {
                $labelId = $object->string('id');
                if ($labelId !== '') {
                    $labels[$labelId] = $object->string('name');
                }
                continue;
            }

            if ($object->className === 'Labelling') {
                $labellings[] = [
                    'page_id' => $object->reference('content') ?: $object->string('labelableId'),
                    'label_id' => $object->reference('label'),
                ];
            }
        }

        $pageLabels = [];
        foreach ($labellings as $labelling) {
            $pageId = $this->text($labelling['page_id'] ?? '');
            $label = $this->text($labels[$this->text($labelling['label_id'] ?? '')] ?? '');
            if ($pageId !== '' && $label !== '') {
                $pageLabels[$pageId][] = $label;
            }
        }

        return [
            'pages' => $pages,
            'attachments' => $attachments,
            'properties' => $properties,
            'comments' => $comments,
            'permission_sets' => $permissionSets,
            'permissions' => $permissions,
            'page_labels' => $pageLabels,
            'body_directory' => $bodyDirectory,
            'warnings' => $warnings,
        ];
    }

    /**
     * HR: Grupira odabrane verzije stranica prema logičkom izvornom ID-u.
     * EN: Groups selected page versions by logical source ID.
     *
     * @param list<array<string,mixed>> $pages
     * @param array<string,mixed> $options
     * @return array<string,list<array<string,mixed>>>
     */
    private function selectedPageGroups(array $pages, array $options): array
    {
        $groups = [];
        foreach ($pages as $page) {
            $logicalId = $this->text($page['logical_source_id'] ?? '');
            $status = $this->text($page['status'] ?? 'current');
            $isHistorical = $this->text($page['original_version_id'] ?? '') !== '';
            if ($logicalId === '' || $this->text($page['title'] ?? '') === '') {
                continue;
            }
            if ($status === 'draft' && !$options['include_drafts']) {
                continue;
            }
            if ($status === 'deleted' && !$options['include_deleted']) {
                continue;
            }
            if ($isHistorical && $status !== 'draft' && !$options['include_history']) {
                continue;
            }
            $groups[$logicalId][] = $page;
        }

        foreach ($groups as &$versions) {
            usort($versions, static fn(array $left, array $right): int => (int)($left['version'] ?? 0) <=> (int)($right['version'] ?? 0));
        }
        unset($versions);

        return $groups;
    }

    /**
     * HR: Unaprijed određuje jedinstvene slugove i ključeve ciljnih stranica.
     * EN: Precomputes unique slugs and document keys for target pages.
     *
     * @param array<string,list<array<string,mixed>>> $pages
     * @param array<string,mixed> $workspace
     * @param list<array<string,mixed>> $allPages
     * @return array<string,array<string,mixed>>
     */
    private function plannedTargets(array $pages, array $workspace, array $allPages): array
    {
        $used = [];
        $result = [];
        $workspaceSlug = $this->text($workspace['slug'] ?? '');
        $normalizedParents = $this->pageHierarchy->normalizedParents($pages, $allPages);
        foreach ($pages as $logicalId => $versions) {
            $current = $this->currentPage($versions);
            $title = $this->text($current['title'] ?? '');
            $slug = $this->pageSlugger->unique(
                $this->editor->slugFromTitle($title),
                'page-' . $logicalId,
                $used,
            );
            $result[$logicalId] = [
                'slug' => $slug,
                'title' => $title,
                'parent_id' => $normalizedParents[(string)$logicalId] ?? '',
                'sort_order' => (int)($current['position'] ?? 100),
                'status' => $this->text($current['status'] ?? 'current'),
                'path' => $this->nodePath($workspaceSlug, $slug),
            ];
        }

        return $result;
    }

    /**
     * HR: Kopira aktualne privitke u privatnu pohranu i bilježi neuspjele vrste.
     * EN: Copies current attachments into private storage and records failed types.
     *
     * @param array<string,mixed> $dataset
     * @param array<string,mixed> $workspace
     * @return array{urls:array<string,array<string,string>>,imported:int,failed:int,warnings:list<string>}
     */
    private function importAttachments(
        string $archivePath,
        array $dataset,
        array $workspace,
        int $jobId,
        int $offset = 0,
        int $limit = PHP_INT_MAX,
    ): array {
        $latest = [];
        foreach ($this->rows($dataset['attachments'] ?? []) as $attachment) {
            if (($attachment['status'] ?? 'current') !== 'current') {
                continue;
            }
            $logicalId = $this->text($attachment['logical_source_id'] ?? '');
            if (!isset($latest[$logicalId]) || (int)$attachment['version'] > (int)$latest[$logicalId]['version']) {
                $latest[$logicalId] = $attachment;
            }
        }

        $urls = [];
        $imported = 0;
        $failed = 0;
        $warnings = [];
        $workspaceId = (int)($workspace['id'] ?? 0);
        $properties = is_array($dataset['properties'] ?? null) ? $dataset['properties'] : [];
        ksort($latest);
        $all = array_values($latest);
        $total = count($all);
        $slice = array_slice($all, max(0, $offset), max(1, $limit));
        foreach ($slice as $attachment) {
            $sourceId = $this->text($attachment['source_id'] ?? '');
            $pageId = $this->text($attachment['page_id'] ?? '');
            if ($sourceId === '' || $pageId === '') {
                continue;
            }
            $version = max(1, (int)($attachment['version'] ?? 1));
            $filename = basename($this->text($attachment['title'] ?? 'attachment'));
            $existing = $this->repository->attachmentBySourceVersion($jobId, $sourceId, $version);
            $existingPath = is_array($existing) ? $this->text($existing['storage_path'] ?? '') : '';
            if (($existing['status'] ?? '') === 'stored' && $existingPath !== '' && is_file($existingPath)) {
                $saved = $this->repository->recordAttachment([
                    'source_attachment_id' => $sourceId,
                    'logical_source_id' => $this->text($attachment['logical_source_id'] ?? $sourceId),
                    'source_page_id' => $pageId,
                    'source_version' => $version,
                    'original_name' => $filename,
                    'mime_type' => $this->text($existing['mime_type'] ?? 'application/octet-stream'),
                    'file_size' => (int)($existing['file_size'] ?? 0),
                    'storage_path' => $existingPath,
                    'workspace_id' => $workspaceId,
                    'status' => 'stored',
                ], $jobId);
                $urls[$pageId][$filename] = $this->attachmentPath($this->text($saved['uuid'] ?? ''));
                ++$imported;

                continue;
            }

            $storedName = bin2hex(random_bytes(24)) . '.bin';
            $this->ensureDirectory($this->config->attachmentDirectory());
            $path = $this->config->attachmentDirectory() . DIRECTORY_SEPARATOR . $storedName;
            $metadata = is_array($properties[$sourceId] ?? null) ? $properties[$sourceId] : [];
            $record = [
                'source_attachment_id' => $sourceId,
                'logical_source_id' => $this->text($attachment['logical_source_id'] ?? $sourceId),
                'source_page_id' => $pageId,
                'source_version' => $version,
                'original_name' => $filename,
                'mime_type' => $this->text($metadata['MEDIA_TYPE'] ?? 'application/octet-stream'),
                'file_size' => is_numeric($metadata['FILESIZE'] ?? null) ? (int)$metadata['FILESIZE'] : 0,
                'storage_path' => null,
                'workspace_id' => $workspaceId,
                'status' => 'failed',
            ];
            try {
                $this->archive->copyAttachment($archivePath, $pageId, $sourceId, $version, $path);
                $record['storage_path'] = $path;
                $record['file_size'] = filesize($path) ?: 0;
                $record['mime_type'] = $this->detectedMime($path, $record['mime_type']);
                $record['status'] = 'stored';
            } catch (\Throwable $throwable) {
                $record['error_message'] = $throwable->getMessage();
                ++$failed;
                $warnings[] = sprintf(
                    __('Privitak "%1$s" nije uvezen: %2$s'),
                    $filename,
                    $throwable->getMessage(),
                );
            }

            $saved = $this->repository->recordAttachment($record, $jobId);
            if (($saved['status'] ?? '') === 'stored') {
                $urls[$pageId][$filename] = $this->attachmentPath($this->text($saved['uuid'] ?? ''));
                ++$imported;
            }
        }

        return [
            'urls' => $urls,
            'imported' => $imported,
            'failed' => $failed,
            'warnings' => $warnings,
            'next_offset' => min($total, max(0, $offset) + count($slice)),
            'total' => $total,
        ];
    }

    /**
     * HR: Uvozi stablo, verzije, statuse objave i izvorna mapiranja stranica.
     * EN: Imports the tree, versions, publication states, and source page mappings.
     *
     * @param array<string,list<array<string,mixed>>> $pages
     * @param array<string,mixed> $dataset
     * @param array<string,array<string,mixed>> $targets
     * @param array<string,array<string,string>> $attachments
     * @param array<string,mixed> $space
     * @param array<string,mixed> $workspace
     * @param array<string,mixed> $options
     * @param array<string,array<string,mixed>> $nodesBySource
     * @param array<string,array<string,mixed>> $documentsBySource
     * @return array<string,mixed>
     */
    private function importPages(
        array $pages,
        array $dataset,
        array $targets,
        array $attachments,
        array $space,
        array $workspace,
        int $jobId,
        int $actorUserId,
        array $options,
        array $nodesBySource = [],
        array $documentsBySource = [],
        int $maxPages = PHP_INT_MAX,
    ): array {
        $workspaceId = (int)($workspace['id'] ?? 0);
        $sourceSpaceKey = $this->text($space['source_key'] ?? '');
        $mappingSpaceKey = $this->text($options['mapping_space_key'] ?? $sourceSpaceKey);
        $homePageId = $this->homepageLogicalId($pages, $this->text($space['home_page_id'] ?? ''));
        $language = $this->text($options['language'] ?? $this->config->defaultLanguage());
        $localById = [];
        $localByTitle = [];
        $macroPages = [];
        $macroUsers = [];
        $macroCalendars = [];
        foreach ($this->rows($dataset['calendars'] ?? []) as $calendar) {
            $sourceUuid = $this->text($calendar['source_uuid'] ?? '');
            if ($sourceUuid !== '') {
                $macroCalendars[$sourceUuid] = $this->text($calendar['name'] ?? '');
            }
        }
        foreach ($this->rows($dataset['users'] ?? []) as $sourceUser) {
            $displayName = $this->sourceUserDisplayName($sourceUser);
            $sourceKey = $this->text($sourceUser['source_key'] ?? '');
            $mappedUserId = $sourceKey !== '' ? $this->repository->mappedUserId($sourceKey) : null;
            $mappedUser = $mappedUserId !== null
                ? $this->users->findByIdIncludingInactive($mappedUserId)
                : null;
            if (is_array($mappedUser)) {
                $displayName = $this->text(
                    $mappedUser['display_name']
                    ?? $mappedUser['login_identifier']
                    ?? $displayName,
                );
            }
            foreach (['source_key', 'username', 'email'] as $identityField) {
                $identity = $this->text($sourceUser[$identityField] ?? '');
                if ($identity !== '') {
                    $macroUsers[$identity] = $displayName;
                }
            }
        }
        foreach ($targets as $logicalId => $target) {
            $localById[$logicalId] = $this->text($target['path'] ?? '#');
            $localByTitle[$this->text($target['title'] ?? '')] = $this->text($target['path'] ?? '#');
            $versions = $pages[(string)$logicalId] ?? [];
            $current = $this->currentPage($versions);
            $sourceLabels = [];
            foreach ($this->pageSourceIds((string)$logicalId, $versions) as $labelPageId) {
                foreach ((array)($dataset['page_labels'][$labelPageId] ?? []) as $sourceLabel) {
                    if (is_scalar($sourceLabel) && trim((string)$sourceLabel) !== '') {
                        $sourceLabels[] = trim((string)$sourceLabel);
                    }
                }
            }
            $bodyPath = $this->text($dataset['body_directory'] ?? '') . DIRECTORY_SEPARATOR
                . $this->safeSourceId($this->text($current['source_id'] ?? '')) . '.html';
            $sourceBody = is_file($bodyPath) ? file_get_contents($bodyPath) : '';
            $creatorSourceKey = $this->text($current['creator_source_key'] ?? '');
            $macroPages[(string)$logicalId] = [
                'title' => $this->text($target['title'] ?? ''),
                'path' => $this->text($target['path'] ?? '#'),
                'parent_id' => $this->text($target['parent_id'] ?? ''),
                'sort_order' => (int)($target['sort_order'] ?? 100),
                'workspace_slug' => $this->text($workspace['slug'] ?? ''),
                'node_slug' => $this->text($target['slug'] ?? ''),
                'labels' => array_values(array_unique($sourceLabels)),
                'creator' => $macroUsers[$creatorSourceKey] ?? $creatorSourceKey,
                'updated_at' => $this->text($current['updated_at'] ?? ''),
                'tasks' => is_string($sourceBody) && str_contains($sourceBody, '<ac:task')
                    ? $this->converter->taskSummaries($sourceBody, (string)$logicalId)
                    : [],
            ];
        }

        $pending = [];
        foreach ($pages as $logicalId => $versions) {
            if (!isset($documentsBySource[(string)$logicalId])) {
                $pending[(string)$logicalId] = $versions;
            }
        }
        $imported = 0;
        $history = 0;
        $drafts = 0;
        $deleted = 0;
        $warnings = [];
        $reviewPages = [];
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $logicalId => $versions) {
                $target = $targets[$logicalId];
                $parentSource = $this->text($target['parent_id'] ?? '');
                if ($parentSource !== '' && isset($pending[$parentSource])) {
                    continue;
                }

                $parentNodeId = $parentSource !== '' ? ($nodesBySource[$parentSource] ?? null) : null;
                if ($parentSource !== '' && $parentNodeId === null) {
                    $warnings[] = sprintf(__('Roditelj %s nije uvezen; stranica je postavljena u korijen.'), $parentSource);
                }

                $publishable = array_values(array_filter(
                    $versions,
                    static fn(array $version): bool => ($version['status'] ?? 'current') !== 'draft',
                ));
                $draftVersions = array_values(array_filter(
                    $versions,
                    static fn(array $version): bool => ($version['status'] ?? '') === 'draft',
                ));
                $first = $publishable[0] ?? $draftVersions[0] ?? null;
                if (!is_array($first)) {
                    unset($pending[$logicalId]);
                    continue;
                }

                $converted = $this->convertedBody(
                    $dataset,
                    $first,
                    $sourceSpaceKey,
                    $jobId,
                    $attachments[$logicalId] ?? $attachments[$this->text($first['source_id'] ?? '')] ?? [],
                    $localById,
                    $localByTitle,
                    new ConfluenceMacroContext(
                        $logicalId,
                        $macroPages,
                        $attachments[$logicalId] ?? [],
                        $macroUsers,
                        $macroCalendars,
                    ),
                );
                $warnings = [...$warnings, ...$converted['warnings']];
                $this->recordPageReview($reviewPages, $converted['review_issues'], $first, $target);
                $title = $this->text($first['title'] ?? $target['title']);
                $document = $this->editor->createWithContent(
                    $title,
                    $this->text($target['slug'] ?? ''),
                    $language,
                    $converted['html'],
                );
                $versionNumber = $this->editor->currentVersionNumber($document->id, $language);
                $node = $this->editorWorkspace->attachDocument(
                    $this->text($workspace['slug'] ?? ''),
                    is_int($parentNodeId) ? $parentNodeId : 0,
                    $document->id,
                    $title,
                    $this->text($target['slug'] ?? ''),
                    $language,
                    $versionNumber,
                );
                $this->editor->markVersionDraft($document->id, $language, $versionNumber);
                $node = $this->workspaces->saveNode($workspaceId, [
                    'id' => $node['id'] ?? null,
                    'title' => $this->text($target['title'] ?? ''),
                    'title_translations' => [
                        $language => $this->text($target['title'] ?? ''),
                    ],
                    'slug' => $this->text($target['slug'] ?? ''),
                    'node_type' => 'document',
                    'document_key' => $document->id,
                    'parent_id' => $parentNodeId,
                    'sort_order' => (int)($target['sort_order'] ?? 100),
                    'is_homepage' => $this->text($logicalId) === $homePageId,
                ], $actorUserId);
                $nodeId = (int)($node['id'] ?? 0);
                $sourceLabels = [];
                foreach ($this->pageSourceIds($logicalId, $versions) as $labelPageId) {
                    foreach ((array)($dataset['page_labels'][$labelPageId] ?? []) as $sourceLabel) {
                        if (is_scalar($sourceLabel) && trim((string)$sourceLabel) !== '') {
                            $sourceLabels[] = trim((string)$sourceLabel);
                        }
                    }
                }
                $this->workspaces->replaceNodeLabels($nodeId, array_values(array_unique($sourceLabels)));
                $this->workspaces->replaceNodeProperties($nodeId, $converted['properties']);
                $nodesBySource[$logicalId] = $nodeId;
                $documentsBySource[$logicalId] = $document->id;
                $registeredAttachments = 0;
                foreach ($this->pageSourceIds($logicalId, $versions) as $attachmentPageId) {
                    $this->repository->attachImportedAttachmentsToPage(
                        $attachmentPageId,
                        $workspaceId,
                        $nodeId,
                        $document->id,
                    );
                    $registeredAttachments += $this->registerImportedAttachments(
                        $attachmentPageId,
                        $workspaceId,
                        $nodeId,
                        $document->id,
                        $actorUserId,
                    );
                }
                if ($registeredAttachments > 0) {
                    // HR: Confluence privitci pripadaju stranici i moraju biti vidljivi svakome tko smije vidjeti tu stranicu.
                    // EN: Confluence attachments belong to the page and must be visible to everyone allowed to view that page.
                    $this->editor->updateAttachmentVisibility(
                        $document->id,
                        EditorService::ATTACHMENT_VISIBILITY_PUBLIC,
                    );
                }
                if ($publishable !== []) {
                    $versionNumber = $this->publishVersion($document->id, $language, $nodeId, $actorUserId);
                    $this->attributeImportedVersion($document->id, $language, $versionNumber, $first, $actorUserId, true);
                    $this->mapImportedVersion($first, $mappingSpaceKey, $workspaceId, $node, $jobId);
                } else {
                    $versionNumber = $this->editor->currentVersionNumber($document->id, $language);
                    $this->editor->markVersionDraft($document->id, $language, $versionNumber);
                    $this->workflow->markDocumentDraft($document->id, $language, $versionNumber, $actorUserId);
                    $this->attributeImportedVersion($document->id, $language, $versionNumber, $first, $actorUserId, false);
                    $this->mapImportedVersion($first, $mappingSpaceKey, $workspaceId, $node, $jobId, 'draft');
                    ++$drafts;
                }
                ++$imported;

                foreach (array_slice($publishable, 1) as $version) {
                    $converted = $this->convertedBody(
                        $dataset,
                        $version,
                        $sourceSpaceKey,
                        $jobId,
                        $attachments[$logicalId] ?? [],
                        $localById,
                        $localByTitle,
                        new ConfluenceMacroContext(
                            $logicalId,
                            $macroPages,
                            $attachments[$logicalId] ?? [],
                            $macroUsers,
                            $macroCalendars,
                        ),
                    );
                    $warnings = [...$warnings, ...$converted['warnings']];
                    $this->recordPageReview($reviewPages, $converted['review_issues'], $version, $target);
                    $this->editor->save($document->id, $language, $this->text($version['title'] ?? ''), $converted['html']);
                    $versionNumber = $this->publishVersion($document->id, $language, $nodeId, $actorUserId);
                    $this->attributeImportedVersion($document->id, $language, $versionNumber, $version, $actorUserId, true);
                    $this->mapImportedVersion($version, $mappingSpaceKey, $workspaceId, $node, $jobId);
                    if ($this->text($version['original_version_id'] ?? '') !== '') {
                        ++$history;
                    }
                }

                if ($draftVersions !== [] && $publishable !== []) {
                    $draft = $draftVersions[count($draftVersions) - 1];
                    $converted = $this->convertedBody(
                        $dataset,
                        $draft,
                        $sourceSpaceKey,
                        $jobId,
                        $attachments[$logicalId] ?? [],
                        $localById,
                        $localByTitle,
                        new ConfluenceMacroContext(
                            $logicalId,
                            $macroPages,
                            $attachments[$logicalId] ?? [],
                            $macroUsers,
                            $macroCalendars,
                        ),
                    );
                    $warnings = [...$warnings, ...$converted['warnings']];
                    $this->recordPageReview($reviewPages, $converted['review_issues'], $draft, $target);
                    $this->editor->save($document->id, $language, $this->text($draft['title'] ?? ''), $converted['html']);
                    $versionNumber = $this->editor->currentVersionNumber($document->id, $language);
                    $this->workflow->markDocumentDraft($document->id, $language, $versionNumber, $actorUserId);
                    $this->attributeImportedVersion($document->id, $language, $versionNumber, $draft, $actorUserId, false);
                    $this->mapImportedVersion($draft, $mappingSpaceKey, $workspaceId, $node, $jobId, 'draft');
                    ++$drafts;
                }

                $current = $this->currentPage($versions);
                $this->invokeImportAttribution('attributeDocument', [
                    $document->id,
                    $this->sourceCreatorUserId($versions, $actorUserId),
                    $this->sourceVersionAuthorUserId($current, $actorUserId),
                    $this->text($current['created_at'] ?? ''),
                    $this->text($current['updated_at'] ?? ''),
                ]);

                if (($target['status'] ?? '') === 'deleted') {
                    $this->workspaces->disableNodeTree($workspaceId, $nodeId, $actorUserId);
                    $this->editor->deleteDocument($document->id);
                    ++$deleted;
                } else {
                    // HR: Nakon registracije privitaka priprema prikazne kopije slika; original ostaje dostupan na klik.
                    // EN: After attachment registration, prepares display image copies; the original remains available on click.
                    $this->imageVariants->prewarmDocument($document->id);
                }

                unset($pending[$logicalId]);
                $progress = true;
                if ($imported >= max(1, $maxPages)) {
                    break 2;
                }
            }

            if (!$progress) {
                // HR: Ciklus ili nedostajući roditelj ne smije zaustaviti sadržaj; preostale stranice stavljamo u korijen.
                // EN: A cycle or missing parent must not lose content; remaining pages are moved to the root.
                foreach ($pending as $logicalId => &$versions) {
                    $targets[$logicalId]['parent_id'] = '';
                }
                unset($versions);
            }
        }

        return [
            'imported' => $imported,
            'history' => $history,
            'drafts' => $drafts,
            'deleted' => $deleted,
            'warnings' => array_values(array_unique($warnings)),
            'review_pages' => array_values($reviewPages),
            'nodes_by_source' => $nodesBySource,
            'documents_by_source' => $documentsBySource,
        ];
    }

    /**
     * HR: Razrješava Confluence početnu stranicu na logički identitet koji
     *     koristi grupirani skup verzija. Ako izvorna oznaka nije dostupna,
     *     bira prvu korijensku, a tek zatim prvu uvezenu stranicu.
     * EN: Resolves the Confluence homepage to the logical identity used by the
     *     grouped version set. When the source reference is unavailable, it
     *     selects the first root page and only then the first imported page.
     *
     * @param array<string,list<array<string,mixed>>> $pages
     */
    private function homepageLogicalId(array $pages, string $sourceHomepageId): string
    {
        if ($sourceHomepageId !== '') {
            foreach ($pages as $logicalId => $versions) {
                if (
                    (string)$logicalId === $sourceHomepageId
                    || in_array($sourceHomepageId, $this->pageSourceIds($logicalId, $versions), true)
                ) {
                    return (string)$logicalId;
                }
            }
        }

        foreach ($pages as $logicalId => $versions) {
            if ($this->text($this->currentPage($versions)['parent_id'] ?? '') === '') {
                return (string)$logicalId;
            }
        }

        foreach ($pages as $logicalId => $_versions) {
            return (string)$logicalId;
        }

        return '';
    }

    /**
     * HR: Vraća sve Confluence ID-eve verzija koji mogu biti container privitka iste logičke stranice.
     * EN: Returns all Confluence version IDs that may be an attachment container for the same logical page.
     *
     * @param list<array<string,mixed>> $versions
     * @return list<string>
     */
    private function pageSourceIds(int|string $logicalId, array $versions): array
    {
        $ids = [(string)$logicalId];
        foreach ($versions as $version) {
            $sourceId = $this->text($version['source_id'] ?? '');
            if ($sourceId !== '') {
                $ids[] = $sourceId;
            }
        }

        return array_values(array_unique(array_filter($ids, static fn(string $id): bool => $id !== '')));
    }

    /**
     * HR: Registrira ranije izdvojene Confluence datoteke kao stvarne Editor privitke stranice.
     * EN: Registers previously staged Confluence files as real Editor page attachments.
     */
    private function registerImportedAttachments(
        string $sourcePageId,
        int $workspaceId,
        int $nodeId,
        string $documentKey,
        int $actorUserId,
    ): int {
        $registered = 0;
        foreach ($this->repository->storedAttachmentsForPage($sourcePageId, $workspaceId) as $attachment) {
            $attachmentId = (int)($attachment['id'] ?? 0);
            $sourcePath = $this->text($attachment['storage_path'] ?? '');
            if ($attachmentId <= 0 || $sourcePath === '' || !is_file($sourcePath)) {
                continue;
            }

            $this->importedAttachmentService->importFromPath(
                $documentKey,
                $this->text($attachment['uuid'] ?? ''),
                $sourcePath,
                $this->text($attachment['original_name'] ?? 'attachment'),
                $this->text($attachment['mime_type'] ?? 'application/octet-stream'),
                $actorUserId,
            );
            if (!unlink($sourcePath) && is_file($sourcePath)) {
                throw new ConfluenceImportException(
                    __('Privremenu kopiju uvezenog privitka nije moguće ukloniti.'),
                );
            }
            $this->repository->markAttachmentRegistered($attachmentId, $nodeId, $documentKey);
            ++$registered;
        }

        return $registered;
    }

    /**
     * HR: Grupira upozorenja konverzije po ciljnoj stranici za trajni izvještaj importa.
     * EN: Groups conversion warnings by target page for the durable import report.
     *
     * @param array<string,array<string,mixed>> $reviewPages
     * @param list<array<string,mixed>> $issues
     * @param array<string,mixed> $page
     * @param array<string,mixed> $target
     */
    private function recordPageReview(array &$reviewPages, array $issues, array $page, array $target): void
    {
        if ($issues === []) {
            return;
        }

        $sourcePageId = $this->text($page['logical_source_id'] ?? $page['source_id'] ?? '');
        $url = $this->text($target['path'] ?? '');
        $key = $sourcePageId !== '' ? $sourcePageId : $url;
        $reviewPages[$key] ??= [
            'source_page_id' => $sourcePageId,
            'title' => $this->text($target['title'] ?? $page['title'] ?? ''),
            'url' => $url,
            'issues' => [],
        ];

        $known = [];
        foreach (is_array($reviewPages[$key]['issues'] ?? null) ? $reviewPages[$key]['issues'] : [] as $issue) {
            if (is_array($issue)) {
                $known[$this->reviewIssueKey($issue)] = $issue;
            }
        }
        foreach ($issues as $issue) {
            $known[$this->reviewIssueKey($issue)] = $issue;
        }
        $reviewPages[$key]['issues'] = array_values($known);
    }

    /**
     * HR: Razlikuje više istovrsnih makroa na istoj stranici stabilnom oznakom.
     * EN: Distinguishes multiple macros of the same kind on one page by their stable marker.
     *
     * @param array<string,mixed> $issue
     */
    private function reviewIssueKey(array $issue): string
    {
        return implode(':', [
            $this->text($issue['type'] ?? ''),
            $this->text($issue['macro'] ?? ''),
            $this->text($issue['marker'] ?? ''),
            $this->text($issue['source_calendar_id'] ?? ''),
        ]);
    }

    /**
     * HR: Pretvara jedno izvorno tijelo i razrješava privitke i poveznice.
     * EN: Converts one source body and resolves attachments and links.
     *
     * @param array<string,mixed> $dataset
     * @param array<string,mixed> $page
     * @param array<string,string> $attachmentUrls
     * @param array<string,string> $localById
     * @param array<string,string> $localByTitle
     * @return array{html:string,warnings:list<string>,review_issues:list<array<string,mixed>>,properties:list<array{key:string,label:string,type:string,value:string,sort_order:int}>}
     */
    private function convertedBody(
        array $dataset,
        array $page,
        string $spaceKey,
        int $jobId,
        array $attachmentUrls,
        array $localById,
        array $localByTitle,
        ?ConfluenceMacroContext $macroContext = null,
    ): array {
        $sourceId = $this->text($page['source_id'] ?? '');
        $path = $this->text($dataset['body_directory'] ?? '') . DIRECTORY_SEPARATOR . $this->safeSourceId($sourceId) . '.html';
        $body = is_file($path) ? file_get_contents($path) : '';
        $converted = $this->converter->convert(
            is_string($body) ? $body : '',
            $spaceKey,
            $sourceId,
            $macroContext,
        );
        $html = $this->references->resolve(
            $converted->html,
            $sourceId,
            $spaceKey,
            $jobId,
            $attachmentUrls,
            $localById,
            $localByTitle,
        );

        // HR: Confluence include nije statički link. Pretvara se u kanonsku
        //     Editor referencu koja pri svakom prikazu ponovno čita ciljnu stranicu.
        // EN: A Confluence include is not a static link. It becomes a canonical
        //     Editor reference that reloads the target page on every view.
        foreach ($converted->includes as $include) {
            $marker = $this->text($include['marker'] ?? '');
            if ($marker === '') {
                continue;
            }

            $destinationSpace = $this->text($include['destination_space_key'] ?? $spaceKey);
            $destinationId = $this->text($include['destination_page_id'] ?? '');
            $destinationTitle = $this->text($include['destination_page_title'] ?? '');
            $html = str_replace(
                $marker,
                $this->documentIncludes->placeholder(
                    '',
                    $destinationTitle,
                    'confluence',
                    $destinationSpace !== '' ? $destinationSpace : $spaceKey,
                    $destinationId,
                    $destinationTitle,
                ),
                $html,
            );
        }

        $unresolvedIncludes = array_values(array_filter(
            $converted->includes,
            fn(array $include): bool => !$this->includeCanResolve(
                $include,
                $spaceKey,
                $localById,
                $localByTitle,
            ),
        ));

        return [
            'html' => $html,
            'warnings' => array_map(
                static fn(string $macro): string => sprintf(__('Makro "%s" sačuvan je kao statički prikaz.'), $macro),
                $converted->unsupportedMacros,
            ),
            'review_issues' => [
                ...$converted->reviewIssues,
                ...array_map(
                    static fn(string $macro): array => ['type' => 'unsupported_macro', 'macro' => $macro],
                    $converted->unsupportedMacros,
                ),
                ...array_map(
                    static fn(array $include): array => [
                        'type' => 'unresolved_include',
                        'macro' => 'include',
                    ],
                    $unresolvedIncludes,
                ),
            ],
            'properties' => $converted->properties,
        ];
    }

    /**
     * HR: Provjerava postoji li cilj include makroa u ovom planu ili ranijem importu.
     * EN: Checks whether an include target exists in this plan or an earlier import.
     *
     * @param array<string,string> $include
     * @param array<string,string> $localById
     * @param array<string,string> $localByTitle
     */
    private function includeCanResolve(
        array $include,
        string $sourceSpaceKey,
        array $localById,
        array $localByTitle,
    ): bool {
        $spaceKey = $this->text($include['destination_space_key'] ?? $sourceSpaceKey);
        $pageId = $this->text($include['destination_page_id'] ?? '');
        $title = $this->text($include['destination_page_title'] ?? '');
        if ($spaceKey === '' || $spaceKey === $sourceSpaceKey) {
            return ($pageId !== '' && isset($localById[$pageId]))
                || ($title !== '' && isset($localByTitle[$title]));
        }

        return is_array($pageId !== ''
            ? $this->repository->contentBySource($spaceKey, $pageId)
            : $this->repository->contentByTitle($spaceKey, $title));
    }

    /** HR: Objavljuje trenutačnu verziju dokumenta kroz službeni workflow. EN: Publishes the current document version through the official workflow. */
    private function publishVersion(string $documentKey, string $language, int $nodeId, int $actorUserId): int
    {
        $versionNumber = $this->editor->currentVersionNumber($documentKey, $language);
        $this->editor->markVersionDraft($documentKey, $language, $versionNumber);
        $this->workflow->markDocumentDraft($documentKey, $language, $versionNumber, $actorUserId);
        $this->editor->publishDraft($documentKey, $language, $versionNumber);
        $this->workflow->transition($nodeId, $language, 'publish', $versionNumber, $actorUserId, true, true, true);

        return $versionNumber;
    }

    /**
     * HR: Pripisuje jednu uvezenu verziju stvarnom Confluence autoru kada je njegov račun mapiran.
     * EN: Attributes one imported version to its real Confluence author when that account is mapped.
     *
     * @param array<string,mixed> $page
     */
    private function attributeImportedVersion(
        string $documentKey,
        string $language,
        int $versionNumber,
        array $page,
        int $fallbackUserId,
        bool $published,
    ): void {
        $this->invokeImportAttribution('attributeVersion', [
            $documentKey,
            $language,
            $versionNumber,
            $this->sourceVersionAuthorUserId($page, $fallbackUserId),
            $published,
            $this->text($page['updated_at'] ?? $page['created_at'] ?? ''),
        ]);
    }

    /**
     * HR: Novi Editor prima i izvorne vremenske oznake, ali importer ostaje
     *     siguran tijekom zajedničkog Composer ažuriranja sa starijim izdanjem.
     * EN: New Editor releases accept source timestamps, while the importer
     *     remains safe during a joint Composer update from an older release.
     *
     * @param list<mixed> $arguments
     */
    private function invokeImportAttribution(string $method, array $arguments): void
    {
        $reflection = new \ReflectionMethod($this->importAttribution, $method);
        $reflection->invokeArgs(
            $this->importAttribution,
            array_slice($arguments, 0, $reflection->getNumberOfParameters()),
        );
    }

    /**
     * HR: Vraća mapiranog zadnjeg urednika, zatim izvornog autora, pa sigurni administratorski fallback.
     * EN: Returns the mapped last editor, then source creator, then the safe importing-admin fallback.
     *
     * @param array<string,mixed> $page
     */
    private function sourceVersionAuthorUserId(array $page, int $fallbackUserId): int
    {
        foreach (['modifier_source_key', 'creator_source_key'] as $field) {
            $mapped = $this->repository->mappedUserId($this->text($page[$field] ?? ''));
            if ($mapped !== null) {
                return $mapped;
            }
        }

        return $fallbackUserId;
    }

    /**
     * HR: Vraća najranijeg mapiranog autora dokumenta bez promjene vlasnika i ACL-a.
     * EN: Returns the earliest mapped document creator without changing ownership or ACL.
     *
     * @param list<array<string,mixed>> $versions
     */
    private function sourceCreatorUserId(array $versions, int $fallbackUserId): int
    {
        foreach ($versions as $version) {
            foreach (['creator_source_key', 'modifier_source_key'] as $field) {
                $mapped = $this->repository->mappedUserId($this->text($version[$field] ?? ''));
                if ($mapped !== null) {
                    return $mapped;
                }
            }
        }

        return $fallbackUserId;
    }

    /**
     * HR: Sprema administratorsku napomenu za jednu uvezenu verziju.
     * EN: Stores the administrator provenance note for one imported version.
     *
     * @param array<string,mixed> $page
     * @param array<string,mixed> $node
     */
    private function mapImportedVersion(
        array $page,
        string $spaceKey,
        int $workspaceId,
        array $node,
        int $jobId,
        string $status = 'imported',
    ): void {
        $this->repository->mapContent($page, [
            'source_space_key' => $spaceKey,
            'workspace_id' => $workspaceId,
            'node_id' => $node['id'] ?? null,
            'document_key' => $node['document_key'] ?? null,
            'slug' => $node['slug'] ?? null,
            'note' => sprintf(
                'Confluence content ID: %s; original ID: %s; version: %d; status: %s',
                $this->text($page['source_id'] ?? ''),
                $this->text($page['logical_source_id'] ?? ''),
                (int)($page['version'] ?? 1),
                $this->text($page['status'] ?? 'current'),
            ),
        ], $jobId, $status);
    }

    /**
     * HR: Primjenjuje dodatna ograničenja stranica uz zatvoreni fallback.
     * EN: Applies page-level restrictions with a fail-closed fallback.
     *
     * @param array<string,mixed> $dataset
     * @param array<string,int> $nodesBySource
     * @param array<string,mixed> $options
     */
    private function applyNodeAcl(
        int $workspaceId,
        int $workspaceManagerUserId,
        array $dataset,
        array $nodesBySource,
        array $options,
    ): void {
        $permissions = is_array($dataset['permissions'] ?? null) ? $dataset['permissions'] : [];
        $permissionsBySet = [];
        foreach ($permissions as $permissionId => $permission) {
            if (!is_array($permission)) {
                continue;
            }

            $setId = $this->text($permission['set_id'] ?? '');
            if ($setId !== '') {
                $permissionsBySet[$setId][] = (string)$permissionId;
            }
        }
        $setsByPage = [];
        foreach ($this->rows($dataset['permission_sets'] ?? []) as $set) {
            $pageId = $this->text($set['page_id'] ?? '');
            $nodeId = $nodesBySource[$pageId] ?? 0;
            if ($nodeId <= 0) {
                continue;
            }

            $restrictionType = strtolower($this->text($set['type'] ?? '')) === 'edit' ? 'edit' : 'view';
            // HR: Sama prisutnost skupa je semantički važna. Prazan ili potpuno
            // neriješen View skup mora ostati zatvoren, dok prazan Edit skup
            // smije ograničiti uređivanje bez skrivanja stranice.
            // EN: The set's presence is semantically significant. An empty or
            // fully unresolved View set must remain closed, while an empty Edit
            // set may restrict editing without hiding the page.
            $setsByPage[$pageId][$restrictionType] ??= [];
            $permissionIds = is_array($set['permission_ids'] ?? null) ? $set['permission_ids'] : [];
            if ($permissionIds === []) {
                $permissionIds = $permissionsBySet[$this->text($set['source_id'] ?? '')] ?? [];
            }
            foreach ($permissionIds as $permissionId) {
                $permission = is_array($permissions[(string)$permissionId] ?? null)
                    ? $permissions[(string)$permissionId]
                    : null;
                if (!is_array($permission)) {
                    continue;
                }
                $sourceUser = $this->text($permission['user_source_key'] ?? '');
                $groupName = $this->text($permission['group_name'] ?? '');
                if ($sourceUser !== '') {
                    $targetId = $this->repository->mappedUserId($sourceUser) ?? 0;
                    if ($targetId > 0) {
                        $setsByPage[$pageId][$restrictionType][WorkspaceRepository::SUBJECT_USER . ':' . $targetId] = [
                            'type' => WorkspaceRepository::SUBJECT_USER,
                            'id' => $targetId,
                        ];
                    }
                } elseif ($groupName !== '') {
                    $groupMap = is_array($options['group_map'] ?? null) ? $options['group_map'] : [];
                    $targetId = is_numeric($groupMap[$groupName] ?? null) ? (int)$groupMap[$groupName] : 0;
                    if ($targetId > 0) {
                        $setsByPage[$pageId][$restrictionType][WorkspaceRepository::SUBJECT_GROUP . ':' . $targetId] = [
                            'type' => WorkspaceRepository::SUBJECT_GROUP,
                            'id' => $targetId,
                        ];
                    }
                }
            }
        }

        $managerKey = WorkspaceRepository::SUBJECT_USER . ':' . $workspaceManagerUserId;

        foreach ($setsByPage as $pageId => $sets) {
            $nodeId = $nodesBySource[$pageId] ?? 0;
            if ($nodeId <= 0) {
                continue;
            }

            $viewSubjects = is_array($sets['view'] ?? null) ? $sets['view'] : [];
            $editSubjects = is_array($sets['edit'] ?? null) ? $sets['edit'] : [];
            $hasViewRestriction = array_key_exists('view', $sets);
            $hasEditRestriction = array_key_exists('edit', $sets);
            $candidateKeys = array_unique([
                ...array_keys($viewSubjects),
                ...array_keys($editSubjects),
                $managerKey,
            ]);
            // HR: Ugrađeni public red je zadano pravilo cijele stranice. Kod
            //     View ograničenja uskraćuje sve, a izričite iznimke ispod
            //     ponovno uključuju samo mapirane korisnike i grupe. Kod samog
            //     Edit ograničenja svima ostavlja pregled, ali uskraćuje izmjene.
            // EN: The built-in public row is the page-wide default. A View
            //     restriction denies everyone before explicit mapped user/group
            //     exceptions are applied. An Edit-only restriction keeps viewing
            //     while denying modifications by default.
            $acl = [
                WorkspaceRepository::SUBJECT_PUBLIC => [
                    WorkspaceRepository::BUILT_IN_SUBJECT_ID => $this->completeRights([
                        'can_view' => !$hasViewRestriction,
                        'can_add' => false,
                        'can_edit' => false,
                        'can_publish' => false,
                        'can_delete' => false,
                        'can_manage' => false,
                    ]),
                ],
            ];
            foreach ($candidateKeys as $subjectKey) {
                [$type, $rawId] = array_pad(explode(':', $subjectKey, 2), 2, '');
                $id = is_numeric($rawId) ? (int)$rawId : 0;
                if (
                    !in_array($type, [WorkspaceRepository::SUBJECT_USER, WorkspaceRepository::SUBJECT_GROUP], true)
                    || $id <= 0
                ) {
                    continue;
                }
                $rights = $this->completeRights([
                    'can_view' => true,
                    'can_add' => true,
                    'can_edit' => true,
                    'can_publish' => true,
                    'can_delete' => true,
                    'can_manage' => true,
                ]);
                if ($hasEditRestriction && !isset($editSubjects[$subjectKey]) && $subjectKey !== $managerKey) {
                    $rights['can_add'] = false;
                    $rights['can_edit'] = false;
                    $rights['can_publish'] = false;
                    $rights['can_delete'] = false;
                    $rights['can_manage'] = false;
                }
                $acl[$type][$id] = $this->completeRights($rights);
            }

            $this->workspaces->replaceNodeAcl($workspaceId, $nodeId, $acl);
        }
    }

    /**
     * HR: Uvozi samo komentare s razriješenim autorom i ciljnom stranicom.
     * EN: Imports only comments with a resolved author and target page.
     *
     * @param array<string,mixed> $dataset
     * @param array<string,string> $documentsBySource
     * @return array{imported:int,skipped:int}
     */
    private function importComments(
        array $dataset,
        array $documentsBySource,
        int $jobId,
        string $sourceSpaceKey,
        int $workspaceId,
        string $language,
    ): array {
        if (!class_exists(self::COMMENT_SERVICE)) {
            return ['imported' => 0, 'skipped' => count($dataset['comments'] ?? [])];
        }
        $service = $this->container->get(self::COMMENT_SERVICE);
        if (!is_object($service) || !method_exists($service, 'create')) {
            return ['imported' => 0, 'skipped' => count($dataset['comments'] ?? [])];
        }

        $comments = $this->rows($dataset['comments'] ?? []);
        $commentsBySource = [];
        foreach ($comments as $comment) {
            $sourceId = $this->text($comment['source_id'] ?? '');
            if ($sourceId !== '') {
                $commentsBySource[$sourceId] = $comment;
            }
        }

        // HR: Confluence dopušta komentare izravno na privitku. Simbioza ih
        //     prikazuje uz stranicu kojoj privitak pripada, pa ovdje gradimo
        //     vezu privitak -> stranica prije obrade komentara.
        // EN: Confluence allows comments directly on an attachment. Simbioza
        //     shows them on the attachment's owning page, so we build the
        //     attachment-to-page relation before processing comments.
        $attachmentPagesBySource = [];
        foreach ($this->rows($dataset['attachments'] ?? []) as $attachment) {
            $attachmentId = $this->text($attachment['source_id'] ?? '');
            $pageId = $this->text($attachment['page_id'] ?? '');
            if ($attachmentId !== '' && $pageId !== '') {
                $attachmentPagesBySource[$attachmentId] = $pageId;
            }
        }

        $imported = 0;
        $skipped = 0;
        foreach ($comments as $comment) {
            $pageId = $this->commentPageId($comment, $commentsBySource, $attachmentPagesBySource);
            $documentKey = $documentsBySource[$pageId] ?? '';
            $userId = $this->repository->mappedUserId($this->text($comment['creator_source_key'] ?? ''));
            $bodyPath = $this->text($dataset['body_directory'] ?? '') . DIRECTORY_SEPARATOR
                . $this->safeSourceId($this->text($comment['source_id'] ?? '')) . '.html';
            $body = is_file($bodyPath) ? trim(strip_tags((string)file_get_contents($bodyPath))) : '';
            if ($documentKey === '' || $userId === null || $body === '') {
                ++$skipped;
                $comment['source_type'] = 'comment';
                $comment['logical_source_id'] = $this->text($comment['source_id'] ?? '');
                $this->repository->mapContent($comment, [
                    'source_space_key' => $sourceSpaceKey,
                    'workspace_id' => $workspaceId,
                    'note' => 'Comment retained as unresolved import metadata; author or target page is not mapped.',
                ], $jobId, 'skipped_unmapped');
                continue;
            }

            $sourceUser = $this->users->findById($userId);
            $displayName = is_array($sourceUser)
                ? $this->text($sourceUser['display_name'] ?? $sourceUser['login_identifier'] ?? '')
                : __('Uvezeni korisnik');
            $service->create($documentKey, $language, $userId, $displayName, $body, true);
            ++$imported;
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * HR: Prati lanac odgovora dok ne dođe do stvarne stranice. Confluenceov
     *     `containerContent` odgovora pokazuje na nadređeni komentar.
     * EN: Follows a reply chain until it reaches the actual page. A Confluence
     *     reply's `containerContent` points at its parent comment.
     *
     * @param array<string,mixed> $comment
     * @param array<string,array<string,mixed>> $commentsBySource
     * @param array<string,string> $attachmentPagesBySource
     */
    private function commentPageId(
        array $comment,
        array $commentsBySource,
        array $attachmentPagesBySource,
    ): string {
        $containerId = $this->text($comment['page_id'] ?? '');
        $visited = [];
        while ($containerId !== '' && isset($commentsBySource[$containerId]) && !isset($visited[$containerId])) {
            $visited[$containerId] = true;
            $containerId = $this->text($commentsBySource[$containerId]['page_id'] ?? '');
        }

        return $attachmentPagesBySource[$containerId] ?? $containerId;
    }

    /** HR: Pokušava razriješiti stare cross-space poveznice nakon svakog novog importa. EN: Attempts to resolve old cross-space links after each new import. */
    private function reconcileLinks(): int
    {
        $resolved = 0;
        foreach ($this->repository->unresolvedLinks() as $link) {
            $spaceKey = $this->text($link['destination_space_key'] ?? '');
            $pageId = $this->text($link['destination_page_id'] ?? '');
            $title = $this->text($link['destination_page_title'] ?? '');
            if ($pageId !== '') {
                $mapping = $spaceKey !== ''
                    ? $this->repository->contentBySource($spaceKey, $pageId)
                    : $this->repository->contentByAnySourceId($pageId);
            } else {
                $mapping = $spaceKey !== '' ? $this->repository->contentByTitle($spaceKey, $title) : null;
            }
            if (!is_array($mapping)) {
                continue;
            }
            $space = $this->repository->spaceByWorkspaceId((int)($mapping['target_workspace_id'] ?? 0));
            $spaceSlug = is_array($space) ? $this->text($space['target_workspace_slug'] ?? '') : '';
            $nodeSlug = $this->text($mapping['target_slug'] ?? '');
            if ($spaceSlug === '' || $nodeSlug === '') {
                continue;
            }
            $target = $this->nodePath($spaceSlug, $nodeSlug);
            $original = $this->text($link['original_target'] ?? '');
            $fragment = Utf8Url::component($original, PHP_URL_FRAGMENT);
            if (is_string($fragment) && trim($fragment) !== '') {
                $safeFragment = $this->safeFragment($fragment);
                $target .= $safeFragment !== '' ? '#' . $safeFragment : '';
            }
            $this->repository->resolveLink((int)$link['id'], $target);
            ++$resolved;
        }

        return $resolved;
    }

    /**
     * HR: Razrješava include reference tek kada su sve stranice područja stvorene.
     *     Time radi i forward reference te reference iz ranije uvezenih područja.
     * EN: Resolves include references only after every Workspace page exists.
     *     This supports forward references and references from earlier imports.
     *
     * @param array<string,array<string,mixed>> $targets
     * @param array<string,string> $documentsBySource
     */
    private function reconcileIncludes(string $spaceKey, array $targets, array $documentsBySource): int
    {
        $resolved = 0;
        foreach ($documentsBySource as $sourceId => $documentKey) {
            $target = $targets[(string)$sourceId] ?? [];
            $resolved += $this->documentIncludes->resolveExternal(
                'confluence',
                $spaceKey,
                (string)$sourceId,
                $this->text(is_array($target) ? ($target['title'] ?? '') : ''),
                $documentKey,
            );
        }

        return $resolved;
    }

    /**
     * HR: Opcionalno bilježi poslovni događaj bez utjecaja na ishod importa.
     * EN: Optionally records a business event without affecting the import outcome.
     *
     * @param array<string,mixed> $data
     */
    private function audit(string $event, array $data): void
    {
        if (!class_exists(self::AUDIT_LOG)) {
            return;
        }

        try {
            $audit = $this->container->get(self::AUDIT_LOG);
            if (is_object($audit) && method_exists($audit, 'record')) {
                $audit->record($event, ['module' => 'simbioza-confluence-import', ...$data]);
            }
        } catch (\Throwable $throwable) {
            $this->logger->warning('Confluence import audit integration failed.', [
                'module' => 'simbioza-confluence-import',
                'event' => $event,
                'exception' => $throwable,
            ]);
        }
    }

    /**
     * HR: Priprema jednoznačnu strategiju ponovnog uvoza prije prvog zapisa.
     *     Zamjena trajno uklanja prethodno uvezeno područje, dok kopija dobiva
     *     vlastiti izvorni identitet i slobodan naziv/slug.
     * EN: Prepares an unambiguous re-import strategy before the first write.
     *     Replacement permanently removes the previous imported Workspace,
     *     while a copy receives its own source identity and free name/slug.
     *
     * @param array<string,mixed> $space
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function prepareReimport(
        array $space,
        array $options,
        int $actorUserId,
        int $jobId,
    ): array {
        $sourceId = $this->text($space['source_id'] ?? '');
        $sourceKey = $this->text($space['source_key'] ?? '');
        $strategy = $this->text($options['reimport_strategy'] ?? 'new');
        $existingMapping = $this->repository->spaceBySourceId($sourceId);

        $options['mapping_space_id'] = $sourceId;
        $options['mapping_space_key'] = $sourceKey;
        if (!is_array($existingMapping)) {
            if ($strategy === 'copy') {
                $options['mapping_space_id'] = $sourceId . '#copy-' . $jobId;
                $options['mapping_space_key'] = $sourceKey . '#copy-' . $jobId;
            }

            return $options;
        }

        $workspaceId = (int)($existingMapping['target_workspace_id'] ?? 0);
        $existingWorkspace = $workspaceId > 0
            ? $this->workspaces->findWorkspaceById($workspaceId, true)
            : null;
        if ($strategy === 'new') {
            throw new ConfluenceImportException(
                __('Confluence područje već je uvezeno. Odaberite zamjenu postojećeg područja ili uvoz nove kopije.'),
            );
        }

        if ($strategy === 'copy') {
            if (($space['type'] ?? '') === 'personal') {
                throw new ConfluenceImportException(
                    __('Osobno Confluence područje nije moguće uvesti kao zasebnu kopiju.'),
                );
            }

            $existingName = is_array($existingWorkspace)
                ? $this->text($existingWorkspace['name'] ?? '')
                : '';
            $existingSlug = is_array($existingWorkspace)
                ? $this->text($existingWorkspace['slug'] ?? '')
                : '';
            if ($existingName !== '' && $this->text($options['workspace_name'] ?? '') === $existingName) {
                $options['workspace_name'] = $existingName . ' — ' . __('kopija');
            }
            if ($existingSlug !== '' && $this->text($options['workspace_slug'] ?? '') === $existingSlug) {
                $options['workspace_slug'] = $existingSlug . '-copy';
            }
            $options['mapping_space_id'] = $sourceId . '#copy-' . $jobId;
            $options['mapping_space_key'] = $sourceKey . '#copy-' . $jobId;

            return $options;
        }

        if (is_array($existingWorkspace)) {
            if (!(bool)($existingWorkspace['is_deleted'] ?? false)) {
                $this->workspaces->softDeleteWorkspace($workspaceId, $actorUserId);
            }
            $this->workspaceMaintenance->permanentlyDeleteWorkspace(
                $workspaceId,
                $this->text($existingWorkspace['slug'] ?? ''),
                $actorUserId,
            );
        }

        return $options;
    }

    /**
     * HR: Vraća izvorni zapis s identitetom namijenjenim ovom uvozu.
     * EN: Returns the source record with the identity assigned to this import.
     *
     * @param array<string,mixed> $space
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function mappingSpace(array $space, array $options): array
    {
        $space['source_id'] = $this->text($options['mapping_space_id'] ?? $space['source_id'] ?? '');
        $space['source_key'] = $this->text($options['mapping_space_key'] ?? $space['source_key'] ?? '');

        return $space;
    }

    /**
     * HR: Normalizira i potvrđuje sve administratorske opcije importa.
     * EN: Normalizes and validates all administrator import options.
     *
     * @param array<string,mixed> $options
     * @param array<string,mixed> $space
     * @return array<string,mixed>
     */
    private function normalizeOptions(array $options, array $space): array
    {
        $name = $this->text($options['workspace_name'] ?? $space['name'] ?? '');
        $slug = $this->text($options['workspace_slug'] ?? $space['source_key'] ?? '');
        $reimportStrategy = $this->text($options['reimport_strategy'] ?? 'new');
        if (!in_array($reimportStrategy, ['new', 'replace', 'copy'], true)) {
            $reimportStrategy = 'new';
        }
        if ($name === '' || $slug === '') {
            throw new ConfluenceImportException(__('Naziv i slug ciljnog područja su obavezni.'));
        }

        return [
            'workspace_name' => $name,
            'workspace_slug' => $slug,
            'reimport_strategy' => $reimportStrategy,
            'language' => $this->language($options['language'] ?? $this->config->defaultLanguage()),
            'include_history' => $this->boolean($options['include_history'] ?? false),
            'include_deleted' => $this->boolean($options['include_deleted'] ?? false),
            'include_drafts' => $this->boolean($options['include_drafts'] ?? false),
            'include_attachments' => !array_key_exists('include_attachments', $options)
                || $this->boolean($options['include_attachments']),
            'include_comments' => !array_key_exists('include_comments', $options)
                || $this->boolean($options['include_comments']),
            'identity_map' => is_array($options['identity_map'] ?? null) ? $options['identity_map'] : [],
            'identity_create' => is_array($options['identity_create'] ?? null)
                ? $options['identity_create']
                : [],
            'group_map' => is_array($options['group_map'] ?? null) ? $options['group_map'] : [],
            'group_create' => is_array($options['group_create'] ?? null) ? $options['group_create'] : [],
        ];
    }

    /**
     * HR: Pretvara izvorni Page objekt u interni zapis.
     * EN: Converts a source Page object into an internal record.
     *
     * @return array<string,mixed>
     */
    private function page(ConfluenceObject $object): array
    {
        $sourceId = $object->string('id');
        $original = $object->reference('originalVersion');
        return [
            'source_id' => $sourceId,
            'logical_source_id' => $original !== '' ? $original : $sourceId,
            'original_version_id' => $original,
            'parent_id' => $object->reference('parent'),
            'title' => $object->string('title'),
            'version' => $object->integer('version', 1),
            'status' => strtolower($object->string('contentStatus', 'current')),
            'position' => $object->integer('position', 100),
            'creator_source_key' => $object->reference('creator'),
            'modifier_source_key' => $object->reference('lastModifier'),
            'created_at' => $object->string('creationDate'),
            'updated_at' => $object->string('lastModificationDate'),
            'version_comment' => $object->string('versionComment'),
        ];
    }

    /**
     * HR: Pretvara izvorni Attachment objekt u interni zapis.
     * EN: Converts a source Attachment object into an internal record.
     *
     * @return array<string,mixed>
     */
    private function attachment(ConfluenceObject $object): array
    {
        return [
            'source_id' => $object->string('id'),
            'logical_source_id' => $object->reference('originalVersion') ?: $object->string('id'),
            'page_id' => $object->reference('containerContent'),
            'title' => $object->string('title'),
            'version' => $object->integer('version', 1),
            'status' => strtolower($object->string('contentStatus', 'current')),
        ];
    }

    /**
     * HR: Pretvara izvorni Comment objekt u interni zapis.
     * EN: Converts a source Comment object into an internal record.
     *
     * @return array<string,mixed>
     */
    private function comment(ConfluenceObject $object): array
    {
        return [
            'source_id' => $object->string('id'),
            'page_id' => $object->reference('containerContent'),
            'status' => strtolower($object->string('contentStatus', 'current')),
            'creator_source_key' => $object->reference('creator'),
            'created_at' => $object->string('creationDate'),
            'updated_at' => $object->string('lastModificationDate'),
        ];
    }

    /**
     * HR: Odabire aktualnu verziju jedne logičke stranice.
     * EN: Selects the current version of one logical page.
     *
     * @param list<array<string,mixed>> $versions
     * @return array<string,mixed>
     */
    private function currentPage(array $versions): array
    {
        foreach (array_reverse($versions) as $page) {
            if (($page['original_version_id'] ?? '') === '' && ($page['status'] ?? '') !== 'draft') {
                return $page;
            }
        }

        return $versions[count($versions) - 1] ?? [];
    }

    /**
     * HR: Mapira Confluence ovlast na Simbioza prava.
     * EN: Maps a Confluence permission to Simbioza rights.
     *
     * @return array<string,bool>
     */
    private function spacePermissionRights(string $type): array
    {
        return match (strtoupper(trim($type))) {
            'VIEWSPACE' => ['can_view' => true],
            'EDITSPACE', 'COMMENT' => ['can_view' => true, 'can_edit' => true],
            'CREATEATTACHMENT', 'ADDPAGE', 'EDITBLOG' => ['can_view' => true, 'can_add' => true],
            'REMOVEPAGE', 'REMOVEATTACHMENT', 'REMOVEBLOG' => ['can_view' => true, 'can_delete' => true],
            'SETPAGEPERMISSIONS', 'SETSPACEPERMISSIONS', 'ADMINISTERSPACE' => [
                'can_view' => true,
                'can_add' => true,
                'can_edit' => true,
                'can_publish' => true,
                'can_delete' => true,
                'can_manage' => true,
            ],
            default => [],
        };
    }

    /**
     * HR: Spaja dva skupa prava bez gubitka dopuštenja.
     * EN: Merges two rights sets without losing permissions.
     *
     * @param array<string,bool> $left
     * @param array<string,bool> $right
     * @return array<string,bool>
     */
    private function mergeRights(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            $left[$key] = ($left[$key] ?? false) || $value;
        }
        return $this->completeRights($left);
    }

    /**
     * HR: Dopunjuje sva ACL polja eksplicitnim boolean vrijednostima.
     * EN: Completes every ACL field with an explicit boolean value.
     *
     * @param array<string,bool> $rights
     * @return array<string,bool>
     */
    private function completeRights(array $rights): array
    {
        $complete = [];
        foreach (['can_view', 'can_add', 'can_edit', 'can_publish', 'can_delete', 'can_manage'] as $key) {
            $complete[$key] = (bool)($rights[$key] ?? false);
        }
        return $complete;
    }

    /** HR: Gradi putanju jedne stranice područja. EN: Builds a Workspace page path. */
    private function nodePath(string $workspaceSlug, string $nodeSlug): string
    {
        if ($this->urls->namedRouteExists('workspace.node.show')) {
            return $this->urls->getPathFor('workspace.node.show', [
                'workspaceSlug' => $workspaceSlug,
                'nodeSlug' => $nodeSlug,
            ]);
        }
        return rtrim($this->urls->getBasePath(), '/') . '/workspace/'
            . rawurlencode($workspaceSlug) . '/' . rawurlencode($nodeSlug);
    }

    /** HR: Gradi Editorovu ACL-zaštićenu putanju stvarnog privitka stranice. EN: Builds Editor's ACL-protected real page-attachment path. */
    private function attachmentPath(string $uuid): string
    {
        if ($this->urls->namedRouteExists('editor-html.asset')) {
            return $this->urls->getPathFor('editor-html.asset', ['assetUuid' => $uuid]);
        }
        return rtrim($this->urls->getBasePath(), '/') . '/editor-html/asset/' . rawurlencode($uuid);
    }

    /** HR: Priprema izolirani direktorij jednog importa. EN: Prepares an isolated directory for one import. */
    private function stagingDirectory(string $uuid): string
    {
        $path = $this->config->dataDirectory() . DIRECTORY_SEPARATOR . 'staging' . DIRECTORY_SEPARATOR
            . preg_replace('/[^a-f0-9-]/i', '', $uuid);
        $this->ensureDirectory($path);
        return $path;
    }

    /** HR: Sigurno izrađuje potreban privatni direktorij. EN: Safely creates a required private directory. */
    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new ConfluenceImportException(__('Direktorij za pripremu importa nije moguće kreirati.'));
        }
    }

    /** HR: Rekurzivno uklanja samo direktorij unutar privatnog korijena modula. EN: Recursively removes only a directory inside the module's private root. */
    private function removeDirectory(string $directory): void
    {
        $privateRoot = realpath($this->config->dataDirectory());
        $resolvedDirectory = realpath($directory);
        if (
            $privateRoot === false
            || $resolvedDirectory === false
            || !is_dir($resolvedDirectory)
            || !str_starts_with($resolvedDirectory . DIRECTORY_SEPARATOR, $privateRoot . DIRECTORY_SEPARATOR)
        ) {
            return;
        }

        // HR: macOS može u privatni direktorij naknadno dodati .DS_Store; kratki ponovljeni prolaz sprječava lažno upozorenje nakon uspješnog importa.
        // EN: macOS may recreate .DS_Store in the private directory; a short repeated sweep prevents a false warning after a successful import.
        for ($attempt = 0; $attempt < 3 && is_dir($resolvedDirectory); $attempt++) {
            $items = scandir($resolvedDirectory);
            foreach (is_array($items) ? $items : [] as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $resolvedDirectory . DIRECTORY_SEPARATOR . $item;
                if (is_link($path) || is_file($path)) {
                    @unlink($path);
                } elseif (is_dir($path)) {
                    $this->removeDirectory($path);
                }
            }

            // HR: Aplikacijski error handler ne smije pretvoriti bezazleni rmdir neuspjeh u tehnički log.
            // EN: The application error handler must not turn a harmless rmdir failure into a technical-log entry.
            set_error_handler(static fn (): bool => true);
            try {
                rmdir($resolvedDirectory);
            } finally {
                restore_error_handler();
            }
        }
    }

    /** HR: Detektira MIME iz sadržaja uz siguran fallback. EN: Detects MIME from content with a safe fallback. */
    private function detectedMime(string $path, string $fallback): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected = $finfo !== false ? finfo_file($finfo, $path) : false;
        return is_string($detected) && trim($detected) !== '' ? trim($detected) : ($fallback ?: 'application/octet-stream');
    }

    /** HR: Pretvara izvorni ID u siguran dio naziva datoteke. EN: Converts a source ID into a safe filename segment. */
    private function safeSourceId(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $value) ?: 'unknown';
    }

    /**
     * HR: Zadržava samo znakove dopuštene u URL fragmentu.
     * EN: Keeps only characters allowed in a URL fragment.
     */
    private function safeFragment(string $fragment): string
    {
        return preg_replace('~[^A-Za-z0-9._\\~!$&\'()*+,;=:@%/\\-]~', '', trim($fragment)) ?? '';
    }

    /**
     * HR: Normalizira popis asocijativnih zapisa.
     * EN: Normalizes a list of associative records.
     *
     * @return list<array<string,mixed>>
     */
    private function rows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $rows = [];
        foreach ($value as $candidate) {
            if (is_array($candidate)) {
                $rows[] = $candidate;
            }
        }
        return $rows;
    }

    /** HR: Normalizira skalarnu tekstualnu vrijednost. EN: Normalizes a scalar text value. */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /** HR: Čita HTML ili JSON boolean vrijednost. EN: Reads an HTML or JSON boolean value. */
    private function boolean(mixed $value): bool
    {
        return $value === true || (is_scalar($value) && in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true));
    }

    /** HR: Potvrđuje jezični kod uz zadani fallback. EN: Validates a language code with the configured fallback. */
    private function language(mixed $value): string
    {
        $language = strtolower($this->text($value));
        return preg_match('/^[a-z]{2}(?:-[a-z0-9]{2,8})?$/', $language) === 1
            ? $language
            : $this->config->defaultLanguage();
    }

    /** HR: Zahtijeva pozitivan brojčani identifikator. EN: Requires a positive numeric identifier. */
    private function positiveInt(mixed $value, string $message): int
    {
        $id = is_numeric($value) ? (int)$value : 0;
        if ($id <= 0) {
            throw new ConfluenceImportException($message);
        }
        return $id;
    }
}
