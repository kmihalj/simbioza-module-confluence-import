<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Command;

use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceExportScanner;
use HeartPhrame\Config\ConfigInterface;
use RuntimeException;

/**
 * EN: CLI for migration installation and read-only Confluence archive inspection.
 *
 * HR: CLI za instalaciju migracije i read-only pregled Confluence arhive.
 */
final readonly class HpSimbiozaConfluenceImportCommand
{
    /**
     * EN: Receives the application root and safe scanner.
     *
     * HR: Prima aplikacijski korijen i sigurni scanner.
     */
    public function __construct(
        private ConfigInterface $config,
        private ConfluenceExportScanner $scanner,
    ) {
    }

    /**
     * EN: Routes a subcommand to installation, inspection, or help.
     *
     * HR: Usmjerava podnaredbu na instalaciju, provjeru ili pomoć.
     *
     * @param list<string> $arguments
     * @param array<string,mixed> $options
     */
    public function run(array $arguments = [], array $options = []): int
    {
        $action = strtolower(trim((string)($arguments[0] ?? 'help')));

        return match ($action) {
            'install', 'install-migration' => $this->installMigration($options),
            'inspect', 'preflight' => $this->inspect(array_slice($arguments, 1)),
            'help', '--help', '-h' => $this->help(),
            default => throw new ConfluenceImportException('Unknown Confluence-import subcommand: ' . $action),
        };
    }

    /**
     * EN: Copies the initial migration into the host application.
     *
     * HR: Kopira početnu migraciju u host aplikaciju.
     *
     * @param array<string,mixed> $options
     */
    public function installMigration(array $options = []): int
    {
        $configured = is_scalar($options['path'] ?? null) ? trim((string)$options['path']) : '';
        $directory = $configured !== ''
            ? $configured
            : rtrim($this->config->getAppRootDir(), DIRECTORY_SEPARATOR) . '/database/migrations';
        if (!str_starts_with($directory, DIRECTORY_SEPARATOR)) {
            $directory = rtrim($this->config->getAppRootDir(), DIRECTORY_SEPARATOR) . '/' . $directory;
        }

        $template = dirname(__DIR__, 2) . '/resources/migrations/initial_simbioza_confluence_import_schema.php';
        if (!is_file($template)) {
            throw new RuntimeException(__('Predložak Confluence-import migracije nije pronađen.'));
        }
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(__('Direktorij migracija nije moguće kreirati.'));
        }

        $target = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . date('YmdHis') . '_install_simbioza_confluence_import_schema.php';
        if (!copy($template, $target)) {
            throw new RuntimeException(__('Confluence-import migraciju nije moguće kopirati.'));
        }

        fwrite(STDOUT, __('Migracija je kreirana: ') . $target . PHP_EOL);

        return 0;
    }

    /**
     * EN: Prints safe preflight JSON without importing.
     *
     * HR: Ispisuje sigurni preflight kao JSON bez importa.
     *
     * @param list<string> $arguments
     */
    public function inspect(array $arguments): int
    {
        $archive = trim((string)($arguments[0] ?? ''));
        if ($archive === '' || !is_file($archive)) {
            throw new ConfluenceImportException(__('Navedite postojeću Confluence XML ZIP arhivu.'));
        }

        $json = json_encode(
            $this->scanner->scan($archive),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        fwrite(STDOUT, $json . PHP_EOL);

        return 0;
    }

    /**
     * EN: Prints beginner-friendly CLI examples.
     *
     * HR: Ispisuje početničke CLI primjere.
     */
    private function help(): int
    {
        fwrite(STDOUT, <<<'TEXT'
Simbioza Confluence Import

  vendor/bin/hph simbioza-confluence-import:install-migration
  vendor/bin/hph simbioza-confluence-import:inspect /path/to/confluence-space.xml.zip

Inspection is read-only. Confirmed content import, identity mapping, and progress
are available in Settings > Workspaces > Confluence import.
TEXT);
        fwrite(STDOUT, PHP_EOL);

        return 0;
    }
}
