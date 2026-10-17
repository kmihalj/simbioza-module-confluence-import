<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

/**
 * EN: Converts inert source presentation metadata; it never assigns ownership or access.
 * The named palette is published by Atlassian in CONFSERVER-90801.
 *
 * HR: Pretvara neizvršive metapodatke prikaza izvora; ne dodjeljuje vlasništvo ni pristup.
 * Imenovanu paletu objavio je Atlassian u CONFSERVER-90801.
 */
final class ConfluenceCalendarPresentation
{
    private const COLORS = [
        'gray' => '#091E42', 'green2' => '#00875A', 'purple' => '#5243AA',
        'green3' => '#36B37E', 'yellow' => '#FFC400', 'orange2' => '#FF5630',
        'turquoise' => '#00A3BF', 'green' => '#006644', 'bronze' => '#FFAB00',
        'pink' => '#DE350B', 'purple2' => '#403294', 'blue' => '#0052CC',
        'blue2' => '#0049B0', 'gray2' => '#0065FF', 'purple3' => '#253858',
        'green4' => '#008DA6', 'red' => '#BF2600', 'purple4' => '#8777D9',
        'green5' => '#00B8D9', 'green6' => '#42526E', 'orange' => '#FF8B00',
    ];

    /**
     * EN: Accepts a strict HEX value or an exact known palette token, never CSS or arbitrary color syntax.
     *
     * HR: Prihvaća strogi HEX ili točnu poznatu oznaku palete, nikad CSS ni proizvoljnu sintaksu boje.
     */
    public static function color(string $source): ?string
    {
        $source = trim($source);
        if (preg_match('/^#[a-f0-9]{6}$/iD', $source) === 1) {
            return strtoupper($source);
        }
        return str_starts_with($source, 'subcalendar-') ? self::COLORS[substr($source, 12)] ?? null : null;
    }

    /**
     * EN: Official/private names and personal-space association suggest personal, not team.
     * This is only a review default: a mapped owner and explicit validated choice remain mandatory.
     * Ambiguous custom/project names have no invented team default.
     *
     * HR: Službeni/privatni nazivi i veza s osobnim područjem predlažu osobni, ne timski kalendar.
     * To je samo prijedlog pregleda: mapirani vlasnik i izričit valjani odabir ostaju obavezni.
     * Nejasni prilagođeni/projektni nazivi ne dobivaju izmišljenu zadanu timsku vrstu.
     *
     * @param array<string,mixed> $calendar
     */
    public static function suggestedType(array $calendar): string
    {
        $name = mb_strtolower((string)($calendar['name'] ?? ''), 'UTF-8');
        if (preg_match('/\b(?:služben\p{L}*|sluzben\p{L}*|privatn\p{L}*|osobn\p{L}*|official|private|personal)\b/u', $name) === 1) {
            return 'personal';
        }
        foreach ($calendar['space_keys'] ?? [] as $key) {
            if (is_string($key) && str_starts_with($key, '~')) {
                return 'personal';
            }
        }
        return '';
    }
}
