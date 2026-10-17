<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\HeartPhrameModuleOrm\Database\LocaleSorter;

/**
 * EN: Filters and stably paginates the bounded source catalog, independently of saved mapping choices.
 * Only approved page sizes and scalar query values are accepted.
 *
 * HR: Filtrira i stabilno dijeli ograničeni izvorni pregled, neovisno o spremljenim odabirima mapiranja.
 * Prihvaća samo dopuštene veličine stranice i skalarne vrijednosti upita.
 */
final readonly class ConfluenceSiteUserPagination
{
    /**
     * EN: Returns the current slice, result range and a bounded numbered window with explicit gaps.
     * Filtering precedes pagination; out-of-range pages are clamped, including an empty result.
     *
     * HR: Vraća aktualni izrez, raspon rezultata i ograničeni prozor brojeva s izričitim preskocima.
     * Filtriranje prethodi paginaciji; stranice izvan raspona ograničavaju se, i za prazan rezultat.
     *
     * @param list<array<string,mixed>> $users
     * @param array<string,mixed> $query
     * @param array<string,string> $choices
     * @param array<string,string> $reasons
     * @param array<string,string> $conflicts
     * @return array{items:list<array<string,mixed>>,query:string,status:string,counts:array<string,int>,page:int,pages:int,per_page:int,total:int,from:int,to:int,numbers:list<int|null>}
     */
    public static function paginate(array $users, array $query, array $choices = [], array $reasons = [], array $conflicts = []): array
    {
        $search = mb_substr(trim(self::scalar($query['identity_q'] ?? '')), 0, 100);
        $needle = mb_strtolower($search);
        $size = (int)self::scalar($query['identity_per_page'] ?? '50');
        $size = in_array($size, [25, 50, 100], true) ? $size : 50;
        $status = self::scalar($query['identity_status'] ?? 'all');
        $status = in_array($status, ['all', 'mapped', 'unmapped', 'create', 'automatic', 'conflict', 'inactive', 'reference_only', 'activity_unknown'], true) ? $status : 'all';
        $rows = array_values(array_filter($users, static fn(array $user): bool => $needle === ''
            || str_contains(mb_strtolower(implode(' ', array_map(
                static fn(string $key): string => self::scalar($user[$key] ?? ''),
                ['username', 'display_name', 'email', 'directory_name', 'source_id'],
            ))), $needle)));
        $counts = array_fill_keys(['all', 'mapped', 'unmapped', 'create', 'automatic', 'conflict', 'inactive', 'reference_only', 'activity_unknown'], 0);
        $rows = array_values(array_filter($rows, static function (array $user) use ($choices, $reasons, $conflicts, $status, &$counts): bool {
            $id = (string)$user['source_id'];
            $choice = $choices[$id] ?? '';
            $flags = ['all' => true, 'mapped' => (ctype_digit($choice) && (int)$choice > 0) || str_starts_with($choice, ConfluenceSiteIdentityPlan::SOURCE_PREFIX),
                'unmapped' => $choice === '', 'create' => $choice === '__create__',
                'automatic' => in_array($reasons[$id] ?? '', ['username', 'email', 'username_email'], true),
                'conflict' => isset($conflicts[$id]),
                'inactive' => in_array(ConfluenceSourceActivity::status($user), ['account_disabled', 'directory_disabled'], true),
                'reference_only' => ConfluenceSourceActivity::status($user) === 'reference_only',
                'activity_unknown' => ConfluenceSourceActivity::status($user) === 'unknown'];
            foreach ($flags as $key => $matches) {
                $counts[$key] += (int)$matches;
            }
            return $flags[$status];
        }));
        $locale = LocaleSorter::currentLocale();
        usort($rows, static function (array $left, array $right) use ($locale): int {
            $leftName = trim(self::scalar($left['display_name'] ?? '')) ?: self::scalar($left['username'] ?? '');
            $rightName = trim(self::scalar($right['display_name'] ?? '')) ?: self::scalar($right['username'] ?? '');
            return LocaleSorter::compare($leftName, $rightName, $locale)
                ?: strcmp(self::scalar($left['source_id'] ?? ''), self::scalar($right['source_id'] ?? ''));
        });
        $total = count($rows);
        $pages = max(1, (int)ceil($total / $size));
        $page = max(1, min($pages, (int)self::scalar($query['identity_page'] ?? '1')));
        $window = array_values(array_unique([1, ...range(max(1, $page - 2), min($pages, $page + 2)), $pages]));
        $numbers = [];
        $previous = 0;
        foreach ($window as $number) {
            if ($previous > 0 && $number - $previous > 1) {
                $numbers[] = null;
            }
            $numbers[] = $number;
            $previous = $number;
        }
        return ['items' => array_slice($rows, ($page - 1) * $size, $size), 'query' => $search, 'status' => $status, 'counts' => $counts,
            'page' => $page, 'pages' => $pages, 'per_page' => $size, 'total' => $total,
            'from' => $total === 0 ? 0 : ($page - 1) * $size + 1,
            'to' => min($page * $size, $total), 'numbers' => $numbers];
    }

    /**
     * EN: Rejects nested query parameters without PHP conversion warnings.
     *
     * HR: Odbacuje ugniježđene parametre upita bez PHP upozorenja o pretvorbi.
     */
    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }
}
