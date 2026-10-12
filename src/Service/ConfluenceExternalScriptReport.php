<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

/**
 * EN: Groups exact script identities and their distinct source pages, without activating anything.
 *
 * HR: Grupira točne identitete skripti i njihove izvorne stranice, bez aktiviranja.
 */
final class ConfluenceExternalScriptReport
{
    /**
     * EN: One definition is shown once even when imported in multiple macros or history versions.
     *
     * HR: Definiciju prikazuje jednom i kad je uvezena kroz više makroa ili povijesnih verzija.
     *
     * @param list<array<string,mixed>> $pages
     * @return array<string,array{definition:array<string,mixed>,pages:array<string,array{id:string,title:string,url:string,resolution:string}>}>
     */
    public static function groups(array $pages): array
    {
        $groups = [];
        foreach ($pages as $page) {
            $sourceId = is_string($page['source_page_id'] ?? null) ? $page['source_page_id'] : '';
            foreach (is_array($page['issues'] ?? null) ? $page['issues'] : [] as $issue) {
                if (
                    !is_array($issue) || ($issue['type'] ?? '') !== 'external_script'
                    || !is_string($issue['marker'] ?? null) || !is_array($issue['script'] ?? null)
                ) {
                    continue;
                }
                $id = $issue['marker'];
                $groups[$id] ??= ['definition' => $issue['script'], 'pages' => []];
                $groups[$id]['pages'][$sourceId] = [
                    'id' => $sourceId,
                    'title' => is_string($page['title'] ?? null) ? $page['title'] : '',
                    'url' => is_string($page['url'] ?? null) ? $page['url'] : '',
                    'resolution' => ($issue['resolved'] ?? false) === true
                        && is_string($issue['resolution_mode'] ?? null) ? $issue['resolution_mode'] : '',
                ];
            }
        }
        return $groups;
    }
}
