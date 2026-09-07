<?php

declare(strict_types=1);

/**
 * @var string $title
 * @var array<string,mixed>|null $preparation
 * @var string $initialError
 * @var list<array<string,mixed>> $jobs
 * @var string $settingsPath
 * @var string $jobsPath
 * @var string $csrfPath
 * @var string $uploadStartPath
 * @var string $uploadChunkPath
 * @var string $uploadFinishPath
 * @var string $batchStartPath
 * @var string $cancelPath
 * @var string $importPath
 * @var string $processPath
 * @var string $stylesPath
 * @var string $userSearchPath
 * @var list<array{name:string,size:int}> $batchArchives
 * @var array<string,mixed>|null $activeBatchJob
 * @var array<string,mixed> $currentAdministrator
 * @var string $batchDirectory
 * @var string $csrfName
 * @var string $csrfToken
 * @var int $chunkSize
 * @var int $maxArchiveSize
 * @var string $defaultLanguage
 * @var list<string> $supportedLanguages
 * @var object|null $menuRenderer
 */

$scan = is_array($preparation['scan'] ?? null) ? $preparation['scan'] : [];
$job = is_array($preparation['job'] ?? null) ? $preparation['job'] : [];
$space = is_array($scan['spaces'][0] ?? null) ? $scan['spaces'][0] : [];
$sourceUsers = is_array($scan['users'] ?? null) ? $scan['users'] : [];
$sourceGroups = is_array($scan['groups'] ?? null) ? $scan['groups'] : [];
$targetUsers = is_array($preparation['target_users'] ?? null) ? $preparation['target_users'] : [];
$targetGroups = is_array($preparation['target_groups'] ?? null) ? $preparation['target_groups'] : [];
$currentActor = is_array($preparation['current_actor'] ?? null)
    ? $preparation['current_actor']
    : $currentAdministrator;
$currentActorName = (string)($currentActor['display_name'] ?? $currentActor['login_identifier'] ?? __('Trenutni administrator'));
$activeBatchOptions = is_array($activeBatchJob['options'] ?? null) ? $activeBatchJob['options'] : [];
$batchCreateInactiveUsers = ($activeBatchOptions['batch_create_inactive_users'] ?? false) === true;
$batchPolicyLocked = ($activeBatchJob['status'] ?? '') === 'running';
$batchArchiveNames = array_values(array_map(
    static fn(array $archive): string => (string)$archive['name'],
    $batchArchives,
));
$activeBatchName = is_array($activeBatchJob) ? (string)($activeBatchJob['original_name'] ?? '') : '';
$batchArchiveCount = count($batchArchiveNames)
    + ($activeBatchName !== '' && !in_array($activeBatchName, $batchArchiveNames, true) ? 1 : 0);
$targetUsersById = [];
foreach ($targetUsers as $targetUser) {
    if (is_array($targetUser) && is_numeric($targetUser['id'] ?? null)) {
        $targetUsersById[(int)$targetUser['id']] = $targetUser;
    }
}
$suggestions = is_array($preparation['identity_suggestions'] ?? null)
    ? $preparation['identity_suggestions']
    : [];
$groupSuggestions = is_array($preparation['group_suggestions'] ?? null)
    ? $preparation['group_suggestions']
    : [];
$existingImport = is_array($preparation['existing_import'] ?? null)
    ? $preparation['existing_import']
    : null;
$ownerSourceKey = is_scalar($space['owner_source_key'] ?? null) ? (string)$space['owner_source_key'] : '';
$counts = is_array($scan['counts'] ?? null) ? $scan['counts'] : [];
$attachmentCounts = is_array($scan['attachment_counts'] ?? null) ? $scan['attachment_counts'] : [];
$statuses = is_array($scan['statuses'] ?? null) ? $scan['statuses'] : [];
$warnings = is_array($scan['warnings'] ?? null) ? $scan['warnings'] : [];
$settingsMenuHtml = null;
if (isset($menuRenderer) && is_object($menuRenderer)) {
    $settingsMenuCallback = [$menuRenderer, 'renderSettingsMenu'];
    if (is_callable($settingsMenuCallback)) {
        $renderedSettingsMenu = $settingsMenuCallback($settingsMenuActiveSection);
        $settingsMenuHtml = is_string($renderedSettingsMenu) ? $renderedSettingsMenu : null;
    }
}
?>
<link rel="stylesheet" href="<?= $this->escape($stylesPath) ?>">

<div class="row g-4">
    <aside class="col-lg-3">
        <?php if (is_string($settingsMenuHtml) && $settingsMenuHtml !== '') : ?>
            <?= $settingsMenuHtml ?>
        <?php endif; ?>
    </aside>

    <main class="col-lg-9 confluence-import-shell">
        <section class="card">
            <div class="card-body">
                <header class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
                <div>
                    <h1 class="h3 mb-1"><?= $this->escape($title) ?></h1>
                    <p class="text-body-secondary mb-0"><?= $this->escape(__('Uvezite Confluence XML backup jednog područja uz kontrolirano mapiranje korisnika, grupa i ovlasti.')) ?></p>
                </div>
                </header>

            <div class="alert alert-info" role="note">
                <strong><?= $this->escape(__('Sigurni tijek importa')) ?></strong>
                <div><?= $this->escape(__('Arhiva se najprije prenosi i provjerava bez promjene sadržaja. Import počinje tek nakon pregleda mapiranja. Neriješene ovlasti ostaju zatvorene.')) ?></div>
            </div>

            <details class="confluence-import-panel"<?= $preparation === null ? ' open' : '' ?>>
                <summary><?= $this->escape(__('1. Prenesi i provjeri Confluence arhivu')) ?></summary>
                <div class="confluence-import-body">
                    <div class="mb-3">
                        <label class="form-label" for="confluence-import-file"><?= $this->escape(__('Confluence XML ZIP arhiva')) ?></label>
                        <div class="input-group">
                            <input class="visually-hidden" id="confluence-import-file" type="file" accept=".zip,application/zip">
                            <label class="btn btn-outline-primary" for="confluence-import-file"><?= $this->escape(__('Odaberi datoteku')) ?></label>
                            <span class="form-control text-body-secondary" id="confluence-import-file-name"><?= $this->escape(__('Nije odabrana nijedna datoteka.')) ?></span>
                        </div>
                        <div class="form-text"><?= $this->escape(__('Podržan je backup jednog Confluence područja. Velika datoteka šalje se u manjim dijelovima koji se mogu nastaviti nakon prekida.')) ?></div>
                    </div>
                    <div class="progress confluence-import-progress mb-2" role="progressbar" aria-label="<?= $this->escape(__('Napredak prijenosa')) ?>">
                        <div class="progress-bar" id="confluence-import-upload-progress" style="width: 0"></div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 align-items-center confluence-import-actions">
                        <button class="btn btn-primary" type="button" id="confluence-import-upload"><?= $this->escape(__('Prenesi i provjeri')) ?></button>
                        <span class="text-body-secondary" id="confluence-import-upload-status" aria-live="polite"></span>
                    </div>
                </div>
            </details>

            <?php if ($batchArchives !== [] || is_array($activeBatchJob)) : ?>
                <details class="confluence-import-panel mt-3" open>
                    <summary><?= $this->escape(__('Batch import arhiva s poslužitelja')) ?></summary>
                    <div class="confluence-import-body">
                        <p class="mb-2"><?= $this->escape(sprintf(
                            __('Pronađeno je %d XML ZIP arhiva u direktoriju %s. Obrađuju se redom, svaka kao zaseban import i izvještaj.'),
                            $batchArchiveCount,
                            $batchDirectory,
                        )) ?></p>
                        <p class="small text-body-secondary"><?= $this->escape(__('Postojeći korisnici mapiraju se automatski. Nove Confluence grupe izrađuju se kao obične lokalne grupe. Postojeće uvezeno područje batch import neće prepisati.')) ?></p>
                        <div class="confluence-import-option mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="confluence-import-batch-create-unmapped-users"<?= $batchCreateInactiveUsers ? ' checked' : '' ?><?= $batchPolicyLocked ? ' disabled' : '' ?>>
                                <label class="form-check-label" for="confluence-import-batch-create-unmapped-users"><?= $this->escape(__('Za nemapirane identitete izradi neaktivne korisnike')) ?></label>
                            </div>
                            <div class="form-text"><?= $this->escape(sprintf(__('Ako je isključeno, nemapirani autori i urednici pripisuju se trenutnom administratoru (%s). Ako je uključeno, izrađuju se neaktivni korisnici bez mogućnosti prijave.'), $currentActorName)) ?></div>
                        </div>
                        <?php if ($batchArchives !== []) : ?>
                            <ul class="small confluence-import-batch-files">
                                <?php foreach ($batchArchives as $batchArchive) : ?>
                                    <li><code><?= $this->escape((string)$batchArchive['name']) ?></code> <span class="text-body-secondary">(<?= $this->escape(number_format((int)$batchArchive['size'] / 1048576, 1, ',', '.')) ?> MB)</span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <div class="progress confluence-import-progress mb-2 d-none" id="confluence-import-batch-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width:0"></div></div>
                        <div class="d-flex flex-wrap gap-2 align-items-center confluence-import-actions">
                            <button class="btn btn-primary" type="button" id="confluence-import-batch-start"><?= $this->escape(is_array($activeBatchJob) ? __('Nastavi batch import') : __('Uvezi sve batch arhive')) ?></button>
                            <span class="text-body-secondary" id="confluence-import-batch-status" aria-live="polite"></span>
                        </div>
                    </div>
                </details>
            <?php endif; ?>

            <?php if ($preparation !== null) : ?>
                <details class="confluence-import-panel mt-3" open>
                    <summary><?= $this->escape(__('2. Pregledaj mapiranja i pokreni import')) ?></summary>
                    <div class="confluence-import-body">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6 col-xl-3"><div class="confluence-import-option h-100"><small class="text-body-secondary d-block"><?= $this->escape(__('Izvorno područje')) ?></small><strong><?= $this->escape((string)($space['name'] ?? '')) ?></strong><div class="confluence-import-source-key"><?= $this->escape((string)($space['source_key'] ?? '')) ?></div></div></div>
                            <div class="col-md-6 col-xl-3"><div class="confluence-import-option h-100"><small class="text-body-secondary d-block"><?= $this->escape(__('Stranice')) ?></small><strong><?= $this->escape((string)($statuses['current'] ?? 0)) ?></strong><div class="small text-body-secondary"><?= $this->escape(sprintf(__('Aktualne: %1$d; povijesne: %2$d; nacrti: %3$d; obrisane: %4$d'), (int)($statuses['current'] ?? 0), (int)($statuses['history'] ?? 0), (int)($statuses['draft'] ?? 0), (int)($statuses['deleted'] ?? 0))) ?></div></div></div>
                            <div class="col-md-6 col-xl-3"><div class="confluence-import-option h-100"><small class="text-body-secondary d-block"><?= $this->escape(__('Privitci')) ?></small><strong><?= $this->escape((string)($attachmentCounts['current'] ?? $counts['Attachment'] ?? 0)) ?></strong><div class="small text-body-secondary"><?= $this->escape(__('Spremaju se privatno i uvijek se preuzimaju kao datoteke.')) ?></div></div></div>
                            <div class="col-md-6 col-xl-3"><div class="confluence-import-option h-100"><small class="text-body-secondary d-block"><?= $this->escape(__('Vrsta područja')) ?></small><strong><?= $this->escape(($space['type'] ?? '') === 'personal' ? __('Osobno područje') : __('Opće područje')) ?></strong><div class="small text-body-secondary"><?= $this->escape(__('Confluence verzija:')) ?> <?= $this->escape((string)($scan['source']['confluence_version'] ?? '')) ?></div></div></div>
                        </div>

                        <?php foreach ($warnings as $warning) : ?>
                            <?php if (is_scalar($warning)) :
                                ?><div class="alert alert-warning py-2"><?= $this->escape(__((string)$warning)) ?></div><?php
                            endif; ?>
                        <?php endforeach; ?>

                        <form id="confluence-import-form">
                            <input type="hidden" name="uuid" value="<?= $this->escape((string)($job['uuid'] ?? '')) ?>">
                            <div class="row g-3">
                                <div class="col-md-5">
                                    <label class="form-label" for="confluence-import-workspace-name"><?= $this->escape(__('Naziv ciljnog područja')) ?></label>
                                    <input class="form-control" id="confluence-import-workspace-name" name="workspace_name" value="<?= $this->escape((string)($existingImport['workspace_name'] ?? $space['name'] ?? '')) ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="confluence-import-workspace-slug"><?= $this->escape(__('Slug ciljnog područja')) ?></label>
                                    <input class="form-control" id="confluence-import-workspace-slug" name="workspace_slug" value="<?= $this->escape((string)($existingImport['workspace_slug'] ?? $space['source_key'] ?? '')) ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="confluence-import-language"><?= $this->escape(__('Jezik uvezenog sadržaja')) ?></label>
                                    <select class="form-select" id="confluence-import-language" name="language">
                                        <?php foreach ($supportedLanguages as $language) : ?>
                                            <option value="<?= $this->escape($language) ?>"<?= $language === $defaultLanguage ? ' selected' : '' ?>><?= $this->escape(strtoupper($language)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <?php if ($existingImport !== null && ($space['type'] ?? '') !== 'personal') : ?>
                                <fieldset class="mt-4">
                                    <legend class="h5"><?= $this->escape(__('Ponovni uvoz')) ?></legend>
                                    <p class="text-body-secondary">
                                        <?= $this->escape(sprintf(
                                            __('Ovo Confluence područje već je uvezeno kao %1$s (%2$s).'),
                                            (string)($existingImport['workspace_name'] ?? ''),
                                            (string)($existingImport['workspace_slug'] ?? ''),
                                        )) ?>
                                    </p>
                                    <div class="row g-2">
                                        <div class="col-lg-6">
                                            <label class="form-check confluence-import-option confluence-import-strategy h-100">
                                                <input class="form-check-input" type="radio" name="reimport_strategy" value="replace" checked>
                                                <span class="form-check-label">
                                                    <strong><?= $this->escape(__('Zamijeni postojeće uvezeno područje')) ?></strong>
                                                    <span class="d-block small text-body-secondary"><?= $this->escape(__('Postojeće područje i sav njegov sadržaj trajno će se ukloniti te ponovno uvesti iz ove arhive.')) ?></span>
                                                </span>
                                            </label>
                                        </div>
                                        <div class="col-lg-6">
                                            <label class="form-check confluence-import-option confluence-import-strategy h-100">
                                                <input class="form-check-input" type="radio" name="reimport_strategy" value="copy">
                                                <span class="form-check-label">
                                                    <strong><?= $this->escape(__('Zadrži postojeće i uvezi novu kopiju')) ?></strong>
                                                    <span class="d-block small text-body-secondary"><?= $this->escape(__('Postojeće područje ostaje nepromijenjeno, a arhiva se uvozi u novo područje s drugim nazivom i slugom.')) ?></span>
                                                </span>
                                            </label>
                                        </div>
                                    </div>
                                </fieldset>
                            <?php elseif ($existingImport !== null) : ?>
                                <input type="hidden" name="reimport_strategy" value="replace">
                                <div class="alert alert-info mt-3 mb-0"><?= $this->escape(__('Postojeće osobno područje bit će zamijenjeno novim uvozom.')) ?></div>
                            <?php else : ?>
                                <input type="hidden" name="reimport_strategy" value="new">
                            <?php endif; ?>
                            <?php if (($space['type'] ?? '') === 'personal') : ?>
                                <div class="alert alert-info mt-3 mb-0"><?= $this->escape(__('Osobni Confluence space bit će uvezen u osobno područje potvrđeno mapiranog vlasnika. Naziv i slug iznad služe samo kao pregled izvora.')) ?></div>
                            <?php endif; ?>

                            <fieldset class="mt-4">
                                <legend class="h5"><?= $this->escape(__('Sadržaj importa')) ?></legend>
                                <div class="row g-2">
                                    <div class="col-md-4"><label class="form-check confluence-import-option h-100"><input class="form-check-input" type="checkbox" name="include_attachments" checked><span class="form-check-label"><strong><?= $this->escape(__('Privitci')) ?></strong><span class="d-block small text-body-secondary"><?= $this->escape(__('Uvozi aktualne datoteke svih MIME tipova u privatnu pohranu.')) ?></span></span></label></div>
                                    <div class="col-md-4"><label class="form-check confluence-import-option h-100"><input class="form-check-input" type="checkbox" name="include_comments" checked><span class="form-check-label"><strong><?= $this->escape(__('Komentari')) ?></strong><span class="d-block small text-body-secondary"><?= $this->escape(__('Komentari se uvoze samo kada su autor i ciljna stranica mapirani.')) ?></span></span></label></div>
                                    <div class="col-md-4"><label class="form-check confluence-import-option h-100"><input class="form-check-input" type="checkbox" name="include_history"><span class="form-check-label"><strong><?= $this->escape(__('Povijest stranica')) ?></strong><span class="d-block small text-body-secondary"><?= $this->escape(__('Opcionalno uvozi i ranije objavljene verzije.')) ?></span></span></label></div>
                                    <div class="col-md-4"><label class="form-check confluence-import-option h-100"><input class="form-check-input" type="checkbox" name="include_deleted"><span class="form-check-label"><strong><?= $this->escape(__('Obrisane stranice')) ?></strong><span class="d-block small text-body-secondary"><?= $this->escape(__('Opcionalno ih sprema kao soft-obrisani sadržaj koji administrator može vratiti.')) ?></span></span></label></div>
                                    <div class="col-md-4"><label class="form-check confluence-import-option h-100"><input class="form-check-input" type="checkbox" name="include_drafts"><span class="form-check-label"><strong><?= $this->escape(__('Nacrti')) ?></strong><span class="d-block small text-body-secondary"><?= $this->escape(__('Opcionalno uvozi zadnji Confluence nacrt bez objave.')) ?></span></span></label></div>
                                </div>
                            </fieldset>

                            <details class="confluence-import-mapping mt-4">
                                <summary><?= $this->escape(sprintf(__('Korisnici i identiteti (%d)'), count($sourceUsers))) ?></summary>
                                <div class="confluence-import-body">
                                    <p class="text-body-secondary"><?= $this->escape(__('Nemapirani autori pripisuju se trenutnom administratoru koji pokreće import, dok njihove izvorne ovlasti ostaju nemapirane. Pretražite i odaberite lokalnog korisnika samo kada ste sigurni da predstavlja istu osobu.')) ?></p>
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" role="switch" id="confluence-import-create-unmapped-users">
                                        <label class="form-check-label" for="confluence-import-create-unmapped-users"><?= $this->escape(__('Za sve trenutačno nemapirane identitete izradi neaktivne korisnike')) ?></label>
                                        <div class="form-text"><?= $this->escape(__('Kada je uključeno, izrađeni neaktivni korisnici postaju autori umjesto trenutnog administratora. Računi nemaju mogućnost prijave dok ih administrator ne konfigurira i aktivira.')) ?></div>
                                    </div>
                                    <input class="form-control mb-3" type="search" data-filter-table="identity" placeholder="<?= $this->escape(__('Pretraži Confluence korisnike')) ?>">
                                    <div class="table-responsive confluence-import-table-wrap">
                                        <table class="table table-sm align-middle mb-0" data-filter-target="identity"><thead><tr><th><?= $this->escape(__('Confluence identitet')) ?></th><th><?= $this->escape(__('Ciljni korisnik')) ?></th></tr></thead><tbody>
                                        <?php foreach ($sourceUsers as $sourceUser) : ?>
                                            <?php if (!is_array($sourceUser)) {
                                                continue;
                                            } $sourceKey = (string)($sourceUser['source_key'] ?? '');
                                            $suggested = is_numeric($suggestions[$sourceKey] ?? null) ? (int)$suggestions[$sourceKey] : 0;
                                            $suggestedUser = $targetUsersById[$suggested] ?? null;
                                            $suggestedLabel = is_array($suggestedUser)
                                                ? (string)($suggestedUser['display_name'] ?? $suggestedUser['login_identifier'] ?? $suggested)
                                                : '';
                                            $administratorLabel = sprintf(__('Trenutni administrator (%s)'), $currentActorName); ?>
                                            <tr data-filter-row class="<?= $sourceKey !== '' && $sourceKey === $ownerSourceKey ? 'confluence-import-owner-row' : '' ?>">
                                                <td><strong><?= $this->escape((string)($sourceUser['display_name'] ?? $sourceUser['username'] ?? $sourceKey)) ?></strong><?php if ($sourceKey === $ownerSourceKey) :
                                                    ?> <span class="badge text-bg-warning"><?= $this->escape(__('Vlasnik')) ?></span><?php
                                                            endif; ?><div class="small text-body-secondary"><?= $this->escape((string)($sourceUser['email'] ?? '')) ?></div><div class="confluence-import-source-key"><?= $this->escape($sourceKey) ?></div></td>
                                                <td>
                                                    <div class="confluence-import-user-picker" data-identity-picker>
                                                        <input type="hidden" data-identity-map="<?= $this->escape($sourceKey) ?>" value="<?= $suggested > 0 ? $suggested : '' ?>">
                                                        <button class="form-select form-select-sm text-start" type="button" data-identity-picker-toggle data-current-administrator-label="<?= $this->escape($administratorLabel) ?>" data-create-user-label="<?= $this->escape(__('Izradi neaktivnog korisnika bez prijave')) ?>" aria-expanded="false"><?= $this->escape($suggestedLabel !== '' ? $suggestedLabel : $administratorLabel) ?></button>
                                                        <div class="confluence-import-user-picker-panel shadow" data-identity-picker-panel hidden>
                                                            <button class="list-group-item list-group-item-action" type="button" data-identity-picker-choice="" data-identity-picker-label="<?= $this->escape($administratorLabel) ?>"><?= $this->escape($administratorLabel) ?><span class="d-block small text-body-secondary"><?= $this->escape(__('Samo autorstvo; izvorne ovlasti ostaju nemapirane.')) ?></span></button>
                                                            <button class="list-group-item list-group-item-action" type="button" data-identity-picker-choice="__create_inactive__" data-identity-picker-label="<?= $this->escape(__('Izradi neaktivnog korisnika bez prijave')) ?>"><?= $this->escape(__('Izradi neaktivnog korisnika bez prijave')) ?></button>
                                                            <div class="p-2"><input class="form-control form-control-sm" type="search" autocomplete="off" role="combobox" aria-autocomplete="list" placeholder="<?= $this->escape(__('Upišite najmanje 2 znaka za pretragu korisnika')) ?>" data-identity-picker-search></div>
                                                            <div class="list-group list-group-flush" data-identity-picker-results></div>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody></table>
                                    </div>
                                </div>
                            </details>

                            <details class="confluence-import-mapping mt-3">
                                <summary><?= $this->escape(sprintf(__('Grupe i ACL (%d)'), count($sourceGroups))) ?></summary>
                                <div class="confluence-import-body">
                                    <p class="text-body-secondary"><?= $this->escape(__('Grupu možete povezati s postojećom grupom ili izričito izraditi novu običnu grupu. Članstva i administratorske ovlasti ne prenose se automatski.')) ?></p>
                                    <div class="table-responsive confluence-import-table-wrap">
                                        <table class="table table-sm align-middle mb-0"><thead><tr><th><?= $this->escape(__('Confluence grupa')) ?></th><th><?= $this->escape(__('Ciljna grupa')) ?></th></tr></thead><tbody>
                                        <?php foreach ($sourceGroups as $sourceGroup) : ?>
                                            <?php if (!is_array($sourceGroup)) {
                                                continue;
                                            } $sourceName = (string)($sourceGroup['source_name'] ?? ''); ?>
                                            <?php $suggestedGroupId = is_numeric($groupSuggestions[$sourceName] ?? null) ? (int)$groupSuggestions[$sourceName] : 0; ?>
                                            <tr><td><strong><?= $this->escape($sourceName) ?></strong></td><td><select class="form-select form-select-sm" data-group-map="<?= $this->escape($sourceName) ?>"><option value=""><?= $this->escape(__('Nije mapirano — pristup ostaje blokiran')) ?></option><option value="__create__"><?= $this->escape(__('Izradi novu običnu grupu')) ?></option><?php foreach ($targetGroups as $targetGroup) :
                                                ?><?php if (!is_array($targetGroup) || !is_numeric($targetGroup['id'] ?? null)) {
                                                continue;
                                                } $targetGroupId = (int)$targetGroup['id']; ?><option value="<?= $targetGroupId ?>"<?= $targetGroupId === $suggestedGroupId ? ' selected' : '' ?>><?= $this->escape((string)($targetGroup['group_name'] ?? $targetGroup['group_key'] ?? $targetGroup['id'])) ?></option><?php
                                                            endforeach; ?></select></td></tr>
                                        <?php endforeach; ?>
                                        </tbody></table>
                                    </div>
                                </div>
                            </details>

                            <div class="progress confluence-import-progress mt-4 d-none" id="confluence-import-run-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width:0"></div></div>
                            <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mt-3 confluence-import-actions">
                                <span class="text-body-secondary" id="confluence-import-run-status" aria-live="polite"><?= $this->escape(__('Spremno za import. Izvorna arhiva briše se s poslužitelja tek nakon uspješnog završetka.')) ?></span>
                                <div class="d-flex flex-wrap gap-2">
                                    <button class="btn btn-danger" type="button" data-cancel-job="<?= $this->escape((string)($job['uuid'] ?? '')) ?>"><?= $this->escape(__('Odustani od importa')) ?></button>
                                    <button class="btn btn-primary" type="submit" id="confluence-import-run"><?= $this->escape(__('Uvezi područje')) ?></button>
                                </div>
                            </div>
                        </form>
                        <pre class="alert alert-info confluence-import-result mt-3 d-none" id="confluence-import-result"></pre>
                    </div>
                </details>
            <?php endif; ?>

            </div>
        </section>

        <section class="card mt-3">
            <div class="card-body">
                    <h2 class="h4 mb-1"><?= $this->escape(__('Nedavni Confluence importi')) ?></h2>
                    <p class="text-body-secondary"><?= $this->escape(__('Popis se automatski osvježava i prikazuje trenutačnu fazu dugotrajnog importa.')) ?></p>
                    <p id="confluence-import-jobs-empty"<?= $jobs === [] ? '' : ' class="d-none"' ?>><?= $this->escape(__('Još nema Confluence import poslova.')) ?></p>
                    <div class="table-responsive<?= $jobs === [] ? ' d-none' : '' ?>" id="confluence-import-jobs-wrap"><table class="table align-middle mb-0"><thead><tr><th><?= $this->escape(__('Vrijeme')) ?></th><th><?= $this->escape(__('Arhiva / područje')) ?></th><th><?= $this->escape(__('Stanje')) ?></th><th><?= $this->escape(__('Faza')) ?></th><th><?= $this->escape(__('Radnja')) ?></th></tr></thead><tbody id="confluence-import-jobs-body">
                    <?php foreach ($jobs as $recent) :
                        ?><tr><td><?= $this->escape((string)($recent['created_at_display'] ?? '')) ?></td><td><strong><?= $this->escape((string)($recent['space_name'] ?: $recent['name'] ?? '')) ?></strong><div class="confluence-import-source-key"><?= $this->escape((string)($recent['space_key'] ?? '')) ?></div></td><td><?= $this->escape((string)($recent['status_label'] ?? '')) ?><?php if (($recent['error'] ?? '') !== '') :
    ?><div class="small text-danger"><?= $this->escape((string)$recent['error']) ?></div><?php
                        endif; ?></td><td><?= $this->escape((string)($recent['stage_label'] ?? '')) ?></td><td><div class="d-flex flex-wrap gap-2"><?php if (is_string($recent['mapping_url'] ?? null)) :
    ?><a class="btn btn-sm btn-secondary" href="<?= $this->escape($recent['mapping_url']) ?>" title="<?= $this->escape(__('Otvori mapiranje')) ?>">↗</a><?php
                        elseif (is_string($recent['workspace_url'] ?? null)) :
                            ?><a class="btn btn-sm btn-secondary" href="<?= $this->escape($recent['workspace_url']) ?>" title="<?= $this->escape(__('Otvori područje')) ?>">↗</a><?php
                        endif; ?><?php if (is_string($recent['report_url'] ?? null)) :
    ?><a class="btn btn-sm btn-secondary" href="<?= $this->escape($recent['report_url']) ?>" title="<?= $this->escape(__('Otvori izvještaj importa')) ?>" aria-label="<?= $this->escape(__('Otvori izvještaj importa')) ?>"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v16H4z"/><path d="M8 9h8M8 13h8M8 17h5"/></svg></a><?php
                        endif; ?><?php if (($recent['can_cancel'] ?? false) === true) :
    ?><button class="btn btn-sm btn-danger" type="button" data-cancel-job="<?= $this->escape((string)($recent['uuid'] ?? '')) ?>" title="<?= $this->escape(__('Odustani od importa')) ?>" aria-label="<?= $this->escape(__('Odustani od importa')) ?>">×</button><?php
                        endif; ?></div></td></tr><?php
                    endforeach; ?>
                    </tbody></table></div>
            </div>
        </section>
    </main>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3 confluence-import-toast-container"><div class="toast border-0 confluence-import-toast" id="confluence-import-toast" role="status" aria-live="polite" aria-atomic="true"><div class="toast-header bg-primary text-white" id="confluence-import-toast-header"><strong class="me-auto" id="confluence-import-toast-title"><?= $this->escape(__('Informacija')) ?></strong><button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="<?= $this->escape(__('Zatvori')) ?>"></button></div><div class="toast-body" id="confluence-import-toast-body"></div></div></div>

<script>
(() => {
    'use strict';
    const config = <?= json_encode([
        'settings' => $settingsPath,
        'jobs' => $jobsPath,
        'csrf' => $csrfPath,
        'start' => $uploadStartPath,
        'chunk' => $uploadChunkPath,
        'finish' => $uploadFinishPath,
        'batchStart' => $batchStartPath,
        'cancel' => $cancelPath,
        'run' => $importPath,
        'process' => $processPath,
        'userSearch' => $userSearchPath,
        'batchArchives' => $batchArchiveNames,
        'activeBatchUuid' => is_array($activeBatchJob) ? (string)($activeBatchJob['uuid'] ?? '') : '',
        'activeBatchName' => $activeBatchName,
        'csrfHeader' => 'X-' . str_replace('_', '-', strtoupper($csrfName)),
        'csrfToken' => $csrfToken,
        'chunkSize' => $chunkSize,
        'maxSize' => $maxArchiveSize,
        'initialError' => $initialError,
        'selectFile' => __('Odaberite Confluence XML ZIP arhivu.'),
        'tooLarge' => __('Arhiva prelazi dopuštenu veličinu.'),
        'uploading' => __('Arhiva se prenosi…'),
        'scanning' => __('Prijenos je dovršen. Provjeravam strukturu i sadržaj arhive…'),
        'ready' => __('Arhiva je provjerena. Otvaram mapiranje…'),
        'confirmImport' => __('Pokrenuti potvrđeni Confluence import?'),
        'confirmCancel' => __('Odustati od importa? Prenesena arhiva i podaci pripreme ovog nedovršenog posla bit će trajno obrisani.'),
        'cancelled' => __('Confluence import je otkazan, a prenesena arhiva obrisana.'),
        'importing' => __('Import je u tijeku. Velika područja mogu potrajati nekoliko minuta.'),
        'confirmBatch' => __('Pokrenuti sekvencijalni import svih pronađenih batch arhiva?'),
        'batchRunning' => __('Batch import: {name} ({current} / {total})'),
        'batchFinished' => __('Batch import je dovršen. Uspješno: {success}; neuspjelo: {failed}.'),
        'batchStopped' => __('Batch import je zaustavljen na arhivi {name}. Osvježite stranicu kako biste sigurno nastavili isti posao.'),
        'userSearchEmpty' => __('Nema pronađenih korisnika.'),
        'userSearchFailed' => __('Pretraživanje korisnika nije uspjelo.'),
        'processingAttachments' => __('Uvoz privitaka: {done} / {total}'),
        'processingPages' => __('Uvoz stranica: {done} / {total}'),
        'finalizing' => __('Završavam ovlasti, komentare, poveznice i indeks pretrage…'),
        'failed' => __('Zahtjev nije uspio.'),
        'failedHttp' => __('Poslužitelj je prekinuo zahtjev (HTTP {status}). Pogledajte pogrešku u popisu poslova ili tehničkom logu.'),
        'successTitle' => __('Uspjeh'),
        'errorTitle' => __('Pogreška'),
        'infoTitle' => __('Informacija'),
        'openMapping' => __('Otvori mapiranje'),
        'openWorkspace' => __('Otvori područje'),
        'openReport' => __('Otvori izvještaj importa'),
        'cancelImport' => __('Odustani od importa'),
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const query = (selector) => document.querySelector(selector);
    const uploadStorageKey = 'simbioza.confluenceImport.upload';
    let upload = null;
    try {
        const stored = window.sessionStorage.getItem(uploadStorageKey);
        upload = stored ? JSON.parse(stored) : null;
    } catch (_error) {
        upload = null;
    }

    const rememberUpload = () => {
        if (upload?.uuid) window.sessionStorage.setItem(uploadStorageKey, JSON.stringify(upload));
        else window.sessionStorage.removeItem(uploadStorageKey);
    };

    const responsePayload = async (response) => {
        const text = await response.text();
        const transportError = config.failedHttp.replace('{status}', String(response.status));
        if (text === '') return response.ok ? {} : {error: transportError};
        try { return JSON.parse(text); } catch (_error) {
            return {error: response.ok ? config.failed : transportError};
        }
    };
    const toast = (message, type = 'info') => {
        const element = query('#confluence-import-toast');
        const header = query('#confluence-import-toast-header');
        if (!element || !header) return;
        query('#confluence-import-toast-body').textContent = message;
        header.classList.remove('bg-primary', 'bg-success', 'bg-danger');
        header.classList.add(type === 'danger' ? 'bg-danger' : (type === 'success' ? 'bg-success' : 'bg-primary'));
        query('#confluence-import-toast-title').textContent = type === 'danger' ? config.errorTitle : (type === 'success' ? config.successTitle : config.infoTitle);
        if (window.bootstrap?.Toast) window.bootstrap.Toast.getOrCreateInstance(element, {delay: 7000}).show();
        else { element.classList.add('show'); window.setTimeout(() => element.classList.remove('show'), 7000); }
    };
    let csrfRefreshPromise = null;
    const refreshCsrf = async () => {
        if (csrfRefreshPromise instanceof Promise) return csrfRefreshPromise;
        csrfRefreshPromise = (async () => {
            const response = await fetch(config.csrf, {headers: {Accept: 'application/json'}, cache: 'no-store', credentials: 'same-origin'});
            const data = await responsePayload(response);
            if (!response.ok || typeof data.csrf_token !== 'string' || data.csrf_token === '') throw new Error(data.error || config.failed);
            config.csrfToken = data.csrf_token;
        })();
        try {
            await csrfRefreshPromise;
        } finally {
            csrfRefreshPromise = null;
        }
    };
    const post = async (url, data) => {
        await refreshCsrf();
        const response = await fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json', Accept: 'application/json', [config.csrfHeader]: config.csrfToken}, body: JSON.stringify(data)});
        const payload = await responsePayload(response);
        if (!response.ok) throw new Error(payload.error || config.failed);
        return payload;
    };

    document.querySelectorAll('[data-identity-picker]').forEach((picker) => {
        const toggle = picker.querySelector('[data-identity-picker-toggle]');
        const panel = picker.querySelector('[data-identity-picker-panel]');
        const search = picker.querySelector('[data-identity-picker-search]');
        const results = picker.querySelector('[data-identity-picker-results]');
        const value = picker.querySelector('[data-identity-map]');
        if (!(toggle instanceof HTMLButtonElement) || !(panel instanceof HTMLElement) || !(search instanceof HTMLInputElement) || !(results instanceof HTMLElement) || !(value instanceof HTMLInputElement)) return;

        let timer = 0;
        let controller = null;
        const close = () => { panel.hidden = true; toggle.setAttribute('aria-expanded', 'false'); };
        const positionPanel = () => {
            const padding = 8;
            const gap = 4;
            const rect = toggle.getBoundingClientRect();
            const width = Math.min(480, Math.max(rect.width, window.innerWidth - (padding * 2)));
            const left = Math.max(padding, Math.min(rect.left, window.innerWidth - width - padding));
            panel.style.left = `${left}px`;
            panel.style.width = `${width}px`;
            panel.style.right = 'auto';
            panel.style.maxHeight = `${Math.max(160, Math.min(320, window.innerHeight - (padding * 2)))}px`;
            const height = Math.min(panel.scrollHeight, 320);
            const below = rect.bottom + gap;
            const top = below + height <= window.innerHeight - padding
                ? below
                : Math.max(padding, rect.top - gap - height);
            panel.style.top = `${top}px`;
        };
        const choose = (id, label) => { value.value = String(id || ''); toggle.textContent = String(label || ''); close(); };
        toggle.addEventListener('click', () => {
            document.querySelectorAll('[data-identity-picker-panel]').forEach((other) => { if (other !== panel) other.hidden = true; });
            panel.hidden = !panel.hidden;
            toggle.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
            if (!panel.hidden) {
                positionPanel();
                search.focus();
            }
        });
        panel.addEventListener('click', (event) => {
            const choice = event.target instanceof Element ? event.target.closest('[data-identity-picker-choice]') : null;
            if (!(choice instanceof HTMLButtonElement)) return;
            choose(choice.dataset.identityPickerChoice || '', choice.dataset.identityPickerLabel || choice.textContent || '');
        });
        search.addEventListener('input', () => {
            window.clearTimeout(timer);
            if (controller instanceof AbortController) controller.abort();
            const term = search.value.trim();
            results.replaceChildren();
            if (term.length < 2) return;
            timer = window.setTimeout(async () => {
                controller = new AbortController();
                const url = new URL(config.userSearch, window.location.href);
                url.searchParams.set('type', 'user');
                url.searchParams.set('mode', 'creator');
                url.searchParams.set('q', term);
                try {
                    const response = await fetch(url, {headers: {Accept: 'application/json'}, credentials: 'same-origin', signal: controller.signal});
                    const data = await responsePayload(response);
                    if (!response.ok || data.ok !== true || !Array.isArray(data.results)) throw new Error(config.userSearchFailed);
                    if (data.results.length === 0) {
                        const empty = document.createElement('div'); empty.className = 'list-group-item text-body-secondary'; empty.textContent = config.userSearchEmpty; results.appendChild(empty); return;
                    }
                    data.results.forEach((user) => {
                        const button = document.createElement('button'); button.className = 'list-group-item list-group-item-action'; button.type = 'button'; button.dataset.identityPickerChoice = String(user.id || ''); button.dataset.identityPickerLabel = String(user.label || ''); button.textContent = String(user.label || ''); results.appendChild(button);
                    });
                } catch (error) {
                    if (error instanceof DOMException && error.name === 'AbortError') return;
                    const failed = document.createElement('div'); failed.className = 'list-group-item text-danger'; failed.textContent = config.userSearchFailed; results.replaceChildren(failed);
                }
            }, 180);
        });
        document.addEventListener('click', (event) => { if (event.target instanceof Node && !picker.contains(event.target)) close(); });
    });

    const updateImportProgress = (data, status, progress) => {
        const percent = Math.max(0, Math.min(100, Number(data.progress || 0)));
        progress.style.width = `${percent}%`;
        progress.parentElement?.setAttribute('aria-valuenow', String(percent));
        if (data.phase === 'attachments') return config.processingAttachments.replace('{done}', String(data.attachments_done || 0)).replace('{total}', String(data.attachments_total || 0));
        if (data.phase === 'pages') return config.processingPages.replace('{done}', String(data.pages_done || 0)).replace('{total}', String(data.pages_total || 0));
        if (data.phase === 'finalizing') return config.finalizing;
        return status;
    };

    const finishQueuedImport = async (data, uuid, onProgress) => {
        let current = data;
        while (current.completed !== true) {
            onProgress(current);
            current = await post(config.process, {uuid});
        }
        onProgress(current);
        return current;
    };
    const synchronizeUpload = async (file) => {
        if (!upload?.uuid || upload.name !== file.name || Number(upload.archive_size) !== file.size) {
            upload = null;
            rememberUpload();
            return;
        }
        const response = await fetch(config.jobs, {headers: {Accept: 'application/json'}, cache: 'no-store'});
        const data = await responsePayload(response);
        const current = response.ok && Array.isArray(data.jobs)
            ? data.jobs.find((job) => job.uuid === upload.uuid && job.status === 'uploading')
            : null;
        upload = current ? {...upload, ...current} : null;
        rememberUpload();
    };

    const uploadButton = query('#confluence-import-upload');
    const fileInput = query('#confluence-import-file');
    const fileName = query('#confluence-import-file-name');
    fileInput?.addEventListener('change', () => {
        const selectedFile = fileInput.files?.[0];
        if (fileName) fileName.textContent = selectedFile?.name || <?= json_encode(__('Nije odabrana nijedna datoteka.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    });
    uploadButton?.addEventListener('click', async () => {
        const file = fileInput?.files?.[0];
        const status = query('#confluence-import-upload-status');
        const progress = query('#confluence-import-upload-progress');
        uploadButton.disabled = true;
        try {
            if (!file) throw new Error(config.selectFile);
            if (file.size > config.maxSize) throw new Error(config.tooLarge);
            status.textContent = config.uploading;
            await synchronizeUpload(file);
            if (!upload) {
                upload = await post(config.start, {name: file.name, size: file.size});
                rememberUpload();
            }
            while (upload.next_offset < file.size) {
                const end = Math.min(file.size, upload.next_offset + upload.chunk_size);
                await refreshCsrf();
                const response = await fetch(config.chunk, {method: 'POST', headers: {'Content-Type': 'application/octet-stream', 'X-Confluence-Import-Upload': upload.uuid, 'X-Confluence-Import-Offset': String(upload.next_offset), [config.csrfHeader]: config.csrfToken}, body: file.slice(upload.next_offset, end)});
                const payload = await responsePayload(response);
                if (!response.ok) throw new Error(payload.error || config.failed);
                upload = payload;
                rememberUpload();
                progress.style.width = `${Math.round(upload.next_offset / file.size * 100)}%`;
            }
            status.textContent = config.scanning;
            upload = await post(config.finish, {uuid: upload.uuid});
            window.sessionStorage.removeItem(uploadStorageKey);
            status.textContent = config.ready;
            toast(config.ready, 'success');
            window.location.assign(upload.mapping_url);
        } catch (error) {
            const message = error instanceof Error ? error.message : config.failed;
            status.textContent = message;
            toast(message, 'danger');
            uploadButton.disabled = false;
        }
    });

    query('#confluence-import-batch-start')?.addEventListener('click', async (event) => {
        if (!window.confirm(config.confirmBatch)) return;
        const button = event.currentTarget;
        const status = query('#confluence-import-batch-status');
        const progress = query('#confluence-import-batch-progress');
        const bar = progress?.querySelector('.progress-bar');
        if (!(button instanceof HTMLButtonElement) || !(status instanceof HTMLElement) || !(progress instanceof HTMLElement) || !(bar instanceof HTMLElement)) return;
        button.disabled = true;
        progress.classList.remove('d-none');
        const heartbeat = window.setInterval(() => {
            refreshCsrf().catch(() => {});
        }, 240000);
        let success = 0;
        let failed = 0;
        const files = [...config.batchArchives].filter((name) => name !== config.activeBatchName);
        const queue = config.activeBatchUuid !== ''
            ? [{uuid: config.activeBatchUuid, name: config.activeBatchName}, ...files.map((name) => ({uuid: '', name}))]
            : files.map((name) => ({uuid: '', name}));
        let stoppedName = '';
        try {
            for (let index = 0; index < queue.length; index += 1) {
                const item = queue[index];
                status.textContent = config.batchRunning.replace('{name}', item.name).replace('{current}', String(index + 1)).replace('{total}', String(queue.length));
                try {
                    const createInactiveUsers = query('#confluence-import-batch-create-unmapped-users');
                    const batchPolicy = createInactiveUsers instanceof HTMLInputElement && createInactiveUsers.checked;
                    const started = await post(config.batchStart, item.uuid !== ''
                        ? {uuid: item.uuid, create_inactive_users: batchPolicy}
                        : {name: item.name, create_inactive_users: batchPolicy});
                    config.activeBatchUuid = String(started.uuid || item.uuid || '');
                    config.activeBatchName = item.name;
                    await finishQueuedImport(started, started.uuid, (data) => {
                        status.textContent = `${config.batchRunning.replace('{name}', item.name).replace('{current}', String(index + 1)).replace('{total}', String(queue.length))} — ${updateImportProgress(data, status.textContent, bar)}`;
                    });
                    success += 1;
                    config.activeBatchUuid = '';
                    config.activeBatchName = '';
                    await refreshJobs();
                } catch (error) {
                    failed += 1;
                    stoppedName = item.name;
                    toast(`${item.name}: ${error instanceof Error ? error.message : config.failed}`, 'danger');
                    break;
                }
            }
            status.textContent = stoppedName !== ''
                ? config.batchStopped.replace('{name}', stoppedName)
                : config.batchFinished.replace('{success}', String(success)).replace('{failed}', String(failed));
            toast(status.textContent, failed > 0 ? 'danger' : 'success');
        } finally {
            window.clearInterval(heartbeat);
            progress.classList.add('d-none');
            button.disabled = false;
        }
    });

    query('#confluence-import-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!window.confirm(config.confirmImport)) return;
        const form = event.currentTarget;
        const button = query('#confluence-import-run');
        const status = query('#confluence-import-run-status');
        const progress = query('#confluence-import-run-progress');
        const result = query('#confluence-import-result');
        const payload = {
            uuid: form.elements.uuid.value,
            workspace_name: form.elements.workspace_name.value,
            workspace_slug: form.elements.workspace_slug.value,
            reimport_strategy: form.elements.reimport_strategy?.value || 'new',
            language: form.elements.language.value,
            include_attachments: form.elements.include_attachments.checked,
            include_comments: form.elements.include_comments.checked,
            include_history: form.elements.include_history.checked,
            include_deleted: form.elements.include_deleted.checked,
            include_drafts: form.elements.include_drafts.checked,
            identity_map: {},
            identity_create: {},
            group_map: {},
            group_create: {},
        };
        document.querySelectorAll('[data-identity-map]').forEach((select) => {
            if (select.value === '__create_inactive__') payload.identity_create[select.dataset.identityMap] = true;
            else if (select.value !== '') payload.identity_map[select.dataset.identityMap] = Number(select.value);
        });
        document.querySelectorAll('[data-group-map]').forEach((select) => {
            if (select.value === '__create__') payload.group_create[select.dataset.groupMap] = true;
            else if (select.value !== '') payload.group_map[select.dataset.groupMap] = Number(select.value);
        });
        button.disabled = true;
        progress.classList.remove('d-none');
        status.textContent = config.importing;
        try {
            let data = await post(config.run, payload);
            while (data.completed !== true) {
                const percent = Math.max(0, Math.min(100, Number(data.progress || 0)));
                progress.querySelector('.progress-bar').style.width = `${percent}%`;
                progress.setAttribute('aria-valuenow', String(percent));
                if (data.phase === 'attachments') {
                    status.textContent = config.processingAttachments
                        .replace('{done}', String(data.attachments_done || 0))
                        .replace('{total}', String(data.attachments_total || 0));
                } else if (data.phase === 'pages') {
                    status.textContent = config.processingPages
                        .replace('{done}', String(data.pages_done || 0))
                        .replace('{total}', String(data.pages_total || 0));
                } else if (data.phase === 'finalizing') {
                    status.textContent = config.finalizing;
                }
                data = await post(config.process, {uuid: payload.uuid});
            }
            status.textContent = data.message || config.ready;
            result.classList.remove('d-none');
            result.textContent = JSON.stringify(data.summary || data, null, 2);
            toast(status.textContent, 'success');
            if (typeof data.workspace_url === 'string' && data.workspace_url !== '') {
                const link = document.createElement('a');
                link.className = 'btn btn-secondary ms-2'; link.href = data.workspace_url; link.textContent = config.openWorkspace;
                button.replaceWith(link);
            }
        } catch (error) {
            const message = error instanceof Error ? error.message : config.failed;
            status.textContent = message;
            result.classList.remove('d-none'); result.textContent = message;
            toast(message, 'danger'); button.disabled = false;
        } finally { progress.classList.add('d-none'); }
    });

    const createUnmappedUsers = query('#confluence-import-create-unmapped-users');
    if (createUnmappedUsers instanceof HTMLInputElement) {
        createUnmappedUsers.addEventListener('change', function () {
            document.querySelectorAll('[data-identity-map]').forEach(function (input) {
                if (!(input instanceof HTMLInputElement)) return;
                const toggle = input.closest('[data-identity-picker]')?.querySelector('[data-identity-picker-toggle]');
                if (!(toggle instanceof HTMLButtonElement)) return;
                if (createUnmappedUsers.checked && input.value === '') {
                    input.value = '__create_inactive__';
                    toggle.textContent = toggle.dataset.createUserLabel || '';
                } else if (!createUnmappedUsers.checked && input.value === '__create_inactive__') {
                    input.value = '';
                    toggle.textContent = toggle.dataset.currentAdministratorLabel || '';
                }
            });
        });
    }

    document.querySelectorAll('[data-filter-table]').forEach((input) => input.addEventListener('input', () => {
        const table = document.querySelector(`[data-filter-target="${CSS.escape(input.dataset.filterTable)}"]`);
        const term = input.value.toLocaleLowerCase();
        table?.querySelectorAll('[data-filter-row]').forEach((row) => { row.classList.toggle('d-none', !row.textContent.toLocaleLowerCase().includes(term)); });
    }));

    const renderJobs = (jobs) => {
        const body = query('#confluence-import-jobs-body');
        if (!body) return;
        body.replaceChildren();
        query('#confluence-import-jobs-empty')?.classList.toggle('d-none', jobs.length !== 0);
        query('#confluence-import-jobs-wrap')?.classList.toggle('d-none', jobs.length === 0);
        jobs.forEach((job) => {
            const row = document.createElement('tr');
            const values = [job.created_at_display, job.space_name || job.name, job.status_label, job.stage_label];
            values.forEach((value, index) => { const cell = document.createElement('td'); if (index === 1) { const strong = document.createElement('strong'); strong.textContent = String(value || ''); cell.appendChild(strong); if (job.space_key) { const key = document.createElement('div'); key.className = 'confluence-import-source-key'; key.textContent = String(job.space_key); cell.appendChild(key); } } else { cell.textContent = String(value || ''); } if (index === 2 && job.error) { const error = document.createElement('div'); error.className = 'small text-danger'; error.textContent = String(job.error); cell.appendChild(error); } row.appendChild(cell); });
            const action = document.createElement('td');
            const actions = document.createElement('div');
            actions.className = 'd-flex flex-wrap gap-2';
            const url = job.mapping_url || job.workspace_url;
            if (url) { const link = document.createElement('a'); link.className = 'btn btn-sm btn-secondary'; link.href = url; link.title = job.mapping_url ? config.openMapping : config.openWorkspace; link.textContent = '↗'; actions.appendChild(link); }
            if (job.report_url) { const report = document.createElement('a'); report.className = 'btn btn-sm btn-secondary'; report.href = job.report_url; report.title = config.openReport; report.setAttribute('aria-label', config.openReport); report.innerHTML = '<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v16H4z"></path><path d="M8 9h8M8 13h8M8 17h5"></path></svg>'; actions.appendChild(report); }
            if (job.can_cancel) { const cancel = document.createElement('button'); cancel.className = 'btn btn-sm btn-danger'; cancel.type = 'button'; cancel.dataset.cancelJob = String(job.uuid || ''); cancel.title = config.cancelImport; cancel.setAttribute('aria-label', config.cancelImport); cancel.textContent = '×'; actions.appendChild(cancel); }
            action.appendChild(actions);
            row.appendChild(action); body.appendChild(row);
        });
    };
    const refreshJobs = async () => {
        try { const response = await fetch(config.jobs, {headers: {Accept: 'application/json'}, cache: 'no-store'}); const data = await responsePayload(response); if (response.ok && Array.isArray(data.jobs)) renderJobs(data.jobs); } catch (_error) { /* The next refresh retries. */ }
    };
    document.addEventListener('click', async (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-cancel-job]') : null;
        if (!(button instanceof HTMLButtonElement)) return;
        const uuid = button.dataset.cancelJob || '';
        if (uuid === '' || !window.confirm(config.confirmCancel)) return;

        button.disabled = true;
        try {
            const data = await post(config.cancel, {uuid});
            if (upload?.uuid === uuid) {
                upload = null;
                rememberUpload();
            }
            toast(data.message || config.cancelled, 'success');
            const activeJob = new URL(window.location.href).searchParams.get('job');
            if (activeJob === uuid) {
                window.location.assign(config.settings);
                return;
            }
            await refreshJobs();
        } catch (error) {
            toast(error instanceof Error ? error.message : config.failed, 'danger');
            button.disabled = false;
        }
    });
    window.setInterval(refreshJobs, 5000);
    if (config.initialError) toast(config.initialError, 'danger');
})();
</script>
