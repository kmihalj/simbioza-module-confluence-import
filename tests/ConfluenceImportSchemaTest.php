<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\SimbiozaModuleConfluenceImport\ModuleSimbiozaConfluenceImport;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModuleSimbiozaConfluenceImport::class)]
final class ConfluenceImportSchemaTest extends TestCase
{
    /** HR: Dokazuje da je shema potpuna i reverzibilna na SQLiteu. EN: Proves that the schema is complete and reversible on SQLite. */
    public function testMigrationCreatesAndDropsAllOwnedTables(): void
    {
        $helper = new Helper();
        $database = new Database(new Config($helper, [
            'database' => ['connections' => ['default' => ['driver' => 'sqlite', 'database' => ':memory:']]],
        ]), $helper);
        $migration = require dirname(__DIR__) . '/resources/migrations/initial_simbioza_confluence_import_schema.php';
        self::assertInstanceOf(ReversibleMigrationInterface::class, $migration);

        $migration->up($database);
        foreach ($this->tables() as $table) {
            self::assertTrue($database->schema()->hasTable($table), $table);
        }
        self::assertTrue($database->schema()->hasColumns(
            ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS,
            ['uuid', 'storage_path', 'target_node_id', 'mime_type'],
        ));

        $migration->down($database);
        foreach ($this->tables() as $table) {
            self::assertFalse($database->schema()->hasTable($table), $table);
        }
    }

    /** @return list<string> */
    private function tables(): array
    {
        return [
            ModuleSimbiozaConfluenceImport::TABLE_JOBS,
            ModuleSimbiozaConfluenceImport::TABLE_SPACES,
            ModuleSimbiozaConfluenceImport::TABLE_CONTENT,
            ModuleSimbiozaConfluenceImport::TABLE_IDENTITIES,
            ModuleSimbiozaConfluenceImport::TABLE_GROUPS,
            ModuleSimbiozaConfluenceImport::TABLE_LINKS,
            ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS,
        ];
    }
}
