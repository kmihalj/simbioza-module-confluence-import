<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Value;

/** HR: Prenosi HTML, poveznice, privitke, svojstva stranice, uključene stranice i upozorenja pretvorbe. EN: Carries HTML, links, attachments, page properties, included pages, and conversion warnings. */
final readonly class ConvertedConfluenceBody
{
    /**
     * HR: Sprema rezultat jedne pretvorbe bez dodatne obrade.
     * EN: Stores one conversion result without further processing.
     *
     * @param list<array<string,string>> $links
     * @param list<array<string,string>> $attachments
     * @param list<string> $unsupportedMacros
     * @param list<array<string,string>> $includes
     * @param list<array{key:string,label:string,type:string,value:string,sort_order:int}> $properties
     */
    public function __construct(
        public string $html,
        public array $links,
        public array $attachments,
        public array $unsupportedMacros,
        public array $includes = [],
        public array $properties = [],
    ) {
    }
}
