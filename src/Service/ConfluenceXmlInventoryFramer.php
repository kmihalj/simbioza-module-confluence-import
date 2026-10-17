<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use DOMDocument;

/**
 * EN: Frames Hibernate objects while discarding unselected payloads before DOM parsing.
 * CDATA and comments are lexical boundaries: apparent objects inside them are never records.
 * This is an inventory reader, not a content converter or a generic XML deserializer.
 *
 * HR: Odvaja Hibernate objekte i odbacuje neodabrane podatke prije DOM parsiranja.
 * CDATA i komentari granice su zapisa: prividni objekti unutar njih nikada nisu zapisi.
 * Ovo je čitač pregleda, ne pretvarač sadržaja ni opći XML deserijalizator.
 */
final readonly class ConfluenceXmlInventoryFramer
{
    private const METADATA_CLASSES = ['Space', 'Page', 'Attachment', 'BlogPost', 'Comment'];

    /**
     * EN: Bounds the only object payloads retained in memory.
     *
     * HR: Ograničava jedine sadržaje objekata koji se zadržavaju u memoriji.
     *
     * @param list<string> $metadataClasses
     */
    public function __construct(private int $maxObjectSize, private array $metadataClasses = self::METADATA_CLASSES)
    {
    }

    /**
     * EN: Validates the envelope and every selected object; never expands a DTD or an entity.
     * Skipped plugin/identity/body payloads are not deserialized or persisted.
     *
     * HR: Provjerava omotnicu i svaki odabrani objekt; nikada ne razvija DTD ni entitet.
     * Preskočeni podaci dodataka/identiteta/tijela ne deserijaliziraju se niti spremaju.
     *
     * @param iterable<string> $chunks
     * @return \Generator<int,array{class:string,package:string,xml:?string}>
     */
    public function frames(iterable $chunks): \Generator
    {
        $buffer = '';
        $opened = false;
        $closed = false;
        $class = null;
        $package = '';
        $fragment = '';
        $capture = false;
        $literal = null;
        foreach ($chunks as $chunk) {
            $buffer .= $chunk;
            $position = 0;
            $length = strlen($buffer);
            if (!$opened) {
                $root = strpos($buffer, '<hibernate-generic');
                $end = $root !== false ? strpos($buffer, '>', $root) : false;
                if ($root === false || $end === false) {
                    if ($length > 65536) {
                        $this->invalid();
                    }
                    continue;
                }
                $prefix = substr($buffer, 0, $root);
                $prefix = preg_replace('~<!--(?:(?!-->).)*-->~s', '', $prefix);
                if (!is_string($prefix) || preg_match('~\A(?:\xEF\xBB\xBF)?\s*(?:<\?xml[^?]*\?>\s*)?\z~D', $prefix) !== 1) {
                    $this->invalid();
                }
                $header = substr($buffer, $root, $end + 1 - $root);
                $closed = str_ends_with($header, '/>');
                $this->element($header . ($closed ? '' : '</hibernate-generic>'), 'hibernate-generic');
                $opened = true;
                $position = $end + 1;
            }
            while ($position < $length) {
                if ($literal !== null) {
                    $delimiter = $literal === 'cdata' ? ']]>' : '-->';
                    $end = strpos($buffer, $delimiter, $position);
                    if ($end === false) {
                        $end = max($position, $length - 2);
                        $this->append($fragment, $capture, $buffer, $position, $end - $position);
                        $position = $end;
                        break;
                    }
                    $this->append($fragment, $capture, $buffer, $position, $end + 3 - $position);
                    $position = $end + 3;
                    $literal = null;
                    continue;
                }
                if ($class === null) {
                    $position += strspn($buffer, " \t\r\n", $position);
                    if ($position === $length) {
                        break;
                    }
                    $rest = substr($buffer, $position, 64);
                    if ($closed) {
                        $this->invalid();
                    }
                    if (str_starts_with($rest, '<!--')) {
                        $literal = 'comment';
                        $position += 4;
                        continue;
                    }
                    if (str_starts_with($rest, '</hibernate-generic>')) {
                        $closed = true;
                        $position += strlen('</hibernate-generic>');
                        continue;
                    }
                    if (strlen($rest) < 22 && !str_starts_with($rest, '<object ')) {
                        break;
                    }
                    if (preg_match('~\A<object\s~', $rest) !== 1) {
                        $this->invalid();
                    }
                    $end = strpos($buffer, '>', $position);
                    if ($end === false) {
                        if ($length - $position > 65536) {
                            $this->invalid();
                        }
                        break;
                    }
                    $header = substr($buffer, $position, $end + 1 - $position);
                    if (strlen($header) > 65536) {
                        $this->invalid();
                    }
                    $selfClosing = str_ends_with($header, '/>');
                    if (preg_match('~\A<object\s+class="([A-Za-z_][A-Za-z0-9_.]*)"(?:\s+package="([A-Za-z_][A-Za-z0-9_.]*)")?\s*/?>\z~D', $header, $match) === 1) {
                        $class = $match[1];
                        $package = $match[2] ?? '';
                    } else {
                        $element = $this->element($header . ($selfClosing ? '' : '</object>'), 'object');
                        $class = trim($element->getAttribute('class'));
                        $package = trim($element->getAttribute('package'));
                    }
                    if ($class === '' || strlen($class) > 190 || strlen($package) > 255) {
                        $this->invalid();
                    }
                    $capture = in_array($class, $this->metadataClasses, true);
                    $fragment = $capture ? $header : '';
                    $position = $end + 1;
                    if ($selfClosing) {
                        yield ['class' => $class, 'package' => $package, 'xml' => $capture ? $fragment : null];
                        $class = null;
                        $fragment = '';
                        $capture = false;
                    }
                    continue;
                }
                $tokens = [];
                foreach (['</object>' => 'end', '<!' => 'declaration'] as $needle => $type) {
                    $at = strpos($buffer, $needle, $position);
                    if ($at !== false) {
                        $tokens[$at] = $type;
                    }
                }
                if ($tokens === []) {
                    $end = max($position, $length - 16);
                    $this->append($fragment, $capture, $buffer, $position, $end - $position);
                    $position = $end;
                    break;
                }
                ksort($tokens);
                $at = array_key_first($tokens);
                $token = $tokens[$at];
                $segment = substr($buffer, $position, $at - $position);
                if (str_contains($segment, '<?') || str_contains($segment, '<object')) {
                    $this->invalid();
                }
                if ($token === 'declaration') {
                    $declaration = substr($buffer, $at, 9);
                    if (str_starts_with($declaration, '<![CDATA[')) {
                        $token = 'cdata';
                    } elseif (str_starts_with($declaration, '<!--')) {
                        $token = 'comment';
                    } elseif (strlen($declaration) < 9) {
                        $this->append($fragment, $capture, $buffer, $position, $at - $position);
                        $position = $at;
                        break;
                    }
                }
                if (!in_array($token, ['end', 'cdata', 'comment'], true)) {
                    $this->invalid();
                }
                $size = $token === 'comment' ? 4 : 9;
                $this->append($fragment, $capture, $buffer, $position, $at + $size - $position);
                $position = $at + $size;
                if ($token === 'end') {
                    yield ['class' => $class, 'package' => $package, 'xml' => $capture ? $fragment : null];
                    $class = null;
                    $fragment = '';
                    $capture = false;
                } else {
                    $literal = $token;
                }
            }
            $buffer = substr($buffer, $position);
        }
        if (!$opened || !$closed || $class !== null || $literal !== null || trim($buffer) !== '') {
            $this->invalid();
        }
    }

    /**
     * EN: Appends only selected metadata and rejects oversized records before allocating them.
     *
     * HR: Dodaje samo odabrane metapodatke i odbija prevelike zapise prije njihove alokacije.
     */
    private function append(string &$fragment, bool $capture, string $buffer, int $offset, int $length): void
    {
        if (!$capture) {
            return;
        }
        if (strlen($fragment) + $length > $this->maxObjectSize) {
            throw new ConfluenceImportException(__('Confluence objekt metapodataka je prevelik.'));
        }
        $fragment .= substr($buffer, $offset, $length);
    }

    /**
     * EN: Parses a closed metadata element with external resolution disabled.
     *
     * HR: Parsira zatvoreni element metapodataka uz isključeno vanjsko razrješavanje.
     */
    private function element(string $xml, string $name): \DOMElement
    {
        $document = new DOMDocument();
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded || $document->doctype instanceof \DOMDocumentType || $document->documentElement?->tagName !== $name) {
            $this->invalid();
        }
        return $document->documentElement;
    }

    /**
     * EN: Reports structural errors without exposing the XML or secret values.
     *
     * HR: Prijavljuje strukturne greške bez otkrivanja XML-a ili tajnih vrijednosti.
     */
    private function invalid(): never
    {
        throw new ConfluenceImportException(__('Confluence XML omotnica nije valjana ili sadrži zabranjenu deklaraciju.'));
    }
}
