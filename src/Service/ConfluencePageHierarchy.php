<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use function array_reverse;
use function array_keys;
use function is_scalar;

/**
 * HR: Preslikava Confluence roditelje na najbliže stranice koje se stvarno uvoze.
 * EN: Maps Confluence parents to the nearest pages that are actually imported.
 */
final readonly class ConfluencePageHierarchy
{
    /**
     * HR: Preskače izostavljene nacrte i obrisane posrednike bez izdizanja njihove djece u korijen.
     * EN: Skips excluded draft and deleted intermediates without promoting their children to the root.
     *
     * @param array<string,list<array<string,mixed>>> $selectedPages
     * @param list<array<string,mixed>> $allPages
     * @return array<string,string>
     */
    public function normalizedParents(array $selectedPages, array $allPages): array
    {
        $allGroups = [];
        foreach ($allPages as $page) {
            $logicalId = $this->text($page['logical_source_id'] ?? '');
            if ($logicalId !== '') {
                $allGroups[$logicalId][] = $page;
            }
        }

        $parentByPage = [];
        foreach ($allGroups as $logicalId => $versions) {
            $current = $this->currentPage($versions);
            $parentByPage[$logicalId] = $this->text($current['parent_id'] ?? '');
        }

        $selected = array_fill_keys(array_map(strval(...), array_keys($selectedPages)), true);
        $result = [];
        foreach ($selectedPages as $logicalId => $versions) {
            $current = $this->currentPage($versions);
            $parentId = $this->text($current['parent_id'] ?? '');
            $visited = [];
            while ($parentId !== '' && !isset($selected[$parentId])) {
                if (isset($visited[$parentId])) {
                    $parentId = '';
                    break;
                }
                $visited[$parentId] = true;
                $parentId = $parentByPage[$parentId] ?? '';
            }
            $result[(string)$logicalId] = $parentId;
        }

        return $result;
    }

    /**
     * HR: Odabire aktualnu objavljenu verziju stranice ili zadnju dostupnu verziju.
     * EN: Selects the current published page version or the last available version.
     *
     * @param list<array<string,mixed>> $versions
     * @return array<string,mixed>
     */
    private function currentPage(array $versions): array
    {
        foreach (array_reverse($versions) as $page) {
            if (($page['original_version_id'] ?? '') === '' && ($page['status'] ?? '') !== 'draft') {
                return $page;
            }
        }

        return $versions[array_key_last($versions)] ?? [];
    }

    /** HR: Sigurno pretvara skalarnu vrijednost u obrezani tekst. EN: Safely converts a scalar value to trimmed text. */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }
}
