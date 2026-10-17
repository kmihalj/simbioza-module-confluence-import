<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;

/**
 * EN: Reviews and applies site identities independently of later Workspace content imports.
 * An owner-bound preview, source fingerprint and current target snapshot are mandatory.
 * Only the importer owns this orchestration; Auth and the framework remain unchanged.
 *
 * HR: Pregledava i primjenjuje identitete sitea odvojeno od kasnijeg uvoza sadržaja područja.
 * Obavezni su pregled vezan uz vlasnika, otisak izvora i aktualna snimka ciljnih identiteta.
 * Ovom orkestracijom upravlja samo importer; Auth i framework ostaju nepromijenjeni.
 */
final readonly class ConfluenceSiteAccessService
{
    private const REVIEW_VERSION = 5;
    /**
     * EN: Receives importer-owned storage, catalog and unchanged Auth integration.
     *
     * HR: Prima spremište, pregled i integraciju nepromijenjenog Autha u vlasništvu importera.
     */
    public function __construct(
        private ConfluenceImportRepository $repository,
        private ConfluenceSitePrincipals $principals,
        private ConfluenceSiteAuth $auth,
    ) {
    }

    /**
     * EN: Builds the bounded metadata catalog without changing identities or holding a database lock.
     *
     * HR: Stvara ograničeni pregled metapodataka bez izmjene identiteta i dugog zaključavanja baze.
     *
     * @return array<string,mixed>
     */
    public function prepare(string $uuid, int $actor): array
    {
        $job = $this->job($uuid, $actor);
        $this->repository->assertSiteSetupMutable($job);
        $catalog = $this->principals->scan((string)$job['archive_path'], (string)$job['summary']['source_sha256']);
        // EN: Cancellation during the long scan must not produce a usable stale preview.
        //
        // HR: Otkazivanje tijekom dugog čitanja ne smije proizvesti upotrebljiv zastarjeli pregled.
        $this->job($uuid, $actor);
        return ['users' => count($catalog['users']), 'groups' => count($catalog['groups'])];
    }

    /**
     * EN: Returns only private administration data; GET never scans or writes business rows.
     *
     * HR: Vraća samo privatne administratorske podatke; GET ne čita arhivu niti upisuje poslovne redove.
     *
     * @return array<string,mixed>
     */
    public function state(string $uuid, int $actor): array
    {
        $job = $this->job($uuid, $actor);
        $catalog = $this->principals->cached((string)$job['archive_path'], (string)$job['summary']['source_sha256']);
        $saved = $job['options']['site_access'] ?? [];
        if ($catalog === null) {
            return ['ready' => false];
        }
        $users = $this->auth->targetUsers();
        $groups = $this->auth->targetGroups();
        $source = (string)($saved['source_url'] ?? '');
        $manualUsers = $saved['manual_users'] ?? array_filter($saved['users'] ?? [], static fn(mixed $value): bool => $value !== '');
        $autoMap = (bool)($saved['auto_map_users'] ?? true);
        $createUnmapped = (bool)($saved['create_unmapped_users'] ?? false);
        $createArchival = (bool)($saved['create_archival_users'] ?? false);
        $manualGroups = $saved['manual_groups'] ?? array_filter($saved['groups'] ?? [], static fn(mixed $value): bool => $value !== '');
        $autoMapGroups = (bool)($saved['auto_map_groups'] ?? true);
        $createUnmappedGroups = (bool)($saved['create_unmapped_groups'] ?? false);
        $reviewError = '';
        try {
            $choices = $this->choices($catalog, $source, $manualUsers, $manualGroups, $users, $groups, $autoMap, $createUnmapped, $autoMapGroups, $createUnmappedGroups, $createArchival);
        } catch (ConfluenceImportException $error) {
            $choices = ['users' => $saved['users'] ?? [], 'groups' => $saved['groups'] ?? []];
            $reviewError = $error->getMessage();
        }
        return ['ready' => true, 'catalog' => $catalog, 'source_url' => $source,
            'choices' => $choices, 'target_users' => $users, 'target_groups' => $groups,
            'manual_users' => $manualUsers, 'auto_map_users' => $autoMap, 'create_unmapped_users' => $createUnmapped,
            'create_archival_users' => $createArchival,
            'manual_groups' => $manualGroups, 'auto_map_groups' => $autoMapGroups, 'create_unmapped_groups' => $createUnmappedGroups,
            'memberships' => (bool)($saved['memberships'] ?? false),
            'preview' => $reviewError === '' && ($saved['preview']['version'] ?? null) === self::REVIEW_VERSION ? $saved['preview'] : null,
            'review_error' => $reviewError, 'last_report_uuid' => $saved['last_report_uuid'] ?? ''];
    }

    /**
     * EN: Clears only unapplied choices; confirmed mappings and completed reports are immutable.
     *
     * HR: Briše samo neprimijenjene odabire; potvrđena mapiranja i dovršeni izvještaji ostaju.
     *
     * @return array<string,bool>
     */
    public function resetReview(string $uuid, int $actor): array
    {
        return $this->repository->withLockedJob($uuid, $actor, function () use ($uuid, $actor): array {
            $job = $this->job($uuid, $actor);
            $this->repository->assertSiteSetupMutable($job);
            $options = $job['options'] ?? [];
            unset(
                $options['site_access']['users'],
                $options['site_access']['groups'],
                $options['site_access']['manual_users'],
                $options['site_access']['manual_groups'],
                $options['site_access']['group_reasons'],
                $options['site_access']['group_conflicts'],
                $options['site_access']['user_reasons'],
                $options['site_access']['user_conflicts'],
                $options['site_access']['preview']
            );
            $options['site_access']['memberships'] = false;
            $options['site_access']['auto_map_users'] = false;
            $options['site_access']['create_unmapped_users'] = false;
            $options['site_access']['create_archival_users'] = false;
            $options['site_access']['auto_map_groups'] = false;
            $options['site_access']['create_unmapped_groups'] = false;
            $this->repository->updateJob((int)$job['id'], ['options_json' => $this->json($options)]);
            return ['reset' => true];
        });
    }

    /**
     * EN: The owner-only picker includes inactive targets without exposing credentials.
     *
     * HR: Izbornik dostupan samo vlasniku uključuje neaktivne ciljeve bez otkrivanja vjerodajnica.
     *
     * @return array<string,mixed>
     */
    public function userPage(string $uuid, int $actor, string $search, int $page): array
    {
        $job = $this->job($uuid, $actor);
        $search = mb_substr(trim($search), 0, 100);
        $page = max(1, $page);
        $result = $this->auth->userPage($search, $page, 20);
        $planned = [];
        foreach ($this->catalog($job)['users'] as $source) {
            $choice = $job['options']['site_access']['users'][$source['source_id']] ?? '';
            if ($choice !== '__create__' && !ctype_digit((string)$choice)) {
                continue;
            }
            $label = ($source['display_name'] ?: $source['username']) . ' · ' . $source['username'];
            if ($search === '' || str_contains(mb_strtolower($label . ' ' . $source['email']), mb_strtolower($search))) {
                $planned[] = ['id' => ConfluenceSiteIdentityPlan::SOURCE_PREFIX . $source['source_id'],
                    'label' => __('Isti korisnik kao izvorni račun') . ': ' . $label];
            }
        }
        return ['ok' => true, 'page' => $result['page'],
            'results' => [...array_map(static fn(array $user): array => ['id' => (int)$user['id'], 'label' => $user['label']], $result['items']),
                ...array_slice($planned, ($page - 1) * 5, 5)],
            'hasMore' => $result['hasMore'] || count($planned) > $page * 5];
    }

    /**
     * EN: Saves reviewed choices (including other pages of the picker) but creates no accounts.
     * Empty choices mean skip, never the acting administrator. Automatic choices stay unconfirmed until Apply.
     *
     * HR: Sprema pregledane odabire (i s drugih stranica izbornika), ali ne stvara račune.
     * Prazan odabir znači preskočiti, nikada administratora. Automatski odabiri nisu potvrđeni do Primjene.
     *
     * @param array<string,mixed> $userChoices
     * @param array<string,mixed> $groupChoices
     * @return array<string,mixed>
     */
    public function review(string $uuid, int $actor, string $sourceUrl, array $userChoices, array $groupChoices, bool $memberships, bool $autoMapUsers = false, bool $createUnmappedUsers = false, bool $autoMapGroups = false, bool $createUnmappedGroups = false, bool $createArchivalUsers = false): array
    {
        $sourceUrl = self::sourceUrl($sourceUrl);
        return $this->repository->withLockedJob($uuid, $actor, function () use ($uuid, $actor, $sourceUrl, $userChoices, $groupChoices, $memberships, $autoMapUsers, $createUnmappedUsers, $autoMapGroups, $createUnmappedGroups, $createArchivalUsers): array {
            if (($this->job($uuid, $actor)['options']['site_calendars']['phase'] ?? '') === 'running') {
                throw new ConfluenceImportException(__('Uvoz kalendara je pokrenut. Najprije ga dovršite.'));
            }
            $job = $this->job($uuid, $actor);
            $this->repository->assertSiteSetupMutable($job);
            $catalog = $this->catalog($job);
            $options = $job['options'] ?? [];
            $old = ($options['site_access']['source_url'] ?? '') === $sourceUrl ? $options['site_access'] : [];
            $users = $this->auth->targetUsers();
            $groups = $this->auth->targetGroups();
            $manualUsers = array_replace($old['manual_users'] ?? array_filter($old['users'] ?? [], static fn(mixed $value): bool => $value !== ''), $userChoices);
            $allowedUserIds = array_fill_keys(array_column($catalog['users'], 'source_id'), true);
            foreach ($manualUsers as $id => $choice) {
                if ($choice === '__auto__') {
                    if (!isset($allowedUserIds[$id])) {
                        throw new ConfluenceImportException(__('Odabir identiteta nije valjan.'));
                    }
                    unset($manualUsers[$id]);
                }
            }
            $manualGroups = array_replace($old['manual_groups'] ?? array_filter($old['groups'] ?? [], static fn(mixed $value): bool => $value !== ''), $groupChoices);
            $allowedGroupIds = array_fill_keys(array_column($catalog['groups'], 'source_id'), true);
            foreach ($manualGroups as $id => $choice) {
                if ($choice === '__auto__') {
                    if (!isset($allowedGroupIds[$id])) {
                        throw new ConfluenceImportException(__('Odabir identiteta nije valjan.'));
                    }
                    unset($manualGroups[$id]);
                }
            }
            $choices = $this->choices(
                $catalog,
                $sourceUrl,
                $manualUsers,
                $manualGroups,
                $users,
                $groups,
                $autoMapUsers,
                $createUnmappedUsers,
                $autoMapGroups,
                $createUnmappedGroups,
                $createArchivalUsers,
            );
            $plan = ['source_url' => $sourceUrl, ...$choices, 'memberships' => $memberships,
                'manual_users' => $manualUsers, 'auto_map_users' => $autoMapUsers, 'create_unmapped_users' => $createUnmappedUsers,
                'create_archival_users' => $createArchivalUsers,
                'manual_groups' => $manualGroups, 'auto_map_groups' => $autoMapGroups, 'create_unmapped_groups' => $createUnmappedGroups,
                'last_report_uuid' => $old['last_report_uuid'] ?? ''];
            $preview = ['version' => self::REVIEW_VERSION, 'token' => bin2hex(random_bytes(32)), 'created_at' => time(),
                'source_sha256' => $job['summary']['source_sha256'],
                'targets_sha256' => $this->digest([$users, $groups]),
                'summary' => $this->summary($catalog, $choices, $memberships),
                'decisions' => $this->decisionRows($catalog, $choices, $users, $groups)];
            $plan['preview'] = $preview;
            $options['site_access'] = $plan;
            unset($options['site_content']);
            $this->repository->updateJob((int)$job['id'], ['options_json' => $this->json($options)]);
            return $preview;
        });
    }

    /**
     * EN: Applies exactly one reviewed preview atomically; retries return its durable report.
     * Credentials, existing login policies and administrator flags are never copied or rewritten.
     *
     * HR: Atomski primjenjuje točno jedan pregled; ponovljeni zahtjev vraća njegov trajni izvještaj.
     * Vjerodajnice, postojeća pravila prijave i administratorske oznake ne kopiraju se niti mijenjaju.
     *
     * @return array<string,mixed>
     */
    public function apply(string $uuid, int $actor, string $token): array
    {
        $events = [];
        $result = $this->repository->withLockedJob($uuid, $actor, function () use ($uuid, $actor, $token, &$events): array {
            if (($this->job($uuid, $actor)['options']['site_calendars']['phase'] ?? '') === 'running') {
                throw new ConfluenceImportException(__('Uvoz kalendara je pokrenut. Najprije ga dovršite.'));
            }
            $job = $this->job($uuid, $actor);
            $this->repository->assertSiteSetupMutable($job);
            $options = $job['options'] ?? [];
            $plan = $options['site_access'] ?? [];
            if ($token !== '' && hash_equals((string)($plan['applied_token'] ?? ''), $token)) {
                return $this->repository->jobByUuid((string)$plan['last_report_uuid'], $actor);
            }
            $preview = $plan['preview'] ?? [];
            $catalog = $this->catalog($job);
            $users = $this->auth->targetUsers();
            $groups = $this->auth->targetGroups();
            if (
                ($preview['version'] ?? null) !== self::REVIEW_VERSION
                || $token === '' || !hash_equals((string)($preview['token'] ?? ''), $token)
                || (int)($preview['created_at'] ?? 0) < time() - 1800
                || ($preview['source_sha256'] ?? '') !== $job['summary']['source_sha256']
                || ($preview['targets_sha256'] ?? '') !== $this->digest([$users, $groups])
            ) {
                throw new ConfluenceImportException(__('Pregled identiteta je zastario. Ponovno pregledajte odabir.'));
            }
            $sourceUrl = self::sourceUrl((string)$plan['source_url']);
            $choices = $this->choices($catalog, $sourceUrl, $plan['users'], $plan['groups'], $users, $groups);
            $choices['user_reasons'] = $plan['user_reasons'] ?? $choices['user_reasons'];
            $choices['user_conflicts'] = $plan['user_conflicts'] ?? [];
            $choices['group_reasons'] = $plan['group_reasons'] ?? $choices['group_reasons'];
            $choices['group_conflicts'] = $plan['group_conflicts'] ?? [];
            $report = $this->repository->createUploadJob((string)$job['original_name'], '', 0, 0, $actor, time());
            $reportId = (int)$report['id'];
            $counts = ['users_created' => 0, 'users_mapped' => 0, 'groups_created' => 0, 'groups_mapped' => 0];
            $targets = ['users' => [], 'groups' => []];
            $resolvedUsers = ConfluenceSiteIdentityPlan::resolve($choices['users']);
            // EN: Create each chosen primary once before resolving aliases, regardless of catalog order.
            //
            // HR: Svaki odabrani glavni račun stvori jednom prije povezivanja, neovisno o redoslijedu izvora.
            foreach ($catalog['users'] as $source) {
                $id = (string)$source['source_id'];
                if (($choices['users'][$id] ?? '') === '__create__') {
                    $targets['users'][$id] = $this->auth->createUser($source, $this->login($source));
                }
            }
            $groupNames = $this->groupNameCounts($catalog['groups']);
            $decisions = $this->decisionRows($catalog, $choices, $users, $groups);
            foreach (['users', 'groups'] as $kind) {
                foreach ($catalog[$kind] as $source) {
                    $id = (string)$source['source_id'];
                    $choice = $choices[$kind][$id] ?? '';
                    if ($choice === '') {
                        continue;
                    }
                    $create = $choice === '__create__';
                    $root = $kind === 'users' ? $resolvedUsers[$id] : ['source_id' => $id, 'choice' => $choice];
                    $target = $kind === 'users'
                        ? ($root['choice'] === '__create__' ? $targets['users'][$root['source_id']] : (int)$root['choice'])
                        : ($create ? $this->auth->createGroup($this->groupName($source, $groupNames)) : (int)$choice);
                    $targets[$kind][$id] = $target;
                    ++$counts[$kind . ($create ? '_created' : '_mapped')];
                    $keys = [$id, ...($kind === 'users' ? ($source['aliases'] ?? []) : [])];
                    foreach ($keys as $key) {
                        $mappingKey = self::mappingKey($sourceUrl, $kind, (string)$key);
                        if ($kind === 'users') {
                            $this->repository->mapIdentity(['source_key' => $mappingKey, 'username' => $source['username'],
                                'display_name' => $source['display_name'], 'email' => $source['email']], $target, true, $reportId);
                        } else {
                            $this->repository->mapGroup($mappingKey, $target, true, $reportId);
                        }
                    }
                }
            }
            $pairs = [];
            $unresolved = $nested = 0;
            if ((bool)$plan['memberships']) {
                foreach ($catalog['memberships'] as $membership) {
                    if ($membership['child_group_id'] !== '') {
                        ++$nested;
                    } elseif (isset($targets['users'][$membership['user_id']], $targets['groups'][$membership['group_id']])) {
                        $pairs[] = ['user_id' => $targets['users'][$membership['user_id']], 'group_id' => $targets['groups'][$membership['group_id']]];
                    } else {
                        ++$unresolved;
                    }
                }
            }
            $membershipResult = $this->auth->addMemberships($pairs);
            $events = $membershipResult['events'];
            unset($membershipResult['events']);
            $summary = ['source_url' => $sourceUrl, 'source_sha256' => $job['summary']['source_sha256'], 'parent_uuid' => $job['uuid'],
                'users_auto_map' => (int)($preview['summary']['users_auto_map'] ?? 0),
                'users_auto_create' => (int)($preview['summary']['users_auto_create'] ?? 0),
                'users_conflict' => (int)($preview['summary']['users_conflict'] ?? 0),
                'groups_auto_map' => (int)($preview['summary']['groups_auto_map'] ?? 0),
                'groups_auto_create' => (int)($preview['summary']['groups_auto_create'] ?? 0),
                'groups_conflict' => (int)($preview['summary']['groups_conflict'] ?? 0),
                ...$counts, 'memberships' => $membershipResult, 'memberships_requested' => (bool)$plan['memberships'],
                'memberships_unresolved' => $unresolved, 'nested_groups_omitted' => $nested,
                'permission_records_retained' => count($catalog['permissions']), 'decisions' => $decisions];
            $this->repository->updateJob($reportId, ['operation' => 'site_access', 'status' => 'completed', 'stage' => 'completed',
                'sha256' => $job['summary']['source_sha256'], 'expires_at' => null, 'summary_json' => $this->json($summary)]);
            $options['site_access'] = [...$plan, 'users' => array_map(strval(...), $targets['users']),
                'manual_users' => array_replace($plan['manual_users'] ?? [], array_map(strval(...), $targets['users'])),
                'groups' => array_map(strval(...), $targets['groups']), 'applied_token' => $token, 'last_report_uuid' => $report['uuid']];
            $options['site_access']['manual_groups'] = array_replace($plan['manual_groups'] ?? [], array_map(strval(...), $targets['groups']));
            unset($options['site_access']['preview']);
            $parentSummary = $job['summary'];
            $parentSummary['site_reports']['access'][] = $report['uuid'];
            $this->repository->updateJob((int)$job['id'], ['options_json' => $this->json($options), 'summary_json' => $this->json($parentSummary)]);
            return $this->repository->jobByUuid((string)$report['uuid'], $actor);
        });
        $this->auth->emit($events);
        return $result;
    }

    /**
     * EN: Stable site namespaces isolate directories from other Confluence installations.
     * The URL is an identifier only: this service never fetches it.
     *
     * HR: Stabilni prostori imena sitea odvajaju imenike različitih Confluence instalacija.
     * URL je samo identifikator: ovaj ga servis nikada ne dohvaća.
     */
    public static function sourceUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (
            strlen($url) > 2048 || !is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f]/', $url) === 1
        ) {
            throw new ConfluenceImportException(__('Unesite glavnu HTTP(S) adresu izvornog Confluence sitea bez prijave, upita ili fragmenta.'));
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        return $scheme . '://' . strtolower($parts['host']) . (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80) ? '' : ':' . $port)
            . rtrim($parts['path'] ?? '', '/');
    }

    /**
     * EN: Builds a bounded stable mapping key from the source instance and original identity.
     *
     * HR: Stvara ograničeni stabilni ključ mapiranja iz izvorne instance i izvornog identiteta.
     */
    public static function mappingKey(string $sourceUrl, string $kind, string $sourceId): string
    {
        return self::prefix($sourceUrl) . ($kind === 'users' ? 'u:' : 'g:') . hash('sha256', $sourceId);
    }

    /**
     * EN: Separates confirmed site mappings from the unchanged legacy single-space namespace.
     *
     * HR: Odvaja potvrđena mapiranja sitea od nepromijenjenog prostora uvoza jednog područja.
     */
    private static function prefix(string $sourceUrl): string
    {
        return 'c2:' . hash('sha256', self::sourceUrl($sourceUrl)) . ':';
    }

    /**
     * EN: Requires a ready inventory owned by the authenticated administrator.
     *
     * HR: Zahtijeva spreman pregled u vlasništvu prijavljenog administratora.
     *
     * @return array<string,mixed>
     */
    private function job(string $uuid, int $actor): array
    {
        $job = $this->repository->jobByUuid($uuid, $actor);
        if (
            $actor <= 0 || ($job['status'] ?? '') !== 'ready' || ($job['summary']['inventory_only'] ?? false) !== true
            || !is_string($job['summary']['source_sha256'] ?? null)
        ) {
            throw new ConfluenceImportException(__('Pregled sitea nije spreman ili je odabir zastario.'));
        }
        return $job;
    }

    /**
     * EN: Reads a complete unchanged catalog and refuses implicit scanning during apply.
     *
     * HR: Čita potpun nepromijenjen pregled i odbija prešutno skeniranje tijekom primjene.
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private function catalog(array $job): array
    {
        $catalog = $this->principals->cached((string)$job['archive_path'], (string)$job['summary']['source_sha256']);
        if ($catalog === null) {
            throw new ConfluenceImportException(__('Najprije pripremite pregled identiteta sitea.'));
        }
        return $catalog;
    }

    /**
     * EN: Resolves explicit selections against current targets and immutable confirmed mappings.
     * Bulk target/mapping indexes avoid an N+1 directory lookup; conflicts require manual review.
     *
     * HR: Provjerava izričite odabire prema aktualnim ciljevima i nepromjenjivim potvrđenim mapiranjima.
     * Skupni indeksi izbjegavaju N+1 dohvat imenika; sukobi zahtijevaju ručni pregled.
     *
     * @param array<string,mixed> $catalog
     * @param array<string,mixed> $userChoices
     * @param array<string,mixed> $groupChoices
     * @param list<array<string,mixed>> $users
     * @param list<array<string,mixed>> $groups
     * @return array{users:array<string,string>,groups:array<string,string>,user_reasons:array<string,string>,user_conflicts:array<string,string>,group_reasons:array<string,string>,group_conflicts:array<string,string>}
     */
    private function choices(array $catalog, string $url, array $userChoices, array $groupChoices, array $users, array $groups, bool $autoMapUsers = false, bool $createUnmappedUsers = false, bool $autoMapGroups = false, bool $createUnmappedGroups = false, bool $createArchivalUsers = false): array
    {
        $maps = $url !== '' ? $this->repository->siteMappings(self::prefix($url)) : ['users' => [], 'groups' => []];
        $groupNames = $this->groupNameCounts($catalog['groups']);
        $suggestions = ConfluenceSiteUserPlanner::suggest($catalog['users'], $users, $autoMapUsers, $createUnmappedUsers, $createArchivalUsers);
        $unavailableGroupNames = $this->auth->unavailableGroupNames();
        $groupSuggestions = ConfluenceSiteGroupPlanner::suggest($catalog['groups'], $groups, $autoMapGroups, $createUnmappedGroups, $unavailableGroupNames);
        $result = ['users' => [], 'groups' => [], 'user_reasons' => [], 'user_conflicts' => [], 'group_reasons' => [], 'group_conflicts' => []];
        $confirmedUsers = [];
        $occupied = [];
        foreach ($users as $user) {
            foreach ([(string)$user['login_identifier'], (string)$user['email'], ...$user['email_aliases']] as $value) {
                if ($value !== '') {
                    $occupied['users'][mb_strtolower(trim($value))] = true;
                }
            }
        }
        foreach ($groups as $group) {
            $occupied['groups'][mb_strtolower(trim((string)$group['group_name']))] = true;
        }
        foreach ($unavailableGroupNames as $name) {
            $occupied['groups'][mb_strtolower(trim($name))] = true;
        }
        foreach (['users' => $userChoices, 'groups' => $groupChoices] as $kind => $requested) {
            $allowed = array_column($catalog[$kind], null, 'source_id');
            $targets = $this->byId($kind === 'users' ? $users : $groups);
            foreach ($requested as $id => $value) {
                if (!isset($allowed[$id]) || !is_scalar($value) || (!is_string($value) && !is_int($value))) {
                    throw new ConfluenceImportException(__('Odabir identiteta nije valjan.'));
                }
            }
            foreach ($allowed as $id => $source) {
                $confirmed = [];
                foreach ([$id, ...($kind === 'users' ? $source['aliases'] : [])] as $alias) {
                    $target = $url !== '' ? ($maps[$kind][self::mappingKey($url, $kind, (string)$alias)] ?? null) : null;
                    if ($target !== null) {
                        $confirmed[$target] = true;
                    }
                }
                if (count($confirmed) > 1) {
                    throw new ConfluenceImportException(__('Postojeća mapiranja izvornog identiteta nisu jednoznačna.'));
                }
                $previous = count($confirmed) === 1 ? (string)array_key_first($confirmed) : '';
                if ($kind === 'users' && $previous !== '') {
                    $confirmedUsers[$id] = $previous;
                }
                $automatic = $kind === 'users' ? $suggestions[$id] : $groupSuggestions[$id];
                $choice = (string)($requested[$id] ?? ($previous !== '' ? $previous : $automatic['choice']));
                $reasonKey = $kind === 'users' ? 'user_reasons' : 'group_reasons';
                $conflictKey = $kind === 'users' ? 'user_conflicts' : 'group_conflicts';
                $result[$reasonKey][$id] = $previous !== '' ? 'confirmed'
                    : (array_key_exists($id, $requested) ? 'manual' : $automatic['reason']);
                if ($previous === '' && !array_key_exists($id, $requested) && $automatic['conflict'] !== '') {
                    $result[$conflictKey][$id] = $automatic['conflict'];
                }
                $sourceChoice = $kind === 'users' && str_starts_with($choice, ConfluenceSiteIdentityPlan::SOURCE_PREFIX);
                if ($choice !== '' && $previous !== '' && $choice !== $previous && !$sourceChoice) {
                    throw new ConfluenceImportException(__('Potvrđeno mapiranje nije moguće preusmjeriti.'));
                }
                if ($choice === '__create__') {
                    $values = $kind === 'users' ? [$this->login($source), (string)$source['email']] : [$this->groupName($source, $groupNames)];
                    $normalizedValues = array_unique(array_map(static fn(string $value): string => mb_strtolower(trim($value)), $values));
                    foreach (array_filter($normalizedValues, static fn(string $value): bool => $value !== '') as $normalized) {
                        if (isset($occupied[$kind][$normalized])) {
                            throw new ConfluenceImportException(__('Odabrano novo ime ili email već postoji. Odaberite mapiranje ili najprije uredite ciljni račun/grupu.'));
                        }
                        $occupied[$kind][$normalized] = true;
                    }
                } elseif ($choice !== '' && !$sourceChoice && (!ctype_digit($choice) || !isset($targets[(int)$choice]))) {
                    throw new ConfluenceImportException(__('Odabrani ciljni korisnik ili grupa više nije dostupna.'));
                }
                $result[$kind][$id] = $choice;
            }
        }
        $resolved = ConfluenceSiteIdentityPlan::resolve($result['users']);
        foreach ($confirmedUsers as $id => $previous) {
            if (($resolved[$id]['choice'] ?? '') !== $previous && ($result['users'][$id] ?? '') !== '') {
                throw new ConfluenceImportException(__('Potvrđeno mapiranje nije moguće preusmjeriti.'));
            }
        }
        return $result;
    }

    /**
     * EN: Keeps a usable source login, otherwise requires explicit target mapping.
     *
     * HR: Zadržava upotrebljivu izvornu login oznaku, inače traži izričito mapiranje cilja.
     *
     * @param array<string,mixed> $source
     */
    private function login(array $source): string
    {
        $value = ConfluenceSiteUserPlanner::login($source);
        if (strlen($value) > 190 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new ConfluenceImportException(__('Izvorna login oznaka nije valjana. Mapirajte postojeći račun.'));
        }
        return $value;
    }

    /**
     * EN: Distinguishes same-name source directory groups before creating ordinary target groups.
     *
     * HR: Razlikuje istoimene grupe izvornih imenika prije stvaranja običnih ciljnih grupa.
     *
     * @param array<string,mixed> $source
     * @param array<string,int> $nameCounts
     */
    private function groupName(array $source, array $nameCounts): string
    {
        $name = trim((string)$source['name']);
        if (($nameCounts[mb_strtolower($name)] ?? 0) > 1) {
            $name .= ' [' . $source['directory_id'] . ']';
        }
        if ($name === '' || strlen($name) > 190 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            throw new ConfluenceImportException(__('Izvorno ime grupe nije valjano. Mapirajte postojeću grupu.'));
        }
        return $name;
    }

    /**
     * EN: Counts duplicate directory-group labels once, avoiding quadratic scans in large sites.
     *
     * HR: Jednom broji ponovljene oznake grupa imenika, bez kvadratnog obilaska velikih siteova.
     *
     * @param list<array<string,mixed>> $groups
     * @return array<string,int>
     */
    private function groupNameCounts(array $groups): array
    {
        return array_count_values(array_map(static fn(array $group): string => mb_strtolower(trim((string)$group['name'])), $groups));
    }

    /**
     * EN: Indexes an already-batched non-secret target directory by its local IDs.
     *
     * HR: Indeksira već skupno učitani netajni ciljni imenik prema lokalnim ID-jevima.
     *
     * @param list<array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function byId(array $rows): array
    {
        return array_column($rows, null, 'id');
    }

    /**
     * EN: Lists every decision across all picker pages before the administrator applies it.
     * Labels are portable audit snapshots, not foreign IDs without backup mappings.
     *
     * HR: Prije primjene navodi svaku odluku sa svih stranica izbornika.
     * Oznake su prenosive revizijske snimke, a ne strani ID-jevi bez mapiranja u backupu.
     *
     * @param array<string,mixed> $catalog
     * @param array<string,array<string,string>> $choices
     * @param list<array<string,mixed>> $users
     * @param list<array<string,mixed>> $groups
     * @return list<array<string,string>>
     */
    private function decisionRows(array $catalog, array $choices, array $users, array $groups): array
    {
        $targets = ['users' => $this->byId($users), 'groups' => $this->byId($groups)];
        $groupNames = $this->groupNameCounts($catalog['groups']);
        $sourceUsers = array_column($catalog['users'], null, 'source_id');
        $resolved = ConfluenceSiteIdentityPlan::resolve($choices['users']);
        $result = [];
        foreach (['users', 'groups'] as $kind) {
            foreach ($catalog[$kind] as $source) {
                $id = (string)$source['source_id'];
                $choice = $choices[$kind][$id] ?? '';
                if ($choice === '' && ($kind !== 'users' || !ConfluenceSourceActivity::archival($source))) {
                    continue;
                }
                $create = $choice === '__create__';
                $root = $kind === 'users' ? $resolved[$id] : ['source_id' => $id, 'choice' => $choice];
                $primary = $kind === 'users' ? $sourceUsers[$root['source_id']] : $source;
                $result[] = ['kind' => $kind, 'source_id' => $id,
                    'source_label' => (string)($source['username'] ?? $source['name'] ?? ''),
                    'directory_id' => (string)$source['directory_id'], 'action' => $choice === '' ? 'skipped' : ($create ? 'created' : 'mapped'),
                    'source_activity' => ConfluenceSourceActivity::status($source),
                    'account_active' => $source['account_active'] ?? null, 'directory_active' => $source['directory_active'] ?? null,
                    'reason' => $choices[$kind === 'users' ? 'user_reasons' : 'group_reasons'][$id] ?? 'manual',
                    'target_label' => $choice === '' ? '' : ($kind === 'users'
                        ? ($root['choice'] === '__create__' ? $this->login($primary) : (string)$targets[$kind][(int)$root['choice']]['login_identifier'])
                        : ($create ? $this->groupName($source, $groupNames) : (string)$targets[$kind][(int)$choice]['group_name']))];
            }
        }
        return $result;
    }

    /**
     * EN: Summarizes every choice and distinguishes unresolved/nested memberships before writes.
     *
     * HR: Prije upisa sažima sve odabire i razlikuje neriješena/ugnježđena članstva.
     *
     * @param array<string,mixed> $catalog
     * @param array<string,array<string,string>> $choices
     * @return array<string,int>
     */
    private function summary(array $catalog, array $choices, bool $memberships): array
    {
        $result = ['membership_candidates' => 0, 'membership_unresolved' => 0, 'nested_groups' => 0,
            'permission_records_retained' => count($catalog['permissions']),
            'users_auto_map' => count(array_filter($choices['user_reasons'] ?? [], static fn(string $reason): bool => in_array($reason, ['username', 'email', 'username_email'], true))),
            'users_auto_create' => count(array_filter($choices['user_reasons'] ?? [], static fn(string $reason): bool => $reason === 'auto_create')),
            'users_conflict' => count($choices['user_conflicts'] ?? []),
            'groups_auto_map' => count(array_filter($choices['group_reasons'] ?? [], static fn(string $reason): bool => $reason === 'group_name')),
            'groups_auto_create' => count(array_filter($choices['group_reasons'] ?? [], static fn(string $reason): bool => $reason === 'group_auto_create')),
            'groups_conflict' => count($choices['group_conflicts'] ?? [])];
        foreach (['users', 'groups'] as $kind) {
            $created = count(array_filter($choices[$kind], static fn(string $value): bool => $value === '__create__'));
            $mapped = count(array_filter($choices[$kind], static fn(string $value): bool => $value !== '' && $value !== '__create__'));
            $result[$kind . '_create'] = $created;
            $result[$kind . '_map'] = $mapped;
            $result[$kind . '_skip'] = count($choices[$kind]) - $created - $mapped;
        }
        if ($memberships) {
            foreach ($catalog['memberships'] as $row) {
                $key = $row['child_group_id'] !== '' ? 'nested_groups'
                    : (($choices['users'][$row['user_id']] ?? '') !== '' && ($choices['groups'][$row['group_id']] ?? '') !== '' ? 'membership_candidates' : 'membership_unresolved');
                ++$result[$key];
            }
        }
        return $result;
    }

    /**
     * EN: Fingerprints only the selected non-secret target projection, not credentials.
     *
     * HR: Stvara otisak samo odabrane netajne ciljne projekcije, ne vjerodajnica.
     *
     * @param array<mixed> $value
     */
    private function digest(array $value): string
    {
        return hash('sha256', $this->json($value));
    }

    /**
     * EN: Encodes deterministic portable JSON for reviews and durable reports.
     *
     * HR: Kodira deterministički prenosivi JSON za preglede i trajne izvještaje.
     *
     * @param array<mixed> $value
     */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
