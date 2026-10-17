<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;

/**
 * EN: Keeps Team Calendars metadata and root-calendar event streams in private application data.
 * Complete manifests bind the source bytes and every partition; interrupted scans cannot become previews.
 *
 * HR: Čuva metapodatke Team Calendara i tokove događaja po izvornom kalendaru u privatnim podacima.
 * Potpuni manifest veže bajtove izvora i sve dijelove; prekinuti pregled nije upotrebljiv za potvrdu.
 */
final readonly class ConfluenceSiteCalendarIndex
{
    private const VERSION = 1;

    /**
     * EN: Receives the bounded AO reader and application execution policy.
     *
     * HR: Prima ograničeni AO čitač i izvršna pravila aplikacije.
     */
    public function __construct(private ConfluenceTeamCalendarReader $reader, private ConfluenceImportConfig $config)
    {
    }

    /**
     * EN: A normal GET reads only a small complete catalog and never opens the source ZIP.
     *
     * HR: Obični GET čita samo mali potpuni pregled i nikada ne otvara izvorni ZIP.
     *
     * @return array<string,mixed>|null
     */
    public function cached(string $path, string $hash): ?array
    {
        if ($path === '' || !is_file($path) || is_link($path)) {
            return null;
        }
        $file = $this->directory($path) . '/catalog.json';
        if (!is_file($file) || is_link($file) || filesize($file) > 16777216) {
            return null;
        }
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) && ($data['version'] ?? 0) === self::VERSION
            && ($data['source_sha256'] ?? '') === $hash && ($data['source_stat'] ?? []) === $this->stat($path)
            && is_array($data['calendars'] ?? null) ? $data : null;
    }

    /**
     * EN: Scans once, then partitions the small retained event stream without a second multi-GB XML pass.
     * Credentials, subscription endpoints and reminder settings are never retained.
     *
     * HR: Jednom čita arhivu pa dijeli mali zadržani tok događaja bez drugog prolaza višegigabajtnog XML-a.
     * Vjerodajnice, adrese pretplata i postavke podsjetnika nikada se ne čuvaju.
     *
     * @return array<string,mixed>|null Null means an existing worker owns the preparation lock.
     */
    public function prepare(string $path, string $hash): ?array
    {
        $lockPath = $path . '.calendars.lock';
        if (is_link($lockPath)) {
            throw new ConfluenceImportException(__('Putanja Confluence indeksa nije valjana.'));
        }
        $lock = fopen($lockPath, 'c+');
        if ($lock === false) {
            throw new ConfluenceImportException(__('Indeks kalendara nije moguće spremiti.'));
        }
        try {
            chmod($lockPath, 0660);
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return null;
            }
            $progress = new ConfluenceCalendarPreparation($path, $hash);
            $progress->update('verify');
            try {
                $catalog = $this->build($path, $hash, $progress);
                $progress->update('ready', count($catalog['calendars']), count($catalog['calendars']));
                return $catalog;
            } catch (\Throwable $error) {
                $progress->update('failed');
                throw $error;
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * EN: Polls an existing worker without starting work, acquiring its lock or reading archive contents.
     * A released lock with an incomplete snapshot is an interruption, not an endless busy state.
     *
     * HR: Prati postojeći postupak bez pokretanja rada, preuzimanja njegova zaključavanja ili čitanja arhive.
     * Otpušteno zaključavanje uz nepotpuni snimak znači prekid, a ne beskonačno čekanje.
     *
     * @return array<string,mixed>
     */
    public function preparation(string $path, string $hash): array
    {
        $snapshot = ConfluenceCalendarPreparation::read($path, $hash);
        $lockPath = $path . '.calendars.lock';
        if (is_link($lockPath)) {
            throw new ConfluenceImportException(__('Putanja Confluence indeksa nije valjana.'));
        }
        $active = false;
        if (is_file($lockPath)) {
            $lock = fopen($lockPath, 'rb');
            if ($lock === false) {
                throw new ConfluenceImportException(__('Indeks kalendara nije moguće pročitati.'));
            }
            $active = !flock($lock, LOCK_EX | LOCK_NB);
            fclose($lock);
        }
        if ($active) {
            return ['status' => 'running'] + ($snapshot ?? ['phase' => 'scan', 'done' => 0, 'total' => null, 'started_at' => 0]);
        }
        if (($snapshot['phase'] ?? '') === 'failed') {
            return ['status' => 'failed'] + $snapshot;
        }
        $catalog = $this->cached($path, $hash);
        if ($catalog !== null) {
            return ['status' => 'ready', 'phase' => 'ready', 'done' => count($catalog['calendars']),
                'total' => count($catalog['calendars']), 'started_at' => $snapshot['started_at'] ?? 0];
        }
        return ['status' => $snapshot !== null ? 'interrupted' : 'idle', 'phase' => '', 'done' => 0, 'total' => null, 'started_at' => 0];
    }

    /**
     * EN: The preparation lock serializes cache rebuilds; missing/corrupt partitions can be rebuilt safely.
     *
     * HR: Zaključavanje pripreme serijalizira obnovu indeksa; nedostajući/oštećeni dijelovi sigurno se obnavljaju.
     *
     * @return array<string,mixed>
     */
    private function build(string $path, string $hash, ConfluenceCalendarPreparation $progress): array
    {
        $cached = $this->cached($path, $hash);
        if ($cached !== null) {
            $valid = true;
            foreach ($cached['calendars'] as $calendar) {
                $file = $this->eventsPath($path, (string)$calendar['source_id']);
                $valid = $valid && !is_link($file) && is_file($file) && filesize($file) === $calendar['events_bytes']
                    && hash_equals($calendar['events_sha256'], (string)hash_file('sha256', $file));
            }
            if ($valid) {
                return $cached;
            }
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1 || !hash_equals($hash, (string)hash_file('sha256', $path))) {
            throw new ConfluenceImportException(__('Izvornu arhivu nije moguće provjeriti.'));
        }
        if (function_exists('set_time_limit')) {
            set_time_limit($this->config->importExecutionTimeLimit());
        }
        $before = $this->stat($path);
        $directory = $this->directory($path);
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0770, true))) {
            throw new ConfluenceImportException(__('Indeks kalendara nije moguće spremiti.'));
        }
        foreach (glob($directory . '/*') ?: [] as $file) {
            if (is_link($file) || !is_file($file) || !unlink($file)) {
                throw new ConfluenceImportException(__('Indeks kalendara nije moguće spremiti.'));
            }
        }
        $this->write($directory . '/events.tmp', '');
        $sub = $acl = $spaces = $types = $exclusions = $invitees = $counts = $seen = [];
        $eventBytes = 0;
        $progress->update('scan');
        $records = 0;
        foreach ($this->reader->rows($path) as $record) {
            $progress->update('scan', ++$records);
            $table = $record['table'];
            $row = $record['row'];
            $id = (string)($row['ID'] ?? '');
            if ($id === '' || strlen($id) > 190 || isset($seen[$table][$id])) {
                throw new ConfluenceImportException(__('Team Calendars ima nevaljan ili ponovljen ID.'));
            }
            $seen[$table][$id] = true;
            $counts[$table] = ($counts[$table] ?? 0) + 1;
            if ($table === 'EVENTS') {
                $line = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
                $eventBytes += strlen($line);
                if ($eventBytes > 536870912) {
                    throw new ConfluenceImportException(__('Indeks kalendara prelazi dopušteni limit.'));
                }
                if (file_put_contents($directory . '/events.tmp', $line, FILE_APPEND) !== strlen($line)) {
                    throw new ConfluenceImportException(__('Indeks kalendara nije moguće spremiti.'));
                }
            } elseif ($table === 'SUBCALS') {
                $sub[$id] = $row;
            } elseif (str_starts_with($table, 'SUBCALS_PRIV_')) {
                $acl[$row['SUB_CALENDAR_ID']][] = ['kind' => $table === 'SUBCALS_PRIV_GRP' ? 'group' : 'user',
                    'key' => $row[$table === 'SUBCALS_PRIV_GRP' ? 'GROUP_NAME' : 'USER_KEY'], 'type' => $row['TYPE']];
            } elseif ($table === 'SUBCALS_IN_SPACE') {
                $spaces[$row['SUB_CALENDAR_ID']][$row['SPACE_KEY']] = true;
            } elseif ($table === 'CUSTOM_EV_TYPES') {
                $types[$id] = $row['TITLE'];
            } elseif ($table === 'EVENTS_EXCL') {
                $exclusions[$row['EVENT_ID']][] = ['value' => $row['EXCLUSION'], 'all_day' => $row['ALL_DAY'] === 'true'];
            } elseif ($table === 'EVENTS_INVITEES') {
                $invitees[$row['EVENT_ID']][] = $row['INVITEE_ID'];
            }
            if (count($sub) > 20000 || count($types) > 20000 || count($exclusions) > 100000 || count($invitees) > 100000) {
                throw new ConfluenceImportException(__('Indeks kalendara prelazi dopušteni limit.'));
            }
        }
        unset($seen);
        $calendars = $aliases = [];
        foreach ($sub as $id => $row) {
            $root = self::root($id, $sub);
            if ($root === null) {
                continue;
            }
            $aliases[$id] = $root;
            if (!isset($calendars[$root])) {
                $source = $sub[$root];
                $calendars[$root] = ['source_id' => $root, 'name' => $source['NAME'], 'description' => $source['DESCRIPTION'],
                    'owner_key' => $source['CREATOR'], 'color' => $source['COLOUR'], 'timezone' => $source['TIME_ZONE_ID'] ?: 'UTC',
                    'space_keys' => [], 'acl' => $acl[$root] ?? [], 'types' => [], 'events' => 0, 'subscriptions' => 0];
                $this->write($this->eventsPath($path, $root), '');
            }
            if ($row['SUBSCRIPTION_ID'] !== '') {
                ++$calendars[$root]['subscriptions'];
            } else {
                $calendars[$root]['types'][$id] = ['source_id' => $row['ID'],
                    'label' => $types[$row['USING_CUSTOM_EVENT_TYPE_ID']] ?? $row['NAME'],
                    'store' => $row['STORE_KEY'], 'timezone' => $row['TIME_ZONE_ID'] ?: $calendars[$root]['timezone'],
                    'acl' => $acl[$id] ?? []];
            }
            foreach ([$row['SPACE_KEY'], ...array_keys($spaces[$id] ?? [])] as $key) {
                if ($key !== '') {
                    $calendars[$root]['space_keys'][$key] = true;
                }
            }
        }
        $orphanEvents = 0;
        $partitioned = 0;
        $progress->update('partition', 0, $counts['EVENTS'] ?? 0);
        foreach ($this->lines($directory . '/events.tmp') as $row) {
            $progress->update('partition', ++$partitioned, $counts['EVENTS'] ?? 0);
            $root = $aliases[$row['SUB_CALENDAR_ID']] ?? null;
            if ($root === null || ($sub[$row['SUB_CALENDAR_ID']]['SUBSCRIPTION_ID'] ?? '') !== '') {
                ++$orphanEvents;
                continue;
            }
            $row['_exclusions'] = $exclusions[$row['ID']] ?? [];
            $row['_invitees'] = $invitees[$row['ID']] ?? [];
            $line = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
            if (file_put_contents($this->eventsPath($path, $root), $line, FILE_APPEND) !== strlen($line)) {
                throw new ConfluenceImportException(__('Indeks kalendara nije moguće spremiti.'));
            }
            ++$calendars[$root]['events'];
        }
        unlink($directory . '/events.tmp');
        $finished = [];
        $progress->update('finalize');
        foreach ($calendars as $id => $calendar) {
            $file = $this->eventsPath($path, $id);
            $calendar['events_sha256'] = hash_file('sha256', $file);
            $calendar['events_bytes'] = filesize($file);
            $calendar['space_keys'] = array_keys($calendar['space_keys'] ?? []);
            $finished[$id] = $calendar;
        }
        uasort($finished, static fn(array $a, array $b): int => strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
        $result = ['version' => self::VERSION, 'source_sha256' => $hash, 'source_stat' => $before, 'counts' => $counts,
            'calendars' => $finished, 'aliases' => $aliases, 'unresolved_subcalendars' => count($sub) - count($aliases),
            'unresolved_events' => $orphanEvents];
        if ($before !== $this->stat($path)) {
            throw new ConfluenceImportException(__('Izvornu arhivu nije moguće provjeriti.'));
        }
        $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 16777216) {
            throw new ConfluenceImportException(__('Indeks kalendara prelazi dopušteni limit.'));
        }
        $this->write($directory . '/catalog.tmp', $json);
        if (!rename($directory . '/catalog.tmp', $directory . '/catalog.json')) {
            throw new ConfluenceImportException(__('Indeks kalendara nije moguće spremiti.'));
        }
        return $result;
    }

    /**
     * EN: Follows only archive-local native calendars and internal subscriptions; cycles fail closed.
     *
     * HR: Slijedi samo lokalne nativne kalendare i interne pretplate iz arhive; ciklusi se ne razrješavaju.
     *
     * @param array<string,array<string,string>> $sub
     */
    public static function root(string|int $id, array $sub): ?string
    {
        $id = (string)$id;
        $seen = [];
        while (isset($sub[$id]) && !isset($seen[$id]) && count($seen) < 32) {
            $seen[$id] = true;
            $row = $sub[$id];
            if ($row['SUBSCRIPTION_ID'] !== '') {
                $id = $row['SUBSCRIPTION_ID'];
            } elseif ($row['PARENT_ID'] !== '') {
                $id = $row['PARENT_ID'];
            } else {
                return str_ends_with($row['STORE_KEY'], '.GenericSubCalendarDataStore') ? $id : null;
            }
        }
        return null;
    }

    /**
     * EN: Verifies a complete private partition before converting its events.
     *
     * HR: Provjerava potpuni privatni dio prije pretvaranja njegovih događaja.
     *
     * @param array<string,mixed> $calendar
     * @return \Generator<int,array<string,mixed>>
     */
    public function events(string $path, array $calendar): \Generator
    {
        $file = $this->eventsPath($path, (string)$calendar['source_id']);
        if (
            is_link($file) || !is_file($file) || filesize($file) !== $calendar['events_bytes']
            || !hash_equals((string)$calendar['events_sha256'], (string)hash_file('sha256', $file))
        ) {
            throw new ConfluenceImportException(__('Indeks kalendara nije potpun. Ponovno pripremite pregled.'));
        }
        yield from $this->lines($file);
    }

    /**
     * EN: Uses an importer-owned sibling of the managed upload, not a browser-supplied destination.
     *
     * HR: Koristi susjednu putanju kojom upravlja importer, ne odredište iz preglednika.
     */
    private function directory(string $path): string
    {
        return $path . '.calendars';
    }

    /**
     * EN: Hashes source identifiers so they cannot become paths.
     *
     * HR: Hashira izvorne identifikatore kako ne bi postali putanje.
     */
    private function eventsPath(string $path, string|int $id): string
    {
        return $this->directory($path) . '/' . hash('sha256', (string)$id) . '.jsonl';
    }

    /**
     * EN: Bounds each retained JSON record, with deterministic stream cleanup.
     *
     * HR: Ograničava svaki zadržani JSON zapis uz sigurno zatvaranje toka.
     *
     * @return \Generator<int,array<string,mixed>>
     */
    private function lines(string $file): \Generator
    {
        $stream = fopen($file, 'rb');
        if ($stream === false) {
            throw new ConfluenceImportException(__('Indeks kalendara nije moguće pročitati.'));
        }
        try {
            while (($line = fgets($stream, 2097153)) !== false) {
                if (!str_ends_with($line, "\n")) {
                    throw new ConfluenceImportException(__('Indeks kalendara nije potpun. Ponovno pripremite pregled.'));
                }
                $row = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($row)) {
                    throw new ConfluenceImportException(__('Indeks kalendara nije potpun. Ponovno pripremite pregled.'));
                }
                yield $row;
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * EN: Private files are never followed through symlinks.
     *
     * HR: Privatne datoteke nikada se ne otvaraju kroz simboličke poveznice.
     */
    private function write(string $path, string $content): void
    {
        if (is_link($path) || file_put_contents($path, $content, LOCK_EX) !== strlen($content) || !chmod($path, 0660)) {
            throw new ConfluenceImportException(__('Indeks kalendara nije moguće spremiti.'));
        }
    }

    /**
     * EN: Binds cached catalogs to the actual current source file.
     *
     * HR: Veže spremljene preglede uz stvarnu aktualnu izvornu datoteku.
     *
     * @return array<string,int>
     */
    private function stat(string $path): array
    {
        clearstatcache(true, $path);
        $stat = is_file($path) ? stat($path) : false;
        if (!is_array($stat)) {
            throw new ConfluenceImportException(__('Izvornu arhivu nije moguće provjeriti.'));
        }
        return array_intersect_key($stat, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']));
    }
}
