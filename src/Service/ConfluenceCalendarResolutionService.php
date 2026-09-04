<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorApiActorContext;
use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorDocumentVersion;
use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorService;
use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceContentChangeBatch;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceWorkflowService;
use DOMDocument;
use DOMElement;
use Psr\Container\ContainerInterface;
use Throwable;

use function gmdate;
use function interface_exists;
use function in_array;
use function is_array;
use function is_callable;
use function is_numeric;
use function is_object;
use function is_scalar;
use function json_encode;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function method_exists;
use function preg_match;
use function sprintf;
use function str_contains;
use function strtolower;
use function trim;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const LIBXML_HTML_NODEFDTD;
use const LIBXML_HTML_NOIMPLIED;
use const LIBXML_NONET;

/**
 * HR: Ručno razrješava Confluence calendar makro u nativni Editor Calendar blok.
 * EN: Manually resolves a Confluence calendar macro to a native Editor Calendar block.
 */
final readonly class ConfluenceCalendarResolutionService
{
    private const CALENDAR_MANAGER =
        'AaiEduHr\\HeartPhrameModuleCalendar\\Service\\CalendarManagerInterface';

    /** HR: Prima samo javne granice vlasničkih modula. EN: Receives only public owner-module boundaries. */
    public function __construct(
        private ConfluenceImportRepository $repository,
        private ConfluenceImportConfig $config,
        private EditorService $editor,
        private EditorApiActorContext $editorActors,
        private WorkspaceWorkflowService $workflow,
        private WorkspaceContentChangeBatch $workspaceChanges,
        private ContainerInterface $container,
    ) {
    }

    /** HR: Provjerava je li opcionalni Calendar modul dostupan. EN: Checks whether the optional Calendar module is available. */
    public function isAvailable(): bool
    {
        return $this->calendarManager() !== null;
    }

    /**
     * HR: Vraća kalendare koje administrator smije čitati i ugraditi.
     * EN: Returns calendars the administrator may read and embed.
     *
     * @param array<string,mixed> $actor
     * @return list<array<string,mixed>>
     */
    public function availableCalendars(array $actor): array
    {
        $manager = $this->requiredCalendarManager();
        $rows = $this->invoke($manager, 'visibleCalendars', [$actor]);
        $calendars = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $uuid = $this->text($row['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }

            $calendars[] = [
                'uuid' => $uuid,
                'name' => $this->text($row['name'] ?? __('Calendar')),
                'calendar_type' => $this->text($row['calendar_type'] ?? ''),
                'is_public_read' => (bool)($row['is_public_read'] ?? false),
                'is_authenticated_read' => (bool)($row['is_authenticated_read'] ?? false),
                'can_write' => (bool)($row['can_write'] ?? false),
            ];
        }

        return $calendars;
    }

    /**
     * HR: Povezuje postojeći kalendar ili izričito uvozi ICS pa objavljuje novu verziju stranice.
     * EN: Links an existing calendar or explicitly imports ICS and publishes a new page version.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function resolve(string $jobUuid, array $data, string $ics, array $actor): array
    {
        $actorUserId = $this->positiveInt($actor['id'] ?? null, __('Prijavljeni administrator nije pronađen.'));
        $job = $this->repository->jobByUuid($jobUuid);
        if (($job['status'] ?? '') !== 'completed') {
            throw new ConfluenceImportException(__('Kalendar je moguće povezati tek nakon dovršenog importa.'));
        }

        $summary = is_array($job['summary'] ?? null) ? $job['summary'] : [];
        $sourcePageId = $this->text($data['source_page_id'] ?? '');
        $sourceCalendarId = $this->text($data['source_calendar_id'] ?? '');
        $marker = $this->text($data['marker'] ?? '');
        if (
            $sourcePageId === ''
            || preg_match('/^confluence-calendar-[a-f0-9]{20}$/', $marker) !== 1
        ) {
            throw new ConfluenceImportException(__('Confluence calendar makro nije valjano odabran.'));
        }

        [$pageIndex, $issueIndex, $issue] = $this->calendarIssue(
            $summary,
            $sourcePageId,
            $sourceCalendarId,
            $marker,
        );
        if ((bool)($issue['resolved'] ?? false)) {
            throw new ConfluenceImportException(__('Ovaj Confluence kalendar već je povezan.'));
        }

        $content = $this->repository->contentForJobSource((int)($job['id'] ?? 0), $sourcePageId);
        $documentKey = $this->text($content['target_document_key'] ?? '');
        $nodeId = $this->positiveInt($content['target_node_id'] ?? null, __('Ciljna stranica nije pronađena.'));
        if ($documentKey === '') {
            throw new ConfluenceImportException(__('Ciljni dokument nije pronađen.'));
        }

        $options = is_array($job['options'] ?? null) ? $job['options'] : [];
        $language = $this->text($options['language'] ?? $this->config->defaultLanguage());
        $publishedVersionNumber = $this->workflow->publicationVersionForNode($nodeId, $language);
        $currentVersionNumber = $this->editor->currentVersionNumber($documentKey, $language);
        if ($publishedVersionNumber <= 0) {
            throw new ConfluenceImportException(__('Ciljna stranica još nije objavljena.'));
        }
        if ($currentVersionNumber !== $publishedVersionNumber) {
            throw new ConfluenceImportException(
                __('Stranica ima neobjavljeni nacrt. Najprije ga objavite ili odbacite pa ponovno povežite kalendar.'),
            );
        }

        $version = $this->editor->loadVersion($documentKey, $language, $publishedVersionNumber);
        if (!$version instanceof EditorDocumentVersion || !str_contains($version->html, 'id="' . $marker . '"')) {
            throw new ConfluenceImportException(__('Oznaka Confluence kalendara više nije prisutna na stranici.'));
        }

        $manager = $this->requiredCalendarManager();
        $mode = strtolower($this->text($data['resolution_mode'] ?? ''));
        $importResult = null;
        if ($mode === 'import') {
            if (trim($ics) === '') {
                throw new ConfluenceImportException(__('Odaberite ICS datoteku za uvoz kalendara.'));
            }
            $calendarType = $this->text($data['calendar_type'] ?? 'team');
            if (!in_array($calendarType, ['team', 'resource'], true)) {
                throw new ConfluenceImportException(__('Odaberite timski ili resursni kalendar.'));
            }
            $importResult = $this->invoke($manager, 'importCalendar', [[
                'ics' => $ics,
                'calendar_id' => 0,
                'calendar_type' => $calendarType,
                'name' => $this->text($issue['source_calendar_name'] ?? ''),
                'prefer_ics_name' => true,
                'is_public_read' => (bool)($data['is_public_read'] ?? false),
                'is_authenticated_read' => (bool)($data['is_authenticated_read'] ?? false),
                'merge_mode' => 'skip',
            ], $actor]);
            if (!is_array($importResult)) {
                throw new ConfluenceImportException(__('Calendar modul nije vratio rezultat uvoza.'));
            }
            $calendar = is_array($importResult['calendar'] ?? null) ? $importResult['calendar'] : [];
        } elseif ($mode === 'existing') {
            $calendar = $this->invoke(
                $manager,
                'calendarByUuid',
                [$this->text($data['calendar_uuid'] ?? ''), $actor],
            );
            if (!is_array($calendar)) {
                throw new ConfluenceImportException(__('Odabrani kalendar nije dostupan za povezivanje.'));
            }
        } else {
            throw new ConfluenceImportException(__('Odaberite uvoz ICS-a ili povezivanje postojećeg kalendara.'));
        }

        $calendarUuid = $this->text($calendar['uuid'] ?? '');
        $calendarName = $this->text($calendar['name'] ?? __('Calendar'));
        if ($calendarUuid === '') {
            throw new ConfluenceImportException(__('Odabrani kalendar nema stabilni UUID.'));
        }

        $updatedHtml = $this->replaceMarker($version->html, $marker, $calendarUuid, $calendarName);
        $this->workspaceChanges->run(function () use (
            $actor,
            $documentKey,
            $language,
            $version,
            $updatedHtml,
            $nodeId,
            $actorUserId,
        ): void {
            $this->editorActors->runAs(
                $actor,
                function () use ($documentKey, $language, $version, $updatedHtml, $nodeId, $actorUserId): void {
                    $this->editor->save($documentKey, $language, $version->title, $updatedHtml);
                    $versionNumber = $this->editor->currentVersionNumber($documentKey, $language);
                    $this->editor->markVersionDraft($documentKey, $language, $versionNumber);
                    $this->workflow->markDocumentDraft($documentKey, $language, $versionNumber, $actorUserId);
                    $this->editor->publishDraft($documentKey, $language, $versionNumber);
                    $this->workflow->transition(
                        $nodeId,
                        $language,
                        'publish',
                        $versionNumber,
                        $actorUserId,
                        true,
                        true,
                        true,
                    );
                },
            );
        });

        $summary['review_pages'][$pageIndex]['issues'][$issueIndex] = [
            ...$issue,
            'resolved' => true,
            'resolution_mode' => $mode,
            'target_calendar_uuid' => $calendarUuid,
            'target_calendar_name' => $calendarName,
            'resolved_at' => gmdate('Y-m-d H:i:s'),
            'events_created' => is_array($importResult) ? (int)($importResult['created'] ?? 0) : 0,
            'events_updated' => is_array($importResult) ? (int)($importResult['updated'] ?? 0) : 0,
            'events_skipped' => is_array($importResult) ? (int)($importResult['skipped'] ?? 0) : 0,
        ];
        $this->repository->updateCompletedSummary((int)($job['id'] ?? 0), $summary);

        return $summary['review_pages'][$pageIndex]['issues'][$issueIndex];
    }

    /**
     * HR: Pronalazi točno kalendarsko upozorenje u spremljenom izvještaju importa.
     * EN: Finds the exact calendar issue in the persisted import report.
     *
     * @param array<string,mixed> $summary
     * @return array{0:int,1:int,2:array<string,mixed>}
     */
    private function calendarIssue(
        array $summary,
        string $sourcePageId,
        string $sourceCalendarId,
        string $marker,
    ): array {
        foreach (is_array($summary['review_pages'] ?? null) ? $summary['review_pages'] : [] as $pageIndex => $page) {
            if (!is_array($page) || $this->text($page['source_page_id'] ?? '') !== $sourcePageId) {
                continue;
            }
            foreach (is_array($page['issues'] ?? null) ? $page['issues'] : [] as $issueIndex => $issue) {
                if (
                    is_array($issue)
                    && ($issue['type'] ?? '') === 'calendar'
                    && $this->text($issue['source_calendar_id'] ?? '') === $sourceCalendarId
                    && $this->text($issue['marker'] ?? '') === $marker
                ) {
                    return [(int)$pageIndex, (int)$issueIndex, $issue];
                }
            }
        }

        throw new ConfluenceImportException(__('Confluence calendar makro nije pronađen u izvještaju importa.'));
    }

    /** HR: Zamjenjuje samo točno označeni makro nativnim Calendar placeholderom. EN: Replaces only the exact marked macro with a native Calendar placeholder. */
    private function replaceMarker(string $html, string $marker, string $calendarUuid, string $calendarName): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"><div id="confluence-calendar-root">' . $html . '</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $document->getElementById('confluence-calendar-root');
        $source = $document->getElementById($marker);
        if (!$loaded || !$root instanceof DOMElement || !$source instanceof DOMElement) {
            throw new ConfluenceImportException(__('Oznaka Confluence kalendara više nije prisutna na stranici.'));
        }

        $replacement = $document->createElement('p');
        $replacement->setAttribute('class', 'editor-html-calendar-embed');
        $replacement->setAttribute('data-editor-html-calendar', '1');
        $replacement->setAttribute('data-calendar-uuid', $calendarUuid);
        $replacement->setAttribute('data-calendar-uuids', $calendarUuid);
        $replacement->setAttribute('data-calendar-labels', json_encode(
            [$calendarUuid => $calendarName],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
        $replacement->setAttribute('data-calendar-title', '');
        $replacement->setAttribute('data-calendar-view', 'month');
        $replacement->setAttribute('data-calendar-date-mode', 'today');
        $replacement->setAttribute('data-calendar-date', '');
        $replacement->setAttribute('data-calendar-start-hour', '');
        $replacement->setAttribute('data-calendar-end-hour', '');
        $container = $document->createElement('span');
        $title = $document->createElement('span');
        $title->setAttribute('class', 'editor-html-calendar-placeholder-title');
        $title->appendChild($document->createTextNode(sprintf(__('Kalendar: %s'), $calendarName)));
        $meta = $document->createElement('span');
        $meta->setAttribute('class', 'editor-html-calendar-placeholder-meta');
        $meta->appendChild($document->createTextNode('month · today'));
        $container->appendChild($title);
        $container->appendChild($meta);
        $replacement->appendChild($container);
        $source->parentNode?->replaceChild($replacement, $source);

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }

        return trim($result);
    }

    /** HR: Vraća dostupni i kompatibilni Calendar servis. EN: Returns the available compatible Calendar service. */
    private function calendarManager(): ?object
    {
        if (!interface_exists(self::CALENDAR_MANAGER) || !$this->container->has(self::CALENDAR_MANAGER)) {
            return null;
        }

        try {
            $manager = $this->container->get(self::CALENDAR_MANAGER);
        } catch (Throwable) {
            return null;
        }

        return is_object($manager)
            && method_exists($manager, 'visibleCalendars')
            && method_exists($manager, 'calendarByUuid')
            && method_exists($manager, 'importCalendar')
            ? $manager
            : null;
    }

    /** HR: Zahtijeva dostupni Calendar servis za izmjenu. EN: Requires the Calendar service for a mutation. */
    private function requiredCalendarManager(): object
    {
        $manager = $this->calendarManager();
        if ($manager === null) {
            throw new ConfluenceImportException(__('Calendar modul nije dostupan na ovoj instalaciji.'));
        }

        return $manager;
    }

    /**
     * HR: Poziva prethodno provjerenu metodu opcionalnog modula bez tvrde Composer ovisnosti.
     * EN: Calls a previously validated optional-module method without a hard Composer dependency.
     *
     * @param list<mixed> $arguments
     */
    private function invoke(object $service, string $method, array $arguments): mixed
    {
        $callable = [$service, $method];
        if (!is_callable($callable)) {
            throw new ConfluenceImportException(__('Calendar modul nema potrebnu funkcionalnost.'));
        }

        return $callable(...$arguments);
    }

    /** HR: Sigurno normalizira skalarnu tekstnu vrijednost. EN: Safely normalizes a scalar text value. */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /** HR: Zahtijeva pozitivni cijeli broj ili prekida zadanom porukom. EN: Requires a positive integer or fails with the supplied message. */
    private function positiveInt(mixed $value, string $message): int
    {
        $number = is_numeric($value) ? (int)$value : 0;
        if ($number <= 0) {
            throw new ConfluenceImportException($message);
        }

        return $number;
    }
}
