<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

/**
 * EN: Proposes ordinary group mappings once for the entire source catalog.
 * Duplicate directory labels are kept separate; no administrator role is inferred.
 *
 * HR: Jednom predlaže mapiranja običnih grupa za cijeli izvorni pregled.
 * Jednaki nazivi različitih imenika ostaju odvojeni; administratorska uloga se ne zaključuje.
 */
final class ConfluenceSiteGroupPlanner
{
    /**
     * EN: Exact case-insensitive names may match one assignable target only.
     * New duplicate-directory groups receive the same qualified names used by manual creation.
     *
     * HR: Točni nazivi neovisni o veličini slova smiju odgovarati samo jednom dodjeljivom cilju.
     * Nove grupe jednakih naziva dobivaju isti nastavak imenika kao pri ručnoj izradi.
     *
     * @param list<array<string,mixed>> $sources
     * @param list<array<string,mixed>> $targets
     * @param list<string> $unavailableNames
     * @return array<string,array{choice:string,reason:string,conflict:string}>
     */
    public static function suggest(array $sources, array $targets, bool $autoMap, bool $autoCreate, array $unavailableNames = []): array
    {
        $normalize = static fn(string $name): string => mb_strtolower(trim($name));
        $counts = array_count_values(array_map(static fn(array $source): string => $normalize((string)$source['name']), $sources));
        $index = [];
        foreach ($targets as $target) {
            $index[$normalize((string)$target['group_name'])][(int)$target['id']] = true;
        }
        $blocked = array_fill_keys(array_map($normalize, $unavailableNames), true);
        $names = [];
        foreach ($sources as $source) {
            $name = trim((string)$source['name']);
            if (($counts[$normalize($name)] ?? 0) > 1) {
                $name .= ' [' . $source['directory_id'] . ']';
            }
            $names[(string)$source['source_id']] = $name;
        }
        $qualifiedCounts = array_count_values(array_map($normalize, $names));
        $result = [];
        foreach ($sources as $source) {
            $id = (string)$source['source_id'];
            $name = $names[$id];
            $key = $normalize($name);
            $matches = $index[$key] ?? [];
            $choice = '';
            $reason = 'unmapped';
            $conflict = '';
            if ($autoMap && count($matches) === 1) {
                $choice = (string)array_key_first($matches);
                $reason = 'group_name';
            } elseif (($autoMap || $autoCreate) && (count($matches) > 1 || isset($blocked[$key]) || $qualifiedCounts[$key] > 1)) {
                $reason = 'conflict';
                $conflict = 'group_ambiguous';
            } elseif ($autoCreate && $matches === []) {
                if (trim((string)$source['name']) === '' || strlen($name) > 190 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
                    $reason = 'conflict';
                    $conflict = 'group_invalid';
                } else {
                    $choice = '__create__';
                    $reason = 'group_auto_create';
                }
            }
            $result[$id] = ['choice' => $choice, 'reason' => $reason, 'conflict' => $conflict];
        }
        return $result;
    }
}
