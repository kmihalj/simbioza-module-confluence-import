<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

/**
 * HR: Sigurno predlaže postojeće Auth korisnike i grupe za Confluence
 * identitete. Podudaranje je namjerno strogo: email, login oznaka, ključ ili
 * naziv moraju se podudarati nakon uklanjanja suvišnih razmaka i razlike u
 * veličini slova. Slični nazivi nikada se ne povezuju automatski.
 *
 * EN: Safely suggests existing Auth users and groups for Confluence
 * principals. Matching is intentionally strict: email, login identifier, key,
 * or name must match after whitespace and case normalization. Similar names
 * are never linked automatically.
 */
final readonly class ConfluencePrincipalMatcher
{
    /**
     * HR: Predlaže točno jednog korisnika, prvo po emailu pa po login oznaci.
     * EN: Suggests exactly one user, preferring email before login identifier.
     *
     * @param array<string,mixed> $sourceUser
     * @param list<array<string,mixed>> $targetUsers
     */
    public function suggestUserId(array $sourceUser, array $targetUsers): ?int
    {
        $email = $this->normalize($sourceUser['email'] ?? '');
        if ($email !== '') {
            $emailMatches = [];
            foreach ($targetUsers as $targetUser) {
                if (!is_array($targetUser)) {
                    continue;
                }

                $emails = [];
                if (is_scalar($targetUser['email'] ?? null)) {
                    $emails[] = (string)$targetUser['email'];
                }
                if (is_array($targetUser['email_aliases'] ?? null)) {
                    foreach ($targetUser['email_aliases'] as $alias) {
                        if (is_scalar($alias)) {
                            $emails[] = (string)$alias;
                        }
                    }
                }

                foreach ($emails as $candidateEmail) {
                    if ($this->normalize($candidateEmail) !== $email) {
                        continue;
                    }

                    $this->addId($emailMatches, $targetUser['id'] ?? null);
                    break;
                }
            }

            if (count($emailMatches) === 1) {
                return $emailMatches[0];
            }
            if (count($emailMatches) > 1) {
                return null;
            }
        }

        $username = $this->normalize($sourceUser['username'] ?? '');
        if ($username === '') {
            return null;
        }

        $loginMatches = [];
        foreach ($targetUsers as $targetUser) {
            if (
                !is_array($targetUser)
                || $this->normalize($targetUser['login_identifier'] ?? '') !== $username
            ) {
                continue;
            }

            $this->addId($loginMatches, $targetUser['id'] ?? null);
        }

        return count($loginMatches) === 1 ? $loginMatches[0] : null;
    }

    /**
     * HR: Predlaže aktivnu ciljnu grupu po točnom ključu ili nazivu.
     * EN: Suggests an enabled target group by exact key or name.
     *
     * @param list<array<string,mixed>> $targetGroups
     */
    public function suggestGroupId(string $sourceName, array $targetGroups): ?int
    {
        $source = $this->normalize($sourceName);
        if ($source === '') {
            return null;
        }

        $keyMatches = [];
        $nameMatches = [];
        foreach ($targetGroups as $targetGroup) {
            if (!is_array($targetGroup) || !(bool)($targetGroup['is_enabled'] ?? true)) {
                continue;
            }

            if ($this->normalize($targetGroup['group_key'] ?? '') === $source) {
                $this->addId($keyMatches, $targetGroup['id'] ?? null);
            }
            if ($this->normalize($targetGroup['group_name'] ?? '') === $source) {
                $this->addId($nameMatches, $targetGroup['id'] ?? null);
            }
        }

        if (count($keyMatches) === 1) {
            return $keyMatches[0];
        }
        if (count($keyMatches) > 1) {
            return null;
        }

        return count($nameMatches) === 1 ? $nameMatches[0] : null;
    }

    /**
     * HR: Gradi stabilnu login oznaku za neaktivni predračun bez providera.
     * EN: Builds a stable login identifier for an inactive provider-less account.
     *
     * @param array<string,mixed> $sourceUser
     */
    public function inactiveLoginIdentifier(array $sourceUser): string
    {
        foreach (['username', 'email'] as $field) {
            $value = is_scalar($sourceUser[$field] ?? null) ? trim((string)$sourceUser[$field]) : '';
            if ($value !== '') {
                return $this->truncate($value, 190);
            }
        }

        $sourceKey = is_scalar($sourceUser['source_key'] ?? null)
            ? trim((string)$sourceUser['source_key'])
            : '';

        return 'confluence-' . substr(hash('sha256', $sourceKey !== '' ? $sourceKey : serialize($sourceUser)), 0, 24);
    }

    /**
     * HR: Dodaje valjani pozitivni ID samo jednom u listu rezultata.
     * EN: Adds a valid positive ID to the result list only once.
     *
     * @param list<int> $ids
     */
    private function addId(array &$ids, mixed $candidate): void
    {
        if (!is_numeric($candidate) || (int)$candidate <= 0) {
            return;
        }

        $id = (int)$candidate;
        if (!in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }

    /**
     * HR: Normalizira izvorni identifikator za strogu usporedbu.
     * EN: Normalizes a source identifier for strict comparison.
     */
    private function normalize(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string)$value) : '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /**
     * HR: Sigurno skraćuje tehničku oznaku na dopuštenu duljinu.
     * EN: Safely truncates a technical identifier to the allowed length.
     */
    private function truncate(string $value, int $length): string
    {
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $length, 'UTF-8')
            : substr($value, 0, $length);
    }
}
