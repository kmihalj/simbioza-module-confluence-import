<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleAuth\Middleware\RequireAuthenticatedUserMiddleware;
use AaiEduHr\HeartPhrameModuleAuth\ModuleAuth;
use AaiEduHr\HeartPhrameModuleEditorHtml\ModuleEditorHtml;
use AaiEduHr\HeartPhrameModuleMenu\ModuleMenu;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleWorkspace\ModuleWorkspace;
use AaiEduHr\SimbiozaModuleConfluenceImport\Command\HpSimbiozaConfluenceImportCommand;
use AaiEduHr\SimbiozaModuleConfluenceImport\Controller\ConfluenceImportController;
use AaiEduHr\SimbiozaModuleConfluenceImport\Listener\PurgeConfluencePageImport;
use AaiEduHr\SimbiozaModuleConfluenceImport\Listener\PurgeConfluenceWorkspaceImport;
use AaiEduHr\SimbiozaModuleConfluenceImport\ModuleSimbiozaConfluenceImport;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportMenuIntegration;
use AaiEduHr\SimbiozaModuleUser\ModuleSimbiozaUser;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Command\CommandDefinition;
use HeartPhrame\Config\ConfigInterface;
use HeartPhrame\Event\EventListener;
use HeartPhrame\Module\AbstractModuleManifest;
use Psr\Container\ContainerInterface;

return new class extends AbstractModuleManifest {
    private const REQUIRED_PACKAGES = [
        'aaieduhr/heartphrame-module-orm',
        'aaieduhr/heartphrame-module-menu',
        'aaieduhr/heartphrame-module-auth',
        'aaieduhr/heartphrame-module-editor-html',
        'aaieduhr/simbioza-module-workspace',
        'aaieduhr/simbioza-module-user',
    ];

    /** HR: Provjerava instalaciju i redoslijed svih vlasnika ciljnih podataka. EN: Checks installation and ordering of all target-data owners. */
    public function canLoad(ContainerInterface $container): bool
    {
        $composer = $container->get(ComposerBridge::class);
        $config = $container->get(ConfigInterface::class);
        if (!($composer instanceof ComposerBridge) || !($config instanceof ConfigInterface)) {
            throw new RuntimeException('Simbioza Confluence Import requires ComposerBridge and ConfigInterface.');
        }

        $enabled = $config->getAsArrayWithValuesAsNonEmptyStrings('app.modules.enabled') ?? [];
        $ownPosition = array_search(ModuleSimbiozaConfluenceImport::PACKAGE_NAME, $enabled, true);
        foreach (self::REQUIRED_PACKAGES as $package) {
            $position = array_search($package, $enabled, true);
            if (
                !$composer->isInstalled($package)
                || $position === false
                || ($ownPosition !== false && $position > $ownPosition)
            ) {
                throw new RuntimeException(
                    'Simbioza Confluence Import requires enabled module "' . $package . '" before itself.',
                );
            }
        }

        if (
            !class_exists(Database::class)
            || !class_exists(ModuleAuth::class)
            || !class_exists(ModuleEditorHtml::class)
            || !class_exists(ModuleMenu::class)
            || !class_exists(ModuleWorkspace::class)
            || !class_exists(ModuleSimbiozaUser::class)
        ) {
            throw new RuntimeException('Simbioza Confluence Import required module classes are unavailable.');
        }

        return true;
    }

    /** HR: Čeka da vlasnički moduli registriraju javne servise. EN: Waits for owner modules to register their public services. */
    public function requiresDeferredLoading(): bool
    {
        return true;
    }

    /** HR: Vraća eksplicitne DI tvornice bez refleksijskog autowiringa. EN: Returns explicit DI factories without reflection autowiring. */
    public function getServices(): array
    {
        $services = require __DIR__ . '/config/services.php';

        return is_array($services) ? $services : [];
    }

    /** HR: Registrira administratorski tijek, privatne privitke i stabilne neriješene poveznice. EN: Registers the admin flow, private attachments, and stable unresolved links. */
    public function getBaseRoutes(): array
    {
        $authenticated = [RequireAuthenticatedUserMiddleware::class];

        return [
            [
                'GET',
                '/settings/confluence-import',
                ConfluenceImportController::class . '@index',
                'simbioza-confluence-import.settings',
                $authenticated,
            ],
            [
                'GET',
                '/settings/confluence-import/jobs',
                ConfluenceImportController::class . '@jobs',
                'simbioza-confluence-import.jobs',
                $authenticated,
            ],
            [
                'GET',
                '/settings/confluence-import/report/{uuid}',
                ConfluenceImportController::class . '@report',
                'simbioza-confluence-import.report',
                $authenticated,
            ],
            [
                'GET',
                '/settings/confluence-import/csrf',
                ConfluenceImportController::class . '@csrf',
                'simbioza-confluence-import.csrf',
                $authenticated,
            ],
            [
                'POST',
                '/settings/confluence-import/upload/start',
                ConfluenceImportController::class . '@uploadStart',
                'simbioza-confluence-import.upload.start',
                $authenticated,
            ],
            [
                'POST',
                '/settings/confluence-import/upload/chunk',
                ConfluenceImportController::class . '@uploadChunk',
                'simbioza-confluence-import.upload.chunk',
                $authenticated,
            ],
            [
                'POST',
                '/settings/confluence-import/upload/finish',
                ConfluenceImportController::class . '@uploadFinish',
                'simbioza-confluence-import.upload.finish',
                $authenticated,
            ],
            [
                'POST',
                '/settings/confluence-import/cancel',
                ConfluenceImportController::class . '@cancel',
                'simbioza-confluence-import.cancel',
                $authenticated,
            ],
            [
                'POST',
                '/settings/confluence-import/run',
                ConfluenceImportController::class . '@run',
                'simbioza-confluence-import.run',
                $authenticated,
            ],
            [
                'POST',
                '/settings/confluence-import/process',
                ConfluenceImportController::class . '@process',
                'simbioza-confluence-import.process',
                $authenticated,
            ],
            [
                'GET',
                '/confluence-import/attachment/{uuid}',
                ConfluenceImportController::class . '@attachment',
                'simbioza-confluence-import.attachment',
                [],
            ],
            [
                'GET',
                '/confluence-import/link/{uuid}',
                ConfluenceImportController::class . '@link',
                'simbioza-confluence-import.link',
                [],
            ],
            [
                'GET',
                '/confluence-import/assets.css',
                ConfluenceImportController::class . '@styles',
                'simbioza-confluence-import.assets.css',
                [],
            ],
        ];
    }

    /** HR: Izlaže direktorij prikaza. EN: Exposes the view directory. */
    public function getViewsPath(): string
    {
        return __DIR__ . '/views';
    }

    /** HR: Registrira instalacijsku i read-only inspect naredbu. EN: Registers installation and read-only inspect commands. */
    public function getCommands(): array
    {
        return [
            new CommandDefinition(
                'simbioza-confluence-import',
                'Inspect Confluence XML space archives and install the importer schema.',
                [HpSimbiozaConfluenceImportCommand::class, 'run'],
            ),
            new CommandDefinition(
                'simbioza-confluence-import:install-migration',
                'Copy the Simbioza Confluence Import migration.',
                [HpSimbiozaConfluenceImportCommand::class, 'installMigration'],
            ),
            new CommandDefinition(
                'simbioza-confluence-import:inspect',
                'Read-only inspection of a Confluence XML ZIP space archive.',
                [HpSimbiozaConfluenceImportCommand::class, 'inspect'],
            ),
        ];
    }

    /** HR: Uklanja import metapodatke pri trajnom brisanju područja. EN: Removes import metadata when a Workspace is permanently deleted. */
    public function getEventListeners(): array
    {
        return [
            new EventListener(
                \AaiEduHr\SimbiozaModuleWorkspace\Event\WorkspacePermanentlyDeleting::class,
                PurgeConfluenceWorkspaceImport::class,
            ),
            new EventListener(
                \AaiEduHr\SimbiozaModuleWorkspace\Event\WorkspacePagesPermanentlyDeleting::class,
                PurgeConfluencePageImport::class,
            ),
        ];
    }

    /** HR: Dodaje samo vlastitu stavku u grupu Područja, uz čuvanje korisničkog redoslijeda. EN: Adds only its own item to Workspaces while preserving user ordering. */
    public function getBootstrapCallables(): array
    {
        return [static function (ContainerInterface $container): void {
            $integration = $container->get(ConfluenceImportMenuIntegration::class);
            if ($integration instanceof ConfluenceImportMenuIntegration) {
                $integration->register();
            }
        }];
    }
};
