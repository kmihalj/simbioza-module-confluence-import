<?php

declare(strict_types=1);

/**
 * @var string $title
 * @var array<string,mixed> $job
 * @var array<string,mixed> $summary
 * @var list<array<string,mixed>> $reviewPages
 * @var bool $calendarAvailable
 * @var list<array<string,mixed>> $calendarOptions
 * @var string $calendarResolvePath
 * @var string|null $calendarAdminPath
 * @var string $calendarResolutionStatus
 * @var string $calendarResolutionMessage
 * @var string $settingsPath
 * @var string|null $workspacePath
 * @var string $stylesPath
 * @var string $settingsMenuActiveSection
 * @var object|null $menuRenderer
 */

$settingsMenuHtml = null;
if (isset($menuRenderer) && is_object($menuRenderer)) {
    $settingsMenuCallback = [$menuRenderer, 'renderSettingsMenu'];
    if (is_callable($settingsMenuCallback)) {
        $renderedSettingsMenu = $settingsMenuCallback($settingsMenuActiveSection);
        $settingsMenuHtml = is_string($renderedSettingsMenu) ? $renderedSettingsMenu : null;
    }
}
$number = static fn(mixed $value): int => is_numeric($value) ? (int)$value : 0;
$unresolvedReviewPages = array_filter(
    $reviewPages,
    static function (array $page): bool {
        foreach (is_array($page['issues'] ?? null) ? $page['issues'] : [] as $issue) {
            if (!is_array($issue) || !($issue['resolved'] ?? false)) {
                return true;
            }
        }

        return false;
    },
);
$calendarTypeLabel = static fn(string $type): string => match ($type) {
    'personal' => __('Osobni kalendar'),
    'resource' => __('Resursni kalendar'),
    default => __('Timski kalendar'),
};
$calendarVisibilityLabel = static function (array $calendar): string {
    if ((bool)($calendar['is_public_read'] ?? false)) {
        return __('javno čitanje');
    }
    if ((bool)($calendar['is_authenticated_read'] ?? false)) {
        return __('čitanje za prijavljene');
    }

    return __('čitanje prema ACL-u kalendara');
};
$normalizeCalendarName = static function (string $name): string {
    $normalized = preg_replace('/\s+/u', ' ', trim($name));

    return mb_strtolower(is_string($normalized) ? $normalized : trim($name), 'UTF-8');
};
?>
<link rel="stylesheet" href="<?= $this->escape($stylesPath) ?>">

<div class="row g-4">
    <aside class="col-lg-3">
        <?php if (is_string($settingsMenuHtml) && $settingsMenuHtml !== '') : ?>
            <?= $settingsMenuHtml ?>
        <?php endif; ?>
    </aside>

    <main class="col-lg-9 confluence-import-shell">
        <section class="card">
            <div class="card-body">
                <header class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
                    <div>
                        <h1 class="h3 mb-1"><?= $this->escape($title) ?></h1>
                        <p class="text-body-secondary mb-0">
                            <?= $this->escape((string)($job['source_space_name'] ?? $job['original_name'] ?? '')) ?>
                            <?php if (($job['source_space_key'] ?? '') !== '') : ?>
                                · <?= $this->escape((string)$job['source_space_key']) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-secondary" href="<?= $this->escape($settingsPath) ?>"><?= $this->escape(__('Natrag na importe')) ?></a>
                        <?php if (is_string($workspacePath) && $workspacePath !== '') : ?>
                            <a class="btn btn-primary" href="<?= $this->escape($workspacePath) ?>"><?= $this->escape(__('Otvori područje')) ?></a>
                        <?php endif; ?>
                    </div>
                </header>

                <?php if ($calendarResolutionStatus === 'success' && $calendarResolutionMessage !== '') : ?>
                    <div class="alert alert-success" role="status"><?= $this->escape($calendarResolutionMessage) ?></div>
                <?php elseif ($calendarResolutionStatus === 'error' && $calendarResolutionMessage !== '') : ?>
                    <div class="alert alert-danger" role="alert"><?= $this->escape($calendarResolutionMessage) ?></div>
                <?php endif; ?>

                <div class="row g-3 mb-4">
                    <div class="col-sm-6 col-xl-3"><div class="confluence-import-report-stat"><span><?= $this->escape(__('Stranice')) ?></span><strong><?= $number($summary['pages_imported'] ?? 0) ?></strong></div></div>
                    <div class="col-sm-6 col-xl-3"><div class="confluence-import-report-stat"><span><?= $this->escape(__('Privitci')) ?></span><strong><?= $number($summary['attachments_imported'] ?? 0) ?></strong></div></div>
                    <div class="col-sm-6 col-xl-3"><div class="confluence-import-report-stat"><span><?= $this->escape(__('Komentari')) ?></span><strong><?= $number($summary['comments_imported'] ?? 0) ?></strong></div></div>
                    <div class="col-sm-6 col-xl-3"><div class="confluence-import-report-stat"><span><?= $this->escape(__('Stranice za provjeru')) ?></span><strong><?= count($unresolvedReviewPages) ?></strong></div></div>
                </div>

                <h2 class="h4 mb-2"><?= $this->escape(__('Sadržaj koji zahtijeva provjeru')) ?></h2>
                <p class="text-body-secondary"><?= $this->escape(__('Ovdje su stranice na kojima dio Confluence sadržaja nije moguće prenijeti kao izvornu funkcionalnost. Statički prikaz ostao je sačuvan gdje god je to bilo moguće.')) ?></p>

                <?php if ($reviewPages === []) : ?>
                    <div class="alert alert-success mb-0" role="status"><?= $this->escape(__('Import nije pronašao sadržaj koji zahtijeva ručnu provjeru.')) ?></div>
                <?php else : ?>
                    <div class="list-group confluence-import-report-list">
                        <?php foreach ($reviewPages as $page) :
                            $issues = is_array($page['issues'] ?? null) ? $page['issues'] : [];
                            $pageUrl = is_string($page['url'] ?? null) ? (string)$page['url'] : '';
                            ?>
                            <article class="list-group-item">
                                <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
                                    <div>
                                        <h3 class="h5 mb-1"><?= $this->escape((string)($page['title'] ?? __('Stranica bez naslova'))) ?></h3>
                                        <?php if (($page['source_page_id'] ?? '') !== '') : ?>
                                            <div class="small text-body-secondary">Confluence ID: <?= $this->escape((string)$page['source_page_id']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($pageUrl !== '') : ?>
                                        <a class="btn btn-sm btn-primary" href="<?= $this->escape($pageUrl) ?>"><?= $this->escape(__('Provjeri stranicu')) ?></a>
                                    <?php endif; ?>
                                </div>
                                <div class="d-grid gap-3 mt-3">
                                    <?php foreach ($issues as $issue) : ?>
                                        <?php if (is_array($issue) && ($issue['type'] ?? '') === 'calendar') :
                                            $sourceCalendarName = trim((string)($issue['source_calendar_name'] ?? ''));
                                            $calendarName = $sourceCalendarName !== ''
                                                ? $sourceCalendarName
                                                : (string)($issue['source_calendar_id'] ?? __('Nepoznati kalendar'));
                                            $matchingCalendarUuids = [];
                                            if ($sourceCalendarName !== '') {
                                                $normalizedSourceCalendarName = $normalizeCalendarName($sourceCalendarName);
                                                foreach ($calendarOptions as $calendarOption) {
                                                    if (
                                                        $normalizeCalendarName((string)($calendarOption['name'] ?? ''))
                                                        === $normalizedSourceCalendarName
                                                    ) {
                                                        $matchingCalendarUuids[] = (string)($calendarOption['uuid'] ?? '');
                                                    }
                                                }
                                            }
                                            $matchedCalendarUuid = count($matchingCalendarUuids) === 1
                                                ? $matchingCalendarUuids[0]
                                                : '';
                                            ?>
                                            <section class="confluence-import-calendar-resolution" data-confluence-calendar-issue>
                                                <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
                                                    <div>
                                                        <h4 class="h6 mb-1"><?= $this->escape(sprintf(__('Confluence kalendar: %s'), $calendarName)) ?></h4>
                                                        <p class="small text-body-secondary mb-0">
                                                            <?= $this->escape(__('XML sadrži identitet kalendara, ali ne i njegove događaje. Kalendar se zato ne povezuje automatski.')) ?>
                                                        </p>
                                                    </div>
                                                    <?php if ((bool)($issue['resolved'] ?? false)) : ?>
                                                        <span class="badge text-bg-success"><?= $this->escape(__('Razriješeno')) ?></span>
                                                    <?php endif; ?>
                                                </div>

                                                <?php if ((bool)($issue['resolved'] ?? false)) : ?>
                                                    <div class="alert alert-success mt-3 mb-0" role="status">
                                                        <?= $this->escape(sprintf(
                                                            __('Stranica prikazuje kalendar „%s”.'),
                                                            (string)($issue['target_calendar_name'] ?? __('Calendar')),
                                                        )) ?>
                                                        <?php if (($issue['resolution_mode'] ?? '') === 'import') : ?>
                                                            <?= $this->escape(sprintf(
                                                                __(' Događaji: %d dodano, %d ažurirano, %d preskočeno.'),
                                                                $number($issue['events_created'] ?? 0),
                                                                $number($issue['events_updated'] ?? 0),
                                                                $number($issue['events_skipped'] ?? 0),
                                                            )) ?>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php elseif (!$calendarAvailable) : ?>
                                                    <div class="alert alert-warning mt-3 mb-0" role="alert">
                                                        <?= $this->escape(__('Calendar modul nije dostupan. Instalirajte ga prije povezivanja ovog makroa.')) ?>
                                                    </div>
                                                <?php else : ?>
                                                    <div class="row g-3 mt-1">
                                                        <div class="col-xl-6">
                                                            <form class="confluence-import-resolution-option h-100" method="post" action="<?= $this->escape($calendarResolvePath) ?>">
                                                                <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
                                                                <input type="hidden" name="resolution_mode" value="existing">
                                                                <input type="hidden" name="source_page_id" value="<?= $this->escape((string)($page['source_page_id'] ?? '')) ?>">
                                                                <input type="hidden" name="source_calendar_id" value="<?= $this->escape((string)($issue['source_calendar_id'] ?? '')) ?>">
                                                                <input type="hidden" name="marker" value="<?= $this->escape((string)($issue['marker'] ?? '')) ?>">
                                                                <h5 class="h6"><?= $this->escape(__('Poveži postojeći kalendar')) ?></h5>
                                                                <p class="small text-body-secondary">
                                                                    <?= $this->escape(__('Odaberite kalendar koji već postoji u Simbiozi. Naziv ne mora odgovarati nazivu iz Confluencea.')) ?>
                                                                </p>
                                                                <?php if ($matchedCalendarUuid !== '') : ?>
                                                                    <div class="alert alert-info py-2 small" role="status">
                                                                        <?= $this->escape(__('Pronađen je postojeći kalendar istog naziva i unaprijed je odabran. Provjerite ga prije povezivanja.')) ?>
                                                                    </div>
                                                                <?php elseif (count($matchingCalendarUuids) > 1) : ?>
                                                                    <div class="alert alert-warning py-2 small" role="status">
                                                                        <?= $this->escape(__('Pronađeno je više dostupnih kalendara istog naziva. Odaberite odgovarajući kalendar.')) ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                                <label class="form-label" for="calendar-existing-<?= $this->escape((string)($issue['marker'] ?? '')) ?>"><?= $this->escape(__('Kalendar')) ?></label>
                                                                <select
                                                                    class="form-select"
                                                                    id="calendar-existing-<?= $this->escape((string)($issue['marker'] ?? '')) ?>"
                                                                    name="calendar_uuid"
                                                                    required
                                                                    <?= $calendarOptions === [] ? 'disabled' : '' ?>
                                                                >
                                                                    <option value=""><?= $this->escape(__('Odaberite kalendar')) ?></option>
                                                                    <?php foreach ($calendarOptions as $calendar) : ?>
                                                                        <option
                                                                            value="<?= $this->escape((string)($calendar['uuid'] ?? '')) ?>"
                                                                            <?= (string)($calendar['uuid'] ?? '') === $matchedCalendarUuid ? 'selected' : '' ?>
                                                                        >
                                                                            <?= $this->escape(sprintf(
                                                                                '%s · %s · %s',
                                                                                (string)($calendar['name'] ?? __('Calendar')),
                                                                                $calendarTypeLabel((string)($calendar['calendar_type'] ?? 'team')),
                                                                                $calendarVisibilityLabel($calendar),
                                                                            )) ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                                <?php if ($calendarOptions === []) : ?>
                                                                    <div class="form-text"><?= $this->escape(__('Nema kalendara koje smijete čitati.')) ?></div>
                                                                <?php endif; ?>
                                                                <button class="btn btn-primary mt-3" type="submit" <?= $calendarOptions === [] ? 'disabled' : '' ?>>
                                                                    <?= $this->escape(__('Poveži odabrani kalendar')) ?>
                                                                </button>
                                                            </form>
                                                        </div>
                                                        <div class="col-xl-6">
                                                            <form
                                                                class="confluence-import-resolution-option h-100"
                                                                method="post"
                                                                action="<?= $this->escape($calendarResolvePath) ?>"
                                                                enctype="multipart/form-data"
                                                            >
                                                                <?= $this->csrfHandler->generateCsrfTokenInputField() ?>
                                                                <input type="hidden" name="resolution_mode" value="import">
                                                                <input type="hidden" name="source_page_id" value="<?= $this->escape((string)($page['source_page_id'] ?? '')) ?>">
                                                                <input type="hidden" name="source_calendar_id" value="<?= $this->escape((string)($issue['source_calendar_id'] ?? '')) ?>">
                                                                <input type="hidden" name="marker" value="<?= $this->escape((string)($issue['marker'] ?? '')) ?>">
                                                                <h5 class="h6"><?= $this->escape(__('Uvezi ICS kao novi kalendar')) ?></h5>
                                                                <p class="small text-body-secondary">
                                                                    <?= $this->escape(__('Uvoz koristi postojeće vrste i ovlasti Calendar modula. Izvorni Confluence ACL ne nagađa se iz XML-a.')) ?>
                                                                </p>
                                                                <div class="row g-3">
                                                                    <div class="col-12">
                                                                        <label class="form-label"><?= $this->escape(__('iCalendar datoteka')) ?></label>
                                                                        <input class="form-control" type="file" name="ics_file" accept=".ics,text/calendar" required>
                                                                        <div class="form-text">
                                                                            <?= $this->escape(__('Naziv kalendara preuzima se iz ICS datoteke; ako u njoj nije naveden, koristi se naziv iz Confluencea.')) ?>
                                                                        </div>
                                                                    </div>
                                                                    <div class="col-md-5">
                                                                        <label class="form-label"><?= $this->escape(__('Vrsta')) ?></label>
                                                                        <select class="form-select" name="calendar_type">
                                                                            <option value="team"><?= $this->escape(__('Timski kalendar')) ?></option>
                                                                            <option value="resource"><?= $this->escape(__('Resursni kalendar')) ?></option>
                                                                        </select>
                                                                    </div>
                                                                </div>
                                                                <div class="mt-3 d-grid gap-2">
                                                                    <label class="form-check">
                                                                        <input type="hidden" name="is_authenticated_read" value="0">
                                                                        <input class="form-check-input" type="checkbox" name="is_authenticated_read" value="1">
                                                                        <span class="form-check-label"><?= $this->escape(__('Svi prijavljeni korisnici mogu čitati')) ?></span>
                                                                    </label>
                                                                    <label class="form-check">
                                                                        <input type="hidden" name="is_public_read" value="0">
                                                                        <input class="form-check-input" type="checkbox" name="is_public_read" value="1">
                                                                        <span class="form-check-label"><?= $this->escape(__('Javno čitanje bez prijave')) ?></span>
                                                                    </label>
                                                                </div>
                                                                <button class="btn btn-primary mt-3" type="submit"><?= $this->escape(__('Uvezi i prikaži na stranici')) ?></button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                    <p class="small text-body-secondary mt-3 mb-0">
                                                        <?= $this->escape(__('Ovlasti kalendara vrijede i nakon ugradnje u stranicu; ovlasti stranice ne daju dodatni pristup kalendaru.')) ?>
                                                        <?php if (is_string($calendarAdminPath) && $calendarAdminPath !== '') : ?>
                                                            <a href="<?= $this->escape($calendarAdminPath) ?>"><?= $this->escape(__('Detaljne korisničke i grupne ovlasti uredite u postavkama kalendara.')) ?></a>
                                                        <?php endif; ?>
                                                    </p>
                                                <?php endif; ?>
                                            </section>
                                        <?php elseif (is_array($issue) && ($issue['type'] ?? '') === 'unsupported_macro') : ?>
                                            <div class="alert alert-warning mb-0">
                                                <?= $this->escape(sprintf(
                                                    __('Confluence makro „%s” nema odgovarajuću Simbioza funkcionalnost.'),
                                                    (string)($issue['macro'] ?? ''),
                                                )) ?>
                                            </div>
                                        <?php else : ?>
                                            <div class="alert alert-warning mb-0">
                                                <?= $this->escape(is_scalar($issue) ? (string)$issue : __('Nepoznato upozorenje konverzije.')) ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>
