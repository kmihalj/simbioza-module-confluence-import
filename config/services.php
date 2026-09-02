<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleAuth\Service\AuthGroupService;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthUserAttributeService;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthUserService;
use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorApiActorContext;
use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorHtmlChartService;
use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorHtmlRoadmapService;
use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorDocumentIncludeService;
use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorImportAttachmentService;
use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorImageVariantService;
use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorService;
use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorWorkspaceIntegration;
use AaiEduHr\HeartPhrameModuleMenu\Service\MenuRenderer;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceAccessService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceContentChangeBatch;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceMaintenanceService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceWorkflowService;
use AaiEduHr\SimbiozaModuleConfluenceImport\Backup\ConfluenceImportBackupProvider;
use AaiEduHr\SimbiozaModuleConfluenceImport\Backup\ConfluenceImportWorkspaceBackupProvider;
use AaiEduHr\SimbiozaModuleConfluenceImport\Command\HpSimbiozaConfluenceImportCommand;
use AaiEduHr\SimbiozaModuleConfluenceImport\Controller\ConfluenceImportController;
use AaiEduHr\SimbiozaModuleConfluenceImport\Listener\PurgeConfluencePageImport;
use AaiEduHr\SimbiozaModuleConfluenceImport\Listener\PurgeConfluenceWorkspaceImport;
use AaiEduHr\SimbiozaModuleConfluenceImport\ModuleSimbiozaConfluenceImport;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceArchive;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceExportReader;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceExportScanner;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceHtmlConverter;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportConfig;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportMenuIntegration;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportModuleViewRenderer;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportRepository;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportService;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportUploadService;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluencePageSlugger;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluencePrincipalMatcher;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceReferenceResolver;
use AaiEduHr\SimbiozaModuleUser\Service\PersonalWorkspaceService;
use HeartPhrame\Config\ConfigInterface;
use HeartPhrame\Http\ResponseFactory;
use HeartPhrame\Localization\TranslatorInterface;
use HeartPhrame\Routing\UrlGenerator;
use HeartPhrame\Session\SessionInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

$services = [
    PurgeConfluencePageImport::class =>
        static fn(ContainerInterface $container): PurgeConfluencePageImport =>
            new PurgeConfluencePageImport(
                $container->get(Database::class),
                $container->get(ConfluenceImportConfig::class),
            ),
    PurgeConfluenceWorkspaceImport::class =>
        static fn(ContainerInterface $container): PurgeConfluenceWorkspaceImport =>
            new PurgeConfluenceWorkspaceImport(
                $container->get(Database::class),
                $container->get(ConfluenceImportConfig::class),
            ),
    ConfluenceImportConfig::class => static fn(ContainerInterface $container): ConfluenceImportConfig =>
        new ConfluenceImportConfig($container->get(ConfigInterface::class), dirname(__DIR__)),
    ConfluenceImportRepository::class => static fn(ContainerInterface $container): ConfluenceImportRepository =>
        new ConfluenceImportRepository($container->get(Database::class)),
    ConfluenceArchive::class => static fn(ContainerInterface $container): ConfluenceArchive =>
        new ConfluenceArchive($container->get(ConfluenceImportConfig::class)),
    ConfluenceExportReader::class => static fn(ContainerInterface $container): ConfluenceExportReader =>
        new ConfluenceExportReader($container->get(ConfluenceArchive::class)),
    ConfluenceExportScanner::class => static fn(ContainerInterface $container): ConfluenceExportScanner =>
        new ConfluenceExportScanner(
            $container->get(ConfluenceArchive::class),
            $container->get(ConfluenceExportReader::class),
        ),
    ConfluenceImportUploadService::class => static fn(ContainerInterface $container): ConfluenceImportUploadService =>
        new ConfluenceImportUploadService(
            $container->get(ConfluenceImportRepository::class),
            $container->get(ConfluenceImportConfig::class),
            $container->get(ConfluenceExportScanner::class),
        ),
    ConfluenceHtmlConverter::class => static fn(ContainerInterface $container): ConfluenceHtmlConverter =>
        new ConfluenceHtmlConverter(
            $container->get(EditorHtmlChartService::class),
            $container->get(EditorHtmlRoadmapService::class),
        ),
    ConfluencePrincipalMatcher::class => static fn(): ConfluencePrincipalMatcher => new ConfluencePrincipalMatcher(),
    ConfluencePageSlugger::class => static fn(): ConfluencePageSlugger => new ConfluencePageSlugger(),
    ConfluenceReferenceResolver::class => static fn(ContainerInterface $container): ConfluenceReferenceResolver =>
        new ConfluenceReferenceResolver(
            $container->get(ConfluenceImportRepository::class),
            $container->get(UrlGenerator::class),
        ),
    ConfluenceImportService::class => static fn(ContainerInterface $container): ConfluenceImportService =>
        new ConfluenceImportService(
            $container->get(ConfluenceImportRepository::class),
            $container->get(ConfluenceImportUploadService::class),
            $container->get(ConfluenceExportReader::class),
            $container->get(ConfluenceArchive::class),
            $container->get(ConfluenceImportConfig::class),
            $container->get(ConfluenceHtmlConverter::class),
            $container->get(ConfluenceReferenceResolver::class),
            $container->get(ConfluencePageSlugger::class),
            $container->get(WorkspaceRepository::class),
            $container->get(WorkspaceContentChangeBatch::class),
            $container->get(WorkspaceWorkflowService::class),
            $container->get(WorkspaceMaintenanceService::class),
            $container->get(EditorService::class),
            $container->get(EditorDocumentIncludeService::class),
            $container->get(EditorWorkspaceIntegration::class),
            $container->get(EditorApiActorContext::class),
            $container->get(EditorImportAttachmentService::class),
            $container->get(\AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorImportAttributionService::class),
            $container->get(EditorImageVariantService::class),
            $container->get(AuthUserService::class),
            $container->get(AuthUserAttributeService::class),
            $container->get(AuthGroupService::class),
            $container->get(ConfluencePrincipalMatcher::class),
            $container->get(PersonalWorkspaceService::class),
            $container->get(UrlGenerator::class),
            $container,
            $container->get(LoggerInterface::class),
        ),
    ConfluenceImportModuleViewRenderer::class =>
        static fn(ContainerInterface $container): ConfluenceImportModuleViewRenderer =>
            new ConfluenceImportModuleViewRenderer($container->get(ResponseFactory::class)),
    ConfluenceImportMenuIntegration::class =>
        static fn(ContainerInterface $container): ConfluenceImportMenuIntegration =>
            new ConfluenceImportMenuIntegration(
                $container,
                $container->get(LoggerInterface::class),
            ),
    ConfluenceImportController::class => static fn(ContainerInterface $container): ConfluenceImportController =>
        new ConfluenceImportController(
            $container->get(ResponseFactory::class),
            $container->get(ConfluenceImportModuleViewRenderer::class),
            $container->get(WorkspaceAccessService::class),
            $container->get(WorkspaceRepository::class),
            $container->get(ConfluenceImportRepository::class),
            $container->get(ConfluenceImportUploadService::class),
            $container->get(ConfluenceImportService::class),
            $container->get(ConfluenceImportConfig::class),
            $container->get(UrlGenerator::class),
            $container->get(SessionInterface::class),
            $container->get(TranslatorInterface::class),
            $container->get(ConfigInterface::class),
            $container->get(MenuRenderer::class),
        ),
    HpSimbiozaConfluenceImportCommand::class =>
        static fn(ContainerInterface $container): HpSimbiozaConfluenceImportCommand =>
            new HpSimbiozaConfluenceImportCommand(
                $container->get(ConfigInterface::class),
                $container->get(ConfluenceExportScanner::class),
            ),
];

if (class_exists(\AaiEduHr\HeartPhrameModuleBackup\Service\DatabaseTableBackupProvider::class)) {
    $services['heartphrame.backup.provider.simbioza-confluence-import'] =
        static fn(ContainerInterface $container): ConfluenceImportBackupProvider =>
            new ConfluenceImportBackupProvider(
                $container->get(Database::class),
                new \AaiEduHr\HeartPhrameModuleBackup\Value\BackupProviderMetadata(
                    'simbioza-confluence-import',
                    ModuleSimbiozaConfluenceImport::PACKAGE_NAME,
                    1,
                    ['hr' => 'Confluence mapiranja područja', 'en' => 'Confluence Workspace mappings'],
                    ['auth', 'workspace'],
                    [
                        \AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope::SITE,
                        \AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope::COMPONENT,
                    ],
                    true,
                    true,
                    componentGroups: [\AaiEduHr\HeartPhrameModuleBackup\Value\BackupComponentGroup::WORKSPACES],
                ),
                [
                    [
                        'dataset' => 'jobs',
                        'table' => ModuleSimbiozaConfluenceImport::TABLE_JOBS,
                        'primary_key' => 'id',
                        'conflict_keys' => ['uuid'],
                        'preserve_primary_key' => false,
                        'identity_namespace' => 'simbioza-confluence-import.job',
                        'foreign_keys' => [
                            ['column' => 'workspace_id', 'namespace' => 'workspace.workspace', 'nullable' => true],
                            ['column' => 'actor_user_id', 'namespace' => 'auth.user', 'nullable' => true],
                        ],
                    ],
                    [
                        'dataset' => 'spaces',
                        'table' => ModuleSimbiozaConfluenceImport::TABLE_SPACES,
                        'primary_key' => 'id',
                        'conflict_keys' => ['source_instance', 'source_space_id'],
                        'preserve_primary_key' => false,
                        'foreign_keys' => [
                            ['column' => 'target_workspace_id', 'namespace' => 'workspace.workspace'],
                            ['column' => 'job_id', 'namespace' => 'simbioza-confluence-import.job'],
                        ],
                    ],
                    [
                        'dataset' => 'identities',
                        'table' => ModuleSimbiozaConfluenceImport::TABLE_IDENTITIES,
                        'primary_key' => 'id',
                        'conflict_keys' => ['source_user_key'],
                        'preserve_primary_key' => false,
                        'foreign_keys' => [
                            ['column' => 'target_user_id', 'namespace' => 'auth.user', 'nullable' => true],
                            ['column' => 'job_id', 'namespace' => 'simbioza-confluence-import.job'],
                        ],
                    ],
                    [
                        'dataset' => 'groups',
                        'table' => ModuleSimbiozaConfluenceImport::TABLE_GROUPS,
                        'primary_key' => 'id',
                        'conflict_keys' => ['source_group_name'],
                        'preserve_primary_key' => false,
                        'foreign_keys' => [
                            ['column' => 'target_group_id', 'namespace' => 'auth.group', 'nullable' => true],
                            ['column' => 'job_id', 'namespace' => 'simbioza-confluence-import.job'],
                        ],
                    ],
                    [
                        'dataset' => 'content',
                        'table' => ModuleSimbiozaConfluenceImport::TABLE_CONTENT,
                        'primary_key' => 'id',
                        'conflict_keys' => ['source_space_key', 'source_content_id'],
                        'preserve_primary_key' => false,
                        'foreign_keys' => [
                            ['column' => 'target_workspace_id', 'namespace' => 'workspace.workspace'],
                            ['column' => 'target_node_id', 'namespace' => 'workspace.node', 'nullable' => true],
                            ['column' => 'job_id', 'namespace' => 'simbioza-confluence-import.job'],
                        ],
                    ],
                    [
                        'dataset' => 'links',
                        'table' => ModuleSimbiozaConfluenceImport::TABLE_LINKS,
                        'primary_key' => 'id',
                        'conflict_keys' => ['uuid'],
                        'preserve_primary_key' => false,
                        'foreign_keys' => [
                            ['column' => 'job_id', 'namespace' => 'simbioza-confluence-import.job'],
                        ],
                    ],
                    [
                        'dataset' => 'attachments',
                        'table' => ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS,
                        'primary_key' => 'id',
                        'conflict_keys' => ['uuid'],
                        'preserve_primary_key' => false,
                        'foreign_keys' => [
                            ['column' => 'target_workspace_id', 'namespace' => 'workspace.workspace', 'nullable' => true],
                            ['column' => 'target_node_id', 'namespace' => 'workspace.node', 'nullable' => true],
                            ['column' => 'job_id', 'namespace' => 'simbioza-confluence-import.job'],
                        ],
                    ],
                ],
                $container->get(ConfluenceImportConfig::class),
            );

    $services['heartphrame.backup.provider.simbioza-confluence-import-workspace'] =
        static fn(ContainerInterface $container): ConfluenceImportWorkspaceBackupProvider =>
            new ConfluenceImportWorkspaceBackupProvider(
                $container->get(Database::class),
                $container->get(ConfluenceImportConfig::class),
                $container->get(\AaiEduHr\HeartPhrameModuleBackup\Service\BackupFilesystem::class),
            );
}

if (class_exists(\AaiEduHr\HeartPhrameModuleBackup\Service\FilesystemBackupProvider::class)) {
    $services['heartphrame.backup.provider.simbioza-confluence-import-files'] =
        static fn(ContainerInterface $container): \AaiEduHr\HeartPhrameModuleBackup\Service\FilesystemBackupProvider =>
            new \AaiEduHr\HeartPhrameModuleBackup\Service\FilesystemBackupProvider(
                new \AaiEduHr\HeartPhrameModuleBackup\Value\BackupProviderMetadata(
                    'simbioza-confluence-import-files',
                    ModuleSimbiozaConfluenceImport::PACKAGE_NAME,
                    1,
                    ['hr' => 'Privatni Confluence privitci', 'en' => 'Private Confluence attachments'],
                    ['simbioza-confluence-import'],
                    [
                        \AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope::SITE,
                        \AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope::COMPONENT,
                    ],
                    true,
                    true,
                    componentGroups: [\AaiEduHr\HeartPhrameModuleBackup\Value\BackupComponentGroup::WORKSPACES],
                ),
                $container->get(\AaiEduHr\HeartPhrameModuleBackup\Service\BackupFilesystem::class),
                [[
                    'key' => 'simbioza-confluence-import-attachments',
                    'path' => $container->get(ConfluenceImportConfig::class)->attachmentDirectory(),
                ]],
            );
}

return $services;
