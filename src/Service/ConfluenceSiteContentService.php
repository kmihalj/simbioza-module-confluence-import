<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\SimbiozaModuleUser\Service\PersonalWorkspaceService;

/**
 * EN: Coordinates reviewed site imports through the existing single-space processing engine.
 * One owned parent retains the ZIP; each selected space has a separate resumable child/report.
 *
 * HR: Koordinira pregledani uvoz sitea kroz postojeći mehanizam obrade jednog područja.
 * Jedan roditeljski posao čuva ZIP; svako odabrano područje ima zaseban nastavivi podposao/izvještaj.
 */
final readonly class ConfluenceSiteContentService
{
    /**
     * EN: Receives importer-owned orchestration and existing Workspace APIs; Auth/framework stay unchanged.
     *
     * HR: Prima orkestraciju importa i postojeće Workspace API-je; Auth/framework ostaju nepromijenjeni.
     */
    public function __construct(
        private ConfluenceImportRepository $repository,
        private ConfluenceSiteContentIndex $index,
        private ConfluenceExportScanner $scanner,
        private ConfluenceSitePrincipals $principals,
        private ConfluenceImportService $imports,
        private ConfluenceImportConfig $config,
        private WorkspaceRepository $workspaces,
        private PersonalWorkspaceService $personalWorkspaces,
    ) {
    }

    /**
     * EN: Shows selected spaces, their existing targets and a previously saved review without writing content.
     *
     * HR: Prikazuje odabrana područja, postojeće ciljeve i spremljeni pregled bez zapisivanja sadržaja.
     *
     * @return array<string,mixed>
     */
    public function state(string $uuid, int $actor): array
    {
        $job = $this->job($uuid, $actor);
        $plan = $job['options']['site_content'] ?? [];
        $draft = $job['options']['site_workflow_draft']['content'] ?? null;
        if (
            is_array($draft) && in_array($job['options']['site_workflow']['phase'] ?? 'draft', ['draft', 'review'], true)
            && ($job['options']['site_workflow']['phase'] ?? '') !== 'review'
        ) {
            $plan = [...$draft, 'decisions' => $draft['spaces'] ?? []];
        }
        $spaces = $this->selected($job);
        $catalog = $this->principals->cached((string)$job['archive_path'], (string)$job['summary']['source_sha256']) ?? [];
        return ['job' => $job, 'plan' => $plan, 'spaces' => $spaces,
            'archival_sources' => ConfluenceSourceActivity::archivalSources($catalog),
            'personal_groups' => ConfluenceSiteIdentityPlan::personalGroups($spaces, $catalog, $job['options']['site_access']['users'] ?? []),
            'source_url' => $plan['source_url'] ?? $job['options']['site_access']['source_url'] ?? ''];
    }

    /**
     * EN: Freezes selected-space options and replace/copy targets behind an expiring confirmation token.
     * Personal owners must already have a confirmed C2 mapping; preview never creates identities.
     *
     * HR: Zaključava odabire područja i ciljeve zamjene/kopije iza potvrde s rokom važenja.
     * Vlasnici osobnih područja moraju već imati potvrđeno C2 mapiranje; pregled ne stvara identitete.
     *
     * @param array<string,mixed> $input
     * @param array<string,int|string>|null $plannedUsers Read-only projected source aliases, only during unified preflight.
     * @return array<string,mixed>
     */
    public function review(string $uuid, int $actor, array $input, ?array $plannedUsers = null): array
    {
        return $this->repository->withLockedJob($uuid, $actor, function () use ($uuid, $actor, $input, $plannedUsers): array {
            $job = $this->job($uuid, $actor);
            $this->repository->assertSiteSetupMutable($job);
            if ($job['status'] !== 'ready' || ($job['options']['site_calendars']['phase'] ?? '') === 'running') {
                throw new ConfluenceImportException(__('Pregled sitea nije spreman ili je odabir zastario.'));
            }
            $url = ConfluenceSiteAccessService::sourceUrl((string)($input['source_url'] ?? ''));
            $catalog = $this->principals->cached((string)$job['archive_path'], (string)$job['summary']['source_sha256']);
            if ($catalog === null) {
                throw new ConfluenceImportException(__('Najprije pripremite pregled identiteta sitea.'));
            }
            $groupMap = $this->groupMap($catalog, $url);
            $spaces = $this->selected($job);
            if ($spaces === []) {
                throw new ConfluenceImportException(__('Odaberite barem jedno područje za uvoz.'));
            }
            $decisions = [];
            $snapshots = [];
            $requested = $input['spaces'] ?? [];
            if (!is_array($requested)) {
                throw new ConfluenceImportException(__('Odabir područja nije valjan.'));
            }
            if (array_diff_key($requested, array_column($spaces, null, 'source_id')) !== []) {
                throw new ConfluenceImportException(__('Odabir područja nije valjan.'));
            }
            $archivalSources = ConfluenceSourceActivity::archivalSources($catalog);
            $this->repository->withSiteContext($url, $groupMap, function () use ($spaces, $requested, $plannedUsers, $archivalSources, &$decisions, &$snapshots): void {
                $targets = $this->targets(array_map(static fn(array $space): string => (string)$space['source_id'], $spaces));
                $personalOwners = [];
                $slugs = [];
                foreach ($spaces as $space) {
                    $id = (string)$space['source_id'];
                    $choice = $requested[$id] ?? [];
                    if (!is_array($choice)) {
                        throw new ConfluenceImportException(__('Odabir područja nije valjan.'));
                    }
                    $snapshot = $targets[$id] ?? null;
                    $strategy = (string)($choice['strategy'] ?? 'new');
                    $ownerMode = $choice['owner_mode'] ?? 'mapped';
                    if (!in_array($ownerMode, ['mapped', 'custody'], true)) {
                        throw new ConfluenceImportException(__('Odabir područja nije valjan.'));
                    }
                    $custody = $ownerMode === 'custody';
                    if (
                        $custody && (($space['type'] ?? '') !== 'personal'
                        || !isset($archivalSources[$space['owner_source_key'] ?? '']) || $strategy === 'append')
                    ) {
                        throw new ConfluenceImportException(__('Skrbništvo je dostupno samo za osobni sadržaj preskočenog neaktivnog vlasnika, bez dodavanja u osobno područje.'));
                    }
                    if (!in_array($strategy, ['new', 'copy', 'replace', 'append'], true) || ($snapshot !== null && $strategy === 'new')) {
                        throw new ConfluenceImportException(__('Confluence područje već je uvezeno. Odaberite zamjenu postojećeg područja ili uvoz nove kopije.'));
                    }
                    $appendTarget = null;
                    $appendOwner = null;
                    $owner = null;
                    $appendAfter = null;
                    if ($strategy === 'append' && ($space['type'] ?? '') !== 'personal') {
                        throw new ConfluenceImportException(__('Dodavanje bez zamjene dostupno je samo za osobno područje.'));
                    }
                    if (($space['type'] ?? '') === 'personal') {
                        $owner = $plannedUsers !== null ? ($plannedUsers[$space['owner_source_key']] ?? null)
                            : $this->repository->mappedUserId((string)$space['owner_source_key']);
                        if ($custody && ($owner !== null || ($snapshot !== null && $strategy !== 'copy'))) {
                            throw new ConfluenceImportException(__('Skrbništvo zahtijeva preskočenog neaktivnog vlasnika i novo zasebno područje. Postojeći sadržaj ostaje nepromijenjen.'));
                        }
                    }
                    if (($space['type'] ?? '') === 'personal' && !$custody) {
                        if ($owner === null) {
                            throw new ConfluenceImportException(__('Vlasnik osobnog Confluence područja mora biti potvrđeno mapiran.'));
                        }
                        if (isset($personalOwners[$owner])) {
                            $previous = $personalOwners[$owner];
                            if ($strategy !== 'append' || $decisions[$previous]['strategy'] !== 'append') {
                                throw new ConfluenceImportException(__('Za spajanje osobnih područja istog vlasnika za svako odaberite dodavanje samo novih stranica.'));
                            }
                            $appendAfter = $previous;
                        }
                        $personalOwners[$owner] = $id;
                        if ($strategy === 'copy') {
                            throw new ConfluenceImportException(__('Osobno Confluence područje nije moguće uvesti kao zasebnu kopiju.'));
                        }
                        $personal = is_int($owner) ? $this->personalWorkspaces->forUser($owner) : null;
                        if ($strategy === 'append') {
                            if (is_array($personal) && ($personal['is_deleted'] ?? false)) {
                                throw new ConfluenceImportException(__('Osobno područje je obrisano. Najprije ga vratite prije dodavanja stranica.'));
                            }
                            if (is_array($snapshot) && (!is_array($personal) || (int)$snapshot['workspace_id'] !== (int)$personal['workspace']['id'])) {
                                throw new ConfluenceImportException(__('Ciljno područje promijenio je drugi import. Ponovno pregledajte uvoz.'));
                            }
                            $appendOwner = $owner;
                            $appendTarget = is_array($personal) ? $personal['workspace'] : null;
                        } elseif (is_array($personal) && ($snapshot === null || (int)$personal['workspace']['id'] !== (int)$snapshot['workspace_id'])) {
                            throw new ConfluenceImportException(__('Cilj korisnika već ima osobno područje koje nije povezano s ovim izvorom. Uvoz nije dopušten.'));
                        }
                    }
                    $name = trim((string)($choice['name'] ?? $space['name']));
                    $slug = trim((string)($choice['slug'] ?? mb_strtolower((string)$space['source_key'])));
                    if ($name === '' || $slug === '' || strlen($name) > 190 || strlen($slug) > 190 || preg_match('/[\x00-\x1f\x7f]/', $name . $slug) === 1) {
                        throw new ConfluenceImportException(__('Naziv i slug ciljnog područja su obavezni.'));
                    }
                    if (isset($slugs[mb_strtolower($slug)])) {
                        throw new ConfluenceImportException(__('Odabrana područja moraju imati različite slugove.'));
                    }
                    $slugs[mb_strtolower($slug)] = true;
                    $decisions[$id] = ['space_id' => $id, 'source_key' => $space['source_key'], 'name' => $name, 'slug' => $slug,
                        'owner_mode' => $ownerMode, 'custody_source' => $custody ? $archivalSources[$space['owner_source_key']] : null,
                        'strategy' => $strategy, 'target' => $snapshot, 'append_owner' => $appendOwner, 'append_target' => $appendTarget,
                        'append_after' => $appendAfter];
                    $snapshots[$id] = ['mapping' => $snapshot, 'append_target' => $appendTarget];
                }
            });
            $language = (string)($input['language'] ?? $this->config->defaultLanguage());
            if (preg_match('/^[a-z]{2}(?:-[A-Za-z]{2})?$/D', $language) !== 1) {
                throw new ConfluenceImportException(__('Odabir područja nije valjan.'));
            }
            $flags = [];
            foreach (['include_attachments', 'include_comments', 'include_history', 'include_drafts', 'include_deleted'] as $flag) {
                if (isset($input[$flag]) && !is_bool($input[$flag])) {
                    throw new ConfluenceImportException(__('Odabir područja nije valjan.'));
                }
                $flags[$flag] = $input[$flag] ?? in_array($flag, ['include_attachments', 'include_comments'], true);
            }
            $plan = ['source_url' => $url, 'language' => $language, ...$flags, 'decisions' => $decisions, 'group_map' => $groupMap,
                'projected' => $plannedUsers !== null,
                'token' => bin2hex(random_bytes(32)), 'reviewed_at' => time(), 'source_sha256' => $job['summary']['source_sha256'],
                'selection' => array_keys($decisions), 'snapshots_sha256' => $this->digest($snapshots), 'phase' => 'review', 'children' => []];
            $plan['identities_sha256'] = $this->digest($this->repository->siteMappings('c2:' . hash('sha256', $url) . ':'));
            $options = $job['options'];
            $options['site_content'] = $plan;
            $this->save($job, $options);
            return ['reviewed' => true];
        });
    }

    /**
     * EN: Confirms only the saved review; no browser-supplied path or identity map is trusted.
     *
     * HR: Potvrđuje samo spremljeni pregled; putanji ni mapiranju iz preglednika ne vjeruje se.
     *
     * @return array<string,mixed>
     */
    public function queue(string $uuid, int $actor, string $token): array
    {
        return $this->repository->withLockedJob($uuid, $actor, function () use ($uuid, $actor, $token): array {
            $job = $this->job($uuid, $actor);
            $this->repository->assertSiteSetupMutable($job);
            $plan = $job['options']['site_content'] ?? [];
            if ($plan['projected'] ?? false) {
                throw new ConfluenceImportException(__('Najprije potvrdite zajednički pregled uvoza sitea.'));
            }
            if (($job['options']['site_calendars']['phase'] ?? '') === 'running') {
                throw new ConfluenceImportException(__('Uvoz kalendara je pokrenut. Najprije ga dovršite.'));
            }
            if ($job['status'] === 'running') {
                return $this->progress($job, $plan);
            }
            $snapshots = $this->repository->withSiteContext((string)($plan['source_url'] ?? ''), $plan['group_map'] ?? [], function () use ($plan): array {
                $targets = $this->targets(array_map(strval(...), array_keys($plan['decisions'] ?? [])));
                $result = [];
                foreach ($plan['decisions'] ?? [] as $id => $decision) {
                    $personal = ($decision['strategy'] ?? '') === 'append' && is_int($decision['append_owner'] ?? null)
                        ? $this->personalWorkspaces->forUser($decision['append_owner']) : null;
                    $result[$id] = ['mapping' => $targets[$id] ?? null, 'append_target' => $personal['workspace'] ?? null];
                }
                return $result;
            });
            $selected = array_map(static fn(array $space): string => (string)$space['source_id'], $this->selected($job));
            if (
                $job['status'] !== 'ready' || $token === '' || !hash_equals((string)($plan['token'] ?? ''), $token)
                || (int)($plan['reviewed_at'] ?? 0) < time() - 1800
                || ($plan['source_sha256'] ?? '') !== $job['summary']['source_sha256']
                || !is_file((string)$job['archive_path']) || !hash_equals((string)$plan['source_sha256'], (string)hash_file('sha256', (string)$job['archive_path']))
                || ($plan['identities_sha256'] ?? '') !== $this->digest($this->repository->siteMappings('c2:' . hash('sha256', (string)$plan['source_url']) . ':'))
                || array_map(strval(...), $plan['selection'] ?? []) !== $selected
                || ($plan['snapshots_sha256'] ?? '') !== $this->digest($snapshots)
            ) {
                throw new ConfluenceImportException(__('Pregled uvoza područja je zastario. Ponovno pregledajte odabir.'));
            }
            $options = $job['options'];
            $options['site_content']['phase'] = 'index';
            $this->repository->updateJob((int)$job['id'], ['operation' => 'site_import', 'status' => 'running', 'stage' => 'site_index',
                'expires_at' => null, 'options_json' => json_encode($options, JSON_THROW_ON_ERROR), 'error_message' => null]);
            return $this->progress($job, $options['site_content']);
        });
    }

    /**
     * EN: Runs one parent/child step under a nonblocking parent lock; a lost browser can resume.
     * Failed children stop the parent and retain the shared ZIP and all completed reports.
     *
     * HR: Izvodi korak roditelja/podposla uz nezadržavajuću roditeljsku bravu; preglednik može nastaviti.
     * Neuspjeli podposao zaustavlja roditelja i čuva zajednički ZIP i sve dovršene izvještaje.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function process(string $uuid, array $actor): array
    {
        $job = $this->job($uuid, (int)$actor['id']);
        $this->repository->assertSiteSetupMutable($job);
        $plan = $job['options']['site_content'] ?? [];
        if ($job['status'] === 'completed') {
            return ['completed' => true, 'phase' => 'completed', 'progress' => 100, 'summary' => $job['summary']];
        }
        if ($job['status'] !== 'running') {
            throw new ConfluenceImportException(__('Confluence import nije u tijeku.'));
        }
        $lockPath = $this->config->uploadDirectory() . '/' . $uuid . '.site.lock';
        if (is_link($lockPath)) {
            throw new ConfluenceImportException(__('Confluence import nije moguće zaključati.'));
        }
        $lock = fopen($lockPath, 'c+b');
        if (!is_resource($lock)) {
            throw new ConfluenceImportException(__('Confluence import nije moguće zaključati.'));
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return [...$this->progress($job, $plan), 'busy' => true];
        }
        try {
            $job = $this->job($uuid, (int)$actor['id']);
            $plan = $job['options']['site_content'];
            if ($plan['phase'] === 'index') {
                $this->index->prepare((string)$job['archive_path'], (string)$plan['source_sha256'], array_map(strval(...), array_keys($plan['decisions'])), $plan);
                $aliases = [];
                foreach ($plan['decisions'] as $id => $decision) {
                    $child = $this->child($job, $plan, $decision, (int)$actor['id']);
                    $plan['children'][$id] = $child['uuid'];
                    $aliases[(string)$decision['source_key']] = $decision['source_key'] . ($decision['strategy'] === 'copy' ? '#copy-' . $child['id'] : '');
                }
                foreach ($plan['children'] as $childUuid) {
                    $child = $this->repository->jobByUuid((string)$childUuid, (int)$actor['id']);
                    $childOptions = $child['options'];
                    $childOptions['_site_child']['space_aliases'] = $aliases;
                    $this->save($child, $childOptions);
                }
                $plan['space_aliases'] = $aliases;
                $plan['phase'] = 'spaces';
                $this->repository->updateJob((int)$job['id'], ['stage' => 'site_spaces']);
            } else {
                foreach ($plan['decisions'] as $id => $decision) {
                    $childUuid = $plan['children'][$id] ?? null;
                    if ($childUuid === null) {
                        $child = $this->child($job, $plan, $decision, (int)$actor['id']);
                        $plan['children'][$id] = $child['uuid'];
                        $options = $job['options'];
                        $options['site_content'] = $plan;
                        $this->save($job, $options);
                    } else {
                        $child = $this->repository->jobByUuid((string)$childUuid, (int)$actor['id']);
                    }
                    if ($child['status'] === 'completed') {
                        continue;
                    }
                    if ($child['status'] === 'failed') {
                        throw new ConfluenceImportException((string)$child['error_message']);
                    }
                    $next = $child['status'] === 'ready'
                        ? $this->imports->queue((string)$child['uuid'], $this->appendOptions($job, $plan, $decision, $child), $actor)
                        : $this->imports->process((string)$child['uuid'], $actor);
                    $plan['current'] = ['space_key' => $decision['source_key'], 'uuid' => $child['uuid'], ...$next];
                    break;
                }
            }
            $options = $job['options'];
            $options['site_content'] = $plan;
            $this->save($job, $options);
            $progress = $this->progress($job, $plan);
            if ($progress['spaces_done'] === count($plan['decisions']) && $plan['phase'] === 'spaces') {
                $summary = [...$job['summary'], 'site_content_report' => true, 'source_url' => $plan['source_url'], 'source_sha256' => $plan['source_sha256'],
                    'children' => $progress['children'], 'spaces_imported' => $progress['spaces_done']];
                $this->repository->updateJob((int)$job['id'], ['status' => 'completed', 'stage' => 'completed', 'summary_json' => json_encode($summary, JSON_THROW_ON_ERROR)]);
                // EN: C4 still needs the same archive for calendars; children never own or delete it.
                //
                // HR: C4 još treba istu arhivu za kalendare; podposlovi je nikada ne posjeduju ni brišu.
                return ['completed' => true, 'phase' => 'completed', 'progress' => 100, 'summary' => $summary];
            }
            return $progress;
        } catch (\Throwable $error) {
            $this->repository->failImport((int)$job['id'], $error->getMessage());
            throw $error;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * EN: A later additive source may reuse only the reviewed owner's completed earlier child.
     * The first source can create the personal target; concurrent/unrelated targets still fail closed.
     *
     * HR: Kasniji izvor s dodavanjem smije koristiti samo dovršeni raniji podposao pregledanog vlasnika.
     * Prvi izvor može stvoriti osobni cilj; istodobni/nepovezani ciljevi i dalje zaustavljaju uvoz.
     *
     * @param array<string,mixed> $parent
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $decision
     * @param array<string,mixed> $child
     * @return array<string,mixed>
     */
    private function appendOptions(array $parent, array $plan, array $decision, array $child): array
    {
        $options = $child['options'];
        $previousId = $decision['append_after'] ?? null;
        if ($previousId === null) {
            return $options;
        }
        $previous = $plan['decisions'][$previousId] ?? [];
        $previousUuid = $plan['children'][$previousId] ?? '';
        $prior = $previousUuid !== '' ? $this->repository->jobByUuid($previousUuid, (int)$parent['actor_user_id']) : [];
        $owner = $decision['append_owner'] ?? null;
        $personal = is_int($owner) ? $this->personalWorkspaces->forUser($owner) : null;
        $targetId = (int)($personal['workspace']['id'] ?? 0);
        if (
            $decision['strategy'] !== 'append' || ($previous['strategy'] ?? '') !== 'append'
            || $owner === null || ($previous['append_owner'] ?? null) !== $owner
            || ($prior['status'] ?? '') !== 'completed' || ($prior['options']['_site_child']['parent_uuid'] ?? '') !== $parent['uuid']
            || ($prior['options']['_site_child']['space_id'] ?? '') !== (string)$previousId
            || $targetId <= 0 || ($personal['is_deleted'] ?? false) || (int)($prior['workspace_id'] ?? 0) !== $targetId
            || (isset($decision['append_target']['id']) && (int)$decision['append_target']['id'] !== $targetId)
        ) {
            throw new ConfluenceImportException(__('Ciljno područje promijenio je drugi import. Ponovno pregledajte uvoz.'));
        }
        return $this->repository->withLockedJob((string)$child['uuid'], (int)$parent['actor_user_id'], function () use ($child, $targetId, $options): array {
            $current = $this->repository->jobByUuid((string)$child['uuid'], (int)$child['actor_user_id']);
            if ($current['status'] !== 'ready' || $current['options'] !== $child['options']) {
                throw new ConfluenceImportException(__('Ciljno područje promijenio je drugi import. Ponovno pregledajte uvoz.'));
            }
            // EN: The single-space engine trusts persisted child context, never caller-provided private fields.
            //
            // HR: Mehanizam jednog područja vjeruje spremljenom kontekstu, nikada privatnim poljima pozivatelja.
            $options['_site_child']['append_workspace_id'] = $targetId;
            $this->save($current, $options);
            return $options;
        });
    }

    /**
     * EN: Creates one owned child without copying the source ZIP or applying any identity proposals.
     *
     * HR: Stvara podposao istog vlasnika bez kopiranja ZIP-a ni primjene prijedloga identiteta.
     *
     * @param array<string,mixed> $parent
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $decision
     * @return array<string,mixed>
     */
    private function child(array $parent, array $plan, array $decision, int $actor): array
    {
        $path = (string)$parent['archive_path'];
        $id = (string)$decision['space_id'];
        $scan = $this->scanner->scanObjects($this->index->objects($path, $id), ['descriptor' => ['backupAttachments' => ($parent['summary']['source']['attachments'] ?? false) ? 'true' : 'false']]);
        $scan['warnings'] = array_values(array_unique([...$scan['warnings'], ...($parent['summary']['warnings'] ?? [])]));
        return $this->repository->withLockedJob((string)$parent['uuid'], $actor, function () use ($parent, $plan, $decision, $scan, $path, $id, $actor): array {
            $current = $this->repository->jobByUuid((string)$parent['uuid'], $actor);
            if (isset($current['options']['site_content']['children'][$id])) {
                return $this->repository->jobByUuid((string)$current['options']['site_content']['children'][$id], $actor);
            }
            $child = $this->repository->createUploadJob((string)$decision['source_key'], $path, 0, 0, $actor, time() + 3600);
            $this->repository->saveScan((int)$child['id'], $scan);
            $options = ['workspace_name' => $decision['name'], 'workspace_slug' => $decision['slug'],
                'source_base_url' => $plan['source_url'], 'language' => $plan['language'], 'reimport_strategy' => $decision['strategy'],
                '_site_child' => ['parent_uuid' => $parent['uuid'], 'space_id' => $id, 'source_url' => $plan['source_url'], 'group_map' => $plan['group_map'],
                    'custody_source' => $decision['custody_source'] ?? null,
                    'append_workspace_id' => $decision['append_target']['id'] ?? null]];
            foreach (['include_history', 'include_deleted', 'include_drafts', 'include_attachments', 'include_comments'] as $flag) {
                $options[$flag] = $plan[$flag];
            }
            if ((int)($parent['summary']['source']['attachment_files'] ?? 0) === 0) {
                $options['include_attachments'] = false;
            }
            $this->repository->updateJob((int)$child['id'], ['operation' => 'site_space', 'sha256' => $plan['source_sha256'], 'expires_at' => null, 'options_json' => json_encode($options, JSON_THROW_ON_ERROR)]);
            $parentOptions = $current['options'];
            $parentOptions['site_content']['children'][$id] = $child['uuid'];
            $this->save($current, $parentOptions);
            return $this->repository->jobByUuid((string)$child['uuid'], $actor);
        });
    }

    /**
     * EN: Explicit retry keeps completed children; only a failed child's own partial target is rebuilt.
     *
     * HR: Izričito ponavljanje čuva dovršene podposlove; ponovno izrađuje samo parcijalni cilj neuspjelog podposla.
     *
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function retry(string $uuid, array $actor): array
    {
        $job = $this->job($uuid, (int)$actor['id']);
        $this->repository->assertSiteSetupMutable($job);
        if ($job['status'] !== 'failed' || $job['operation'] !== 'site_import') {
            throw new ConfluenceImportException(__('Confluence import nije spreman za pokretanje.'));
        }
        $lockPath = $this->config->uploadDirectory() . '/' . $uuid . '.site.lock';
        $lock = !is_link($lockPath) ? fopen($lockPath, 'c+b') : false;
        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new ConfluenceImportException(__('Confluence import nije moguće zaključati.'));
        }
        try {
            $job = $this->job($uuid, (int)$actor['id']);
            if ($job['status'] !== 'failed' || $job['operation'] !== 'site_import') {
                throw new ConfluenceImportException(__('Confluence import nije spreman za pokretanje.'));
            }
            $plan = $job['options']['site_content'];
            $this->index->prepare((string)$job['archive_path'], (string)$plan['source_sha256'], array_map(strval(...), array_keys($plan['decisions'])), $plan);
            foreach ($job['options']['site_content']['children'] ?? [] as $childUuid) {
                $child = $this->repository->jobByUuid((string)$childUuid, (int)$actor['id']);
                if ($child['status'] === 'failed') {
                    $this->imports->retrySiteChild((string)$childUuid, $actor);
                }
            }
            $this->repository->updateJob((int)$job['id'], ['status' => 'running', 'stage' => 'site_spaces', 'error_message' => null]);
            return $this->progress($job, $job['options']['site_content']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * EN: Counts durable child jobs, so progress survives request loss and process restarts.
     *
     * HR: Broji trajne podposlove pa napredak preživljava gubitak zahtjeva i ponovno pokretanje procesa.
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function progress(array $job, array $plan): array
    {
        $children = [];
        $done = 0;
        foreach ($this->repository->jobsByUuids(array_values($plan['children'] ?? []), (int)$job['actor_user_id']) as $child) {
            $done += $child['status'] === 'completed' ? 1 : 0;
            $children[] = ['uuid' => $child['uuid'], 'space_key' => $child['source_space_key'], 'status' => $child['status'], 'workspace_id' => $child['workspace_id']];
        }
        $total = count($plan['decisions'] ?? []);
        $current = $plan['current'] ?? [];
        return ['completed' => false, 'phase' => $plan['phase'] === 'index' ? 'site_index' : 'site_spaces',
            'progress' => min(99, 5 + (int)(94 * ($done + (($current['completed'] ?? false) ? 0 : (int)($current['progress'] ?? 0) / 100)) / max(1, $total))),
            'spaces_done' => $done, 'spaces_total' => $total, 'current' => $current, 'children' => $children];
    }

    /**
     * EN: Selects only IDs saved by the inventory owner, in stable catalog order.
     *
     * HR: Odabire samo ID-jeve koje je spremio vlasnik pregleda, u stabilnom redoslijedu.
     *
     * @param array<string,mixed> $job
     * @return list<array<string,mixed>>
     */
    private function selected(array $job): array
    {
        $ids = array_fill_keys($job['options']['inventory_selection']['space_ids'] ?? [], true);
        return array_values(array_filter($job['summary']['spaces'] ?? [], static fn(array $space): bool => isset($ids[$space['source_id']])));
    }

    /**
     * EN: Only fully confirmed, unambiguous group-name relations may grant content rights.
     *
     * HR: Ovlasti sadržaja smiju dati samo potpuno potvrđene, jednoznačne veze naziva grupa.
     *
     * @param array<string,mixed> $catalog
     * @return array<string,int>
     */
    private function groupMap(array $catalog, string $url): array
    {
        $maps = $this->repository->siteMappings('c2:' . hash('sha256', ConfluenceSiteAccessService::sourceUrl($url)) . ':');
        $names = [];
        foreach ($catalog['groups'] as $group) {
            $names[(string)$group['name']][] = $maps['groups'][ConfluenceSiteAccessService::mappingKey($url, 'groups', (string)$group['source_id'])] ?? null;
        }
        $result = [];
        foreach ($names as $name => $targets) {
            $unique = array_unique($targets, SORT_REGULAR);
            if (count($unique) === 1 && $unique[0] !== null) {
                $result[$name] = (int)$unique[0];
            }
        }
        return $result;
    }

    /**
     * EN: Fingerprints the mapped target, not just a browser-selected name.
     *
     * HR: Stvara otisak mapiranog cilja, ne samo naziva odabranog u pregledniku.
     *
     * @param list<string> $ids
     * @return array<string,array<string,mixed>>
     */
    private function targets(array $ids): array
    {
        $mappings = $this->repository->spacesBySourceIds($ids);
        $workspaces = [];
        $wanted = array_fill_keys(array_map(static fn(array $mapping): int => (int)$mapping['target_workspace_id'], $mappings), true);
        // EN: Reuse the public batch catalog available in the released Workspace module, never one query per target.
        //
        // HR: Koristi javni skupni katalog objavljenog Workspace modula, nikada zaseban upit po cilju.
        foreach ($wanted !== [] ? $this->workspaces->allWorkspaces() : [] as $workspace) {
            if (isset($wanted[(int)$workspace['id']])) {
                $workspaces[(int)$workspace['id']] = $workspace;
            }
        }
        $result = [];
        foreach ($mappings as $id => $mapping) {
            $workspace = $workspaces[(int)$mapping['target_workspace_id']] ?? null;
            $result[$id] = ['mapping_id' => $mapping['id'], 'workspace_id' => $mapping['target_workspace_id'], 'workspace' => $workspace];
        }
        return $result;
    }

    /**
     * EN: Enforces parent type, ownership and source availability independently of the UI.
     *
     * HR: Neovisno o sučelju provjerava vrstu roditelja, vlasništvo i dostupnost izvora.
     *
     * @return array<string,mixed>
     */
    private function job(string $uuid, int $actor): array
    {
        $job = $this->repository->jobByUuid($uuid, $actor);
        if (($job['summary']['inventory_only'] ?? false) !== true && $job['operation'] !== 'site_import') {
            throw new ConfluenceImportException(__('Odabir područja nije valjan.'));
        }
        return $job;
    }

    /**
     * EN: Stores only parent options; child content and reports have their own lifecycle.
     *
     * HR: Sprema samo opcije roditelja; sadržaj i izvještaji podposlova imaju zaseban životni ciklus.
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $options
     */
    private function save(array $job, array $options): void
    {
        $this->repository->updateJob((int)$job['id'], ['options_json' => json_encode($options, JSON_THROW_ON_ERROR)]);
    }

    /**
     * EN: Hashes an internal normalized review snapshot.
     *
     * HR: Stvara hash internog normaliziranog snimka pregleda.
     *
     * @param array<mixed> $value
     */
    private function digest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }
}
