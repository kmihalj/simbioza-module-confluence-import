<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Controller;

use AaiEduHr\HeartPhrameModuleOrm\Database\LocaleDateFormatter;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceAccessService;
use AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\HeartPhrameModuleMenu\Service\MenuRenderer;
use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceCalendarResolutionService;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportConfig;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportModuleViewRenderer;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportRepository;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportService;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportUploadService;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteAccessService;
use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteContentService;
use DateTimeImmutable;
use DateTimeZone;
use HeartPhrame\Config\ConfigInterface;
use HeartPhrame\Http\ResponseFactory;
use HeartPhrame\Localization\TranslatorInterface;
use HeartPhrame\Routing\UrlGenerator;
use HeartPhrame\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

use function http_build_query;
use function is_resource;
use function time;

use const UPLOAD_ERR_OK;

/**
 * EN: Administration UI for resumable upload, mapping review, and controlled Confluence import.
 *
 * HR: Administratorsko sučelje za nastavivi upload, pregled mapiranja i kontrolirani Confluence import.
 */
final readonly class ConfluenceImportController
{
    /**
     * EN: Receives public owner-module services and shared HTTP services.
     *
     * HR: Prima javne servise vlasničkih modula i zajedničke HTTP servise.
     */
    public function __construct(
        private ResponseFactory $responses,
        private ConfluenceImportModuleViewRenderer $views,
        private WorkspaceAccessService $access,
        private WorkspaceRepository $workspaces,
        private ConfluenceImportRepository $repository,
        private ConfluenceImportUploadService $uploads,
        private ConfluenceImportService $imports,
        private ConfluenceCalendarResolutionService $calendarResolution,
        private ConfluenceImportConfig $config,
        private UrlGenerator $urls,
        private SessionInterface $session,
        private TranslatorInterface $translator,
        private ConfigInterface $applicationConfig,
        private MenuRenderer $menuRenderer,
        private ?ConfluenceSiteAccessService $siteAccess = null,
        private ?ConfluenceSiteContentService $siteContent = null,
        private ?\AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteCalendarService $siteCalendars = null,
        private ?\AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteReportService $siteReports = null,
        private ?\AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteWorkflow $siteWorkflow = null,
    ) {
    }

    /**
     * EN: Shows upload, preflight, mappings, and recent jobs.
     *
     * HR: Prikazuje upload, preflight, mapiranja i zadnje poslove.
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->denied();
        }

        $actorUserId = $this->actorUserId();
        $jobUuid = $this->text($request->getQueryParams()['job'] ?? '');
        $preparation = null;
        $error = '';
        $siteAccessState = null;
        $siteContentState = null;
        $siteCalendarState = null;
        if ($jobUuid !== '') {
            try {
                $preparation = $this->imports->preparation($jobUuid, $actorUserId);
                if (($preparation['scan']['inventory_only'] ?? false) === true) {
                    if (($preparation['job']['status'] ?? '') === 'ready') {
                        $siteAccessState = $this->siteAccess?->state($jobUuid, $actorUserId);
                    }
                    $siteContentState = $this->siteContent?->state($jobUuid, $actorUserId);
                    $siteCalendarState = $this->siteCalendars?->state($jobUuid, $actorUserId);
                }
            } catch (Throwable $throwable) {
                $error = $throwable->getMessage();
            }
        }
        if (($request->getQueryParams()['mapping_review'] ?? '') === '1') {
            if (($siteAccessState['ready'] ?? false) !== true) {
                return $this->responses->text(__('Najprije pripremite pregled identiteta sitea.'), 409);
            }
            return $this->views->render('settings/site-mapping-review', [
                'title' => __('Prijedlozi mapiranja sitea'), 'jobUuid' => $jobUuid,
                'review' => \AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteMappingReview::build($siteAccessState, $siteCalendarState ?? []),
                'targetLabels' => array_column($siteAccessState['target_users'], 'display_name', 'id'),
                'calendarReady' => ($siteCalendarState['catalog'] ?? null) !== null,
                'settingsPath' => $this->path('simbioza-confluence-import.settings', '/settings/confluence-import'),
                'stylesPath' => $this->path('simbioza-confluence-import.assets.css', '/confluence-import/assets.css'),
                'settingsMenuActiveSection' => 'simbioza-confluence-import.settings', 'menuRenderer' => $this->menuRenderer,
            ])->withHeader('Cache-Control', 'no-store, private');
        }
        $activeBatchJob = $this->repository->activeBatchJob($actorUserId);
        $currentAdministrator = $this->access->currentUser();

        return $this->views->render('settings/index', [
            'title' => __('Confluence import'),
            'preparation' => $preparation,
            'siteAccessState' => $siteAccessState,
            'siteContentState' => $siteContentState,
            'siteCalendarState' => $siteCalendarState,
            'siteWorkflowState' => $preparation !== null && ($preparation['scan']['inventory_only'] ?? false)
                ? $this->siteWorkflow?->state($jobUuid, $actorUserId) : null,
            'siteWorkflowPath' => $this->path('simbioza-confluence-import.site.workflow', '/settings/confluence-import/site/workflow'),
            'siteCalendarPath' => $this->path('simbioza-confluence-import.site.calendars', '/settings/confluence-import/site/calendars'),
            'siteContentPath' => $this->path('simbioza-confluence-import.site.content', '/settings/confluence-import/site/content'),
            'siteAccessQuery' => $request->getQueryParams(),
            'siteAccessPath' => $this->path('simbioza-confluence-import.site.access', '/settings/confluence-import/site/access'),
            'siteUsersPath' => $this->path('simbioza-confluence-import.site.users', '/settings/confluence-import/site/users'),
            'initialError' => $error,
            'jobs' => $this->jobPayloads(),
            'siteOverview' => $preparation !== null && ($preparation['scan']['inventory_only'] ?? false) === true
                ? $this->siteReports?->overview($preparation['job']) : null,
            'siteCleanupPath' => $this->path('simbioza-confluence-import.site.cleanup', '/settings/confluence-import/site/cleanup'),
            'settingsPath' => $this->path('simbioza-confluence-import.settings', '/settings/confluence-import'),
            'jobsPath' => $this->path('simbioza-confluence-import.jobs', '/settings/confluence-import/jobs'),
            'csrfPath' => $this->path('simbioza-confluence-import.csrf', '/settings/confluence-import/csrf'),
            'uploadStartPath' => $this->path('simbioza-confluence-import.upload.start', '/settings/confluence-import/upload/start'),
            'uploadChunkPath' => $this->path('simbioza-confluence-import.upload.chunk', '/settings/confluence-import/upload/chunk'),
            'uploadFinishPath' => $this->path('simbioza-confluence-import.upload.finish', '/settings/confluence-import/upload/finish'),
            'batchStartPath' => $this->path('simbioza-confluence-import.batch.start', '/settings/confluence-import/batch/start'),
            'cancelPath' => $this->path('simbioza-confluence-import.cancel', '/settings/confluence-import/cancel'),
            'importPath' => $this->path('simbioza-confluence-import.run', '/settings/confluence-import/run'),
            'processPath' => $this->path('simbioza-confluence-import.process', '/settings/confluence-import/process'),
            'inventorySelectionPath' => $this->path('simbioza-confluence-import.inventory.selection', '/settings/confluence-import/inventory/selection'),
            'stylesPath' => $this->path('simbioza-confluence-import.assets.css', '/confluence-import/assets.css'),
            'userSearchPath' => $this->path('workspace.acl.subjects', '/workspaces/acl/subjects'),
            'batchArchives' => $this->uploads->batchArchives(),
            'activeBatchJob' => $activeBatchJob,
            'currentAdministrator' => is_array($currentAdministrator) ? $currentAdministrator : [],
            'batchDirectory' => 'data/confluence-import/batch-import',
            'csrfName' => $this->session->getCsrfTokenName(),
            'csrfToken' => $this->session->getOrGenerateCsrfToken(),
            'chunkSize' => $this->config->chunkSize(),
            'maxArchiveSize' => $this->config->maxArchiveSize(),
            'defaultLanguage' => $this->config->defaultLanguage(),
            'sourceBaseUrl' => $this->config->sourceBaseUrl(),
            'supportedLanguages' => $this->supportedLanguages(),
            'settingsMenuActiveSection' => 'simbioza-confluence-import.settings',
            'menuRenderer' => $this->menuRenderer,
        ]);
    }

    /**
     * EN: Returns concise localized job status for background refreshes.
     *
     * HR: Vraća kratki lokalizirani status poslova za pozadinsko osvježavanje.
     */
    public function jobs(): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->deniedJson();
        }

        return $this->responses->json(['jobs' => $this->jobPayloads()]);
    }

    /**
     * EN: Shows the durable report for one completed import.
     *
     * HR: Prikazuje trajni izvještaj jednog dovršenog importa.
     */
    public function report(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->denied();
        }

        try {
            $job = $this->repository->jobByUuid($this->text($request->getAttribute('uuid')));
            $siteParent = ($job['summary']['inventory_only'] ?? false) === true || ($job['operation'] ?? '') === 'site_import';
            if ($siteParent) {
                return $this->views->render('settings/site-content-report', [
                    'title' => __('Zajednički izvještaj uvoza sitea'), 'job' => $job, 'summary' => $job['summary'],
                    'siteOverview' => $this->siteReports?->overview($job),
                    'settingsPath' => $this->path('simbioza-confluence-import.settings', '/settings/confluence-import'),
                ]);
            }
            if (($job['status'] ?? '') !== 'completed') {
                return $this->responses->text(__('Izvještaj je dostupan tek nakon dovršenog importa.'), 404);
            }

            $summary = is_array($job['summary'] ?? null) ? $job['summary'] : [];
            if (($job['operation'] ?? '') === 'site_calendars') {
                return $this->views->render('settings/site-calendar-report', [
                    'title' => __('Izvještaj uvoza kalendara sitea'), 'job' => $job, 'summary' => $summary,
                    'settingsPath' => $this->path('simbioza-confluence-import.settings', '/settings/confluence-import'),
                ]);
            }
            if (($job['operation'] ?? '') === 'site_access') {
                return $this->views->render('settings/site-access-report', [
                    'title' => __('Izvještaj identiteta Confluence sitea'), 'job' => $job, 'summary' => $summary,
                    'settingsPath' => $this->path('simbioza-confluence-import.settings', '/settings/confluence-import'),
                    'settingsMenuActiveSection' => 'simbioza-confluence-import.settings', 'menuRenderer' => $this->menuRenderer,
                ]);
            }
            $summary['attachment_report'] = $this->repository->attachmentReport((int)($job['id'] ?? 0));
            $reviewPages = [];
            foreach (is_array($summary['review_pages'] ?? null) ? $summary['review_pages'] : [] as $page) {
                if (is_array($page)) {
                    $reviewPages[] = $page;
                }
            }
            $actor = $this->access->currentUser();
            if (!is_array($actor)) {
                throw new ConfluenceImportException(__('Prijavljeni administrator nije pronađen.'));
            }
            $calendarAvailable = $this->calendarResolution->isAvailable();
            $query = $request->getQueryParams();
            $unresolvedLinkPages = $this->unresolvedLinkPages((int)($job['id'] ?? 0));

            return $this->views->render('settings/report', [
                'title' => __('Izvještaj Confluence importa'),
                'job' => $job,
                'summary' => $summary,
                'reviewPages' => $reviewPages,
                'externalScriptStatuses' => $this->imports->externalScriptStatuses(),
                'externalScriptDecisionPath' => $this->path(
                    'simbioza-confluence-import.report.script',
                    '/settings/confluence-import/report/' . $this->text($job['uuid'] ?? '') . '/script',
                ),
                'unresolvedLinkPages' => $unresolvedLinkPages,
                'calendarAvailable' => $calendarAvailable,
                'calendarOptions' => $calendarAvailable
                    ? $this->calendarResolution->availableCalendars($actor)
                    : [],
                'calendarResolvePath' => $this->calendarResolutionPath($this->text($job['uuid'] ?? '')),
                'calendarAdminPath' => $calendarAvailable
                    ? $this->path('calendar.admin', '/settings/calendar')
                    : null,
                'calendarResolutionStatus' => $this->text($query['calendar_resolution'] ?? ''),
                'calendarResolutionMessage' => $this->text($query['calendar_message'] ?? ''),
                'manualCorrectionStatus' => $this->text($query['manual_correction'] ?? ''),
                'manualCorrectionMessage' => $this->text($query['manual_message'] ?? ''),
                'linkCorrectionPath' => $this->linkCorrectionPath($this->text($job['uuid'] ?? '')),
                'reviewCorrectionPath' => $this->reviewCorrectionPath($this->text($job['uuid'] ?? '')),
                'settingsPath' => $this->path('simbioza-confluence-import.settings', '/settings/confluence-import'),
                'workspacePath' => $this->workspacePathById($this->integer($job['workspace_id'] ?? 0)),
                'stylesPath' => $this->path('simbioza-confluence-import.assets.css', '/confluence-import/assets.css'),
                'settingsMenuActiveSection' => 'simbioza-confluence-import.settings',
                'menuRenderer' => $this->menuRenderer,
            ]);
        } catch (Throwable) {
            return $this->responses->text(__('Izvještaj Confluence importa nije pronađen.'), 404);
        }
    }

    /**
     * EN: Manually imports or links a calendar from the durable report.
     *
     * HR: Ručno uvozi ili povezuje kalendar iz trajnog izvještaja.
     */
    public function resolveCalendar(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->denied();
        }

        $uuid = $this->text($request->getAttribute('uuid'));
        try {
            $body = $this->body($request);
            $actor = $this->access->currentUser();
            if (!is_array($actor)) {
                throw new ConfluenceImportException(__('Prijavljeni administrator nije pronađen.'));
            }

            $ics = $this->text($body['resolution_mode'] ?? '') === 'import'
                ? $this->uploadedIcsContent($request)
                : '';

            /*
             * EN: After CSRF, authorization, and upload parsing, the session is no
             *     longer mutated. Release its PHP lock before the potentially long
             *     ICS import so the administrator's other tabs remain responsive.
             *
             * HR: Nakon CSRF-a, ovlasti i uploada više ne mijenjamo session. Dugi
             *     ICS uvoz zato ne smije držati PHP session lock i blokirati sve
             *     ostale tabove istoga administratora.
             */
            $this->session->close();
            $result = $this->calendarResolution->resolve($uuid, $body, $ics, $actor);
            $message = sprintf(
                __('Stranica sada prikazuje kalendar „%s”.'),
                $this->text($result['target_calendar_name'] ?? __('Kalendar')),
            );

            return $this->responses->redirect($this->calendarResolutionRedirect($uuid, 'success', $message));
        } catch (Throwable $throwable) {
            return $this->responses->redirect(
                $this->calendarResolutionRedirect($uuid, 'error', $throwable->getMessage()),
            );
        }
    }

    /**
     * EN: Confirms that an administrator corrected one unresolved-link group.
     *
     * HR: Potvrđuje da je administrator korigirao jednu grupu nerazriješenih poveznica.
     */
    public function markLinkCorrected(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->denied();
        }

        $uuid = $this->text($request->getAttribute('uuid'));
        try {
            $job = $this->repository->jobByUuid($uuid);
            if (($job['status'] ?? '') !== 'completed') {
                throw new ConfluenceImportException(__('Izvještaj je dostupan tek nakon dovršenog importa.'));
            }
            $body = $this->body($request);
            $linkUuids = array_values(array_filter(array_map(
                $this->text(...),
                is_array($body['link_uuids'] ?? null) ? $body['link_uuids'] : [],
            ), static fn(string $linkUuid): bool => $linkUuid !== ''));
            $count = $this->repository->markLinksManuallyResolved(
                $this->integer($job['id'] ?? 0),
                $linkUuids,
            );
            if ($count <= 0) {
                throw new ConfluenceImportException(__('Poveznica više nije nerazriješena ili nije pronađena.'));
            }

            return $this->responses->redirect($this->manualCorrectionRedirect(
                $uuid,
                'success',
                __('Poveznica je označena kao korigirana i više se neće automatski mijenjati.'),
            ));
        } catch (Throwable $throwable) {
            return $this->responses->redirect(
                $this->manualCorrectionRedirect($uuid, 'error', $throwable->getMessage()),
            );
        }
    }

    /**
     * EN: Confirms that an administrator manually corrected an unsupported Confluence macro.
     *
     * HR: Potvrđuje da je administrator ručno korigirao nepodržani Confluence makro.
     */
    public function markReviewCorrected(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->denied();
        }

        $uuid = $this->text($request->getAttribute('uuid'));
        try {
            $job = $this->repository->jobByUuid($uuid);
            if (($job['status'] ?? '') !== 'completed') {
                throw new ConfluenceImportException(__('Izvještaj je dostupan tek nakon dovršenog importa.'));
            }
            $body = $this->body($request);
            $marked = $this->repository->markReviewIssueManuallyResolved(
                $this->integer($job['id'] ?? 0),
                $this->text($body['source_page_id'] ?? ''),
                $this->text($body['issue_type'] ?? ''),
                $this->text($body['macro'] ?? ''),
                $this->text($body['marker'] ?? ''),
                $this->actorUserId(),
            );
            if (!$marked) {
                throw new ConfluenceImportException(__('Upozorenje više nije otvoreno ili nije pronađeno.'));
            }

            return $this->responses->redirect($this->manualCorrectionRedirect(
                $uuid,
                'success',
                __('Sadržaj je označen kao korigiran i uklonjen iz popisa za provjeru.'),
            ));
        } catch (Throwable $throwable) {
            return $this->responses->redirect(
                $this->manualCorrectionRedirect($uuid, 'error', $throwable->getMessage()),
            );
        }
    }

    /**
     * EN: Only an administrator may decide on a script already recorded by this import.
     *
     * HR: Samo administrator smije odlučiti o skripti koju je ovaj import već zabilježio.
     */
    public function decideExternalScript(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->denied();
        }
        $uuid = $this->text($request->getAttribute('uuid'));
        $body = $this->body($request);
        try {
            $actor = $this->access->currentUser();
            if (!is_array($actor)) {
                throw new ConfluenceImportException(__('Prijavljeni administrator nije pronađen.'));
            }
            $this->imports->decideExternalScript(
                $uuid,
                $this->text($body['script_id'] ?? ''),
                $this->text($body['decision'] ?? ''),
                $this->text($body['scope'] ?? 'none'),
                $this->text($body['source_page_id'] ?? ''),
                $actor,
            );
            return $this->responses->redirect($this->manualCorrectionRedirect($uuid, 'success', __('Odluka je spremljena.')));
        } catch (Throwable $error) {
            return $this->responses->redirect($this->manualCorrectionRedirect($uuid, 'error', $error->getMessage()));
        }
    }

    /**
     * EN: Refreshes the CSRF token for chunked uploads.
     *
     * HR: Osvježava CSRF token za upload po dijelovima.
     */
    public function csrf(): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->deniedJson();
        }

        // EN: An explicit mutation refreshes the PHP session timestamp even when the CSRF token is unchanged.
        //
        // HR: Izričita promjena jamči osvježavanje vremena PHP sesije i kada se CSRF token ne mijenja.
        $this->session->set('simbioza_confluence_import_activity', time());

        return $this->responses->json(['csrf_token' => $this->session->getOrGenerateCsrfToken()]);
    }

    /**
     * EN: Starts resumable upload after validating name and size.
     *
     * HR: Otvara nastavivi upload nakon provjere naziva i veličine.
     */
    public function uploadStart(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->deniedJson();
        }

        try {
            $body = $this->body($request);
            $job = $this->uploads->start(
                $this->text($body['name'] ?? ''),
                $this->integer($body['size'] ?? 0),
                $this->actorUserId(),
            );

            return $this->responses->json($this->uploadPayload($job), 201);
        } catch (Throwable $throwable) {
            return $this->errorJson($throwable);
        }
    }

    /**
     * EN: Stores the next binary chunk only at the negotiated offset.
     *
     * HR: Sprema sljedeći binarni dio samo na dogovoreni offset.
     */
    public function uploadChunk(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->deniedJson();
        }

        try {
            $stream = $request->getBody()->detach();
            if (!is_resource($stream)) {
                throw new ConfluenceImportException(__('Dio uploada nije moguće pročitati.'));
            }
            $job = $this->uploads->append(
                trim($request->getHeaderLine('X-Confluence-Import-Upload')),
                (int)$request->getHeaderLine('X-Confluence-Import-Offset'),
                $stream,
                $this->actorUserId(),
            );

            return $this->responses->json($this->uploadPayload($job));
        } catch (Throwable $throwable) {
            return $this->errorJson($throwable);
        }
    }

    /**
     * EN: Validates the completed ZIP and performs preflight without changing application data.
     *
     * HR: Provjerava dovršeni ZIP i radi preflight bez promjene aplikacijskih podataka.
     */
    public function uploadFinish(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->deniedJson();
        }

        try {
            $uuid = $this->text($this->body($request)['uuid'] ?? '');
            $actorUserId = $this->actorUserId();
            $this->session->close();
            $job = $this->uploads->finish($uuid, $actorUserId);

            return $this->responses->json([
                ...$this->uploadPayload($job),
                'scan' => $job['scan'] ?? [],
                'mapping_url' => $this->path('simbioza-confluence-import.settings', '/settings/confluence-import')
                    . '?job=' . rawurlencode((string)($job['uuid'] ?? '')),
            ]);
        } catch (Throwable $throwable) {
            return $this->errorJson($throwable);
        }
    }

    /**
     * EN: Adopts or resumes the next server-side batch archive and immediately starts its separate import.
     *
     * HR: Preuzima ili nastavlja sljedeću serversku batch arhivu i odmah pokreće njezin zasebni import.
     */
    public function batchStart(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->deniedJson();
        }

        try {
            $body = $this->body($request);
            $actor = $this->access->currentUser();
            if (!is_array($actor)) {
                throw new ConfluenceImportException(__('Prijavljeni administrator nije pronađen.'));
            }
            $actorUserId = $this->actorUserId();
            $this->session->close();
            $active = $this->repository->activeBatchJob($actorUserId);
            $requestedUuid = $this->text($body['uuid'] ?? '');
            if (is_array($active)) {
                if ($requestedUuid !== '' && $requestedUuid !== $this->text($active['uuid'] ?? '')) {
                    throw new ConfluenceImportException(__('Prethodni batch import još nije dovršen.'));
                }
                $job = $active;
            } else {
                $job = $this->uploads->adoptBatchArchive(
                    $this->text($body['name'] ?? ''),
                    $actorUserId,
                );
            }

            $createInactiveUsers = ($body['create_inactive_users'] ?? false) === true
                || $this->text($body['create_inactive_users'] ?? '') === '1';
            $progress = $this->imports->queueBatch(
                $this->text($job['uuid'] ?? ''),
                $actor,
                $createInactiveUsers,
                $this->text($body['source_base_url'] ?? ''),
            );

            return $this->responses->json([
                ...$progress,
                'uuid' => $this->text($job['uuid'] ?? ''),
                'name' => $this->text($job['original_name'] ?? ''),
                'queued' => true,
                'message' => __('Batch Confluence import je pokrenut.'),
            ], 201);
        } catch (Throwable $throwable) {
            return $this->errorJson($throwable);
        }
    }

    /**
     * EN: Cancels the actor's unfinished import and immediately deletes its uploaded archive.
     *
     * HR: Otkazuje vlastiti nedovršeni import i odmah briše prenesenu arhivu.
     */
    public function cancel(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->deniedJson();
        }

        try {
            $body = $this->body($request);
            $job = $this->uploads->cancel(
                $this->text($body['uuid'] ?? ''),
                $this->actorUserId(),
            );

            return $this->responses->json([
                'cancelled' => true,
                'uuid' => $this->text($job['uuid'] ?? ''),
                'message' => __('Confluence import je otkazan, a prenesena arhiva obrisana.'),
            ]);
        } catch (Throwable $throwable) {
            return $this->errorJson($throwable);
        }
    }

    /**
     * EN: Prepares a confirmed resumable import.
     *
     * HR: Priprema potvrđeni nastavivi import.
     */
    public function run(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->deniedJson();
        }

        try {
            $body = $this->body($request);
            $uuid = $this->text($body['uuid'] ?? '');
            $actor = $this->access->currentUser();
            if (!is_array($actor)) {
                throw new ConfluenceImportException(__('Prijavljeni administrator nije pronađen.'));
            }

            $this->session->close();
            $job = $this->repository->jobByUuid($uuid, (int)$actor['id']);
            if ($job['operation'] === 'site_space') {
                throw new ConfluenceImportException(__('Koristite roditeljski posao za uvoz sitea.'));
            }
            $progress = ($job['summary']['inventory_only'] ?? false) && $this->siteWorkflow instanceof \AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteWorkflow
                ? $this->siteWorkflow->start($uuid, $actor, $this->text($body['token'] ?? ''))
                : $this->imports->queue($uuid, $body, $actor);

            return $this->responses->json([
                'imported' => false,
                'queued' => true,
                ...$progress,
                'message' => __('Confluence import je pokrenut.'),
            ]);
        } catch (Throwable $throwable) {
            return $this->errorJson($throwable);
        }
    }

    /**
     * EN: Runs the next bounded import step and returns actual progress.
     *
     * HR: Izvodi sljedeći ograničeni korak importa i vraća stvarni napredak.
     */
    public function process(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->deniedJson();
        }

        try {
            $body = $this->body($request);
            $actor = $this->access->currentUser();
            if (!is_array($actor)) {
                throw new ConfluenceImportException(__('Prijavljeni administrator nije pronađen.'));
            }

            $this->session->close();
            $uuid = $this->text($body['uuid'] ?? '');
            $job = $this->repository->jobByUuid($uuid, (int)$actor['id']);
            if ($job['operation'] === 'site_space') {
                throw new ConfluenceImportException(__('Koristite roditeljski posao za uvoz sitea.'));
            }
            $progress = $job['operation'] === 'site_import' && $this->siteWorkflow instanceof \AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteWorkflow
                ? $this->siteWorkflow->process($uuid, $actor) : $this->imports->process($uuid, $actor);
            $summary = is_array($progress['summary'] ?? null) ? $progress['summary'] : [];
            $slug = $this->text($summary['workspace_slug'] ?? '');

            return $this->responses->json([
                ...$progress,
                'imported' => ($progress['completed'] ?? false) === true,
                'workspace_url' => ($progress['completed'] ?? false) === true
                    ? ($slug !== '' ? $this->workspacePath($slug) : $this->path('workspace.index', '/workspaces'))
                    : null,
                'message' => ($progress['completed'] ?? false) === true
                    ? __('Confluence područje uspješno je uvezeno.')
                    : __('Confluence import je u tijeku.'),
            ]);
        } catch (Throwable $throwable) {
            return $this->errorJson($throwable);
        }
    }

    /**
     * EN: Saves the authenticated owner's reviewed site selection without importing content.
     *
     * HR: Sprema pregledani odabir sitea prijavljenog vlasnika bez uvoza sadržaja.
     */
    public function inventorySelection(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->deniedJson();
        }
        try {
            $body = $this->body($request);
            $ids = $body['space_ids'] ?? [];
            if (!is_array($ids)) {
                throw new ConfluenceImportException(__('Odabir područja nije valjan.'));
            }
            $selected = $this->uploads->saveInventorySelection(
                $this->text($body['uuid'] ?? ''),
                $this->actorUserId(),
                $this->text($body['source_sha256'] ?? ''),
                $ids,
            );
            return $this->responses->json(['saved' => true, 'space_ids' => $selected,
                'message' => __('Odabir područja je spremljen. Sadržaj nije uvezen.')]);
        } catch (Throwable $error) {
            return $this->errorJson($error);
        }
    }

    /**
     * EN: The existing administrator/CSRF boundary protects preparation, review and explicit apply.
     * Release the session before the potentially long read-only catalog scan.
     *
     * HR: Postojeća administratorska/CSRF granica štiti pripremu, pregled i izričitu primjenu.
     * Prije mogućeg dugog čitanja pregleda otpusti sjednicu.
     */
    public function siteAccess(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator() || !$this->siteAccess instanceof \AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteAccessService) {
            return $this->deniedJson();
        }
        try {
            $body = $this->body($request);
            $uuid = $this->text($body['uuid'] ?? '');
            $actor = $this->actorUserId();
            $action = $this->text($body['action'] ?? '');
            $this->session->close();
            if ($action === 'prepare') {
                $result = $this->siteAccess->prepare($uuid, $actor);
            } elseif ($action === 'reset') {
                $result = $this->siteAccess->resetReview($uuid, $actor);
            } elseif ($action === 'review') {
                if (!is_array($body['users'] ?? null) || !is_array($body['groups'] ?? null) || !is_bool($body['memberships'] ?? null)) {
                    throw new ConfluenceImportException(__('Odabir identiteta nije valjan.'));
                }
                foreach (['auto_map_users', 'create_unmapped_users', 'auto_map_groups', 'create_unmapped_groups', 'create_archival_users'] as $flag) {
                    if (isset($body[$flag]) && !is_bool($body[$flag])) {
                        throw new ConfluenceImportException(__('Odabir identiteta nije valjan.'));
                    }
                }
                $result = $this->siteAccess->review(
                    $uuid,
                    $actor,
                    $this->text($body['source_url'] ?? ''),
                    $body['users'],
                    $body['groups'],
                    $body['memberships'],
                    $body['auto_map_users'] ?? false,
                    $body['create_unmapped_users'] ?? false,
                    $body['auto_map_groups'] ?? false,
                    $body['create_unmapped_groups'] ?? false,
                    $body['create_archival_users'] ?? false
                );
            } elseif ($action === 'apply') {
                throw new ConfluenceImportException(__('Najprije potvrdite zajednički pregled uvoza sitea.'));
            } else {
                throw new ConfluenceImportException(__('Odabir identiteta nije valjan.'));
            }
            return $this->responses->json($result);
        } catch (Throwable $error) {
            return $this->errorJson($error);
        }
    }

    /**
     * EN: Searches only non-secret user fields for the authenticated owner of this site preview.
     *
     * HR: Pretražuje samo netajna korisnička polja za prijavljenog vlasnika ovog pregleda sitea.
     */
    public function siteUsers(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator() || !$this->siteAccess instanceof \AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteAccessService) {
            return $this->deniedJson();
        }
        try {
            $query = $request->getQueryParams();
            if (($query['scope'] ?? '') === 'calendar-owners' && $this->siteCalendars instanceof \AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteCalendarService) {
                return $this->responses->json($this->siteCalendars->ownerPage(
                    $this->text($query['uuid'] ?? ''),
                    $this->actorUserId(),
                    $this->text($query['q'] ?? ''),
                    min(4001, max(1, $this->integer($query['page'] ?? 1)))
                ));
            }
            return $this->responses->json($this->siteAccess->userPage(
                $this->text($query['uuid'] ?? ''),
                $this->actorUserId(),
                $this->text($query['q'] ?? ''),
                min(4001, max(1, $this->integer($query['page'] ?? 1)))
            ));
        } catch (Throwable $error) {
            return $this->errorJson($error);
        }
    }

    /**
     * EN: Reviews content configuration only; execution belongs to the unified site coordinator.
     *
     * HR: Pregledava samo postavke sadržaja; izvršavanje pripada zajedničkom koordinatoru sitea.
     */
    public function siteContent(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator() || !$this->siteContent instanceof \AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteContentService) {
            return $this->deniedJson();
        }
        try {
            $body = $this->body($request);
            $actor = $this->access->currentUser();
            if (!is_array($actor)) {
                throw new ConfluenceImportException(__('Prijavljeni administrator nije pronađen.'));
            }
            $this->session->close();
            $uuid = $this->text($body['uuid'] ?? '');
            if (($body['action'] ?? 'review') === 'retry') {
                throw new ConfluenceImportException(__('Najprije potvrdite zajednički pregled uvoza sitea.'));
            }
            $result = $this->siteContent->review($uuid, (int)$actor['id'], $body);
            return $this->responses->json($result);
        } catch (Throwable $error) {
            return $this->errorJson($error);
        }
    }

    /**
     * EN: Only the authenticated administrator can review, confirm or advance the owned unified site workflow.
     * Ordinary route middleware provides the same CSRF boundary as the existing importer actions.
     *
     * HR: Samo prijavljeni administrator smije pregledati, potvrditi ili pomaknuti vlastiti zajednički uvoz sitea.
     * Uobičajeni middleware rute daje istu CSRF zaštitu kao postojeće radnje importera.
     */
    public function siteWorkflow(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator() || !$this->siteWorkflow instanceof \AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteWorkflow) {
            return $this->deniedJson();
        }
        try {
            $body = $this->body($request);
            $actor = $this->access->currentUser();
            if (!is_array($actor)) {
                throw new ConfluenceImportException(__('Prijavljeni administrator nije pronađen.'));
            }
            $this->session->close();
            $uuid = $this->text($body['uuid'] ?? '');
            $result = match ($this->text($body['action'] ?? '')) {
                'draft' => $this->siteWorkflow->draft($uuid, (int)$actor['id'], $body),
                'review' => $this->siteWorkflow->review($uuid, $actor, $body),
                'start' => $this->siteWorkflow->start($uuid, $actor, $this->text($body['token'] ?? '')),
                'process' => $this->siteWorkflow->process($uuid, $actor),
                default => throw new ConfluenceImportException(__('Odabir zajedničkog uvoza nije valjan.')),
            };
            return $this->responses->json($result);
        } catch (Throwable $error) {
            return $this->errorJson($error);
        }
    }

    /**
     * EN: Prepares the calendar catalog only; independent HTTP execution cannot bypass final confirmation.
     *
     * HR: Priprema samo pregled kalendara; neovisno HTTP izvršavanje ne smije zaobići završnu potvrdu.
     */
    public function siteCalendars(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator() || !$this->siteCalendars instanceof \AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteCalendarService) {
            return $this->deniedJson();
        }
        try {
            $body = $this->body($request);
            $actor = $this->access->currentUser();
            if (!is_array($actor)) {
                throw new ConfluenceImportException(__('Prijavljeni administrator nije pronađen.'));
            }
            $uuid = $this->text($body['uuid'] ?? '');
            $this->session->close();
            $result = match ($body['action'] ?? '') {
                'prepare' => $this->siteCalendars->prepare($uuid, (int)$actor['id']),
                default => throw new ConfluenceImportException(__('Najprije potvrdite zajednički pregled uvoza sitea.')),
            };
            return $this->responses->json($result);
        } catch (Throwable $error) {
            return $this->errorJson($error);
        }
    }

    /**
     * EN: Reads owner-authorized progress without rotating single-use mutation CSRF tokens.
     * GET cannot start preparation, import data or change an import plan.
     *
     * HR: Čita napredak ovlaštenom vlasniku bez rotiranja jednokratnih CSRF tokena za promjene.
     * GET ne može pokrenuti pripremu, uvesti podatke ili promijeniti plan uvoza.
     */
    public function siteCalendarPreparation(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator() || !$this->siteCalendars instanceof \AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteCalendarService) {
            return $this->deniedJson();
        }
        try {
            $actor = $this->actorUserId();
            $this->session->close();
            $uuid = $this->text($request->getQueryParams()['uuid'] ?? '');
            return $this->responses->json($this->siteCalendars->preparation($uuid, $actor))->withHeader('Cache-Control', 'no-store');
        } catch (Throwable $error) {
            return $this->errorJson($error);
        }
    }

    /**
     * EN: Releases the owner's completed site source only after explicit confirmation and normal CSRF checks.
     *
     * HR: Uklanja izvor dovršenog sitea vlasnika samo nakon izričite potvrde i običnih CSRF provjera.
     */
    public function siteCleanup(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->deniedJson();
        }
        try {
            $body = $this->body($request);
            if (($body['confirm'] ?? null) !== 'release-source') {
                throw new ConfluenceImportException(__('Potvrdite uklanjanje radne kopije arhive.'));
            }
            return $this->responses->json($this->uploads->releaseSiteSource($this->text($body['uuid'] ?? ''), $this->actorUserId()));
        } catch (Throwable $error) {
            return $this->errorJson($error);
        }
    }

    /**
     * EN: Downloads an imported attachment only after rechecking current Workspace/page ACL.
     *
     * HR: Preuzima uvezeni privitak tek nakon ponovne provjere aktualnog Workspace/page ACL-a.
     */
    public function attachment(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $uuid = $this->text($request->getAttribute('uuid'));
            $attachment = $this->repository->attachmentByUuid($uuid);
            if (!$this->canViewTarget($attachment)) {
                return $this->denied();
            }

            $path = $this->text($attachment['storage_path'] ?? '');
            $root = rtrim($this->config->attachmentDirectory(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if ($path === '' || !str_starts_with($path, $root) || !is_file($path)) {
                return $this->responses->text(__('Uvezeni privitak nije pronađen.'), 404);
            }

            return $this->responses->file(
                $path,
                'application/octet-stream',
                basename($this->text($attachment['original_name'] ?? 'attachment')),
                headers: [
                    'Cache-Control' => 'private, no-store, max-age=0',
                    'X-Content-Type-Options' => 'nosniff',
                    'Content-Security-Policy' => "default-src 'none'; sandbox",
                ],
            );
        } catch (Throwable) {
            return $this->responses->text(__('Uvezeni privitak nije pronađen.'), 404);
        }
    }

    /**
     * EN: Resolves a cross-space link or returns a clear unresolved message.
     *
     * HR: Razrješava međupodručnu poveznicu ili daje jasnu neriješenu poruku.
     */
    public function link(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $link = $this->repository->linkByUuid($this->text($request->getAttribute('uuid')));
            $source = $this->repository->contentBySource(
                $this->text($link['source_space_key'] ?? ''),
                $this->text($link['source_page_id'] ?? ''),
            );
            if (!is_array($source) || !$this->canViewTarget($source)) {
                return $this->denied();
            }

            $target = $this->text($link['resolved_target'] ?? '');
            if (($link['status'] ?? '') === 'resolved' && $this->safeLocalPath($target)) {
                return $this->responses->redirect($target);
            }

            return $this->responses->text(
                __('Povezano Confluence područje još nije uvezeno. Poveznica će se automatski pokušati razriješiti nakon njegova importa.'),
                404,
            );
        } catch (Throwable) {
            return $this->responses->text(__('Uvezena poveznica nije pronađena.'), 404);
        }
    }

    /**
     * EN: Serves small CSS that uses Bootstrap and active-theme variables.
     *
     * HR: Poslužuje mali CSS koji koristi Bootstrap i varijable aktivne teme.
     */
    public function styles(): ResponseInterface
    {
        $path = dirname(__DIR__, 2) . '/resources/assets/confluence-import.css';
        $css = is_file($path) ? file_get_contents($path) : '';

        return $this->responses->text(is_string($css) ? $css : '', contentType: 'text/css; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    /**
     * EN: Rechecks the target page's current ACL.
     *
     * HR: Ponovno provjerava aktualni ACL ciljne stranice.
     *
     * @param array<string,mixed> $target
     */
    private function canViewTarget(array $target): bool
    {
        $workspaceId = $this->integer($target['target_workspace_id'] ?? 0);
        $nodeId = $this->integer($target['target_node_id'] ?? 0);
        $workspace = $workspaceId > 0 ? $this->workspaces->findWorkspaceById($workspaceId) : null;
        $node = $nodeId > 0 ? $this->workspaces->findNodeById($nodeId) : null;
        if (!is_array($workspace) || !is_array($node)) {
            return false;
        }

        return (bool)($this->access->nodePermissions($workspace, $node)['can_view'] ?? false);
    }

    /**
     * EN: Prepares localized recent-job payloads.
     *
     * HR: Priprema lokalizirane podatke nedavnih poslova.
     *
     * @return list<array<string,mixed>>
     */
    private function jobPayloads(): array
    {
        $result = [];
        $actorUserId = $this->actorUserId();
        foreach ($this->repository->recentJobs() as $job) {
            $result[] = [
                'uuid' => $this->text($job['uuid'] ?? ''),
                'name' => $this->text($job['original_name'] ?? ''),
                'space_name' => $this->text($job['source_space_name'] ?? ''),
                'space_key' => $this->text($job['source_space_key'] ?? ''),
                'status' => $this->text($job['status'] ?? ''),
                'status_label' => $this->statusLabel($this->text($job['status'] ?? '')),
                'stage' => $this->text($job['stage'] ?? ''),
                'stage_label' => $this->stageLabel($this->text($job['stage'] ?? '')),
                'archive_size' => $this->integer($job['archive_size'] ?? 0),
                'next_offset' => $this->integer($job['next_offset'] ?? 0),
                'chunk_size' => $this->integer($job['chunk_size'] ?? $this->config->chunkSize()),
                'created_at_display' => $this->formatUtcDateTime($job['created_at'] ?? null),
                'error' => $this->text($job['error_message'] ?? ''),
                'can_cancel' => $this->integer($job['actor_user_id'] ?? 0) === $actorUserId
                    && $this->uploads->canCancel($job),
                'can_resume' => $this->integer($job['actor_user_id'] ?? 0) === $actorUserId
                    && ($job['status'] ?? '') === 'running' && ($job['operation'] ?? '') !== 'site_space',
                'mapping_url' => in_array(($job['status'] ?? ''), ['ready', 'running', 'failed'], true) && ($job['operation'] ?? '') !== 'site_space'
                    ? $this->path('simbioza-confluence-import.settings', '/settings/confluence-import')
                        . '?job=' . rawurlencode($this->text($job['uuid'] ?? ''))
                    : null,
                'workspace_url' => $this->integer($job['workspace_id'] ?? 0) > 0
                    ? $this->workspacePathById($this->integer($job['workspace_id']))
                    : null,
                'report_url' => ($job['status'] ?? '') === 'completed'
                    ? $this->reportPath($this->text($job['uuid'] ?? ''))
                    : null,
            ];
        }

        return $result;
    }

    /**
     * EN: Converts a job into a resumable-upload response.
     *
     * HR: Pretvara posao u odgovor nastavivog uploada.
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private function uploadPayload(array $job): array
    {
        return [
            'uuid' => $this->text($job['uuid'] ?? ''),
            'name' => $this->text($job['original_name'] ?? ''),
            'status' => $this->text($job['status'] ?? ''),
            'stage' => $this->text($job['stage'] ?? ''),
            'archive_size' => $this->integer($job['archive_size'] ?? 0),
            'next_offset' => $this->integer($job['next_offset'] ?? 0),
            'chunk_size' => $this->integer($job['chunk_size'] ?? $this->config->chunkSize()),
        ];
    }

    /**
     * EN: Reads form data or a JSON request body.
     *
     * HR: Čita podatke forme ili JSON tijelo zahtjeva.
     *
     * @return array<string,mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (is_array($body) && $body !== []) {
            return $body;
        }

        $raw = (string)$request->getBody();
        $decoded = $raw !== '' ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : (is_array($body) ? $body : []);
    }

    /**
     * EN: Returns the authenticated administrator ID.
     *
     * HR: Vraća ID prijavljenog administratora.
     */
    private function actorUserId(): int
    {
        $user = $this->access->currentUser();
        $id = is_array($user) ? $this->integer($user['id'] ?? 0) : 0;
        if ($id <= 0) {
            throw new ConfluenceImportException(__('Prijavljeni administrator nije pronađen.'));
        }

        return $id;
    }

    /**
     * EN: Builds a Workspace homepage path.
     *
     * HR: Gradi putanju naslovnice područja.
     */
    private function workspacePath(string $slug): string
    {
        if ($this->urls->namedRouteExists('workspace.show')) {
            return $this->urls->getPathFor('workspace.show', ['workspaceSlug' => $slug]);
        }

        return rtrim($this->urls->getBasePath(), '/') . '/workspace/' . rawurlencode($slug);
    }

    /**
     * EN: Builds a Workspace path from its ID.
     *
     * HR: Gradi putanju područja iz njegova ID-a.
     */
    private function workspacePathById(int $workspaceId): ?string
    {
        $workspace = $workspaceId > 0 ? $this->workspaces->findWorkspaceById($workspaceId) : null;
        $slug = is_array($workspace) ? $this->text($workspace['slug'] ?? '') : '';

        return $slug !== '' ? $this->workspacePath($slug) : null;
    }

    /**
     * EN: Groups currently unresolved report links by their source page.
     *
     * HR: Grupira trenutačno nerazriješene poveznice po izvornoj stranici izvještaja.
     *
     * @return list<array{source_page_id:string,title:string,url:string,links:list<array{target:string,destination:string,uuids:list<string>}>}>
     */
    private function unresolvedLinkPages(int $jobId): array
    {
        /** @var array<string,array{source_page_id:string,title:string,url:string}> $pages */
        $pages = [];
        /** @var array<string,array<string,array{target:string,destination:string,uuids:list<string>}>> $linksByPage */
        $linksByPage = [];
        foreach ($this->repository->unresolvedLinksForJob($jobId) as $link) {
            $sourcePageId = $this->text($link['source_page_id'] ?? '');
            $target = $this->text($link['original_target'] ?? '');
            if ($sourcePageId === '') {
                continue;
            }
            $uniqueKey = implode("\0", [
                $sourcePageId,
                $target,
                $this->text($link['destination_space_key'] ?? ''),
                $this->text($link['destination_page_id'] ?? ''),
                $this->text($link['destination_page_title'] ?? ''),
            ]);
            $mapping = $this->repository->contentForJobSource($jobId, $sourcePageId);
            $pageKey = $sourcePageId;
            $pages[$pageKey] ??= [
                'source_page_id' => $sourcePageId,
                'title' => is_array($mapping)
                    ? $this->text($mapping['source_title'] ?? '')
                    : '',
                'url' => is_array($mapping) ? $this->contentPath($mapping) : '',
            ];
            $destinationSpace = $this->text($link['destination_space_key'] ?? '');
            $destinationId = $this->text($link['destination_page_id'] ?? '');
            $destinationTitle = $this->text($link['destination_page_title'] ?? '');
            $destination = $destinationTitle;
            if ($destination === '' && $destinationId !== '') {
                $destination = sprintf(__('Confluence stranica ID %s'), $destinationId);
            }
            if ($destination === '' && $destinationSpace !== '') {
                $destination = sprintf(__('Naslovnica područja %s'), $destinationSpace);
            }
            if ($destination === '') {
                $destination = __('Neprepoznata Confluence poveznica');
            } elseif ($destinationSpace !== '' && $destinationTitle !== '') {
                $destination = $destinationSpace . ' · ' . $destination;
            }
            $linksByPage[$pageKey][$uniqueKey] ??= [
                'target' => $target,
                'destination' => $destination,
                'uuids' => [],
            ];
            $linkUuid = $this->text($link['uuid'] ?? '');
            if ($linkUuid !== '') {
                $linksByPage[$pageKey][$uniqueKey]['uuids'][] = $linkUuid;
            }
        }

        $result = [];
        foreach ($pages as $pageKey => $page) {
            $result[] = [
                ...$page,
                'links' => array_values($linksByPage[$pageKey] ?? []),
            ];
        }

        return $result;
    }

    /**
     * EN: Builds a local imported-node path from its durable mapping.
     *
     * HR: Gradi lokalnu putanju jednog uvezenog čvora iz trajnog mapiranja.
     *
     * @param array<string,mixed> $mapping
     */
    private function contentPath(array $mapping): string
    {
        $space = $this->repository->spaceByWorkspaceId($this->integer($mapping['target_workspace_id'] ?? 0));
        $workspaceSlug = is_array($space) ? $this->text($space['target_workspace_slug'] ?? '') : '';
        $nodeSlug = $this->text($mapping['target_slug'] ?? '');
        if ($workspaceSlug === '' || $nodeSlug === '') {
            return '';
        }
        if ($this->urls->namedRouteExists('workspace.node.show')) {
            return $this->urls->getPathFor('workspace.node.show', [
                'workspaceSlug' => $workspaceSlug,
                'nodeSlug' => $nodeSlug,
            ]);
        }

        return rtrim($this->urls->getBasePath(), '/') . '/workspace/'
            . rawurlencode($workspaceSlug) . '/' . rawurlencode($nodeSlug);
    }

    /**
     * EN: Builds the administrator path for a durable report.
     *
     * HR: Gradi administratorsku putanju trajnog izvještaja.
     */
    private function reportPath(string $uuid): string
    {
        if ($this->urls->namedRouteExists('simbioza-confluence-import.report')) {
            return $this->urls->getPathFor('simbioza-confluence-import.report', ['uuid' => $uuid]);
        }

        return rtrim($this->urls->getBasePath(), '/')
            . '/settings/confluence-import/report/' . rawurlencode($uuid);
    }

    /**
     * EN: Builds the manual calendar-resolution path.
     *
     * HR: Gradi putanju ručnog razrješenja kalendara.
     */
    private function calendarResolutionPath(string $uuid): string
    {
        if ($this->urls->namedRouteExists('simbioza-confluence-import.report.calendar')) {
            return $this->urls->getPathFor('simbioza-confluence-import.report.calendar', ['uuid' => $uuid]);
        }

        return $this->reportPath($uuid) . '/calendar';
    }

    /**
     * EN: Builds the manual link-correction confirmation path.
     *
     * HR: Gradi putanju potvrde ručno korigirane poveznice.
     */
    private function linkCorrectionPath(string $uuid): string
    {
        if ($this->urls->namedRouteExists('simbioza-confluence-import.report.link-corrected')) {
            return $this->urls->getPathFor('simbioza-confluence-import.report.link-corrected', ['uuid' => $uuid]);
        }

        return $this->reportPath($uuid) . '/link-corrected';
    }

    /**
     * EN: Builds the manual macro-correction confirmation path.
     *
     * HR: Gradi putanju potvrde ručno korigiranog makroa.
     */
    private function reviewCorrectionPath(string $uuid): string
    {
        if ($this->urls->namedRouteExists('simbioza-confluence-import.report.review-corrected')) {
            return $this->urls->getPathFor('simbioza-confluence-import.report.review-corrected', ['uuid' => $uuid]);
        }

        return $this->reportPath($uuid) . '/review-corrected';
    }

    /**
     * EN: Returns to the report with a manual-correction outcome.
     *
     * HR: Vraća na izvještaj s ishodom ručne korekcije.
     */
    private function manualCorrectionRedirect(string $uuid, string $status, string $message): string
    {
        return $this->reportPath($uuid) . '?' . http_build_query([
            'manual_correction' => $status,
            'manual_message' => $message,
        ]);
    }

    /**
     * EN: Returns to the report with a concise outcome message.
     *
     * HR: Vraća na izvještaj s kratkom porukom ishoda.
     */
    private function calendarResolutionRedirect(string $uuid, string $status, string $message): string
    {
        return $this->reportPath($uuid) . '?' . http_build_query([
            'calendar_resolution' => $status,
            'calendar_message' => $message,
        ]);
    }

    /**
     * EN: Reads the explicitly selected ICS file.
     *
     * HR: Čita izričito odabranu ICS datoteku.
     */
    private function uploadedIcsContent(ServerRequestInterface $request): string
    {
        $file = $request->getUploadedFiles()['ics_file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            throw new ConfluenceImportException(__('Odaberite ispravnu iCalendar datoteku.'));
        }

        $content = (string)$file->getStream();
        if (trim($content) === '') {
            throw new ConfluenceImportException(__('iCalendar datoteka je prazna.'));
        }

        return $content;
    }

    /**
     * EN: Resolves a named route with a local fallback.
     *
     * HR: Razrješava imenovanu rutu uz lokalni fallback.
     */
    private function path(string $route, string $fallback): string
    {
        try {
            return $this->urls->getPathFor($route);
        } catch (Throwable) {
            return rtrim($this->urls->getBasePath(), '/') . $fallback;
        }
    }

    /**
     * EN: Accepts only a local absolute HTTP path.
     *
     * HR: Prihvaća samo lokalnu apsolutnu HTTP putanju.
     */
    private function safeLocalPath(string $path): bool
    {
        return $path !== '' && str_starts_with($path, '/') && !str_starts_with($path, '//');
    }

    /**
     * EN: Localizes an import business status.
     *
     * HR: Lokalizira poslovni status importa.
     */
    private function statusLabel(string $status): string
    {
        return match ($status) {
            'uploading' => __('Prijenos u tijeku'),
            'scanning' => __('Provjera arhive'),
            'ready' => __('Spremno za mapiranje'),
            'running' => __('Import u tijeku'),
            'completed' => __('Dovršeno'),
            'failed' => __('Neuspjelo'),
            default => $status,
        };
    }

    /**
     * EN: Localizes the current import stage.
     *
     * HR: Lokalizira trenutačnu fazu importa.
     */
    private function stageLabel(string $stage): string
    {
        return match ($stage) {
            'upload' => __('Prijenos arhive'),
            'preflight' => __('Provjera arhive'),
            'inventory' => __('Pregled sitea i odabir područja'),
            'site_index' => __('Priprema zajedničkog indeksa sadržaja sitea'),
            'site_spaces' => __('Uvoz odabranih područja sitea'),
            'mapping', 'identity_mapping' => __('Mapiranje identiteta'),
            'workspace' => __('Izrada područja'),
            'attachments' => __('Uvoz privitaka'),
            'pages' => __('Uvoz stranica'),
            'acl' => __('Primjena ovlasti'),
            'comments' => __('Uvoz komentara'),
            'links_and_search' => __('Poveznice i indeks pretrage'),
            'preparing_content' => __('Priprema sadržaja'),
            'finalizing' => __('Završna obrada'),
            'completed' => __('Dovršeno'),
            'failed' => __('Neuspjelo'),
            default => $stage,
        };
    }

    /**
     * EN: Converts a stored UTC date into the locale-specific format.
     *
     * HR: Pretvara spremljeni UTC datum u lokalni format jezika.
     */
    private function formatUtcDateTime(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            return '';
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', trim($value), new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable) {
            return trim($value);
        }

        return LocaleDateFormatter::dateTimeSeconds(
            $date,
            $this->translator->getLocale(),
            $this->timezone()->getName(),
        );
    }

    /**
     * EN: Returns the application's safe time zone.
     *
     * HR: Vraća sigurnu vremensku zonu aplikacije.
     */
    private function timezone(): DateTimeZone
    {
        $configured = $this->applicationConfig->get('app.timezone');
        $name = is_string($configured) && trim($configured) !== '' ? trim($configured) : 'UTC';
        try {
            return new DateTimeZone($name);
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    /**
     * EN: Returns active application locales for target content.
     *
     * HR: Vraća aktivne jezike aplikacije za ciljni sadržaj.
     *
     * @return list<string>
     */
    private function supportedLanguages(): array
    {
        $configured = $this->applicationConfig->getAsArrayWithValuesAsNonEmptyStrings(
            'app.localization.supported_locales',
        ) ?? [];
        $languages = [];
        foreach ($configured as $language) {
            $language = strtolower(trim($language));
            if (preg_match('/^[a-z]{2}(?:-[a-z0-9]{2,8})?$/', $language) === 1) {
                $languages[$language] = $language;
            }
        }

        if ($languages === []) {
            $languages[$this->config->defaultLanguage()] = $this->config->defaultLanguage();
        }

        return array_values($languages);
    }

    /**
     * EN: Normalizes a scalar text value.
     *
     * HR: Normalizira skalarnu tekstualnu vrijednost.
     */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * EN: Normalizes a numeric value.
     *
     * HR: Normalizira brojčanu vrijednost.
     */
    private function integer(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * EN: Returns a forbidden HTML response.
     *
     * HR: Vraća HTML odgovor zabranjenog pristupa.
     */
    private function denied(): ResponseInterface
    {
        return $this->responses->text(__('Pristup nije dozvoljen.'), 403);
    }

    /**
     * EN: Returns a forbidden JSON response.
     *
     * HR: Vraća JSON odgovor zabranjenog pristupa.
     */
    private function deniedJson(): ResponseInterface
    {
        return $this->responses->json(['error' => __('Pristup nije dozvoljen.')], 403);
    }

    /**
     * EN: Converts a business or technical failure into a JSON response.
     *
     * HR: Pretvara poslovnu ili tehničku pogrešku u JSON odgovor.
     */
    private function errorJson(Throwable $throwable): ResponseInterface
    {
        $status = $throwable instanceof ConfluenceImportException ? 422 : 500;

        return $this->responses->json(['error' => $throwable->getMessage()], $status);
    }
}
