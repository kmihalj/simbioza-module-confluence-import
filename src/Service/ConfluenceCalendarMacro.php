<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

/**
 * EN: Normalizes bounded calendar selections without assuming a site's IDs or directory layout.
 *
 * HR: Normalizira ograničene odabire kalendara bez pretpostavki o ID-jevima ili imenicima sitea.
 */
final class ConfluenceCalendarMacro
{
    /**
     * EN: Keeps source order, removes duplicate references and rejects malformed or oversized selections.
     * An empty selection remains an explicit unresolved reference, never the viewer's subscriptions.
     *
     * HR: Čuva izvorni redoslijed, uklanja ponovljene reference i odbija nevaljane/prevelike odabire.
     * Prazan odabir ostaje izričita nerazriješena referenca, nikada pretplate gledatelja.
     *
     * @return list<string>
     */
    public static function ids(string $raw): array
    {
        if (strlen($raw) > 191000) {
            throw new \InvalidArgumentException('Calendar selection exceeds its limit.');
        }
        $ids = array_values(array_unique(array_map(trim(...), explode(',', $raw))));
        if (count($ids) > 1000) {
            throw new \InvalidArgumentException('Too many selected calendars.');
        }
        foreach ($ids as $id) {
            if ($id !== '' && (strlen($id) > 190 || preg_match('/^[A-Za-z0-9_.:-]+$/D', $id) !== 1)) {
                throw new \InvalidArgumentException('Invalid calendar reference.');
            }
        }
        return $ids;
    }
}
