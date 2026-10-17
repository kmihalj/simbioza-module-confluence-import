<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\SimbiozaModuleConfluenceImport\Value\ConfluenceObject;

/**
 * EN: Reads only the metadata needed for a large archive inventory; no body or credential is retained.
 *
 * HR: Čita samo metapodatke potrebne pregledu velike arhive; ne zadržava tijela ni vjerodajnice.
 */
final readonly class ConfluenceInventoryReader
{
    /**
     * EN: Reuses the normal object model and parser without changing the single-space reader.
     *
     * HR: Koristi isti model i parser objekta bez promjene čitača jednog područja.
     */
    public function __construct(
        private ConfluenceArchive $archive,
        private ConfluenceExportReader $reader,
        private ConfluenceImportConfig $config,
    ) {
    }

    /**
     * EN: Unselected classes carry only their type so counters cannot leak identity/plugin secrets.
     *
     * HR: Neodabrane klase nose samo vrstu pa brojači ne mogu otkriti tajne identiteta/dodataka.
     *
     * @return \Generator<int,ConfluenceObject>
     */
    public function objects(string $archivePath): \Generator
    {
        $framer = new ConfluenceXmlInventoryFramer($this->config->maxInventoryObjectSize());
        foreach ($framer->frames($this->archive->entityChunks($archivePath)) as $frame) {
            yield $frame['xml'] !== null
                ? $this->reader->parseObject($frame['xml'])
                : new ConfluenceObject($frame['class'], $frame['package'], []);
        }
    }
}
