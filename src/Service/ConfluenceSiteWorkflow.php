<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;

/**
 * EN: Executes one frozen site selection in dependency order, using the existing component services.
 * Preview writes only importer draft metadata. Durable component checkpoints make lost responses repeat-safe.
 *
 * HR: Izvršava jedan zaključani odabir sitea redoslijedom ovisnosti kroz postojeće komponentne servise.
 * Pregled upisuje samo nacrt importera. Trajne kontrolne točke sigurno podnose izgubljene odgovore.
 */
final readonly class ConfluenceSiteWorkflow
{
    /**
     * EN: All coordination belongs to the importer; Auth and framework APIs remain unchanged.
     *
     * HR: Sva koordinacija pripada importeru; Auth i framework API-ji ostaju nepromijenjeni.
     */
    public function __construct(
        private ConfluenceImportRepository $repository,
        private ConfluenceImportUploadService $uploads,
        private ConfluenceSiteAccessService $access,
        private ConfluenceSitePrincipals $principals,
        private ConfluenceSiteAuth $auth,
        private ConfluenceSiteContentService $content,
        private ConfluenceSiteCalendarService $calendars,
        private ConfluenceImportConfig $config,
    ) {
    }

    /**
     * EN: Reads only saved workflow metadata and never advances a worker on GET.
     *
     * HR: Čita samo spremljene metapodatke postupka i nikada ne pokreće obradu na GET-u.
     *
     * @return array<string,mixed>
     */
    public function state(string $uuid, int $actor): array
    {
        $job = $this->job($uuid, $actor);
        $flow = $job['options']['site_workflow'] ?? ['phase' => $job['summary']['site_workflow_report']['phase'] ?? 'draft',
            'input' => $job['options']['site_workflow_draft'] ?? []];
        return $flow + ['progress_state' => $this->progress($flow)];
    }

    /**
     * EN: Saves bounded configuration fields only, so pagination cannot discard unsent space/calendar choices.
     * No draft value is executable; the complete preflight validates all choices again before confirmation.
     *
     * HR: Sprema samo ograničena konfiguracijska polja da paginacija ne izgubi neposlane odabire područja/kalendara.
     * Nacrt nije izvršiv; potpuni pregled ponovno provjerava sve odabire prije potvrde.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function draft(string $uuid, int $actor, array $input): array
    {
        return $this->locked($uuid, $actor, fn(): array => $this->repository->withLockedJob($uuid, $actor, function () use ($uuid, $actor, $input): array {
            $job = $this->job($uuid, $actor);
            $this->repository->assertSiteSetupMutable($job);
            $draft = array_intersect_key($input, array_fill_keys(['content', 'calendar_ids', 'calendar_types', 'calendar_owners'], true));
            if (strlen(json_encode($draft, JSON_THROW_ON_ERROR)) > 1048576) {
                throw new ConfluenceImportException(__('Odabir zajedničkog uvoza nije valjan.'));
            }
            foreach ($draft as $value) {
                if (!is_array($value)) {
                    throw new ConfluenceImportException(__('Odabir zajedničkog uvoza nije valjan.'));
                }
            }
            $this->validateDraft($draft);
            if (isset($input['space_ids'])) {
                if (!is_array($input['space_ids'])) {
                    throw new ConfluenceImportException(__('Odabir područja nije valjan.'));
                }
                $this->uploads->saveInventorySelection($uuid, $actor, (string)$job['summary']['source_sha256'], $input['space_ids']);
            }
            if (isset($input['identities'])) {
                $this->reviewIdentities($uuid, $actor, ConfluenceSiteAccessService::sourceUrl((string)($input['source_url'] ?? '')), $input['identities']);
            }
            $job = $this->job($uuid, $actor);
            $options = $job['options'];
            $options['site_workflow_draft'] = $draft;
            unset($options['site_workflow']);
            $this->repository->updateJob((int)$job['id'], ['options_json' => json_encode($options, JSON_THROW_ON_ERROR)]);
            return ['saved' => true];
        }));
    }

    /**
     * EN: Reviews every selected component before the single confirmation, without creating business objects.
     *
     * HR: Pregledava sve odabrane komponente prije jedne potvrde, bez stvaranja poslovnih objekata.
     *
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function review(string $uuid, array $actor, array $input): array
    {
        return $this->locked($uuid, (int)$actor['id'], function () use ($uuid, $actor, $input): array {
            $this->repository->assertSiteSetupMutable($this->job($uuid, (int)$actor['id']));
            return $this->repository->withLockedJob($uuid, (int)$actor['id'], function () use ($uuid, $actor, $input): array {
                $review = $this->buildReview($uuid, $actor, $input);
                $flow = ['phase' => 'review', ...$review, 'token' => bin2hex(random_bytes(32)), 'expires_at' => time() + 1800];
                $this->save($uuid, (int)$actor['id'], $flow);
                return ['reviewed' => true];
            });
        });
    }

    /**
     * EN: Revalidates the entire draft and source before queuing any identity, calendar or content mutation.
     * Repeating the same confirmation never queues another run.
     *
     * HR: Ponovno provjerava cijeli nacrt i izvor prije bilo kakve izmjene identiteta, kalendara ili sadržaja.
     * Ponavljanje iste potvrde nikada ne pokreće drugo izvršavanje.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function start(string $uuid, array $actor, string $token): array
    {
        return $this->locked($uuid, (int)$actor['id'], fn(): array => $this->repository->withLockedJob($uuid, (int)$actor['id'], function () use ($uuid, $actor, $token): array {
            $job = $this->job($uuid, (int)$actor['id']);
            $flow = $job['options']['site_workflow'] ?? [];
            if ($token !== '' && hash_equals((string)($flow['confirmation'] ?? ''), hash('sha256', $token))) {
                return $this->progress($flow);
            }
            if (
                ($flow['phase'] ?? '') !== 'review' || $token === '' || !hash_equals((string)$flow['token'], $token)
                || (int)$flow['expires_at'] < time() || $job['status'] !== 'ready'
            ) {
                throw new ConfluenceImportException(__('Zajednički pregled je zastario. Ponovno pregledajte odabir.'));
            }
            if (!hash_equals((string)$job['summary']['source_sha256'], (string)hash_file('sha256', (string)$job['archive_path']))) {
                throw new ConfluenceImportException(__('Izvornu arhivu nije moguće provjeriti.'));
            }
            $review = $this->buildReview($uuid, $actor, $flow['input']);
            if (!hash_equals($flow['digest'], $review['digest'])) {
                throw new ConfluenceImportException(__('Zajednički pregled je zastario. Ponovno pregledajte odabir.'));
            }
            $current = $this->job($uuid, (int)$actor['id']);
            $flow = ['phase' => 'identities', ...$review, 'confirmation' => hash('sha256', $token),
                'identity_token' => $current['options']['site_access']['preview']['token'], 'steps' => [], 'links' => []];
            $this->save($uuid, (int)$actor['id'], $flow);
            $this->repository->updateJob((int)$job['id'], ['operation' => 'site_import', 'expires_at' => null]);
            return $this->progress($flow);
        }));
    }

    /**
     * EN: One request advances one durable component checkpoint, never a parallel independent import.
     * Failures retain exact completed reports and stop the unified run without repairing imported records.
     *
     * HR: Jedan zahtjev pomiče jednu trajnu kontrolnu točku, nikada usporedni neovisni import.
     * Greške čuvaju dovršene izvještaje i zaustavljaju zajednički posao bez popravljanja uvezenih zapisa.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function process(string $uuid, array $actor): array
    {
        return $this->locked($uuid, (int)$actor['id'], fn(): array => $this->repository->withWorkflowContext(function () use ($uuid, $actor): array {
            $job = $this->job($uuid, (int)$actor['id']);
            $flow = $job['options']['site_workflow'] ?? [];
            if (($flow['phase'] ?? '') === 'completed') {
                return $this->progress($flow);
            }
            if (in_array($flow['phase'] ?? 'draft', ['draft', 'review', 'failed'], true)) {
                throw new ConfluenceImportException(__('Zajednički uvoz nije pokrenut ili je zaustavljen zbog greške.'));
            }
            try {
                $input = $flow['input'];
                $identityDigest = hash('sha256', json_encode($this->repository->siteMappings('c2:' . hash('sha256', $input['source_url']) . ':'), JSON_THROW_ON_ERROR));
                if (isset($flow['identities_digest']) && !hash_equals($flow['identities_digest'], $identityDigest)) {
                    throw new ConfluenceImportException(__('Potvrđena mapiranja su promijenjena. Uvoz je zaustavljen.'));
                }
                if ($flow['phase'] === 'identities') {
                    $report = $this->access->apply($uuid, (int)$actor['id'], $flow['identity_token']);
                    $flow['steps']['identities'] = $report['uuid'];
                    $flow['identities_digest'] = hash('sha256', json_encode($this->repository->siteMappings('c2:' . hash('sha256', $input['source_url']) . ':'), JSON_THROW_ON_ERROR));
                    $flow['phase'] = $input['calendar_ids'] !== [] ? 'calendar_prepare' : 'content_prepare';
                } elseif ($flow['phase'] === 'calendar_prepare') {
                    return $this->repository->withLockedJob($uuid, (int)$actor['id'], function () use ($uuid, $actor, $input, $flow): array {
                        $this->calendars->review($uuid, $actor, $input['source_url'], $input['calendar_ids'], $input['calendar_types'], $input['calendar_owners']);
                        $plan = $this->calendars->state($uuid, (int)$actor['id'])['plan'];
                        $result = $this->calendars->start($uuid, $actor, $plan['token']);
                        $flow['steps']['calendars'] = $result['report_uuid'];
                        $flow['current'] = $result;
                        $flow['phase'] = 'calendars';
                        $this->save($uuid, (int)$actor['id'], $flow);
                        return $this->progress($flow);
                    });
                } elseif ($flow['phase'] === 'calendars') {
                    $flow['current'] = $this->calendars->process($uuid, $actor);
                    if ($flow['current']['completed']) {
                        $flow['phase'] = 'content_prepare';
                    }
                } elseif ($flow['phase'] === 'content_prepare') {
                    return $this->repository->withLockedJob($uuid, (int)$actor['id'], function () use ($uuid, $actor, $input, $flow): array {
                        if ($input['space_ids'] === []) {
                            $flow['phase'] = 'links';
                        } else {
                            $this->content->review($uuid, (int)$actor['id'], $input['content']);
                            $plan = $this->content->state($uuid, (int)$actor['id'])['plan'];
                            $flow['current'] = $this->content->queue($uuid, (int)$actor['id'], $plan['token']);
                            $flow['phase'] = 'content';
                        }
                        $this->save($uuid, (int)$actor['id'], $flow);
                        return $this->progress($flow);
                    });
                } elseif ($flow['phase'] === 'content') {
                    $flow['current'] = $this->content->process($uuid, $actor);
                    if ($flow['current']['completed']) {
                        $flow['phase'] = 'links';
                    }
                } elseif ($flow['phase'] === 'links') {
                    return $this->repository->withLockedJob($uuid, (int)$actor['id'], function () use ($uuid, $actor, $flow): array {
                        if ($flow['input']['space_ids'] !== [] || $flow['input']['calendar_ids'] !== []) {
                            $flow['links'] = $this->calendars->linkPageBatch($uuid, $actor, $flow['links']['cursor'] ?? []);
                            if (!$flow['links']['completed']) {
                                $this->save($uuid, (int)$actor['id'], $flow);
                                return $this->progress($flow);
                            }
                        }
                        $flow['phase'] = 'completed';
                        unset($flow['current']);
                        $this->save($uuid, (int)$actor['id'], $flow);
                        $job = $this->job($uuid, (int)$actor['id']);
                        $summary = $job['summary'];
                        $summary['source_url'] = $flow['input']['source_url'];
                        $summary['site_workflow_report'] = ['phase' => 'completed', 'steps' => $flow['steps']];
                        $this->repository->updateJob((int)$job['id'], ['status' => 'completed', 'stage' => 'completed',
                            'summary_json' => json_encode($summary, JSON_THROW_ON_ERROR)]);
                        return $this->progress($flow);
                    });
                } else {
                    throw new ConfluenceImportException(__('Spremljeni korak zajedničkog uvoza nije valjan.'));
                }
                $this->save($uuid, (int)$actor['id'], $flow);
                return $this->progress($flow);
            } catch (\Throwable $error) {
                $current = $this->job($uuid, (int)$actor['id']);
                if (($current['options']['site_calendars']['phase'] ?? '') === 'running') {
                    $this->calendars->stop($uuid, $actor);
                }
                $flow['failed_step'] = $flow['phase'];
                $flow['phase'] = 'failed';
                $this->save($uuid, (int)$actor['id'], $flow);
                $this->repository->failImport((int)$job['id'], $error->getMessage());
                throw $error;
            }
        }));
    }

    /**
     * EN: Reuses component preflights with projected identities; deferred IDs can never reach a component worker.
     *
     * HR: Koristi komponentne preglede s planiranim identitetima; odgođeni ID-jevi nikada ne dolaze do obrade.
     *
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function buildReview(string $uuid, array $actor, array $input): array
    {
        $job = $this->job($uuid, (int)$actor['id']);
        if ($job['status'] !== 'ready') {
            throw new ConfluenceImportException(__('Pregled sitea nije spreman ili je odabir zastario.'));
        }
        $url = ConfluenceSiteAccessService::sourceUrl((string)($input['source_url'] ?? ''));
        $identity = $input['identities'] ?? [];
        $this->validateDraft($input);
        $content = $input['content'] ?? [];
        $spaceIds = $input['space_ids'] ?? [];
        $calendarIds = $input['calendar_ids'] ?? [];
        if (
            !is_array($identity) || !is_array($content) || !is_array($spaceIds) || !array_is_list($spaceIds)
            || !is_array($calendarIds) || !array_is_list($calendarIds) || count($calendarIds) > 1000
        ) {
            throw new ConfluenceImportException(__('Odabir zajedničkog uvoza nije valjan.'));
        }
        foreach ($calendarIds as $id) {
            if (!is_string($id)) {
                throw new ConfluenceImportException(__('Odabir kalendara nije valjan.'));
            }
        }
        $this->uploads->saveInventorySelection($uuid, (int)$actor['id'], (string)$job['summary']['source_sha256'], $spaceIds);
        $identityPreview = $this->reviewIdentities($uuid, (int)$actor['id'], $url, $identity);
        $job = $this->job($uuid, (int)$actor['id']);
        $access = $job['options']['site_access'];
        $catalog = $this->principals->cached((string)$job['archive_path'], (string)$job['summary']['source_sha256']) ?? [];
        $projection = ConfluenceSiteIdentityPlan::project($catalog, $access['users'], $this->auth->targetUsers());
        $content['source_url'] = $url;
        $contentPlan = [];
        if ($spaceIds !== []) {
            $this->content->review($uuid, (int)$actor['id'], $content, $projection['users']);
            $contentPlan = $this->job($uuid, (int)$actor['id'])['options']['site_content'];
        }
        $calendarPlan = [];
        if ($calendarIds !== []) {
            $this->calendars->review($uuid, $actor, $url, $calendarIds, $input['calendar_types'] ?? [], $input['calendar_owners'] ?? [], true);
            $calendarPlan = $this->job($uuid, (int)$actor['id'])['options']['site_calendars'];
        }
        $normalized = ['source_url' => $url, 'space_ids' => $spaceIds, 'content' => $content,
            'calendar_ids' => $calendarIds, 'calendar_types' => $input['calendar_types'] ?? [], 'calendar_owners' => $input['calendar_owners'] ?? [],
            'identities' => [...$identity, 'users' => $access['manual_users'], 'groups' => $access['manual_groups']]];
        $snapshot = [$access['users'], $access['groups'], $access['memberships'], $identityPreview['targets_sha256'],
            $contentPlan['decisions'] ?? [], $calendarPlan['decisions'] ?? [], $normalized];
        return ['input' => $normalized, 'digest' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'counts' => ['identities' => $identityPreview['summary'], 'spaces' => count($spaceIds), 'calendars' => count($calendarIds),
                'events' => array_sum(array_column($calendarPlan['decisions'] ?? [], 'events')),
                'skipped_events' => array_sum(array_column($calendarPlan['decisions'] ?? [], 'skipped'))]];
    }

    /**
     * EN: Saves validated identity choices as draft metadata, including selections on other result pages.
     *
     * HR: Sprema provjerene odabire identiteta kao nacrt, uključujući odabire na drugim stranicama rezultata.
     *
     * @return array<string,mixed>
     */
    private function reviewIdentities(string $uuid, int $actor, string $url, mixed $identity): array
    {
        if (!is_array($identity)) {
            throw new ConfluenceImportException(__('Odabir identiteta nije valjan.'));
        }
        foreach (['users', 'groups'] as $field) {
            if (!is_array($identity[$field] ?? [])) {
                throw new ConfluenceImportException(__('Odabir identiteta nije valjan.'));
            }
        }
        foreach (['memberships', 'auto_map_users', 'create_unmapped_users', 'auto_map_groups', 'create_unmapped_groups', 'create_archival_users'] as $field) {
            if (isset($identity[$field]) && !is_bool($identity[$field])) {
                throw new ConfluenceImportException(__('Odabir identiteta nije valjan.'));
            }
        }
        return $this->access->review(
            $uuid,
            $actor,
            $url,
            $identity['users'] ?? [],
            $identity['groups'] ?? [],
            $identity['memberships'] ?? false,
            $identity['auto_map_users'] ?? false,
            $identity['create_unmapped_users'] ?? false,
            $identity['auto_map_groups'] ?? false,
            $identity['create_unmapped_groups'] ?? false,
            $identity['create_archival_users'] ?? false
        );
    }

    /**
     * EN: Rejects malformed nested draft values before they can reach PHP templates or component preflights.
     * Draft storage is not an execution shortcut and accepts no file paths, tokens or target objects.
     *
     * HR: Odbija neispravne ugniježđene nacrte prije PHP predložaka ili komponentnih pregleda.
     * Spremanje nacrta nije prečac za izvršavanje i ne prihvaća putanje, tokene ili ciljne objekte.
     *
     * @param array<string,mixed> $input
     */
    private function validateDraft(array $input): void
    {
        $valid = strlen(json_encode($input, JSON_THROW_ON_ERROR)) <= 1048576;
        foreach (['calendar_ids', 'space_ids'] as $field) {
            $value = $input[$field] ?? [];
            $valid = $valid && is_array($value) && array_is_list($value) && count($value) <= 10000;
            foreach (is_array($value) ? $value : [] as $id) {
                $valid = $valid && is_string($id) && strlen($id) <= 190;
            }
        }
        foreach (['calendar_types', 'calendar_owners'] as $field) {
            $value = $input[$field] ?? [];
            $valid = $valid && is_array($value) && count($value) <= 1000;
            foreach (is_array($value) ? $value : [] as $item) {
                $valid = $valid && is_string($item) && strlen($item) <= 190;
            }
        }
        $content = $input['content'] ?? [];
        $valid = $valid && is_array($content);
        if (is_array($content)) {
            foreach ($content as $key => $value) {
                if ($key === 'spaces') {
                    $valid = $valid && is_array($value);
                    foreach (is_array($value) ? $value : [] as $choice) {
                        $valid = $valid && is_array($choice);
                        foreach (is_array($choice) ? $choice : [] as $name => $text) {
                            $valid = $valid && in_array($name, ['name', 'slug', 'strategy', 'owner_mode'], true) && is_string($text) && strlen($text) <= 190;
                        }
                    }
                } elseif (in_array($key, ['language', 'source_url'], true)) {
                    $valid = $valid && is_string($value) && strlen($value) <= 2048;
                } elseif (in_array($key, ['include_attachments', 'include_comments', 'include_history', 'include_drafts', 'include_deleted'], true)) {
                    $valid = $valid && is_bool($value);
                } else {
                    $valid = false;
                }
            }
        }
        if (!$valid) {
            throw new ConfluenceImportException(__('Odabir zajedničkog uvoza nije valjan.'));
        }
    }

    /**
     * EN: Serializes all coordinator requests without holding a database transaction across archive processing.
     *
     * HR: Serijalizira sve zahtjeve koordinatora bez transakcije baze tijekom obrade arhive.
     *
     * @param callable():array<string,mixed> $callback
     * @return array<string,mixed>
     */
    private function locked(string $uuid, int $actor, callable $callback): array
    {
        $this->job($uuid, $actor);
        $path = $this->config->uploadDirectory() . '/' . $uuid . '.workflow.lock';
        $lock = !is_link($path) ? fopen($path, 'c+b') : false;
        if (!is_resource($lock)) {
            throw new ConfluenceImportException(__('Confluence import nije moguće zaključati.'));
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return [...$this->progress($this->state($uuid, $actor)), 'busy' => true];
            }
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * EN: Restricts orchestration to the authenticated owner's actual site inventory.
     *
     * HR: Ograničava koordinaciju na stvarni inventar sitea prijavljenog vlasnika.
     *
     * @return array<string,mixed>
     */
    private function job(string $uuid, int $actor): array
    {
        $job = $this->repository->jobByUuid($uuid, $actor);
        if (!($job['summary']['inventory_only'] ?? false) || $job['operation'] === 'site_space') {
            throw new ConfluenceImportException(__('Odabir zajedničkog uvoza nije valjan.'));
        }
        return $job;
    }

    /**
     * EN: Merges a checkpoint into the latest parent, preserving component reports and options.
     *
     * HR: Spaja kontrolnu točku s aktualnim roditeljem, uz očuvanje komponentnih izvještaja i odabira.
     *
     * @param array<string,mixed> $flow
     */
    private function save(string $uuid, int $actor, array $flow): void
    {
        $job = $this->job($uuid, $actor);
        $options = $job['options'];
        $options['site_workflow'] = $flow;
        $summary = $job['summary'];
        $summary['site_workflow_report'] = ['phase' => $flow['phase'], 'steps' => $flow['steps'] ?? [], 'failed_step' => $flow['failed_step'] ?? ''];
        $this->repository->updateJob((int)$job['id'], ['options_json' => json_encode($options, JSON_THROW_ON_ERROR),
            'summary_json' => json_encode($summary, JSON_THROW_ON_ERROR)]);
    }

    /**
     * EN: Returns saved progress only; completed means all linking steps, not merely imported spaces.
     *
     * HR: Vraća samo spremljeni napredak; dovršeno znači i povezivanje, ne samo uvezena područja.
     *
     * @param array<string,mixed> $flow
     * @return array<string,mixed>
     */
    private function progress(array $flow): array
    {
        $phase = $flow['phase'] ?? 'draft';
        $measuredPhase = $phase === 'failed' ? ($flow['failed_step'] ?? '') : $phase;
        $current = $flow['current'] ?? [];
        $percent = $phase === 'completed' ? 100
            : (in_array($measuredPhase, ['calendars', 'content'], true) && isset($current['progress'])
                ? max(0, min(100, (int)$current['progress'])) : null);
        return ['completed' => ($flow['phase'] ?? '') === 'completed', 'phase' => $flow['phase'] ?? 'draft',
            'progress' => $percent, 'current' => $current, 'steps' => $flow['steps'] ?? [], 'counts' => $flow['counts'] ?? []];
    }
}
