<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\SimbiozaModuleConfluenceImport\Value\ConvertedConfluenceBody;
use DOMDocument;
use DOMElement;
use DOMNameSpaceNode;
use DOMNode;
use DOMXPath;

use function array_values;
use function htmlspecialchars;
use function is_scalar;
use function is_string;
use function json_encode;
use function parse_str;
use function parse_url;
use function preg_match;
use function rawurldecode;
use function str_replace;
use function strtolower;
use function trim;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * HR: Pretvara Confluence storage-format u siguran, razumljiv HTML uz očuvanje neriješenih referenci.
 * EN: Converts Confluence storage format into safe, understandable HTML while preserving unresolved references.
 */
final readonly class ConfluenceHtmlConverter
{
    private const AC_NAMESPACE = 'http://atlassian.com/content';

    private const RI_NAMESPACE = 'http://atlassian.com/resource/identifier';

    private const LINK_PREFIX = '__SIMBIOZA_CONFLUENCE_LINK__';

    private const ATTACHMENT_PREFIX = '__SIMBIOZA_CONFLUENCE_ATTACHMENT__';

    /**
     * HR: Pretvara jedan BodyContent i vraća HTML te strojno čitljive reference.
     * EN: Converts one BodyContent and returns HTML plus machine-readable references.
     */
    public function convert(string $storageFormat, string $sourceSpaceKey, string $sourcePageId): ConvertedConfluenceBody
    {
        if (trim($storageFormat) === '') {
            return new ConvertedConfluenceBody('<p></p>', [], [], []);
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml version="1.0" encoding="UTF-8"?><div xmlns:ac="' . self::AC_NAMESPACE
            . '" xmlns:ri="' . self::RI_NAMESPACE . '">' . $storageFormat . '</div>';
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML(
                $wrapped,
                LIBXML_NONET | LIBXML_COMPACT | LIBXML_BIGLINES | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$loaded || !$document->documentElement instanceof DOMElement) {
            return new ConvertedConfluenceBody(
                '<div class="alert alert-warning">'
                    . htmlspecialchars(__('Confluence sadržaj nije moguće potpuno pretvoriti.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '</div><pre>' . htmlspecialchars($storageFormat, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>',
                [],
                [],
                ['invalid-storage-format'],
            );
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('ac', self::AC_NAMESPACE);
        $xpath->registerNamespace('ri', self::RI_NAMESPACE);
        $links = [];
        $attachments = [];
        $unsupported = [];

        foreach ($this->elements($xpath->query('//ac:image')) as $image) {
            $attachment = $this->firstElement($xpath->query('.//ri:attachment', $image));
            if (!$attachment instanceof DOMElement) {
                continue;
            }

            $filename = $this->attribute($attachment, self::RI_NAMESPACE, 'filename');
            if ($filename === '') {
                continue;
            }

            $reference = ['source_page_id' => $sourcePageId, 'filename' => $filename, 'kind' => 'image'];
            $attachments[] = $reference;
            $replacement = $document->createElement('img');
            $replacement->setAttribute('src', self::ATTACHMENT_PREFIX . $this->token($reference));
            $replacement->setAttribute('alt', $filename);
            $replacement->setAttribute('class', 'img-fluid');
            $image->parentNode?->replaceChild($replacement, $image);
        }

        foreach ($this->elements($xpath->query('//ac:link')) as $link) {
            $page = $this->firstElement($xpath->query('.//ri:page', $link));
            $attachment = $this->firstElement($xpath->query('.//ri:attachment', $link));
            $url = $this->firstElement($xpath->query('.//ri:url', $link));
            $label = $this->linkLabel($xpath, $link);
            $replacement = $document->createElement('a');
            $replacement->appendChild($document->createTextNode($label));

            if ($page instanceof DOMElement) {
                $reference = [
                    'source_page_id' => $sourcePageId,
                    'source_space_key' => $sourceSpaceKey,
                    'destination_space_key' => $this->attribute($page, self::RI_NAMESPACE, 'space-key') ?: $sourceSpaceKey,
                    'destination_page_id' => $this->attribute($page, self::RI_NAMESPACE, 'content-id'),
                    'destination_page_title' => $this->attribute($page, self::RI_NAMESPACE, 'content-title'),
                    'original_target' => '',
                ];
                $links[] = $reference;
                $replacement->setAttribute('href', self::LINK_PREFIX . $this->token($reference));
            } elseif ($attachment instanceof DOMElement) {
                $filename = $this->attribute($attachment, self::RI_NAMESPACE, 'filename');
                $reference = ['source_page_id' => $sourcePageId, 'filename' => $filename, 'kind' => 'file'];
                $attachments[] = $reference;
                $replacement->setAttribute('href', self::ATTACHMENT_PREFIX . $this->token($reference));
            } elseif ($url instanceof DOMElement) {
                $replacement->setAttribute('href', $this->attribute($url, self::RI_NAMESPACE, 'value'));
            } else {
                $replacement->setAttribute('href', '#');
            }

            $link->parentNode?->replaceChild($replacement, $link);
        }

        // HR: Confluence često sprema interne poveznice kao obične apsolutne
        // URL-ove umjesto ac:link/ri:page elemenata. Pretvaramo samo poznate
        // Confluence obrasce; ostale vanjske URL-ove ostavljamo netaknutima.
        // EN: Confluence often stores internal links as ordinary absolute URLs
        // rather than ac:link/ri:page elements. Only known Confluence patterns
        // are converted; unrelated external URLs remain untouched.
        foreach ($this->elements($xpath->query('//a[@href]')) as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            $attachmentReference = $this->plainAttachmentReference($href);
            if ($attachmentReference !== null) {
                $attachments[] = $attachmentReference;
                $anchor->setAttribute('href', self::ATTACHMENT_PREFIX . $this->token($attachmentReference));
                continue;
            }

            $reference = $this->plainPageReference($href, $sourceSpaceKey, $sourcePageId);
            if ($reference !== null) {
                $links[] = $reference;
                $anchor->setAttribute('href', self::LINK_PREFIX . $this->token($reference));
            }
        }

        foreach ($this->elements($xpath->query('//img[@src]')) as $image) {
            $reference = $this->plainAttachmentReference(trim($image->getAttribute('src')));
            if ($reference === null) {
                continue;
            }

            $reference['kind'] = 'image';
            $attachments[] = $reference;
            $image->setAttribute('src', self::ATTACHMENT_PREFIX . $this->token($reference));
            if (!$image->hasAttribute('alt')) {
                $image->setAttribute('alt', $reference['filename']);
            }
            $image->setAttribute('class', trim($image->getAttribute('class') . ' img-fluid'));
        }

        foreach ($this->elements($xpath->query('//ac:task-list')) as $taskList) {
            $replacement = $document->createElement('ul');
            $replacement->setAttribute('class', 'list-unstyled confluence-task-list');
            foreach ($this->elements($xpath->query('.//ac:task', $taskList)) as $task) {
                $status = strtolower(trim($this->nodeText($xpath, './/ac:task-status', $task)));
                $body = $this->nodeText($xpath, './/ac:task-body', $task);
                $item = $document->createElement('li');
                $checkbox = $document->createElement('input');
                $checkbox->setAttribute('type', 'checkbox');
                $checkbox->setAttribute('disabled', 'disabled');
                if ($status === 'complete') {
                    $checkbox->setAttribute('checked', 'checked');
                }
                $item->appendChild($checkbox);
                $item->appendChild($document->createTextNode(' ' . $body));
                $replacement->appendChild($item);
            }
            $taskList->parentNode?->replaceChild($replacement, $taskList);
        }

        foreach ($this->elements($xpath->query('//ac:structured-macro')) as $macro) {
            $name = strtolower($this->attribute($macro, self::AC_NAMESPACE, 'name'));
            $replacement = $this->macroReplacement($document, $xpath, $macro, $name, $unsupported);
            $macro->parentNode?->replaceChild($replacement, $macro);
        }

        foreach ($this->elements($xpath->query('//ac:emoticon')) as $emoticon) {
            $name = $this->attribute($emoticon, self::AC_NAMESPACE, 'name');
            $emoticon->parentNode?->replaceChild($document->createTextNode($this->emoticon($name)), $emoticon);
        }

        return new ConvertedConfluenceBody(
            $this->innerHtml($document, $document->documentElement),
            $links,
            $attachments,
            array_values(array_unique($unsupported)),
        );
    }

    /**
     * HR: Pretvara podržani makro u siguran statički HTML element.
     * EN: Converts a supported macro into a safe static HTML element.
     *
     * @param list<string> $unsupported
     */
    private function macroReplacement(
        DOMDocument $document,
        DOMXPath $xpath,
        DOMElement $macro,
        string $name,
        array &$unsupported,
    ): DOMElement {
        $plain = trim($this->nodeText($xpath, './/ac:plain-text-body', $macro));
        $rich = $this->firstElement($xpath->query('.//ac:rich-text-body', $macro));

        if (in_array($name, ['code', 'noformat'], true)) {
            $pre = $document->createElement('pre');
            $code = $document->createElement('code');
            $code->appendChild($document->createTextNode($plain));
            $pre->appendChild($code);
            return $pre;
        }

        if (in_array($name, ['info', 'note', 'tip', 'warning'], true)) {
            $class = match ($name) {
                'warning' => 'alert-warning',
                'tip' => 'alert-success',
                'note' => 'alert-secondary',
                default => 'alert-info',
            };
            $panel = $document->createElement('div');
            $panel->setAttribute('class', 'alert ' . $class);
            $this->appendRichBody($document, $panel, $rich, $plain);
            return $panel;
        }

        if ($name === 'toc') {
            $notice = $document->createElement('nav');
            $notice->setAttribute('class', 'confluence-import-toc text-body-secondary');
            $notice->setAttribute('aria-label', __('Sadržaj stranice'));
            $notice->appendChild($document->createTextNode(__('Sadržaj stranice')));
            return $notice;
        }

        $unsupported[] = $name !== '' ? $name : 'unknown';
        $box = $document->createElement('div');
        $box->setAttribute('class', 'alert alert-secondary confluence-import-macro');
        $title = $document->createElement('strong');
        $title->appendChild($document->createTextNode(sprintf(
            __('Confluence makro: %s'),
            $name !== '' ? $name : __('nepoznat'),
        )));
        $box->appendChild($title);
        $this->appendRichBody($document, $box, $rich, $plain);
        return $box;
    }

    /** HR: Dodaje bogato ili obično tijelo u zamjenski element. EN: Appends a rich or plain body to a replacement element. */
    private function appendRichBody(DOMDocument $document, DOMElement $target, ?DOMElement $rich, string $plain): void
    {
        if ($rich instanceof DOMElement) {
            foreach (iterator_to_array($rich->childNodes) as $child) {
                if ($child instanceof DOMNode) {
                    $target->appendChild($document->importNode($child, true));
                }
            }
            return;
        }

        if ($plain !== '') {
            $paragraph = $document->createElement('p');
            $paragraph->appendChild($document->createTextNode($plain));
            $target->appendChild($paragraph);
        }
    }

    /** HR: Određuje čitljiv tekst Confluence poveznice. EN: Determines a readable Confluence link label. */
    private function linkLabel(DOMXPath $xpath, DOMElement $link): string
    {
        $label = trim($this->nodeText($xpath, './/ac:plain-text-link-body', $link));
        if ($label !== '') {
            return $label;
        }

        $page = $this->firstElement($xpath->query('.//ri:page', $link));
        if ($page instanceof DOMElement) {
            return $this->attribute($page, self::RI_NAMESPACE, 'content-title') ?: __('Povezana stranica');
        }

        $attachment = $this->firstElement($xpath->query('.//ri:attachment', $link));
        if ($attachment instanceof DOMElement) {
            return $this->attribute($attachment, self::RI_NAMESPACE, 'filename') ?: __('Privitak');
        }

        return trim($link->textContent) ?: __('Poveznica');
    }

    /** HR: Čita imenovani atribut uz namespace i legacy fallback. EN: Reads a named attribute with namespace and legacy fallback. */
    private function attribute(DOMElement $element, string $namespace, string $localName): string
    {
        $value = trim($element->getAttributeNS($namespace, $localName));
        if ($value !== '') {
            return $value;
        }

        return trim($element->getAttribute(($namespace === self::AC_NAMESPACE ? 'ac:' : 'ri:') . $localName));
    }

    /**
     * HR: Pretvara XPath rezultat u popis DOM elemenata.
     * EN: Converts an XPath result into a list of DOM elements.
     *
     * @param \DOMNodeList<DOMNameSpaceNode|DOMNode>|false $nodes
     * @return list<DOMElement>
     */
    private function elements(\DOMNodeList|false $nodes): array
    {
        if ($nodes === false) {
            return [];
        }

        $result = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $result[] = $node;
            }
        }
        return $result;
    }

    /**
     * HR: Vraća prvi DOM element iz XPath rezultata.
     * EN: Returns the first DOM element from an XPath result.
     *
     * @param \DOMNodeList<DOMNameSpaceNode|DOMNode>|false $nodes
     */
    private function firstElement(\DOMNodeList|false $nodes): ?DOMElement
    {
        foreach ($this->elements($nodes) as $node) {
            return $node;
        }

        return null;
    }

    /** HR: Čita tekst prvog čvora zadanog XPath upita. EN: Reads text from the first node of an XPath query. */
    private function nodeText(DOMXPath $xpath, string $query, DOMElement $context): string
    {
        $nodes = $xpath->query($query, $context);
        $node = $nodes !== false ? $nodes->item(0) : null;

        return $node instanceof DOMNode ? trim($node->textContent) : '';
    }

    /** HR: Serijalizira samo unutarnji HTML korijena. EN: Serializes only the root element's inner HTML. */
    private function innerHtml(DOMDocument $document, DOMElement $root): string
    {
        $html = '';
        foreach ($root->childNodes as $child) {
            $saved = $document->saveHTML($child);
            if (is_string($saved)) {
                $html .= $saved;
            }
        }

        return trim($html) !== '' ? trim($html) : '<p></p>';
    }

    /**
     * HR: Prepoznaje moderne, legacy i pageId Confluence URL-ove.
     * EN: Recognizes modern, legacy, and pageId Confluence URLs.
     *
     * @return array<string,string>|null
     */
    private function plainPageReference(string $href, string $sourceSpaceKey, string $sourcePageId): ?array
    {
        $path = parse_url($href, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        $destinationSpace = '';
        $destinationId = '';
        $destinationTitle = '';
        if (preg_match('~/spaces/([^/]+)/pages/([0-9]+)(?:/([^/?#]+))?~iu', $path, $match) === 1) {
            $destinationSpace = rawurldecode($match[1]);
            $destinationId = $match[2];
            $destinationTitle = $this->decodedUrlTitle($match[3] ?? '');
        } elseif (preg_match('~/display/([^/]+)/(.+)$~iu', $path, $match) === 1) {
            $destinationSpace = rawurldecode($match[1]);
            $destinationTitle = $this->decodedUrlTitle($match[2]);
        } elseif (preg_match('~/pages/viewpage\.action$~iu', $path) === 1) {
            $query = parse_url($href, PHP_URL_QUERY);
            $parameters = [];
            parse_str(is_string($query) ? $query : '', $parameters);
            $destinationId = is_scalar($parameters['pageId'] ?? null)
                ? trim((string)$parameters['pageId'])
                : '';
        } else {
            return null;
        }

        if ($destinationId === '' && $destinationTitle === '') {
            return null;
        }

        $fragment = parse_url($href, PHP_URL_FRAGMENT);

        return [
            'source_page_id' => $sourcePageId,
            'source_space_key' => $sourceSpaceKey,
            'destination_space_key' => $destinationSpace,
            'destination_page_id' => $destinationId,
            'destination_page_title' => $destinationTitle,
            'fragment' => is_string($fragment) ? trim($fragment) : '',
            'original_target' => $href,
        ];
    }

    /**
     * HR: Prepoznaje legacy URL privitka.
     * EN: Recognizes a legacy attachment URL.
     *
     * @return array<string,string>|null
     */
    private function plainAttachmentReference(string $href): ?array
    {
        $path = parse_url($href, PHP_URL_PATH);
        if (
            !is_string($path)
            || preg_match('~/download/attachments/([0-9]+)/([^/?#]+)$~iu', $path, $match) !== 1
        ) {
            return null;
        }

        $filename = rawurldecode($match[2]);
        if ($filename === '') {
            return null;
        }

        return [
            'source_page_id' => $match[1],
            'filename' => $filename,
            'kind' => 'file',
        ];
    }

    /** HR: Dekodira naslov zapisan u URL-u. EN: Decodes a title stored in a URL. */
    private function decodedUrlTitle(string $value): string
    {
        return trim(rawurldecode(str_replace('+', ' ', $value)));
    }

    /**
     * HR: Kodira sigurni privremeni token poveznice.
     * EN: Encodes a safe temporary link token.
     *
     * @param array<string,string> $reference
     */
    private function token(array $reference): string
    {
        return rawurlencode(base64_encode(json_encode(
            $reference,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )));
    }

    /** HR: Pretvara Confluence emotikon u Unicode znak. EN: Converts a Confluence emoticon into a Unicode character. */
    private function emoticon(string $name): string
    {
        return match (strtolower(trim($name))) {
            'smile' => '🙂',
            'sad' => '🙁',
            'cheeky' => '😉',
            'thumbs-up' => '👍',
            'thumbs-down' => '👎',
            'information' => 'ℹ️',
            'warning' => '⚠️',
            default => '•',
        };
    }
}
