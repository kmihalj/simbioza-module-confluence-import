<?php

declare(strict_types=1);

// EN: C1 shows a read-only site catalog and persists only source-space choices.
// Source names are untrusted text; escape all output and never expose identity secrets.
//
// HR: C1 prikazuje pregled sitea i sprema samo odabir izvornih područja.
// Izvorni nazivi nepouzdan su tekst; sav izlaz kodirati i ne otkrivati tajne identiteta.

$catalog = is_array($scan['spaces'] ?? null) ? $scan['spaces'] : [];
$selectedIds = is_array($preparation['inventory_selection'] ?? null) ? $preparation['inventory_selection'] : [];
?>
<section class="confluence-import-panel mt-3" aria-labelledby="confluence-site-inventory-title">
    <div class="confluence-import-body">
        <h2 class="h4" id="confluence-site-inventory-title"><?= $this->escape(($scan['archive_type'] ?? '') === 'site' ? __('Izvoz cijelog Confluence sitea') : __('Izvoz više Confluence područja')) ?></h2>
        <p><?= $this->escape(__('Odabir područja samo je postavka. Sve odabrane dijelove uvoza potvrđujete zajedno na kraju.')) ?></p>
        <?php foreach ($warnings as $warning) :
            if (str_starts_with((string)$warning, 'Pregled sitea ne mijenja korisnike')) {
                continue;
            }
            ?>
            <div class="alert alert-warning"><?= $this->escape(__((string)$warning)) ?></div>
        <?php endforeach; ?>
        <dl class="row">
            <dt class="col-sm-6"><?= $this->escape(__('Područja')) ?></dt><dd class="col-sm-6"><?= count($catalog) ?></dd>
            <dt class="col-sm-6"><?= $this->escape(__('Aktualne stranice')) ?></dt><dd class="col-sm-6"><?= (int)($statuses['current'] ?? 0) ?></dd>
            <dt class="col-sm-6"><?= $this->escape(__('Računi korisničkih imenika')) ?></dt><dd class="col-sm-6"><?= (int)($counts['InternalUser'] ?? 0) ?></dd>
            <dt class="col-sm-6"><?= $this->escape(__('Reference identiteta autora')) ?></dt><dd class="col-sm-6"><?= (int)($counts['ConfluenceUserImpl'] ?? 0) ?></dd>
            <dt class="col-sm-6"><?= $this->escape(__('Zapisi grupa i članstava')) ?></dt><dd class="col-sm-6"><?= (int)($counts['InternalGroup'] ?? 0) ?> / <?= (int)($counts['HibernateMembership'] ?? 0) ?></dd>
            <dt class="col-sm-6"><?= $this->escape(__('Datoteke privitaka u ZIP-u')) ?></dt><dd class="col-sm-6"><?= (int)($scan['source']['attachment_files'] ?? 0) ?></dd>
            <dt class="col-sm-6"><?= $this->escape(__('Podaci dodataka')) ?></dt><dd class="col-sm-6"><?= $this->escape(($scan['source']['plugin_data'] ?? false) ? __('Pronađeni; kalendare odaberite ispod.') : __('Nisu pronađeni.')) ?></dd>
        </dl>
        <form id="confluence-site-selection" method="post" action="<?= $this->escape($inventorySelectionPath) ?>">
            <input type="hidden" name="<?= $this->escape($csrfName) ?>" value="<?= $this->escape($csrfToken) ?>">
            <input type="hidden" name="uuid" value="<?= $this->escape((string)$job['uuid']) ?>">
            <input type="hidden" name="source_sha256" value="<?= $this->escape((string)$scan['source_sha256']) ?>">
            <label class="form-label" for="confluence-site-filter"><?= $this->escape(__('Pretraži područja')) ?></label>
            <input type="search" class="form-control mb-2" id="confluence-site-filter">
            <div class="d-flex flex-wrap gap-2 mb-3">
                <button class="btn btn-outline-secondary" id="confluence-site-select-visible" type="button"><?= $this->escape(__('Odaberi prikazana područja')) ?></button>
                <button class="btn btn-outline-secondary" id="confluence-site-select-filtered" type="button"><?= $this->escape(__('Odaberi sva filtrirana područja')) ?></button>
                <button class="btn btn-outline-secondary" id="confluence-site-select-none" type="button"><?= $this->escape(__('Poništi odabir')) ?></button>
            </div>
            <?php $sitePagerLabel = __('Stranice područja');
            require __DIR__ . '/site-table-pagination.php'; ?>
            <p data-pager-empty hidden><?= $this->escape(__('Nema rezultata za odabrani filtar.')) ?></p>
            <div class="table-responsive" tabindex="0" aria-label="<?= $this->escape(__('Područja')) ?>">
                <table class="table align-middle" id="confluence-site-spaces">
                    <caption><?= $this->escape(__('Odaberite područja za uvoz. Spremanje odabira ne pokreće import.')) ?></caption>
                    <thead><tr><th scope="col"><?= $this->escape(__('Odabir')) ?></th><th scope="col"><?= $this->escape(__('Područje')) ?></th><th scope="col"><?= $this->escape(__('Vrsta područja')) ?></th><th scope="col"><?= $this->escape(__('Aktualne stranice')) ?></th><th scope="col"><?= $this->escape(__('XML zapisi privitaka')) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($catalog as $entry) : ?>
                        <?php $sourceId = (string)$entry['source_id']; ?>
                        <tr data-filter-row>
                            <td><input class="form-check-input" type="checkbox" name="space_ids[]" value="<?= $this->escape($sourceId) ?>" aria-label="<?= $this->escape(__('Odaberi područje') . ': ' . (string)$entry['name']) ?>"<?= in_array($sourceId, $selectedIds, true) ? ' checked' : '' ?>></td>
                            <td><strong><?= $this->escape((string)$entry['name']) ?></strong><div><code><?= $this->escape((string)$entry['source_key']) ?></code> · <?= $this->escape($sourceId) ?></div></td>
                            <td><?= $this->escape(($entry['type'] ?? '') === 'personal' ? __('Osobno područje') : __('Opće područje')) ?></td>
                            <td><?= (int)($entry['content_counts']['Page']['current'] ?? 0) ?></td>
                            <td><?= (int)($entry['content_counts']['Attachment']['current'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php require __DIR__ . '/site-table-pagination.php'; ?>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <button class="btn btn-primary" type="submit"><?= $this->escape(__('Spremi odabir područja')) ?></button>
                <button class="btn btn-danger" type="button" data-cancel-job="<?= $this->escape((string)$job['uuid']) ?>"><?= $this->escape(__('Odustani od importa')) ?></button>
                <span id="confluence-site-selection-status" role="status" aria-live="polite"></span>
            </div>
        </form>
    </div>
</section>
