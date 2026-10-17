<?php

declare(strict_types=1);

// EN: Reuse identical accessible controls above and below the source table; disabled items are not links.
//
// HR: Koristi iste pristupačne kontrole iznad i ispod izvorne tablice; isključene stavke nisu poveznice.
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 my-3" data-site-identity-pagination>
 <p class="small text-body-secondary mb-0"><?= $this->escape(sprintf(__('Prikazano %1$d–%2$d od %3$d korisnika.'), $pagination['from'], $pagination['to'], $pagination['total'])) ?></p>
 <?php if ($pagination['pages'] > 1) : ?>
 <nav aria-label="<?= $this->escape(__('Stranice izvornih korisnika')) ?>">
  <ul class="pagination pagination-sm flex-wrap mb-0">
        <?php foreach ([[1, __('Prva stranica'), '«'], [$pagination['page'] - 1, __('Prethodna stranica'), '‹']] as [$number, $label, $symbol]) : ?>
   <li class="page-item<?= $pagination['page'] === 1 ? ' disabled' : '' ?>">
            <?php if ($pagination['page'] === 1) : ?>
    <span class="page-link" aria-disabled="true" aria-label="<?= $this->escape($label) ?>" title="<?= $this->escape($label) ?>"><?= $symbol ?></span>
            <?php else : ?>
    <a class="page-link" data-site-identity-page href="<?= $this->escape($identityPagePath((int)$number)) ?>" aria-label="<?= $this->escape($label) ?>" title="<?= $this->escape($label) ?>"><?= $symbol ?></a>
            <?php endif; ?>
   </li>
        <?php endforeach; ?>
        <?php foreach ($pagination['numbers'] as $number) : ?>
   <li class="page-item<?= $number === $pagination['page'] ? ' active' : ($number === null ? ' disabled' : '') ?>">
            <?php if ($number === null) : ?>
    <span class="page-link" aria-hidden="true">&hellip;</span>
            <?php elseif ($number === $pagination['page']) : ?>
    <span class="page-link" aria-current="page"><?= $number ?></span>
            <?php else : ?>
    <a class="page-link" data-site-identity-page href="<?= $this->escape($identityPagePath($number)) ?>" aria-label="<?= $this->escape(sprintf(__('Stranica %d'), $number)) ?>"><?= $number ?></a>
            <?php endif; ?>
   </li>
        <?php endforeach; ?>
        <?php foreach ([[$pagination['page'] + 1, __('Sljedeća stranica'), '›'], [$pagination['pages'], __('Zadnja stranica'), '»']] as [$number, $label, $symbol]) : ?>
   <li class="page-item<?= $pagination['page'] === $pagination['pages'] ? ' disabled' : '' ?>">
            <?php if ($pagination['page'] === $pagination['pages']) : ?>
    <span class="page-link" aria-disabled="true" aria-label="<?= $this->escape($label) ?>" title="<?= $this->escape($label) ?>"><?= $symbol ?></span>
            <?php else : ?>
    <a class="page-link" data-site-identity-page href="<?= $this->escape($identityPagePath($number)) ?>" aria-label="<?= $this->escape($label) ?>" title="<?= $this->escape($label) ?>"><?= $symbol ?></a>
            <?php endif; ?>
   </li>
        <?php endforeach; ?>
  </ul>
 </nav>
 <?php endif; ?>
</div>
