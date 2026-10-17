<?php

declare(strict_types=1);

// EN: Space settings are draft-only until the one workflow confirmation; all source labels are escaped.
//
// HR: Postavke područja samo su nacrt do jedne potvrde postupka; sve izvorne oznake su kodirane.
$contentState = $siteContentState;
$contentPlan = $contentState['plan'] ?? [];
$contentReady = ($job['status'] ?? '') === 'ready';
?>
<section class="confluence-import-panel mt-3" id="site-content" aria-labelledby="site-content-title">
 <div class="confluence-import-body">
  <h2 class="h4" id="site-content-title"><?= $this->escape(__('Uvoz odabranih područja sitea')) ?></h2>
  <p><?= $this->escape(__('Privici se uvoze iz zajedničkog ZIP-a ako postoje. Autori, ovlasti i poveznice koriste mapiranja zajedničkog uvoza.')) ?></p>
  <?php if ($contentReady) : ?>
   <form id="confluence-site-content" data-uuid="<?= $this->escape((string)$job['uuid']) ?>">
        <?php if (($contentState['personal_groups'] ?? []) !== []) : ?>
    <div class="alert alert-info" data-personal-space-groups>
     <h3 class="h5"><?= $this->escape(__('Više osobnih područja istog vlasnika')) ?></h3>
     <p><?= $this->escape(__('Odabrana izvorna područja mogu dodati nove stranice u jedno osobno područje vlasnika. Postojeće stranice, početna stranica i ovlasti ostaju nepromijenjene; svaki izvor ima zaseban izvještaj.')) ?></p>
            <?php foreach ($contentState['personal_groups'] as $group) : ?>
     <p><strong><?= $this->escape($group['owner_label']) ?></strong>: <?= $this->escape(implode(', ', array_column($group['sources'], 'key'))) ?></p>
     <button type="button" class="btn btn-outline-secondary mb-2" data-personal-space-append="<?= $this->escape(json_encode(array_column($group['sources'], 'id'), JSON_THROW_ON_ERROR)) ?>"><?= $this->escape(__('Postavi dodavanje za ova osobna područja')) ?></button>
            <?php endforeach; ?>
    </div>
        <?php endif; ?>
    <label class="form-label" for="site-content-language"><?= $this->escape(__('Jezik')) ?></label>
    <select class="form-select mb-2" name="language" id="site-content-language">
        <?php foreach ($supportedLanguages as $language) : ?>
     <option value="<?= $this->escape($language) ?>"<?= $language === ($contentPlan['language'] ?? $defaultLanguage) ? ' selected' : '' ?>><?= $this->escape($language) ?></option>
        <?php endforeach; ?>
    </select>
        <?php foreach (['include_attachments' => __('Uvezi privitke'), 'include_comments' => __('Uvezi komentare'), 'include_history' => __('Uvezi povijest'), 'include_drafts' => __('Uvezi nacrte'), 'include_deleted' => __('Uvezi obrisane stranice')] as $flag => $label) : ?>
     <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="<?= $flag ?>"<?= ($contentPlan[$flag] ?? in_array($flag, ['include_attachments', 'include_comments'], true)) ? ' checked' : '' ?>><span class="form-check-label"><?= $this->escape($label) ?></span></label>
        <?php endforeach; ?>
    <label class="form-label" for="site-content-filter"><?= $this->escape(__('Pretraži odabrana područja')) ?></label>
    <input class="form-control mb-2" type="search" id="site-content-filter">
        <?php $sitePagerLabel = __('Stranice postavki područja');
        require __DIR__ . '/site-table-pagination.php'; ?>
    <p data-pager-empty hidden><?= $this->escape(__('Nema rezultata za odabrani filtar.')) ?></p>
    <div class="table-responsive"><table class="table"><thead><tr><th><?= $this->escape(__('Izvorno područje')) ?></th><th><?= $this->escape(__('Naziv')) ?></th><th>Slug</th><th><?= $this->escape(__('Radnja')) ?></th></tr></thead><tbody>
        <?php foreach ($contentState['spaces'] as $entry) :
            $id = (string)$entry['source_id'];
            $decision = $contentPlan['decisions'][$id] ?? [];
            ?>
     <tr data-site-space="<?= $this->escape($id) ?>"><td><?= $this->escape((string)$entry['name']) ?><div><code><?= $this->escape((string)$entry['source_key']) ?></code></div>
            <?php if (is_array($decision['target']['workspace'] ?? null)) : ?>
       <small><?= $this->escape(__('Postojeće područje')) ?>: <?= $this->escape((string)$decision['target']['workspace']['name']) ?></small>
            <?php endif; ?>
      </td><td><input class="form-control" data-space-name required maxlength="190" aria-label="<?= $this->escape(__('Naziv') . ': ' . (string)$entry['source_key']) ?>" value="<?= $this->escape((string)($decision['name'] ?? $entry['name'])) ?>"></td>
      <td><input class="form-control" data-space-slug required maxlength="190" aria-label="<?= $this->escape('Slug: ' . (string)$entry['source_key']) ?>" value="<?= $this->escape((string)($decision['slug'] ?? mb_strtolower((string)$entry['source_key']))) ?>"></td>
      <td><select class="form-select" data-space-strategy aria-label="<?= $this->escape(__('Radnja') . ': ' . (string)$entry['source_key']) ?>">
            <?php foreach ([...(($entry['type'] ?? '') === 'personal' ? ['append' => __('Dodaj samo nove stranice; postojeće ostaju nepromijenjene')] : []), 'new' => __('Novo područje'), 'copy' => __('Nova kopija'), 'replace' => __('Zamijeni ranije uvezeno područje')] as $strategy => $label) : ?>
       <option value="<?= $strategy ?>"<?= $strategy === ($decision['strategy'] ?? 'new') ? ' selected' : '' ?>><?= $this->escape($label) ?></option>
            <?php endforeach; ?>
      </select>
            <?php if (($entry['type'] ?? '') === 'personal' && isset($contentState['archival_sources'][$entry['owner_source_key'] ?? ''])) : ?>
       <label class="form-label mt-2"><?= $this->escape(__('Preuzimanje neaktivnog vlasnika')) ?>
        <select class="form-select" data-space-owner-mode>
         <option value="mapped"><?= $this->escape(__('Osobno područje mapiranog vlasnika')) ?></option>
         <option value="custody"<?= ($decision['owner_mode'] ?? '') === 'custody' ? ' selected' : '' ?>><?= $this->escape(__('Zasebno arhivsko područje pod upravom administratora')) ?></option>
        </select>
       </label>
       <p class="small"><?= $this->escape(__('Skrbništvo zahtijeva preskočenog neaktivnog vlasnika i novo zasebno područje. Postojeći sadržaj ostaje nepromijenjen.')) ?></p>
            <?php endif; ?>
      </td></tr>
        <?php endforeach; ?>
    </tbody></table></div>
        <?php require __DIR__ . '/site-table-pagination.php'; ?>
    <p class="alert alert-warning"><?= $this->escape(__('Zamjena trajno uklanja ranije uvezeno ciljano područje prije ponovnog uvoza. Osobno područje zahtijeva potvrđeno mapiranog vlasnika i ne podržava kopiju.')) ?></p>
    <button type="submit" class="btn btn-primary"><?= $this->escape(__('Spremi postavke sadržaja')) ?></button>
   </form>
  <?php endif; ?>
  <div id="confluence-site-content-status" class="mt-2" role="status" aria-live="polite"></div>
 </div>
</section>
