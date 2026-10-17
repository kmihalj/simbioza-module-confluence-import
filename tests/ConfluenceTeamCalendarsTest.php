<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceArchive;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceCalendarPreparation;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportConfig;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteCalendarIndex;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceTeamCalendarConverter;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceTeamCalendarReader;
use AaiEduHr\SimbiozaModuleConfluenceImport\Tests\Support\TeamCalendarsFixture;
use HeartPhrame\Config\Config;
use HeartPhrame\Helper\Helper;
use PHPUnit\Framework\TestCase;

/**
 * EN: Verifies streaming, secret filtering, source hierarchy and conservative event conversion.
 *
 * HR: Provjerava tok podataka, filtriranje tajni, hijerarhiju izvora i oprezno pretvaranje događaja.
 */
final class ConfluenceTeamCalendarsTest extends TestCase
{
    /**
     * EN: Polling and repeated starts observe the existing lock without rescanning or changing the source.
     *
     * HR: Praćenje i ponovljeno pokretanje prate postojeće zaključavanje bez čitanja ili promjene izvora.
     */
    public function testPreparationProgressFollowsTheExistingWorker(): void
    {
        TeamCalendarsFixture::archive($this->path, TeamCalendarsFixture::tables());
        $hash = hash_file('sha256', $this->path);
        self::assertSame('idle', $this->index->preparation($this->path, $hash)['status']);
        self::assertFileDoesNotExist($this->path . '.calendars.lock');
        $lock = fopen($this->path . '.calendars.lock', 'c+');
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            self::assertSame('running', $this->index->preparation($this->path, $hash)['status']);
            $progress = new ConfluenceCalendarPreparation($this->path, $hash);
            $progress->update('scan', 120);
            $state = $this->index->preparation($this->path, $hash);
            self::assertSame('scan', $state['phase']);
            self::assertSame(120, $state['done']);
            self::assertNull($state['total']);
            self::assertGreaterThan(0, $state['started_at']);
            self::assertNull($this->index->prepare($this->path, $hash));
            self::assertFileDoesNotExist($this->path . '.calendars/catalog.json');
            $progress->update('partition', 3, 10);
            self::assertSame(10, $this->index->preparation($this->path, $hash)['total']);
        } finally {
            fclose($lock);
        }
        self::assertSame('interrupted', $this->index->preparation($this->path, $hash)['status']);
        self::assertNotNull($this->index->prepare($this->path, $hash));
        self::assertSame('ready', $this->index->preparation($this->path, $hash)['status']);
        self::assertSame($hash, hash_file('sha256', $this->path));
    }

    /**
     * EN: A failed scan releases its lock and reports failure instead of waiting forever; retry rebuilds it.
     *
     * HR: Neuspjeli pregled otpušta zaključavanje i javlja grešku umjesto beskonačnog čekanja; ponavljanje ga obnavlja.
     */
    public function testFailedPreparationCanBeRetriedAndTelemetryIsSourceBound(): void
    {
        TeamCalendarsFixture::archive($this->path, TeamCalendarsFixture::tables(), '<!DOCTYPE backup [<!ENTITY injected "x">]>');
        $hash = hash_file('sha256', $this->path);
        try {
            $this->index->prepare($this->path, $hash);
            self::fail('Unsafe XML must not produce a preview.');
        } catch (ConfluenceImportException) {
            self::assertSame('failed', $this->index->preparation($this->path, $hash)['status']);
        }
        self::assertNull(ConfluenceCalendarPreparation::read($this->path, str_repeat('0', 64)));
        TeamCalendarsFixture::archive($this->path, TeamCalendarsFixture::tables());
        $newHash = hash_file('sha256', $this->path);
        self::assertNotNull($this->index->prepare($this->path, $newHash));
        self::assertSame('ready', $this->index->preparation($this->path, $newHash)['status']);
        $json = file_get_contents($this->path . '.calendars.progress.json');
        self::assertStringNotContainsString('alice', $json);
        self::assertStringNotContainsString('calendar-one', $json);
    }

    /**
     * EN: Progress cannot overwrite a linked target or expose malformed/unbounded snapshots.
     *
     * HR: Napredak ne može prepisati povezano odredište ili prikazati neispravne/prevelike snimke.
     */
    public function testPreparationRejectsLinkedAndMalformedTelemetry(): void
    {
        $target = $this->root . '/outside.json';
        file_put_contents($target, 'untouched');
        $file = $this->path . '.calendars.progress.json';
        symlink($target, $file);
        self::assertNull(ConfluenceCalendarPreparation::read($this->path, 'hash'));
        try {
            (new ConfluenceCalendarPreparation($this->path, 'hash'))->update('scan');
            self::fail('Linked telemetry must not be written.');
        } catch (ConfluenceImportException) {
            self::assertSame('untouched', file_get_contents($target));
        }
        unlink($file);
        file_put_contents($file, str_repeat('x', 4097));
        self::assertNull(ConfluenceCalendarPreparation::read($this->path, 'hash'));
        file_put_contents($file, '{"source_sha256":"hash","phase":"scan","done":-1,"started_at":1}');
        self::assertNull(ConfluenceCalendarPreparation::read($this->path, 'hash'));
    }

    /**
     * EN: Retains Unicode text at Calendar's limits and reports excess without shortening the source.
     *
     * HR: Čuva Unicode tekst do Calendar granica, a višak prijavljuje bez skraćivanja izvora.
     */
    public function testLongTitlesAndLocationsArePreservedOrReported(): void
    {
        $tables = TeamCalendarsFixture::tables();
        $tables['EVENTS'][0]['SUMMARY'] = str_repeat('Č', 765);
        $tables['EVENTS'][0]['LOCATION'] = str_repeat('Ž', 510);
        TeamCalendarsFixture::archive($this->path, $tables);
        $catalog = $this->index->prepare($this->path, hash_file('sha256', $this->path));
        $calendar = $catalog['calendars']['calendar-one'];
        $events = iterator_to_array($this->index->events($this->path, $calendar));
        $converter = new ConfluenceTeamCalendarConverter();
        $result = $converter->convert($events, $calendar);
        self::assertSame(1, $result['events']);
        self::assertStringContainsString('SUMMARY:' . str_repeat('Č', 765), $result['batches'][0]['ics']);
        self::assertStringContainsString('LOCATION:' . str_repeat('Ž', 510), $result['batches'][0]['ics']);
        $events[0]['SUMMARY'] .= 'Č';
        $result = $converter->convert($events, $calendar);
        self::assertSame(1, $result['skipped']);
        self::assertSame([], $result['batches']);
        self::assertStringContainsString('765', $result['warnings'][0]['reason']);
        $events[0]['SUMMARY'] = 'Valid';
        $events[0]['LOCATION'] .= 'Ž';
        self::assertStringContainsString('510', $converter->convert($events, $calendar)['warnings'][0]['reason']);
    }

    private string $root;
    private string $path;
    private ConfluenceTeamCalendarReader $reader;
    private ConfluenceSiteCalendarIndex $index;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/confluence-c4-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0770);
        $config = new ConfluenceImportConfig(new Config(new Helper(), [], $this->root), dirname(__DIR__));
        $this->reader = new ConfluenceTeamCalendarReader(new ConfluenceArchive($config));
        $this->index = new ConfluenceSiteCalendarIndex($this->reader, $config);
        $this->path = $this->root . '/site.zip';
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    /**
     * EN: Events may precede subcalendars; internal subscriptions alias an original rather than duplicate it.
     *
     * HR: Događaji mogu prethoditi podkalendarima; interne pretplate povezuju izvornik umjesto dupliciranja.
     */
    public function testIndexPartitionsNativeCalendarsAndNeverRetainsPluginSecrets(): void
    {
        $tables = TeamCalendarsFixture::tables();
        $tables['SUBCALS'][] = array_replace($tables['SUBCALS'][0], ['ID' => 'subscription', 'SUBSCRIPTION_ID' => 'calendar-one', 'STORE_KEY' => 'InternalSubscriptionCalendarDataStore']);
        $tables['SUBCALS_PROPS'] = [['ID' => 'secret', 'KEY' => 'password', 'VALUE' => 'must-not-survive', 'SUB_CALENDAR_ID' => 'calendar-one']];
        TeamCalendarsFixture::archive($this->path, $tables);
        $hash = hash_file('sha256', $this->path);
        self::assertNull($this->index->cached($this->path, $hash));
        $catalog = $this->index->prepare($this->path, $hash);
        self::assertCount(1, $catalog['calendars']);
        self::assertSame('calendar-one', $catalog['aliases']['subscription']);
        self::assertSame(1, $catalog['calendars']['calendar-one']['events']);
        self::assertSame(['ONE'], $catalog['calendars']['calendar-one']['space_keys']);
        self::assertStringNotContainsString('must-not-survive', json_encode(iterator_to_array($this->reader->rows($this->path))));
        self::assertSame($catalog, $this->index->cached($this->path, $hash));
        $converted = $this->convert($catalog);
        self::assertSame(1, $converted['events']);
        self::assertStringContainsString('DTSTART;TZID=Europe/Zagreb:20260622T110000', $converted['batches'][0]['ics']);
        self::assertSame('Meeting', $converted['batches'][0]['type']);
    }

    /**
     * EN: DTD/entity definitions are forbidden even without external network access.
     *
     * HR: DTD/definicije entiteta zabranjene su i bez vanjskog mrežnog pristupa.
     */
    public function testRejectsDtd(): void
    {
        TeamCalendarsFixture::archive($this->path, TeamCalendarsFixture::tables(), '<!DOCTYPE backup [<!ENTITY injected "x">]>');
        $this->expectException(ConfluenceImportException::class);
        iterator_to_array($this->reader->rows($this->path));
    }

    /**
     * EN: A changed private partition is never imported using an earlier review.
     *
     * HR: Promijenjeni privatni dio nikada se ne uvozi na temelju ranijeg pregleda.
     */
    public function testRejectsCorruptedPartition(): void
    {
        TeamCalendarsFixture::archive($this->path, TeamCalendarsFixture::tables());
        $catalog = $this->index->prepare($this->path, hash_file('sha256', $this->path));
        file_put_contents($this->path . '.calendars/' . hash('sha256', 'calendar-one') . '.jsonl', "{}\n");
        $this->expectException(ConfluenceImportException::class);
        $this->convert($catalog);
    }

    /**
     * EN: Source exclusions and moved instances survive; importing cannot inject attendee/HTML execution.
     *
     * HR: Izostavljanja i premještene instance ostaju očuvani; uvoz ne može umetnuti sudionike/izvršivi HTML.
     */
    public function testRecurrenceExceptionsAndTextEscaping(): void
    {
        $tables = TeamCalendarsFixture::tables();
        $tables['EVENTS'][0]['RECURRENCE_RULE'] = 'FREQ=WEEKLY;COUNT=-1;BYDAY=MO';
        $tables['EVENTS'][0]['SUMMARY'] = "Title\r\nATTENDEE:bad";
        $tables['EVENTS'][0]['DESCRIPTION'] = '<script>alert(1)</script><p>Text</p>';
        $tables['EVENTS'][] = array_replace($tables['EVENTS'][0], ['ID' => 'moved', 'RECURRENCE_ID_TIMESTAMP' => '1782723600000', 'START' => '1782810000000', 'UTC_START' => '1782810000000', 'END' => '1782813600000', 'UTC_END' => '1782813600000']);
        $tables['EVENTS_EXCL'] = [['ID' => '1', 'EVENT_ID' => 'event-one', 'EXCLUSION' => '1783328400000', 'ALL_DAY' => 'false']];
        TeamCalendarsFixture::archive($this->path, $tables);
        $catalog = $this->index->prepare($this->path, hash_file('sha256', $this->path));
        $converted = $this->convert($catalog);
        self::assertSame(2, $converted['events']);
        $ics = $converted['batches'][0]['ics'];
        self::assertSame(1, substr_count($ics, 'RRULE:'));
        self::assertStringNotContainsString('COUNT=-1', $ics);
        self::assertSame(2, substr_count($ics, 'EXDATE;TZID='));
        self::assertStringContainsString('RECURRENCE-ID;TZID=Europe/Zagreb:20260629T110000', $ics);
        self::assertStringNotContainsString("\r\nATTENDEE:", $ics);
        self::assertStringNotContainsString('<script', $ics);
        self::assertStringNotContainsString('VALARM', $ics);
    }

    /**
     * EN: Unsupported recurrences never silently become non-recurring or partially imported series.
     *
     * HR: Nepodržana ponavljanja nikada se tiho ne pretvaraju u pojedinačne ili djelomično uvezene događaje.
     */
    public function testUnsupportedAndDuplicateSeriesAreReported(): void
    {
        foreach (['FREQ=HOURLY', 'FREQ=WEEKLY;BYDAY=MO,TU', 'FREQ=MONTHLY;BYMONTHDAY=22', 'FREQ=DAILY;INTERVAL=0'] as $rule) {
            $tables = TeamCalendarsFixture::tables();
            $tables['EVENTS'][0]['RECURRENCE_RULE'] = $rule;
            TeamCalendarsFixture::archive($this->path, $tables);
            $catalog = $this->index->prepare($this->path, hash_file('sha256', $this->path));
            $converted = $this->convert($catalog);
            self::assertSame(0, $converted['events']);
            self::assertSame(1, $converted['skipped']);
            self::assertNotEmpty($converted['warnings']);
        }
    }

    /**
     * EN: No full-site event collection is needed for a single source-calendar conversion.
     *
     * HR: Za pretvaranje jednog izvornog kalendara ne treba skup svih site događaja.
     *
     * @param array<string,mixed> $catalog
     * @return array<string,mixed>
     */
    private function convert(array $catalog): array
    {
        $calendar = $catalog['calendars']['calendar-one'];
        return (new ConfluenceTeamCalendarConverter())->convert(iterator_to_array($this->index->events($this->path, $calendar), false), $calendar);
    }
}
