<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

/**
 * EN: Creates an inert review of potentially duplicate accounts and incomplete calendar choices.
 * Source metadata is evidence for a proposal, never authorization to merge accounts or assign access.
 *
 * HR: Izrađuje neizvršivi pregled mogućih duplih računa i nedovršenih odabira kalendara.
 * Izvorni metapodaci dokaz su za prijedlog, nikada ovlast za spajanje računa ili dodjelu pristupa.
 */
final class ConfluenceSiteMappingReview
{
    /**
     * EN: Uses prepared catalogs only; does not scan archives, write drafts or call another module.
     * Exact shared identifiers and full names form review groups, not automatic mappings.
     *
     * HR: Koristi samo pripremljene preglede; ne čita arhive, ne piše nacrte niti poziva drugi modul.
     * Točni zajednički identifikatori i puna imena čine grupe za pregled, ne automatska mapiranja.
     *
     * @param array<string,mixed> $access
     * @param array<string,mixed> $calendars
     * @return array<string,mixed>
     */
    public static function build(array $access, array $calendars): array
    {
        $sources = array_column($access['catalog']['users'] ?? [], null, 'source_id');
        $choices = $access['choices']['users'] ?? [];
        $groups = self::duplicates($sources, $choices);
        $logins = [];
        foreach ($sources as $source) {
            foreach ([$source['username'] ?? '', $source['email'] ?? ''] as $login) {
                if (trim((string)$login) !== '') {
                    $logins[mb_strtolower(trim((string)$login))][$source['source_id']] = $source;
                }
            }
        }
        $rows = [];
        foreach ($calendars['catalog']['calendars'] ?? [] as $id => $calendar) {
            $decision = $calendars['plan']['decisions'][$id] ?? [];
            $type = $decision['calendar_type'] ?? ConfluenceCalendarPresentation::suggestedType($calendar);
            if ($type !== '' && ($type !== 'personal' || ($decision['owner_ref'] ?? '') !== '')) {
                continue;
            }
            $suggestion = $calendars['owner_suggestions'][$id] ?? ['candidates' => [], 'status' => 'none'];
            $suggestion['candidates'] = self::candidates($calendar, $suggestion['candidates'], $logins, $calendars['owner_choices'] ?? []);
            [$proposed, $reason] = self::calendarType($calendar, $suggestion);
            $rows[] = ['id' => (string)$id, 'name' => (string)$calendar['name'], 'current_type' => $decision['calendar_type'] ?? '',
                'proposed_type' => $proposed, 'type_reason' => $reason, 'owner_status' => $suggestion['status'],
                'candidates' => $proposed === 'team' ? [] : $suggestion['candidates'],
                'space_keys' => $calendar['space_keys'] ?? [], 'events' => (int)($calendar['events'] ?? 0)];
        }
        return ['duplicates' => $groups, 'calendars' => $rows,
            'conflicts' => count($access['choices']['user_conflicts'] ?? []),
            'types' => array_count_values(array_column($rows, 'proposed_type'))];
    }

    /**
     * EN: Shared-resource names outrank embedding hints; a personal page is not ownership evidence.
     * General-space association proposes team with low confidence; unknown personal names stay unresolved.
     *
     * HR: Nazivi zajedničkih resursa imaju prednost nad tragom ugradnje; osobna stranica ne dokazuje vlasništvo.
     * Veza s općim područjem slabo predlaže timski; nepoznati osobni nazivi ostaju neriješeni.
     *
     * @param array<string,mixed> $calendar
     * @param array<string,mixed> $suggestion
     * @return array{string,string}
     */
    private static function calendarType(array $calendar, array $suggestion): array
    {
        $name = self::nameKey((string)$calendar['name']);
        if (preg_match('/\b(?:dvoran\p{L}*|soba|sobu|room|vozil\p{L}*|vehicle|rezervacij\p{L}*|reservation|sektorsk\p{L}*|timski|team)\b/u', $name) === 1) {
            return ['team', 'shared_resource'];
        }
        foreach ($suggestion['candidates'] as $candidate) {
            if (in_array($candidate['reason'], ['name', 'login', 'name_space'], true)) {
                return ['personal', 'person_name'];
            }
        }
        if (preg_match('/\b(?:sluzben\p{L}*|privatn\p{L}*|osobn\p{L}*|official|private|personal)\b/u', $name) === 1) {
            return ['personal', 'personal_name'];
        }
        $personal = array_filter($calendar['space_keys'] ?? [], static fn(string $key): bool => str_starts_with($key, '~'));
        if ($personal !== [] && count($personal) === count($calendar['space_keys'] ?? [])) {
            return ['personal', 'personal_context'];
        }
        return $personal === [] && ($calendar['space_keys'] ?? []) !== [] ? ['team', 'general_context'] : ['', 'unknown'];
    }

    /**
     * EN: A matching given name plus a linked personal-space login is only a review hint for changed surnames.
     * Suppress page-owner hints contradicting an explicit personal calendar name; never use the creator.
     *
     * HR: Podudarno ime i login povezanog osobnog područja samo su trag pregleda kod promijenjenog prezimena.
     * Izostavi trag vlasnika stranice koji proturječi izričitom osobnom nazivu; nikad ne koristi kreatora.
     *
     * @param array<string,mixed> $calendar
     * @param list<array<string,mixed>> $candidates
     * @param array<string,array<string,array<string,mixed>>> $logins
     * @param array<string,array<string,mixed>> $owners
     * @return list<array<string,mixed>>
     */
    private static function candidates(array $calendar, array $candidates, array $logins, array $owners): array
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate['reason'], ['name', 'login'], true)) {
                return $candidates;
            }
        }
        $name = self::nameKey((string)$calendar['name']);
        $explicitPersonal = preg_match('/\b(?:sluzben\p{L}*|privatn\p{L}*|official|private)\b/u', $name) === 1;
        $result = [];
        foreach ($candidates as $candidate) {
            $given = explode(' ', self::nameKey($candidate['label']))[0];
            if (!$explicitPersonal || ($given !== '' && str_starts_with($name, $given . ' '))) {
                $result[$candidate['ref']] = $candidate;
            }
        }
        foreach ($calendar['space_keys'] ?? [] as $key) {
            if (!str_starts_with($key, '~')) {
                continue;
            }
            foreach ($logins[mb_strtolower(substr($key, 1))] ?? [] as $ref => $source) {
                $given = self::nameKey((string)($source['first_name'] ?? ''));
                if ($given === '') {
                    $given = explode(' ', self::nameKey((string)$source['display_name']))[0];
                }
                if ($given === '' || ($name !== $given && !str_starts_with($name, $given . ' '))) {
                    continue;
                }
                $owner = $owners[$ref] ?? null;
                $result[$ref] = ['ref' => $ref, 'label' => (string)$source['display_name'], 'username' => (string)$source['username'],
                    'reason' => 'name_space', 'target_label' => $owner['label'] ?? '', 'target' => $owner['target'] ?? null];
            }
        }
        return array_slice(array_values($result), 0, 10);
    }

    /**
     * EN: Traverses each identifier bucket once and keeps service/shared-email and name-only warnings explicit.
     * A proposed primary is only a review preference; existing accounts are never merged or rewritten.
     *
     * HR: Svaki skup identifikatora prolazi jednom i izričito označava servisni/zajednički email i samo ime.
     * Predloženi glavni račun samo je preporuka pregleda; postojeći računi se ne spajaju niti prepisuju.
     *
     * @param array<string,array<string,mixed>> $sources
     * @param array<string,string> $choices
     * @return list<array<string,mixed>>
     */
    private static function duplicates(array $sources, array $choices): array
    {
        $buckets = $keys = [];
        foreach ($sources as $id => $source) {
            foreach ([$source['username'] ?? '', $source['email'] ?? ''] as $value) {
                $value = mb_strtolower(trim((string)$value));
                if ($value !== '') {
                    $keys[$id]['value:' . $value] = true;
                }
            }
            $name = self::nameKey((string)($source['display_name'] ?? ''));
            if (str_contains($name, ' ')) {
                $keys[$id]['name:' . $name] = true;
            }
            foreach (array_keys($keys[$id] ?? []) as $key) {
                $buckets[$key][] = $id;
            }
        }
        $resolved = ConfluenceSiteIdentityPlan::resolve($choices);
        $visited = $used = $groups = [];
        foreach (array_keys($sources) as $id) {
            if (isset($visited[$id])) {
                continue;
            }
            $queue = [$id];
            $members = $evidence = [];
            while ($queue !== []) {
                $next = array_pop($queue);
                if (isset($visited[$next])) {
                    continue;
                }
                $visited[$next] = true;
                $members[] = $next;
                foreach (array_keys($keys[$next] ?? []) as $key) {
                    if (isset($used[$key])) {
                        continue;
                    }
                    $used[$key] = true;
                    if (count($buckets[$key]) > 1) {
                        $evidence[] = $key;
                        array_push($queue, ...$buckets[$key]);
                    }
                }
            }
            if (count($members) < 2) {
                continue;
            }
            $targets = [];
            foreach ($members as $member) {
                $decision = $resolved[$member] ?? ['choice' => '', 'source_id' => $member];
                $choice = $decision['choice'];
                $targets[] = $choice === '__create__' ? 'new:' . $decision['source_id'] : $choice;
            }
            if (!in_array('', $targets, true) && count(array_unique($targets)) === 1) {
                continue;
            }
            usort($members, static function (string $left, string $right) use ($sources, $choices): int {
                $score = static fn(string $ref): int => (is_numeric($choices[$ref] ?? '') ? 200 : 0)
                    + (ConfluenceSourceActivity::status($sources[$ref]) === 'active' ? 100 : 0)
                    + (ConfluenceSourceActivity::status($sources[$ref]) === 'unknown' ? 50 : 0)
                    + (!str_contains((string)$sources[$ref]['username'], '@') ? 10 : 0);
                return ($score($right) <=> $score($left)) ?: strcmp($left, $right);
            });
            $names = array_unique(array_map(static fn(string $ref): string => self::nameKey((string)$sources[$ref]['display_name']), $members));
            $sharedValues = array_values(array_filter($evidence, static fn(string $key): bool => str_starts_with($key, 'value:')));
            $groups[] = ['members' => array_map(static fn(string $ref): array => [
                'source' => $sources[$ref], 'choice' => $choices[$ref] ?? '',
            ], $members), 'primary' => $members[0], 'evidence' => $evidence,
                'confidence' => count($names) > 1 ? 'different_names' : ($sharedValues !== [] ? 'name_identifier' : 'name_only')];
        }
        return $groups;
    }

    /**
     * EN: Accent folding is only presentation evidence; it never changes a login or saves a mapping.
     *
     * HR: Uklanjanje dijakritika samo je dokaz za pregled; ne mijenja login niti sprema mapiranje.
     */
    private static function nameKey(string $value): string
    {
        $value = strtr(mb_strtolower(trim($value)), ['č' => 'c', 'ć' => 'c', 'š' => 's', 'ž' => 'z', 'đ' => 'dj']);
        return trim((string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value));
    }
}
