<?php

declare(strict_types=1);

// EN: Paginate source users; saved choices from other pages remain in the server-side review.
// Never insert source values as HTML or assume that a suggested target is an approved mapping.
//
// HR: Izvorne korisnike podijeli po stranicama; odabiri drugih stranica ostaju u pregledu na poslužitelju.
// Izvorne vrijednosti ne umetati kao HTML niti pretpostaviti da je predloženi cilj potvrđeno mapiranje.
$state = $siteAccessState;
$catalog = $state['catalog'] ?? [];
$targetUsersById = array_column($state['target_users'] ?? [], null, 'id');
$sourceUsersById = array_column($catalog['users'] ?? [], null, 'source_id');
$pagination = \AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteUserPagination::paginate(
    $catalog['users'] ?? [],
    $siteAccessQuery,
    $state['choices']['users'] ?? [],
    $state['choices']['user_reasons'] ?? [],
    $state['choices']['user_conflicts'] ?? []
);
require __DIR__ . '/site-user-reason-labels.php';
$statusLabels = ['all' => __('Svi korisnici'), 'mapped' => __('Mapirani korisnici'), 'unmapped' => __('Nemapirani korisnici'),
    'create' => __('Predviđeni za izradu'), 'automatic' => __('Automatski mapirani'), 'conflict' => __('Potrebna ručna provjera'),
    'inactive' => __('Izvorno neaktivni računi ili imenici'), 'reference_only' => __('Samo povijesne reference autora'),
    'activity_unknown' => __('Nepoznata aktivnost izvora')];
$sourceQuery = $pagination['query'];
$identityPagePath = static fn(int $page): string => $settingsPath . '?' . http_build_query([
    ...array_filter($siteAccessQuery, is_scalar(...)), 'job' => $job['uuid'],
    'identity_page' => $page, 'identity_q' => $sourceQuery, 'identity_per_page' => $pagination['per_page'],
    'identity_status' => $pagination['status'],
]) . '#site-identities';
$preview = $state['preview'] ?? null;
$labels = [
    'users_create' => __('Novi neaktivni računi'), 'users_map' => __('Mapirani korisnici'), 'users_skip' => __('Preskočeni korisnici'),
    'groups_create' => __('Nove grupe'), 'groups_map' => __('Mapirane grupe'), 'groups_skip' => __('Preskočene grupe'),
    'membership_candidates' => __('Kandidati za članstvo'), 'membership_unresolved' => __('Neriješena članstva'),
    'users_auto_map' => __('Automatski mapirani'), 'users_auto_create' => __('Automatska izrada neaktivnog računa'),
    'users_conflict' => __('Potrebna ručna provjera'),
    'groups_auto_map' => __('Automatski mapirane grupe'), 'groups_auto_create' => __('Automatski predviđene nove grupe'),
    'groups_conflict' => __('Grupe za ručnu provjeru'),
    'nested_groups' => __('Ugniježđene grupe nisu uvezene'), 'permission_records_retained' => __('Zapisi ovlasti za kasniji uvoz područja'),
];
?>
<section class="confluence-import-panel mt-3" id="site-identities" aria-labelledby="site-identities-title">
 <div class="confluence-import-body">
  <h2 class="h4" id="site-identities-title"><?= $this->escape(__('Korisnici, grupe i članstva sitea')) ?></h2>
  <div id="confluence-site-access-status" role="status" aria-live="polite"></div>
  <?php if (($state['ready'] ?? false) !== true) : ?>
   <p><?= $this->escape(__('Priprema pregleda može potrajati nekoliko minuta. Ne stvara račune niti mijenja ovlasti.')) ?></p>
   <button class="btn btn-primary" id="confluence-site-access-prepare" type="button" data-uuid="<?= $this->escape((string)$job['uuid']) ?>"><?= $this->escape(__('Pripremi pregled identiteta')) ?></button>
  <?php else : ?>
      <?php if (($state['review_error'] ?? '') !== '') :
            ?><p class="alert alert-warning"><?= $this->escape((string)$state['review_error']) ?></p><?php
      endif; ?>
   <p><?= count($catalog['users']) ?> <?= $this->escape(__('Korisnici')) ?> · <?= count($catalog['groups']) ?> <?= $this->escape(__('Grupe')) ?> · <?= count($catalog['memberships']) ?> <?= $this->escape(__('Članstva')) ?></p>
   <p class="alert alert-warning"><?= $this->escape(__('Novi računi su neaktivni i nemaju omogućenu prijavu. Lozinke, tokeni, SSO postavke i administratorske oznake se ne prenose. Nakon uvoza prijavu podesite u Auth postavkama.')) ?></p>
   <p><?= $this->escape(__('Za duple račune prvo odaberite glavni račun za izradu ili mapiranje. Za ostale u pretraživom odabiru odaberite „Isti korisnik kao izvorni račun”. Zajednički email sam po sebi ne potvrđuje da su računi ista osoba.')) ?></p>
   <p><a href="<?= $this->escape($settingsPath . '?' . http_build_query(['job' => $job['uuid'], 'mapping_review' => '1']) . '#review-duplicates') ?>"><?= $this->escape(__('Pregledaj prijedloge duplih računa i kalendara')) ?></a></p>
   <p class="small"><?= $this->escape(__('Pregled koristi spremljene postavke; prije otvaranja spremite eventualne promjene.')) ?></p>
   <form id="confluence-site-identity-filter" method="get" action="<?= $this->escape($settingsPath) ?>#site-identities" class="mb-3">
      <?php foreach ([...array_filter($siteAccessQuery, is_scalar(...)), 'job' => $job['uuid']] as $key => $value) : ?>
            <?php if (!in_array($key, ['identity_q', 'identity_page', 'identity_per_page', 'identity_status'], true)) : ?>
    <input type="hidden" name="<?= $this->escape((string)$key) ?>" value="<?= $this->escape((string)$value) ?>">
            <?php endif; ?>
      <?php endforeach; ?>
    <label for="site-identity-filter" class="form-label"><?= $this->escape(__('Pretraži izvorne korisnike')) ?></label>
    <div class="input-group"><input id="site-identity-filter" type="search" class="form-control" name="identity_q" value="<?= $this->escape($sourceQuery) ?>"><button class="btn btn-outline-secondary"><?= $this->escape(__('Pretraži')) ?></button></div>
    <label for="site-identity-status" class="form-label mt-2"><?= $this->escape(__('Status mapiranja')) ?></label>
    <select id="site-identity-status" name="identity_status" class="form-select w-auto">
      <?php foreach ($statusLabels as $key => $label) : ?>
     <option value="<?= $key ?>"<?= $key === $pagination['status'] ? ' selected' : '' ?>><?= $this->escape($label) ?> (<?= $pagination['counts'][$key] ?>)</option>
      <?php endforeach; ?>
    </select>
    <label for="site-identity-per-page" class="form-label mt-2"><?= $this->escape(__('Korisnika po stranici')) ?></label>
    <select id="site-identity-per-page" name="identity_per_page" class="form-select w-auto">
      <?php foreach ([25, 50, 100] as $size) :
            ?><option value="<?= $size ?>"<?= $size === $pagination['per_page'] ? ' selected' : '' ?>><?= $size ?></option><?php
      endforeach; ?>
    </select>
   </form>
   <form id="confluence-site-access" data-uuid="<?= $this->escape((string)$job['uuid']) ?>">
    <label for="site-source-url" class="form-label"><?= $this->escape(__('Glavna adresa izvornog Confluence sitea')) ?></label>
    <input class="form-control mb-2" id="site-source-url" name="source_url" type="url" required maxlength="2048" value="<?= $this->escape((string)($state['source_url'] ?: $sourceBaseUrl)) ?>" placeholder="https://wiki.example.org/confluence">
    <p class="small"><?= $this->escape(__('Koristite istu adresu pri ponovnom uvozu istog sitea. Ona razdvaja mapiranja različitih siteova i ne dohvaća se mrežom.')) ?></p>
    <label class="form-check mb-2"><input class="form-check-input" name="auto_map_users" type="checkbox"<?= ($state['auto_map_users'] ?? true) ? ' checked' : '' ?>><span class="form-check-label"><?= $this->escape(__('Automatski mapiraj po jednoznačnom korisničkom imenu ili emailu')) ?></span></label>
    <label class="form-check mb-2"><input class="form-check-input" name="create_unmapped_users" type="checkbox"<?= ($state['create_unmapped_users'] ?? false) ? ' checked' : '' ?>><span class="form-check-label"><?= $this->escape(__('Automatski kreiraj neaktivne korisnike za sve nemapirane')) ?></span></label>
    <label class="form-check mb-2"><input class="form-check-input" name="create_archival_users" type="checkbox"<?= ($state['create_archival_users'] ?? false) ? ' checked' : '' ?>><span class="form-check-label"><?= $this->escape(__('U automatsku izradu uključi i izvorno neaktivne račune, ugašene imenike i povijesne reference autora')) ?></span></label>
    <p class="small"><?= $this->escape(__('Bez tog dodatnog odabira neaktivni izvori ne stvaraju nove račune automatski. Izričita mapiranja i pojedinačna izrada ostaju mogući. Nepoznata aktivnost nije dokaz neaktivnosti.')) ?></p>
    <p class="small"><?= $this->escape(__('Automatska pravila vrijede za cijeli izvorni popis, ne samo prikazanu stranicu ili filter. Ručni odabiri imaju prednost; dvosmislenosti i sukobi ostaju za ručnu provjeru. Računi se stvaraju tek nakon potvrde pregleda.')) ?></p>
    <p><?= $this->escape(__('Pri promjeni stranice, pretrage ili veličine stranice odabiri se automatski spremaju u pregled, bez stvaranja korisnika i grupa. Prazan odabir znači preskočiti.')) ?></p>
    <h3 class="h5"><?= $this->escape(__('Korisnici')) ?> — <?= $pagination['page'] ?> / <?= $pagination['pages'] ?></h3>
      <?php require __DIR__ . '/site-user-pagination.php'; ?>
    <div class="table-responsive" style="max-height:32rem;overflow:auto" tabindex="0" aria-label="<?= $this->escape(__('Korisnici')) ?>"><table class="table"><thead><tr><th><?= $this->escape(__('Izvorni korisnik')) ?></th><th><?= $this->escape(__('Ciljni korisnik')) ?></th></tr></thead><tbody>
      <?php foreach ($pagination['items'] as $offset => $source) : ?>
            <?php $id = (string)$source['source_id'];
            $value = (string)($state['choices']['users'][$id] ?? '');
            $reason = (string)($state['choices']['user_reasons'][$id] ?? 'unmapped');
            $pickerId = 'c2-user-' . $offset;
            $target = $targetUsersById[(int)$value] ?? [];
            $targetLabel = trim((string)($target['display_name'] ?? ''));
            $targetLogin = (string)($target['login_identifier'] ?? '');
            $targetLabel = $targetLabel !== '' && $targetLabel !== $targetLogin ? $targetLabel . ' · ' . $targetLogin : $targetLogin;
            $selectedLabel = $value === '__create__' ? __('Izradi neaktivnog korisnika bez prijave') : ($value !== '' ? $targetLabel : __('Preskoči')); ?>
            <?php if (str_starts_with($value, \AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteIdentityPlan::SOURCE_PREFIX)) :
                $primarySource = $sourceUsersById[substr($value, strlen(\AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteIdentityPlan::SOURCE_PREFIX))] ?? [];
                $primaryName = trim((string)($primarySource['display_name'] ?? '')) ?: (string)($primarySource['username'] ?? '');
                $selectedLabel = __('Isti korisnik kao izvorni račun') . ': ' . $primaryName;
                if ($primaryName !== ($primarySource['username'] ?? '')) {
                    $selectedLabel .= ' · ' . ($primarySource['username'] ?? '');
                }
            endif; ?>
     <tr><td><strong><?= $this->escape((string)($source['display_name'] ?: $source['username'])) ?></strong><div><?= $this->escape((string)$source['username']) ?> · <?= $this->escape((string)$source['email']) ?></div><small><?= $this->escape((string)$source['directory_name']) ?> · <?= $this->escape($id) ?></small><?php require __DIR__ . '/site-source-activity.php'; ?></td>
      <td><div class="confluence-import-user-picker" data-identity-picker data-search-url="<?= $this->escape($siteUsersPath . '?' . http_build_query(['uuid' => $job['uuid']])) ?>">
       <input type="hidden" data-identity-map data-site-kind="users" data-site-manual="<?= array_key_exists($id, $state['manual_users'] ?? []) ? 'true' : 'false' ?>" data-source-id="<?= $this->escape($id) ?>" value="<?= $this->escape($value) ?>">
       <button id="<?= $pickerId ?>-toggle" class="form-select text-start" type="button" data-identity-picker-toggle aria-label="<?= $this->escape(__('Ciljni korisnik') . ': ' . (string)$source['username']) ?>" aria-controls="<?= $pickerId ?>-panel" aria-expanded="false"><?= $this->escape($selectedLabel) ?></button>
       <div id="<?= $pickerId ?>-panel" class="confluence-import-user-picker-panel shadow" data-identity-picker-panel popover="manual" hidden>
        <button type="button" class="list-group-item list-group-item-action" data-identity-picker-choice="__auto__" data-identity-picker-label="<?= $this->escape(__('Koristi automatska pravila')) ?>"><?= $this->escape(__('Koristi automatska pravila')) ?></button>
        <button type="button" class="list-group-item list-group-item-action" data-identity-picker-choice="" data-identity-picker-label="<?= $this->escape(__('Preskoči')) ?>"><?= $this->escape(__('Preskoči')) ?></button>
        <button type="button" class="list-group-item list-group-item-action" data-identity-picker-choice="__create__" data-identity-picker-label="<?= $this->escape(__('Izradi neaktivnog korisnika bez prijave')) ?>"><?= $this->escape(__('Izradi neaktivnog korisnika bez prijave')) ?></button>
        <div class="p-2"><input class="form-control" type="search" autocomplete="off" aria-label="<?= $this->escape(__('Pretraži korisnike')) ?>" placeholder="<?= $this->escape((string)$source['username']) ?>" data-identity-picker-search></div>
        <div class="small px-2" data-identity-picker-status role="status" aria-atomic="true"></div><div class="list-group list-group-flush" data-identity-picker-results></div>
        <button class="btn btn-sm btn-outline-secondary" type="button" data-identity-picker-more hidden><?= $this->escape(__('Učitaj još')) ?></button>
       </div>
      </div><div class="small mt-1" data-site-user-reason data-manual-label="<?= $this->escape(__('Ručni odabir')) ?>"><?= $this->escape($siteUserReasonLabels[$reason] ?? $reason) ?></div>
            <?php if (isset($state['choices']['user_conflicts'][$id])) :
                ?><div class="small text-warning-emphasis"><?= $this->escape($siteUserConflictLabels[$state['choices']['user_conflicts'][$id]] ?? '') ?></div><?php
            endif; ?>
      </td></tr>
      <?php endforeach; ?>
      <?php if ($pagination['total'] === 0) :
            ?><tr><td colspan="2"><?= $this->escape(__('Nema korisnika koji odgovaraju pretrazi.')) ?></td></tr><?php
      endif; ?>
    </tbody></table></div>
      <?php require __DIR__ . '/site-user-pagination.php'; ?>
    <h3 class="h5"><?= $this->escape(__('Grupe')) ?></h3>
    <label class="form-check mb-2"><input class="form-check-input" name="auto_map_groups" type="checkbox"<?= ($state['auto_map_groups'] ?? true) ? ' checked' : '' ?>><span class="form-check-label"><?= $this->escape(__('Automatski mapiraj grupe po jednoznačnom nazivu')) ?></span></label>
    <label class="form-check mb-2"><input class="form-check-input" name="create_unmapped_groups" type="checkbox"<?= ($state['create_unmapped_groups'] ?? false) ? ' checked' : '' ?>><span class="form-check-label"><?= $this->escape(__('Automatski kreiraj sve grupe koje ne postoje')) ?></span></label>
    <p class="small"><?= $this->escape(__('Pravila vrijede za sve izvorne grupe. Ručni odabiri imaju prednost. Grupe jednakih naziva iz različitih imenika ostaju odvojene nastavkom ID-a imenika. Sistemske i isključene grupe ne mapiraju se automatski. Grupe se stvaraju tek nakon potvrde pregleda.')) ?></p>
    <div class="table-responsive" style="max-height:24rem;overflow:auto" tabindex="0" aria-label="<?= $this->escape(__('Grupe')) ?>"><table class="table"><thead><tr><th><?= $this->escape(__('Izvorna grupa')) ?></th><th><?= $this->escape(__('Ciljna grupa')) ?></th></tr></thead><tbody>
      <?php foreach ($catalog['groups'] as $source) : ?>
            <?php $id = (string)$source['source_id'];
            $value = (string)($state['choices']['groups'][$id] ?? ''); ?>
     <tr><td><?= $this->escape((string)$source['name']) ?><div class="small"><?= $this->escape((string)$source['directory_name']) ?> · <?= $this->escape($id) ?></div></td><td>
      <select class="form-select" data-site-kind="groups" data-site-manual="<?= array_key_exists($id, $state['manual_groups'] ?? []) ? 'true' : 'false' ?>" data-source-id="<?= $this->escape($id) ?>" aria-label="<?= $this->escape(__('Ciljna grupa') . ': ' . (string)$source['name']) ?>">
       <option value="__auto__"><?= $this->escape(__('Koristi automatska pravila')) ?></option>
       <option value=""<?= $value === '' ? ' selected' : '' ?>><?= $this->escape(__('Preskoči')) ?></option><option value="__create__"<?= $value === '__create__' ? ' selected' : '' ?>><?= $this->escape(__('Izradi novu grupu')) ?></option>
            <?php foreach ($state['target_groups'] as $target) :
                ?><option value="<?= (int)$target['id'] ?>"<?= $value === (string)$target['id'] ? ' selected' : '' ?>><?= $this->escape((string)$target['group_name']) ?> · <?= $this->escape((string)$target['group_key']) ?></option><?php
            endforeach; ?>
      </select><div class="small mt-1" data-site-user-reason data-manual-label="<?= $this->escape(__('Ručni odabir')) ?>"><?= $this->escape($siteUserReasonLabels[$state['choices']['group_reasons'][$id] ?? 'unmapped'] ?? '') ?></div>
            <?php if (isset($state['choices']['group_conflicts'][$id])) : ?>
       <div class="small text-warning-emphasis"><?= $this->escape($siteUserConflictLabels[$state['choices']['group_conflicts'][$id]] ?? '') ?></div>
            <?php endif; ?>
      </td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <label class="form-check mb-3"><input class="form-check-input" name="memberships" type="checkbox"<?= $state['memberships'] ? ' checked' : '' ?>><span class="form-check-label"><?= $this->escape(__('Dodaj članstva odabranih korisnika u odabrane grupe; postojeća članstva i izuzeća ostaju sačuvana.')) ?></span></label>
    <button class="btn btn-primary" type="submit"><?= $this->escape(__('Spremi postavke korisnika i grupa')) ?></button>
    <button class="btn btn-outline-secondary" type="button" id="confluence-site-access-reset" data-uuid="<?= $this->escape((string)$job['uuid']) ?>"><?= $this->escape(__('Poništi neprimijenjene odabire')) ?></button>
   </form>
      <?php if (is_array($preview)) : ?>
    <section class="alert alert-info mt-3"><h3 class="h5"><?= $this->escape(__('Pregled prije primjene')) ?></h3>
     <dl class="row"><?php foreach ($preview['summary'] as $key => $count) :
            ?><dt class="col-sm-8"><?= $this->escape($labels[$key] ?? $key) ?></dt><dd class="col-sm-4"><?= (int)$count ?></dd><?php
                     endforeach; ?></dl>
     <div class="table-responsive" style="max-height:24rem;overflow:auto" tabindex="0" aria-label="<?= $this->escape(__('Pregled prije primjene')) ?>">
      <table class="table"><thead><tr><th><?= $this->escape(__('Izvor')) ?></th><th><?= $this->escape(__('Radnja')) ?></th><th><?= $this->escape(__('Cilj')) ?></th><th><?= $this->escape(__('Razlog odabira')) ?></th></tr></thead><tbody>
            <?php foreach ($preview['decisions'] ?? [] as $decision) : ?>
       <tr><td><?= $this->escape((string)$decision['source_label']) ?><div class="small"><?= $this->escape((string)$decision['source_id']) ?> · <?= $this->escape((string)$decision['directory_id']) ?></div></td><td><?= $this->escape(match ($decision['action']) {
            'created' => __('Stvoreno'), 'skipped' => __('Preskočeno'), default => __('Mapirano')
               }) ?></td><td><?= $this->escape((string)$decision['target_label']) ?></td><td><?= $this->escape($siteUserReasonLabels[$decision['reason'] ?? 'manual'] ?? '') ?></td></tr>
            <?php endforeach; ?>
      </tbody></table>
     </div>
     <p><?= $this->escape(__('Korisnici, grupe i članstva primjenjuju se tek završnom potvrdom cijelog uvoza.')) ?></p>
    </section>
      <?php endif; ?>
  <?php endif; ?>
 </div>
</section>
