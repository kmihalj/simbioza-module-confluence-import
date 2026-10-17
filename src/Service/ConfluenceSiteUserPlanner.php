<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

/**
 * EN: Builds linear-time, non-secret account suggestions for the complete source catalog.
 * Never uses display names, weak similarity or provider subjects as proof of identity.
 *
 * HR: U linearnom vremenu izrađuje netajne prijedloge računa za cijeli izvorni pregled.
 * Prikazna imena, slaba sličnost i identifikatori providera nisu dokaz identiteta.
 */
final readonly class ConfluenceSiteUserPlanner
{
    /**
     * EN: Ambiguous username/email matches and collisions stay unresolved, never create a duplicate.
     * Email aliases and inactive targets participate; a username that is an email may match an email alias.
     *
     * HR: Dvosmislena podudaranja login oznake/emaila i sukobi ostaju neriješeni, bez izrade duplikata.
     * Uspoređuju se email aliasi i neaktivni ciljevi; login u obliku emaila može se povezati s email aliasom.
     *
     * @param list<array<string,mixed>> $sources
     * @param list<array<string,mixed>> $targets
     * @return array<string,array{choice:string,reason:string,conflict:string}>
     */
    public static function suggest(array $sources, array $targets, bool $autoMap, bool $createUnmapped, bool $createArchival = false): array
    {
        $logins = $emails = $occupied = $sourceValues = [];
        foreach ($targets as $target) {
            $id = (int)$target['id'];
            $login = self::key((string)$target['login_identifier']);
            if ($login !== '') {
                $logins[$login][$id] = true;
                $occupied[$login] = true;
            }
            foreach ([(string)($target['email'] ?? ''), ...($target['email_aliases'] ?? [])] as $email) {
                $key = self::key((string)$email);
                if ($key !== '') {
                    $emails[$key][$id] = true;
                    $occupied[$key] = true;
                }
            }
        }
        foreach ($sources as $source) {
            foreach (array_unique([self::key(self::login($source)), self::key((string)($source['email'] ?? ''))]) as $value) {
                if ($value !== '') {
                    $sourceValues[$value] = ($sourceValues[$value] ?? 0) + 1;
                }
            }
        }
        $result = [];
        foreach ($sources as $source) {
            $id = (string)$source['source_id'];
            $username = self::key((string)($source['username'] ?? ''));
            $email = self::key((string)($source['email'] ?? ''));
            $usernameMatches = $logins[$username] ?? [];
            $emailMatches = $emails[$email] ?? [];
            if (filter_var($username, FILTER_VALIDATE_EMAIL) !== false) {
                $emailMatches += $emails[$username] ?? [];
            }
            $matches = $usernameMatches + $emailMatches;
            $choice = '';
            $reason = 'unmapped';
            $conflict = '';
            if (count($matches) > 1) {
                $conflict = 'ambiguous';
            } elseif ($autoMap && count($matches) === 1) {
                $choice = (string)array_key_first($matches);
                $reason = $usernameMatches !== [] && $emailMatches !== [] ? 'username_email'
                    : ($usernameMatches !== [] ? 'username' : 'email');
            } elseif (ConfluenceSourceActivity::archival($source) && !$createArchival) {
                $reason = ConfluenceSourceActivity::status($source);
            } elseif ($createUnmapped) {
                $login = self::login($source);
                if (strlen($login) > 190 || preg_match('/[\x00-\x1f\x7f]/', $login) === 1) {
                    $conflict = 'invalid_login';
                } elseif ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $conflict = 'invalid_email';
                } else {
                    foreach (array_unique([self::key($login), $email]) as $value) {
                        if ($value !== '' && (isset($occupied[$value]) || ($sourceValues[$value] ?? 0) > 1)) {
                            $conflict = 'collision';
                        }
                    }
                }
                if ($conflict === '') {
                    $choice = '__create__';
                    $reason = 'auto_create';
                }
            }
            $result[$id] = ['choice' => $choice, 'reason' => $conflict !== '' ? 'conflict' : $reason, 'conflict' => $conflict];
        }
        return $result;
    }

    /**
     * EN: Uses the same deterministic login policy for preview and creation.
     *
     * HR: Za pregled i stvaranje koristi istu determinističku politiku login oznake.
     *
     * @param array<string,mixed> $source
     */
    public static function login(array $source): string
    {
        $value = trim((string)($source['username'] ?? '')) ?: trim((string)($source['email'] ?? ''));
        return $value !== '' ? $value : 'confluence-' . substr(hash('sha256', (string)$source['source_id']), 0, 20);
    }

    /**
     * EN: Normalizes case and surrounding whitespace, not the identity's spelling or punctuation.
     *
     * HR: Normalizira veličinu slova i rubne razmake, ne mijenja zapis identiteta ni interpunkciju.
     */
    private static function key(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
