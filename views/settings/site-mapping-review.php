<?php

declare(strict_types=1);

// EN: This owner-only review has no mutation controls, credentials or automatic approval.
// Escape source values; edit links return to the ordinary reviewed draft workflow.
//
// HR: Ovaj pregled za vlasnika nema kontrole upisa, vjerodajnice ni automatsko odobrenje.
// Kodiraj izvorne vrijednosti; uređivanje vraća u uobičajeni pregledani nacrt.
$back = $settingsPath . '?' . http_build_query(['job' => $jobUuid]);
$types = ['personal' => __('Osobni kalendar'), 'team' => __('Timski kalendar'), '' => __('Potrebna odluka')];
$confidence = ['name_identifier' => __('Podudaraju se puno ime i identifikator; potvrditi istu osobu.'),
    'name_only' => __('Podudara se samo ime; potvrditi radi li se o istoj osobi.'),
    'different_names' => __('Zajednički identifikator uz različita imena; ne spajati bez pojedinačne provjere.')];
$reasons = ['shared_resource' => __('Naziv upućuje na zajednički resurs, tim ili projekt.'),
    'person_name' => __('Naziv sadrži ime ili korisničku oznaku osobe; vlasnika treba potvrditi.'),
    'personal_name' => __('Naziv označava osobni službeni ili privatni kalendar; vlasnik nije potvrđen.'),
    'personal_context' => __('Veza samo s osobnim područjima slab je trag, ne dokaz vlasništva.'),
    'general_context' => __('Veza s općim područjem predlaže timski kalendar, ali naziv nije dovoljan dokaz.'),
    'unknown' => __('Nema dovoljno podataka za prijedlog vrste; potreban je ručni odabir.')];
$ownerReasons = ['name' => __('Puno ime u nazivu kalendara'), 'login' => __('Korisnička oznaka ili email u nazivu'),
    'personal_space' => __('Samo trag osobnog područja'),
    'name_space' => __('Podudarno ime i povezano osobno područje; moguć drukčiji zapis prezimena, obavezno potvrditi.')];
?>
<link rel="stylesheet" href="<?= $this->escape($stylesPath) ?>">
<div class="confluence-import-shell" data-site-mapping-review>
 <section class="card"><div class="card-body">
  <h1 class="h3"><?= $this->escape($title) ?></h1>
  <p><?= $this->escape(__('Ovo su prijedlozi za potvrdu, ne spremljena mapiranja. Pregled ne mijenja račune, odabire, vlasništvo ni pretplate i ne pokreće uvoz.')) ?></p>
  <a class="btn btn-outline-secondary" href="<?= $this->escape($back) ?>"><?= $this->escape(__('Povratak na postavke uvoza')) ?></a>
 </div></section>
 <section class="card mt-3" id="review-duplicates"><div class="card-body" data-review-list>
  <h2 class="h4"><?= $this->escape(__('Mogući dupli računi')) ?> (<?= count($review['duplicates']) ?>)</h2>
  <p><?= $this->escape(__('Računi za ručnu provjeru')) ?>: <?= (int)$review['conflicts'] ?></p>
  <p><?= $this->escape(__('Glavni račun predlaže se prema postojećem cilju, aktivnom izvornom računu i korisničkoj oznaci. To ne uključuje prijavu ni administratorske ovlasti. Različita imena i servisne račune provjerite zasebno.')) ?></p>
  <label class="form-label"><?= $this->escape(__('Pretraži račune')) ?><input class="form-control" type="search" data-review-search></label>
  <?php $sitePagerLabel = __('Stranice prijedloga računa');
    require __DIR__ . '/site-table-pagination.php'; ?>
  <p data-pager-empty hidden><?= $this->escape(__('Nema rezultata za odabrani filtar.')) ?></p>
  <div class="table-responsive"><table class="table align-middle"><thead><tr><th><?= $this->escape(__('Izvorni računi')) ?></th><th><?= $this->escape(__('Dokazi i upozorenja')) ?></th><th><?= $this->escape(__('Prijedlog za potvrdu')) ?></th></tr></thead><tbody>
    <?php foreach ($review['duplicates'] as $group) : ?>
   <tr data-review-row><td>
        <?php foreach ($group['members'] as $member) :
            $source = $member['source']; ?>
    <p><strong><?= $this->escape((string)$source['display_name']) ?></strong><br><code><?= $this->escape((string)$source['username']) ?></code> · <?= $this->escape((string)$source['email']) ?><br>
     <small><?= $this->escape((string)($source['directory_name'] ?? '')) ?></small><?php require __DIR__ . '/site-source-activity.php'; ?><br>
            <?php if (is_numeric($member['choice'])) : ?>
     <small><?= $this->escape(__('Ciljni korisnik')) ?>: <?= $this->escape($targetLabels[(int)$member['choice']] ?? $member['choice']) ?></small><br>
            <?php endif; ?>
     <a href="<?= $this->escape($back . '&' . http_build_query(['identity_q' => $source['username'], 'identity_status' => 'all']) . '#site-identities') ?>"><?= $this->escape(__('Uredi odabir')) ?></a>
    </p>
        <?php endforeach; ?>
   </td><td><p><?= $this->escape($confidence[$group['confidence']]) ?></p>
        <?php foreach ($group['evidence'] as $evidence) : ?>
    <div><small><?= $this->escape(substr($evidence, strpos($evidence, ':') + 1)) ?></small></div>
        <?php endforeach; ?>
   </td><td>
        <?php if ($group['confidence'] === 'name_identifier') :
            $primary = array_column(array_column($group['members'], 'source'), null, 'source_id')[$group['primary']]; ?>
    <p><?= $this->escape(__('Predloženi glavni izvorni račun')) ?>: <strong><?= $this->escape((string)$primary['username']) ?></strong></p>
    <p><?= $this->escape(__('Ako potvrdite istu osobu, ostale izvorne račune povežite s glavnim računom. Osobna područja mogu dodavati sadržaj u jedno područje vlasnika.')) ?></p>
        <?php else : ?>
    <p><?= $this->escape(__('Potvrdite identitet pojedinačno. Zajednički email ili ime nisu dovoljni za automatsko spajanje.')) ?></p>
        <?php endif; ?>
   </td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
    <?php require __DIR__ . '/site-table-pagination.php'; ?>
 </div></section>
 <section class="card mt-3" id="review-calendars"><div class="card-body" data-review-list>
  <h2 class="h4"><?= $this->escape(__('Kalendari za potvrdu vrste ili vlasnika')) ?> (<?= count($review['calendars']) ?>)</h2>
    <?php if (!$calendarReady) : ?>
  <p class="alert alert-warning"><?= $this->escape(__('Najprije pripremite pregled kalendara.')) ?></p>
    <?php endif; ?>
  <p><?= $this->escape(__('Prijedlog timskog kalendara ne traži osobnog vlasnika. Prijedlog osobnog kalendara zahtijeva potvrđenog vlasnika; povezanost sa stranicom nije dokaz.')) ?></p>
  <p>
    <?php foreach ($review['types'] as $type => $count) : ?>
   <span class="me-3"><?= $this->escape($types[$type]) ?>: <?= (int)$count ?></span>
    <?php endforeach; ?>
  </p>
  <label class="form-label"><?= $this->escape(__('Pretraži prijedloge kalendara')) ?><input class="form-control" type="search" data-review-search></label>
    <?php $sitePagerLabel = __('Stranice prijedloga kalendara');
    require __DIR__ . '/site-table-pagination.php'; ?>
  <p data-pager-empty hidden><?= $this->escape(__('Nema rezultata za odabrani filtar.')) ?></p>
  <div class="table-responsive"><table class="table align-middle"><thead><tr><th><?= $this->escape(__('Kalendar')) ?></th><th><?= $this->escape(__('Spremljena vrsta')) ?></th><th><?= $this->escape(__('Predložena vrsta i razlog')) ?></th><th><?= $this->escape(__('Mogući vlasnici za potvrdu')) ?></th></tr></thead><tbody>
    <?php foreach ($review['calendars'] as $calendar) : ?>
   <tr data-review-row><td><strong><?= $this->escape($calendar['name']) ?></strong><br><small><?= $this->escape($calendar['id']) ?></small><br>
    <a href="<?= $this->escape($back . '&' . http_build_query(['calendar_q' => $calendar['id'], 'calendar_owner_status' => 'all']) . '#site-calendars') ?>"><?= $this->escape(__('Uredi odabir')) ?></a>
    <details><summary><?= $this->escape(__('Područja')) ?> (<?= count($calendar['space_keys']) ?>)</summary><?= $this->escape(implode(', ', $calendar['space_keys'])) ?></details>
   </td><td><?= $this->escape($types[$calendar['current_type']]) ?></td><td><strong><?= $this->escape($types[$calendar['proposed_type']]) ?></strong><p><?= $this->escape($reasons[$calendar['type_reason']]) ?></p></td><td>
        <?php if ($calendar['proposed_type'] === 'team') : ?>
            <?= $this->escape(__('Osobni vlasnik nije potreban; provjerite timske ovlasti.')) ?>
        <?php elseif ($calendar['candidates'] === []) : ?>
            <?= $this->escape(__('Nema pouzdanog prijedloga vlasnika. Odaberite ga ručno ili zasad preskočite kalendar.')) ?>
        <?php else : ?>
            <?php foreach ($calendar['candidates'] as $candidate) : ?>
    <p><strong><?= $this->escape($candidate['label']) ?></strong><br><code><?= $this->escape($candidate['username']) ?></code><br>
     <small><?= $this->escape($ownerReasons[$candidate['reason']]) ?></small><br>
                <?php if ($candidate['target_label'] !== '') : ?>
     <small><?= $this->escape(__('Ciljni korisnik')) ?>: <?= $this->escape($candidate['target_label']) ?></small>
                <?php else : ?>
     <small><?= $this->escape(__('Najprije potvrdite povezivanje ili izradu izvornog računa.')) ?></small>
                <?php endif; ?>
    </p>
            <?php endforeach; ?>
        <?php endif; ?>
   </td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
    <?php require __DIR__ . '/site-table-pagination.php'; ?>
 </div></section>
</div>
<script>
(() => {
    'use strict';
    <?php require __DIR__ . '/site-table-pagination-script.php'; ?>
    document.querySelectorAll('[data-review-list]').forEach((list) => {
        const search = list.querySelector('[data-review-search]');
        const pager = createSiteTablePager(list, '[data-review-row]', (row) => row.textContent.toLocaleLowerCase().includes(search.value.trim().toLocaleLowerCase()));
        search.addEventListener('input', () => pager.refresh(true));
    });
})();
</script>
