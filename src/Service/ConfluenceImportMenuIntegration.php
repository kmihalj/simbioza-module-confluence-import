<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/** HR: Dodaje Confluence import u postojeću grupu postavki područja. EN: Adds Confluence import to the existing Workspace settings group. */
final readonly class ConfluenceImportMenuIntegration
{
    private const MENU_REPOSITORY = \AaiEduHr\HeartPhrameModuleMenu\Service\MenuConfigRepository::class;

    /** HR: Prima container kako bi Menu ostao zamjenjiva integracija. EN: Receives the container so Menu remains a replaceable integration. */
    public function __construct(
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }

    /** HR: Idempotentno osvježava samo stavku u vlasništvu ovog modula. EN: Idempotently refreshes only the item owned by this module. */
    public function register(): void
    {
        if (!class_exists(self::MENU_REPOSITORY)) {
            return;
        }

        try {
            $repository = $this->container->get(self::MENU_REPOSITORY);
            if (!is_object($repository) || !method_exists($repository, 'upsertItemsForSection')) {
                return;
            }
            $repository->upsertItemsForSection('settings', [$this->definition()]);
        } catch (Throwable $throwable) {
            // HR: Settings izbornik je pogodnost i ne smije zaustaviti import ili CLI.
            // EN: The Settings menu is a convenience and must not stop import or CLI.
            $this->logger->warning('Confluence Import settings-menu integration failed.', [
                'module' => 'simbioza-confluence-import',
                'exception' => $throwable,
            ]);
        }
    }

    /**
     * HR: Vraća kanonsku stavku izbornika modula.
     * EN: Returns the module's canonical menu item.
     *
     * @return array<string,mixed>
     */
    private function definition(): array
    {
        return [
            'id' => 'simbioza-confluence-import.settings',
            'parent_id' => 'workspace.settings.group',
            'label' => ['hr' => 'Confluence import', 'en' => 'Confluence import'],
            'route' => 'simbioza-confluence-import.settings',
            'url' => '',
            'query' => '',
            'order' => 80,
            'enabled' => true,
            'level' => 1,
        ];
    }
}
