<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Value;

/** HR: Prenosi HTML, reference i strojno čitljive stavke za ručnu provjeru. EN: Carries HTML, references, and machine-readable manual-review items. */
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
     * @param list<array<string,mixed>> $reviewIssues
     */
    public function __construct(
        public string $html,
        public array $links,
        public array $attachments,
        public array $unsupportedMacros,
        public array $includes = [],
        public array $properties = [],
        public array $reviewIssues = [],
    ) {
    }
}
