<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

/**
 * EN: Builds review-only owner suggestions from names and confirmed identity choices.
 * A calendar creator or a page embedding a calendar never proves personal ownership.
 * Suggestions do not change choices, create accounts, assign access or start imports.
 *
 * HR: Gradi prijedloge vlasnika za pregled iz naziva i potvrđenih odabira identiteta.
 * Kreator kalendara ni stranica koja ugrađuje kalendar nisu dokaz osobnog vlasništva.
 * Prijedlozi ne mijenjaju odabire, ne stvaraju račune, ne daju pristup ni pokreću uvoz.
 */
final class ConfluenceCalendarOwnerSuggestions
{
    /**
     * EN: Indexes the source directory once; only a human can accept a suggested owner.
     * Duplicate source identities mapped to one target share one candidate, not a false conflict.
     * One personal-space association is only a weak hint and cannot be bulk accepted.
     *
     * HR: Jednom indeksira izvorni imenik; samo korisnik može prihvatiti predloženog vlasnika.
     * Izvorni identiteti mapirani na isti cilj čine jednog kandidata, ne lažni sukob.
     * Veza s jednim osobnim područjem samo je slab trag i ne prihvaća se skupno.
     *
     * @param array<string,array<string,mixed>> $calendars
     * @param list<array<string,mixed>> $sources
     * @param array<string,array{label:string,target:int|string}> $owners
     * @return array<string,array<string,mixed>>
     */
    public static function build(array $calendars, array $sources, array $owners): array
    {
        $names = $logins = $users = [];
        foreach ($sources as $source) {
            $ref = (string)$source['source_id'];
            $users[$ref] = $source;
            foreach ([(string)($source['display_name'] ?? ''), trim(($source['first_name'] ?? '') . ' ' . ($source['last_name'] ?? ''))] as $name) {
                $key = self::nameKey($name);
                if ($key !== '') {
                    $names[$key][$ref] = true;
                }
            }
            foreach ([(string)($source['username'] ?? ''), (string)($source['email'] ?? '')] as $login) {
                $key = mb_strtolower(trim($login));
                if ($key !== '') {
                    $logins[$key][$ref] = true;
                }
            }
        }
        $result = [];
        foreach ($calendars as $id => $calendar) {
            $matches = [];
            $parts = preg_split('/\s+[-–—|:]\s+|[()]/u', (string)$calendar['name']) ?: [];
            foreach ([(string)$calendar['name'], ...$parts] as $part) {
                $part = trim($part);
                foreach (array_keys($logins[mb_strtolower($part)] ?? []) as $ref) {
                    $matches[$ref] = 'login';
                }
                foreach (array_keys($names[self::nameKey($part)] ?? []) as $ref) {
                    $matches[$ref] ??= 'name';
                }
            }
            $weak = false;
            if ($matches === []) {
                $personalKeys = array_values(array_unique(array_filter(
                    $calendar['space_keys'] ?? [],
                    static fn(string $key): bool => str_starts_with($key, '~')
                )));
                if (count($personalKeys) === 1) {
                    foreach (array_keys($logins[mb_strtolower(substr($personalKeys[0], 1))] ?? []) as $ref) {
                        $matches[$ref] = 'personal_space';
                    }
                    $weak = $matches !== [];
                }
            }
            $candidates = [];
            foreach ($matches as $ref => $reason) {
                $owner = $owners[$ref] ?? null;
                $key = $owner === null ? 'source:' . $ref : 'target:' . $owner['target'];
                $source = $users[$ref];
                $candidate = ['ref' => $ref, 'label' => (string)($source['display_name'] ?: $source['username']),
                    'username' => (string)$source['username'], 'reason' => $reason,
                    'target_label' => $owner['label'] ?? '', 'target' => $owner['target'] ?? null];
                if (!isset($candidates[$key]) || strcmp($ref, $candidates[$key]['ref']) < 0) {
                    $candidates[$key] = $candidate;
                }
            }
            $count = count($candidates);
            $candidate = $count === 1 ? array_values($candidates)[0] : null;
            $status = $count === 0 ? 'none' : ($count > 1 ? 'ambiguous' : ($weak ? 'hint'
                : ($candidate['target'] === null ? 'unmapped' : (is_int($candidate['target']) ? 'mapped' : 'planned'))));
            $result[$id] = ['status' => $status, 'candidate_count' => $count,
                'personal_candidate' => $count > 0 || ConfluenceCalendarPresentation::suggestedType($calendar) === 'personal',
                'candidates' => array_slice(array_values($candidates), 0, 10),
                'choice' => in_array($status, ['mapped', 'planned'], true) ? $candidate['ref'] : ''];
        }
        return $result;
    }

    /**
     * EN: Normalizes case, separators and Croatian diacritics for suggestions, never for identity mapping.
     * Spelling similarity, partial names and creator IDs are deliberately not compared.
     *
     * HR: Za prijedloge normalizira veličinu slova, razdjelnike i hrvatske dijakritike, ne za mapiranje identiteta.
     * Sličnost zapisa, dijelovi imena i ID-jevi kreatora namjerno se ne uspoređuju.
     */
    private static function nameKey(string $value): string
    {
        $value = strtr(mb_strtolower(trim($value)), ['č' => 'c', 'ć' => 'c', 'š' => 's', 'ž' => 'z', 'đ' => 'dj']);
        return trim((string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value));
    }
}
