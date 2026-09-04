<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Controller;

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

use const UPLOAD_ERR_OK;

/**
 * HR: Administratorsko sučelje za nastavivi upload, pregled mapiranja i kontrolirani Confluence import.
 * EN: Administration UI for resumable upload, mapping review, and controlled Confluence import.
 */
final readonly class ConfluenceImportController
{
    /** HR: Prima javne servise vlasničkih modula i zajedničke HTTP servise. EN: Receives public owner-module services and shared HTTP services. */
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
    ) {
    }

    /** HR: Prikazuje upload, preflight, mapiranja i zadnje poslove. EN: Shows upload, preflight, mappings, and recent jobs. */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->denied();
        }

        $actorUserId = $this->actorUserId();
        $jobUuid = $this->text($request->getQueryParams()['job'] ?? '');
        $preparation = null;
        $error = '';
        if ($jobUuid !== '') {
            try {
                $preparation = $this->imports->preparation($jobUuid, $actorUserId);
            } catch (Throwable $throwable) {
                $error = $throwable->getMessage();
            }
        }

        return $this->views->render('settings/index', [
            'title' => __('Confluence import'),
            'preparation' => $preparation,
            'initialError' => $error,
            'jobs' => $this->jobPayloads(),
            'settingsPath' => $this->path('simbioza-confluence-import.settings', '/settings/confluence-import'),
            'jobsPath' => $this->path('simbioza-confluence-import.jobs', '/settings/confluence-import/jobs'),
            'csrfPath' => $this->path('simbioza-confluence-import.csrf', '/settings/confluence-import/csrf'),
            'uploadStartPath' => $this->path('simbioza-confluence-import.upload.start', '/settings/confluence-import/upload/start'),
            'uploadChunkPath' => $this->path('simbioza-confluence-import.upload.chunk', '/settings/confluence-import/upload/chunk'),
            'uploadFinishPath' => $this->path('simbioza-confluence-import.upload.finish', '/settings/confluence-import/upload/finish'),
            'cancelPath' => $this->path('simbioza-confluence-import.cancel', '/settings/confluence-import/cancel'),
            'importPath' => $this->path('simbioza-confluence-import.run', '/settings/confluence-import/run'),
            'processPath' => $this->path('simbioza-confluence-import.process', '/settings/confluence-import/process'),
            'stylesPath' => $this->path('simbioza-confluence-import.assets.css', '/confluence-import/assets.css'),
            'csrfName' => $this->session->getCsrfTokenName(),
            'csrfToken' => $this->session->getOrGenerateCsrfToken(),
            'chunkSize' => $this->config->chunkSize(),
            'maxArchiveSize' => $this->config->maxArchiveSize(),
            'defaultLanguage' => $this->config->defaultLanguage(),
            'supportedLanguages' => $this->supportedLanguages(),
            'settingsMenuActiveSection' => 'simbioza-confluence-import.settings',
            'menuRenderer' => $this->menuRenderer,
        ]);
    }

    /** HR: Vraća kratki lokalizirani status poslova za pozadinsko osvježavanje. EN: Returns concise localized job status for background refreshes. */
    public function jobs(): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->deniedJson();
        }

        return $this->responses->json(['jobs' => $this->jobPayloads()]);
    }

    /** HR: Prikazuje trajni izvještaj jednog dovršenog importa. EN: Shows the durable report for one completed import. */
    public function report(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->denied();
        }

        try {
            $job = $this->repository->jobByUuid($this->text($request->getAttribute('uuid')));
            if (($job['status'] ?? '') !== 'completed') {
                return $this->responses->text(__('Izvještaj je dostupan tek nakon dovršenog importa.'), 404);
            }

            $summary = is_array($job['summary'] ?? null) ? $job['summary'] : [];
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

            return $this->views->render('settings/report', [
                'title' => __('Izvještaj Confluence importa'),
                'job' => $job,
                'summary' => $summary,
                'reviewPages' => $reviewPages,
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

    /** HR: Ručno uvozi ili povezuje kalendar iz trajnog izvještaja. EN: Manually imports or links a calendar from the durable report. */
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
            $result = $this->calendarResolution->resolve($uuid, $body, $ics, $actor);
            $message = sprintf(
                __('Stranica sada prikazuje kalendar „%s”.'),
                $this->text($result['target_calendar_name'] ?? __('Calendar')),
            );

            return $this->responses->redirect($this->calendarResolutionRedirect($uuid, 'success', $message));
        } catch (Throwable $throwable) {
            return $this->responses->redirect(
                $this->calendarResolutionRedirect($uuid, 'error', $throwable->getMessage()),
            );
        }
    }

    /** HR: Vraća svježi CSRF token za svaki korak uploada. EN: Returns a fresh CSRF token for every upload step. */
    public function csrf(): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->deniedJson();
        }

        return $this->responses->json(['csrf_token' => $this->session->getOrGenerateCsrfToken()]);
    }

    /** HR: Otvara nastavivi upload nakon provjere naziva i veličine. EN: Starts resumable upload after validating name and size. */
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

    /** HR: Sprema sljedeći binarni dio samo na dogovoreni offset. EN: Stores the next binary chunk only at the negotiated offset. */
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

    /** HR: Provjerava dovršeni ZIP i radi preflight bez promjene aplikacijskih podataka. EN: Validates the completed ZIP and performs preflight without changing application data. */
    public function uploadFinish(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->access->isAdministrator()) {
            return $this->deniedJson();
        }

        try {
            $job = $this->uploads->finish($this->text($this->body($request)['uuid'] ?? ''), $this->actorUserId());

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
     * HR: Otkazuje vlastiti nedovršeni import i odmah briše prenesenu arhivu.
     * EN: Cancels the actor's unfinished import and immediately deletes its uploaded archive.
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

    /** HR: Priprema potvrđeni nastavivi import. EN: Prepares a confirmed resumable import. */
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

            $progress = $this->imports->queue($uuid, $body, $actor);

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
     * HR: Izvodi sljedeći ograničeni korak importa i vraća stvarni napredak.
     * EN: Runs the next bounded import step and returns actual progress.
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

            $progress = $this->imports->process($this->text($body['uuid'] ?? ''), $actor);
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

    /** HR: Preuzima uvezeni privitak tek nakon ponovne provjere aktualnog Workspace/page ACL-a. EN: Downloads an imported attachment only after rechecking current Workspace/page ACL. */
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

    /** HR: Razrješava međupodručnu poveznicu ili daje jasnu neriješenu poruku. EN: Resolves a cross-space link or returns a clear unresolved message. */
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

    /** HR: Poslužuje mali CSS koji koristi Bootstrap i varijable aktivne teme. EN: Serves small CSS that uses Bootstrap and active-theme variables. */
    public function styles(): ResponseInterface
    {
        $path = dirname(__DIR__, 2) . '/resources/assets/confluence-import.css';
        $css = is_file($path) ? file_get_contents($path) : '';

        return $this->responses->text(is_string($css) ? $css : '', contentType: 'text/css; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    /**
     * HR: Ponovno provjerava aktualni ACL ciljne stranice.
     * EN: Rechecks the target page's current ACL.
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
     * HR: Priprema lokalizirane podatke nedavnih poslova.
     * EN: Prepares localized recent-job payloads.
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
                'mapping_url' => in_array(($job['status'] ?? ''), ['ready', 'running'], true)
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
     * HR: Pretvara posao u odgovor nastavivog uploada.
     * EN: Converts a job into a resumable-upload response.
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
     * HR: Čita podatke forme ili JSON tijelo zahtjeva.
     * EN: Reads form data or a JSON request body.
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

    /** HR: Vraća ID prijavljenog administratora. EN: Returns the authenticated administrator ID. */
    private function actorUserId(): int
    {
        $user = $this->access->currentUser();
        $id = is_array($user) ? $this->integer($user['id'] ?? 0) : 0;
        if ($id <= 0) {
            throw new ConfluenceImportException(__('Prijavljeni administrator nije pronađen.'));
        }

        return $id;
    }

    /** HR: Gradi putanju naslovnice područja. EN: Builds a Workspace homepage path. */
    private function workspacePath(string $slug): string
    {
        if ($this->urls->namedRouteExists('workspace.show')) {
            return $this->urls->getPathFor('workspace.show', ['workspaceSlug' => $slug]);
        }

        return rtrim($this->urls->getBasePath(), '/') . '/workspace/' . rawurlencode($slug);
    }

    /** HR: Gradi putanju područja iz njegova ID-a. EN: Builds a Workspace path from its ID. */
    private function workspacePathById(int $workspaceId): ?string
    {
        $workspace = $workspaceId > 0 ? $this->workspaces->findWorkspaceById($workspaceId) : null;
        $slug = is_array($workspace) ? $this->text($workspace['slug'] ?? '') : '';

        return $slug !== '' ? $this->workspacePath($slug) : null;
    }

    /** HR: Gradi administratorsku putanju trajnog izvještaja. EN: Builds the administrator path for a durable report. */
    private function reportPath(string $uuid): string
    {
        if ($this->urls->namedRouteExists('simbioza-confluence-import.report')) {
            return $this->urls->getPathFor('simbioza-confluence-import.report', ['uuid' => $uuid]);
        }

        return rtrim($this->urls->getBasePath(), '/')
            . '/settings/confluence-import/report/' . rawurlencode($uuid);
    }

    /** HR: Gradi putanju ručnog razrješenja kalendara. EN: Builds the manual calendar-resolution path. */
    private function calendarResolutionPath(string $uuid): string
    {
        if ($this->urls->namedRouteExists('simbioza-confluence-import.report.calendar')) {
            return $this->urls->getPathFor('simbioza-confluence-import.report.calendar', ['uuid' => $uuid]);
        }

        return $this->reportPath($uuid) . '/calendar';
    }

    /** HR: Vraća na izvještaj s kratkom porukom ishoda. EN: Returns to the report with a concise outcome message. */
    private function calendarResolutionRedirect(string $uuid, string $status, string $message): string
    {
        return $this->reportPath($uuid) . '?' . http_build_query([
            'calendar_resolution' => $status,
            'calendar_message' => $message,
        ]);
    }

    /** HR: Čita izričito odabranu ICS datoteku. EN: Reads the explicitly selected ICS file. */
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

    /** HR: Razrješava imenovanu rutu uz lokalni fallback. EN: Resolves a named route with a local fallback. */
    private function path(string $route, string $fallback): string
    {
        try {
            return $this->urls->getPathFor($route);
        } catch (Throwable) {
            return rtrim($this->urls->getBasePath(), '/') . $fallback;
        }
    }

    /** HR: Prihvaća samo lokalnu apsolutnu HTTP putanju. EN: Accepts only a local absolute HTTP path. */
    private function safeLocalPath(string $path): bool
    {
        return $path !== '' && str_starts_with($path, '/') && !str_starts_with($path, '//');
    }

    /** HR: Lokalizira poslovni status importa. EN: Localizes an import business status. */
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

    /** HR: Lokalizira trenutačnu fazu importa. EN: Localizes the current import stage. */
    private function stageLabel(string $stage): string
    {
        return match ($stage) {
            'upload' => __('Prijenos arhive'),
            'preflight' => __('Provjera arhive'),
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

    /** HR: Pretvara spremljeni UTC datum u lokalni format jezika. EN: Converts a stored UTC date into the locale-specific format. */
    private function formatUtcDateTime(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            return '';
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', trim($value), new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable) {
            return trim($value);
        }

        $format = str_starts_with(strtolower($this->translator->getLocale()), 'en')
            ? 'Y-m-d H:i:s'
            : 'j. n. Y. H:i:s';

        return $date->setTimezone($this->timezone())->format($format);
    }

    /** HR: Vraća sigurnu vremensku zonu aplikacije. EN: Returns the application's safe time zone. */
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
     * HR: Vraća aktivne jezike aplikacije za ciljni sadržaj.
     * EN: Returns active application locales for target content.
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

    /** HR: Normalizira skalarnu tekstualnu vrijednost. EN: Normalizes a scalar text value. */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /** HR: Normalizira brojčanu vrijednost. EN: Normalizes a numeric value. */
    private function integer(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }

    /** HR: Vraća HTML odgovor zabranjenog pristupa. EN: Returns a forbidden HTML response. */
    private function denied(): ResponseInterface
    {
        return $this->responses->text(__('Pristup nije dozvoljen.'), 403);
    }

    /** HR: Vraća JSON odgovor zabranjenog pristupa. EN: Returns a forbidden JSON response. */
    private function deniedJson(): ResponseInterface
    {
        return $this->responses->json(['error' => __('Pristup nije dozvoljen.')], 403);
    }

    /** HR: Pretvara poslovnu ili tehničku pogrešku u JSON odgovor. EN: Converts a business or technical failure into a JSON response. */
    private function errorJson(Throwable $throwable): ResponseInterface
    {
        $status = $throwable instanceof ConfluenceImportException ? 422 : 500;

        return $this->responses->json(['error' => $throwable->getMessage()], $status);
    }
}
