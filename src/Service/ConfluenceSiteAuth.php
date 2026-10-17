<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\HeartPhrameModuleAuth\Event\AuthUserGroupsChanged;
use AaiEduHr\HeartPhrameModuleAuth\ModuleAuth;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthGroupService;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthUserAttributeService;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthUserService;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * EN: Import-specific integration with the existing Auth services and membership relation.
 * It changes no Auth code, provider configuration, credentials or unrelated memberships.
 *
 * HR: Integracija importa s postojećim Auth servisima i vezom članstava.
 * Ne mijenja Auth kod, konfiguraciju providera, vjerodajnice ni nepovezana članstva.
 */
final readonly class ConfluenceSiteAuth
{
    /**
     * EN: Receives existing Auth services and optional standard membership event dispatch.
     *
     * HR: Prima postojeće Auth servise i opcionalnu objavu standardnih događaja članstava.
     */
    public function __construct(
        private Database $database,
        private AuthUserService $users,
        private AuthGroupService $groups,
        private ?AuthUserAttributeService $attributes = null,
        private ?EventDispatcherInterface $events = null,
    ) {
    }

    /**
     * EN: Projects only non-secret target fields and batches dynamic attributes.
     * Password hashes, linked identities and provider tokens are not queried.
     *
     * HR: Dohvaća samo netajne ciljne podatke i skupno čita dinamičke atribute.
     * Hashovi lozinki, povezani identiteti i tokeni providera uopće se ne dohvaćaju.
     *
     * @return list<array<string,mixed>>
     * @param list<int>|null $ids Optional selected targets; null means the bounded reconciliation directory.
     */
    public function targetUsers(?array $ids = null): array
    {
        if ($ids === []) {
            return [];
        }
        $query = $this->database->table(ModuleAuth::TABLE_AUTH_USERS)
            ->select(['id', 'login_identifier', 'is_active', 'is_admin']);
        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }
        $rows = $query->orderBy('id', 'ASC')->limit(100001)->get();
        if (count($rows) > 100000) {
            throw new ConfluenceImportException(__('Ciljni imenik prelazi dopuštenu veličinu pregleda.'));
        }
        $result = [];
        foreach (array_chunk($rows, 200) as $chunk) {
            $ids = array_map(static fn(array $row): int => (int)$row['id'], $chunk);
            $attributes = $this->attributes?->valueListsForUserIds($ids) ?? [];
            foreach ($chunk as $row) {
                $id = (int)$row['id'];
                $fields = $attributes[$id] ?? [];
                $first = $fields['first_name'][0] ?? '';
                $last = $fields['last_name'][0] ?? '';
                $display = $fields['display_name'][0] ?? trim($first . ' ' . $last);
                $result[] = ['id' => $id, 'login_identifier' => (string)$row['login_identifier'],
                    'is_active' => (bool)$row['is_active'], 'is_admin' => (bool)$row['is_admin'],
                    'display_name' => $display !== '' ? $display : (string)$row['login_identifier'],
                    'first_name' => $first, 'last_name' => $last,
                    'email' => $fields['email'][0] ?? '', 'email_aliases' => $fields['email'] ?? []];
            }
        }
        return $result;
    }

    /**
     * EN: Reuses Auth's existing bounded non-secret search API, including inactive users.
     * Filtering and pagination run in the database, not over a fully loaded target directory.
     *
     * HR: Koristi postojeći ograničeni Auth API pretrage netajnih podataka, uključujući neaktivne korisnike.
     * Filtriranje i paginacija izvode se u bazi, ne nad cijelim učitanim ciljnim imenikom.
     *
     * @return array<string,mixed>
     */
    public function userPage(string $search, int $page, int $size = 25): array
    {
        $result = $this->users->userLookupPage($search, $page, $size);
        foreach ($result['items'] as &$item) {
            if ($item['label'] !== $item['login']) {
                $item['label'] .= ' · ' . $item['login'];
            }
        }
        unset($item);
        return $result;
    }

    /**
     * EN: Reads assignable ordinary groups without triggering Auth's GUI repair/seed path.
     *
     * HR: Čita dodjeljive obične grupe bez pokretanja Auth GUI popravka/početnih unosa.
     *
     * @return list<array<string,mixed>>
     */
    public function targetGroups(): array
    {
        $rows = $this->database->table(ModuleAuth::TABLE_AUTH_GROUPS)
            ->select(['id', 'group_key', 'group_name'])->where('is_enabled', '=', true)->where('is_system', '=', false)
            ->where('group_key', '!=', AuthGroupService::SYSTEM_GROUP_ADMINISTRATOR_KEY)->orderBy('id', 'ASC')->limit(100001)->get();
        if (count($rows) > 100000) {
            throw new ConfluenceImportException(__('Ciljni imenik prelazi dopuštenu veličinu pregleda.'));
        }
        return array_values($rows);
    }

    /**
     * EN: Creates a pending account through Auth without importing authentication data.
     *
     * HR: Kroz Auth stvara račun na čekanju bez uvoza podataka za autentikaciju.
     *
     * @param array<string,mixed> $source
     */
    public function createUser(array $source, string $login): int
    {
        $user = $this->users->createInactiveUserWithoutLogin(
            $login,
            (string)($source['display_name'] ?: $source['username'] ?: $login),
            (string)$source['first_name'],
            (string)$source['last_name'],
            (string)$source['email']
        );
        return (int)$user['id'];
    }

    /**
     * EN: Prevents automatic creation from colliding with disabled or protected system groups.
     *
     * HR: Sprečava da automatska izrada dođe u sukob s isključenim ili zaštićenim sistemskim grupama.
     *
     * @return list<string>
     */
    public function unavailableGroupNames(): array
    {
        $names = [];
        foreach ($this->database->table(ModuleAuth::TABLE_AUTH_GROUPS)->select(['group_name', 'is_enabled', 'is_system', 'group_key'])->get() as $group) {
            if (!(bool)$group['is_enabled'] || (bool)$group['is_system'] || $group['group_key'] === AuthGroupService::SYSTEM_GROUP_ADMINISTRATOR_KEY) {
                $names[] = (string)$group['group_name'];
            }
        }
        return $names;
    }

    /**
     * EN: Delegates ordinary group creation to the existing Auth API.
     *
     * HR: Stvaranje obične grupe prepušta postojećem Auth API-ju.
     */
    public function createGroup(string $name): int
    {
        return (int)$this->groups->createGroupForApi($name, true)['id'];
    }

    /**
     * EN: Adds only absent manual membership pairs inside the caller's transaction.
     * Replacement-style GUI operations are deliberately not used: automatic rows and exclusions survive.
     * Fresh read services avoid reusing an earlier request cache when calculating neutral Auth events.
     *
     * HR: Unutar transakcije pozivatelja dodaje samo nedostajuće parove ručnih članstava.
     * Ne koristi GUI radnje zamjene: automatski redovi i izuzeća ostaju sačuvani.
     * Novi čitači ne koriste raniji cache zahtjeva pri izračunu neutralnih Auth događaja.
     *
     * @param list<array{user_id:int,group_id:int}> $pairs
     * @return array{added:int,existing:int,excluded:int,events:list<AuthUserGroupsChanged>}
     */
    public function addMemberships(array $pairs): array
    {
        if (count($pairs) > 100000) {
            throw new ConfluenceImportException(__('Broj članstava prelazi dopušteni limit.'));
        }
        $allowedGroups = array_fill_keys(array_column($this->targetGroups(), 'id'), true);
        $unique = [];
        foreach ($pairs as $pair) {
            if ($pair['user_id'] <= 0 || !isset($allowedGroups[$pair['group_id']])) {
                throw new ConfluenceImportException(__('Ciljni korisnik ili grupa za članstvo nije valjana.'));
            }
            $unique[$pair['user_id'] . ':' . $pair['group_id']] = $pair;
        }
        $userIds = array_values(array_unique(array_column($unique, 'user_id')));
        $known = $existing = [];
        foreach (array_chunk($userIds, 200) as $chunk) {
            foreach ($this->database->table(ModuleAuth::TABLE_AUTH_USERS)->select(['id'])->whereIn('id', $chunk)->lockForUpdate()->get() as $row) {
                $known[(int)$row['id']] = true;
            }
            foreach (
                $this->database->table(ModuleAuth::TABLE_AUTH_USER_GROUPS)
                ->select(['user_id', 'group_id', 'source'])->whereIn('user_id', $chunk)->get() as $row
            ) {
                $existing[(int)$row['user_id'] . ':' . (int)$row['group_id']][(string)$row['source']] = true;
            }
        }
        if (count($known) !== count($userIds)) {
            throw new ConfluenceImportException(__('Ciljni korisnik za članstvo više ne postoji.'));
        }
        $before = [];
        $beforeReader = new AuthGroupService($this->database);
        foreach (array_chunk($userIds, 200) as $chunk) {
            $before += $beforeReader->groupsForUserIds($chunk);
        }
        $result = ['added' => 0, 'existing' => 0, 'excluded' => 0, 'events' => []];
        $changed = [];
        foreach ($unique as $key => $pair) {
            if (isset($existing[$key]['excluded'])) {
                ++$result['excluded'];
            } elseif (isset($existing[$key]['manual']) || isset($existing[$key]['automatic'])) {
                ++$result['existing'];
            } else {
                $now = date('Y-m-d H:i:s');
                $this->database->table(ModuleAuth::TABLE_AUTH_USER_GROUPS)->insert([
                    ...$pair, 'source' => 'manual', 'source_field_key' => null, 'source_provider' => null,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $changed[$pair['user_id']] = true;
                ++$result['added'];
            }
        }
        $after = [];
        $afterReader = new AuthGroupService($this->database);
        foreach (array_chunk(array_keys($changed), 200) as $chunk) {
            $after += $afterReader->groupsForUserIds($chunk);
        }
        foreach (array_keys($changed) as $id) {
            $previous = array_map(static fn(array $group): int => (int)$group['id'], $before[$id] ?? []);
            $current = array_map(static fn(array $group): int => (int)$group['id'], $after[$id] ?? []);
            sort($previous);
            sort($current);
            if ($previous !== $current) {
                $result['events'][] = new AuthUserGroupsChanged($id, $previous, $current);
            }
        }
        return $result;
    }

    /**
     * EN: Emits standard membership events only after the import transaction commits.
     * Optional listeners cannot undo a committed account import.
     *
     * HR: Standardne događaje članstava objavljuje tek nakon potvrde transakcije importa.
     * Opcionalni slušači ne mogu poništiti potvrđeni uvoz računa.
     *
     * @param list<AuthUserGroupsChanged> $events
     */
    public function emit(array $events): void
    {
        foreach ($events as $event) {
            try {
                $this->events?->dispatch($event);
            } catch (Throwable) {
                // EN: Match Auth's optional-listener boundary; business rows are already committed.
                //
                // HR: Ista granica opcionalnih slušača kao u Authu; poslovni redovi već su potvrđeni.
            }
        }
    }
}
