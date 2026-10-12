<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Value;

use function is_array;
use function is_numeric;
use function is_scalar;
use function trim;

/**
 * EN: Immutable normalized record for one Confluence XML object.
 *
 * HR: Neizmjenjivi normalizirani zapis jednog Confluence XML objekta.
 */
final readonly class ConfluenceObject
{
    /**
     * EN: Stores the normalized class name, package, and object values.
     *
     * HR: Sprema normalizirani naziv klase, paket i vrijednosti objekta.
     *
     * @param array<string,mixed> $values
     */
    public function __construct(
        public string $className,
        public string $packageName,
        public array $values,
    ) {
    }

    /**
     * EN: Returns a scalar string without coercing arrays.
     *
     * HR: Vraća tekstualnu vrijednost bez implicitnog pretvaranja nizova.
     */
    public function string(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? null;

        return is_scalar($value) ? trim((string)$value) : $default;
    }

    /**
     * EN: Returns an integer or the supplied default.
     *
     * HR: Vraća cijeli broj ili zadanu vrijednost.
     */
    public function integer(string $key, int $default = 0): int
    {
        $value = $this->values[$key] ?? null;

        return is_numeric($value) ? (int)$value : $default;
    }

    /**
     * EN: Returns a reference to another Confluence object.
     *
     * HR: Vraća referencu na drugi Confluence objekt.
     */
    public function reference(string $key): string
    {
        return $this->string($key . '_ref');
    }

    /**
     * EN: Returns references to other Confluence objects.
     *
     * HR: Vraća popis referenci na druge Confluence objekte.
     *
     * @return list<string>
     */
    public function references(string $key): array
    {
        $value = $this->values[$key . '_refs'] ?? null;
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $candidate) {
            if (is_scalar($candidate) && trim((string)$candidate) !== '') {
                $result[] = trim((string)$candidate);
            }
        }

        return $result;
    }
}
