<?php

declare(strict_types=1);

// EN: Render escaped metadata only; imported scripts stay in the existing child review, never execute here.
//
// HR: Prikazivati samo kodirane metapodatke; uvezene skripte ostaju u pregledu podposla, ovdje se ne izvršavaju.
$reportStatuses = ['uploading' => __('Prijenos'), 'scanning' => __('Provjera arhive'), 'ready' => __('Spremno'),
    'running' => __('U tijeku'), 'failed' => __('Greška'), 'completed' => __('Dovršeno')];
$reportKinds = ['site_access' => __('Korisnici, grupe i članstva sitea'), 'site_space' => __('Područje'), 'site_calendars' => __('Kalendari')];
$reportCounters = ['users_created' => __('Novi neaktivni računi'), 'users_mapped' => __('Mapirani korisnici'),
    'groups_created' => __('Nove grupe'), 'groups_mapped' => __('Mapirane grupe'), 'memberships_unresolved' => __('Neriješena članstva'),
    'pages_imported' => __('Uvezene stranice'), 'history_versions_imported' => __('Uvezene povijesne verzije'),
    'attachments_imported' => __('Uvezeni privici'), 'attachments_failed' => __('Neuspjeli privici'), 'comments_imported' => __('Uvezeni komentari'),
    'warnings' => __('Upozorenja'), 'scripts_pending' => __('Skripte za provjeru'), 'review_pending' => __('Stavke za ručnu provjeru'),
    'calendars' => __('Kalendari'), 'events_created' => __('Novi događaji'), 'events_existing' => __('Postojeći događaji'), 'events_skipped' => __('Preskočeni događaji'),
    'links_resolved' => __('Razriješene poveznice'), 'links_unresolved' => __('Nerazriješene poveznice')];
?>
<h2 class="h4"><?= $this->escape(__('Zajednički izvještaj uvoza sitea')) ?></h2>
<p><?= $this->escape((string)$siteOverview['source_url']) ?> · <?= $this->escape($reportStatuses[$siteOverview['status']] ?? __('Nepoznato')) ?></p>
<p><?= $this->escape(__('Brojanja zbrajaju zabilježene postupke, ne jedinstvene objekte. Ponovni uvoz ima zaseban izvještaj.')) ?></p>
<p><?= $this->escape(__('Stanje poveznica prikazuje i razrješenja iz kasnijih uvoza. Detaljni izvještaji korisnika, kalendara i svakog područja ostaju zasebni.')) ?></p>
<?php if ($siteOverview['reports'] === []) : ?>
 <p><?= $this->escape(__('Još nema pokrenutih koraka uvoza.')) ?></p>
<?php endif; ?>
<div class="table-responsive"><table class="table"><thead><tr><th><?= $this->escape(__('Vrsta')) ?></th><th><?= $this->escape(__('Stanje')) ?></th><th><?= $this->escape(__('Sažetak')) ?></th><th><?= $this->escape(__('Izvještaj')) ?></th></tr></thead><tbody>
<?php foreach ($siteOverview['reports'] as $report) : ?>
 <tr><td><?= $this->escape($reportKinds[$report['operation']] ?? __('Nepoznato')) ?><div><?= $this->escape((string)$report['name']) ?></div></td>
 <td><?= $this->escape($reportStatuses[$report['status']] ?? __('Nepoznato')) ?><?= $report['partial'] ? ' · ' . $this->escape(__('Djelomično')) : '' ?></td>
 <td><?php foreach ($report['counts'] as $key => $value) : ?>
        <?php if ($value > 0) :
            ?><div><?= $this->escape($reportCounters[$key] ?? $key) ?>: <?= (int)$value ?></div><?php
        endif; ?>
     <?php endforeach; ?></td>
 <td><?php if ($report['status'] === 'completed') :
        ?><a href="<?= $this->escape($settingsPath . '/report/' . rawurlencode((string)$report['uuid'])) ?>"><?= $this->escape(__('Otvori izvještaj importa')) ?></a><?php
     else :
            ?><?= $this->escape(__('Detalji su dostupni nakon dovršetka koraka.')) ?><?php
     endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if ($siteOverview['unavailable_reports'] > 0) :
    ?><p class="alert alert-warning"><?= $this->escape(__('Neki povezani izvještaji nisu dostupni. Provjerite jesu li uključeni u obnovljeni backup.')) ?> <?= (int)$siteOverview['unavailable_reports'] ?></p><?php
endif; ?>
<p><?= $this->escape($siteOverview['source_released'] ? __('Radna kopija arhive i indeksi uklonjeni su; izvještaji i uvezeni sadržaj ostaju sačuvani.') : __('Radna kopija arhive ostaje za nastavak uvoza i kalendare.')) ?></p>
