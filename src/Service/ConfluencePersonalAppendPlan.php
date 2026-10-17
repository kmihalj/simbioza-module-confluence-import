<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;

/**
 * EN: Plans insert-only personal imports from one batched target snapshot. Existing
 * source pages are kept by identity, never matched to or overwritten by title.
 *
 * HR: Planira osobni uvoz samo novih stranica iz skupnog snimka cilja. Postojeće
 * izvorne stranice čuva po identitetu, nikada ih ne povezuje niti prepisuje po naslovu.
 */
final class ConfluencePersonalAppendPlan
{
    /**
     * EN: Reserves every occupied slug and separates preserved pages from new work.
     * Missing or inconsistent mapped targets stop the import rather than recreate content.
     *
     * HR: Rezervira svaki zauzeti slug i odvaja sačuvane stranice od novog sadržaja.
     * Nestali ili nedosljedni mapirani ciljevi zaustavljaju uvoz umjesto ponovne izrade sadržaja.
     *
     * @param array<string,list<array<string,mixed>>> $pages
     * @param list<array<string,mixed>> $nodes
     * @param list<array<string,mixed>> $mappings
     * @return array{pages:array<string,list<array<string,mixed>>>,retained:array<string,array<string,mixed>>,parents:array<string,int>,used:array<string,true>}
     */
    public static function build(array $pages, array $nodes, array $mappings): array
    {
        $byId = [];
        $used = [];
        foreach ($nodes as $node) {
            $byId[(int)$node['id']] = $node;
            $used[(string)$node['slug']] = true;
        }
        $retained = [];
        $parents = [];
        foreach ($mappings as $mapping) {
            $logical = (string)$mapping['logical_source_id'];
            $node = $byId[(int)($mapping['target_node_id'] ?? 0)] ?? null;
            if (!is_array($node) || (string)($node['document_key'] ?? '') !== (string)$mapping['target_document_key']) {
                throw new ConfluenceImportException(__('Postojeće mapiranje stranice nije valjano. Uvoz dodavanjem je zaustavljen bez zamjene sadržaja.'));
            }
            if ((bool)($node['is_enabled'] ?? false)) {
                $parents[$logical] = (int)$node['id'];
            }
            if (isset($pages[$logical])) {
                $retained[$logical] = $node;
                unset($pages[$logical]);
            }
        }

        return ['pages' => $pages, 'retained' => $retained, 'parents' => $parents, 'used' => $used];
    }
}
