<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Value;

/** HR: Prenosi HTML, poveznice, privitke i upozorenja nastala pretvorbom. EN: Carries HTML, links, attachments, and conversion warnings. */
final readonly class ConvertedConfluenceBody
{
    /**
     * HR: Sprema rezultat jedne pretvorbe bez dodatne obrade.
     * EN: Stores one conversion result without further processing.
     *
     * @param list<array<string,string>> $links
     * @param list<array<string,string>> $attachments
     * @param list<string> $unsupportedMacros
     */
    public function __construct(
        public string $html,
        public array $links,
        public array $attachments,
        public array $unsupportedMacros,
    ) {
    }
}
