<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use function rtrim;
use function strlen;
use function strrpos;
use function substr;
use function trim;

/**
 * HR: Gradi prenosive i jedinstvene slugove uvezenih Confluence stranica.
 * EN: Builds portable and unique slugs for imported Confluence pages.
 */
final class ConfluencePageSlugger
{
    /** HR: Duljina stupca workspace_nodes.slug. EN: Length of the workspace_nodes.slug column. */
    public const MAX_LENGTH = 128;

    /**
     * HR: Vraća prvi slobodan slug bez prekoračenja prenosive sheme.
     * EN: Returns the first available slug without exceeding the portable schema.
     *
     * @param array<string,true> $used
     */
    public function unique(string $baseSlug, string $fallback, array &$used): string
    {
        $base = $this->shorten(trim($baseSlug) !== '' ? $baseSlug : $fallback);
        if ($base === '') {
            $base = 'page';
        }

        $candidate = $base;
        $counter = 2;
        while (isset($used[$candidate])) {
            $suffix = '-' . $counter++;
            $candidate = $this->shorten($base, self::MAX_LENGTH - strlen($suffix)) . $suffix;
        }

        $used[$candidate] = true;

        return $candidate;
    }

    /**
     * HR: Skraćuje ASCII slug na granici riječi kada se time ne gubi previše čitljivosti.
     * EN: Shortens an ASCII slug at a word boundary when that preserves useful readability.
     */
    public function shorten(string $slug, int $maximumLength = self::MAX_LENGTH): string
    {
        $slug = trim($slug, '-');
        if ($maximumLength < 1 || strlen($slug) <= $maximumLength) {
            return $maximumLength < 1 ? '' : $slug;
        }

        $short = rtrim(substr($slug, 0, $maximumLength), '-');
        $boundary = strrpos($short, '-');
        if ($boundary !== false && $boundary >= (int)($maximumLength * 0.75)) {
            $short = rtrim(substr($short, 0, $boundary), '-');
        }

        return $short !== '' ? $short : substr($slug, 0, $maximumLength);
    }
}
