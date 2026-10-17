<?php

declare(strict_types=1);

// EN: Parent reports remain readable before completion and after source cleanup or backup restoration.
// Keep all report metadata on a theme-owned content card, never as loose hero-overlapping text.
//
// HR: Izvještaji roditelja čitljivi su prije dovršetka i nakon čišćenja izvora ili obnove backupa.
// Svi metapodaci izvještaja ostaju na sadržajnoj kartici teme, ne kao tekst preko hero zaglavlja.
?>
<section class="card" data-confluence-site-report="overview"><div class="card-body">
<h1 class="h3"><?= $this->escape($title) ?></h1>
<p><a href="<?= $this->escape($settingsPath . '?job=' . rawurlencode((string)$job['uuid'])) ?>"><?= $this->escape(__('Confluence import')) ?></a></p>
<?php if (is_array($siteOverview ?? null)) {
    require __DIR__ . '/site-report-overview.php';
} ?>
</div></section>
