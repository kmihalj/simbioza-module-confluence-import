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
    }
}
