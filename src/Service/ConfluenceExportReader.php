<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use AaiEduHr\SimbiozaModuleConfluenceImport\Value\ConfluenceObject;
use DOMDocument;
use DOMElement;
use XMLReader;

use function is_string;
use function trim;
use function unlink;

/** HR: Stream parsira Hibernate XML bez učitavanja cijelog exporta u memoriju. EN: Stream-parses Hibernate XML without loading the complete export into memory. */
final readonly class ConfluenceExportReader
{
    /** HR: Prima sigurni ZIP čitač. EN: Receives the safe ZIP reader. */
    public function __construct(private ConfluenceArchive $archive)
    {
    }

    /**
     * HR: Generira normalizirane objekte; privremeni XML briše i kod prekida petlje.
     * EN: Yields normalized objects; the temporary XML is deleted even when iteration stops early.
     *
     * @return \Generator<int,ConfluenceObject>
     */
    public function objects(string $archivePath): \Generator
    {
        $entitiesPath = $this->archive->entitiesFile($archivePath);
        $reader = new XMLReader();
        try {
            if (!$reader->open($entitiesPath, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_BIGLINES)) {
                throw new ConfluenceImportException(__('Confluence XML parser nije moguće pokrenuti.'));
            }

            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'object') {
                    continue;
                }

                $xml = $reader->readOuterXml();
                if (!is_string($xml) || $xml === '') {
                    continue;
                }

                yield $this->parseObject($xml);
            }
        } finally {
            $reader->close();
            @unlink($entitiesPath);
        }
    }

    /** HR: Pretvara jedan object element u zatvoreni normalizirani model. EN: Converts one object element into a closed normalized model. */
    private function parseObject(string $xml): ConfluenceObject
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_BIGLINES);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $document->documentElement;
        if (!$loaded || !$root instanceof DOMElement) {
            throw new ConfluenceImportException(__('Confluence XML objekt nije valjan.'));
        }

        $values = [];
        foreach ($root->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            if ($child->tagName === 'id') {
                $values['id'] = trim($child->textContent);
                continue;
            }

            if ($child->tagName === 'collection') {
                $name = trim($child->getAttribute('name'));
                $references = [];
                foreach ($child->getElementsByTagName('id') as $id) {
                    $reference = trim($id->textContent);
                    if ($reference !== '') {
                        $references[] = $reference;
                    }
                }
                if ($name !== '') {
                    $values[$name . '_refs'] = $references;
                }
                continue;
            }

            if ($child->tagName !== 'property') {
                continue;
            }

            $name = trim($child->getAttribute('name'));
            if ($name === '') {
                continue;
            }

            $references = [];
            foreach ($child->getElementsByTagName('id') as $id) {
                $reference = trim($id->textContent);
                if ($reference !== '') {
                    $references[] = $reference;
                }
            }

            if ($references !== []) {
                if ($child->getElementsByTagName('collection')->length > 0 || count($references) > 1) {
                    $values[$name . '_refs'] = $references;
                } else {
                    $values[$name . '_ref'] = $references[0];
                }
                continue;
            }

            $values[$name] = trim($child->textContent);
        }

        return new ConfluenceObject(
            trim($root->getAttribute('class')),
            trim($root->getAttribute('package')),
            $values,
        );
    }
}
