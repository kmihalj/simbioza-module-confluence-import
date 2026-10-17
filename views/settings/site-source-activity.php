<?php

declare(strict_types=1);

// EN: Account and directory activity are separate exported facts; missing flags stay visibly unknown.
//
// HR: Aktivnost računa i imenika odvojene su izvezene činjenice; nedostajuće oznake vidljivo su nepoznate.
$activityLabel = static fn(?bool $value): string => $value === null ? __('Nepoznato') : ($value ? __('Aktivno') : __('Neaktivno'));
?>
<span class="small d-block" data-source-activity="<?= $this->escape(\AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSourceActivity::status($source)) ?>">
<?php if ($source['reference_only'] ?? false) : ?>
    <?= $this->escape(__('Samo povijesna referenca autora; nema računa imenika.')) ?>
<?php else : ?>
    <?= $this->escape(__('Račun') . ': ' . $activityLabel($source['account_active'] ?? null)) ?> · <?= $this->escape(__('Imenik') . ': ' . $activityLabel($source['directory_active'] ?? null)) ?>
<?php endif; ?>
</span>
