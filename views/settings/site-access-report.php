<?php

declare(strict_types=1);

// EN: Durable audit labels are escaped; raw target IDs and authentication data are not persisted here.
// Keep the entire identity report inside a theme-owned content card for readable hero overlap.
//
// HR: Trajne oznake revizije kodiraju se; ovdje se ne spremaju sirovi ciljni ID-jevi ni podaci prijave.
// Cijeli izvještaj identiteta ostaje u sadržajnoj kartici teme radi čitljivog preklapanja zaglavlja.
$labels = ['users_created' => __('Novi neaktivni računi'), 'users_mapped' => __('Mapirani korisnici'),
    'users_auto_map' => __('Automatski mapirani'), 'users_auto_create' => __('Automatska izrada neaktivnog računa'),
    'users_conflict' => __('Potrebna ručna provjera'),
    'groups_created' => __('Nove grupe'), 'groups_mapped' => __('Mapirane grupe'),
    'groups_auto_map' => __('Automatski mapirane grupe'), 'groups_auto_create' => __('Automatski predviđene nove grupe'),
    'groups_conflict' => __('Grupe za ručnu provjeru'),
    'memberships_unresolved' => __('Neriješena članstva'), 'nested_groups_omitted' => __('Ugniježđene grupe nisu uvezene'),
    'permission_records_retained' => __('Zapisi ovlasti za kasniji uvoz područja')];
require __DIR__ . '/site-user-reason-labels.php';
?>
<section class="card" data-confluence-site-report="identities"><div class="card-body">
<h1 class="h3"><?= $this->escape($title) ?></h1>
<p><a href="<?= $this->escape($settingsPath) ?>"><?= $this->escape(__('Confluence import')) ?></a></p>
<p><?= $this->escape((string)$summary['source_url']) ?> · <code><?= $this->escape((string)$job['uuid']) ?></code></p>
<dl class="row"><?php foreach ($labels as $key => $label) :
    ?><dt class="col-sm-8"><?= $this->escape($label) ?></dt><dd class="col-sm-4"><?= (int)($summary[$key] ?? 0) ?></dd><?php
                endforeach; ?></dl>
<h2><?= $this->escape(__('Članstva')) ?></h2>
<p><?= $this->escape(__('Dodana / postojeća / izuzeta')) ?>: <?= (int)($summary['memberships']['added'] ?? 0) ?> / <?= (int)($summary['memberships']['existing'] ?? 0) ?> / <?= (int)($summary['memberships']['excluded'] ?? 0) ?></p>
<div class="table-responsive"><table class="table"><thead><tr><th><?= $this->escape(__('Vrsta')) ?></th><th><?= $this->escape(__('Izvor')) ?></th><th><?= $this->escape(__('Radnja')) ?></th><th><?= $this->escape(__('Cilj')) ?></th><th><?= $this->escape(__('Razlog odabira')) ?></th></tr></thead><tbody>
<?php foreach ($summary['decisions'] ?? [] as $decision) : ?>
 <tr><td><?= $this->escape($decision['kind'] === 'users' ? __('Korisnici') : __('Grupe')) ?></td><td><?= $this->escape((string)$decision['source_label']) ?><div class="small"><?= $this->escape((string)$decision['source_id']) ?> · <?= $this->escape((string)$decision['directory_id']) ?></div></td><td><?= $this->escape(match ($decision['action']) {
        'created' => __('Stvoreno'), 'skipped' => __('Preskočeno'), default => __('Mapirano')
         }) ?></td><td><?= $this->escape((string)$decision['target_label']) ?></td><td><?= $this->escape($siteUserReasonLabels[$decision['reason'] ?? 'manual'] ?? '') ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
</div></section>
