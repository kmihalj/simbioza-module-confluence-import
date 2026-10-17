<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\HeartPhrameModuleAuth\Service\AuthGroupService;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthUserService;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceArchive;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceCalendarResolutionService;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceExportReader;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportConfig;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportRepository;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteAccessService;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteAuth;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteCalendarIndex;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteCalendarService;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSitePrincipals;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceTeamCalendarConverter;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceTeamCalendarReader;
use AaiEduHr\SimbiozaModuleConfluenceImport\Tests\Support\TeamCalendarsFixture;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;

/**
 * EN: Exercises C4 transaction/owner/idempotency boundaries with an isolated transactional Calendar API double.
 * Real Calendar API integration is verified separately in the complete disposable application.
 *
 * HR: Provjerava C4 transakcije/vlasništvo/idempotenciju izoliranim transakcijskim dvojnikom Calendar API-ja.
 * Integracija stvarnog Calendar API-ja zasebno se provjerava u potrošnoj potpunoj aplikaciji.
 */
final class ConfluenceSiteCalendarServiceTest extends TestCase
{
    /**
     * EN: Both read-only preview endpoints remain bound to the authenticated import owner.
     *
     * HR: Oba endpointa pregleda bez izmjena ostaju vezana uz prijavljenog vlasnika uvoza.
     */
    public function testPreviewLookupsRejectAnotherJobOwner(): void
    {
        foreach (['preparation', 'ownerPage'] as $method) {
            try {
                $method === 'preparation' ? $this->service->preparation($this->job['uuid'], 2)
                    : $this->service->ownerPage($this->job['uuid'], 2, '', 1);
                self::fail('Another job owner must not read private preview data.');
            } catch (ConfluenceImportException) {
                self::assertSame([], $this->db->table('c4_calendars')->get());
            }
        }
    }

    /**
     * EN: Pending source accounts appear separately from local accounts without being created during search.
     *
     * HR: Planirani izvorni računi prikazuju se odvojeno od lokalnih bez stvaranja tijekom pretrage.
     */
    public function testOwnerLookupIncludesSelectedFutureAccounts(): void
    {
        $this->repo->updateJob((int)$this->job['id'], ['options_json' => json_encode([
            'site_access' => ['users' => ['internal-user:1' => '__create__']],
        ], JSON_THROW_ON_ERROR)]);
        $before = $this->db->table('auth_users')->get();
        $result = $this->service->ownerPage($this->job['uuid'], 1, 'alice', 1);
        self::assertSame(['local-user:2', 'internal-user:1'], array_column($result['results'], 'id'));
        self::assertStringContainsString('alice', $result['results'][1]['label']);
        self::assertSame($before, $this->db->table('auth_users')->get());
    }

    /**
     * EN: Existing owners can be selected without source mappings, including when no source URL is saved yet.
     * Selection is revalidated and never turns into an identity mapping or an importing-admin fallback.
     *
     * HR: Postojeći vlasnici dostupni su bez izvornih mapiranja i dok izvorna adresa još nije spremljena.
     * Odabir se ponovno provjerava i ne stvara mapiranje identiteta niti zamjenskog administratora uvoza.
     */
    public function testLocalOwnerSelectionWorksWithoutIdentityChoices(): void
    {
        $this->repo->updateJob((int)$this->job['id'], ['options_json' => '{}']);
        $before = $this->db->table('auth_users')->get();
        $lookup = $this->service->ownerPage($this->job['uuid'], 1, 'local-alice', 1);
        self::assertSame('local-user:2', $lookup['results'][0]['id']);
        self::assertCount(1, $lookup['results']);
        self::assertSame([], $this->service->ownerPage($this->job['uuid'], 1, 'absent', 1)['results']);
        self::assertSame([], $this->service->state($this->job['uuid'], 1)['owner_choices']);
        $this->service->review($this->job['uuid'], $this->actor, 'https://source.test', ['calendar-one'], ['calendar-one' => 'personal'], ['calendar-one' => 'local-user:2']);
        $plan = $this->service->state($this->job['uuid'], 1)['plan'];
        self::assertSame(2, $plan['decisions']['calendar-one']['owner_id']);
        $this->service->start($this->job['uuid'], $this->actor, $plan['token']);
        $this->service->process($this->job['uuid'], $this->actor);
        self::assertSame(2, $this->calendar->payloads[0]['owner_user_id']);
        self::assertSame($before, $this->db->table('auth_users')->get());
    }

    /**
     * EN: Database-paged owner search is bounded and includes inactive accounts without exposing secrets.
     *
     * HR: Pretraga vlasnika paginirana u bazi ograničena je i uključuje neaktivne račune bez otkrivanja tajni.
     */
    public function testOwnerSearchLoadsBoundedDistinctPages(): void
    {
        for ($id = 3; $id <= 10080; ++$id) {
            $this->db->table('auth_users')->insert(['id' => $id, 'login_identifier' => 'person-' . $id, 'is_active' => 0, 'is_admin' => 0]);
        }
        $first = $this->service->ownerPage($this->job['uuid'], 1, '', 1);
        $second = $this->service->ownerPage($this->job['uuid'], 1, '', 2);
        self::assertCount(20, $first['results']);
        self::assertCount(20, $second['results']);
        self::assertTrue($first['hasMore']);
        self::assertSame([], array_intersect(array_column($first['results'], 'id'), array_column($second['results'], 'id')));
        foreach ($first['results'] as $result) {
            self::assertSame(['id', 'label'], array_keys($result));
        }
        self::assertSame('local-user:10079', $this->service->ownerPage($this->job['uuid'], 1, 'person-10079', 1)['results'][0]['id']);
    }

    /**
     * EN: A removed explicitly selected local owner invalidates the review before any Calendar writes.
     *
     * HR: Uklonjeni izričito odabrani lokalni vlasnik poništava pregled prije bilo kakva Calendar upisa.
     */
    public function testRemovedLocalOwnerStopsExecution(): void
    {
        $this->service->review($this->job['uuid'], $this->actor, 'https://source.test', ['calendar-one'], ['calendar-one' => 'personal'], ['calendar-one' => 'local-user:1']);
        $plan = $this->service->state($this->job['uuid'], 1)['plan'];
        $this->db->table('auth_users')->where('id', '=', 1)->delete();
        $this->expectException(ConfluenceImportException::class);
        $this->expectExceptionMessage('Odabrani vlasnik više nije dostupan.');
        $this->service->start($this->job['uuid'], $this->actor, $plan['token']);
    }

    /**
     * EN: Only committed batches advance progress, including an empty calendar's checkpoint.
     *
     * HR: Napredak povećavaju samo potvrđeni paketi, uključujući kontrolnu točku praznog kalendara.
     */
    public function testProgressCountsCommittedBatches(): void
    {
        $method = new \ReflectionMethod($this->service, 'progress');
        $plan = ['phase' => 'running', 'position' => 0, 'batch' => 1,
            'decisions' => ['one' => ['batches' => 3], 'empty' => ['batches' => 0]]];
        $progress = $method->invoke($this->service, $plan);
        self::assertSame(25, $progress['progress']);
        self::assertSame(1, $progress['batches_done']);
        self::assertSame(4, $progress['batches_total']);
        self::assertSame(0, $progress['calendars_done']);
        $plan['position'] = 1;
        $plan['batch'] = 0;
        self::assertSame(75, $method->invoke($this->service, $plan)['progress']);
        $plan['position'] = 2;
        $plan['phase'] = 'completed';
        self::assertSame(100, $method->invoke($this->service, $plan)['progress']);
    }

    private string $root;
    private Database $db;
    private ConfluenceImportRepository $repo;
    private ConfluenceSiteCalendarService $service;
    private array $job;
    private object $calendar;
    private array $actor = ['id' => 1, 'is_admin' => true];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/confluence-c4-service-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0770);
        $helper = new Helper();
        $config = new Config($helper, ['database' => ['connections' => ['default' => ['driver' => 'sqlite', 'database' => ':memory:']]]], $this->root);
        $this->db = new Database($config, $helper);
        $pdo = $this->db->getConnection();
        $pdo->exec('CREATE TABLE auth_users (id INTEGER PRIMARY KEY, login_identifier TEXT, is_active INTEGER, is_admin INTEGER)');
        $pdo->exec("INSERT INTO auth_users VALUES (1, 'admin', 1, 1), (2, 'local-alice', 0, 0)");
        $pdo->exec('CREATE TABLE auth_groups (id INTEGER PRIMARY KEY, group_key TEXT, group_name TEXT, is_enabled INTEGER, is_system INTEGER)');
        $pdo->exec("INSERT INTO auth_groups VALUES (3, 'local-staff', 'Local staff', 1, 0)");
        $pdo->exec('CREATE TABLE c4_calendars (id INTEGER PRIMARY KEY AUTOINCREMENT, payload TEXT)');
        $pdo->exec('CREATE TABLE c4_events (calendar_id INTEGER, uid TEXT, UNIQUE(calendar_id, uid))');
        (require __DIR__ . '/../resources/migrations/initial_simbioza_confluence_import_schema.php')->up($this->db);
        $this->repo = new ConfluenceImportRepository($this->db);
        $moduleConfig = new ConfluenceImportConfig($config, dirname(__DIR__));
        $archive = new ConfluenceArchive($moduleConfig);
        $principals = new ConfluenceSitePrincipals($archive, new ConfluenceExportReader($archive), $moduleConfig);
        $groups = new AuthGroupService($this->db);
        $auth = new ConfluenceSiteAuth($this->db, new AuthUserService($this->db), $groups);
        $this->calendar = new class ($this->db) {
            public bool $fail = false;
            public array $payloads = [];
            public function __construct(private readonly Database $db)
            {
            }
            public function createCalendar(array $payload, array $actor): array
            {
                $this->payloads[] = $payload;
                $this->db->table('c4_calendars')->insert(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
                return $this->calendar((int)$this->db->lastInsertId(), $actor);
            }
            public function calendar(int $id, array $actor): ?array
            {
                $row = $this->db->table('c4_calendars')->where('id', '=', $id)->first();
                return $row ? ['id' => $id, 'uuid' => 'test-uuid-' . $id, 'payload' => $row['payload']]
                    + json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR) : null;
            }
            public function importCalendar(array $payload, array $actor): array
            {
                preg_match_all('/^UID:(.+)$/m', $payload['ics'], $matches);
                $created = $skipped = 0;
                foreach ($matches[1] as $uid) {
                    $old = $this->db->table('c4_events')->where('calendar_id', '=', $payload['calendar_id'])->where('uid', '=', trim($uid))->first();
                    if ($old) {
                        ++$skipped;
                    } else {
                        $this->db->table('c4_events')->insert(['calendar_id' => $payload['calendar_id'], 'uid' => trim($uid)]);
                        ++$created;
                    }
                }
                if ($this->fail) {
                    throw new \RuntimeException('Synthetic failure after event insert.');
                }
                return ['created' => $created, 'skipped' => $skipped];
            }
        };
        $container = new class ($this->calendar) implements ContainerInterface {
            public function __construct(private readonly object $calendar)
            {
            }
            public function get(string $id): mixed
            {
                return $this->calendar;
            }
            public function has(string $id): bool
            {
                return str_ends_with($id, 'CalendarManagerInterface');
            }
        };
        $this->service = new ConfluenceSiteCalendarService(
            $this->repo,
            new ConfluenceSiteCalendarIndex(new ConfluenceTeamCalendarReader($archive), $moduleConfig),
            new ConfluenceTeamCalendarConverter(),
            $principals,
            $auth,
            (new ReflectionClass(ConfluenceCalendarResolutionService::class))->newInstanceWithoutConstructor(),
            $container
        );
        mkdir($moduleConfig->uploadDirectory(), 0770, true);
        $path = $moduleConfig->uploadDirectory() . '/site.zip';
        $tables = TeamCalendarsFixture::tables();
        $tables['SUBCALS'][0]['COLOUR'] = 'subcalendar-green2';
        $tables['SUBCALS'][] = array_replace($tables['SUBCALS'][0], ['ID' => 'calendar-two', 'NAME' => 'Team two']);
        $tables['EVENTS'][] = array_replace($tables['EVENTS'][0], ['ID' => 'event-two', 'SUB_CALENDAR_ID' => 'calendar-two', 'VEVENT_UID' => 'event-two@test']);
        TeamCalendarsFixture::archive($path, $tables);
        $hash = hash_file('sha256', $path);
        $this->job = $this->repo->createUploadJob('site.zip', $path, filesize($path), 0, 1, time() + 3600);
        $this->repo->saveScan((int)$this->job['id'], ['inventory_only' => true, 'archive_type' => 'site', 'spaces' => [], 'source_sha256' => $hash]);
        $principals->scan($path, $hash);
        $this->repo->mapIdentity(['source_key' => ConfluenceSiteAccessService::mappingKey('https://source.test', 'users', 'internal-user:1')], 2, true, (int)$this->job['id']);
        $this->repo->mapGroup(ConfluenceSiteAccessService::mappingKey('https://source.test', 'groups', 'internal-group:11'), 3, true, (int)$this->job['id']);
        $this->repo->updateJob((int)$this->job['id'], ['options_json' => json_encode([
            'site_access' => ['source_url' => 'https://source.test', 'users' => ['internal-user:1' => '2']],
        ], JSON_THROW_ON_ERROR)]);
        $this->service->prepare($this->job['uuid'], 1);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    /**
     * EN: Preview is read-only; confirmed C2 users/groups become ACL rules, never new accounts.
     *
     * HR: Pregled ne mijenja podatke; potvrđeni C2 korisnici/grupe postaju ACL pravila, ne novi računi.
     */
    public function testUsesConfirmedMappingsAndReimportCannotDuplicate(): void
    {
        $before = $this->db->table('auth_users')->get();
        $plan = $this->review();
        self::assertNull($plan['decisions']['calendar-one']['owner_id']);
        self::assertCount(2, $plan['decisions']['calendar-one']['rules']);
        self::assertSame([], $this->db->table('c4_calendars')->get());
        $this->service->start($this->job['uuid'], $this->actor, $plan['token']);
        self::assertTrue($this->service->process($this->job['uuid'], $this->actor)['completed']);
        self::assertTrue($this->service->process($this->job['uuid'], $this->actor)['completed']);
        self::assertCount(1, $this->db->table('c4_calendars')->get());
        self::assertCount(1, $this->db->table('c4_events')->get());
        self::assertCount(2, $this->repo->calendarMappings('https://source.test'));
        self::assertFalse($this->calendar->payloads[0]['is_public_read']);
        self::assertFalse($this->calendar->payloads[0]['is_authenticated_read']);
        self::assertFalse($this->calendar->payloads[0]['subscribe_actor']);
        self::assertSame('#00875A', $this->calendar->payloads[0]['color']);
        $plan = $this->review();
        $this->service->start($this->job['uuid'], $this->actor, $plan['token']);
        $done = $this->service->process($this->job['uuid'], $this->actor);
        self::assertTrue($done['completed']);
        self::assertCount(1, $this->db->table('c4_calendars')->get());
        self::assertCount(1, $this->db->table('c4_events')->get());
        self::assertSame($before, $this->db->table('auth_users')->get());
        $report = $this->repo->jobByUuid($done['report_uuid']);
        self::assertSame('completed', $report['status']);
        self::assertSame('site_calendars', $report['operation']);
        self::assertSame(1, $report['summary']['results']['calendar-one']['existing']);
    }

    /**
     * EN: The target, events, provenance and cursor roll back together after a failed bulk operation.
     *
     * HR: Cilj, događaji, podrijetlo i pokazivač zajedno se poništavaju nakon neuspješne skupne radnje.
     */
    public function testFailureRollsBackAndResumeStartsFromLastCommittedBatch(): void
    {
        $plan = $this->review();
        $this->service->start($this->job['uuid'], $this->actor, $plan['token']);
        $this->calendar->fail = true;
        try {
            $this->service->process($this->job['uuid'], $this->actor);
            self::fail('Expected synthetic failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('Synthetic failure after event insert.', $error->getMessage());
        }
        self::assertSame([], $this->db->table('c4_calendars')->get());
        self::assertSame([], $this->db->table('c4_events')->get());
        self::assertSame([], $this->repo->calendarMappings('https://source.test'));
        self::assertSame(0, $this->service->state($this->job['uuid'], 1)['plan']['position']);
        $this->calendar->fail = false;
        self::assertTrue($this->service->process($this->job['uuid'], $this->actor)['completed']);
        self::assertCount(1, $this->db->table('c4_events')->get());
    }

    /**
     * EN: Stopping a partial execution keeps committed targets and allows a safe fresh review.
     *
     * HR: Zaustavljanje djelomičnog izvršavanja čuva potvrđene ciljeve i omogućuje siguran novi pregled.
     */
    public function testStoppedPartialImportKeepsTargetsAndCanBeReviewedAgain(): void
    {
        $this->service->review($this->job['uuid'], $this->actor, 'https://source.test', ['calendar-one', 'calendar-two'], ['calendar-one' => 'team', 'calendar-two' => 'team']);
        $plan = $this->service->state($this->job['uuid'], 1)['plan'];
        $this->service->start($this->job['uuid'], $this->actor, $plan['token']);
        self::assertFalse($this->service->process($this->job['uuid'], $this->actor)['completed']);
        self::assertCount(1, $this->db->table('c4_events')->get());
        self::assertTrue($this->service->stop($this->job['uuid'], $this->actor)['stopped']);
        self::assertSame('interrupted', $this->service->state($this->job['uuid'], 1)['plan']['phase']);
        self::assertCount(1, $this->db->table('c4_events')->get());
        $this->service->review($this->job['uuid'], $this->actor, 'https://source.test', ['calendar-one', 'calendar-two'], ['calendar-one' => 'team', 'calendar-two' => 'team']);
        $this->service->start($this->job['uuid'], $this->actor, $this->service->state($this->job['uuid'], 1)['plan']['token']);
        self::assertFalse($this->service->process($this->job['uuid'], $this->actor)['completed']);
        self::assertTrue($this->service->process($this->job['uuid'], $this->actor)['completed']);
        self::assertCount(2, $this->db->table('c4_events')->get());
        self::assertCount(2, $this->db->table('c4_calendars')->get());
    }

    /**
     * EN: A wrong review token cannot queue any mutation or consume the valid review.
     *
     * HR: Pogrešan token pregleda ne pokreće izmjene niti troši valjani pregled.
     */
    public function testInvalidTokenCannotStartImport(): void
    {
        $this->review();
        try {
            $this->service->start($this->job['uuid'], $this->actor, 'wrong-token');
            self::fail('Wrong token accepted.');
        } catch (ConfluenceImportException) {
            self::assertSame('review', $this->service->state($this->job['uuid'], 1)['plan']['phase']);
        }
        self::assertSame([], $this->db->table('c4_calendars')->get());
    }

    /**
     * EN: Another administrator cannot prepare/read/apply the upload owner's private calendar source.
     *
     * HR: Drugi administrator ne smije pripremiti/čitati/primijeniti privatni izvor vlasnika prijenosa.
     */
    public function testOwnerBoundary(): void
    {
        $this->expectException(ConfluenceImportException::class);
        $this->service->state($this->job['uuid'], 2);
    }

    /**
     * EN: A disabled C2 target group invalidates a review before any calendar is created.
     *
     * HR: Isključena C2 ciljna grupa poništava pregled prije stvaranja bilo kojeg kalendara.
     */
    public function testChangedMappingsInvalidateReview(): void
    {
        $plan = $this->review();
        $this->db->table('auth_groups')->where('id', '=', 3)->update(['is_enabled' => 0]);
        $this->expectException(ConfluenceImportException::class);
        $this->service->start($this->job['uuid'], $this->actor, $plan['token']);
    }

    /**
     * EN: An explicit personal choice uses the confirmed source owner rather than the importing administrator.
     * A repeated import preserves that type/owner and cannot silently convert it to a team calendar.
     *
     * HR: Izričiti osobni odabir koristi potvrđenog izvornog vlasnika umjesto administratora koji uvozi.
     * Ponovni uvoz čuva vrstu/vlasnika i ne smije ga tiho pretvoriti u timski kalendar.
     */
    public function testPersonalChoiceUsesMappedOwnerAndRejectsTypeChanges(): void
    {
        $this->review();
        $state = $this->service->state($this->job['uuid'], 1);
        self::assertSame(['label' => 'local-alice', 'target' => 2], $state['owner_choices']['internal-user:1']);
        $this->service->review($this->job['uuid'], $this->actor, 'https://source.test', ['calendar-one'], ['calendar-one' => 'personal'], ['calendar-one' => 'internal-user:1']);
        $plan = $this->service->state($this->job['uuid'], 1)['plan'];
        self::assertSame('personal', $plan['decisions']['calendar-one']['calendar_type']);
        $this->service->start($this->job['uuid'], $this->actor, $plan['token']);
        $done = $this->service->process($this->job['uuid'], $this->actor);
        self::assertTrue($done['completed']);
        self::assertSame('personal', $this->calendar->payloads[0]['calendar_type']);
        self::assertSame(2, $this->calendar->payloads[0]['owner_user_id']);
        self::assertSame('custom', $this->calendar->payloads[0]['personal_preset']);
        self::assertNotEmpty($this->calendar->payloads[0]['personal_suffix']);
        self::assertSame('personal', $this->repo->jobByUuid($done['report_uuid'])['summary']['results']['calendar-one']['calendar_type']);
        $this->service->review($this->job['uuid'], $this->actor, 'https://source.test', ['calendar-one'], ['calendar-one' => 'personal'], ['calendar-one' => 'internal-user:1']);
        $this->service->start($this->job['uuid'], $this->actor, $this->service->state($this->job['uuid'], 1)['plan']['token']);
        self::assertTrue($this->service->process($this->job['uuid'], $this->actor)['completed']);
        self::assertCount(1, $this->db->table('c4_calendars')->get());
        $this->expectException(ConfluenceImportException::class);
        $this->review();
    }

    /**
     * EN: Personal calendars without a confirmed owner are rejected before any mutation, including crafted requests.
     *
     * HR: Osobni kalendari bez potvrđenog vlasnika odbijaju se prije izmjena, uključujući krivotvorene zahtjeve.
     */
    public function testPersonalChoiceRequiresConfirmedOwner(): void
    {
        $this->db->table('auth_users')->where('id', '=', 2)->delete();
        self::assertArrayNotHasKey('internal-user:1', $this->service->state($this->job['uuid'], 1)['owner_choices']);
        $this->expectException(ConfluenceImportException::class);
        $this->service->review($this->job['uuid'], $this->actor, 'https://source.test', ['calendar-one'], ['calendar-one' => 'personal']);
    }

    /**
     * EN: Even a mapped creator cannot become the personal owner without an explicit selection.
     *
     * HR: Ni mapirani kreator ne postaje osobni vlasnik bez izričitog odabira.
     */
    public function testMappedCreatorDoesNotSelectPersonalOwner(): void
    {
        $this->review();
        try {
            $this->service->review($this->job['uuid'], $this->actor, 'https://source.test', ['calendar-one'], ['calendar-one' => 'personal']);
            self::fail('The mapped creator was silently used as the owner.');
        } catch (ConfluenceImportException $error) {
            self::assertSame(__('Odaberite vlasnika osobnog kalendara.'), $error->getMessage());
        }
        self::assertSame([], $this->db->table('c4_calendars')->get());
    }

    /**
     * EN: Nested, unknown or unselected calendar types cannot enter a signed review.
     *
     * HR: Ugniježđene, nepoznate ili neodabrane vrste kalendara ne ulaze u potpisani pregled.
     */
    public function testMalformedTypeChoicesAreRejected(): void
    {
        foreach (['personal', ['calendar-one' => ['personal']], ['calendar-one' => 'resource'], ['calendar-two' => 'personal']] as $types) {
            try {
                $this->service->review($this->job['uuid'], $this->actor, 'https://source.test', ['calendar-one'], $types);
                self::fail('Invalid type choice was accepted.');
            } catch (ConfluenceImportException) {
                self::assertSame([], $this->db->table('c4_calendars')->get());
            }
        }
    }

    /**
     * EN: User-facing warnings describe the action without leaking internal development-plan stage codes.
     *
     * HR: Korisnička upozorenja opisuju radnju bez internih oznaka koraka razvojnog plana.
     */
    public function testWarningsDoNotExposeDevelopmentStages(): void
    {
        $this->db->table('auth_users')->where('id', '=', 2)->delete();
        $plan = $this->review();
        self::assertContains('Izvorni kreator nije mapiran; njegova prava pristupa nisu prenesena.', $plan['decisions']['calendar-one']['access_warnings']);
        self::assertDoesNotMatchRegularExpression('/\bC[1-5]\b/', implode(' ', $plan['decisions']['calendar-one']['access_warnings']));
        $this->db->table('auth_groups')->where('id', '=', 3)->update(['is_enabled' => 0]);
        try {
            $this->service->start($this->job['uuid'], $this->actor, $plan['token']);
            self::fail('Changed mapping was accepted.');
        } catch (ConfluenceImportException $error) {
            self::assertDoesNotMatchRegularExpression('/\bC[1-5]\b/', $error->getMessage());
        }
    }

    /**
     * EN: Produces a fresh review without changing the fixture's confirmed C2 decisions.
     *
     * HR: Stvara novi pregled bez izmjene potvrđenih C2 odluka fixturea.
     *
     * @return array<string,mixed>
     */
    private function review(): array
    {
        $this->service->review($this->job['uuid'], $this->actor, 'https://source.test', ['calendar-one'], ['calendar-one' => 'team']);
        return $this->service->state($this->job['uuid'], 1)['plan'];
    }

    /**
     * EN: An ambiguous selected calendar requires a type choice before any write.
     *
     * HR: Nejasan odabrani kalendar zahtijeva odabir vrste prije bilo kakva upisa.
     */
    public function testAmbiguousCalendarDoesNotSilentlyBecomeTeam(): void
    {
        try {
            $this->service->review($this->job['uuid'], $this->actor, 'https://source.test', ['calendar-one']);
            self::fail('An explicit calendar type must be selected.');
        } catch (ConfluenceImportException $error) {
            self::assertSame(__('Odabir vrste kalendara nije valjan.'), $error->getMessage());
        }
        self::assertSame([], $this->db->table('c4_calendars')->get());
    }
}
