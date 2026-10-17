<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use Psr\Container\ContainerInterface;

/**
 * EN: Reviews and imports selected site calendars through the optional public Calendar API.
 * C2 mappings are read-only inputs; every batch and checkpoint share one database transaction.
 *
 * HR: Pregledava i uvozi odabrane site kalendare kroz opcionalni javni Calendar API.
 * C2 mapiranja samo su ulaz; svaki paket i kontrolna točka dijele jednu transakciju baze.
 */
final readonly class ConfluenceSiteCalendarService
{
    private const MANAGER = \AaiEduHr\HeartPhrameModuleCalendar\Service\CalendarManagerInterface::class;

    /**
     * EN: Receives importer orchestration and existing public module boundaries, never foreign table writers.
     *
     * HR: Prima orkestraciju importera i postojeće javne granice modula, ne zapisivače tuđih tablica.
     */
    public function __construct(
        private ConfluenceImportRepository $repository,
        private ConfluenceSiteCalendarIndex $index,
        private ConfluenceTeamCalendarConverter $converter,
        private ConfluenceSitePrincipals $principals,
        private ConfluenceSiteAuth $auth,
        private ConfluenceCalendarResolutionService $resolution,
        private ContainerInterface $container,
    ) {
    }

    /**
     * EN: Normal page views never initiate archive scanning or calendar writes.
     *
     * HR: Obični prikaz stranice nikada ne pokreće čitanje arhive ili upis kalendara.
     *
     * @return array<string,mixed>
     */
    public function state(string $uuid, int $actor): array
    {
        $job = $this->job($uuid, $actor, true);
        $catalog = $this->index->cached((string)$job['archive_path'], (string)$job['summary']['source_sha256']);
        $url = $job['options']['site_calendars']['source_url'] ?? $job['options']['site_access']['source_url'] ?? '';
        $projection = ['users' => [], 'aliases' => [], 'owners' => []];
        $principals = null;
        if (
            $catalog !== null
            && ($principals = $this->principals->cached((string)$job['archive_path'], (string)$job['summary']['source_sha256'])) !== null
        ) {
            $projection = $this->projection($job);
        }
        $plan = $job['options']['site_calendars'] ?? [];
        $draft = $job['options']['site_workflow_draft'] ?? [];
        if ($draft !== [] && ($job['options']['site_workflow']['phase'] ?? 'draft') === 'draft') {
            $plan = ['phase' => 'draft', 'decisions' => []];
            foreach ($draft['calendar_ids'] ?? [] as $id) {
                $plan['decisions'][$id] = ['calendar_type' => $draft['calendar_types'][$id] ?? '', 'owner_ref' => $draft['calendar_owners'][$id] ?? ''];
            }
        }
        return ['available' => $this->container->has(self::MANAGER),
            'busy' => !in_array($job['status'], ['ready', 'completed'], true),
            'catalog' => $catalog, 'preparation' => $catalog === null && $job['archive_path'] !== ''
                ? $this->index->preparation((string)$job['archive_path'], (string)$job['summary']['source_sha256']) : [],
            'owner_choices' => $projection['owners'],
            'owner_suggestions' => ConfluenceCalendarOwnerSuggestions::build($catalog['calendars'] ?? [], $principals['users'] ?? [], $projection['owners']),
            'plan' => $plan,
            'source_url' => $url];
    }

    /**
     * EN: Explicit preparation creates only a private catalog; no historical messages or external fetches run.
     *
     * HR: Izričita priprema stvara samo privatni pregled; ne šalje povijesne poruke ili vanjske zahtjeve.
     *
     * @return array<string,mixed>
     */
    public function prepare(string $uuid, int $actor): array
    {
        $job = $this->job($uuid, $actor);
        $this->repository->assertSiteSetupMutable($job);
        $this->assertIdle($job);
        $catalog = $this->index->prepare((string)$job['archive_path'], (string)$job['summary']['source_sha256']);
        $this->job($uuid, $actor);
        return ['prepared' => $catalog !== null, 'calendars' => count($catalog['calendars'] ?? []),
            'preparation' => $this->index->preparation((string)$job['archive_path'], (string)$job['summary']['source_sha256'])];
    }

    /**
     * EN: Returns owner-authorized preparation telemetry without generating plans or importing data.
     *
     * HR: Vraća podatke o pripremi ovlaštenom vlasniku bez stvaranja planova ili uvoza podataka.
     *
     * @return array<string,mixed>
     */
    public function preparation(string $uuid, int $actor): array
    {
        $job = $this->job($uuid, $actor, true);
        return ['preparation' => $this->index->preparation((string)$job['archive_path'], (string)$job['summary']['source_sha256'])];
    }

    /**
     * EN: Searches existing local owners through Auth's paged API plus selected future source accounts.
     * Existing owners do not require a source identity mapping; choosing one never creates that mapping.
     *
     * HR: Pretražuje postojeće lokalne vlasnike kroz paginirani Auth API i odabrane buduće izvorne račune.
     * Postojeći vlasnik ne zahtijeva mapiranje izvornog identiteta; odabir ne stvara to mapiranje.
     *
     * @return array<string,mixed>
     */
    public function ownerPage(string $uuid, int $actor, string $search, int $page): array
    {
        $job = $this->job($uuid, $actor, true);
        $search = mb_strtolower(mb_substr(trim($search), 0, 100));
        $page = max(1, $page);
        $local = $this->auth->userPage($search, $page, 20);
        $results = array_map(static fn(array $user): array => ['id' => 'local-user:' . $user['id'], 'label' => $user['label']], $local['items']);
        $catalog = $this->principals->cached((string)$job['archive_path'], (string)$job['summary']['source_sha256']);
        $planned = [];
        foreach ($catalog['users'] ?? [] as $source) {
            if (($job['options']['site_access']['users'][$source['source_id']] ?? '') !== '__create__') {
                continue;
            }
            $label = $source['display_name'] ?: $source['username'];
            $label .= ' · ' . $source['username'];
            if ($search === '' || str_contains(mb_strtolower($label . ' ' . $source['email']), $search)) {
                $planned[] = ['id' => $source['source_id'], 'label' => __('Novi neaktivni račun') . ': ' . $label];
            }
        }
        return ['ok' => true, 'page' => $page, 'hasMore' => $local['hasMore'] || count($planned) > $page * 5,
            'results' => [...$results, ...array_slice($planned, ($page - 1) * 5, 5)]];
    }

    /**
     * EN: Saves an owner-bound expiring review with current C2 maps, targets and per-calendar warnings.
     * Unmapped restrictions fail closed; no anonymous/authenticated-wide access is inferred from missing AO rows.
     *
     * HR: Sprema vremenski ograničen pregled vlasnika uz aktualna C2 mapiranja, ciljeve i upozorenja.
     * Nemapirana ograničenja se ne šire; iz nedostajućih AO redaka ne izvodi se javni pristup.
     *
     * @param list<string> $ids
     * @param mixed $types Explicit source-ID to personal/team choices, validated before saving the review.
     * @param mixed $ownerRefs Explicit calendar-ID to selected source-owner references.
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function review(string $uuid, array $actor, string $url, array $ids, mixed $types = [], mixed $ownerRefs = [], bool $projected = false): array
    {
        $job = $this->job($uuid, (int)$actor['id']);
        $this->repository->assertSiteSetupMutable($job);
        $this->manager();
        $url = ConfluenceSiteAccessService::sourceUrl($url);
        $catalog = $this->catalog($job);
        if ($ids === [] || count($ids) > 1000 || count(array_unique($ids, SORT_STRING)) !== count($ids)) {
            throw new ConfluenceImportException(__('Odaberite barem jedan izvorni kalendar.'));
        }
        if (!is_array($types) || count($types) > count($ids) || array_diff(array_keys($types), $ids) !== []) {
            throw new ConfluenceImportException(__('Odabir vrste kalendara nije valjan.'));
        }
        if (!is_array($ownerRefs) || count($ownerRefs) > count($ids) || array_diff(array_keys($ownerRefs), $ids) !== []) {
            throw new ConfluenceImportException(__('Odabir vlasnika kalendara nije valjan.'));
        }
        $projection = $this->projection($job, array_values($ownerRefs));
        $maps = $this->maps($job, $url);
        $existing = $this->repository->calendarMappings($url);
        $decisions = [];
        foreach ($ids as $id) {
            if (!is_string($id) || !isset($catalog['calendars'][$id])) {
                throw new ConfluenceImportException(__('Odabir kalendara nije valjan.'));
            }
            $calendar = $catalog['calendars'][$id];
            $converted = $this->converted($job, $calendar);
            $type = $types[$id] ?? ConfluenceCalendarPresentation::suggestedType($calendar);
            if (!in_array($type, ['personal', 'team'], true)) {
                throw new ConfluenceImportException(__('Odabir vrste kalendara nije valjan.'));
            }
            // EN: Confluence's creator is provenance, not proof of personal ownership.
            //
            // HR: Confluenceov kreator podatak je o nastanku, a ne dokaz osobnog vlasništva.
            $ownerRef = $ownerRefs[$id] ?? '';
            if (!is_string($ownerRef) || ($ownerRef !== '' && !isset($projection['owners'][$ownerRef]))) {
                throw new ConfluenceImportException(__('Odabir vlasnika kalendara nije valjan.'));
            }
            if ($type === 'personal' && $ownerRef === '') {
                throw new ConfluenceImportException(__('Odaberite vlasnika osobnog kalendara.'));
            }
            $owner = $ownerRef !== '' ? ($projected || str_starts_with($ownerRef, 'local-user:')
                ? $projection['owners'][$ownerRef]['target'] : ($maps['users'][$ownerRef] ?? null)) : null;
            if ($type === 'personal' && $owner === null) {
                throw new ConfluenceImportException(sprintf(__('Osobni kalendar mora imati potvrđeno mapiranog vlasnika: %s'), $calendar['name']));
            }
            $access = $this->access($calendar, $maps);
            $color = ConfluenceCalendarPresentation::color((string)$calendar['color']);
            if ($color === null) {
                $converted['warnings'][] = ['id' => $id, 'reason' => __('Izvorna boja kalendara nije prepoznata; koristi se zadana boja.')];
            }
            if ($type === 'personal') {
                $access['owner_id'] = is_int($owner) ? $owner : null;
            }
            $knownSpaces = array_fill_keys(array_column($job['summary']['spaces'] ?? [], 'source_key'), true);
            foreach ($calendar['space_keys'] as $key) {
                if (!isset($knownSpaces[$key])) {
                    $access['warnings'][] = sprintf(__('Izvorno područje kalendara nije pronađeno u inventaru: %s'), $key);
                }
            }
            $target = isset($existing[$id]) ? $this->call('calendar', [$existing[$id], $actor]) : null;
            if (isset($existing[$id]) && !is_array($target)) {
                throw new ConfluenceImportException(__('Ranije uvezeni kalendar više nije dostupan. Ručno provjerite mapiranje.'));
            }
            $this->assertTarget($target, $type, $access['owner_id'], $projected && is_string($owner));
            $decisions[$id] = ['name' => $calendar['name'], 'events' => $converted['events'], 'skipped' => $converted['skipped'],
                'warnings' => $converted['warnings'], 'access_warnings' => $access['warnings'], 'rules' => $access['rules'],
                'owner_id' => $access['owner_id'], 'owner_ref' => $ownerRef, 'owner_label' => $projection['owners'][$ownerRef]['label'] ?? '',
                'calendar_type' => $type, 'color' => $color ?? '#3B82F6', 'batches' => count($converted['batches']), 'target' => $target,
                'space_keys' => $calendar['space_keys'], 'events_sha256' => $calendar['events_sha256']];
        }
        $plan = ['phase' => 'review', 'projected' => $projected, 'source_url' => $url, 'source_sha256' => $catalog['source_sha256'],
            'identities_digest' => $this->digest($maps), 'decisions' => $decisions, 'token' => bin2hex(random_bytes(32)),
            'expires_at' => time() + 1800, 'position' => 0, 'batch' => 0, 'results' => []];
        return $this->repository->withLockedJob($uuid, (int)$actor['id'], function (array $current) use ($plan): array {
            $this->assertIdle($current);
            $options = $current['options'] ?? [];
            $options['site_calendars'] = $plan;
            $this->repository->updateJob((int)$current['id'], ['options_json' => json_encode($options, JSON_THROW_ON_ERROR), 'expires_at' => null]);
            return ['reviewed' => true, 'calendars' => count($plan['decisions'])];
        });
    }

    /**
     * EN: Queues only the exact reviewed selection; changed identities/targets require a new review.
     *
     * HR: Pokreće samo točan pregledani odabir; promijenjeni identiteti/ciljevi zahtijevaju novi pregled.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function start(string $uuid, array $actor, string $token): array
    {
        $job = $this->job($uuid, (int)$actor['id']);
        $this->verifySource($job);
        return $this->repository->withLockedJob($uuid, (int)$actor['id'], function (array $current) use ($token, $actor): array {
            $current = $this->repository->jobByUuid((string)$current['uuid'], (int)$actor['id']);
            $this->repository->assertSiteSetupMutable($current);
            $this->assertIdle($current);
            $plan = $current['options']['site_calendars'] ?? [];
            if (
                ($plan['phase'] ?? '') !== 'review' || ($plan['projected'] ?? false) || $token === '' || !hash_equals((string)($plan['token'] ?? ''), $token)
                || (int)($plan['expires_at'] ?? 0) < time()
            ) {
                throw new ConfluenceImportException(__('Pregled kalendara je istekao ili nije valjan. Ponovite pregled.'));
            }
            $this->verifyMaps($current, $plan);
            $targets = $this->repository->calendarMappings($plan['source_url']);
            foreach ($plan['decisions'] as $id => $decision) {
                $target = isset($targets[$id]) ? $this->call('calendar', [$targets[$id], $actor]) : null;
                if ($this->digest($target) !== $this->digest($decision['target'])) {
                    throw new ConfluenceImportException(__('Ciljni kalendar je promijenjen. Ponovite pregled.'));
                }
                $this->assertTarget($target, $decision['calendar_type'] ?? '', $decision['owner_id']);
            }
            $plan['phase'] = 'running';
            $report = $this->repository->createUploadJob('Team Calendars', '', 0, 0, (int)$actor['id'], time() + 3600);
            $plan['report_uuid'] = $report['uuid'];
            $this->repository->updateJob((int)$report['id'], ['operation' => 'site_calendars', 'status' => 'running',
                'stage' => 'calendars', 'expires_at' => null, 'sha256' => $plan['source_sha256']]);
            $summary = $current['summary'];
            $summary['site_reports']['calendars'][] = $report['uuid'];
            $this->repository->updateJob((int)$current['id'], ['summary_json' => json_encode($summary, JSON_THROW_ON_ERROR)]);
            unset($plan['token'], $plan['expires_at']);
            $this->save($current, $plan);
            return $this->progress($plan);
        });
    }

    /**
     * EN: Commits one bounded batch and its cursor atomically, so lost HTTP responses cannot duplicate events.
     * Existing calendars/events are reused without overwriting manual changes; Calendar's bulk API emits no messages.
     *
     * HR: Atomski potvrđuje jedan ograničeni paket i pokazivač pa izgubljeni HTTP odgovor ne duplicira događaje.
     * Postojeći kalendari/događaji koriste se bez prepisivanja ručnih izmjena; skupni Calendar API ne šalje poruke.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function process(string $uuid, array $actor): array
    {
        $this->job($uuid, (int)$actor['id']);
        return $this->repository->withLockedJob($uuid, (int)$actor['id'], function (array $current, Database $database) use ($actor): array {
            $job = $this->repository->jobByUuid((string)$current['uuid'], (int)$actor['id']);
            $this->repository->assertSiteSetupMutable($job);
            $plan = $job['options']['site_calendars'] ?? [];
            if (($plan['phase'] ?? '') === 'completed') {
                return $this->progress($plan);
            }
            if (($plan['phase'] ?? '') !== 'running' || $job['status'] === 'running') {
                throw new ConfluenceImportException(__('Uvoz kalendara nije pokrenut ili se sadržaj još uvozi.'));
            }
            $this->verifyMaps($job, $plan);
            $catalog = $this->catalog($job);
            $reportJob = $this->repository->jobByUuid($plan['report_uuid'], (int)$actor['id']);
            $ids = array_keys($plan['decisions']);
            $id = $ids[$plan['position']] ?? null;
            if ($id !== null) {
                $source = $catalog['calendars'][$id];
                $converted = $this->converted($job, $source);
                $decision = $plan['decisions'][$id];
                $targets = $this->repository->calendarMappings($plan['source_url']);
                $target = isset($targets[$id]) ? $this->call('calendar', [$targets[$id], $actor]) : null;
                if ($target === null && isset($targets[$id])) {
                    throw new ConfluenceImportException(__('Ranije uvezeni kalendar više nije dostupan. Ručno provjerite mapiranje.'));
                }
                $this->assertTarget($target, $decision['calendar_type'] ?? '', $decision['owner_id']);
                $target ??= $this->call('createCalendar', [['name' => $source['name'],
                    'description' => trim(strip_tags((string)$source['description'])), 'calendar_type' => $decision['calendar_type'],
                    'owner_user_id' => $decision['calendar_type'] === 'personal' ? $decision['owner_id'] : null,
                    'personal_preset' => 'custom',
                    'personal_suffix' => trim(strip_tags((string)$source['name'])) . ' [' . substr(hash('sha256', $plan['source_url'] . ':' . $id), 0, 8) . ']',
                    'color' => $decision['color'], 'subscribe_actor' => false,
                    'is_public_read' => false, 'is_authenticated_read' => false,
                    'show_on_public_index' => false, 'show_public_link' => false, 'access_rules' => $decision['rules']], $actor]);
                foreach ($catalog['aliases'] as $alias => $root) {
                    if ($root === $id) {
                        if (isset($targets[$alias]) && $targets[$alias] !== (int)$target['id']) {
                            throw new ConfluenceImportException(__('Podkalendar je već povezan s drugim ciljem. Provjerite mapiranje.'));
                        }
                        $this->repository->mapCalendar($plan['source_url'], (string)$alias, (int)$target['id'], (int)$reportJob['id']);
                    }
                }
                $result = $plan['results'][$id] ?? ['name' => $source['name'], 'target_calendar_id' => (int)$target['id'],
                    'target_uuid' => $target['uuid'], 'calendar_type' => $decision['calendar_type'], 'created' => 0, 'existing' => 0, 'skipped' => $converted['skipped'],
                    'warnings' => $decision['warnings'], 'access_warnings' => $decision['access_warnings']];
                $batch = $converted['batches'][$plan['batch']] ?? null;
                if ($batch !== null) {
                    $imported = $this->call('importCalendar', [['calendar_id' => (int)$target['id'], 'ics' => $batch['ics'],
                        'merge_mode' => 'skip', 'event_type_name' => $batch['type']], $actor]);
                    $result['created'] += (int)$imported['created'];
                    $result['existing'] += (int)$imported['skipped'];
                }
                $plan['results'][$id] = $result;
                ++$plan['batch'];
                if ($plan['batch'] >= count($converted['batches'])) {
                    ++$plan['position'];
                    $plan['batch'] = 0;
                }
            }
            if ($plan['position'] >= count($ids)) {
                $plan['phase'] = 'completed';
            }
            $this->repository->updateJob((int)$reportJob['id'], ['status' => $plan['phase'] === 'completed' ? 'completed' : 'running',
                'stage' => $plan['phase'] === 'completed' ? 'completed' : 'calendars',
                'summary_json' => json_encode(['source_url' => $plan['source_url'], 'source_sha256' => $plan['source_sha256'],
                    'parent_uuid' => $job['uuid'], 'results' => $plan['results']], JSON_THROW_ON_ERROR)]);
            $this->save($job, $plan);
            return $this->progress($plan);
        });
    }

    /**
     * EN: Reuses the existing page resolver only for this owner's completed site children.
     * Drafts, deleted pages and manually changed placeholders remain reported, not overwritten.
     *
     * HR: Koristi postojeći resolver stranica samo za dovršene site podposlove ovog vlasnika.
     * Nacrti, obrisane stranice i ručno promijenjene oznake prijavljuju se, ne prepisuju se.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function linkPages(string $uuid, array $actor): array
    {
        $job = $this->job($uuid, (int)$actor['id']);
        $this->repository->assertSiteSetupMutable($job);
        $plan = $job['options']['site_calendars'] ?? [];
        if (($plan['phase'] ?? '') !== 'completed') {
            throw new ConfluenceImportException(__('Najprije dovršite uvoz kalendara.'));
        }
        $cursor = [];
        do {
            $report = $this->linkPageBatch($uuid, $actor, $cursor);
            $cursor = $report['cursor'];
        } while (!$report['completed']);
        return ['linked' => $report['linked'], 'unresolved' => $report['unresolved']];
    }

    /**
     * EN: Links at most twenty review entries, including calendars mapped by an earlier import of this source.
     * A stable report/page/issue cursor also revisits older space reports from this verified source.
     *
     * HR: Povezuje najviše dvadeset stavki pregleda, uključujući kalendare mapirane ranijim uvozom istog izvora.
     * Stabilni pokazivač izvještaja/stranice/stavke obrađuje i ranije izvještaje istog provjerenog izvora.
     *
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $cursor
     * @return array<string,mixed>
     */
    public function linkPageBatch(string $uuid, array $actor, array $cursor = []): array
    {
        $job = $this->job($uuid, (int)$actor['id']);
        $this->repository->assertSiteSetupMutable($job);
        if (($job['options']['site_calendars']['phase'] ?? '') === 'running') {
            throw new ConfluenceImportException(__('Najprije dovršite uvoz kalendara.'));
        }
        $url = $job['options']['site_workflow']['input']['source_url'] ?? $job['options']['site_content']['source_url']
            ?? $job['options']['site_calendars']['source_url'] ?? '';
        return $this->repository->withReferenceContext($url, fn(): array => $this->linkSourcePages($job, $actor, $cursor, $url));
    }

    /**
     * EN: Shares one verified origin catalog for a bounded batch of current and earlier page reports.
     *
     * HR: Dijeli jedan katalog potvrđenog izvora za ograničeni skup sadašnjih i ranijih izvještaja stranica.
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $cursor
     * @return array<string,mixed>
     */
    private function linkSourcePages(array $job, array $actor, array $cursor, string $url): array
    {
        $targets = $this->repository->calendarMappings($url);
        $cursor += ['after' => 0, 'page' => 0, 'issue' => 0, 'linked' => 0, 'unresolved' => []];
        $processed = 0;
        $reports = 0;
        while (($child = $this->repository->nextSourceSpaceReport($url, (int)$actor['id'], (int)$cursor['after'])) !== null) {
            if (++$reports > 20) {
                return $this->savePageLinks($job, $cursor, false);
            }
            $pages = array_values($child['summary']['review_pages'] ?? []);
            while ($cursor['page'] < count($pages)) {
                $page = $pages[$cursor['page']];
                $issues = array_values($page['issues'] ?? []);
                while ($cursor['issue'] < count($issues)) {
                    if ($processed >= 20) {
                        return $this->savePageLinks($job, $cursor, false);
                    }
                    $issue = $issues[$cursor['issue']++];
                    ++$processed;
                    if (($issue['type'] ?? '') !== 'calendar' || ($issue['resolved'] ?? false)) {
                        continue;
                    }
                    $source = (string)($issue['source_calendar_id'] ?? '');
                    if (!isset($targets[$source])) {
                        continue;
                    }
                    try {
                        $calendar = $this->call('calendar', [$targets[$source], $actor]);
                        $this->resolution->resolve($child['uuid'], ['source_page_id' => (string)$page['source_page_id'],
                            'source_calendar_id' => $source, 'marker' => $issue['marker'], 'resolution_mode' => 'existing',
                            'calendar_uuid' => $calendar['uuid']], '', $actor);
                        ++$cursor['linked'];
                    } catch (ConfluenceImportException $error) {
                        // EN: A user draft or edited marker requires manual review, not an overwrite.
                        // Unexpected errors still stop the workflow instead of being reported as success.
                        //
                        // HR: Korisnički nacrt ili izmijenjena oznaka traži ručnu provjeru, ne prepisivanje.
                        // Neočekivane greške i dalje zaustavljaju postupak umjesto prijave uspjeha.
                        if (
                            !in_array($error->getMessage(), [__('Ciljna stranica još nije objavljena.'),
                            __('Stranica ima neobjavljeni nacrt. Najprije ga objavite ili odbacite pa ponovno povežite kalendar.'),
                            __('Oznaka Confluence kalendara više nije prisutna na stranici.')], true)
                        ) {
                            throw $error;
                        }
                        $cursor['manual_review'] = ($cursor['manual_review'] ?? 0) + 1;
                        if (count($cursor['unresolved']) < 100) {
                            $cursor['unresolved'][] = ['job' => $child['uuid'], 'page' => (string)$page['source_page_id'],
                                'calendar' => $source, 'reason' => $error->getMessage()];
                        }
                    }
                }
                ++$cursor['page'];
                $cursor['issue'] = 0;
            }
            $cursor['after'] = (int)$child['id'];
            $cursor['page'] = $cursor['issue'] = 0;
        }
        return $this->savePageLinks($job, $cursor, true);
    }

    /**
     * EN: Updates the parent's linked-page report without changing any detailed child report format.
     *
     * HR: Ažurira roditeljski izvještaj povezanih stranica bez promjene formata detaljnih podizvještaja.
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $cursor
     * @return array<string,mixed>
     */
    private function savePageLinks(array $job, array $cursor, bool $completed): array
    {
        $report = ['linked' => $cursor['linked'], 'manual_review' => $cursor['manual_review'] ?? 0,
            'unresolved' => $cursor['unresolved'], 'completed' => $completed];
        $current = $this->repository->jobByUuid($job['uuid'], (int)$job['actor_user_id']);
        $summary = $current['summary'];
        $summary['site_calendar_pages'] = $report;
        $this->repository->updateJob((int)$current['id'], ['summary_json' => json_encode($summary, JSON_THROW_ON_ERROR)]);
        return [...$report, 'cursor' => $cursor];
    }

    /**
     * EN: Ends interrupted execution without deleting committed calendars/events or provenance.
     * A new review can reuse existing targets after correcting C2 mappings or source decisions.
     *
     * HR: Završava prekinuto izvršavanje bez brisanja potvrđenih kalendara/događaja ili podrijetla.
     * Novi pregled koristi postojeće ciljeve nakon ispravka C2 mapiranja ili odluka izvora.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function stop(string $uuid, array $actor): array
    {
        $this->job($uuid, (int)$actor['id']);
        return $this->repository->withLockedJob($uuid, (int)$actor['id'], function (array $locked) use ($actor): array {
            $job = $this->repository->jobByUuid((string)$locked['uuid'], (int)$actor['id']);
            $this->repository->assertSiteSetupMutable($job);
            $plan = $job['options']['site_calendars'] ?? [];
            if (($plan['phase'] ?? '') !== 'running') {
                throw new ConfluenceImportException(__('Uvoz kalendara nije pokrenut ili se sadržaj još uvozi.'));
            }
            $report = $this->repository->jobByUuid($plan['report_uuid'], (int)$actor['id']);
            $this->repository->updateJob((int)$report['id'], ['status' => 'completed', 'stage' => 'completed',
                'summary_json' => json_encode(['source_url' => $plan['source_url'], 'source_sha256' => $plan['source_sha256'],
                    'parent_uuid' => $job['uuid'], 'partial' => true, 'results' => $plan['results']], JSON_THROW_ON_ERROR)]);
            $plan['phase'] = 'interrupted';
            $this->save($job, $plan);
            return ['stopped' => true];
        });
    }

    /**
     * EN: Resolves AO principal keys only through confirmed C2 relations and currently existing local principals.
     *
     * HR: Razrješava AO ključeve identiteta samo preko potvrđenih C2 veza i postojećih lokalnih identiteta.
     *
     * @param array<string,mixed> $job
     * @return array{users:array<string,int>,groups:array<string,int>}
     */
    private function maps(array $job, string $url): array
    {
        $catalog = $this->principals->cached((string)$job['archive_path'], (string)$job['summary']['source_sha256']);
        if ($catalog === null) {
            throw new ConfluenceImportException(__('Najprije pripremite pregled korisnika i grupa.'));
        }
        $confirmed = $this->repository->siteMappings('c2:' . hash('sha256', $url) . ':');
        $validUsers = array_fill_keys(array_column($this->auth->targetUsers(), 'id'), true);
        $validGroups = array_fill_keys(array_column($this->auth->targetGroups(), 'id'), true);
        $users = $groups = [];
        foreach ($catalog['users'] as $user) {
            $id = $confirmed['users'][ConfluenceSiteAccessService::mappingKey($url, 'users', (string)$user['source_id'])] ?? null;
            if ($id === null || !isset($validUsers[$id])) {
                continue;
            }
            foreach ([(string)$user['source_id'], ...($user['aliases'] ?? [])] as $key) {
                $users[$key] = (int)$id;
                $users[substr($key, strpos($key, ':') + 1)] = (int)$id;
            }
        }
        $named = [];
        foreach ($catalog['groups'] as $group) {
            $id = $confirmed['groups'][ConfluenceSiteAccessService::mappingKey($url, 'groups', (string)$group['source_id'])] ?? null;
            $named[$group['name']][] = $id !== null && isset($validGroups[$id]) ? (int)$id : null;
        }
        foreach ($named as $name => $ids) {
            $ids = array_values(array_unique($ids, SORT_REGULAR));
            if (count($ids) === 1 && $ids[0] !== null) {
                $groups[$name] = $ids[0];
            }
        }
        ksort($users);
        ksort($groups);
        return ['users' => $users, 'groups' => $groups];
    }

    /**
     * EN: Grants only mapped explicit principals; intersected VIEW/EDIT restrictions are not broadened.
     * Child-specific restrictions cannot be flattened safely and are reported for manual review.
     *
     * HR: Daje pristup samo mapiranim izričitim identitetima; presjek VIEW/EDIT ograničenja ne proširuje se.
     * Ograničenja pojedinih podkalendara ne mogu se sigurno objediniti i prijavljuju se za ručni pregled.
     *
     * @param array<string,mixed> $calendar
     * @param array{users:array<string,int>,groups:array<string,int>} $maps
     * @return array{rules:list<array<string,mixed>>,warnings:list<string>,owner_id:int|null}
     */
    private function access(array $calendar, array $maps): array
    {
        $rules = $warnings = $view = [];
        $creator = $maps['users'][$calendar['owner_key']] ?? null;
        if ($creator !== null) {
            $rules['user:' . $creator] = ['subject_type' => 'user', 'subject_id' => $creator, 'can_read' => true, 'can_write' => true];
        } else {
            $warnings[] = __('Izvorni kreator nije mapiran; njegova prava pristupa nisu prenesena.');
        }
        foreach ($calendar['acl'] as $entry) {
            if ($entry['type'] === 'VIEW') {
                $view[$entry['kind'] . ':' . $entry['key']] = true;
            }
        }
        $childAcl = false;
        foreach ($calendar['types'] as $type) {
            $childAcl = $childAcl || $type['acl'] !== [] && $type['source_id'] !== $calendar['source_id'];
        }
        if ($childAcl) {
            return ['rules' => [], 'warnings' => [__('Podkalendari imaju posebne ovlasti; objedinjeni kalendar ostaje dostupan samo upraviteljima kalendara.')], 'owner_id' => null];
        }
        foreach ($calendar['acl'] as $entry) {
            $id = $maps[$entry['kind'] === 'user' ? 'users' : 'groups'][$entry['key']] ?? null;
            if ($id === null || !in_array($entry['type'], ['VIEW', 'EDIT'], true)) {
                $warnings[] = __('Jedna izvorna ovlast nije mapirana ili podržana i nije dodijeljena.');
                continue;
            }
            if ($entry['type'] === 'EDIT' && $view !== [] && !isset($view[$entry['kind'] . ':' . $entry['key']])) {
                $warnings[] = __('Ovlast uređivanja s posebnim ograničenjem čitanja zahtijeva ručnu provjeru.');
                continue;
            }
            $key = $entry['kind'] . ':' . $id;
            $rule = $rules[$key] ?? ['subject_type' => $entry['kind'], 'subject_id' => $id, 'can_read' => true, 'can_write' => false];
            $rule['can_write'] = $rule['can_write'] || $entry['type'] === 'EDIT';
            $rules[$key] = $rule;
        }
        if ($view === []) {
            $warnings[] = __('Nedostaju izričita ograničenja čitanja. Javni pristup i pristup svim prijavljenima nisu automatski uključeni.');
        }
        return ['rules' => array_values($rules), 'warnings' => array_values(array_unique($warnings)), 'owner_id' => null];
    }

    /**
     * EN: Reusing a calendar never changes its type or personal owner, including between committed batches.
     * Old reviews without an explicit type must be reviewed again before they can write any data.
     *
     * HR: Ponovna uporaba ne mijenja vrstu ili osobnog vlasnika, ni između potvrđenih paketa.
     * Stari pregled bez izričite vrste mora se ponoviti prije bilo kakva upisa podataka.
     *
     * @param array<string,mixed>|null $target
     */
    private function assertTarget(?array $target, string $type, ?int $owner, bool $pending = false): void
    {
        if (!in_array($type, ['team', 'personal'], true) || ($type === 'personal' && $owner === null && !$pending)) {
            throw new ConfluenceImportException(__('Pregled kalendara je istekao ili nije valjan. Ponovite pregled.'));
        }
        if (
            $target !== null && (($target['calendar_type'] ?? '') !== $type
            || ($type === 'personal' && (int)($target['owner_user_id'] ?? 0) !== $owner))
        ) {
            throw new ConfluenceImportException(__('Postojeći kalendar ima drugu vrstu ili vlasnika. Uvoz ne mijenja njegovu vrstu ni vlasnika.'));
        }
    }

    /**
     * EN: Keeps future source-owner choices in the importer plan without writing Auth accounts during preview.
     *
     * HR: Čuva buduće izvorne vlasnike u planu importera bez upisivanja Auth računa tijekom pregleda.
     *
     * @param array<string,mixed> $job
     * @param list<string> $ownerRefs Additional explicit local owner selections for this review.
     * @return array{users:array<string,int|string>,aliases:array<string,string>,owners:array<string,array{label:string,target:int|string}>}
     */
    private function projection(array $job, array $ownerRefs = []): array
    {
        $catalog = $this->principals->cached((string)$job['archive_path'], (string)$job['summary']['source_sha256']) ?? [];
        $choices = $job['options']['site_access']['users'] ?? [];
        $ownerRefs = [...$ownerRefs, ...array_column($job['options']['site_calendars']['decisions'] ?? [], 'owner_ref'),
            ...array_values($job['options']['site_workflow_draft']['calendar_owners'] ?? [])];
        $localIds = [];
        foreach ($ownerRefs as $ref) {
            if (is_string($ref) && preg_match('/^local-user:([1-9][0-9]{0,9})$/D', $ref, $match) === 1) {
                $localIds[] = (int)$match[1];
            }
        }
        $ids = array_map(intval(...), array_values(array_filter($choices, static fn(mixed $value): bool => ctype_digit((string)$value))));
        $targets = $this->auth->targetUsers(array_values(array_unique([...$ids, ...$localIds])));
        $projection = ConfluenceSiteIdentityPlan::project($catalog, $choices, $targets);
        foreach ($targets as $target) {
            if (in_array((int)$target['id'], $localIds, true)) {
                $projection['owners']['local-user:' . $target['id']] = ['target' => (int)$target['id'],
                    'label' => $target['display_name'] . ' · ' . $target['login_identifier']];
            }
        }
        return $projection;
    }

    /**
     * EN: Checks both the owner and site/archive type without starting a generic space import.
     *
     * HR: Provjerava vlasnika i vrstu site/arhive bez pokretanja običnog uvoza područja.
     *
     * @return array<string,mixed>
     */
    private function job(string $uuid, int $actor, bool $allowInProgress = false): array
    {
        $job = $this->repository->jobByUuid($uuid, $actor);
        if (
            !in_array($job['status'], $allowInProgress ? ['ready', 'completed', 'running', 'failed'] : ['ready', 'completed'], true) || !($job['summary']['inventory_only'] ?? false)
            || $job['operation'] === 'site_space'
        ) {
            throw new ConfluenceImportException(__('Za kalendare odaberite pripremljeni ili dovršeni pregled sitea.'));
        }
        return $job;
    }

    /**
     * EN: Reads a previously prepared source-bound catalog.
     *
     * HR: Čita prethodno pripremljeni pregled vezan uz izvor.
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private function catalog(array $job): array
    {
        return $this->index->cached((string)$job['archive_path'], (string)$job['summary']['source_sha256'])
            ?? throw new ConfluenceImportException(__('Najprije pripremite pregled kalendara.'));
    }

    /**
     * EN: Converts one calendar at a time; no full-site event array is ever allocated.
     *
     * HR: Pretvara jedan kalendar odjednom; nikada ne stvara popis događaja cijelog sitea u memoriji.
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $calendar
     * @return array<string,mixed>
     */
    private function converted(array $job, array $calendar): array
    {
        if ($calendar['events_bytes'] > 67108864) {
            throw new ConfluenceImportException(__('Kalendar prelazi dopušteni limit za pretvaranje.'));
        }
        return $this->converter->convert(iterator_to_array($this->index->events((string)$job['archive_path'], $calendar), false), $calendar);
    }

    /**
     * EN: Optional module lookup does not make Calendar a required importer dependency.
     *
     * HR: Dohvat opcionalnog modula ne čini Calendar obaveznom ovisnošću importera.
     */
    private function manager(): object
    {
        if (!$this->container->has(self::MANAGER)) {
            throw new ConfluenceImportException(__('Calendar modul nije dostupan.'));
        }
        return $this->container->get(self::MANAGER);
    }

    /**
     * EN: Calls a verified optional public API without requiring Calendar in standalone installations.
     *
     * HR: Poziva provjereni opcionalni javni API bez obaveznog Calendara u samostalnim instalacijama.
     *
     * @param list<mixed> $arguments
     */
    private function call(string $method, #[\SensitiveParameter] array $arguments): mixed
    {
        $callable = [$this->manager(), $method];
        if (!is_callable($callable)) {
            throw new ConfluenceImportException(__('Calendar modul nema potrebnu funkcionalnost.'));
        }
        return $callable(...$arguments);
    }

    /**
     * EN: Full source hashing is needed before queued mutations, not on every progress request.
     *
     * HR: Potpuni hash izvora potreban je prije pokretanja izmjena, ne na svakom zahtjevu napretka.
     *
     * @param array<string,mixed> $job
     */
    private function verifySource(array $job): void
    {
        if (!hash_equals((string)$job['summary']['source_sha256'], (string)hash_file('sha256', (string)$job['archive_path']))) {
            throw new ConfluenceImportException(__('Izvornu arhivu nije moguće provjeriti.'));
        }
    }

    /**
     * EN: Rechecking confirmed mappings prevents stale grants after another C2 Apply or principal removal.
     *
     * HR: Ponovna provjera potvrđenih mapiranja sprječava zastarjele ovlasti nakon C2 primjene ili brisanja identiteta.
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $plan
     */
    private function verifyMaps(array $job, array $plan): void
    {
        if (!hash_equals($plan['identities_digest'], $this->digest($this->maps($job, $plan['source_url'])))) {
            throw new ConfluenceImportException(__('Mapiranja korisnika ili grupa su promijenjena. Ponovite pregled kalendara.'));
        }
        $ids = [];
        foreach ($plan['decisions'] as $decision) {
            if ($decision['calendar_type'] === 'personal' && str_starts_with($decision['owner_ref'], 'local-user:')) {
                $ids[] = (int)$decision['owner_id'];
            }
        }
        if (count(array_unique($ids)) !== count($this->auth->targetUsers(array_values(array_unique($ids))))) {
            throw new ConfluenceImportException(__('Odabrani vlasnik više nije dostupan. Ponovite pregled kalendara.'));
        }
    }

    /**
     * EN: C3 and C4 cannot mutate the same source job concurrently.
     *
     * HR: C3 i C4 ne smiju istodobno mijenjati isti izvorni posao.
     *
     * @param array<string,mixed> $job
     */
    private function assertIdle(array $job): void
    {
        if ($job['status'] === 'running' || ($job['options']['site_calendars']['phase'] ?? '') === 'running') {
            throw new ConfluenceImportException(__('Uvoz je već pokrenut. Najprije ga dovršite.'));
        }
    }

    /**
     * EN: Retains counts and warnings in a durable report, not transient ICS batches or review tokens.
     *
     * HR: Čuva brojanja i upozorenja u trajnom izvještaju, ne privremene ICS pakete ili tokene pregleda.
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $plan
     */
    private function save(array $job, array $plan): void
    {
        $options = $job['options'];
        $options['site_calendars'] = $plan;
        $summary = ($this->repository->jobByUuid((string)$job['uuid']))['summary'];
        $summary['site_calendar_report'] = ['phase' => $plan['phase'], 'source_url' => $plan['source_url'],
            'calendars_total' => count($plan['decisions']), 'calendars_done' => $plan['position'],
            'results' => $plan['results']];
        $this->repository->updateJob((int)$job['id'], ['options_json' => json_encode($options, JSON_THROW_ON_ERROR),
            'summary_json' => json_encode($summary, JSON_THROW_ON_ERROR), 'expires_at' => null]);
    }

    /**
     * EN: Reports committed calendar/batch positions, never guessed event progress.
     *
     * HR: Prijavljuje potvrđene pozicije kalendara/paketa, ne procijenjeni napredak događaja.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function progress(array $plan): array
    {
        $done = $total = 0;
        foreach (array_values($plan['decisions']) as $position => $decision) {
            $batches = max(1, (int)($decision['batches'] ?? 0));
            $total += $batches;
            $done += $position < $plan['position'] ? $batches
                : ($position === $plan['position'] ? min($batches, (int)$plan['batch']) : 0);
        }
        return ['completed' => $plan['phase'] === 'completed', 'calendars_done' => $plan['position'],
            'calendars_total' => count($plan['decisions']), 'batch' => $plan['batch'],
            'progress' => $plan['phase'] === 'completed' ? 100 : min(99, (int)floor(100 * $done / max(1, $total))),
            'batches_done' => $done, 'batches_total' => $total, 'report_uuid' => $plan['report_uuid'] ?? ''];
    }

    /**
     * EN: Fingerprints a reviewed JSON-compatible snapshot.
     *
     * HR: Stvara otisak pregledane snimke koja se može zapisati kao JSON.
     */
    private function digest(mixed $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }
}
