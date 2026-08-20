<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\HeartPhrameModuleAuth\Service\AuthGroupService;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthUserService;
use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorApiActorContext;
use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorService;
use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorWorkspaceIntegration;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceWorkflowService;
use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use AaiEduHr\SimbiozaModuleConfluenceImport\Value\ConfluenceObject;
use AaiEduHr\SimbiozaModuleUser\Service\PersonalWorkspaceService;
use HeartPhrame\Routing\UrlGenerator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_reverse;
use function array_unique;
use function array_values;
use function basename;
use function count;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function finfo_file;
use function finfo_open;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_numeric;
use function is_object;
use function is_scalar;
use function is_string;
use function ksort;
use function max;
use function mkdir;
use function parse_url;
use function preg_replace;
use function rawurlencode;
use function rmdir;
use function sort;
use function str_contains;
use function str_starts_with;
use function strip_tags;
use function strtolower;
use function trim;
use function unlink;
use function usort;

use const FILEINFO_MIME_TYPE;

/**
 * HR: Orkestrira kontrolirani import područja, stranica, povijesti, ACL-a, privitaka i opcionalnih komentara.
 * EN: Orchestrates controlled import of spaces, pages, history, ACL, attachments, and optional comments.
 */
final readonly class ConfluenceImportService
{
    private const COMMENT_SERVICE = \AaiEduHr\HeartPhrameModuleComment\Service\CommentService::class;

    private const SEARCH_INDEXER = \AaiEduHr\HeartPhrameModuleWorkspaceSearch\Service\WorkspaceSearchIndexer::class;

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
        private WorkspaceRepository $workspaces,
        private WorkspaceWorkflowService $workflow,
        private EditorService $editor,
        private EditorWorkspaceIntegration $editorWorkspace,
        private EditorApiActorContext $editorActors,
        private AuthUserService $users,
        private AuthGroupService $groups,
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
        $targetUsers = $this->users->listUsersForSetup();
        $suggestions = [];
        foreach ($this->rows($scan['users'] ?? []) as $sourceUser) {
            $sourceKey = $this->text($sourceUser['source_key'] ?? '');
            $email = $this->text($sourceUser['email'] ?? '');
            $username = $this->text($sourceUser['username'] ?? '');
            $mappedUserId = $sourceKey !== '' ? $this->repository->mappedUserId($sourceKey) : null;
            $candidate = $mappedUserId !== null ? $this->users->findById($mappedUserId) : null;
            if (!is_array($candidate) && $email !== '') {
                $candidate = $this->users->findByEmailOrAlias($email);
            }
            if (!is_array($candidate) && $username !== '') {
                $candidate = $this->users->findByLoginIdentifier($username);
            }
            if ($sourceKey !== '' && is_array($candidate) && is_numeric($candidate['id'] ?? null)) {
                $suggestions[$sourceKey] = (int)$candidate['id'];
            }
        }

        return [
            'job' => $job,
            'scan' => $scan,
            'target_users' => $targetUsers,
            'target_groups' => $this->groups->listGroups(),
            'identity_suggestions' => $suggestions,
            'group_suggestions' => $this->groupSuggestions($scan),
        ];
    }

    /**
     * HR: Predlaže postojeća mapiranja grupa bez automatskog stvaranja.
     * EN: Suggests existing group mappings without automatic creation.
     *
     * @param array<string,mixed> $scan
     * @return array<string,int>
     */
    private function groupSuggestions(array $scan): array
    {
        $suggestions = [];
        foreach ($this->rows($scan['groups'] ?? []) as $sourceGroup) {
            $name = $this->text($sourceGroup['source_name'] ?? '');
            $targetId = $name !== '' ? $this->repository->mappedGroupId($name) : null;
            if ($targetId !== null) {
                $suggestions[$name] = $targetId;
            }
        }

        return $suggestions;
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
        $this->repository->startImport((int)$job['id'], $normalized);
        $jobId = (int)$job['id'];
        $staging = $this->stagingDirectory($jobUuid);
        $workspace = null;
        try {
            $this->repository->setStage($jobId, 'identity_mapping');
            $this->saveIdentityMappings($scan, $normalized, $jobId);
            $normalized['group_map'] = $this->saveGroupMappings($scan, $normalized, $jobId);
            $this->repository->setStage($jobId, 'workspace');
            $workspace = $this->workspace($space, $normalized, $actorUserId);
            $workspaceId = $this->positiveInt($workspace['id'] ?? null, __('Ciljno područje nije moguće kreirati.'));
            $this->repository->mapSpace($space, $workspace, $jobId);
            $workspaceOwnerId = $this->positiveInt(
                $workspace['owner_user_id'] ?? null,
                __('Vlasnik ciljnog područja nije pronađen.'),
            );
            $this->applyWorkspaceAcl($workspaceId, $workspaceOwnerId, $scan, $normalized);

            $dataset = $this->stageDataset($archivePath, $staging);
            $pages = $this->selectedPageGroups($dataset['pages'], $normalized);
            $targets = $this->plannedTargets($pages, $workspace);
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
                $workspaceOwnerId,
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
                    $this->text($space['source_key'] ?? ''),
                    $workspaceId,
                    $this->text($normalized['language'] ?? $this->config->defaultLanguage()),
                )
                : ['imported' => 0, 'skipped' => 0];
            $this->repository->setStage($jobId, 'links_and_search');
            $reconciled = $this->reconcileLinks();
            $this->rebuildSearch($workspaceId);
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

            return $summary;
        } catch (\Throwable $throwable) {
            $this->repository->failImport($jobId, $throwable->getMessage());
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
     * HR: Pronalazi ili izrađuje ciljano obično ili osobno područje.
     * EN: Finds or creates the target regular or Personal Workspace.
     *
     * @param array<string,mixed> $space
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function workspace(array $space, array $options, int $actorUserId): array
    {
        if (($space['type'] ?? '') === 'personal') {
            $sourceOwner = $this->text($space['owner_source_key'] ?? '');
            $ownerId = $this->repository->mappedUserId($sourceOwner);
            if ($ownerId === null) {
                throw new ConfluenceImportException(__('Vlasnik osobnog Confluence područja mora biti potvrđeno mapiran.'));
            }

            $workspace = $this->personalWorkspaces->ensureForUser($ownerId, $actorUserId, false);
            if (!is_array($workspace)) {
                throw new ConfluenceImportException(__('Osobno područje ciljnog korisnika nije moguće pripremiti.'));
            }

            return $workspace;
        }

        return $this->workspaces->saveWorkspace([
            'name' => $options['workspace_name'],
            'slug' => $options['workspace_slug'],
            'description' => sprintf(
                __('Uvezeno iz Confluence područja %1$s (%2$s).'),
                $this->text($space['name'] ?? ''),
                $this->text($space['source_key'] ?? ''),
            ),
            'visibility' => 'restricted',
            'owner_user_id' => $actorUserId,
            'tree_visibility' => 'inherit',
            'contents_visibility' => 'inherit',
        ], $actorUserId);
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
        foreach ($this->rows($scan['users'] ?? []) as $sourceUser) {
            $sourceKey = $this->text($sourceUser['source_key'] ?? '');
            $targetId = is_numeric($map[$sourceKey] ?? null) && (int)$map[$sourceKey] > 0
                ? (int)$map[$sourceKey]
                : null;
            if ($targetId !== null && !is_array($this->users->findById($targetId))) {
                $targetId = null;
            }
            $this->repository->mapIdentity($sourceUser, $targetId, $targetId !== null, $jobId);
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
            if ($targetId > 0 && !isset($available[$targetId])) {
                $targetId = 0;
            }
            if ($targetId <= 0 && $this->boolean($create[$name] ?? false)) {
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
    private function applyWorkspaceAcl(int $workspaceId, int $ownerUserId, array $scan, array $options): void
    {
        // HR: Vlasnik je sigurni fail-closed subjekt. Njegov red osigurava da
        // područje s potpuno neriješenim Confluence identitetima ne postane
        // dostupno drugim korisnicima kroz nasljeđivanje.
        // EN: The owner is the safe fail-closed subject. Their row ensures a
        // space with entirely unresolved Confluence identities never becomes
        // accessible to other users through inheritance.
        $acl = [
            WorkspaceRepository::SUBJECT_USER => [
                $ownerUserId => $this->completeRights([
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
     * @return array{pages:list<array<string,mixed>>,attachments:list<array<string,mixed>>,properties:array<string,array<string,string>>,comments:list<array<string,mixed>>,permission_sets:list<array<string,mixed>>,permissions:array<string,array<string,mixed>>,warnings:list<string>}
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
            }
        }

        return [
            'pages' => $pages,
            'attachments' => $attachments,
            'properties' => $properties,
            'comments' => $comments,
            'permission_sets' => $permissionSets,
            'permissions' => $permissions,
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
     * @return array<string,array<string,mixed>>
     */
    private function plannedTargets(array $pages, array $workspace): array
    {
        $used = [];
        $result = [];
        $workspaceSlug = $this->text($workspace['slug'] ?? '');
        foreach ($pages as $logicalId => $versions) {
            $current = $this->currentPage($versions);
            $title = $this->text($current['title'] ?? '');
            $base = $this->editor->slugFromTitle($title);
            $base = $base !== '' ? $base : 'page-' . $logicalId;
            $slug = $base;
            $suffix = 2;
            while (isset($used[$slug])) {
                $slug = $base . '-' . $suffix++;
            }
            $used[$slug] = true;
            $result[$logicalId] = [
                'slug' => $slug,
                'title' => $title,
                'parent_id' => $this->text($current['parent_id'] ?? ''),
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
        foreach ($latest as $attachment) {
            $sourceId = $this->text($attachment['source_id'] ?? '');
            $pageId = $this->text($attachment['page_id'] ?? '');
            if ($sourceId === '' || $pageId === '') {
                continue;
            }
            $version = max(1, (int)($attachment['version'] ?? 1));
            $filename = basename($this->text($attachment['title'] ?? 'attachment'));
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
    ): array {
        $workspaceId = (int)($workspace['id'] ?? 0);
        $sourceSpaceKey = $this->text($space['source_key'] ?? '');
        $homePageId = $this->text($space['home_page_id'] ?? '');
        $language = $this->text($options['language'] ?? $this->config->defaultLanguage());
        $localById = [];
        $localByTitle = [];
        foreach ($targets as $logicalId => $target) {
            $localById[$logicalId] = $this->text($target['path'] ?? '#');
            $localByTitle[$this->text($target['title'] ?? '')] = $this->text($target['path'] ?? '#');
        }

        $pending = $pages;
        $nodesBySource = [];
        $documentsBySource = [];
        $imported = 0;
        $history = 0;
        $drafts = 0;
        $deleted = 0;
        $warnings = [];
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $logicalId => $versions) {
                // HR: PHP numeričke XML ID-e pretvara u integer ključeve polja;
                //     od ove granice nadalje Confluence identifikator ostaje tekst.
                // EN: PHP converts numeric XML IDs to integer array keys; from
                //     this boundary onward the Confluence identifier stays text.
                $logicalId = (string)$logicalId;
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
                );
                $warnings = [...$warnings, ...$converted['warnings']];
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
                    'slug' => $this->text($target['slug'] ?? ''),
                    'node_type' => 'document',
                    'document_key' => $document->id,
                    'parent_id' => $parentNodeId,
                    'sort_order' => (int)($target['sort_order'] ?? 100),
                    'is_homepage' => $logicalId === $homePageId,
                ], $actorUserId);
                $nodeId = (int)($node['id'] ?? 0);
                $nodesBySource[$logicalId] = $nodeId;
                $documentsBySource[$logicalId] = $document->id;
                $this->repository->attachImportedAttachmentsToPage(
                    $logicalId,
                    $workspaceId,
                    $nodeId,
                    $document->id,
                );
                if ($publishable !== []) {
                    $this->publishVersion($document->id, $language, $nodeId, $actorUserId);
                    $this->mapImportedVersion($first, $sourceSpaceKey, $workspaceId, $node, $jobId);
                } else {
                    $versionNumber = $this->editor->currentVersionNumber($document->id, $language);
                    $this->editor->markVersionDraft($document->id, $language, $versionNumber);
                    $this->workflow->markDocumentDraft($document->id, $language, $versionNumber, $actorUserId);
                    $this->mapImportedVersion($first, $sourceSpaceKey, $workspaceId, $node, $jobId, 'draft');
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
                    );
                    $warnings = [...$warnings, ...$converted['warnings']];
                    $this->editor->save($document->id, $language, $this->text($version['title'] ?? ''), $converted['html']);
                    $this->publishVersion($document->id, $language, $nodeId, $actorUserId);
                    $this->mapImportedVersion($version, $sourceSpaceKey, $workspaceId, $node, $jobId);
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
                    );
                    $this->editor->save($document->id, $language, $this->text($draft['title'] ?? ''), $converted['html']);
                    $versionNumber = $this->editor->currentVersionNumber($document->id, $language);
                    $this->workflow->markDocumentDraft($document->id, $language, $versionNumber, $actorUserId);
                    $this->mapImportedVersion($draft, $sourceSpaceKey, $workspaceId, $node, $jobId, 'draft');
                    ++$drafts;
                }

                if (($target['status'] ?? '') === 'deleted') {
                    $this->workspaces->disableNodeTree($workspaceId, $nodeId, $actorUserId);
                    $this->editor->deleteDocument($document->id);
                    ++$deleted;
                }

                unset($pending[$logicalId]);
                $progress = true;
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
            'nodes_by_source' => $nodesBySource,
            'documents_by_source' => $documentsBySource,
        ];
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
     * @return array{html:string,warnings:list<string>}
     */
    private function convertedBody(
        array $dataset,
        array $page,
        string $spaceKey,
        int $jobId,
        array $attachmentUrls,
        array $localById,
        array $localByTitle,
    ): array {
        $sourceId = $this->text($page['source_id'] ?? '');
        $path = $this->text($dataset['body_directory'] ?? '') . DIRECTORY_SEPARATOR . $this->safeSourceId($sourceId) . '.html';
        $body = is_file($path) ? file_get_contents($path) : '';
        $converted = $this->converter->convert(is_string($body) ? $body : '', $spaceKey, $sourceId);
        $html = $this->references->resolve(
            $converted->html,
            $sourceId,
            $spaceKey,
            $jobId,
            $attachmentUrls,
            $localById,
            $localByTitle,
        );

        return [
            'html' => $html,
            'warnings' => array_map(
                static fn(string $macro): string => sprintf(__('Makro "%s" sačuvan je kao statički prikaz.'), $macro),
                $converted->unsupportedMacros,
            ),
        ];
    }

    /** HR: Objavljuje trenutačnu verziju dokumenta kroz službeni workflow. EN: Publishes the current document version through the official workflow. */
    private function publishVersion(string $documentKey, string $language, int $nodeId, int $actorUserId): void
    {
        $versionNumber = $this->editor->currentVersionNumber($documentKey, $language);
        $this->editor->markVersionDraft($documentKey, $language, $versionNumber);
        $this->workflow->markDocumentDraft($documentKey, $language, $versionNumber, $actorUserId);
        $this->editor->publishDraft($documentKey, $language, $versionNumber);
        $this->workflow->transition($nodeId, $language, 'publish', $versionNumber, $actorUserId, true, true, true);
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
        int $workspaceOwnerId,
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

        $workspaceSubjects = [];
        foreach ($this->workspaces->workspaceAclRows($workspaceId) as $row) {
            $type = $this->text($row['subject_type'] ?? '');
            $id = is_numeric($row['subject_id'] ?? null) ? (int)$row['subject_id'] : 0;
            if ($type === '' || $id <= 0) {
                continue;
            }
            $workspaceSubjects[$type . ':' . $id] = [
                'type' => $type,
                'id' => $id,
                'rights' => $this->completeRights([
                    'can_view' => (bool)($row['can_view'] ?? false),
                    'can_add' => (bool)($row['can_add'] ?? false),
                    'can_edit' => (bool)($row['can_edit'] ?? false),
                    'can_publish' => (bool)($row['can_publish'] ?? false),
                    'can_delete' => (bool)($row['can_delete'] ?? false),
                    'can_manage' => (bool)($row['can_manage'] ?? false),
                ]),
            ];
        }
        $ownerKey = WorkspaceRepository::SUBJECT_USER . ':' . $workspaceOwnerId;

        foreach ($setsByPage as $pageId => $sets) {
            $nodeId = $nodesBySource[$pageId] ?? 0;
            if ($nodeId <= 0) {
                continue;
            }

            $viewSubjects = is_array($sets['view'] ?? null) ? $sets['view'] : [];
            $editSubjects = is_array($sets['edit'] ?? null) ? $sets['edit'] : [];
            $hasViewRestriction = array_key_exists('view', $sets);
            $hasEditRestriction = array_key_exists('edit', $sets);
            $candidateKeys = $hasViewRestriction
                ? array_unique([...array_keys($viewSubjects), ...array_keys($editSubjects), $ownerKey])
                : array_keys($workspaceSubjects);
            $acl = [];
            foreach ($candidateKeys as $subjectKey) {
                $subject = $workspaceSubjects[$subjectKey] ?? null;
                if (!is_array($subject)) {
                    continue;
                }
                $rights = $subject['rights'];
                if ($hasEditRestriction && !isset($editSubjects[$subjectKey]) && $subjectKey !== $ownerKey) {
                    $rights['can_edit'] = false;
                    $rights['can_publish'] = false;
                    $rights['can_delete'] = false;
                    $rights['can_manage'] = false;
                }
                $acl[(string)$subject['type']][(int)$subject['id']] = $this->completeRights($rights);
            }

            if ($acl === []) {
                $acl = [
                    WorkspaceRepository::SUBJECT_USER => [
                        $workspaceOwnerId => $this->completeRights([
                            'can_view' => true,
                            'can_add' => true,
                            'can_edit' => true,
                            'can_publish' => true,
                            'can_delete' => true,
                            'can_manage' => true,
                        ]),
                    ],
                ];
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

        $imported = 0;
        $skipped = 0;
        foreach ($this->rows($dataset['comments'] ?? []) as $comment) {
            $pageId = $this->text($comment['page_id'] ?? '');
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
            $fragment = parse_url($original, PHP_URL_FRAGMENT);
            if (is_string($fragment) && trim($fragment) !== '') {
                $safeFragment = $this->safeFragment($fragment);
                $target .= $safeFragment !== '' ? '#' . $safeFragment : '';
            }
            $this->repository->resolveLink((int)$link['id'], $target);
            ++$resolved;
        }

        return $resolved;
    }

    /** HR: Nakon uspješnog importa opcionalno obnavlja izvedeni indeks područja. EN: Optionally rebuilds the derived Workspace index after a successful import. */
    private function rebuildSearch(int $workspaceId): void
    {
        if (!class_exists(self::SEARCH_INDEXER)) {
            return;
        }
        try {
            $indexer = $this->container->get(self::SEARCH_INDEXER);
            if (is_object($indexer) && method_exists($indexer, 'rebuildWorkspace')) {
                $indexer->rebuildWorkspace($workspaceId);
            }
        } catch (\Throwable $throwable) {
            $this->logger->warning('Confluence import completed but search reindex failed.', [
                'module' => 'simbioza-confluence-import',
                'workspace_id' => $workspaceId,
                'exception' => $throwable,
            ]);
        }
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
        if ($name === '' || $slug === '') {
            throw new ConfluenceImportException(__('Naziv i slug ciljnog područja su obavezni.'));
        }

        return [
            'workspace_name' => $name,
            'workspace_slug' => $slug,
            'language' => $this->language($options['language'] ?? $this->config->defaultLanguage()),
            'include_history' => $this->boolean($options['include_history'] ?? false),
            'include_deleted' => $this->boolean($options['include_deleted'] ?? false),
            'include_drafts' => $this->boolean($options['include_drafts'] ?? false),
            'include_attachments' => !array_key_exists('include_attachments', $options)
                || $this->boolean($options['include_attachments']),
            'include_comments' => !array_key_exists('include_comments', $options)
                || $this->boolean($options['include_comments']),
            'identity_map' => is_array($options['identity_map'] ?? null) ? $options['identity_map'] : [],
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

    /** HR: Gradi ACL-zaštićenu putanju uvezenog privitka. EN: Builds an ACL-protected imported-attachment path. */
    private function attachmentPath(string $uuid): string
    {
        if ($this->urls->namedRouteExists('simbioza-confluence-import.attachment')) {
            return $this->urls->getPathFor('simbioza-confluence-import.attachment', ['uuid' => $uuid]);
        }
        return rtrim($this->urls->getBasePath(), '/') . '/confluence-import/attachment/' . rawurlencode($uuid);
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
        if (!is_dir($directory) || !str_starts_with($directory, $this->config->dataDirectory())) {
            return;
        }
        $items = scandir($directory);
        foreach (is_array($items) ? $items : [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } elseif (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($directory);
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
