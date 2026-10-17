<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;

/**
 * EN: Projects reviewed identities without allocating accounts or inventing future database IDs.
 * Source references stay stable until the execution checkpoint creates or maps the real users.
 *
 * HR: Prikazuje pregledane identitete bez stvaranja računa ili izmišljanja budućih ID-jeva baze.
 * Izvorne reference ostaju stabilne do izvršnog koraka stvaranja ili mapiranja stvarnih korisnika.
 */
final class ConfluenceSiteIdentityPlan
{
    public const SOURCE_PREFIX = '__source__:';

    /**
     * EN: Resolves explicit source-to-source choices in linear time without allocating target IDs.
     * Unknown/skipped roots and cycles are invalid; email/name similarity never creates a relation.
     *
     * HR: U linearnom vremenu razrješava izričite veze izvornih računa bez dodjele ciljnih ID-jeva.
     * Nepoznati/preskočeni korijeni i ciklusi nisu valjani; sličnost emaila/imena ne stvara vezu.
     *
     * @param array<string,string> $choices
     * @return array<string,array{source_id:string,choice:string}>
     */
    public static function resolve(array $choices): array
    {
        $resolved = [];
        foreach ($choices as $id => $choice) {
            if (isset($resolved[$id])) {
                continue;
            }
            $path = [];
            $cursor = (string)$id;
            while (!isset($resolved[$cursor])) {
                if (isset($path[$cursor]) || !array_key_exists($cursor, $choices)) {
                    throw new ConfluenceImportException(__('Veza izvornih računa nije valjana: provjerite kružne ili nepostojeće odabire.'));
                }
                $value = $choices[$cursor];
                $path[$cursor] = true;
                if (!str_starts_with($value, self::SOURCE_PREFIX)) {
                    if ($value === '' && count($path) > 1) {
                        throw new ConfluenceImportException(__('Povezani izvorni račun mora biti odabran za izradu ili mapiran na postojećeg korisnika.'));
                    }
                    $resolved[$cursor] = ['source_id' => $cursor, 'choice' => $value];
                    break;
                }
                $cursor = substr($value, strlen(self::SOURCE_PREFIX));
            }
            $root = $resolved[$cursor];
            if ($root['choice'] === '' && count($path) > 0 && (string)$id !== $cursor) {
                throw new ConfluenceImportException(__('Povezani izvorni račun mora biti odabran za izradu ili mapiran na postojećeg korisnika.'));
            }
            foreach ($path as $alias => $_) {
                $resolved[$alias] = $root;
            }
        }
        return $resolved;
    }

    /**
     * EN: Exposes only selected sources; a new account has an explicit deferred marker, never the actor's ID.
     *
     * HR: Izlaže samo odabrane izvore; novi račun ima izričitu odgođenu oznaku, nikada ID izvršitelja.
     *
     * @param array<string,mixed> $catalog
     * @param array<string,mixed> $choices
     * @param list<array<string,mixed>> $targets
     * @return array{users:array<string,int|string>,aliases:array<string,string>,owners:array<string,array{label:string,target:int|string}>}
     */
    public static function project(array $catalog, array $choices, array $targets): array
    {
        $targets = array_column($targets, null, 'id');
        $sources = array_column($catalog['users'] ?? [], null, 'source_id');
        $resolved = self::resolve($choices);
        $result = ['users' => [], 'aliases' => [], 'owners' => []];
        foreach ($catalog['users'] ?? [] as $source) {
            $id = (string)$source['source_id'];
            $root = $resolved[$id] ?? ['source_id' => $id, 'choice' => ''];
            $choice = $root['choice'];
            if ($choice === '__create__') {
                $target = 'new:' . $root['source_id'];
                $primary = $sources[$root['source_id']];
                $label = (string)($primary['display_name'] ?: $primary['username']);
            } elseif (ctype_digit((string)$choice) && isset($targets[(int)$choice])) {
                $target = (int)$choice;
                $label = (string)$targets[$target]['display_name'];
            } else {
                continue;
            }
            $result['owners'][$id] = ['label' => $label, 'target' => $target];
            foreach ([$id, ...($source['aliases'] ?? [])] as $alias) {
                $keys = [$alias];
                if (str_contains($alias, ':')) {
                    $keys[] = substr($alias, strpos($alias, ':') + 1);
                }
                foreach ($keys as $key) {
                    $result['users'][$key] = $target;
                    $result['aliases'][$key] = $id;
                }
            }
        }
        return $result;
    }

    /**
     * EN: Groups selected personal source spaces by the reviewed current or future owner, without writes.
     * This is an explicit additive-choice preview, never permission to replace existing content.
     *
     * HR: Grupira odabrana osobna područja po pregledanom postojećem ili budućem vlasniku, bez upisa.
     * Ovo je pregled izričitog odabira dodavanja, nikada dopuštenje za zamjenu postojećeg sadržaja.
     *
     * @param list<array<string,mixed>> $spaces
     * @param array<string,mixed> $catalog
     * @param array<string,string> $choices
     * @return list<array{owner_label:string,sources:list<array{id:string,key:string}>}>
     */
    public static function personalGroups(array $spaces, array $catalog, array $choices): array
    {
        $resolved = self::resolve($choices);
        $sources = array_column($catalog['users'] ?? [], null, 'source_id');
        $owners = [];
        foreach ($sources as $id => $source) {
            $root = $resolved[$id] ?? null;
            if ($root === null || $root['choice'] === '') {
                continue;
            }
            foreach ([$id, ...($source['aliases'] ?? [])] as $alias) {
                $owners[$alias] = $root;
                if (str_contains($alias, ':')) {
                    $owners[substr($alias, strpos($alias, ':') + 1)] = $root;
                }
            }
        }
        $groups = [];
        foreach ($spaces as $space) {
            $root = $owners[$space['owner_source_key'] ?? ''] ?? null;
            if (($space['type'] ?? '') !== 'personal' || $root === null) {
                continue;
            }
            $key = $root['choice'] === '__create__' ? 'new:' . $root['source_id'] : $root['choice'];
            $source = $sources[$root['source_id']];
            $groups[$key]['owner_label'] ??= (string)($source['display_name'] ?: $source['username']);
            $groups[$key]['sources'][] = ['id' => (string)$space['source_id'], 'key' => (string)$space['source_key']];
        }
        return array_values(array_filter($groups, static fn(array $group): bool => count($group['sources']) > 1));
    }
}
