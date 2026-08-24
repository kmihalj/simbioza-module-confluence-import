<?php

declare(strict_types=1);

/**
 * @var string $title
 * @var array<string,mixed> $job
 * @var array<string,mixed> $summary
 * @var list<array<string,mixed>> $reviewPages
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

                <div class="row g-3 mb-4">
                    <div class="col-sm-6 col-xl-3"><div class="confluence-import-report-stat"><span><?= $this->escape(__('Stranice')) ?></span><strong><?= $number($summary['pages_imported'] ?? 0) ?></strong></div></div>
                    <div class="col-sm-6 col-xl-3"><div class="confluence-import-report-stat"><span><?= $this->escape(__('Privitci')) ?></span><strong><?= $number($summary['attachments_imported'] ?? 0) ?></strong></div></div>
                    <div class="col-sm-6 col-xl-3"><div class="confluence-import-report-stat"><span><?= $this->escape(__('Komentari')) ?></span><strong><?= $number($summary['comments_imported'] ?? 0) ?></strong></div></div>
                    <div class="col-sm-6 col-xl-3"><div class="confluence-import-report-stat"><span><?= $this->escape(__('Stranice za provjeru')) ?></span><strong><?= count($reviewPages) ?></strong></div></div>
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
                                <ul class="mb-0 mt-2">
                                    <?php foreach ($issues as $issue) : ?>
                                        <li><?php
                                        if (is_array($issue) && ($issue['type'] ?? '') === 'unsupported_macro') {
                                            echo $this->escape(sprintf(
                                                __('Confluence makro „%s” nema odgovarajuću Simbioza funkcionalnost.'),
                                                (string)($issue['macro'] ?? ''),
                                            ));
                                        } else {
                                            echo $this->escape(is_scalar($issue) ? (string)$issue : __('Nepoznato upozorenje konverzije.'));
                                        }
                                        ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>
