<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorHtmlChartService;
use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorHtmlRoadmapService;
use AaiEduHr\SimbiozaModuleConfluenceImport\Value\ConvertedConfluenceBody;
use AaiEduHr\SimbiozaModuleConfluenceImport\Value\ConfluenceMacroContext;
use DOMDocument;
use DOMElement;
use DOMNameSpaceNode;
use DOMNode;
use DOMXPath;
use DateTimeImmutable;

use function array_filter;
use function array_map;
use function array_shift;
use function array_slice;
use function array_unique;
use function array_values;
use function base64_encode;
use function basename;
use function ceil;
use function count;
use function html_entity_decode;
use function htmlspecialchars;
use function in_array;
use function is_scalar;
use function is_array;
use function is_numeric;
use function is_string;
use function json_encode;
use function parse_str;
use function parse_url;
use function preg_match;
use function preg_replace;
use function preg_quote;
use function preg_replace_callback;
use function preg_split;
use function range;
use function rawurldecode;
use function str_replace;
use function str_starts_with;
use function str_ends_with;
use function substr;
use function strtolower;
use function trim;
use function uasort;
use function usort;
use function max;

use const ENT_QUOTES;
use const ENT_HTML5;
use const ENT_SUBSTITUTE;
use const ENT_XML1;
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
     * HR: Prima nativne Editor renderere grafikona i vremenskog plana.
     * EN: Receives the native Editor chart and timeline-roadmap renderers.
     */
    public function __construct(
        private ?EditorHtmlChartService $charts = null,
        private ?EditorHtmlRoadmapService $roadmaps = null,
    ) {
    }

    /**
     * HR: Pretvara jedan BodyContent i vraća HTML te strojno čitljive reference.
     * EN: Converts one BodyContent and returns HTML plus machine-readable references.
     */
    public function convert(
        string $storageFormat,
        string $sourceSpaceKey,
        string $sourcePageId,
        ?ConfluenceMacroContext $macroContext = null,
    ): ConvertedConfluenceBody {
        if (trim($storageFormat) === '') {
            return new ConvertedConfluenceBody('<p></p>', [], [], []);
        }

        $storageFormat = $this->normalizeStorageFormat($storageFormat);

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
        $includes = [];
        $properties = [];

        foreach ($this->elements($xpath->query('//ac:image')) as $image) {
            $attachment = $this->firstElement($xpath->query('.//ri:attachment', $image));
            $replacement = $document->createElement('img');
            $filename = '';
            if ($attachment instanceof DOMElement) {
                $filename = $this->attribute($attachment, self::RI_NAMESPACE, 'filename');
                if ($filename === '') {
                    continue;
                }
                $reference = ['source_page_id' => $sourcePageId, 'filename' => $filename, 'kind' => 'image'];
                $attachments[] = $reference;
                $replacement->setAttribute('src', self::ATTACHMENT_PREFIX . $this->token($reference));
            } else {
                $url = $this->firstElement($xpath->query('.//ri:url', $image));
                $source = $url instanceof DOMElement
                    ? $this->attribute($url, self::RI_NAMESPACE, 'value')
                    : '';
                $reference = $this->plainAttachmentReference($source);
                if ($reference !== null) {
                    $reference['kind'] = 'image';
                    $filename = $reference['filename'];
                    $attachments[] = $reference;
                    $replacement->setAttribute('src', self::ATTACHMENT_PREFIX . $this->token($reference));
                } elseif ($this->isSafeRemoteUrl($source)) {
                    $filename = basename((string)parse_url($source, PHP_URL_PATH));
                    $replacement->setAttribute('src', $source);
                } else {
                    continue;
                }
            }

            $replacement->setAttribute('alt', $filename);
            $replacement->setAttribute('class', 'img-fluid');
            foreach (['width', 'height'] as $dimension) {
                $value = $this->attribute($image, self::AC_NAMESPACE, $dimension);
                if (preg_match('/^[1-9][0-9]*$/', $value) === 1) {
                    $replacement->setAttribute($dimension, $value);
                }
            }
            $image->parentNode?->replaceChild($replacement, $image);
        }

        foreach ($this->elements($xpath->query('//ac:link')) as $link) {
            // HR: Include makro sam preuzima svoju ri:page referencu. Kada bi
            //     je generički link-konverter prvi zamijenio, cilj bi se izgubio.
            // EN: The include macro owns its ri:page reference. Converting that
            //     nested link first would discard the include target.
            if ($this->isInsideMacro($link, 'include')) {
                continue;
            }

            $page = $this->firstElement($xpath->query('.//ri:page', $link));
            $attachment = $this->firstElement($xpath->query('.//ri:attachment', $link));
            $url = $this->firstElement($xpath->query('.//ri:url', $link));
            $user = $this->firstElement($xpath->query('.//ri:user', $link));

            if ($user instanceof DOMElement) {
                // HR: Confluence korisničke reference nemaju vidljiv tekst. Uvoz
                //     zato koristi mapirano ime, a izvorni identitet samo kao
                //     sigurni zamjenski prikaz kada korisnik još nije mapiran.
                // EN: Confluence user references have no visible text. The import
                //     therefore uses the mapped name, falling back to the source
                //     identity only when the user has not yet been mapped.
                $identity = $this->confluenceUserIdentity($user);
                $label = $macroContext instanceof ConfluenceMacroContext
                    ? ($macroContext->users[$identity] ?? $identity)
                    : $identity;
                $replacement = $document->createElement('span');
                $replacement->setAttribute('class', 'confluence-import-user');
                $replacement->appendChild($document->createTextNode(
                    $label !== '' ? $label : __('Korisnik'),
                ));
                $link->parentNode?->replaceChild($replacement, $link);
                continue;
            }

            $label = $this->linkLabel($xpath, $link);
            $anchor = $this->attribute($link, self::AC_NAMESPACE, 'anchor');
            $replacement = $document->createElement('a');
            $replacement->appendChild($document->createTextNode($label));

            if ($page instanceof DOMElement) {
                $reference = [
                    'source_page_id' => $sourcePageId,
                    'source_space_key' => $sourceSpaceKey,
                    'destination_space_key' => $this->attribute($page, self::RI_NAMESPACE, 'space-key') ?: $sourceSpaceKey,
                    'destination_page_id' => $this->attribute($page, self::RI_NAMESPACE, 'content-id'),
                    'destination_page_title' => $this->attribute($page, self::RI_NAMESPACE, 'content-title'),
                    'fragment' => $anchor,
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
            } elseif ($anchor !== '') {
                $replacement->setAttribute('href', '#' . $this->safeFragment($anchor));
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

        // HR: Unutarnji makroi moraju biti pretvoreni prije roditeljskih panela i rasporeda,
        //     dok makroi na istoj razini zadržavaju izvorni redoslijed.
        // EN: Nested macros must be converted before their parent panels and layouts,
        //     while same-level macros retain their source order.
        $macros = $this->elements($xpath->query('//ac:structured-macro'));
        usort($macros, fn(DOMElement $left, DOMElement $right): int =>
            $this->macroDepth($right) <=> $this->macroDepth($left));
        foreach ($macros as $macro) {
            $name = strtolower($this->attribute($macro, self::AC_NAMESPACE, 'name'));
            $replacement = $this->macroReplacement(
                $document,
                $xpath,
                $macro,
                $name,
                $unsupported,
                $attachments,
                $includes,
                $sourceSpaceKey,
                $sourcePageId,
                $macroContext,
                $properties,
            );
            $macro->parentNode?->replaceChild($replacement, $macro);
        }

        // HR: Confluence rasporedi nisu standardni HTML elementi. Nakon obrade
        // makroa pretvaraju se u Bootstrap retke i stupce kako bi dvostupčane i
        // trostupčane stranice ostale čitljive i prilagodljive manjim ekranima.
        // EN: Confluence layouts are not standard HTML elements. After macro
        // conversion they become Bootstrap rows and columns so two- and
        // three-column pages remain readable and responsive.
        foreach ($this->elements($xpath->query('//ac:layout')) as $layout) {
            $layout->parentNode?->replaceChild(
                $this->layoutReplacement($document, $xpath, $layout),
                $layout,
            );
        }

        foreach ($this->elements($xpath->query('//ac:emoticon')) as $emoticon) {
            $name = $this->attribute($emoticon, self::AC_NAMESPACE, 'name');
            $emoticon->parentNode?->replaceChild($document->createTextNode($this->emoticon($name)), $emoticon);
        }

        // HR: Sve preostale obične i iz makroa materijalizirane tablice koriste
        //     isti HTML ugovor kao tablice izrađene u Editoru. Time Theme modul
        //     upravlja zaglavljem, redcima, obrubima i hover stanjem, a široke
        //     tablice ostaju unutar sadržaja uz vodoravno pomicanje.
        // EN: Every remaining ordinary or macro-materialized table uses the
        //     same HTML contract as Editor-created tables. This lets the Theme
        //     module control headers, rows, borders, and hover state while wide
        //     tables stay inside the content area with horizontal scrolling.
        $this->normalizeTables($document, $document->documentElement);

        return new ConvertedConfluenceBody(
            $this->innerHtml($document, $document->documentElement),
            $links,
            array_values($attachments),
            array_values(array_unique($unsupported)),
            array_values($includes),
            $properties,
        );
    }

    /**
     * HR: Ispravlja poznate Confluence XML-export zapise koji nisu valjani XML fragmenti.
     *     Atlassianov izvoz razdvaja CDATA završetak kao `]] >`, a sadržaj koda
     *     može sadržavati i doslovni CDATA početak. Plain-text tijelo zato se
     *     najprije pretvara u običan escapirani XML tekst. HTML imenovani
     *     entiteti osim pet XML entiteta također nisu definirani u XML-u.
     * EN: Repairs known Confluence XML-export forms that are not valid XML
     *     fragments. Atlassian exports split a CDATA terminator as `]] >`, and
     *     code may contain a literal CDATA opener. Each plain-text body is first
     *     converted to ordinary escaped XML text. HTML named entities other
     *     than the five XML entities are also undefined in XML.
     */
    private function normalizeStorageFormat(string $storageFormat): string
    {
        $normalized = $storageFormat;
        foreach (['plain-text-link-body', 'plain-text-body'] as $tagName) {
            $tag = preg_quote($tagName, '~');
            $normalized = preg_replace_callback(
                '~(<ac:' . $tag . '\b[^>]*>)(.*?)(</ac:' . $tag . '>)~s',
                static function (array $match): string {
                    $plainText = $match[2];
                    if (str_starts_with($plainText, '<![CDATA[')) {
                        $plainText = substr($plainText, 9);
                        $plainText = preg_replace(
                            '/\]\]\s*(?:\]\s*)?>\s*$/',
                            '',
                            $plainText,
                        ) ?? $plainText;
                    }

                    return $match[1]
                        . htmlspecialchars(
                            $plainText,
                            ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1,
                            'UTF-8',
                        )
                        . $match[3];
                },
                $normalized,
            ) ?? $normalized;
        }

        return preg_replace_callback(
            '/&([A-Za-z][A-Za-z0-9]+);/',
            static function (array $match): string {
                if (in_array(strtolower($match[1]), ['amp', 'lt', 'gt', 'quot', 'apos'], true)) {
                    return $match[0];
                }

                $decoded = html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');

                // HR: Nepoznati entitet ostaje vidljiv kao tekst, ali više ne kvari XML.
                // EN: An unknown entity remains visible as text without breaking XML.
                return $decoded !== $match[0] ? $decoded : '&amp;' . $match[1] . ';';
            },
            $normalized,
        ) ?? $normalized;
    }

    /**
     * HR: Pretvara podržani makro u siguran statički HTML element.
     * EN: Converts a supported macro into a safe static HTML element.
     *
     * @param list<string> $unsupported
     * @param array<int,array<string,string>> $attachments
     * @param array<int,array<string,string>> $includes
     * @param list<array{key:string,label:string,type:string,value:string,sort_order:int}> $properties
     */
    private function macroReplacement(
        DOMDocument $document,
        DOMXPath $xpath,
        DOMElement $macro,
        string $name,
        array &$unsupported,
        array &$attachments,
        array &$includes,
        string $sourceSpaceKey,
        string $sourcePageId,
        ?ConfluenceMacroContext $context,
        array &$properties,
    ): DOMNode {
        $plain = trim($this->nodeText($xpath, './/ac:plain-text-body', $macro));
        $rich = $this->firstElement($xpath->query('.//ac:rich-text-body', $macro));

        if (in_array($name, ['code', 'noformat'], true)) {
            $pre = $document->createElement('pre');
            $pre->setAttribute('class', 'confluence-import-code');
            $code = $document->createElement('code');
            $language = strtolower($this->macroParameter($xpath, $macro, 'language'));
            if ($language !== '' && preg_match('/^[a-z0-9_+.-]+$/', $language) === 1) {
                $code->setAttribute('class', 'language-' . $language);
            }
            $code->appendChild($document->createTextNode($plain));
            $pre->appendChild($code);

            $titleText = $this->macroParameter($xpath, $macro, 'title');
            if ($titleText !== '') {
                $figure = $document->createElement('figure');
                $figure->setAttribute('class', 'confluence-import-code-block');
                $caption = $document->createElement('figcaption');
                $caption->setAttribute('class', 'fw-semibold mb-2');
                $caption->appendChild($document->createTextNode($titleText));
                $figure->appendChild($caption);
                $figure->appendChild($pre);
                return $figure;
            }

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

        if ($name === 'details') {
            foreach ($this->pageProperties($xpath, $macro) as $property) {
                $properties[] = $property;
            }
            if (in_array(strtolower($this->macroParameter($xpath, $macro, 'hidden')), ['true', 'yes', '1'], true)) {
                return $document->createDocumentFragment();
            }

            $wrapper = $document->createElement('div');
            $wrapper->setAttribute('class', 'table-responsive confluence-import-page-properties');
            $this->appendRichBody($document, $wrapper, $rich, $plain);
            return $wrapper;
        }

        if (in_array($name, ['detailssummary', 'contentbylabel'], true)) {
            $label = $this->reportLabel($xpath, $macro);
            $headings = $this->reportHeadings($xpath, $macro);
            $sortBy = $this->propertyKey($this->macroParameter($xpath, $macro, 'sortBy'));
            $pageSize = (int)$this->macroParameter($xpath, $macro, 'pageSize');
            return $this->workspaceBlockNode($document, 'page-report', [
                'title' => $this->macroParameter($xpath, $macro, 'title'),
                'label' => $label,
                'columns' => $headings,
                'first_column' => $this->macroParameter($xpath, $macro, 'firstcolumn'),
                'sort' => $sortBy !== '' ? 'property:' . $sortBy : 'title',
                'direction' => strtolower($this->macroParameter($xpath, $macro, 'reverseSort')) === 'true'
                    ? 'desc' : 'asc',
                'limit' => $pageSize > 0 ? min(200, $pageSize) : 100,
            ], __('Izvještaj stranica'));
        }

        if ($name === 'gallery') {
            return $this->workspaceBlockNode($document, 'attachment-gallery', [
                'title' => $this->macroParameter($xpath, $macro, 'title'),
                'sort' => strtolower($this->macroParameter($xpath, $macro, 'sort')) === 'name' ? 'name' : 'date',
                'limit' => 100,
            ], __('Galerija privitaka'));
        }

        if (in_array($name, ['livesearch', 'pagetreesearch'], true)) {
            return $this->workspaceBlockNode($document, 'workspace-search', [
                'title' => $this->macroParameter($xpath, $macro, 'additional'),
                'limit' => 20,
            ], __('Pretraga područja'));
        }

        if (in_array($name, ['recently-updated', 'recently-updated-dashboard'], true)) {
            return $this->workspaceBlockNode($document, 'recent-changes', [
                'title' => '',
                'limit' => max(1, (int)($this->macroParameter($xpath, $macro, 'max') ?: 10)),
            ], __('Nedavne promjene'));
        }

        if ($name === 'panel') {
            $card = $document->createElement('section');
            $card->setAttribute('class', 'card confluence-import-panel mb-3');
            $titleText = $this->macroParameter($xpath, $macro, 'title');
            if ($titleText !== '') {
                $header = $document->createElement('div');
                $header->setAttribute('class', 'card-header fw-semibold');
                $header->appendChild($document->createTextNode($titleText));
                $card->appendChild($header);
            }
            $body = $document->createElement('div');
            $body->setAttribute('class', 'card-body');
            $this->appendRichBody($document, $body, $rich, $plain);
            $card->appendChild($body);
            return $card;
        }

        if ($name === 'profile') {
            $user = $this->macroUser($xpath, $macro);
            $displayName = $context instanceof ConfluenceMacroContext
                ? ($context->users[$user] ?? '')
                : '';
            $profile = $document->createElement('div');
            $profile->setAttribute('class', 'card card-body py-2 confluence-import-profile');
            $profile->appendChild($document->createTextNode(
                $displayName !== '' ? $displayName : ($user !== '' ? $user : __('Korisnik')),
            ));
            return $profile;
        }

        if ($name === 'toc') {
            // HR: Simbioza već gradi tablicu sadržaja iz naslova stranice pa
            //     se Confluence TOC makro uklanja bez dupliciranja sadržaja.
            // EN: Simbioza already builds the page table of contents from its
            //     headings, so the Confluence TOC macro is removed without duplication.
            return $document->createDocumentFragment();
        }

        if ($name === 'create-from-template') {
            $blueprint = strtolower($this->macroParameter($xpath, $macro, 'blueprintModuleCompleteKey'));
            if (str_contains($blueprint, 'file-list-blueprint')) {
                // HR: Confluenceov gumb pokreće njegov uređivački tijek i nema
                //     značenje u običnom uvezenom HTML dokumentu.
                // EN: The Confluence button starts its editor workflow and has
                //     no meaning in an ordinary imported HTML document.
                return $document->createDocumentFragment();
            }
        }

        if ($name === 'content-report-table') {
            return $this->staticContentReport($document, $xpath, $macro, $context);
        }

        if ($name === 'tableenhancer' && $rich instanceof DOMElement) {
            // HR: Dodatak samo poboljšava ponašanje tablice u Confluenceu.
            //     U izvozu je sama tablica potpuna pa je čuvamo kao prilagodljiv HTML.
            // EN: The add-on only enhances table behaviour in Confluence. The
            //     exported table itself is complete, so we retain responsive HTML.
            $wrapper = $document->createElement('div');
            $wrapper->setAttribute('class', 'table-responsive');
            $this->appendRichBody($document, $wrapper, $rich, $plain);
            return $wrapper;
        }

        if (in_array($name, ['children', 'pagetree'], true) && $context instanceof ConfluenceMacroContext) {
            $all = $name === 'pagetree'
                || strtolower($this->macroParameter($xpath, $macro, 'all')) === 'true';
            $rootId = $context->currentPageId;
            if ($name === 'pagetree') {
                $rootTitle = $this->macroPageTitle($xpath, $macro);
                if ($rootTitle !== '' && $rootTitle !== '@self') {
                    $rootId = $this->pageIdByTitle($context, $rootTitle) ?: $rootId;
                }
            }

            return $this->pageList($document, $context, $rootId, $all, $name);
        }

        if ($name === 'attachments' && $context instanceof ConfluenceMacroContext) {
            return $this->attachmentList($document, $context, $attachments, $sourcePageId);
        }

        if ($name === 'multimedia' && $context instanceof ConfluenceMacroContext) {
            $filename = $this->macroAttachmentName($xpath, $macro);
            $url = $context->attachments[$filename] ?? '';
            if ($filename !== '' && $url !== '') {
                $attachments[] = ['source_page_id' => $sourcePageId, 'filename' => $filename, 'kind' => 'file'];
                return $this->mediaElement($document, $filename, $url);
            }
        }

        if ($name === 'view-file' && $context instanceof ConfluenceMacroContext) {
            // HR: Preglednik uredskih datoteka ovisi o Confluence poslužitelju.
            //     Lokalno čuvamo isti privitak i prikazujemo običnu poveznicu.
            // EN: The office-file viewer depends on the Confluence server. We
            //     retain the same local attachment and render a regular link.
            $filename = $this->macroAttachmentName($xpath, $macro);
            $url = $context->attachments[$filename] ?? '';
            if ($filename !== '' && $url !== '') {
                $attachments[] = [
                    'source_page_id' => $sourcePageId,
                    'filename' => $filename,
                    'kind' => 'file',
                ];
                $link = $document->createElement('a');
                $link->setAttribute('href', $url);
                $link->appendChild($document->createTextNode($filename));
                return $link;
            }
        }

        if ($name === 'widget') {
            $widget = $this->widgetReplacement($document, $xpath, $macro);
            if ($widget instanceof DOMNode) {
                return $widget;
            }
        }

        if ($name === 'status') {
            return $this->statusBadge($document, $xpath, $macro);
        }

        if ($name === 'anchor') {
            $anchor = $document->createElement('span');
            $anchor->setAttribute('class', 'confluence-import-anchor');
            $anchor->setAttribute('id', $this->safeFragment($this->macroParameter($xpath, $macro, '')));
            return $anchor;
        }

        if ($name === 'chart' && $rich instanceof DOMElement) {
            $chart = $this->chartReplacement($document, $xpath, $macro, $rich);
            if ($chart instanceof DOMNode) {
                return $chart;
            }

            // HR: Neispravna tablica ostaje čitljiva i ulazi u izvještaj za ručnu provjeru.
            // EN: An invalid table remains readable and enters the report for manual review.
            $unsupported[] = $name;
            $figure = $document->createElement('figure');
            $figure->setAttribute('class', 'confluence-import-chart-table');
            $titleText = $this->macroParameter($xpath, $macro, 'title');
            if ($titleText !== '') {
                $caption = $document->createElement('figcaption');
                $caption->setAttribute('class', 'fw-semibold mb-2');
                $caption->appendChild($document->createTextNode($titleText));
                $figure->appendChild($caption);
            }
            $this->appendRichBody($document, $figure, $rich, $plain);
            return $figure;
        }

        if ($name === 'roadmap') {
            $roadmap = $this->roadmapReplacement($document, $xpath, $macro);
            if ($roadmap instanceof DOMNode) {
                return $roadmap;
            }

            // HR: Neispravan ili nepotpun izvor ostaje u izvještaju umjesto da
            //     se prikaže kao lažno uspješno pretvoren vremenski plan.
            // EN: Invalid or incomplete source data remains in the report
            //     instead of being presented as a successfully converted roadmap.
            $unsupported[] = $name;
        }

        if ($name === 'include') {
            $page = $this->firstElement($xpath->query('./ac:parameter//ri:page', $macro));
            $titleText = $page instanceof DOMElement
                ? $this->attribute($page, self::RI_NAMESPACE, 'content-title')
                : '';
            $spaceKey = $page instanceof DOMElement
                ? $this->attribute($page, self::RI_NAMESPACE, 'space-key')
                : '';
            $pageId = $page instanceof DOMElement
                ? $this->attribute($page, self::RI_NAMESPACE, 'content-id')
                : '';
            $reference = [
                'source_page_id' => $sourcePageId,
                'source_space_key' => $sourceSpaceKey,
                'destination_space_key' => $spaceKey !== '' ? $spaceKey : $sourceSpaceKey,
                'destination_page_id' => $pageId,
                'destination_page_title' => $titleText,
                'original_target' => '',
            ];
            $token = $this->token($reference);
            $marker = '<span data-simbioza-confluence-include-token="' . $token . '"></span>';
            $includes[] = [...$reference, 'marker' => $marker];
            $placeholder = $document->createElement('span');
            $placeholder->setAttribute('data-simbioza-confluence-include-token', $token);

            return $placeholder;
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

    /**
     * HR: Čita strukturirana svojstva iz Confluence Page Properties tablice.
     * EN: Reads structured values from a Confluence Page Properties table.
     *
     * @return list<array{key:string,label:string,type:string,value:string,sort_order:int}>
     */
    private function pageProperties(DOMXPath $xpath, DOMElement $macro): array
    {
        $table = $this->firstElement($xpath->query('.//ac:rich-text-body//table[1]', $macro));
        if (!$table instanceof DOMElement) {
            return [];
        }

        $rows = $this->elements($xpath->query('.//tr', $table));
        if (count($rows) >= 2) {
            $headings = $this->elements($xpath->query('./th|./td', $rows[0]));
            $values = $this->elements($xpath->query('./th|./td', $rows[1]));
            $allHeadings = $headings !== [] && array_reduce(
                $headings,
                static fn(bool $result, DOMElement $cell): bool => $result && strtolower($cell->tagName) === 'th',
                true,
            );

            if ($allHeadings && count($headings) === count($values)) {
                // HR: Confluence Page Properties često koristi zaglavlja u prvom,
                //     a pripadajuće vrijednosti u drugom retku tablice.
                // EN: Confluence Page Properties commonly keeps headings in the
                //     first row and their corresponding values in the second row.
                $properties = [];
                foreach ($headings as $order => $heading) {
                    $property = $this->pageProperty($xpath, trim($heading->textContent), $values[$order], $order);
                    if ($property !== null) {
                        $properties[] = $property;
                    }
                }

                return $properties;
            }
        }

        $properties = [];
        foreach ($rows as $order => $row) {
            $cells = $this->elements($xpath->query('./th|./td', $row));
            if (count($cells) < 2) {
                continue;
            }
            $property = $this->pageProperty($xpath, trim($cells[0]->textContent), $cells[1], $order);
            if ($property !== null) {
                $properties[] = $property;
            }
        }

        return $properties;
    }

    /**
     * HR: Pretvara jednu ćeliju svojstva u kanonski zapis za Područja.
     * EN: Converts one property cell into the canonical Workspaces record.
     *
     * @return array{key:string,label:string,type:string,value:string,sort_order:int}|null
     */
    private function pageProperty(
        DOMXPath $xpath,
        string $label,
        DOMElement $valueCell,
        int $order,
    ): ?array {
        $key = $this->propertyKey($label);
        if ($key === '') {
            return null;
        }

        $type = 'text';
        $value = trim($valueCell->textContent);
        $status = $this->firstElement($xpath->query(
            './/ac:structured-macro[@ac:name="status"]',
            $valueCell,
        ));
        if ($status instanceof DOMElement) {
            $type = 'status';
            $value = $this->macroParameter($xpath, $status, 'title') ?: $value;
        } elseif (
            $this->firstElement($xpath->query(
                './/*[contains(concat(" ", normalize-space(@class), " "), " confluence-import-status ")]',
                $valueCell,
            )) instanceof DOMElement
        ) {
            $type = 'status';
        }

        return [
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'value' => $value,
            'sort_order' => $order,
        ];
    }

    /** HR: Čita oznaku iz jednostavnog Confluence CQL izraza. EN: Reads a label from a simple Confluence CQL expression. */
    private function reportLabel(DOMXPath $xpath, DOMElement $macro): string
    {
        $direct = trim($this->macroParameter($xpath, $macro, 'label'));
        if ($direct !== '') {
            return $direct;
        }
        $cql = $this->macroParameter($xpath, $macro, 'cql');
        if (preg_match('/\blabel\s*=\s*["\']?([A-Za-z0-9_.-]+)/i', $cql, $match) === 1) {
            return trim((string)$match[1]);
        }

        return '';
    }

    /**
     * HR: Čita odabrane naslove stupaca iz makroa izvještaja.
     * EN: Reads selected column headings from a report macro.
     *
     * @return list<string>
     */
    private function reportHeadings(DOMXPath $xpath, DOMElement $macro): array
    {
        $value = $this->macroParameter($xpath, $macro, 'headings');
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), preg_split('/\s*,\s*/', $value) ?: []),
            static fn(string $heading): bool => $heading !== '',
        ));
    }

    /**
     * HR: Gradi kanonski nativni blok koji će Područja prikazati uz ponovnu ACL provjeru.
     * EN: Builds a canonical native block rendered by Workspaces after a fresh ACL check.
     *
     * @param array<string,mixed> $configuration
     */
    private function workspaceBlockNode(
        DOMDocument $document,
        string $kind,
        array $configuration,
        string $label,
    ): DOMElement {
        $json = json_encode($configuration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $encoded = rtrim(str_replace(['+', '/'], ['-', '_'], base64_encode($json)), '=');
        $block = $document->createElement('section');
        $block->setAttribute('class', 'editor-html-workspace-block');
        $block->setAttribute('data-editor-html-workspace-block', '1');
        $block->setAttribute('data-workspace-block-kind', $kind);
        $block->setAttribute('data-workspace-block-config', $encoded);
        $paragraph = $document->createElement('p');
        $paragraph->appendChild($document->createTextNode($label));
        $block->appendChild($paragraph);

        return $block;
    }

    /** HR: Pretvara naslov svojstva u stabilan lokalni ključ. EN: Converts a property label into a stable local key. */
    private function propertyKey(string $label): string
    {
        $key = mb_strtolower(trim($label), 'UTF-8');
        $key = preg_replace('/[^\pL\pN._-]+/u', '-', $key) ?? '';
        return mb_substr(trim($key, '-.'), 0, 128, 'UTF-8');
    }

    /** HR: Mjeri ugniježđenost radi sigurne obrade unutarnjih makroa. EN: Measures nesting for safe inner-macro processing. */
    private function macroDepth(DOMElement $macro): int
    {
        $depth = 0;
        $parent = $macro->parentNode;
        while ($parent instanceof DOMElement) {
            if ($parent->localName === 'structured-macro') {
                ++$depth;
            }
            $parent = $parent->parentNode;
        }

        return $depth;
    }

    /** HR: Čita izravni parametar strukturiranog makroa. EN: Reads a structured macro's direct parameter. */
    private function macroParameter(DOMXPath $xpath, DOMElement $macro, string $name): string
    {
        foreach ($this->elements($xpath->query('./ac:parameter', $macro)) as $parameter) {
            if ($this->attribute($parameter, self::AC_NAMESPACE, 'name') === $name) {
                return trim($parameter->textContent);
            }
        }

        return '';
    }

    /**
     * HR: Čita URL iz tekstnog parametra ili ugniježđene Confluence `ri:url` reference.
     * EN: Reads a URL from a text parameter or a nested Confluence `ri:url` reference.
     */
    private function macroUrlParameter(DOMXPath $xpath, DOMElement $macro, string $name): string
    {
        foreach ($this->elements($xpath->query('./ac:parameter', $macro)) as $parameter) {
            if ($this->attribute($parameter, self::AC_NAMESPACE, 'name') !== $name) {
                continue;
            }

            $url = $this->firstElement($xpath->query('.//ri:url', $parameter));
            if ($url instanceof DOMElement) {
                return trim($this->attribute($url, self::RI_NAMESPACE, 'value'));
            }

            return trim($parameter->textContent);
        }

        return '';
    }

    /**
     * HR: Pretvara podržane vanjske widgete bez učitavanja proizvoljnog koda providera.
     *     YouTube koristi strogo ograničen privacy-enhanced iframe, dok Figma i
     *     društvene objave postaju jasne tematske poveznice.
     * EN: Converts supported external widgets without loading arbitrary provider code.
     *     YouTube uses a tightly restricted privacy-enhanced iframe, while Figma
     *     and social posts become clear theme-aware links.
     */
    private function widgetReplacement(
        DOMDocument $document,
        DOMXPath $xpath,
        DOMElement $macro,
    ): ?DOMNode {
        $url = html_entity_decode(
            $this->macroUrlParameter($xpath, $macro, 'url'),
            ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string)($parts['host'] ?? ''));
        $video = $this->youtubeVideo($parts);
        if ($video !== null) {
            $figure = $document->createElement('figure');
            $figure->setAttribute('class', 'mb-3');
            $ratio = $document->createElement('div');
            $ratio->setAttribute('class', 'ratio ratio-16x9 overflow-hidden');
            $iframe = $document->createElement('iframe');
            $source = 'https://www.youtube-nocookie.com/embed/' . $video['id'];
            if ($video['start'] > 0) {
                $source .= '?start=' . $video['start'];
            }
            $iframe->setAttribute('src', $source);
            $iframe->setAttribute('title', __('YouTube video'));
            $iframe->setAttribute('loading', 'lazy');
            $iframe->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
            $iframe->setAttribute(
                'allow',
                'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share',
            );
            $iframe->setAttribute('allowfullscreen', 'allowfullscreen');
            $iframe->setAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation');
            $ratio->appendChild($iframe);
            $figure->appendChild($ratio);

            $caption = $document->createElement('figcaption');
            $caption->setAttribute('class', 'mt-2');
            $link = $document->createElement('a');
            $link->setAttribute('href', $url);
            $link->setAttribute('target', '_blank');
            $link->setAttribute('rel', 'noopener noreferrer');
            $link->appendChild($document->createTextNode(__('Otvori video na YouTubeu')));
            $caption->appendChild($link);
            $figure->appendChild($caption);

            return $figure;
        }

        $label = match ($host) {
            'figma.com', 'www.figma.com' => __('Otvori Figma prototip'),
            'twitter.com', 'www.twitter.com', 'x.com', 'www.x.com' => __('Otvori objavu na društvenoj mreži'),
            default => '',
        };
        if ($label === '') {
            return null;
        }

        $link = $document->createElement('a');
        $link->setAttribute('class', 'card card-body text-decoration-none mb-3');
        $link->setAttribute('href', $url);
        $link->setAttribute('target', '_blank');
        $link->setAttribute('rel', 'noopener noreferrer');
        $title = $document->createElement('strong');
        $title->appendChild($document->createTextNode($label));
        $link->appendChild($title);
        $provider = $document->createElement('small');
        $provider->setAttribute('class', 'text-body-secondary');
        $provider->appendChild($document->createTextNode($host));
        $link->appendChild($provider);

        return $link;
    }

    /**
     * HR: Izdvaja YouTube identifikator i početak reprodukcije iz poznatih URL oblika.
     * EN: Extracts the YouTube identifier and playback start from known URL forms.
     *
     * @param array<string,mixed> $parts
     * @return array{id:string,start:int}|null
     */
    private function youtubeVideo(array $parts): ?array
    {
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = trim((string)($parts['path'] ?? ''), '/');
        $query = [];
        parse_str((string)($parts['query'] ?? ''), $query);
        $identifier = '';
        if ($host === 'youtu.be') {
            $identifier = $path;
        } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
            if ($path === 'watch') {
                $identifier = is_scalar($query['v'] ?? null) ? trim((string)$query['v']) : '';
            } elseif (preg_match('~^(?:embed|shorts)/([A-Za-z0-9_-]{6,20})$~', $path, $match) === 1) {
                $identifier = $match[1];
            }
        }
        if (preg_match('/^[A-Za-z0-9_-]{6,20}$/', $identifier) !== 1) {
            return null;
        }

        $start = $this->youtubeStart(is_scalar($query['t'] ?? null) ? (string)$query['t'] : '');

        return ['id' => $identifier, 'start' => $start];
    }

    /** HR: Pretvara `1h2m3s` YouTube vrijeme u sekunde. EN: Converts `1h2m3s` YouTube time to seconds. */
    private function youtubeStart(string $value): int
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[0-9]{1,8}$/', $value) === 1) {
            return (int)$value;
        }
        if (preg_match('/^(?:(\d{1,4})h)?(?:(\d{1,4})m)?(?:(\d{1,8})s)?$/', $value, $match) !== 1) {
            return 0;
        }

        return min(86_400, ((int)($match[1] ?? 0) * 3600) + ((int)($match[2] ?? 0) * 60) + (int)($match[3] ?? 0));
    }

    /**
     * HR: Čita korisnika iz tekstnog parametra ili Confluence `ri:user`
     *     reference koja nema tekstni sadržaj.
     * EN: Reads a user from a text parameter or from a Confluence `ri:user`
     *     reference that has no text content.
     */
    private function macroUser(DOMXPath $xpath, DOMElement $macro): string
    {
        foreach ($this->elements($xpath->query('./ac:parameter', $macro)) as $parameter) {
            if ($this->attribute($parameter, self::AC_NAMESPACE, 'name') !== 'user') {
                continue;
            }
            foreach ($this->elements($xpath->query('./ri:user', $parameter)) as $user) {
                $identity = $this->confluenceUserIdentity($user);
                if ($identity !== '') {
                    return $identity;
                }
            }

            return trim($parameter->textContent);
        }

        return '';
    }

    /** HR: Čita stabilni identitet Confluence korisničke reference. EN: Reads the stable identity from a Confluence user reference. */
    private function confluenceUserIdentity(DOMElement $user): string
    {
        foreach (['userkey', 'username'] as $attribute) {
            $value = $this->attribute($user, self::RI_NAMESPACE, $attribute);
            if ($value !== '') {
                return $value;
            }
        }

        return trim($user->textContent);
    }

    /** HR: Provjerava je li element unutar zadanog strukturiranog makroa. EN: Checks whether an element is inside a named structured macro. */
    private function isInsideMacro(DOMElement $element, string $macroName): bool
    {
        $parent = $element->parentNode;
        while ($parent instanceof DOMElement) {
            if (
                $parent->localName === 'structured-macro'
                && strtolower($this->attribute($parent, self::AC_NAMESPACE, 'name')) === $macroName
            ) {
                return true;
            }
            $parent = $parent->parentNode;
        }

        return false;
    }

    /**
     * HR: Pretvara tablicu Confluence chart makroa u uređivi nativni Editor grafikon.
     * EN: Converts a Confluence chart macro table into an editable native Editor chart.
     */
    private function chartReplacement(
        DOMDocument $document,
        DOMXPath $xpath,
        DOMElement $macro,
        DOMElement $rich,
    ): ?DOMNode {
        $table = $this->firstElement($xpath->query('.//table[1]', $rich));
        if (!$table instanceof DOMElement) {
            return null;
        }

        $rows = [];
        $headerFlags = [];
        foreach ($this->elements($xpath->query('.//tr', $table)) as $row) {
            $cells = $this->elements($xpath->query('./th|./td', $row));
            if (count($cells) < 2) {
                continue;
            }

            $rows[] = array_map(static fn(DOMElement $cell): string => trim($cell->textContent), $cells);
            $headerFlags[] = array_filter(
                $cells,
                static fn(DOMElement $cell): bool => strtolower($cell->tagName) === 'th',
            ) !== [];
        }

        if ($rows === []) {
            return null;
        }

        $columns = $this->chartColumns($this->macroParameter($xpath, $macro, 'columns'), count($rows[0]));
        if (count($columns) < 2) {
            return null;
        }

        $hasHeader = $headerFlags[0] ?? false;
        if (!$hasHeader) {
            foreach (array_slice($columns, 1) as $column) {
                if ($this->chartNumber($rows[0][$column] ?? '') === null) {
                    $hasHeader = true;
                    break;
                }
            }
        }

        $header = $hasHeader ? array_shift($rows) : null;
        $labels = [];
        $series = [];
        foreach (array_slice($columns, 1) as $seriesIndex => $column) {
            $series[] = [
                'name' => trim((string)($header[$column] ?? ''))
                    ?: sprintf('%s %d', __('Serija'), $seriesIndex + 1),
                'values' => [],
            ];
        }

        foreach ($rows as $row) {
            $label = trim((string)($row[$columns[0]] ?? ''));
            if ($label === '') {
                continue;
            }

            $values = [];
            foreach (array_slice($columns, 1) as $column) {
                $value = $this->chartNumber($row[$column] ?? '');
                if ($value === null) {
                    $values = [];
                    break;
                }
                $values[] = $value;
            }
            if ($values === []) {
                continue;
            }

            $labels[] = $label;
            foreach ($values as $seriesIndex => $value) {
                $series[$seriesIndex]['values'][] = $value;
            }
        }

        if ($labels === []) {
            return null;
        }

        $sourceType = strtolower($this->macroParameter($xpath, $macro, 'type'));
        $type = match ($sourceType) {
            '', 'bar', 'column' => 'bar',
            'line' => 'line',
            'area' => 'area',
            'pie' => 'pie',
            'doughnut', 'donut' => 'doughnut',
            default => '',
        };
        if ($type === '') {
            return null;
        }

        $orientation = strtolower($this->macroParameter($xpath, $macro, 'orientation'));
        $orientation = $orientation === 'horizontal' ? 'horizontal' : 'vertical';
        $threeDimensional = strtolower($this->macroParameter($xpath, $macro, '3D')) === 'true';
        $legendParameter = strtolower($this->macroParameter($xpath, $macro, 'legend'));
        $showLegend = !in_array($legendParameter, ['false', 'none', 'off'], true);
        if (!$this->charts instanceof EditorHtmlChartService) {
            return null;
        }

        $placeholder = $this->charts->placeholder([
            'type' => $type,
            'orientation' => $orientation,
            'title' => $this->macroParameter($xpath, $macro, 'title'),
            'x_label' => $this->macroParameter($xpath, $macro, 'xLabel'),
            'y_label' => $this->macroParameter($xpath, $macro, 'yLabel'),
            'description' => '',
            'show_legend' => $showLegend,
            'three_dimensional' => $threeDimensional,
            'labels' => $labels,
            'series' => $series,
        ]);

        $fragmentDocument = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $fragmentDocument->loadXML(
                '<?xml version="1.0" encoding="UTF-8"?><root>' . $placeholder . '</root>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded || !$fragmentDocument->documentElement instanceof DOMElement) {
            return null;
        }

        $chartElement = $fragmentDocument->documentElement->firstElementChild;
        return $chartElement instanceof DOMElement ? $document->importNode($chartElement, true) : null;
    }

    /**
     * HR: Pretvara Confluence Roadmap Planner JSON u uređivi nativni vremenski plan.
     *     Trajanje aktivnosti izračunava se u jedinici odabranog mjerila izvoza.
     * EN: Converts Confluence Roadmap Planner JSON into an editable native timeline.
     *     Activity duration is calculated in the unit selected by the export scale.
     */
    private function roadmapReplacement(
        DOMDocument $document,
        DOMXPath $xpath,
        DOMElement $macro,
    ): ?DOMNode {
        if (!$this->roadmaps instanceof EditorHtmlRoadmapService) {
            return null;
        }

        $source = $this->macroParameter($xpath, $macro, 'source');
        if ($source === '') {
            return null;
        }

        $decoded = json_decode(rawurldecode(html_entity_decode(
            $source,
            ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE,
            'UTF-8',
        )), true);
        if (!is_array($decoded)) {
            return null;
        }

        $timeline = is_array($decoded['timeline'] ?? null) ? $decoded['timeline'] : [];
        $startDate = $this->roadmapDate($timeline['startDate'] ?? '');
        $endDate = $this->roadmapDate($timeline['endDate'] ?? '');
        if ($startDate === '' || $endDate === '' || $endDate < $startDate) {
            return null;
        }

        $sourceDisplay = strtolower(trim((string)($timeline['displayOption'] ?? 'month')));
        $display = match ($sourceDisplay) {
            'day', 'week', 'month', 'quarter' => $sourceDisplay,
            default => 'month',
        };
        $durationDays = match ($display) {
            'day' => 1.0,
            'week' => 7.0,
            'quarter' => 91.3125,
            default => 30.4375,
        };

        $lanes = [];
        foreach (is_array($decoded['lanes'] ?? null) ? $decoded['lanes'] : [] as $laneIndex => $laneValue) {
            if (!is_array($laneValue)) {
                continue;
            }
            $colors = is_array($laneValue['color'] ?? null) ? $laneValue['color'] : [];
            $bars = [];
            foreach (is_array($laneValue['bars'] ?? null) ? $laneValue['bars'] : [] as $barIndex => $barValue) {
                if (!is_array($barValue)) {
                    continue;
                }
                $barStart = $this->roadmapDate($barValue['startDate'] ?? '');
                if ($barStart === '') {
                    continue;
                }
                $duration = is_numeric($barValue['duration'] ?? null)
                    ? max(0.0, (float)$barValue['duration'])
                    : 0.0;
                $days = max(1, (int)ceil($duration * $durationDays));
                $barEnd = (new DateTimeImmutable($barStart))->modify('+' . $days . ' days')->format('Y-m-d');
                $bars[] = [
                    'id' => trim((string)($barValue['id'] ?? ''))
                        ?: sprintf('roadmap-%d-%d', $laneIndex + 1, $barIndex + 1),
                    'title' => trim((string)($barValue['title'] ?? '')) ?: __('Aktivnost'),
                    'description' => trim((string)($barValue['description'] ?? '')),
                    'start_date' => $barStart,
                    'end_date' => $barEnd,
                    'link' => $this->roadmapLink($barValue['pageLink'] ?? ''),
                    'row' => max(0, (int)($barValue['rowIndex'] ?? 0)),
                ];
            }
            $lanes[] = [
                'title' => trim((string)($laneValue['title'] ?? ''))
                    ?: sprintf('%s %d', __('Grupa'), $laneIndex + 1),
                'lane_color' => (string)($colors['lane'] ?? '#e9ecef'),
                'bar_color' => (string)($colors['bar'] ?? '#0d6efd'),
                'text_color' => (string)($colors['text'] ?? '#ffffff'),
                'bars' => $bars,
            ];
        }
        if ($lanes === []) {
            return null;
        }

        $markers = [];
        foreach (is_array($decoded['markers'] ?? null) ? $decoded['markers'] : [] as $markerValue) {
            if (!is_array($markerValue)) {
                continue;
            }
            $date = $this->roadmapDate($markerValue['markerDate'] ?? $markerValue['date'] ?? '');
            if ($date === '') {
                continue;
            }
            $markers[] = [
                'title' => trim((string)($markerValue['title'] ?? '')) ?: __('Oznaka'),
                'date' => $date,
            ];
        }

        try {
            $placeholder = $this->roadmaps->placeholder([
                'title' => trim((string)($decoded['title'] ?? '')) ?: __('Vremenski plan'),
                'start_date' => $startDate,
                'end_date' => $endDate,
                'display' => $display,
                'lanes' => $lanes,
                'markers' => $markers,
            ]);
        } catch (\Throwable) {
            return null;
        }

        $fragmentDocument = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $fragmentDocument->loadXML(
                '<?xml version="1.0" encoding="UTF-8"?><root>' . $placeholder . '</root>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $element = $loaded && $fragmentDocument->documentElement instanceof DOMElement
            ? $fragmentDocument->documentElement->firstElementChild
            : null;

        return $element instanceof DOMElement ? $document->importNode($element, true) : null;
    }

    /** HR: Čita datum iz Confluence zapisa. EN: Reads a date from a Confluence value. */
    private function roadmapDate(mixed $value): string
    {
        $value = trim(is_scalar($value) ? (string)$value : '');
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $match) !== 1) {
            return '';
        }

        try {
            return (new DateTimeImmutable($match[1]))->format('Y-m-d');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * HR: Iz proizvoljnog Roadmap pageLink zapisa uzima samo sigurnu HTTP(S) poveznicu.
     * EN: Extracts only a safe HTTP(S) link from an arbitrary Roadmap pageLink value.
     */
    private function roadmapLink(mixed $value): string
    {
        if (is_scalar($value)) {
            $link = trim((string)$value);
            return preg_match('~^https?://~i', $link) === 1 ? $link : '';
        }
        if (!is_array($value)) {
            return '';
        }
        foreach ($value as $candidate) {
            $link = $this->roadmapLink($candidate);
            if ($link !== '') {
                return $link;
            }
        }

        return '';
    }

    /**
     * HR: Čita 1-based Confluence izbor stupaca i vraća 0-based indekse.
     * EN: Reads Confluence's 1-based column selection and returns 0-based indexes.
     *
     * @return list<int>
     */
    private function chartColumns(string $value, int $columnCount): array
    {
        $columns = [];
        if (trim($value) !== '') {
            foreach (preg_split('/\s*,\s*/', trim($value)) ?: [] as $column) {
                if (preg_match('/^[1-9][0-9]*$/', $column) !== 1) {
                    continue;
                }
                $index = (int)$column - 1;
                if ($index < $columnCount && !in_array($index, $columns, true)) {
                    $columns[] = $index;
                }
            }
        }

        return $columns !== [] ? $columns : range(0, max(0, $columnCount - 1));
    }

    /** HR: Pretvara lokalizirani tablični broj u float. EN: Converts a localized table number into a float. */
    private function chartNumber(string $value): ?float
    {
        $normalized = preg_replace('/[\s\x{00A0}]+/u', '', trim($value)) ?? '';
        if (preg_match('/^-?[0-9]+(?:[.,][0-9]+)?$/', $normalized) !== 1) {
            return null;
        }

        return (float)str_replace(',', '.', $normalized);
    }

    /**
     * HR: Pretvara jedan Confluence layout u responzivnu Bootstrap mrežu.
     * EN: Converts one Confluence layout into a responsive Bootstrap grid.
     */
    private function layoutReplacement(
        DOMDocument $document,
        DOMXPath $xpath,
        DOMElement $layout,
    ): DOMElement {
        $container = $document->createElement('div');
        $container->setAttribute('class', 'confluence-import-layout');
        foreach ($this->elements($xpath->query('./ac:layout-section', $layout)) as $section) {
            $row = $document->createElement('div');
            $row->setAttribute('class', 'row g-3 confluence-import-layout-section');
            $type = $this->attribute($section, self::AC_NAMESPACE, 'type');
            $cells = $this->elements($xpath->query('./ac:layout-cell', $section));
            foreach ($cells as $index => $cell) {
                $column = $document->createElement('div');
                $column->setAttribute(
                    'class',
                    $this->layoutColumnClass($type, $index, count($cells)),
                );
                foreach (iterator_to_array($cell->childNodes) as $child) {
                    if ($child instanceof DOMNode) {
                        $column->appendChild($child->cloneNode(true));
                    }
                }
                $row->appendChild($column);
            }
            $container->appendChild($row);
        }

        return $container;
    }

    /** HR: Određuje širinu ćelije izvornog Confluence rasporeda. EN: Determines a source Confluence layout cell width. */
    private function layoutColumnClass(string $type, int $index, int $cellCount): string
    {
        $width = match ($type) {
            'two_left_sidebar' => $index === 0 ? '4' : '8',
            'two_right_sidebar' => $index === 0 ? '8' : '4',
            'three_with_sidebars' => $index === 1 ? '6' : '3',
            'three_equal' => '4',
            'two_equal' => '6',
            default => $cellCount >= 3 ? '4' : ($cellCount === 2 ? '6' : '12'),
        };

        return 'col-12 col-lg-' . $width . ' confluence-import-layout-cell';
    }

    /** HR: Čita naslov stranice iz parametra children/pagetree/include makroa. EN: Reads a page title from a children/pagetree/include macro parameter. */
    private function macroPageTitle(DOMXPath $xpath, DOMElement $macro): string
    {
        $page = $this->firstElement($xpath->query('./ac:parameter//ri:page', $macro));
        return $page instanceof DOMElement
            ? $this->attribute($page, self::RI_NAMESPACE, 'content-title')
            : '';
    }

    /** HR: Čita naziv privitka zadan multimedia makrou. EN: Reads the attachment name supplied to a multimedia macro. */
    private function macroAttachmentName(DOMXPath $xpath, DOMElement $macro): string
    {
        $attachment = $this->firstElement($xpath->query('./ac:parameter//ri:attachment', $macro));
        return $attachment instanceof DOMElement
            ? $this->attribute($attachment, self::RI_NAMESPACE, 'filename')
            : '';
    }

    /** HR: Pronalazi lokalni ID stranice prema izvornom naslovu. EN: Finds a local page ID by its source title. */
    private function pageIdByTitle(ConfluenceMacroContext $context, string $title): string
    {
        foreach ($context->pages as $id => $page) {
            if (($page['title'] ?? '') === $title) {
                return (string)$id;
            }
        }

        return '';
    }

    /** HR: Gradi lokalni hijerarhijski popis children ili pagetree makroa. EN: Builds a local hierarchical children or pagetree macro list. */
    private function pageList(
        DOMDocument $document,
        ConfluenceMacroContext $context,
        string $parentId,
        bool $recursive,
        string $macroName,
    ): DOMElement {
        $list = $document->createElement('ul');
        $list->setAttribute('class', 'confluence-import-page-list confluence-import-' . $macroName);
        foreach ($this->childPages($context, $parentId) as $id => $page) {
            $item = $document->createElement('li');
            $link = $document->createElement('a');
            $link->setAttribute('href', $page['path']);
            $link->appendChild($document->createTextNode($page['title']));
            $item->appendChild($link);
            if ($recursive) {
                $children = $this->pageList($document, $context, (string)$id, true, $macroName);
                if ($children->childElementCount > 0) {
                    $item->appendChild($children);
                }
            }
            $list->appendChild($item);
        }

        return $list;
    }

    /**
     * HR: Materijalizira Confluence izvještaj sadržaja kao običnu uređivu HTML tablicu.
     * EN: Materializes a Confluence content report as an ordinary editable HTML table.
     */
    private function staticContentReport(
        DOMDocument $document,
        DOMXPath $xpath,
        DOMElement $macro,
        ?ConfluenceMacroContext $context,
    ): DOMNode {
        $labels = array_values(array_filter(
            preg_split('/[\s,]+/', strtolower($this->macroParameter($xpath, $macro, 'labels'))) ?: [],
            static fn(string $label): bool => trim($label) !== '',
        ));
        $pages = [];
        if ($context instanceof ConfluenceMacroContext) {
            foreach ($context->pages as $page) {
                $pageLabels = array_map(
                    static fn(string $label): string => strtolower(trim($label)),
                    $page['labels'] ?? [],
                );
                if (
                    $labels !== [] && array_filter(
                        $labels,
                        static fn(string $label): bool => in_array($label, $pageLabels, true),
                    ) === []
                ) {
                    continue;
                }
                $pages[] = $page;
            }
        }

        uasort($pages, static fn(array $left, array $right): int =>
            strnatcasecmp($left['title'] ?? '', $right['title'] ?? ''));
        if ($pages === []) {
            $emptyTitle = $this->macroParameter($xpath, $macro, 'blankTitle');
            $emptyDescription = $this->macroParameter($xpath, $macro, 'blankDescription');
            $emptyText = trim($emptyTitle . ($emptyTitle !== '' && $emptyDescription !== '' ? ': ' : '')
                . $emptyDescription);
            $paragraph = $document->createElement('p');
            $paragraph->setAttribute('class', 'text-body-secondary');
            $paragraph->appendChild($document->createTextNode(
                $emptyText !== '' ? $emptyText : __('Nema stranica za prikaz.'),
            ));

            return $paragraph;
        }

        $wrapper = $document->createElement('div');
        $wrapper->setAttribute('class', 'table-responsive confluence-import-content-report');
        $table = $document->createElement('table');
        $table->setAttribute('class', 'table table-bordered table-striped table-hover');
        $head = $document->createElement('thead');
        $head->setAttribute('class', 'table-light');
        $headingRow = $document->createElement('tr');
        foreach ([__('Naslov'), __('Izmijenjeno')] as $heading) {
            $cell = $document->createElement('th');
            $cell->setAttribute('scope', 'col');
            $cell->appendChild($document->createTextNode($heading));
            $headingRow->appendChild($cell);
        }
        $head->appendChild($headingRow);
        $table->appendChild($head);
        $body = $document->createElement('tbody');
        foreach ($pages as $page) {
            $row = $document->createElement('tr');
            $titleCell = $document->createElement('td');
            $link = $document->createElement('a');
            $link->setAttribute('href', $page['path'] ?? '#');
            $link->appendChild($document->createTextNode($page['title'] ?? ''));
            $titleCell->appendChild($link);
            $row->appendChild($titleCell);
            $updatedCell = $document->createElement('td');
            $updatedCell->appendChild($document->createTextNode($page['updated_at'] ?? ''));
            $row->appendChild($updatedCell);
            $body->appendChild($row);
        }
        $table->appendChild($body);
        $wrapper->appendChild($table);

        return $wrapper;
    }

    /**
     * HR: Normalizira sve tablice na standardni Editor/Bootstrap prikaz i
     *     dodaje responzivni omotač bez stvaranja dvostrukih omotača.
     * EN: Normalizes every table to the standard Editor/Bootstrap presentation
     *     and adds a responsive wrapper without creating duplicate wrappers.
     */
    private function normalizeTables(DOMDocument $document, DOMElement $root): void
    {
        $tables = [];
        foreach ($root->getElementsByTagName('table') as $table) {
            if ($table instanceof DOMElement) {
                $tables[] = $table;
            }
        }

        foreach ($tables as $table) {
            $this->setCanonicalTableClasses($table);
            $this->normalizeTableSections($document, $table);

            $parent = $table->parentNode;
            if (!$parent instanceof DOMElement || $this->hasClass($parent, 'table-responsive')) {
                continue;
            }

            $wrapper = $document->createElement('div');
            $wrapper->setAttribute('class', 'table-responsive');
            $parent->replaceChild($wrapper, $table);
            $wrapper->appendChild($table);
        }
    }

    /**
     * HR: Postavlja kanonske klase tablice na početak, a čuva dodatne semantičke klase izvora.
     * EN: Places canonical table classes first while preserving extra semantic source classes.
     */
    private function setCanonicalTableClasses(DOMElement $table): void
    {
        $canonical = ['table', 'table-bordered', 'table-striped', 'table-hover'];
        $existing = preg_split('/\s+/', trim($table->getAttribute('class'))) ?: [];
        $extra = [];
        foreach ($existing as $class) {
            if ($class !== '' && !in_array($class, [...$canonical, 'align-middle'], true)) {
                $extra[] = $class;
            }
        }

        $table->setAttribute('class', implode(' ', array_values(array_unique([...$canonical, ...$extra]))));
    }

    /**
     * HR: Iz prvog retka sa zaglavljima gradi `thead`, označava ga tematskom
     *     klasom i premješta neposredne retke tablice u `tbody`.
     * EN: Builds `thead` from an initial header row, marks it with the theme
     *     class, and moves direct table rows into `tbody`.
     */
    private function normalizeTableSections(DOMDocument $document, DOMElement $table): void
    {
        $head = null;
        $body = null;
        $directRows = [];
        foreach (iterator_to_array($table->childNodes) as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if ($tag === 'thead' && !$head instanceof DOMElement) {
                $head = $child;
            } elseif ($tag === 'tbody' && !$body instanceof DOMElement) {
                $body = $child;
            } elseif ($tag === 'tr') {
                $directRows[] = $child;
            }
        }

        if (!$body instanceof DOMElement && $directRows !== []) {
            $body = $document->createElement('tbody');
            foreach ($directRows as $row) {
                $body->appendChild($row);
            }
            $table->appendChild($body);
        }

        if (!$head instanceof DOMElement) {
            $firstRow = $body instanceof DOMElement
                ? $this->firstDirectChild($body, 'tr')
                : ($directRows[0] ?? null);
            if ($firstRow instanceof DOMElement && $firstRow->getElementsByTagName('th')->length > 0) {
                $head = $document->createElement('thead');
                $table->insertBefore($head, $body instanceof DOMElement ? $body : $table->firstChild);
                $head->appendChild($firstRow);
            }
        }

        if ($head instanceof DOMElement) {
            $classes = preg_split('/\s+/', trim($head->getAttribute('class'))) ?: [];
            $classes = array_values(array_filter($classes, static fn(string $class): bool => $class !== ''));
            array_unshift($classes, 'table-light');
            $head->setAttribute('class', implode(' ', array_values(array_unique($classes))));
        }
    }

    /** HR: Vraća prvo neposredno dijete zadanog imena. EN: Returns the first direct child with the requested name. */
    private function firstDirectChild(DOMElement $parent, string $tagName): ?DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === $tagName) {
                return $child;
            }
        }

        return null;
    }

    /** HR: Provjerava sadrži li element zadanu CSS klasu. EN: Checks whether an element contains the requested CSS class. */
    private function hasClass(DOMElement $element, string $className): bool
    {
        $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];

        return in_array($className, $classes, true);
    }

    /**
     * HR: Vraća neposrednu djecu u stabilnom Confluence poretku.
     * EN: Returns direct children in stable Confluence order.
     *
     * @return array<string,array{title:string,path:string,parent_id:string,sort_order:int}>
     */
    private function childPages(ConfluenceMacroContext $context, string $parentId): array
    {
        $children = array_filter(
            $context->pages,
            static fn(array $page): bool => ($page['parent_id'] ?? '') === $parentId,
        );
        uasort($children, static function (array $left, array $right): int {
            $order = ($left['sort_order'] ?? 100) <=> ($right['sort_order'] ?? 100);
            return $order !== 0 ? $order : strnatcasecmp($left['title'] ?? '', $right['title'] ?? '');
        });

        return $children;
    }

    /**
     * HR: Gradi read-only popis lokalno uvezenih privitaka stranice.
     * EN: Builds a read-only list of locally imported page attachments.
     *
     * @param array<int,array<string,string>> $attachments
     */
    private function attachmentList(
        DOMDocument $document,
        ConfluenceMacroContext $context,
        array &$attachments,
        string $sourcePageId,
    ): DOMElement {
        $list = $document->createElement('ul');
        $list->setAttribute('class', 'confluence-import-attachment-list');
        foreach ($context->attachments as $filename => $url) {
            $attachments[] = ['source_page_id' => $sourcePageId, 'filename' => $filename, 'kind' => 'file'];
            $item = $document->createElement('li');
            $link = $document->createElement('a');
            $link->setAttribute('href', $url);
            $link->setAttribute('download', basename($filename));
            $link->appendChild($document->createTextNode($filename));
            $item->appendChild($link);
            $list->appendChild($item);
        }

        return $list;
    }

    /** HR: Pretvara Confluence status u tematski Bootstrap badge. EN: Converts a Confluence status into a themed Bootstrap badge. */
    private function statusBadge(DOMDocument $document, DOMXPath $xpath, DOMElement $macro): DOMElement
    {
        $colour = strtolower($this->macroParameter($xpath, $macro, 'colour'));
        $class = match ($colour) {
            'green' => 'text-bg-success',
            'yellow' => 'text-bg-warning',
            'red' => 'text-bg-danger',
            'blue' => 'text-bg-info',
            default => 'text-bg-secondary',
        };
        $badge = $document->createElement('span');
        $badge->setAttribute('class', 'badge ' . $class . ' confluence-import-status');
        $badge->appendChild($document->createTextNode($this->macroParameter($xpath, $macro, 'title') ?: __('Status')));
        return $badge;
    }

    /** HR: Gradi siguran audio/video pregled uvezenog privitka. EN: Builds a safe audio/video preview of an imported attachment. */
    private function mediaElement(DOMDocument $document, string $filename, string $url): DOMElement
    {
        $lower = strtolower($filename);
        $audio = str_ends_with($lower, '.mp3') || str_ends_with($lower, '.wav') || str_ends_with($lower, '.ogg');
        $media = $document->createElement($audio ? 'audio' : 'video');
        $media->setAttribute('controls', 'controls');
        $media->setAttribute('src', $url);
        $media->setAttribute('class', $audio ? 'w-100 confluence-import-media' : 'img-fluid confluence-import-media');
        $media->appendChild($document->createTextNode(__('Multimedijski privitak:') . ' ' . $filename));
        return $media;
    }

    /** HR: Normalizira Confluence sidro u siguran HTML fragment. EN: Normalizes a Confluence anchor into a safe HTML fragment. */
    private function safeFragment(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.:-]+/', '-', trim($value)) ?? '';
        return trim($safe, '-');
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

        $label = trim($this->nodeText($xpath, './/ac:link-body', $link));
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
            !is_string($path) || preg_match(
                '~/download/(?:attachments|thumbnails)/([0-9]+)/([^/?#]+)$~iu',
                $path,
                $match,
            ) !== 1
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

    /** HR: Dopušta samo udaljene HTTP(S) slike. EN: Allows only remote HTTP(S) images. */
    private function isSafeRemoteUrl(string $url): bool
    {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        return $scheme === 'http' || $scheme === 'https';
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
