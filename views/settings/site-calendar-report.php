<?php

declare(strict_types=1);

// EN: The durable report contains only escaped metadata and stable target UUIDs.
// Its title, source and results share the theme's content surface, not the hero background.
//
// HR: Trajni izvještaj sadrži samo kodirane metapodatke i stabilne ciljne UUID-jeve.
// Naslov, izvor i rezultati koriste sadržajnu površinu teme, ne pozadinu hero zaglavlja.
?>
<section class="card" data-confluence-site-report="calendars"><div class="card-body">
<h1 class="h3"><?= $this->escape($title) ?></h1>
<p><a href="<?= $this->escape($settingsPath) ?>"><?= $this->escape(__('Confluence import')) ?></a></p>
<p><?= $this->escape((string)$summary['source_url']) ?></p>
<?php if ($summary['partial'] ?? false) : ?>
 <p class="alert alert-warning"><?= $this->escape(__('Izvršavanje je zaustavljeno. Izvještaj sadrži samo potvrđene pakete.')) ?></p>
<?php endif; ?>
<?php foreach ($summary['results'] ?? [] as $result) : ?>
 <section class="card mb-3"><div class="card-body">
  <h2 class="h4"><?= $this->escape($result['name']) ?></h2>
  <p><code><?= $this->escape($result['target_uuid']) ?></code></p>
  <p><?= $this->escape(__('Vrsta kalendara')) ?>: <?= $this->escape(($result['calendar_type'] ?? '') === 'personal' ? __('Osobni kalendar') : __('Timski kalendar')) ?></p>
  <p><?= $this->escape(__('Novi / postojeći / preskočeni događaji')) ?>: <?= (int)$result['created'] ?> / <?= (int)$result['existing'] ?> / <?= (int)$result['skipped'] ?></p>
    <?php foreach ($result['access_warnings'] as $warning) :
        ?><p><?= $this->escape($warning) ?></p><?php
    endforeach; ?>
    <?php foreach ($result['warnings'] as $warning) :
        ?><p><code><?= $this->escape($warning['id']) ?></code> <?= $this->escape($warning['reason']) ?></p><?php
    endforeach; ?>
 </div></section>
<?php endforeach; ?>
</div></section>
