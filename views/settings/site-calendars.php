<?php

declare(strict_types=1);

// EN: Calendar selection is independent of space selection; labels are always escaped.
//
// HR: Odabir kalendara neovisan je o odabiru područja; oznake su uvijek kodirane.
$calendarCatalog = $siteCalendarState['catalog'];
$calendarPlan = $siteCalendarState['plan'];
$calendarPhase = $calendarPlan['phase'] ?? '';
$calendarFrozen = in_array($job['options']['site_workflow']['phase'] ?? '', ['running', 'completed', 'failed'], true);
$ownerStatusLabels = ['mapped' => __('Jednoznačan mapirani prijedlog'), 'planned' => __('Prijedlog za planirani novi račun'),
    'unmapped' => __('Predloženi korisnik još nije mapiran'), 'ambiguous' => __('Više mogućih vlasnika'),
    'hint' => __('Samo trag osobnog područja'), 'none' => __('Bez pouzdanog prijedloga')];
$ownerReasonLabels = ['name' => __('Puno ime u nazivu kalendara'), 'login' => __('Korisnička oznaka ili email u nazivu'),
    'personal_space' => __('Povezano osobno područje; nije dokaz vlasništva')];
?>
<section class="confluence-import-panel mt-3" id="site-calendars" aria-labelledby="site-calendars-title">
 <div class="confluence-import-body">
  <h2 class="h4" id="site-calendars-title"><?= $this->escape(__('Uvoz kalendara sitea')) ?></h2>
  <p><?= $this->escape(__('Odaberite kalendare za zajednički uvoz. Pretplate se ne dohvaćaju i povijesne obavijesti se ne šalju.')) ?></p>
  <?php if (($job['summary']['source_released'] ?? false) === true || ($job['archive_path'] ?? '') === '') : ?>
   <p><?= $this->escape(__('Radna kopija arhive nije dostupna. Izvještaji ostaju dostupni u zajedničkom pregledu.')) ?></p>
  <?php elseif ($siteCalendarState['busy'] ?? false) : ?>
   <p><?= $this->escape(__('Najprije dovršite ili ponovite prekinuti uvoz područja.')) ?></p>
  <?php elseif (!$siteCalendarState['available']) : ?>
   <p class="alert alert-warning"><?= $this->escape(__('Calendar modul nije dostupan.')) ?></p>
  <?php elseif ($calendarCatalog === null) : ?>
   <div id="site-calendar-preparation" class="border rounded p-3 mb-3 d-none" data-uuid="<?= $this->escape((string)$job['uuid']) ?>" data-state="<?= $this->escape((string)($siteCalendarState['preparation']['status'] ?? 'idle')) ?>" aria-labelledby="site-calendar-preparation-label">
    <h3 class="h6" id="site-calendar-preparation-label"><?= $this->escape(__('Priprema pregleda kalendara')) ?></h3>
    <p id="site-calendar-preparation-phase" class="mb-2" role="status" aria-live="polite"></p>
    <div class="progress" style="height:1.25rem"><div id="site-calendar-preparation-bar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" aria-label="<?= $this->escape(__('Priprema pregleda kalendara')) ?>" style="width:100%"></div></div>
    <p id="site-calendar-preparation-detail" class="small mt-2 mb-0"></p>
    <p class="small mt-2 mb-0"><?= $this->escape(__('Pregled će se prikazati automatski kada priprema završi. Uvoz još nije pokrenut.')) ?></p>
   </div>
   <button type="button" class="btn btn-primary" data-site-calendar-action="prepare" data-uuid="<?= $this->escape((string)$job['uuid']) ?>"><?= $this->escape(__('Pripremi pregled kalendara')) ?></button>
  <?php elseif ($calendarPhase === 'running') : ?>
   <p><?= $this->escape(__('Uvoz je prekinut ili se izvodi. Nastavak ne duplicira već potvrđene kalendare i događaje.')) ?></p>
  <?php else : ?>
   <p><?= $this->escape(__('Izvorni kalendari')) ?>: <?= count($calendarCatalog['calendars']) ?> · <?= $this->escape(__('Događaji')) ?>: <?= (int)($calendarCatalog['counts']['EVENTS'] ?? 0) ?></p>
   <p class="alert alert-warning"><?= $this->escape(__('Nepodržane vanjske pretplate, podkalendari ili događaji ostaju u izvještaju; ne uvoze se automatski. Izostale ili nemapirane ovlasti ne otvaraju javni pristup.')) ?>
      <?= (int)$calendarCatalog['unresolved_subcalendars'] ?> / <?= (int)$calendarCatalog['unresolved_events'] ?>
   </p>
   <p><?= $this->escape(__('Vlasnika osobnog kalendara odaberite među postojećim korisnicima ili korisnicima odabranim za izradu.')) ?></p>
   <p><?= $this->escape(__('Prijedlozi vlasnika služe za pregled. Kreator kalendara nije automatski vlasnik. Prihvaćanje prijedloga samo popunjava postavke; ne pokreće uvoz.')) ?></p>
   <p><a href="<?= $this->escape($settingsPath . '?' . http_build_query(['job' => $job['uuid'], 'mapping_review' => '1']) . '#review-calendars') ?>"><?= $this->escape(__('Pregledaj prijedloge duplih računa i kalendara')) ?></a></p>
   <p class="small"><?= $this->escape(__('Pregled koristi spremljene postavke; prije otvaranja spremite eventualne promjene.')) ?></p>
      <?php if ($calendarFrozen) : ?>
    <p class="alert alert-info"><?= $this->escape(__('Odabiri ovog uvoza su zaključani. Prijedloge možete pregledati; za nove odabire pripremite novi uvoz.')) ?></p>
      <?php endif; ?>
   <form id="confluence-site-calendars" data-uuid="<?= $this->escape((string)$job['uuid']) ?>">
    <label class="form-label" for="site-calendar-filter"><?= $this->escape(__('Filtriraj kalendare')) ?></label>
    <input class="form-control mb-2" type="search" id="site-calendar-filter">
    <label class="form-label" for="site-calendar-owner-filter"><?= $this->escape(__('Pregled vlasništva kalendara')) ?></label>
    <select class="form-select mb-2" id="site-calendar-owner-filter">
     <option value="all"><?= $this->escape(__('Svi kalendari')) ?></option>
     <option value="needs-review"><?= $this->escape(__('Potrebna potvrda vrste ili vlasnika')) ?></option>
     <option value="personal"><?= $this->escape(__('Osobni kalendari i prijedlozi osobnih')) ?></option>
     <option value="personal-mapped"><?= $this->escape(__('Osobni s jednoznačno mapiranim prijedlogom')) ?></option>
     <option value="mapped"><?= $this->escape(__('Jednoznačan mapirani prijedlog')) ?></option>
     <option value="planned"><?= $this->escape(__('Prijedlog za planirani novi račun')) ?></option>
     <option value="unmapped"><?= $this->escape(__('Predloženi korisnik još nije mapiran')) ?></option>
     <option value="ambiguous"><?= $this->escape(__('Više mogućih vlasnika')) ?></option>
     <option value="hint"><?= $this->escape(__('Samo trag osobnog područja')) ?></option>
     <option value="none"><?= $this->escape(__('Bez pouzdanog prijedloga')) ?></option>
    </select>
    <p id="site-calendar-filter-count" data-label="<?= $this->escape(__('Prikazani kalendari')) ?>" role="status" aria-live="polite"></p>
        <?php $sitePagerLabel = __('Stranice kalendara');
        require __DIR__ . '/site-table-pagination.php'; ?>
    <p data-pager-empty hidden><?= $this->escape(__('Nema rezultata za odabrani filtar.')) ?></p>
    <fieldset<?= $calendarFrozen ? ' disabled' : '' ?>>
    <div class="d-flex flex-wrap gap-2 mb-2">
     <button class="btn btn-sm btn-outline-secondary" type="button" id="site-calendar-select-visible"><?= $this->escape(__('Odaberi vidljive kalendare')) ?></button>
     <button class="btn btn-sm btn-outline-secondary" type="button" id="site-calendar-select-filtered"><?= $this->escape(__('Odaberi sve filtrirane kalendare')) ?></button>
     <button class="btn btn-sm btn-outline-secondary" type="button" id="site-calendar-select-none"><?= $this->escape(__('Poništi odabir kalendara')) ?></button>
     <button class="btn btn-sm btn-outline-secondary" type="button" id="site-calendar-accept-suggestions"><?= $this->escape(__('Prihvati jednoznačne prijedloge za vidljive retke')) ?></button>
    </div>
    <div class="table-responsive">
     <table class="table"><thead><tr><th><?= $this->escape(__('Odabir')) ?></th><th><?= $this->escape(__('Kalendar')) ?></th><th><?= $this->escape(__('Vrsta kalendara')) ?></th><th><?= $this->escape($calendarFrozen ? __('Raniji odabir vlasnika') : __('Vlasnik osobnog kalendara')) ?></th><th><?= $this->escape(__('Predloženi vlasnik i razlog')) ?></th><th><?= $this->escape(__('Područja')) ?></th><th><?= $this->escape(__('Događaji')) ?></th></tr></thead><tbody>
        <?php foreach ($calendarCatalog['calendars'] as $calendarId => $entry) : ?>
            <?php $chosenType = $calendarPlan['decisions'][$calendarId]['calendar_type']
                ?? \AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceCalendarPresentation::suggestedType($entry); ?>
            <?php $suggestion = $siteCalendarState['owner_suggestions'][$calendarId] ?? ['status' => 'none', 'choice' => '', 'candidates' => [], 'candidate_count' => 0];
            $isPersonal = $chosenType === 'personal' || ($suggestion['personal_candidate'] ?? false);
            $ownerSearch = implode(' ', array_map(static fn(array $candidate): string => $candidate['label'] . ' ' . $candidate['username'] . ' ' . $candidate['target_label'], $suggestion['candidates'])); ?>
      <tr data-calendar-row data-calendar-personal="<?= $isPersonal ? '1' : '0' ?>" data-calendar-search="<?= $this->escape($entry['name'] . ' ' . $ownerSearch) ?>" data-calendar-owner-status="<?= $this->escape($suggestion['status']) ?>"><td><input class="form-check-input" type="checkbox" name="calendar_ids[]" value="<?= $this->escape((string)$calendarId) ?>" aria-label="<?= $this->escape((string)$entry['name']) ?>"<?= isset($calendarPlan['decisions'][$calendarId]) ? ' checked' : '' ?>></td>
       <td><?= $this->escape((string)$entry['name']) ?><br><small><code><?= $this->escape((string)$calendarId) ?></code></small></td>
       <td><select class="form-select" style="min-width:11rem" data-calendar-type="<?= $this->escape((string)$calendarId) ?>" aria-label="<?= $this->escape(__('Vrsta kalendara') . ': ' . $entry['name']) ?>">
        <option value=""<?= $chosenType === '' ? ' selected' : '' ?>><?= $this->escape(__('Odaberite vrstu kalendara')) ?></option>
        <option value="team"<?= $chosenType === 'team' ? ' selected' : '' ?>><?= $this->escape(__('Timski kalendar')) ?></option>
        <option value="personal"<?= $chosenType === 'personal' ? ' selected' : '' ?>><?= $this->escape(__('Osobni kalendar')) ?></option>
       </select></td>
            <?php $chosenOwner = $calendarPlan['decisions'][$calendarId]['owner_ref'] ?? '';
            $ownerLabel = $siteCalendarState['owner_choices'][$chosenOwner]['label'] ?? $calendarPlan['decisions'][$calendarId]['owner_label'] ?? '';
            $ownerPickerId = 'calendar-owner-' . hash('sha256', (string)$calendarId); ?>
       <td><div class="confluence-import-user-picker" data-identity-picker data-search-url="<?= $this->escape($siteUsersPath . '?' . http_build_query(['uuid' => $job['uuid'], 'scope' => 'calendar-owners'])) ?>">
        <input type="hidden" data-identity-map data-calendar-owner data-picker-label="<?= $this->escape($ownerLabel) ?>" value="<?= $this->escape($chosenOwner) ?>">
        <button id="<?= $ownerPickerId ?>-toggle" class="form-select text-start" type="button" data-identity-picker-toggle aria-label="<?= $this->escape(__('Vlasnik osobnog kalendara') . ': ' . $entry['name']) ?>" aria-controls="<?= $ownerPickerId ?>-panel" aria-expanded="false"><?= $this->escape($ownerLabel ?: __('Odaberite vlasnika')) ?></button>
        <div id="<?= $ownerPickerId ?>-panel" class="confluence-import-user-picker-panel shadow" data-identity-picker-panel popover="manual" hidden>
         <button type="button" class="list-group-item list-group-item-action" data-identity-picker-choice="" data-identity-picker-label="<?= $this->escape(__('Odaberite vlasnika')) ?>"><?= $this->escape(__('Poništi odabir vlasnika')) ?></button>
         <label class="form-label" for="<?= $ownerPickerId ?>-search"><?= $this->escape(__('Pretraži korisnike')) ?></label>
         <input id="<?= $ownerPickerId ?>-search" class="form-control mb-2" type="search" data-identity-picker-search autocomplete="off">
         <div data-identity-picker-status role="status" aria-live="polite"></div>
         <div class="list-group" data-identity-picker-results></div>
         <button type="button" class="btn btn-sm btn-outline-secondary mt-2" data-identity-picker-more hidden><?= $this->escape(__('Učitaj još')) ?></button>
        </div>
       </div></td>
       <td>
        <strong><?= $this->escape($ownerStatusLabels[$suggestion['status']] ?? $ownerStatusLabels['none']) ?></strong>
            <?php foreach ($suggestion['candidates'] as $candidate) : ?>
        <p class="mb-1"><?= $this->escape($candidate['label']) ?> <small>(<?= $this->escape($candidate['username']) ?>)</small><br>
         <small><?= $this->escape($ownerReasonLabels[$candidate['reason']]) ?></small>
                <?php if ($candidate['target_label'] !== '') : ?>
         <br><small><?= $this->escape(__('Ciljni korisnik')) ?>: <?= $this->escape($candidate['target_label']) ?></small>
                <?php endif; ?>
        </p>
            <?php endforeach; ?>
            <?php if ($suggestion['candidate_count'] > count($suggestion['candidates'])) : ?>
        <small><?= $this->escape(__('Ukupno kandidata')) ?>: <?= (int)$suggestion['candidate_count'] ?></small>
            <?php endif; ?>
            <?php if ($suggestion['choice'] !== '') : ?>
        <button type="button" class="btn btn-sm btn-outline-primary" data-calendar-accept-owner="<?= $this->escape($suggestion['choice']) ?>" data-calendar-owner-label="<?= $this->escape($siteCalendarState['owner_choices'][$suggestion['choice']]['label']) ?>"><?= $this->escape(__('Prihvati prijedlog vlasnika')) ?></button>
            <?php endif; ?>
       </td>
       <td><details><summary><?= $this->escape(__('Područja')) ?> (<?= count($entry['space_keys']) ?>)</summary><small><?= $this->escape(implode(', ', $entry['space_keys'])) ?></small></details></td><td><?= (int)$entry['events'] ?></td></tr>
        <?php endforeach; ?>
     </tbody></table>
    </div>
    </fieldset>
        <?php require __DIR__ . '/site-table-pagination.php'; ?>
    <button class="btn btn-primary mt-2" type="submit"<?= $calendarFrozen ? ' disabled' : '' ?>><?= $this->escape(__('Spremi postavke kalendara')) ?></button>
   </form>
        <?php if ($calendarPhase === 'review') : ?>
    <div class="alert alert-info mt-3"><?= $this->escape(__('Pregled ne mijenja kalendare. Provjerite upozorenja prije potvrde; već postojeći događaji neće se prepisivati.')) ?></div>
            <?php foreach ($calendarPlan['decisions'] as $decision) : ?>
     <details class="mb-2"><summary><?= $this->escape((string)$decision['name']) ?> · <?= $this->escape(__('Podržani / preskočeni događaji')) ?>: <?= (int)$decision['events'] ?> / <?= (int)$decision['skipped'] ?></summary>
      <p><?= $this->escape(__('Vrsta kalendara')) ?>: <?= $this->escape(($decision['calendar_type'] ?? '') === 'personal' ? __('Osobni kalendar') : __('Timski kalendar')) ?></p>
                <?php if (($decision['calendar_type'] ?? '') === 'personal') : ?>
      <p><?= $this->escape(__('Vlasnik osobnog kalendara')) ?>: <?= $this->escape(($decision['owner_label'] ?? '') ?: ($decision['owner_id'] !== null ? (string)$decision['owner_id'] : __('Nemapirano'))) ?></p>
                <?php endif; ?>
      <p><?= $this->escape(__('Ovlasti')) ?>: <?= count($decision['rules']) ?></p>
                <?php foreach ($decision['access_warnings'] as $warning) :
                    ?><p class="text-warning-emphasis"><?= $this->escape($warning) ?></p><?php
                endforeach; ?>
                <?php foreach ($decision['warnings'] as $warning) :
                    ?><p><code><?= $this->escape($warning['id']) ?></code> <?= $this->escape($warning['reason']) ?></p><?php
                endforeach; ?>
     </details>
            <?php endforeach; ?>
        <?php elseif ($calendarPhase === 'completed') : ?>
    <p class="alert alert-success mt-3"><?= $this->escape(__('Uvoz kalendara je dovršen.')) ?></p>
    <a href="<?= $this->escape($settingsPath . '/report/' . rawurlencode($calendarPlan['report_uuid'])) ?>"><?= $this->escape(__('Otvori izvještaj importa')) ?></a>
            <?php foreach ($calendarPlan['results'] as $result) : ?>
     <p><?= $this->escape($result['name']) ?>: <?= $this->escape(__('Novi / postojeći / preskočeni događaji')) ?> <?= (int)$result['created'] ?> / <?= (int)$result['existing'] ?> / <?= (int)$result['skipped'] ?></p>
            <?php endforeach; ?>
        <?php endif; ?>
  <?php endif; ?>
  <div id="site-calendar-status" class="mt-2" role="status" aria-live="polite"></div>
 </div>
</section>
