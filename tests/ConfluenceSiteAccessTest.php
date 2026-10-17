<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\HeartPhrameModuleAuth\ModuleAuth;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthGroupService;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthUserService;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use AaiEduHr\SimbiozaModuleConfluenceImport\ModuleSimbiozaConfluenceImport as Module;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceArchive;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceExportReader;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportConfig;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportRepository;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteAccessService;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteAuth;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSitePrincipals;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use ZipArchive;

/**
 * EN: Uses isolated SQLite and real existing Auth services, never the live HFC database/configuration.
 *
 * HR: Koristi izolirani SQLite i stvarne postojeće Auth servise, nikada živu HFC bazu/konfiguraciju.
 */
final class ConfluenceSiteAccessTest extends TestCase
{
    private string $root;
    private Database $db;
    private ConfluenceImportRepository $repository;
    private ConfluenceSiteAccessService $service;
    private ConfluenceSiteAuth $auth;
    private EventDispatcherInterface $events;
    private array $job;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/confluence-site-access-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/data/confluence-import/uploads', 0770, true);
        $helper = new Helper();
        $config = new Config($helper, ['database' => ['connections' => ['default' => ['driver' => 'sqlite', 'database' => ':memory:']]]], $this->root);
        $this->db = new Database($config, $helper);
        $this->authSchema();
        (require __DIR__ . '/../resources/migrations/initial_simbioza_confluence_import_schema.php')->up($this->db);
        $this->repository = new ConfluenceImportRepository($this->db);
        $moduleConfig = new ConfluenceImportConfig($config, dirname(__DIR__));
        $archive = new ConfluenceArchive($moduleConfig);
        $principals = new ConfluenceSitePrincipals($archive, new ConfluenceExportReader($archive), $moduleConfig);
        $groups = new AuthGroupService($this->db);
        $this->events = new class implements EventDispatcherInterface {
            public array $events = [];
            public function dispatch(object $event): object
            {
                $this->events[] = $event;
                return $event;
            }
        };
        $this->auth = new ConfluenceSiteAuth($this->db, new AuthUserService($this->db, null, $groups), $groups, null, $this->events);
        $this->service = new ConfluenceSiteAccessService($this->repository, $principals, $this->auth);
        $this->job = $this->sourceJob();
        $this->service->prepare($this->job['uuid'], 1);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testReviewCreatesNoAccountsAndApplyCreatesInactiveAccountsAndPortableMappings(): void
    {
        $before = $this->businessRows();
        $preview = $this->review(['internal-user:1' => '__create__'], ['internal-group:11' => '__create__']);
        self::assertSame($before, $this->businessRows());
        self::assertSame(1, $preview['summary']['users_create']);
        self::assertSame(1, $preview['summary']['membership_candidates']);
        self::assertSame(['alice', 'source-staff'], array_column($preview['decisions'], 'target_label'));
        $report = $this->service->apply($this->job['uuid'], 1, $preview['token']);
        self::assertSame('completed', $report['status']);
        self::assertSame('site_access', $report['operation']);
        self::assertSame('', $report['archive_path']);
        self::assertSame(1, $report['summary']['memberships']['added']);
        $id = $this->repository->mappedUserId(ConfluenceSiteAccessService::mappingKey('https://source.test', 'users', 'confluence-user:alice-key'));
        self::assertNotNull($id);
        $user = $this->db->table(ModuleAuth::TABLE_AUTH_USERS)->where('id', '=', $id)->first();
        self::assertFalse((bool)$user['is_active']);
        self::assertFalse((bool)$user['is_admin']);
        self::assertNull($user['password_hash']);
        foreach ($this->db->table(ModuleAuth::TABLE_AUTH_USER_PROVIDER_ACCESS)->where('user_id', '=', $id)->get() as $access) {
            self::assertFalse((bool)$access['enabled']);
        }
        self::assertStringNotContainsString('target_user_id', $report['summary_json']);
        self::assertCount(1, $this->events->events);
        $after = $this->businessRows();
        self::assertSame($report['uuid'], $this->service->apply($this->job['uuid'], 1, $preview['token'])['uuid']);
        self::assertSame($after, $this->businessRows());
        self::assertCount(1, $this->events->events);
    }

    public function testMapPreservesTargetCredentialsProviderAndAdministratorState(): void
    {
        $before = $this->db->table(ModuleAuth::TABLE_AUTH_USERS)->get();
        $preview = $this->review(['internal-user:1' => '1'], ['internal-group:11' => '2']);
        $report = $this->service->apply($this->job['uuid'], 1, $preview['token']);
        self::assertSame($before, $this->db->table(ModuleAuth::TABLE_AUTH_USERS)->get());
        self::assertSame(0, $report['summary']['users_created']);
        self::assertSame(1, $report['summary']['users_mapped']);
        self::assertSame(1, $report['summary']['memberships']['added']);
    }

    public function testAdditiveMembershipsPreserveAutomaticManualAndExcludedRows(): void
    {
        foreach ([['automatic', 2], ['manual', 3], ['excluded', 4]] as [$source, $group]) {
            $this->db->table(ModuleAuth::TABLE_AUTH_USER_GROUPS)->insert(['user_id' => 1, 'group_id' => $group, 'source' => $source,
                'source_field_key' => 'organization', 'source_provider' => 'saml']);
        }
        $before = $this->db->table(ModuleAuth::TABLE_AUTH_USER_GROUPS)->get();
        $result = $this->db->transaction(fn(): array => $this->auth->addMemberships([
            ['user_id' => 1, 'group_id' => 2], ['user_id' => 1, 'group_id' => 3], ['user_id' => 1, 'group_id' => 4],
        ]));
        self::assertSame(0, $result['added']);
        self::assertSame(2, $result['existing']);
        self::assertSame(1, $result['excluded']);
        self::assertSame($before, $this->db->table(ModuleAuth::TABLE_AUTH_USER_GROUPS)->get());
        self::assertSame([], $this->events->events);
    }

    public function testUnselectedUsersAndMembershipsAreSkippedWithoutAdminFallback(): void
    {
        $before = $this->businessRows();
        $preview = $this->review([], [], true);
        self::assertSame(0, $preview['summary']['membership_candidates']);
        $report = $this->service->apply($this->job['uuid'], 1, $preview['token']);
        self::assertSame($before, $this->businessRows());
        self::assertSame(1, $report['summary']['memberships_unresolved']);
        self::assertSame(0, $report['summary']['users_mapped']);
    }

    public function testSourceNamespaceAndConfirmedMappingsSurviveAnotherArchive(): void
    {
        $preview = $this->review(['internal-user:1' => '1'], []);
        $this->service->apply($this->job['uuid'], 1, $preview['token']);
        $other = $this->sourceJob();
        $this->service->prepare($other['uuid'], 1);
        $review = $this->service->review($other['uuid'], 1, 'HTTPS://SOURCE.TEST:443/', [], [], false);
        self::assertSame(1, $review['summary']['users_map']);
        self::assertNull($this->repository->mappedUserId(ConfluenceSiteAccessService::mappingKey('https://other.test', 'users', 'internal-user:1')));
        self::assertSame(1, $this->repository->mappedUserId(ConfluenceSiteAccessService::mappingKey('https://source.test', 'users', 'internal-user:1')));
        $this->expectException(ConfluenceImportException::class);
        $this->service->review($other['uuid'], 1, 'https://source.test', ['internal-user:1' => '2'], [], false);
    }

    public function testChangedTargetSnapshotRejectsApplyBeforeAnyCreation(): void
    {
        $preview = $this->review(['internal-user:1' => '__create__'], []);
        $this->db->table(ModuleAuth::TABLE_AUTH_USERS)->where('id', '=', 2)->update(['is_active' => 1]);
        $before = $this->businessRows();
        try {
            $this->service->apply($this->job['uuid'], 1, $preview['token']);
            self::fail('Stale preview was accepted.');
        } catch (ConfluenceImportException) {
            self::assertSame($before, $this->businessRows());
            self::assertCount(1, $this->db->table(Module::TABLE_JOBS)->get());
        }
    }

    public function testExpiredPreviewCannotApplyEvenWhenTargetsAreUnchanged(): void
    {
        $preview = $this->review(['internal-user:1' => '__create__'], []);
        $job = $this->repository->jobByUuid($this->job['uuid']);
        $options = $job['options'];
        $options['site_access']['preview']['created_at'] = time() - 1900;
        $this->repository->updateJob((int)$job['id'], ['options_json' => json_encode($options, JSON_THROW_ON_ERROR)]);
        $before = $this->businessRows();
        try {
            $this->service->apply($this->job['uuid'], 1, $preview['token']);
            self::fail('Expired preview was accepted.');
        } catch (ConfluenceImportException) {
            self::assertSame($before, $this->businessRows());
            self::assertSame([], $this->db->table(Module::TABLE_IDENTITIES)->get());
        }
    }

    public function testWrongOwnerAndUnknownSourceCannotSelectTargets(): void
    {
        try {
            $this->service->review($this->job['uuid'], 2, 'https://source.test', [], [], true);
            self::fail('Wrong owner was accepted.');
        } catch (ConfluenceImportException) {
            self::assertCount(2, $this->auth->targetUsers());
        }
        $this->expectException(ConfluenceImportException::class);
        $this->review(['internal-user:unknown' => '1'], []);
    }

    public function testAdministratorGroupCannotBeMappedOrAssigned(): void
    {
        self::assertNotContains(1, array_column($this->auth->targetGroups(), 'id'));
        $this->expectException(ConfluenceImportException::class);
        $this->review([], ['internal-group:11' => '1']);
    }

    public function testInactiveTargetsAreSearchableAndNoSecretFieldsAreReturned(): void
    {
        $page = $this->service->userPage($this->job['uuid'], 1, 'pending', 1);
        self::assertSame([2], array_column($page['results'], 'id'));
        self::assertStringNotContainsString('SECRET', json_encode($page));
        self::assertSame(['id', 'label'], array_keys($page['results'][0]));
    }

    public function testLoginCollisionRequiresExplicitMapping(): void
    {
        $this->db->table(ModuleAuth::TABLE_AUTH_USERS)->where('id', '=', 2)->update(['login_identifier' => 'ALICE']);
        $this->expectException(ConfluenceImportException::class);
        $this->review(['internal-user:1' => '__create__'], []);
    }

    public function testCaseOnlyDifferenceBetweenLoginAndOwnEmailIsNotACollision(): void
    {
        $job = $this->sourceJob('alice@example.test', 'ALICE@example.test');
        $this->service->prepare($job['uuid'], 1);
        $preview = $this->service->review($job['uuid'], 1, 'https://source.test', ['internal-user:1' => '__create__'], [], false);
        self::assertSame(1, $preview['summary']['users_create']);
        self::assertSame(1, $this->service->apply($job['uuid'], 1, $preview['token'])['summary']['users_created']);
    }

    public function testReviewChoicesOnOtherPagesAreRetainedAndSkipIsExplicit(): void
    {
        $this->review(['internal-user:1' => '1'], []);
        $second = $this->review([], ['internal-group:11' => '2']);
        self::assertSame(1, $second['summary']['users_map']);
        $third = $this->review(['internal-user:1' => ''], []);
        self::assertSame(0, $third['summary']['users_map']);
        self::assertSame(1, $third['summary']['groups_map']);
    }

    public function testInvalidSourceUrlDoesNotFetchOrAcceptCredentials(): void
    {
        $this->expectException(ConfluenceImportException::class);
        $this->service->review($this->job['uuid'], 1, 'https://user:SECRET@source.test/?token=SECRET', [], [], false);
    }

    public function testDirectoryProjectionHasNoNPlusOneQueriesOrSecretColumns(): void
    {
        for ($id = 3; $id <= 503; ++$id) {
            $this->db->table(ModuleAuth::TABLE_AUTH_USERS)->insert(['id' => $id, 'login_identifier' => 'fixture-' . $id]);
        }
        $sql = [];
        $this->db->listen(static function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });
        self::assertCount(503, $this->auth->targetUsers());
        self::assertCount(3, $this->auth->targetGroups());
        self::assertCount(2, $sql);
        self::assertStringNotContainsString('password_hash', implode(' ', $sql));
        self::assertStringNotContainsString('*', implode(' ', $sql));
    }

    public function testInvalidDraftCanBeResetWithoutRemovingConfirmedMappings(): void
    {
        $preview = $this->review(['internal-user:1' => '2'], []);
        $this->service->apply($this->job['uuid'], 1, $preview['token']);
        $this->service->resetReview($this->job['uuid'], 1);
        self::assertSame('2', $this->service->state($this->job['uuid'], 1)['choices']['users']['internal-user:1']);
        self::assertSame(2, $this->repository->mappedUserId(ConfluenceSiteAccessService::mappingKey('https://source.test', 'users', 'internal-user:1')));
    }

    public function testFailedCreationRollsBackNewUsersMappingsAndReport(): void
    {
        $preview = $this->review(['internal-user:1' => '__create__'], ['internal-group:11' => '__create__']);
        // EN: Simulate a late storage constraint after the new account has been inserted.
        //
        // HR: Simuliraj kasno ograničenje spremišta nakon umetanja novog računa.
        $this->db->getConnection()->exec('CREATE TRIGGER reject_group BEFORE INSERT ON ' . ModuleAuth::TABLE_AUTH_GROUPS . ' BEGIN SELECT RAISE(ABORT, "fixture rejection"); END;');
        $before = $this->businessRows();
        try {
            $this->service->apply($this->job['uuid'], 1, $preview['token']);
            self::fail('Failed group storage was accepted.');
        } catch (\RuntimeException) {
            self::assertSame($before, $this->businessRows());
            self::assertSame([], $this->db->table(Module::TABLE_IDENTITIES)->get());
            self::assertCount(1, $this->db->table(Module::TABLE_JOBS)->get());
            self::assertSame([], $this->events->events);
        }
    }

    public function testCompletedIdentityReportAndMappingsEnterBackupBeforeContentImport(): void
    {
        $preview = $this->review(['internal-user:1' => '1'], ['internal-group:11' => '2']);
        $report = $this->service->apply($this->job['uuid'], 1, $preview['token']);
        $config = new ConfluenceImportConfig(new Config(new Helper(), [], $this->root), dirname(__DIR__));
        $container = new class ($this->db, $config) implements \Psr\Container\ContainerInterface {
            public function __construct(private readonly Database $db, private readonly ConfluenceImportConfig $config)
            {
            }
            public function get(string $id): mixed
            {
                return $id === Database::class ? $this->db : $this->config;
            }
            public function has(string $id): bool
            {
                return in_array($id, [Database::class, ConfluenceImportConfig::class], true);
            }
        };
        $factory = (require __DIR__ . '/../config/services.php')['heartphrame.backup.provider.simbioza-confluence-import'];
        $provider = $factory($container);
        $definitions = (new \ReflectionMethod($provider, 'tableDefinitions'))->invoke($provider);
        $definitions = array_column($definitions, null, 'dataset');
        $scope = new \AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope('site');
        $context = new \AaiEduHr\HeartPhrameModuleBackup\Value\BackupExportContext($scope, []);
        $export = new \ReflectionMethod($provider, 'exportRows');
        $jobs = iterator_to_array($export->invoke($provider, $definitions['jobs'], $context), false);
        self::assertCount(1, $jobs);
        self::assertSame($report['uuid'], $jobs[0]['uuid']);
        self::assertSame('ready', $this->repository->jobByUuid($this->job['uuid'])['status']);
        $identities = iterator_to_array($export->invoke($provider, $definitions['identities'], $context), false);
        self::assertCount(2, $identities);
        self::assertSame((int)$report['id'], (int)$identities[0]['job_id']);
        $restore = new \AaiEduHr\HeartPhrameModuleBackup\Value\BackupImportContext($scope, 'merge', []);
        $restore->state->map('auth.user', 1, 901);
        $restore->state->map('simbioza-confluence-import.job', (int)$report['id'], 902);
        $mapped = (new \ReflectionMethod($provider, 'transformImportRecord'))->invoke($provider, $definitions['identities'], $identities[0], $restore);
        self::assertSame(901, (int)$mapped['target_user_id']);
        self::assertSame(902, (int)$mapped['job_id']);
        self::assertStringNotContainsString('target_user_id', $jobs[0]['summary_json']);
        self::assertSame('', $jobs[0]['archive_path']);
    }

    /** @param array<string,string> $users @param array<string,string> $groups */
    public function testAutomaticMatchUsesExistingInactiveAccountWithoutChangingAuthOrAdminState(): void
    {
        $job = $this->sourceJob('PENDING', '');
        $this->service->prepare($job['uuid'], 1);
        $before = $this->businessRows();
        $preview = $this->service->review($job['uuid'], 1, 'https://source.test', [], [], false, true, true);
        self::assertSame($before, $this->businessRows());
        self::assertSame(1, $preview['summary']['users_auto_map']);
        self::assertSame(0, $preview['summary']['users_create']);
        self::assertSame('username', $preview['decisions'][0]['reason']);
        $report = $this->service->apply($job['uuid'], 1, $preview['token']);
        self::assertSame($before, $this->businessRows());
        self::assertSame(1, $report['summary']['users_auto_map']);
        self::assertSame('username', $report['summary']['decisions'][0]['reason']);
        self::assertSame('2', $this->service->state($job['uuid'], 1)['choices']['users']['internal-user:1']);
    }

    public function testGlobalUnmappedCreationCoversWholeCatalogAndPreservesExplicitSkips(): void
    {
        $extra = array_map(static fn(int $id): array => ['login' => 'new-' . $id, 'email' => ''], range(2, 70));
        $job = $this->sourceJob('new-1', '', $extra);
        $this->service->prepare($job['uuid'], 1);
        $before = $this->businessRows();
        $preview = $this->service->review($job['uuid'], 1, 'https://source.test', ['internal-user:1' => ''], [], false, true, true);
        self::assertSame(69, $preview['summary']['users_create']);
        self::assertSame(69, $preview['summary']['users_auto_create']);
        self::assertSame(1, $preview['summary']['users_skip']);
        self::assertSame($before, $this->businessRows());
        $disabled = $this->service->review($job['uuid'], 1, 'https://source.test', [], [], false, true, false);
        self::assertSame(0, $disabled['summary']['users_create']);
        $enabled = $this->service->review($job['uuid'], 1, 'https://source.test', [], [], false, true, true);
        $report = $this->service->apply($job['uuid'], 1, $enabled['token']);
        self::assertSame(69, $report['summary']['users_created']);
        self::assertSame(69, $report['summary']['users_auto_create']);
        self::assertCount(71, $this->db->table(ModuleAuth::TABLE_AUTH_USERS)->get());
        self::assertSame('3', $this->service->state($job['uuid'], 1)['choices']['users']['internal-user:2']);
        self::assertSame('', $this->service->state($job['uuid'], 1)['choices']['users']['internal-user:1']);
    }

    public function testManualMapSkipAndReturningToAutomaticRulesArePreservedAcrossPages(): void
    {
        $job = $this->sourceJob('pending', '');
        $this->service->prepare($job['uuid'], 1);
        $manual = $this->service->review($job['uuid'], 1, 'https://source.test', ['internal-user:1' => '1'], [], false, true, true);
        self::assertSame('manual', $manual['decisions'][0]['reason']);
        self::assertSame('admin', $manual['decisions'][0]['target_label']);
        $skip = $this->service->review($job['uuid'], 1, 'https://source.test', ['internal-user:1' => ''], [], false, true, true);
        self::assertSame(1, $skip['summary']['users_skip']);
        $anotherPage = $this->service->review($job['uuid'], 1, 'https://source.test', [], [], false, true, true);
        self::assertSame(1, $anotherPage['summary']['users_skip']);
        $automatic = $this->service->review($job['uuid'], 1, 'https://source.test', ['internal-user:1' => '__auto__'], [], false, true, true);
        self::assertSame('username', $automatic['decisions'][0]['reason']);
        self::assertSame('pending', $automatic['decisions'][0]['target_label']);
        self::assertSame([], $this->service->state($job['uuid'], 1)['manual_users']);
    }

    public function testGlobalCreationReportsSourceCollisionsForManualResolution(): void
    {
        $job = $this->sourceJob('same-login', '', [['login' => 'SAME-LOGIN', 'email' => '']]);
        $this->service->prepare($job['uuid'], 1);
        $before = $this->businessRows();
        $preview = $this->service->review($job['uuid'], 1, 'https://source.test', [], [], false, true, true);
        self::assertSame(0, $preview['summary']['users_create']);
        // EN: The ambiguous author reference is deliberately not merged with either directory account.
        //
        // HR: Dvosmislena autorska referenca namjerno se ne spaja ni s jednim računom imenika.
        self::assertSame(2, $preview['summary']['users_conflict']);
        self::assertSame(2, count($this->service->state($job['uuid'], 1)['choices']['user_conflicts']));
        self::assertSame('skipped', $preview['decisions'][0]['action']);
        self::assertSame('reference_only', $preview['decisions'][0]['reason']);
        self::assertSame($before, $this->businessRows());
    }

    public function testUnknownAutomaticResetCannotBypassSourceIdValidation(): void
    {
        $this->expectException(ConfluenceImportException::class);
        $this->service->review($this->job['uuid'], 1, 'https://source.test', ['unknown' => '__auto__'], [], false, true, true);
    }

    /**
     * EN: Automatic group review is read-only; Apply and retry preserve ordinary groups and reports.
     *
     * HR: Automatski pregled grupa ne zapisuje podatke; Primjena i ponavljanje čuvaju obične grupe i izvještaje.
     */
    public function testAutomaticGroupsCreateOnlyOnApplyAndReuseConfirmedMappings(): void
    {
        $before = $this->businessRows();
        $preview = $this->service->review($this->job['uuid'], 1, 'https://source.test', [], [], false, false, false, true, true);
        self::assertSame(1, $preview['summary']['groups_auto_create']);
        self::assertSame($before, $this->businessRows());
        $report = $this->service->apply($this->job['uuid'], 1, $preview['token']);
        self::assertSame(1, $report['summary']['groups_created']);
        self::assertSame('group_auto_create', $report['summary']['decisions'][0]['reason']);
        self::assertSame($report['uuid'], $this->service->apply($this->job['uuid'], 1, $preview['token'])['uuid']);
        $second = $this->service->review($this->job['uuid'], 1, 'https://source.test', [], [], false, false, false, true, true);
        self::assertSame(0, $second['summary']['groups_create']);
        self::assertSame(1, $second['summary']['groups_map']);
    }

    /**
     * EN: Explicit skips/maps survive global policies; returning to automatic clears only that override.
     *
     * HR: Ručno preskakanje/mapiranje preživljava skupna pravila; povratak na automatsko briše samo taj odabir.
     */
    public function testAutomaticGroupMappingAndManualOverrides(): void
    {
        $this->db->table(ModuleAuth::TABLE_AUTH_GROUPS)->where('id', '=', 2)->update(['group_name' => 'SOURCE-STAFF']);
        $review = fn(array $choices): array => $this->service->review($this->job['uuid'], 1, 'https://source.test', [], $choices, false, false, false, true, true);
        self::assertSame(1, $review([])['summary']['groups_auto_map']);
        self::assertSame(1, $review(['internal-group:11' => ''])['summary']['groups_skip']);
        self::assertSame(1, $review([])['summary']['groups_skip']);
        self::assertSame(1, $review(['internal-group:11' => '__auto__'])['summary']['groups_auto_map']);
        self::assertSame(0, $review(['internal-group:11' => '3'])['summary']['groups_auto_map']);
        self::assertSame('3', $this->service->state($this->job['uuid'], 1)['choices']['groups']['internal-group:11']);
    }

    /**
     * EN: A protected or disabled group's name cannot be auto-created as a second ordinary group.
     *
     * HR: Naziv zaštićene ili isključene grupe ne smije automatski stvoriti drugu običnu grupu.
     */
    public function testUnavailableGroupNamesStayUnresolved(): void
    {
        $this->db->table(ModuleAuth::TABLE_AUTH_GROUPS)->where('id', '=', 2)->update(['group_name' => 'source-staff', 'is_enabled' => 0]);
        $preview = $this->service->review($this->job['uuid'], 1, 'https://source.test', [], [], false, false, false, true, true);
        self::assertSame(0, $preview['summary']['groups_create']);
        self::assertSame(1, $preview['summary']['groups_conflict']);
    }

    private function review(array $users, array $groups, bool $memberships = true): array
    {
        return $this->service->review($this->job['uuid'], 1, 'https://source.test', $users, $groups, $memberships);
    }

    /**
     * EN: Explicit duplicate-source choices create one account and preserve every source alias/membership.
     *
     * HR: Izričit odabir duplih izvora stvara jedan račun i čuva svaku izvornu vezu/članstvo.
     */
    public function testDuplicateSourcesShareOneNewInactiveAccountInAnyOrder(): void
    {
        $job = $this->sourceJob('old-login', 'shared@example.test', [['login' => 'new-login', 'email' => 'shared@example.test']]);
        $this->service->prepare($job['uuid'], 1);
        $before = $this->businessRows();
        $preview = $this->service->review($job['uuid'], 1, 'https://source.test', [
            'internal-user:1' => '__source__:internal-user:2', 'internal-user:2' => '__create__',
        ], ['internal-group:11' => '__create__'], true);
        self::assertSame($before, $this->businessRows());
        self::assertSame(1, $preview['summary']['users_create']);
        self::assertSame(1, $preview['summary']['users_map']);
        self::assertSame(['new-login', 'new-login'], array_column(array_slice($preview['decisions'], 0, 2), 'target_label'));
        $picker = $this->service->userPage($job['uuid'], 1, 'new-login', 1);
        self::assertContains('__source__:internal-user:2', array_column($picker['results'], 'id'));
        $report = $this->service->apply($job['uuid'], 1, $preview['token']);
        self::assertSame(1, $report['summary']['users_created']);
        self::assertSame(1, $report['summary']['users_mapped']);
        self::assertCount(3, $this->db->table(ModuleAuth::TABLE_AUTH_USERS)->get());
        $map = fn(string $id): ?int => $this->repository->mappedUserId(ConfluenceSiteAccessService::mappingKey('https://source.test', 'users', $id));
        self::assertSame($map('internal-user:1'), $map('internal-user:2'));
        self::assertSame($map('internal-user:1'), $map('confluence-user:alice-key'));
        self::assertSame(1, $report['summary']['memberships']['added']);
        $user = $this->db->table(ModuleAuth::TABLE_AUTH_USERS)->where('id', '=', $map('internal-user:2'))->first();
        self::assertFalse((bool)$user['is_active']);
        self::assertFalse((bool)$user['is_admin']);
        self::assertNull($user['password_hash']);
        self::assertSame($report['uuid'], $this->service->apply($job['uuid'], 1, $preview['token'])['uuid']);
    }

    /**
     * EN: Source aliases cannot bypass confirmed mappings or bind unknown/skipped accounts.
     *
     * HR: Veze izvora ne smiju zaobići potvrđena mapiranja ni vezati nepoznate/preskočene račune.
     */
    public function testSourceAliasesCannotRedirectConfirmedMappings(): void
    {
        $job = $this->sourceJob('first', '', [['login' => 'second', 'email' => '']]);
        $this->service->prepare($job['uuid'], 1);
        $preview = $this->service->review($job['uuid'], 1, 'https://source.test', ['internal-user:1' => '1'], [], false);
        $this->service->apply($job['uuid'], 1, $preview['token']);
        $before = $this->businessRows();
        foreach (
            [['internal-user:1' => '__source__:internal-user:2', 'internal-user:2' => '2'],
            ['internal-user:2' => '__source__:unknown'],
            ['internal-user:2' => '__source__:internal-user:2']] as $choices
        ) {
            try {
                $this->service->review($job['uuid'], 1, 'https://source.test', $choices, [], false);
                self::fail('Invalid alias accepted.');
            } catch (ConfluenceImportException) {
                self::assertSame($before, $this->businessRows());
            }
        }
    }

    private function businessRows(): array
    {
        return array_map(
            fn(string $table): array => $this->db->table($table)->orderBy('id', 'ASC')->get(),
            [ModuleAuth::TABLE_AUTH_USERS, ModuleAuth::TABLE_AUTH_GROUPS, ModuleAuth::TABLE_AUTH_USER_GROUPS]
        );
    }

    private function sourceJob(string $login = 'alice', string $email = 'alice@example.test', array $extraUsers = []): array
    {
        $path = $this->root . '/data/confluence-import/uploads/' . bin2hex(random_bytes(8)) . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('exportDescriptor.properties', "exportType=site\nbackupAttachments=false\n");
        $xml = '<hibernate-generic>
         <object class="InternalUser"><id name="id">1</id><property name="name">SOURCE_LOGIN</property><property name="emailAddress">SOURCE_EMAIL</property><property name="directory" class="Directory"><id name="id">10</id></property></object>
         <object class="ConfluenceUserImpl"><id name="id">alice-key</id><property name="name">SOURCE_LOGIN</property></object>
         <object class="InternalGroup"><id name="id">11</id><property name="name">source-staff</property><property name="directory" class="Directory"><id name="id">10</id></property></object>
         <object class="HibernateMembership"><id name="id">21</id><property name="parentGroup" class="Group"><id name="id">11</id></property><property name="userMember" class="User"><id name="id">1</id></property></object>
         </hibernate-generic>';
        $xml = strtr($xml, ['SOURCE_LOGIN' => htmlspecialchars($login, ENT_XML1), 'SOURCE_EMAIL' => htmlspecialchars($email, ENT_XML1)]);
        $extra = '';
        foreach ($extraUsers as $offset => $user) {
            $extra .= '<object class="InternalUser"><id name="id">' . ($offset + 2) . '</id><property name="name">'
                . htmlspecialchars($user['login'], ENT_XML1) . '</property><property name="emailAddress">'
                . htmlspecialchars($user['email'], ENT_XML1) . '</property></object>';
        }
        $zip->addFromString('entities.xml', str_replace('</hibernate-generic>', $extra . '</hibernate-generic>', $xml));
        $zip->close();
        $job = $this->repository->createUploadJob('site.zip', $path, filesize($path), 0, 1, time() + 3600);
        $this->repository->updateJob((int)$job['id'], ['status' => 'ready', 'stage' => 'inventory', 'summary_json' => json_encode([
            'inventory_only' => true, 'source_sha256' => hash_file('sha256', $path), 'spaces' => [],
        ], JSON_THROW_ON_ERROR)]);
        return $this->repository->jobByUuid($job['uuid']);
    }

    /**
     * EN: Minimal real Auth schema only; do not execute Auth configuration migrations against HFC.
     *
     * HR: Samo minimalna stvarna Auth shema; ne izvršavati Auth konfiguracijske migracije nad HFC-om.
     */
    private function authSchema(): void
    {
        $pdo = $this->db->getConnection();
        $pdo->exec('CREATE TABLE ' . ModuleAuth::TABLE_AUTH_USERS . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, login_identifier TEXT UNIQUE, password_hash TEXT, is_admin INTEGER DEFAULT 0, is_active INTEGER DEFAULT 0, auth_source TEXT, last_login_at TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE ' . ModuleAuth::TABLE_AUTH_USER_PROVIDER_ACCESS . ' (user_id INTEGER, provider TEXT, enabled INTEGER, created_at TEXT, updated_at TEXT, PRIMARY KEY(user_id,provider))');
        $pdo->exec('CREATE TABLE ' . ModuleAuth::TABLE_AUTH_USER_IDENTITIES . ' (id INTEGER PRIMARY KEY, user_id INTEGER, provider_profile_id INTEGER, subject_identifier TEXT, login_identifier TEXT, email TEXT, email_aliases_json TEXT, email_verified INTEGER)');
        $pdo->exec('CREATE TABLE ' . ModuleAuth::TABLE_AUTH_GROUPS . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, group_key TEXT UNIQUE, group_name TEXT, is_system INTEGER DEFAULT 0, is_enabled INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE ' . ModuleAuth::TABLE_AUTH_USER_GROUPS . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, group_id INTEGER, source TEXT, source_field_key TEXT, source_provider TEXT, created_at TEXT, updated_at TEXT)');
        foreach ([[1, 'admin', 1, 1], [2, 'pending', 0, 0]] as [$id, $login, $admin, $active]) {
            $this->db->table(ModuleAuth::TABLE_AUTH_USERS)->insert(['id' => $id, 'login_identifier' => $login, 'is_admin' => $admin, 'is_active' => $active, 'password_hash' => 'SECRET-HASH', 'auth_source' => 'saml']);
        }
        foreach ([[1, 'administrator', 'Administrator', 1, 10], [2, 'staff', 'Staff', 0, 20], [3, 'other', 'Other', 0, 30], [4, 'excluded', 'Excluded', 0, 40]] as [$id, $key, $name, $system, $order]) {
            $this->db->table(ModuleAuth::TABLE_AUTH_GROUPS)->insert(['id' => $id, 'group_key' => $key, 'group_name' => $name, 'is_system' => $system, 'sort_order' => $order]);
        }
    }
}
