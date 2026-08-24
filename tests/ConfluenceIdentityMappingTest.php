<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use AaiEduHr\SimbiozaModuleConfluenceImport\ModuleSimbiozaConfluenceImport;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportRepository;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfluenceImportRepository::class)]
final class ConfluenceIdentityMappingTest extends TestCase
{
    /**
     * HR: Ponovljeni space import ne smije poništiti niti preusmjeriti potvrđeni račun.
     * EN: A repeated space import must not clear or redirect a confirmed account.
     */
    public function testConfirmedMappingIsReusedAcrossImports(): void
    {
        [$repository, $database] = $this->environment();
        $user = ['source_key' => 'confluence-user-1', 'email' => 'user@example.test'];
        $repository->mapIdentity($user, 7, true, 1);
        $repository->mapIdentity($user, null, false, 2);

        self::assertSame(7, $repository->mappedUserId('confluence-user-1'));
        $row = $database->table(ModuleSimbiozaConfluenceImport::TABLE_IDENTITIES)
            ->where('source_user_key', '=', 'confluence-user-1')->first();
        self::assertSame(1, (int)$row['job_id']);
        self::assertTrue((bool)$row['confirmed']);

        $this->expectException(ConfluenceImportException::class);
        $repository->mapIdentity($user, 8, true, 2);
    }

    /** HR: Jednako pravilo vrijedi za potvrđeno mapiranje grupe. EN: The same rule applies to a confirmed group mapping. */
    public function testConfirmedGroupMappingIsReusedAcrossImports(): void
    {
        [$repository, $database] = $this->environment();
        $repository->mapGroup('source-group', 12, true, 1);
        $repository->mapGroup('source-group', null, false, 2);

        self::assertSame(12, $repository->mappedGroupId('source-group'));
        $row = $database->table(ModuleSimbiozaConfluenceImport::TABLE_GROUPS)
            ->where('source_group_name', '=', 'source-group')->first();
        self::assertSame(1, (int)$row['job_id']);

        $this->expectException(ConfluenceImportException::class);
        $repository->mapGroup('source-group', 13, true, 2);
    }

    /** @return array{ConfluenceImportRepository,Database} */
    private function environment(): array
    {
        $helper = new Helper();
        $database = new Database(new Config($helper, [
            'database' => ['connections' => ['default' => ['driver' => 'sqlite', 'database' => ':memory:']]],
        ]), $helper);
        $migration = require dirname(__DIR__) . '/resources/migrations/initial_simbioza_confluence_import_schema.php';
        $migration->up($database);

        return [new ConfluenceImportRepository($database), $database];
    }
}
