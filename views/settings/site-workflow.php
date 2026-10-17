<?php

declare(strict_types=1);

// EN: One confirmation starts the frozen site workflow; component reports remain separate and linked.
//
// HR: Jedna potvrda pokreće zaključani uvoz sitea; komponentni izvještaji ostaju zasebni i povezani.
$flow = $siteWorkflowState ?? ['phase' => 'draft'];
$flowPhase = $flow['phase'] ?? 'draft';
$progressState = $flow['progress_state'] ?? [];
$progressValue = $progressState['progress'] ?? null;
$progressVisible = !in_array($flowPhase, ['draft', 'review'], true);
$phaseLabels = ['identities' => __('Korisnici, grupe i članstva sitea'),
    'calendar_prepare' => __('Priprema kalendara'), 'calendars' => __('Kalendari'),
    'content_prepare' => __('Priprema područja'), 'content' => __('Područja'),
    'links' => __('Povezivanje sadržaja'), 'completed' => __('Dovršeno'), 'failed' => __('Greška')];
?>
<section class="confluence-import-panel mt-3" id="site-workflow">
 <div class="confluence-import-body">
  <h2 class="h4"><?= $this->escape(__('Zajednički uvoz sitea')) ?></h2>
  <?php if (in_array($flowPhase, ['draft', 'review'], true)) : ?>
   <p><?= $this->escape(__('Najprije podesite korisnike, grupe, područja i kalendare. Završna potvrda pokreće sve odabrano pravilnim redoslijedom, uključujući povezivanje sadržaja.')) ?></p>
   <button type="button" class="btn btn-primary" id="site-workflow-review" data-uuid="<?= $this->escape($job['uuid']) ?>"><?= $this->escape(__('Pregledaj cijeli uvoz')) ?></button>
        <?php if ($flowPhase === 'review') : ?>
    <div class="alert alert-info mt-3">
     <h3 class="h5"><?= $this->escape(__('Pregled prije primjene')) ?></h3>
     <dl class="row">
            <?php foreach (['users_create' => __('Novi neaktivni računi'), 'users_map' => __('Mapirani korisnici'), 'groups_create' => __('Nove grupe'), 'groups_map' => __('Mapirane grupe')] as $key => $label) : ?>
       <dt class="col-sm-8"><?= $this->escape($label) ?></dt><dd class="col-sm-4"><?= (int)($flow['counts']['identities'][$key] ?? 0) ?></dd>
            <?php endforeach; ?>
            <?php foreach (['spaces' => __('Područja'), 'calendars' => __('Kalendari'), 'events' => __('Događaji'), 'skipped_events' => __('Preskočeni događaji')] as $key => $label) : ?>
       <dt class="col-sm-8"><?= $this->escape($label) ?></dt><dd class="col-sm-4"><?= (int)($flow['counts'][$key] ?? 0) ?></dd>
            <?php endforeach; ?>
     </dl>
     <p><?= $this->escape(__('Pregled još nije uvoz. Potvrdom se primjenjuju svi odabrani korisnici, grupe, članstva, kalendari i područja.')) ?></p>
     <button type="button" class="btn btn-danger" id="site-workflow-start" data-uuid="<?= $this->escape($job['uuid']) ?>" data-token="<?= $this->escape($flow['token']) ?>"><?= $this->escape(__('Potvrdi i pokreni cijeli uvoz')) ?></button>
    </div>
        <?php endif; ?>
  <?php elseif ($flowPhase === 'completed') : ?>
   <p class="alert alert-success"><?= $this->escape(__('Zajednički uvoz sitea je dovršen. Provjerite detaljne izvještaje i preostale neriješene reference.')) ?></p>
  <?php elseif ($flowPhase === 'failed') : ?>
   <p class="alert alert-danger"><?= $this->escape((string)($job['error_message'] ?? __('Uvoz je zaustavljen zbog greške.'))) ?></p>
   <p><?= $this->escape(__('Dovršeni koraci i njihovi izvještaji ostaju sačuvani. Uvoz nije označen uspješnim.')) ?></p>
  <?php else : ?>
   <p><?= $this->escape($phaseLabels[$flowPhase] ?? __('U tijeku')) ?></p>
   <button type="button" class="btn btn-primary" id="site-workflow-resume" data-uuid="<?= $this->escape($job['uuid']) ?>"><?= $this->escape(__('Nastavi cijeli uvoz')) ?></button>
  <?php endif; ?>
  <div id="site-workflow-progress" class="mt-3<?= $progressVisible ? '' : ' d-none' ?>">
   <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
    <span><?= $this->escape(__('Napredak trenutačnog koraka')) ?></span>
    <strong id="site-workflow-percent"><?= $progressValue !== null ? (int)$progressValue . '%' : $this->escape($flowPhase === 'failed' ? __('Zaustavljeno') : __('Obrada je u tijeku…')) ?></strong>
   </div>
   <div class="progress" style="height: 1.5rem;">
    <div id="site-workflow-progress-bar" class="progress-bar<?= $progressValue === null && $flowPhase !== 'failed' ? ' progress-bar-striped progress-bar-animated' : '' ?><?= $flowPhase === 'failed' ? ' bg-danger' : '' ?>" role="progressbar" aria-label="<?= $this->escape(__('Napredak trenutačnog koraka')) ?>" aria-valuemin="0" aria-valuemax="100"<?= $progressValue !== null ? ' aria-valuenow="' . (int)$progressValue . '"' : '' ?> style="width: <?= $progressValue !== null ? (int)$progressValue : 100 ?>%"></div>
   </div>
  </div>
  <div id="site-workflow-status" class="mt-2" role="status" aria-live="polite"><?= $progressVisible ? $this->escape($phaseLabels[$flowPhase] ?? __('U tijeku')) : '' ?></div>
 </div>
</section>
