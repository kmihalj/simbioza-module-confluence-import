<?php

declare(strict_types=1);

// EN: Shared controls paginate a draft without submitting it; each form keeps all off-page inputs.
//
// HR: Zajedničke kontrole listaju nacrt bez slanja; svaki obrazac zadržava sve unose s ostalih stranica.
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 my-3" data-site-table-pagination
 data-count-label="<?= $this->escape(__('Prikazano {from}–{to} od {total}.')) ?>"
 data-selected-label="<?= $this->escape(__('Odabrano: {selected} / {all}.')) ?>"
 data-first-label="<?= $this->escape(__('Prva stranica')) ?>" data-previous-label="<?= $this->escape(__('Prethodna stranica')) ?>"
 data-next-label="<?= $this->escape(__('Sljedeća stranica')) ?>" data-last-label="<?= $this->escape(__('Zadnja stranica')) ?>"
 data-page-label="<?= $this->escape(__('Stranica %d')) ?>">
 <div>
  <p class="small text-body-secondary mb-0" data-pager-count role="status" aria-live="polite"></p>
  <p class="small text-body-secondary mb-0" data-pager-selected></p>
 </div>
 <label class="d-flex align-items-center gap-2 small"><?= $this->escape(__('Redaka po stranici')) ?>
  <select class="form-select form-select-sm w-auto" data-pager-size>
   <option value="25">25</option><option value="50">50</option><option value="100">100</option>
  </select>
 </label>
 <nav aria-label="<?= $this->escape($sitePagerLabel) ?>"><ul class="pagination pagination-sm flex-wrap mb-0" data-pager-pages></ul></nav>
</div>
