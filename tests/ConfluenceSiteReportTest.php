<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportRepository;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteReportService;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteJobOptions;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\TestCase;

/**
 * EN: Verifies bounded parent/child reporting, ownership/source isolation and portable metadata without archive access.
 *
 * HR: Provjerava ograničeno izvještavanje roditelja/podposlova, odvajanje vlasnika/izvora i prenosivost bez čitanja arhive.
 */
final class ConfluenceSiteReportTest extends TestCase
{
    private ConfluenceImportRepository $repo;
    private ConfluenceSiteReportService $reports;

    protected function setUp(): void
    {
        $helper = new Helper();
        $db = new Database(new Config($helper, ['database' => ['connections' => ['default' => ['driver' => 'sqlite', 'database' => ':memory:']]]]), $helper);
        (require __DIR__ . '/../resources/migrations/initial_simbioza_confluence_import_schema.php')->up($db);
        $this->repo = new ConfluenceImportRepository($db);
        $this->reports = new ConfluenceSiteReportService($this->repo);
    }

    /**
     * EN: An overview reports pending scripts but never includes their bodies or grants approval.
     *
     * HR: Pregled broji skripte na čekanju, ali nikada ne izlaže njihova tijela ni daje odobrenje.
     */
    public function testOverviewUsesOwnedLinkedReportsWithoutArchiveOrSecrets(): void
    {
        $child = $this->job('site_space', ['pages_imported' => 2, 'attachments_failed' => 1, 'review_pages' => [
            ['issues' => [['type' => 'external_script', 'script' => ['code' => 'PRIVATE-CODE']], ['type' => 'calendar', 'resolved' => true]]],
        ]]);
        $calendar = $this->job('site_calendars', ['partial' => true, 'results' => [['created' => 3, 'existing' => 1, 'skipped' => 2]]]);
        $parent = ['actor_user_id' => 1, 'status' => 'running', 'archive_path' => '/PRIVATE-PATH.zip',
            'summary' => ['source_sha256' => str_repeat('a', 64), 'children' => [['uuid' => $child['uuid']]],
                'site_reports' => ['calendars' => [$calendar['uuid']]]], 'options' => ['site_content' => ['children' => ['one' => $child['uuid']]]]];
        $overview = $this->reports->overview($parent);
        self::assertCount(2, $overview['reports']);
        self::assertSame(2, $overview['totals']['pages_imported']);
        self::assertSame(1, $overview['totals']['scripts_pending']);
        self::assertSame(3, $overview['totals']['events_created']);
        self::assertSame('running', $overview['status']);
        self::assertTrue($overview['reports'][1]['partial']);
        self::assertSame(0, $overview['unavailable_reports']);
        self::assertStringNotContainsString('PRIVATE-', json_encode($overview));
        $parent['options'] = json_decode(ConfluenceSiteJobOptions::portable(json_encode($parent['options'])), true);
        $parent['archive_path'] = '';
        self::assertSame($overview['reports'], $this->reports->overview($parent)['reports']);
    }

    /**
     * EN: Tampered references cannot disclose another owner/source report, and missing restored reports are visible.
     *
     * HR: Izmijenjene reference ne otkrivaju izvještaj drugog vlasnika/izvora, a nedostajući obnovljeni izvještaji vidljivi su.
     */
    public function testWrongOwnerAndSourceFailClosed(): void
    {
        $wrongOwner = $this->job('site_access', ['users_created' => 99], 2);
        $wrongSource = $this->job('site_access', ['users_created' => 99], 1, 'b');
        $parent = ['actor_user_id' => 1, 'status' => 'completed', 'summary' => ['source_sha256' => str_repeat('a', 64),
            'site_reports' => ['access' => [$wrongOwner['uuid'], $wrongSource['uuid'], 'missing']]]];
        $overview = $this->reports->overview($parent);
        self::assertSame([], $overview['reports']);
        self::assertSame(3, $overview['unavailable_reports']);
    }

    /**
     * EN: Completed spaces never hide an ongoing or failed linked calendar step.
     *
     * HR: Dovršena područja nikada ne skrivaju povezanu obradu kalendara u tijeku ili s greškom.
     */
    public function testOverallStateIncludesIndependentCalendarExecution(): void
    {
        $calendar = $this->job('site_calendars', []);
        $parent = ['actor_user_id' => 1, 'status' => 'completed', 'summary' => ['source_sha256' => str_repeat('a', 64),
            'site_reports' => ['calendars' => [$calendar['uuid']]]]];
        $this->repo->updateJob((int)$calendar['id'], ['status' => 'running']);
        self::assertSame('running', $this->reports->overview($parent)['status']);
        $this->repo->updateJob((int)$calendar['id'], ['status' => 'failed']);
        self::assertSame('failed', $this->reports->overview($parent)['status']);
    }

    /**
     * EN: An earlier report shows the current link outcome after a later area resolves it, without rewriting history.
     *
     * HR: Raniji izvještaj prikazuje aktualno stanje nakon razrješenja uvozom drugog područja, bez izmjene povijesti.
     */
    public function testOlderReportReflectsLaterLinkResolution(): void
    {
        $child = $this->job('site_space', ['pages_imported' => 1]);
        $this->repo->recordLink(['source_page_id' => '10', 'source_space_key' => 'A', 'destination_space_key' => 'B'], (int)$child['id']);
        $parent = ['actor_user_id' => 1, 'status' => 'completed', 'sha256' => str_repeat('a', 64),
            'summary' => ['children' => [['uuid' => $child['uuid']]]]];
        $before = $this->reports->overview($parent);
        self::assertSame(1, $before['totals']['links_unresolved']);
        $link = $this->repo->unresolvedLinksForJob((int)$child['id'])[0];
        $this->repo->updateLinkResolution((int)$link['id'], '/workspace/b/target');
        $after = $this->reports->overview($parent);
        self::assertSame(1, $after['totals']['links_resolved']);
        self::assertSame(0, $after['totals']['links_unresolved'] ?? 0);
        self::assertSame($before['reports'][0]['uuid'], $after['reports'][0]['uuid']);
        self::assertSame([], $this->repo->unresolvedLinksForJob((int)$child['id']));
    }

    /**
     * EN: Finishing content cannot mark the workflow completed while final linking still runs.
     *
     * HR: Dovršetak sadržaja ne smije proglasiti cijeli postupak dovršenim dok još traje povezivanje.
     */
    public function testFinalLinkingRemainsRunningInOverview(): void
    {
        $parent = ['actor_user_id' => 1, 'status' => 'completed', 'options' => ['site_workflow' => ['phase' => 'links']]];
        self::assertSame('running', $this->reports->overview($parent)['status']);
        $parent['options']['site_workflow']['phase'] = 'failed';
        self::assertSame('failed', $this->reports->overview($parent)['status']);
    }

    /**
     * EN: Automatic calendar linking may visit old reports only from the same owner and verified source.
     *
     * HR: Automatsko povezivanje kalendara smije obrađivati stare izvještaje samo istog vlasnika i izvora.
     */
    public function testPreviousSourceReportsAreIsolatedAndOrdered(): void
    {
        foreach (['https://one.test', 'https://two.test', 'https://one.test'] as $offset => $url) {
            $child = $this->job('site_space', [], $offset === 2 ? 2 : 1);
            $this->repo->withSiteContext($url, [], function () use ($child, $offset): void {
                $this->repo->mapContent(['source_id' => (string)$offset, 'title' => 'Test'], ['source_space_key' => 'A',
                    'workspace_id' => $offset + 1, 'slug' => 'test'], (int)$child['id']);
            });
        }
        $first = $this->repo->nextSourceSpaceReport('https://one.test', 1, 0);
        self::assertNotNull($first);
        self::assertSame(1, (int)$first['id']);
        self::assertNull($this->repo->nextSourceSpaceReport('https://one.test', 1, (int)$first['id']));
    }

    /**
     * EN: Nested component locks share one atomic transaction and never commit the coordinator early.
     *
     * HR: Ugniježđene komponentne brave dijele atomsku transakciju i ne potvrđuju koordinator prerano.
     */
    public function testNestedJobLocksRollBackWithTheirCaller(): void
    {
        $job = $this->job('site_access', []);
        try {
            $this->repo->withLockedJob($job['uuid'], 1, function () use ($job): never {
                $this->repo->withLockedJob($job['uuid'], 1, function () use ($job): array {
                    $this->repo->updateJob((int)$job['id'], ['status' => 'running']);
                    return [];
                });
                throw new \RuntimeException('Synthetic coordinator interruption');
            });
        } catch (\RuntimeException) {
            self::assertSame('completed', $this->repo->jobByUuid($job['uuid'], 1)['status']);
        }
    }

    /**
     * EN: Creates only isolated report metadata for the test.
     *
     * HR: Stvara samo izolirane metapodatke izvještaja za test.
     *
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    private function job(string $operation, array $summary, int $actor = 1, string $hash = 'a'): array
    {
        $job = $this->repo->createUploadJob('test.zip', '', 0, 0, $actor, time() + 60);
        $this->repo->updateJob((int)$job['id'], ['operation' => $operation, 'status' => 'completed',
            'sha256' => str_repeat($hash, 64), 'summary_json' => json_encode($summary)]);
        return $job;
    }
}
