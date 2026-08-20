<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Value;

use function is_array;
use function is_numeric;
use function is_scalar;
use function trim;

/** HR: Neizmjenjivi normalizirani zapis jednog Confluence XML objekta. EN: Immutable normalized record for one Confluence XML object. */
final readonly class ConfluenceObject
{
    /**
     * HR: Sprema normalizirani naziv klase, paket i vrijednosti objekta.
     * EN: Stores the normalized class name, package, and object values.
     *
     * @param array<string,mixed> $values
     */
    public function __construct(
        public string $className,
        public string $packageName,
        public array $values,
    ) {
    }

    /** HR: Vraća tekstualnu vrijednost bez implicitnog pretvaranja nizova. EN: Returns a scalar string without coercing arrays. */
    public function string(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? null;

        return is_scalar($value) ? trim((string)$value) : $default;
    }

    /** HR: Vraća cijeli broj ili zadanu vrijednost. EN: Returns an integer or the supplied default. */
    public function integer(string $key, int $default = 0): int
    {
        $value = $this->values[$key] ?? null;

        return is_numeric($value) ? (int)$value : $default;
    }

    /** HR: Vraća referencu na drugi Confluence objekt. EN: Returns a reference to another Confluence object. */
    public function reference(string $key): string
    {
        return $this->string($key . '_ref');
    }

    /**
     * HR: Vraća popis referenci na druge Confluence objekte.
     * EN: Returns references to other Confluence objects.
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
