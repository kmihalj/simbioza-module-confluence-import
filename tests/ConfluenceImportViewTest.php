<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use PHPUnit\Framework\TestCase;

final class ConfluenceImportViewTest extends TestCase
{
    /** HR: File picker mora koristiti prevedive oznake umjesto tekstova preglednika. EN: The file picker must use translatable labels instead of browser-owned text. */
    public function testFilePickerUsesLocalizedCustomControls(): void
    {
        $view = file_get_contents(dirname(__DIR__) . '/views/settings/index.php');

        self::assertIsString($view);
        self::assertStringContainsString('id="confluence-import-file"', $view);
        self::assertStringContainsString('class="visually-hidden"', $view);
        self::assertStringContainsString("__('Odaberi datoteku')", $view);
        self::assertStringContainsString("__('Nije odabrana nijedna datoteka.')", $view);
        self::assertStringContainsString("fileInput?.addEventListener('change'", $view);
        self::assertStringContainsString('$this->escape(__((string)$warning))', $view);

        $english = require dirname(__DIR__) . '/lang/en.php';
        self::assertSame('Choose file', $english['Odaberi datoteku']);
        self::assertSame('No file selected.', $english['Nije odabrana nijedna datoteka.']);
    }

    /** HR: Import kartice moraju koristiti stvarne površine aktivne teme. EN: Import cards must use the active theme's real surfaces. */
    public function testImportCardsUseDefinedThemeSurfaceVariables(): void
    {
        $styles = file_get_contents(dirname(__DIR__) . '/resources/assets/confluence-import.css');

        self::assertIsString($styles);
        self::assertStringContainsString(
            '--confluence-import-surface-bg: var(--hph-surface-bg, var(--bs-body-bg, #fff));',
            $styles,
        );
        self::assertStringContainsString(
            'background: var(--confluence-import-subtle-bg);',
            $styles,
        );
        self::assertStringContainsString(
            'color: var(--confluence-import-surface-text);',
            $styles,
        );
        self::assertStringNotContainsString('--hph-muted-bg', $styles);
    }

    /** HR: Razrješenje kalendara ne traži ručni naziv koji ICS već sadrži. EN: Calendar resolution does not request a manual name already carried by the ICS file. */
    public function testCalendarImportUsesTheIcsCalendarName(): void
    {
        $view = file_get_contents(dirname(__DIR__) . '/views/settings/report.php');
        $service = file_get_contents(dirname(__DIR__) . '/src/Service/ConfluenceCalendarResolutionService.php');

        self::assertIsString($view);
        self::assertIsString($service);
        self::assertStringNotContainsString('name="calendar_name"', $view);
        self::assertStringContainsString(
            "__('Naziv kalendara preuzima se iz ICS datoteke; ako u njoj nije naveden, koristi se naziv iz Confluencea.')",
            $view,
        );
        self::assertStringContainsString("'prefer_ics_name' => true", $service);
        self::assertStringContainsString("\$issue['source_calendar_name']", $service);
        self::assertStringContainsString('$matchedCalendarUuid', $view);
        self::assertStringContainsString(
            "__('Pronađen je postojeći kalendar istog naziva i unaprijed je odabran. Provjerite ga prije povezivanja.')",
            $view,
        );

        $controller = file_get_contents(dirname(__DIR__) . '/src/Controller/ConfluenceImportController.php');
        self::assertIsString($controller);
        self::assertStringContainsString('$this->session->close();', $controller);
        self::assertLessThan(
            strpos($controller, '$this->calendarResolution->resolve('),
            strpos($controller, '$this->session->close();'),
        );
    }

    /** HR: Trajni izvještaj prikazuje živu listu samo nerazriješenih Confluence veza. EN: The durable report shows a live list of unresolved Confluence links only. */
    public function testReportShowsDynamicUnresolvedLinks(): void
    {
        $view = file_get_contents(dirname(__DIR__) . '/views/settings/report.php');
        $controller = file_get_contents(dirname(__DIR__) . '/src/Controller/ConfluenceImportController.php');

        self::assertIsString($view);
        self::assertIsString($controller);
        self::assertStringContainsString("__('Nerazriješene Confluence poveznice')", $view);
        self::assertStringContainsString('$unresolvedLinkPages', $view);
        self::assertStringContainsString('unresolvedLinksForJob', $controller);
        self::assertStringContainsString('Uspješno lokalno razriješene poveznice više se ne prikazuju.', $view);
    }

    /** HR: Administrator može potvrditi ručnu korekciju veze ili makroa, a riješena upozorenja nestaju iz aktivnog izvještaja. EN: An administrator can confirm a manual link or macro correction and resolved warnings leave the active report. */
    public function testReportSupportsDurableManualCorrections(): void
    {
        $view = file_get_contents(dirname(__DIR__) . '/views/settings/report.php');
        $controller = file_get_contents(dirname(__DIR__) . '/src/Controller/ConfluenceImportController.php');
        $repository = file_get_contents(dirname(__DIR__) . '/src/Service/ConfluenceImportRepository.php');

        self::assertIsString($view);
        self::assertIsString($controller);
        self::assertIsString($repository);
        self::assertStringContainsString("__('Označi kao korigirano')", $view);
        self::assertStringContainsString('name="link_uuids[]"', $view);
        self::assertStringContainsString('name="issue_type" value="unsupported_macro"', $view);
        self::assertStringContainsString('$unresolvedReviewPages as $page', $view);
        self::assertStringContainsString('markLinkCorrected', $controller);
        self::assertStringContainsString('markReviewCorrected', $controller);
        self::assertStringContainsString("'status' => 'manually_resolved'", $repository);
        self::assertStringContainsString("->whereRaw('status <> ?', ['manually_resolved'])", $repository);
        self::assertStringContainsString(
            "(\$issue['resolution_mode'] ?? '') !== 'manual_content_correction'",
            $view,
        );
    }

    /** HR: Batch nudi pravilo za nemapirane korisnike bez umjetnog produljivanja sesije. EN: Batch exposes the unmapped-user policy without artificially extending the session. */
    public function testBatchImportHasUserPolicyAndRemoteUserPickerWithoutHeartbeat(): void
    {
        $view = file_get_contents(dirname(__DIR__) . '/views/settings/index.php');
        $controller = file_get_contents(dirname(__DIR__) . '/src/Controller/ConfluenceImportController.php');
        $service = file_get_contents(dirname(__DIR__) . '/src/Service/ConfluenceImportService.php');

        self::assertIsString($view);
        self::assertIsString($controller);
        self::assertIsString($service);
        self::assertStringContainsString('id="confluence-import-batch-create-unmapped-users"', $view);
        self::assertStringContainsString('create_inactive_users: batchPolicy', $view);
        self::assertStringContainsString('id="confluence-import-batch-source-base-url"', $view);
        self::assertStringContainsString('source_base_url: sourceBaseUrl', $view);
        self::assertStringContainsString('name="source_base_url"', $view);
        self::assertStringContainsString('source_base_url: form.elements.source_base_url.value', $view);
        self::assertStringNotContainsString('const heartbeat', $view);
        self::assertStringNotContainsString('window.clearInterval(heartbeat)', $view);
        self::assertStringNotContainsString('240000', $view);
        self::assertStringContainsString('simbioza_confluence_import_activity', $controller);
        self::assertStringContainsString('$this->session->close();', $controller);
        self::assertStringContainsString('data-identity-picker-search', $view);
        self::assertStringContainsString("url.searchParams.set('q', term)", $view);
        self::assertStringContainsString('const positionPanel = () =>', $view);
        self::assertStringContainsString('$batchArchiveCount', $view);
        self::assertStringContainsString('stoppedName = item.name', $view);
        self::assertStringNotContainsString('listUsersForSetup()', substr($service, 0, (int)strpos($service, 'public function queue(')));
    }

    /** HR: Veliki import ne ponavlja globalnu pripremu ni optimizaciju svih slika u svakom koraku. EN: A large import does not repeat global preparation or eager image optimization in every step. */
    public function testLargeImportDefersRepeatedGlobalWork(): void
    {
        $service = file_get_contents(dirname(__DIR__) . '/src/Service/ConfluenceImportService.php');
        $services = file_get_contents(dirname(__DIR__) . '/config/services.php');

        self::assertIsString($service);
        self::assertIsString($services);
        self::assertStringContainsString('preparedAttachmentDataset', $service);
        self::assertStringContainsString("'render_context' => \$renderContext", $service);
        self::assertStringContainsString('importedAttachmentsForPages', $service);
        self::assertStringContainsString('markAttachmentsRegistered', $service);
        self::assertStringContainsString('ConfluenceImportStateStore::class', $services);
        self::assertStringNotContainsString('->prewarmDocument(', $service);
        self::assertStringNotContainsString('EditorImageVariantService::class', $services);
    }
}
