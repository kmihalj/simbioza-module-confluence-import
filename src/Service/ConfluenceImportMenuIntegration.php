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
            $path = is_object($repository) && method_exists($repository, 'jsonPathForSection')
                ? $repository->jsonPathForSection('settings')
                : null;
            if (!is_string($path) || $path === '') {
                return;
            }

            $items = $this->rows(is_file($path) ? json_decode((string)file_get_contents($path), true) : null);
            $original = $items;
            if (!$this->update($items)) {
                $this->appendToParent($items, 'workspace.settings.group', $this->definition());
            }

            if ($items !== $original) {
                $this->write($path, $items);
            }
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
     * HR: Rekurzivno osvježava postojeću stavku bez promjene njezina redoslijeda.
     * EN: Recursively refreshes an existing item without changing its order.
     *
     * @param list<array<string,mixed>> $items
     */
    private function update(array &$items): bool
    {
        foreach ($items as &$item) {
            if (($item['id'] ?? null) === 'simbioza-confluence-import.settings') {
                $order = $item['order'] ?? null;
                $item = array_replace($item, $this->definition());
                if (is_numeric($order)) {
                    $item['order'] = (int)$order;
                }
                unset($item);
                return true;
            }

            $children = $this->rows($item['children'] ?? null);
            if ($children !== [] && $this->update($children)) {
                $item['children'] = $children;
                unset($item);
                return true;
            }
        }
        unset($item);

        return false;
    }

    /**
     * HR: Dodaje stavku postojećoj grupi područja.
     * EN: Adds the item to the existing Workspace group.
     *
     * @param list<array<string,mixed>> $items
     * @param array<string,mixed> $definition
     */
    private function appendToParent(array &$items, string $parentId, array $definition): bool
    {
        foreach ($items as &$item) {
            if (($item['id'] ?? null) === $parentId) {
                $children = $this->rows($item['children'] ?? null);
                $children[] = $definition;
                $item['children'] = $children;
                unset($item);
                return true;
            }

            $children = $this->rows($item['children'] ?? null);
            if ($children !== [] && $this->appendToParent($children, $parentId, $definition)) {
                $item['children'] = $children;
                unset($item);
                return true;
            }
        }
        unset($item);

        return false;
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

    /**
     * HR: Atomski sprema izmijenjenu konfiguraciju menija.
     * EN: Atomically stores the updated menu configuration.
     *
     * @param list<array<string,mixed>> $items
     */
    private function write(string $path, array $items): void
    {
        $json = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return;
        }

        $temporary = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) !== false) {
            rename($temporary, $path);
        }
    }

    /**
     * HR: Normalizira samo valjane retke konfiguracije.
     * EN: Normalizes only valid configuration rows.
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
}
