<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

/**
 * EN: Interprets exported account and directory flags without inferring missing metadata.
 * Directory names, implementation classes and installation-specific identifiers are not policy.
 *
 * HR: Tumači izvezene oznake računa i imenika bez nagađanja nedostajućih metapodataka.
 * Nazivi imenika, implementacijske klase i identifikatori pojedine instalacije nisu pravila.
 */
final class ConfluenceSourceActivity
{
    /**
     * EN: Missing and unfamiliar boolean values remain unknown, not active or disabled.
     *
     * HR: Nedostajuće i nepoznate logičke vrijednosti ostaju nepoznate, ne aktivne ili ugašene.
     */
    public static function flag(string $value): ?bool
    {
        return match (strtolower(trim($value))) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
    }

    /**
     * EN: A disabled account or directory is conclusive; missing metadata is not.
     * Historical author references are not directory accounts and never imply login rights.
     *
     * HR: Ugašen račun ili imenik jest jasan dokaz; nedostajući metapodaci nisu.
     * Povijesne reference autora nisu računi imenika i nikada ne podrazumijevaju pravo prijave.
     *
     * @param array<string,mixed> $source
     */
    public static function status(array $source): string
    {
        if ($source['reference_only'] ?? false) {
            return 'reference_only';
        }
        if (($source['directory_active'] ?? null) === false) {
            return 'directory_disabled';
        }
        $account = array_key_exists('account_active', $source) ? $source['account_active'] : ($source['active'] ?? null);
        if ($account === false) {
            return 'account_disabled';
        }
        return $account === true && ($source['directory_active'] ?? null) === true ? 'active' : 'unknown';
    }

    /**
     * EN: Only definite inactivity and reference-only entries are skipped by automatic creation.
     *
     * HR: Automatska izrada preskače samo dokazanu neaktivnost i isključivo povijesne reference.
     *
     * @param array<string,mixed> $source
     */
    public static function archival(array $source): bool
    {
        return in_array(self::status($source), ['reference_only', 'directory_disabled', 'account_disabled'], true);
    }

    /**
     * EN: Indexes only known inactive source identities, including original author keys.
     * This is custody evidence, never a user/ACL mapping to the importing administrator.
     *
     * HR: Indeksira samo poznate neaktivne identitete, uključujući izvorne oznake autora.
     * Ovo je dokaz za skrbništvo nad sadržajem, nikada korisničko/ACL mapiranje na administratora.
     *
     * @param array<string,mixed> $catalog
     * @return array<string,array<string,mixed>>
     */
    public static function archivalSources(array $catalog): array
    {
        $result = [];
        foreach ($catalog['users'] ?? [] as $source) {
            if (!self::archival($source)) {
                continue;
            }
            foreach ([$source['source_id'], ...($source['aliases'] ?? [])] as $key) {
                $result[$key] = $source;
                if (str_contains($key, ':')) {
                    $result[substr($key, strpos($key, ':') + 1)] = $source;
                }
            }
        }
        return $result;
    }
}
