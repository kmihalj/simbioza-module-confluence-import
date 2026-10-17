<?php

declare(strict_types=1);

// EN: The host layout owns the main landmark; this view only supplies its content.
//
// HR: Glavni orijentir daje aplikacijski raspored; ovaj je prikaz samo njegov sadržaj.

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
 * @var string $sourceBaseUrl
 * @var list<string> $supportedLanguages
 * @var object|null $menuRenderer
 */

$scan = is_array($preparation['scan'] ?? null) ? $preparation['scan'] : [];
$job = is_array($preparation['job'] ?? null) ? $preparation['job'] : [];
$inventoryOnly = ($scan['inventory_only'] ?? false) === true;
$space = is_array($scan['spaces'][0] ?? null) ? $scan['spaces'][0] : [];
$sourceUsers = is_array($scan['users'] ?? null) ? $scan['users'] : [];
$sourceGroups = is_array($scan['groups'] ?? null) ? $scan['groups'] : [];
$targetUsers = is_array($preparation['target_users'] ?? null) ? $preparation['target_users'] : [];
$targetGroups = is_array($preparation['target_groups'] ?? null) ? $preparation['target_groups'] : [];
$currentActor = is_array($preparation['current_actor'] ?? null)
    ? $preparation['current_actor']
    : $currentAdministrator;
$userPickerLabel = static function (array $user): string {
    $lastName = is_scalar($user['last_name'] ?? null) ? trim((string)$user['last_name']) : '';
    $firstName = is_scalar($user['first_name'] ?? null) ? trim((string)$user['first_name']) : '';
    $surnameFirst = trim($lastName . ' ' . $firstName);

    return $surnameFirst !== ''
        ? $surnameFirst
        : (string)($user['display_name'] ?? $user['login_identifier'] ?? '');
};
$currentActorName = $userPickerLabel($currentActor);
if ($currentActorName === '') {
    $currentActorName = __('Trenutni administrator');
}
$activeBatchOptions = is_array($activeBatchJob['options'] ?? null) ? $activeBatchJob['options'] : [];
$batchCreateInactiveUsers = ($activeBatchOptions['batch_create_inactive_users'] ?? false) === true;
$batchPolicyLocked = ($activeBatchJob['status'] ?? '') === 'running';
$batchSourceBaseUrl = is_scalar($activeBatchOptions['source_base_url'] ?? null)
    ? (string)$activeBatchOptions['source_base_url']
    : $sourceBaseUrl;
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

    <div class="col-lg-9 confluence-import-shell">
        <section class="card">
            <div class="card-body">
                <header class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
                <div>
                    <h1 class="h3 mb-1"><?= $this->escape($title) ?></h1>
                    <p class="text-body-secondary mb-0"><?= $this->escape(__('Uvezite jedno Confluence područje ili odabrana područja iz izvoza cijelog sitea.')) ?></p>
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
                        <?php // EN: One label and visible focus retain the localized native picker.
//
// HR: Jedna oznaka i vidljiv fokus zadržavaju lokalizirani nativni birač.?>
                        <div class="form-label" id="confluence-import-file-label"><?= $this->escape(__('Confluence XML ZIP arhiva')) ?></div>
                        <div class="input-group confluence-import-file-picker">
                            <input class="visually-hidden" id="confluence-import-file" type="file" accept=".zip,application/zip" aria-labelledby="confluence-import-file-label confluence-import-file-choice" aria-describedby="confluence-import-file-name confluence-import-file-help">
                            <label class="btn btn-outline-primary" id="confluence-import-file-choice" for="confluence-import-file"><?= $this->escape(__('Odaberi datoteku')) ?></label>
                            <span class="form-control text-body-secondary" id="confluence-import-file-name"><?= $this->escape(__('Nije odabrana nijedna datoteka.')) ?></span>
                        </div>
                        <div class="form-text" id="confluence-import-file-help"><?= $this->escape(__('Vrsta izvoza prepoznaje se iz arhive. Uvoz jednog područja ostaje isti; site i više područja imaju zaseban pregled identiteta i potvrdu odabranog sadržaja. Velika datoteka šalje se u nastavivim dijelovima.')) ?></div>
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
                        <div class="mb-3">
                            <label class="form-label" for="confluence-import-batch-source-base-url"><?= $this->escape(__('Osnovni URL izvornog Confluencea')) ?></label>
                            <input class="form-control" id="confluence-import-batch-source-base-url" type="url" value="<?= $this->escape($batchSourceBaseUrl) ?>" placeholder="https://wiki.example.org" required<?= $batchPolicyLocked ? ' disabled' : '' ?>>
                            <div class="form-text"><?= $this->escape(__('Samo apsolutne poveznice s odabranog izvornog hosta pokušavaju se lokalno razriješiti i prikazuju u izvještaju. Relativne Confluence poveznice obrađuju se kao interne.')) ?></div>
                        </div>
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
                        <div class="progress confluence-import-progress mb-2 d-none" id="confluence-import-batch-progress" role="progressbar" aria-label="<?= $this->escape(__('Batch import arhiva s poslužitelja')) ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width:0"></div></div>
                        <div class="d-flex flex-wrap gap-2 align-items-center confluence-import-actions">
                            <button class="btn btn-primary" type="button" id="confluence-import-batch-start"><?= $this->escape(is_array($activeBatchJob) ? __('Nastavi batch import') : __('Uvezi sve batch arhive')) ?></button>
                            <span class="text-body-secondary" id="confluence-import-batch-status" aria-live="polite"></span>
                        </div>
                    </div>
                </details>
            <?php endif; ?>

            <?php if ($inventoryOnly) : ?>
                <?php if (($job['status'] ?? '') === 'ready') {
                    $siteSetupOpen = in_array($siteWorkflowState['phase'] ?? 'draft', ['draft', 'review'], true);
                    if ($siteSetupOpen) {
                        require __DIR__ . '/site-inventory.php';
                    }
                } ?>
                <?php if (($siteSetupOpen ?? false) && is_array($siteAccessState ?? null)) {
                    require __DIR__ . '/site-access.php';
                } ?>
                <?php if (($siteSetupOpen ?? false) && is_array($siteContentState ?? null)) {
                    require __DIR__ . '/site-content.php';
                } ?>
                <?php if (is_array($siteCalendarState ?? null) && (($siteSetupOpen ?? false) || ($siteCalendarState['owner_suggestions'] ?? []) !== [])) {
                    require __DIR__ . '/site-calendars.php';
                } ?>
                <?php if (is_array($siteWorkflowState ?? null)) {
                    require __DIR__ . '/site-workflow.php';
                } ?>
                <?php if (is_array($siteOverview ?? null)) : ?>
                 <section class="confluence-import-panel mt-3"><div class="confluence-import-body">
                    <?php require __DIR__ . '/site-report-overview.php'; ?>
                  <a class="btn btn-outline-primary" href="<?= $this->escape($settingsPath . '/report/' . rawurlencode((string)$job['uuid'])) ?>"><?= $this->escape(__('Otvori zajednički izvještaj')) ?></a>
                    <?php if ($siteOverview['status'] === 'completed' && $siteOverview['source_retained'] && ($siteCalendarState['plan']['phase'] ?? '') !== 'running') : ?>
                   <button class="btn btn-outline-danger" type="button" id="site-source-cleanup" data-uuid="<?= $this->escape((string)$job['uuid']) ?>"><?= $this->escape(__('Ukloni radnu kopiju arhive i indekse')) ?></button>
                   <div id="site-source-cleanup-status" role="status"></div>
                    <?php endif; ?>
                 </div></section>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($preparation !== null && !$inventoryOnly) : ?>
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
                                <div class="col-12">
                                    <label class="form-label" for="confluence-import-source-base-url"><?= $this->escape(__('Osnovni URL izvornog Confluencea')) ?></label>
                                    <input class="form-control" id="confluence-import-source-base-url" name="source_base_url" type="url" value="<?= $this->escape($sourceBaseUrl) ?>" placeholder="https://wiki.example.org" required>
                                    <div class="form-text"><?= $this->escape(__('Samo apsolutne poveznice s odabranog izvornog hosta pokušavaju se lokalno razriješiti i prikazuju u izvještaju. Relativne Confluence poveznice obrađuju se kao interne.')) ?></div>
                                </div>
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
                                    <div class="col-md-4"><label class="form-check confluence-import-option h-100"><input class="form-check-input" type="checkbox" name="include_history"><span class="form-check-label"><strong><?= $this->escape(__('Povijest stranica i privitaka')) ?></strong><span class="d-block small text-body-secondary"><?= $this->escape(__('Ranije verzije stranica i privitaka uvoze se samo kada je ova opcija uključena.')) ?></span></span></label></div>
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
                                    <input class="form-control mb-3" type="search" data-filter-table="identity" aria-label="<?= $this->escape(__('Pretraži Confluence korisnike')) ?>" placeholder="<?= $this->escape(__('Pretraži Confluence korisnike')) ?>">
                                    <div class="table-responsive confluence-import-table-wrap">
                                        <table class="table table-sm align-middle mb-0" data-filter-target="identity"><thead><tr><th><?= $this->escape(__('Confluence identitet')) ?></th><th><?= $this->escape(__('Ciljni korisnik')) ?></th></tr></thead><tbody>
                                        <?php foreach ($sourceUsers as $sourceUser) : ?>
                                            <?php if (!is_array($sourceUser)) {
                                                continue;
                                            } $sourceKey = (string)($sourceUser['source_key'] ?? '');
                                            $suggested = is_numeric($suggestions[$sourceKey] ?? null) ? (int)$suggestions[$sourceKey] : 0;
                                            $suggestedUser = $targetUsersById[$suggested] ?? null;
                                            $suggestedLabel = is_array($suggestedUser)
                                                ? $userPickerLabel($suggestedUser)
                                                : '';
                                            $administratorLabel = sprintf(__('Trenutni administrator (%s)'), $currentActorName);
                                            $pickerId = 'import-user-' . hash('sha256', $sourceKey);
                                            $sourceLabel = (string)($sourceUser['display_name'] ?? $sourceUser['username'] ?? $sourceKey); ?>
                                            <tr data-filter-row class="<?= $sourceKey !== '' && $sourceKey === $ownerSourceKey ? 'confluence-import-owner-row' : '' ?>">
                                                <td><strong><?= $this->escape((string)($sourceUser['display_name'] ?? $sourceUser['username'] ?? $sourceKey)) ?></strong><?php if ($sourceKey === $ownerSourceKey) :
                                                    ?> <span class="badge text-bg-warning"><?= $this->escape(__('Vlasnik')) ?></span><?php
                                                            endif; ?><div class="small text-body-secondary"><?= $this->escape((string)($sourceUser['email'] ?? '')) ?></div><div class="confluence-import-source-key"><?= $this->escape($sourceKey) ?></div></td>
                                                <td>
                                                    <div class="confluence-import-user-picker" data-identity-picker>
                                                        <input type="hidden" data-identity-map="<?= $this->escape($sourceKey) ?>" value="<?= $suggested > 0 ? $suggested : '' ?>">
                                                        <span id="<?= $pickerId ?>-label" class="visually-hidden"><?= $this->escape(__('Ciljni korisnik') . ': ' . $sourceLabel) ?></span>
                                                        <button id="<?= $pickerId ?>-toggle" class="form-select form-select-sm text-start" type="button" data-identity-picker-toggle data-current-administrator-label="<?= $this->escape($administratorLabel) ?>" data-create-user-label="<?= $this->escape(__('Izradi neaktivnog korisnika bez prijave')) ?>" aria-labelledby="<?= $pickerId ?>-label <?= $pickerId ?>-toggle" aria-controls="<?= $pickerId ?>-panel" aria-expanded="false"><?= $this->escape($suggestedLabel !== '' ? $suggestedLabel : $administratorLabel) ?></button>
                                                        <div id="<?= $pickerId ?>-panel" class="confluence-import-user-picker-panel shadow" data-identity-picker-panel popover="manual" hidden>
                                                            <button class="list-group-item list-group-item-action" type="button" data-identity-picker-choice="" data-identity-picker-label="<?= $this->escape($administratorLabel) ?>"><?= $this->escape($administratorLabel) ?><span class="d-block small text-body-secondary"><?= $this->escape(__('Samo autorstvo; izvorne ovlasti ostaju nemapirane.')) ?></span></button>
                                                            <button class="list-group-item list-group-item-action" type="button" data-identity-picker-choice="__create_inactive__" data-identity-picker-label="<?= $this->escape(__('Izradi neaktivnog korisnika bez prijave')) ?>"><?= $this->escape(__('Izradi neaktivnog korisnika bez prijave')) ?></button>
                                                            <div class="p-2"><input class="form-control form-control-sm" type="search" autocomplete="off" aria-label="<?= $this->escape(__('Pretraži korisnike')) ?>" placeholder="<?= $this->escape(__('Pretraži korisnike')) ?>" data-identity-picker-search></div>
                                                            <div class="small px-2" data-identity-picker-status role="status" aria-atomic="true"></div>
                                                            <div class="list-group list-group-flush" data-identity-picker-results></div>
                                                            <div class="p-2 pt-0"><button class="btn btn-sm btn-outline-secondary" type="button" data-identity-picker-more hidden><?= $this->escape(__('Učitaj još')) ?></button></div>
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
                                            <tr><td><strong><?= $this->escape($sourceName) ?></strong></td><td><select class="form-select form-select-sm" aria-label="<?= $this->escape(__('Ciljna grupa') . ': ' . $sourceName) ?>" data-group-map="<?= $this->escape($sourceName) ?>"><option value=""><?= $this->escape(__('Nije mapirano — pristup ostaje blokiran')) ?></option><option value="__create__"><?= $this->escape(__('Izradi novu običnu grupu')) ?></option><?php foreach ($targetGroups as $targetGroup) :
                                                ?><?php if (!is_array($targetGroup) || !is_numeric($targetGroup['id'] ?? null)) {
                                                continue;
                                                } $targetGroupId = (int)$targetGroup['id']; ?><option value="<?= $targetGroupId ?>"<?= $targetGroupId === $suggestedGroupId ? ' selected' : '' ?>><?= $this->escape((string)($targetGroup['group_name'] ?? $targetGroup['group_key'] ?? $targetGroup['id'])) ?></option><?php
                                                            endforeach; ?></select></td></tr>
                                        <?php endforeach; ?>
                                        </tbody></table>
                                    </div>
                                </div>
                            </details>

                            <div class="progress confluence-import-progress mt-4 d-none" id="confluence-import-run-progress" role="progressbar" aria-label="<?= $this->escape(__('2. Pregledaj mapiranja i pokreni import')) ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width:0"></div></div>
                            <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mt-3 confluence-import-actions">
                                <span class="text-body-secondary" id="confluence-import-run-status" aria-live="polite"><?= $this->escape(__('Spremno za import. Izvorna arhiva briše se s poslužitelja tek nakon uspješnog završetka.')) ?></span>
                                <div class="d-flex flex-wrap gap-2">
                                    <button class="btn btn-danger" type="button" data-cancel-job="<?= $this->escape((string)($job['uuid'] ?? '')) ?>"><?= $this->escape(__('Odustani od importa')) ?></button>
                                    <button class="btn btn-primary" type="submit" id="confluence-import-run"><?= $this->escape(__('Uvezi područje')) ?></button>
                                </div>
                            </div>
                        </form>
                        <div class="confluence-import-result mt-3 d-none" id="confluence-import-result" role="status"></div>
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
                        endif; ?><?php if (($recent['can_resume'] ?? false) === true) :
    ?><button class="btn btn-sm btn-primary" type="button" data-resume-job="<?= $this->escape((string)($recent['uuid'] ?? '')) ?>"><?= $this->escape(__('Nastavi import')) ?></button><?php
                        endif; ?><?php if (($recent['can_cancel'] ?? false) === true) :
    ?><button class="btn btn-sm btn-danger" type="button" data-cancel-job="<?= $this->escape((string)($recent['uuid'] ?? '')) ?>" title="<?= $this->escape(__('Odustani od importa')) ?>" aria-label="<?= $this->escape(__('Odustani od importa')) ?>">×</button><?php
                        endif; ?></div></td></tr><?php
                    endforeach; ?>
                    </tbody></table></div>
            </div>
        </section>
    </div>
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
        'importFinished' => __('Confluence import je dovršen.'),
        'confirmBatch' => __('Pokrenuti sekvencijalni import svih pronađenih batch arhiva?'),
        'batchRunning' => __('Batch import: {name} ({current} / {total})'),
        'batchFinished' => __('Batch import je dovršen. Uspješno: {success}; neuspjelo: {failed}.'),
        'batchStopped' => __('Batch import je zaustavljen na arhivi {name}. Osvježite stranicu kako biste sigurno nastavili isti posao.'),
        'userSearchEmpty' => __('Nema pronađenih korisnika.'),
        'userSearchLoading' => __('Učitavanje...'),
        'userSearchCount' => __('Pronađeno rezultata: %d'),
        'userSearchFailed' => __('Pretraživanje korisnika nije uspjelo.'),
        'userSearchMore' => __('Učitaj još'),
        'processingAttachments' => __('Uvoz privitaka: {done} / {total}'),
        'processingPages' => __('Uvoz stranica: {done} / {total}'),
        'finalizing' => __('Završavam ovlasti, komentare, poveznice i indeks pretrage…'),
        'reconnecting' => __('Veza je prekinuta; provjeravam i nastavljam isti import…'),
        'stillRunning' => __('Import je i dalje u tijeku. Nastavite ga iz popisa poslova; nemojte pokretati novi import.'),
        'failed' => __('Zahtjev nije uspio.'),
        'failedHttp' => __('Poslužitelj je prekinuo zahtjev (HTTP {status}). Pogledajte pogrešku u popisu poslova ili tehničkom logu.'),
        'successTitle' => __('Uspjeh'),
        'errorTitle' => __('Pogreška'),
        'infoTitle' => __('Informacija'),
        'openMapping' => __('Otvori mapiranje'),
        'openWorkspace' => __('Otvori područje'),
        'openReport' => __('Otvori izvještaj importa'),
        'resumeImport' => __('Nastavi import'),
        'cancelImport' => __('Odustani od importa'),
        'pagesLabel' => __('Stranice'),
        'currentAttachments' => __('Aktualni privitci'),
        'attachmentHistory' => __('Povijest privitaka'),
        'currentAttachmentsOk' => __('Svi aktualni privitci uspješno su preneseni.'),
        'currentAttachmentsFailed' => __('Nisu preneseni svi aktualni privitci.'),
        'historicalAttachmentsFailed' => __('Neke ranije verzije privitaka nisu prenesene. To ne znači da nedostaju aktualne datoteke.'),
        'technicalDetails' => __('Tehnički detalji'),
        'versionLabel' => __('Verzija'),
        'inventorySelection' => $inventorySelectionPath ?? '',
        'siteAccess' => $siteAccessPath ?? '',
        'siteContent' => $siteContentPath ?? '',
        'siteCalendars' => $siteCalendarPath ?? '',
        'calendarPreparationPhases' => ['verify' => __('Provjera izvorne arhive…'), 'scan' => __('Čitanje kalendara iz arhive…'),
            'partition' => __('Raspoređivanje događaja po kalendarima…'), 'finalize' => __('Dovršavanje pregleda kalendara…')],
        'calendarPreparationRecords' => __('Obrađeni zapisi: {done}'),
        'calendarPreparationEvents' => __('Raspoređeni događaji: {done} / {total} ({percent} %)'),
        'calendarPreparationElapsed' => __('Proteklo vrijeme: {seconds} s'),
        'calendarPreparationConnection' => __('Provjera napretka trenutačno nije dostupna. Pokušavam ponovno…'),
        'calendarPreparationStopped' => __('Priprema pregleda nije dovršena. Ponovno pokrenite pripremu.'),
        'siteWorkflow' => $siteWorkflowPath ?? '',
        'siteWorkflowWorking' => __('Obrada je u tijeku…'),
        'siteWorkflowStopped' => __('Zaustavljeno'),
        'siteWorkflowPhases' => ['identities' => __('Korisnici, grupe i članstva sitea'), 'calendar_prepare' => __('Priprema kalendara'),
            'calendars' => __('Kalendari'), 'content_prepare' => __('Priprema područja'), 'content' => __('Područja'), 'links' => __('Povezivanje sadržaja'), 'completed' => __('Dovršeno')],
        'siteCleanup' => $siteCleanupPath ?? '',
        'confirmSiteCleanup' => __('Ukloniti radnu kopiju arhive i indekse? Uvezeni sadržaj i izvještaji ostaju, ali novi uvoz iz te kopije više nije moguć. Izvornu arhivu zadržite izvan aplikacije.'),
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const query = (selector) => document.querySelector(selector);
    <?php require __DIR__ . '/site-table-pagination-script.php'; ?>
    // HR: Odvojeni sažetak ne predstavlja povijesne greške kao nestale aktualne datoteke.
    // EN: A separate summary does not present historical failures as missing current files.
    const renderImportResult = (result, summary) => {
        result.replaceChildren();
        const counts = document.createElement('p');
        counts.textContent = config.pagesLabel + ': ' + Number(summary.pages_imported || 0);
        result.append(counts);
        const report = summary.attachment_report;
        if (report && typeof report === 'object') {
            for (const kind of ['current', 'historical']) {
                const total = Number(report[kind + '_total'] || 0);
                if (total === 0) continue;
                const imported = Number(report[kind + '_imported'] || 0);
                const failed = Number(report[kind + '_failed'] || 0);
                const section = document.createElement('section');
                section.dataset.attachmentReport = kind;
                section.className = 'alert alert-' + (failed > 0 ? (kind === 'current' ? 'danger' : 'warning') : 'info');
                const heading = document.createElement('h3');
                heading.className = 'h6';
                heading.textContent = (kind === 'current' ? config.currentAttachments : config.attachmentHistory)
                    + ': ' + imported + ' / ' + total;
                section.append(heading);
                const message = document.createElement('p');
                message.className = 'mb-0';
                if (failed > 0) {
                    message.textContent = kind === 'current' ? config.currentAttachmentsFailed : config.historicalAttachmentsFailed;
                } else if (kind === 'current' && imported === total) {
                    section.className = 'alert alert-success';
                    message.textContent = config.currentAttachmentsOk;
                }
                section.append(message);
                if (Array.isArray(report[kind + '_failures']) && failed > 0) {
                    const list = document.createElement('ul');
                    list.className = 'mb-0 mt-2';
                    for (const failure of report[kind + '_failures']) {
                        const item = document.createElement('li');
                        item.className = 'text-break';
                        item.textContent = String(failure.name || '') + ' — ' + config.versionLabel + ' '
                            + Number(failure.version || 1) + ': ' + String(failure.error || '');
                        list.append(item);
                    }
                    section.append(list);
                }
                result.append(section);
            }
        }
        const details = document.createElement('details');
        const label = document.createElement('summary');
        label.textContent = config.technicalDetails;
        const data = document.createElement('pre');
        data.className = 'small mt-2';
        data.textContent = JSON.stringify(summary, null, 2);
        details.append(label, data);
        result.append(details);
    };
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
    let toastReturnFocus = null;
    // HR: Zatvaranje poruke vraća fokus na prethodnu kontrolu, ne na tijelo stranice.
    // EN: Dismissing a message returns focus to the previous control, not the page body.
    document.addEventListener('focusin', (event) => {
        if (event.target instanceof HTMLElement && !query('#confluence-import-toast')?.contains(event.target)) {
            toastReturnFocus = event.target;
        }
    });
    query('#confluence-import-toast [data-bs-dismiss]')?.addEventListener('click', () => {
        if (query('#confluence-import-toast').contains(document.activeElement)) {
            if (toastReturnFocus?.isConnected && !toastReturnFocus.disabled) toastReturnFocus.focus();
            else {
                const heading = document.querySelector('h1');
                heading?.setAttribute('tabindex', '-1');
                heading?.focus();
            }
        }
        if (!window.bootstrap?.Toast) query('#confluence-import-toast').classList.remove('show');
    });
    const toast = (message, type = 'info') => {
        const element = query('#confluence-import-toast');
        const header = query('#confluence-import-toast-header');
        if (!element || !header) return;
        query('#confluence-import-toast-body').textContent = message;
        header.classList.remove('bg-primary', 'bg-success', 'bg-danger');
        header.classList.add(type === 'danger' ? 'bg-danger' : (type === 'success' ? 'bg-success' : 'bg-primary'));
        query('#confluence-import-toast-title').textContent = type === 'danger' ? config.errorTitle : (type === 'success' ? config.successTitle : config.infoTitle);
        if (window.bootstrap?.Toast) window.bootstrap.Toast.getOrCreateInstance(element, {autohide: false}).show();
        else element.classList.add('show');
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
        if (!response.ok) {
            const error = new Error(payload.error || config.failed);
            error.httpStatus = response.status;
            throw error;
        }
        return payload;
    };

    document.querySelectorAll('[data-identity-picker]').forEach((picker) => {
        const toggle = picker.querySelector('[data-identity-picker-toggle]');
        const panel = picker.querySelector('[data-identity-picker-panel]');
        const search = picker.querySelector('[data-identity-picker-search]');
        const results = picker.querySelector('[data-identity-picker-results]');
        const loadMore = picker.querySelector('[data-identity-picker-more]');
        const value = picker.querySelector('[data-identity-map]');
        const status = picker.querySelector('[data-identity-picker-status]');
        if (!(toggle instanceof HTMLButtonElement) || !(panel instanceof HTMLElement) || !(search instanceof HTMLInputElement) || !(results instanceof HTMLElement) || !(loadMore instanceof HTMLButtonElement) || !(value instanceof HTMLInputElement)) return;

        let timer = 0;
        let controller = null;
        let page = 0;
        let hasMore = false;
        let loaded = false;
        let sequence = 0;
        // HR: Zatvaranje poništava zakašnjele odgovore i po potrebi vraća fokus.
        // EN: Closing invalidates late responses and restores focus when needed.
        const close = (restoreFocus = false) => {
            window.clearTimeout(timer);
            sequence++;
            controller?.abort();
            if (restoreFocus && !panel.hidden) toggle.focus();
            if (typeof panel.hidePopover === 'function') panel.hidePopover();
            panel.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
            results.removeAttribute('aria-busy');
            loadMore.removeAttribute('aria-disabled');
        };
        picker.addEventListener('identity-picker-close', () => close(panel.contains(document.activeElement)));
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
        // EN: Keep the chosen account visible and expose exactly one pressed choice, including after search/reopening.
        // Hidden fields must emit change so dependent previews are invalidated for mouse and keyboard selection alike.
        //
        // HR: Odabrani račun ostaje vidljiv; samo jedna stavka označena je odabranom, i nakon pretrage/ponovnog otvaranja.
        // Skrivena polja šalju change kako bi odabir mišem ili tipkovnicom poništio ovisni pregled.
        const markChoices = () => {
            panel.querySelectorAll('[data-identity-picker-choice]').forEach((choice) => {
                const selected = choice.dataset.identityPickerChoice === value.value;
                choice.classList.toggle('active', selected);
                choice.setAttribute('aria-pressed', String(selected));
            });
        };
        const choose = (id, label) => {
            value.value = String(id || '');
            value.dataset.pickerLabel = value.value !== '' ? String(label || '') : '';
            if (value.dataset.siteKind === 'users') value.dataset.siteManual = 'true';
            if (value.hasAttribute('data-calendar-owner') && value.value !== '') {
                const row = picker.closest('[data-calendar-row]');
                row.querySelector('[data-calendar-type]').value = 'personal';
                row.dataset.calendarPersonal = '1';
            }
            const reason = picker.parentElement?.querySelector('[data-site-user-reason]');
            if (reason) reason.textContent = reason.dataset.manualLabel || '';
            toggle.textContent = String(label || '');
            markChoices();
            value.dispatchEvent(new Event('change', {bubbles: true}));
            close(true);
        };
        picker.addEventListener('identity-picker-select', (event) => choose(event.detail.id, event.detail.label));
        const loadPage = async (requestedPage, append = false) => {
            if (controller instanceof AbortController) controller.abort();
            controller = new AbortController();
            const requestSequence = ++sequence;
            loadMore.setAttribute('aria-disabled', 'true');
            results.setAttribute('aria-busy', 'true');
            status.textContent = config.userSearchLoading;
            const url = new URL(picker.dataset.searchUrl || config.userSearch, window.location.href);
            url.searchParams.set('type', 'user');
            url.searchParams.set('mode', 'creator');
            url.searchParams.set('q', search.value.trim());
            url.searchParams.set('page', String(requestedPage));
            url.searchParams.set('per_page', '25');
            try {
                const response = await fetch(url, {headers: {Accept: 'application/json'}, credentials: 'same-origin', signal: controller.signal});
                const data = await responsePayload(response);
                if (requestSequence !== sequence || panel.hidden) return;
                if (!response.ok || data.ok !== true || !Array.isArray(data.results)) throw new Error(config.userSearchFailed);
                if (!append) results.replaceChildren();
                if (data.results.length === 0 && !append) {
                    const empty = document.createElement('div'); empty.className = 'list-group-item text-body-secondary'; empty.textContent = config.userSearchEmpty; results.appendChild(empty);
                } else {
                    data.results.forEach((user) => {
                        const button = document.createElement('button'); button.className = 'list-group-item list-group-item-action'; button.type = 'button'; button.dataset.identityPickerChoice = String(user.id || ''); button.dataset.identityPickerLabel = String(user.label || ''); button.textContent = String(user.label || ''); results.appendChild(button);
                    });
                }
                page = Number(data.page || requestedPage);
                hasMore = data.hasMore === true;
                if (append && document.activeElement === loadMore) {
                    const firstNew = results.querySelectorAll('button')[results.querySelectorAll('button').length - data.results.length];
                    (firstNew || search).focus();
                }
                loadMore.hidden = !hasMore;
                status.textContent = results.querySelectorAll('button').length === 0 ? config.userSearchEmpty
                    : config.userSearchCount.replace('%d', String(results.querySelectorAll('button').length));
                loaded = true;
                markChoices();
                positionPanel();
            } catch (error) {
                if (requestSequence !== sequence || panel.hidden || (error instanceof DOMException && error.name === 'AbortError')) return;
                const failed = document.createElement('div'); failed.className = 'list-group-item text-danger'; failed.textContent = config.userSearchFailed;
                if (!append) results.replaceChildren(failed); else results.appendChild(failed);
                hasMore = false;
                if (document.activeElement === loadMore) search.focus();
                loadMore.hidden = true;
                status.textContent = config.userSearchFailed;
            } finally {
                if (requestSequence === sequence) {
                    loadMore.removeAttribute('aria-disabled');
                    results.removeAttribute('aria-busy');
                }
            }
        };
        toggle.addEventListener('click', () => {
            document.querySelectorAll('[data-identity-picker]').forEach((other) => { if (other !== picker) other.dispatchEvent(new Event('identity-picker-close')); });
            if (!panel.hidden) { close(); return; }
            panel.hidden = false;
            // EN: The native top layer escapes sticky headers and table/card stacking contexts without reparenting the form.
            //
            // HR: Nativni gornji sloj izbjegava fiksna zaglavlja i slojeve tablice/kartice bez premještanja obrasca u DOM-u.
            if (typeof panel.showPopover === 'function') panel.showPopover();
            toggle.setAttribute('aria-expanded', 'true');
            markChoices();
            if (!panel.hidden) {
                positionPanel();
                search.focus();
                if (!loaded) void loadPage(1);
            }
        });
        // EN: A mouse press must not blur the search input and hide the panel before its click selects a result.
        //
        // HR: Pritisak miša ne smije maknuti fokus iz pretrage i sakriti panel prije klika koji odabire rezultat.
        panel.addEventListener('mousedown', (event) => {
            if (event.button === 0 && event.target instanceof Element && event.target.closest('[data-identity-picker-choice]')) event.preventDefault();
        });
        panel.addEventListener('click', (event) => {
            const choice = event.target instanceof Element ? event.target.closest('[data-identity-picker-choice]') : null;
            if (!(choice instanceof HTMLButtonElement)) return;
            choose(choice.dataset.identityPickerChoice || '', choice.dataset.identityPickerLabel || choice.textContent || '');
        });
        search.addEventListener('input', () => {
            window.clearTimeout(timer);
            sequence++;
            controller?.abort();
            loaded = false;
            results.replaceChildren();
            loadMore.hidden = true;
            timer = window.setTimeout(() => { void loadPage(1); }, 180);
        });
        loadMore.addEventListener('click', () => {
            if (hasMore && loadMore.getAttribute('aria-disabled') !== 'true') void loadPage(page + 1, true);
        });
        picker.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !panel.hidden) { event.preventDefault(); event.stopPropagation(); close(true); }
        });
        picker.addEventListener('focusout', (event) => { if (!picker.contains(event.relatedTarget)) close(); });
        document.addEventListener('click', (event) => { if (event.target instanceof Node && !picker.contains(event.target)) close(); });
    });

    const updateImportProgress = (data, status, progress) => {
        const percent = Math.max(0, Math.min(100, Number(data.progress || 0)));
        progress.style.width = `${percent}%`;
        progress.parentElement?.setAttribute('aria-valuenow', String(percent));
        if (data.reconnecting === true) return config.reconnecting;
        if (data.phase === 'attachments') return config.processingAttachments.replace('{done}', String(data.attachments_done || 0)).replace('{total}', String(data.attachments_total || 0));
        if (data.phase === 'pages') return config.processingPages.replace('{done}', String(data.pages_done || 0)).replace('{total}', String(data.pages_total || 0));
        if (data.phase === 'finalizing') return config.finalizing;
        return status;
    };

    const waitForImport = (milliseconds) => new Promise((resolve) => window.setTimeout(resolve, milliseconds));
    const retryableImportError = (error) => error instanceof TypeError || [502, 503, 504].includes(Number(error?.httpStatus || 0));
    const currentImportJob = async (uuid) => {
        const response = await fetch(config.jobs, {headers: {Accept: 'application/json'}, cache: 'no-store', credentials: 'same-origin'});
        const data = await responsePayload(response);
        if (!response.ok || !Array.isArray(data.jobs)) throw new Error(data.error || config.failed);
        return data.jobs.find((job) => job.uuid === uuid) || null;
    };
    // HR: Nakon proxy prekida nastavljamo samo isti posao. Poslužitelj
    //     zaključavanjem sprječava da dva zahtjeva obrade isti korak.
    // EN: After a proxy timeout, resume only the same job. The server lock
    //     prevents two requests from processing the same step.
    const processImportStep = async (uuid, onReconnect = () => {}) => {
        let retries = 0;
        while (true) {
            try {
                const data = await post(config.process, {uuid});
                if (data.busy === true) { await waitForImport(1500); continue; }
                return data;
            } catch (error) {
                if (!retryableImportError(error)) throw error;
                onReconnect();
                retries++;
                await waitForImport(Math.min(5000, 1500 * retries));
                let job = null;
                try { job = await currentImportJob(uuid); } catch (_checkError) { /* Try again after a network interruption. */ }
                if (job?.status === 'failed') throw new Error(job.error || config.failed);
                if (job && !['running', 'completed'].includes(job.status)) throw error;
                if (retries >= 20) throw new Error(config.stillRunning);
            }
        }
    };
    const startQueuedImport = async (payload) => {
        try {
            return await post(config.run, payload);
        } catch (error) {
            if (!retryableImportError(error)) throw error;
            await waitForImport(1500);
            const job = await currentImportJob(payload.uuid);
            if (job?.status === 'failed') throw new Error(job.error || config.failed);
            if (job?.status === 'completed') return processImportStep(payload.uuid);
            if (job?.status === 'running') return {completed: false, phase: 'attachments', progress: 2};
            throw error;
        }
    };

    const finishQueuedImport = async (data, uuid, onProgress) => {
        let current = data;
        while (current.completed !== true) {
            onProgress(current);
            current = await processImportStep(uuid, () => onProgress({...current, reconnecting: true}));
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
        const sourceBaseUrlInput = query('#confluence-import-batch-source-base-url');
        if (!(sourceBaseUrlInput instanceof HTMLInputElement) || !sourceBaseUrlInput.reportValidity()) return;
        if (!window.confirm(config.confirmBatch)) return;
        const button = event.currentTarget;
        const status = query('#confluence-import-batch-status');
        const progress = query('#confluence-import-batch-progress');
        const bar = progress?.querySelector('.progress-bar');
        if (!(button instanceof HTMLButtonElement) || !(status instanceof HTMLElement) || !(progress instanceof HTMLElement) || !(bar instanceof HTMLElement)) return;
        button.disabled = true;
        sourceBaseUrlInput.disabled = true;
        const sourceBaseUrl = sourceBaseUrlInput.value.trim();
        progress.classList.remove('d-none');
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
                        ? {uuid: item.uuid, create_inactive_users: batchPolicy, source_base_url: sourceBaseUrl}
                        : {name: item.name, create_inactive_users: batchPolicy, source_base_url: sourceBaseUrl});
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
            progress.classList.add('d-none');
            button.disabled = false;
            sourceBaseUrlInput.disabled = false;
        }
    });

    query('#confluence-site-selection')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const button = form.querySelector('button[type="submit"]');
        const status = query('#confluence-site-selection-status');
        button.disabled = true;
        try {
            await saveSiteDraft();
            window.location.reload();
        } catch (error) {
            status.textContent = error instanceof Error ? error.message : config.failed;
            toast(status.textContent, 'danger');
        } finally { button.disabled = false; }
    });
    const siteAccessForm = query('#confluence-site-access');
    const workflowStart = query('#site-workflow-start');
    const siteStatus = query('#confluence-site-access-status');
    const runSiteAction = async (button, data, reload = true) => {
        button.disabled = true;
        if (siteStatus) siteStatus.textContent = config.userSearchLoading;
        try {
            const response = await post(config.siteAccess, data);
            if (response.report) window.location.assign(response.report);
            else if (reload) window.location.reload();
        } catch (error) {
            if (siteStatus) siteStatus.textContent = error instanceof Error ? error.message : config.failed;
            toast(siteStatus?.textContent || config.failed, 'danger');
            button.disabled = false;
        }
    };
    query('#confluence-site-access-prepare')?.addEventListener('click', (event) => {
        void runSiteAction(event.currentTarget, {action: 'prepare', uuid: event.currentTarget.dataset.uuid});
    });
    query('#confluence-site-access-reset')?.addEventListener('click', (event) => {
        void runSiteAction(event.currentTarget, {action: 'reset', uuid: event.currentTarget.dataset.uuid});
    });
    const siteReviewPayload = () => {
        const selections = {users: {}, groups: {}};
        siteAccessForm.querySelectorAll('[data-site-kind][data-source-id]').forEach((input) => {
            if (input.dataset.siteManual === 'true') selections[input.dataset.siteKind][input.dataset.sourceId] = input.value;
        });
        return {
            action: 'review', uuid: siteAccessForm.dataset.uuid, source_url: siteAccessForm.elements.source_url.value,
            ...selections, memberships: siteAccessForm.elements.memberships.checked,
            auto_map_users: siteAccessForm.elements.auto_map_users.checked,
            create_unmapped_users: siteAccessForm.elements.create_unmapped_users.checked,
            create_archival_users: siteAccessForm.elements.create_archival_users.checked,
            auto_map_groups: siteAccessForm.elements.auto_map_groups.checked,
            create_unmapped_groups: siteAccessForm.elements.create_unmapped_groups.checked,
        };
    };
    let siteNavigationPending = false;
    // EN: Save every component's draft before navigating; only the final site confirmation writes business data.
    // A failed validation/network request keeps the page and its unsaved choices visible for retry.
    //
    // HR: Prije navigacije spremi nacrte svih komponenti; samo završna potvrda sitea upisuje poslovne podatke.
    // Neuspjela provjera/mrežni zahtjev zadržava stranicu i nespremljene odabire za ponovni pokušaj.
    const navigateSiteIdentities = async (destination) => {
        if (siteNavigationPending) return;
        if (siteAccessForm) {
            if (!siteAccessForm.reportValidity()) return;
            siteNavigationPending = true;
            const controls = Array.from(siteAccessForm.querySelectorAll('input, select, button, textarea'),
                (input) => ({input, disabled: input.disabled}));
            controls.forEach(({input}) => { input.disabled = true; });
            siteAccessForm.setAttribute('aria-busy', 'true');
            if (siteStatus) siteStatus.textContent = config.userSearchLoading;
            try {
                await saveSiteDraft();
            } catch (error) {
                if (siteStatus) siteStatus.textContent = error instanceof Error ? error.message : config.failed;
                toast(siteStatus?.textContent || config.failed, 'danger');
                siteNavigationPending = false;
                controls.forEach(({input, disabled}) => { input.disabled = disabled; });
                siteAccessForm.removeAttribute('aria-busy');
                return;
            }
        }
        const next = new URL(destination, window.location.href);
        const current = new URL(window.location.href);
        // EN: A same-URL/fragment navigation does not reload the newly saved server-side policy.
        //
        // HR: Navigacija na isti URL/fragment ne učitava upravo spremljena pravila s poslužitelja.
        if (next.origin === current.origin && next.pathname === current.pathname && next.search === current.search) window.location.reload();
        else window.location.assign(next.href);
    };
    document.querySelectorAll('[data-site-identity-page]').forEach((link) => {
        link.addEventListener('click', (event) => {
            if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            void navigateSiteIdentities(link.href);
        });
    });
    const siteIdentityFilter = query('#confluence-site-identity-filter');
    siteIdentityFilter?.addEventListener('submit', (event) => {
        event.preventDefault();
        const destination = new URL(siteIdentityFilter.action, window.location.href);
        destination.search = new URLSearchParams(new FormData(siteIdentityFilter)).toString();
        void navigateSiteIdentities(destination.href);
    });
    query('#site-identity-per-page')?.addEventListener('change', () => siteIdentityFilter.requestSubmit());
    query('#site-identity-status')?.addEventListener('change', () => siteIdentityFilter.requestSubmit());
    siteAccessForm?.querySelectorAll('[name="auto_map_users"], [name="create_unmapped_users"], [name="auto_map_groups"], [name="create_unmapped_groups"], [name="create_archival_users"]').forEach((input) => {
        input.addEventListener('change', () => siteIdentityFilter.requestSubmit());
    });
    siteAccessForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (siteNavigationPending) return;
        const button = siteAccessForm.querySelector('[type="submit"]');
        button.disabled = true;
        try {
            await saveSiteDraft();
            window.location.reload();
        } catch (error) { toast(error.message || config.failed, 'danger'); button.disabled = false; }
    });
    const invalidateSiteReview = () => { if (workflowStart) workflowStart.disabled = true; };
    document.querySelectorAll('[data-personal-space-append]').forEach((button) => {
        button.addEventListener('click', () => {
            const ids = JSON.parse(button.dataset.personalSpaceAppend);
            document.querySelectorAll('[data-site-space]').forEach((row) => {
                if (ids.includes(row.dataset.siteSpace)) row.querySelector('[data-space-strategy]').value = 'append';
            });
            invalidateSiteReview();
        });
    });
    siteAccessForm?.addEventListener('input', invalidateSiteReview);
    siteAccessForm?.addEventListener('change', invalidateSiteReview);
    siteAccessForm?.querySelectorAll('select[data-site-kind="groups"]').forEach((input) => {
        input.addEventListener('change', () => {
            input.dataset.siteManual = 'true';
            const reason = input.closest('td')?.querySelector('[data-site-user-reason]');
            if (reason) reason.textContent = reason.dataset.manualLabel;
        });
    });
    siteAccessForm?.addEventListener('click', (event) => {
        if (event.target instanceof Element && event.target.closest('[data-identity-picker-choice]')) invalidateSiteReview();
    });
    const normalizedSearch = (value) => value.trim().normalize('NFKC').toLocaleLowerCase();
    const spacePager = createSiteTablePager(query('#confluence-site-selection'), '[data-filter-row]',
        (row) => normalizedSearch(row.textContent).includes(normalizedSearch(query('#confluence-site-filter')?.value || '')), '[name="space_ids[]"]');
    query('#confluence-site-filter')?.addEventListener('input', () => spacePager.refresh(true));
    query('#confluence-site-select-visible')?.addEventListener('click', () => {
        query('#confluence-site-spaces')?.querySelectorAll('tr:not(.d-none) [name="space_ids[]"]').forEach((input) => { input.checked = true; });
        spacePager.refresh();
        invalidateSiteReview();
    });
    query('#confluence-site-select-filtered')?.addEventListener('click', () => {
        spacePager.matchingRows().forEach((row) => { row.querySelector('[name="space_ids[]"]').checked = true; });
        spacePager.refresh();
        invalidateSiteReview();
    });
    query('#confluence-site-select-none')?.addEventListener('click', () => {
        query('#confluence-site-spaces')?.querySelectorAll('[name="space_ids[]"]').forEach((input) => { input.checked = false; });
        spacePager.refresh();
        invalidateSiteReview();
    });

    query('#site-source-cleanup')?.addEventListener('click', async (event) => {
        if (!window.confirm(config.confirmSiteCleanup)) return;
        const button = event.currentTarget;
        button.disabled = true;
        try {
            await post(config.siteCleanup, {uuid: button.dataset.uuid, confirm: 'release-source'});
            window.location.reload();
        } catch (error) { query('#site-source-cleanup-status').textContent = error.message || config.failed; button.disabled = false; }
    });
    const calendarForm = query('#confluence-site-calendars');
    const calendarStatus = query('#site-calendar-status');
    const invalidateCalendar = (event) => {
        if (event?.target?.closest('[data-site-table-pagination]') || ['site-calendar-filter', 'site-calendar-owner-filter'].includes(event?.target?.id)) return;
        invalidateSiteReview();
    };
    calendarForm?.addEventListener('change', invalidateCalendar);
    calendarForm?.addEventListener('input', invalidateCalendar);
    siteAccessForm?.addEventListener('change', invalidateCalendar);
    calendarForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = calendarForm.querySelector('[type="submit"]');
        button.disabled = true;
        try {
            await saveSiteDraft();
            window.location.reload();
        } catch (error) { calendarStatus.textContent = error.message || config.failed; button.disabled = false; }
    });
    const matchesCalendar = (row) => {
        const text = normalizedSearch(query('#site-calendar-filter')?.value || '');
        const status = query('#site-calendar-owner-filter')?.value || 'all';
        const type = row.querySelector('[data-calendar-type]')?.value || '';
        const needsReview = type === '' || (type === 'personal' && !row.querySelector('[data-calendar-owner]')?.value);
        const personal = row.dataset.calendarPersonal === '1' || type === 'personal';
        const selectedOwner = row.querySelector('[data-calendar-owner]')?.dataset.pickerLabel || '';
        return normalizedSearch(row.dataset.calendarSearch + ' ' + selectedOwner + ' ' + row.querySelector('[name="calendar_ids[]"]').value).includes(text) && (status === 'all'
            || (status === 'needs-review' ? needsReview : (status === 'personal' ? personal : (status === 'personal-mapped'
                ? personal && row.dataset.calendarOwnerStatus === 'mapped' : row.dataset.calendarOwnerStatus === status))));
    };
    const calendarPager = createSiteTablePager(calendarForm, '[data-calendar-row]', matchesCalendar, '[name="calendar_ids[]"]');
    const filterCalendarOwners = (reset = false) => {
        if (!calendarForm) return;
        calendarPager.refresh(reset);
        const count = query('#site-calendar-filter-count');
        if (count) count.textContent = count.dataset.label + ': ' + calendarPager.matchingRows().length + ' / ' + calendarForm.querySelectorAll('[data-calendar-row]').length;
    };
    query('#site-calendar-filter')?.addEventListener('input', () => filterCalendarOwners(true));
    query('#site-calendar-owner-filter')?.addEventListener('change', () => filterCalendarOwners(true));
    calendarForm?.addEventListener('change', () => filterCalendarOwners());
    const ownerFilter = query('#site-calendar-owner-filter');
    const calendarQuery = new URL(window.location.href).searchParams.get('calendar_q');
    if (calendarQuery && query('#site-calendar-filter')) query('#site-calendar-filter').value = calendarQuery;
    const initialOwnerFilter = new URL(window.location.href).searchParams.get('calendar_owner_status');
    if (ownerFilter && Array.from(ownerFilter.options).some((option) => option.value === initialOwnerFilter)) ownerFilter.value = initialOwnerFilter;
    filterCalendarOwners();
    const acceptCalendarOwner = (button, replaceExisting) => {
        const row = button.closest('[data-calendar-row]');
        const picker = row?.querySelector('[data-calendar-owner]');
        if (!picker || (!replaceExisting && picker.value !== '') || button.disabled) return;
        const ref = button.dataset.calendarAcceptOwner;
        picker.closest('[data-identity-picker]').dispatchEvent(new CustomEvent('identity-picker-select', {detail: {id: ref, label: button.dataset.calendarOwnerLabel}}));
    };
    calendarForm?.querySelectorAll('[data-calendar-accept-owner]').forEach((button) => {
        button.addEventListener('click', () => { acceptCalendarOwner(button, true); filterCalendarOwners(); });
    });
    query('#site-calendar-accept-suggestions')?.addEventListener('click', () => {
        calendarForm.querySelectorAll('[data-calendar-row]:not(.d-none) [data-calendar-accept-owner]')
            .forEach((button) => acceptCalendarOwner(button, false));
        filterCalendarOwners();
    });
    query('#site-calendar-select-visible')?.addEventListener('click', () => {
        calendarForm.querySelectorAll('[data-calendar-row]:not(.d-none) input[type="checkbox"]').forEach((input) => { input.checked = true; });
        filterCalendarOwners();
        invalidateCalendar();
    });
    query('#site-calendar-select-filtered')?.addEventListener('click', () => {
        calendarPager.matchingRows().forEach((row) => { row.querySelector('[name="calendar_ids[]"]').checked = true; });
        filterCalendarOwners();
        invalidateCalendar();
    });
    query('#site-calendar-select-none')?.addEventListener('click', () => {
        calendarForm.querySelectorAll('[name="calendar_ids[]"]').forEach((input) => { input.checked = false; });
        filterCalendarOwners();
        invalidateCalendar();
    });
    // EN: Follow the existing preparation worker, including after a reload or a lost POST response.
    // Polls never restart scanning; only phases with a measured total expose a percentage.
    //
    // HR: Prati postojeći postupak pripreme i nakon osvježavanja ili izgubljenog odgovora POST-a.
    // Provjere ne pokreću ponovno čitanje; postotak imaju samo faze s izmjerenim ukupnim brojem.
    const calendarPreparation = query('#site-calendar-preparation');
    const calendarPrepareButton = query('[data-site-calendar-action="prepare"]');
    const calendarPreparationBar = query('#site-calendar-preparation-bar');
    const calendarPreparationPhase = query('#site-calendar-preparation-phase');
    const calendarPreparationDetail = query('#site-calendar-preparation-detail');
    let calendarPreparationTimer = null;
    let calendarPreparationStarting = false;
    let calendarPreparationOpening = false;
    let calendarPreparationPolling = false;
    let calendarPreparationFailures = 0;
    const renderCalendarPreparation = (state) => {
        const firstDisplay = calendarPreparation.classList.contains('d-none');
        calendarPreparation.classList.remove('d-none');
        if (firstDisplay) calendarPreparation.scrollIntoView({block: 'center', behavior: 'auto'});
        const running = state.status === 'running';
        calendarPreparation.setAttribute('aria-busy', running ? 'true' : 'false');
        calendarPrepareButton.disabled = running;
        const total = Number(state.total);
        const done = Math.max(0, Number(state.done || 0));
        const measured = state.phase === 'partition' && state.total !== null && total > 0;
        const percent = measured ? Math.min(100, Math.floor(100 * done / total)) : null;
        calendarPreparationBar.style.width = percent !== null ? percent + '%' : '100%';
        calendarPreparationBar.classList.toggle('progress-bar-animated', running);
        if (percent === null) calendarPreparationBar.removeAttribute('aria-valuenow');
        else calendarPreparationBar.setAttribute('aria-valuenow', String(percent));
        calendarPreparationBar.setAttribute('aria-valuemin', '0');
        calendarPreparationBar.setAttribute('aria-valuemax', '100');
        calendarPreparationPhase.textContent = running
            ? (config.calendarPreparationPhases[state.phase] || config.calendarPreparationPhases.scan)
            : config.calendarPreparationStopped;
        const detail = [];
        if (measured) detail.push(config.calendarPreparationEvents.replace('{done}', done.toLocaleString()).replace('{total}', total.toLocaleString()).replace('{percent}', percent));
        else if (state.phase === 'scan' && done > 0) detail.push(config.calendarPreparationRecords.replace('{done}', done.toLocaleString()));
        if (Number(state.started_at) > 0) detail.push(config.calendarPreparationElapsed.replace('{seconds}', Math.max(0, Math.floor(Date.now() / 1000) - state.started_at).toLocaleString()));
        calendarPreparationDetail.textContent = detail.join(' · ');
    };
    const pollCalendarPreparation = async () => {
        if (!calendarPreparation || calendarPreparationOpening || calendarPreparationPolling) return;
        calendarPreparationTimer = null;
        calendarPreparationPolling = true;
        try {
            const url = new URL(config.siteCalendars, window.location.href);
            url.searchParams.set('uuid', calendarPreparation.dataset.uuid);
            const response = await fetch(url, {headers: {Accept: 'application/json'}, credentials: 'same-origin', cache: 'no-store'});
            const result = await responsePayload(response);
            if (!response.ok) throw new Error(result.error || config.failed);
            calendarPreparationFailures = 0;
            const state = result.preparation;
            if (state.status === 'ready') {
                calendarPreparationOpening = true;
                // EN: Preserve other settings edited while the source was being scanned.
                //
                // HR: Sačuvaj druge postavke uređene tijekom čitanja izvora.
                if (siteAccessForm) await saveSiteDraft();
                window.location.hash = 'site-calendars';
                window.location.reload();
                return;
            }
            if (state.status === 'idle' && calendarPreparationStarting) {
                calendarPreparationTimer = window.setTimeout(pollCalendarPreparation, 1500);
                return;
            }
            if (state.status === 'idle') {
                calendarPreparation.classList.add('d-none');
                calendarPrepareButton.disabled = false;
                return;
            }
            renderCalendarPreparation(state);
            if (state.status !== 'running') return;
        } catch (error) {
            calendarPreparationOpening = false;
            calendarPreparationFailures++;
            calendarPreparationPhase.textContent = config.calendarPreparationConnection;
            // EN: A network error never unlocks the start button or launches a duplicate worker.
            //
            // HR: Mrežna greška ne otključava gumb za početak niti pokreće dodatni postupak.
        } finally {
            calendarPreparationPolling = false;
        }
        calendarPreparationTimer = window.setTimeout(pollCalendarPreparation, Math.min(15000, 2000 * (calendarPreparationFailures + 1)));
    };
    calendarPrepareButton?.addEventListener('click', async () => {
        if (calendarPreparationStarting || calendarPreparationOpening) return;
        if (calendarPreparationTimer !== null) window.clearTimeout(calendarPreparationTimer);
        calendarPreparationStarting = true;
        renderCalendarPreparation({status: 'running', phase: 'verify', done: 0, total: null});
        calendarStatus.textContent = '';
        try {
            if (siteAccessForm) await saveSiteDraft();
        } catch (error) {
            calendarPreparationStarting = false;
            renderCalendarPreparation({status: 'interrupted', phase: '', done: 0, total: null});
            calendarStatus.textContent = error.message || config.failed;
            return;
        }
        calendarPreparationTimer = window.setTimeout(pollCalendarPreparation, 1500);
        try {
            await post(config.siteCalendars, {action: 'prepare', uuid: calendarPrepareButton.dataset.uuid});
        } catch (error) {
            calendarStatus.textContent = error.message || config.calendarPreparationConnection;
        } finally {
            calendarPreparationStarting = false;
            if (calendarPreparationTimer !== null) window.clearTimeout(calendarPreparationTimer);
            await pollCalendarPreparation();
        }
    });

    const siteContentForm = query('#confluence-site-content');
    const contentPager = createSiteTablePager(siteContentForm, '[data-site-space]', (row) => {
        const text = row.cells[0].textContent + ' ' + row.querySelector('[data-space-name]').value + ' ' + row.querySelector('[data-space-slug]').value;
        return normalizedSearch(text).includes(normalizedSearch(query('#site-content-filter')?.value || ''));
    });
    query('#site-content-filter')?.addEventListener('input', () => contentPager.refresh(true));
    siteContentForm?.addEventListener('invalid', (event) => {
        const row = event.target.closest('[data-site-space]');
        if (!row) return;
        query('#site-content-filter').value = '';
        contentPager.refresh();
        contentPager.reveal(row);
    }, true);
    siteContentForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const button = form.querySelector('[type="submit"]');
        button.disabled = true;
        try { await saveSiteDraft(); window.location.reload(); }
        catch (error) { toast(error instanceof Error ? error.message : config.failed, 'danger'); button.disabled = false; }
    });
    const invalidateSiteContent = (event) => {
        if (event?.target?.closest('[data-site-table-pagination]') || ['site-content-filter', 'confluence-site-filter'].includes(event?.target?.id)) return;
        invalidateSiteReview();
    };
    siteContentForm?.addEventListener('input', invalidateSiteContent);
    siteContentForm?.addEventListener('change', invalidateSiteContent);
    siteAccessForm?.addEventListener('input', invalidateSiteContent);
    siteAccessForm?.addEventListener('change', invalidateSiteContent);
    query('#confluence-site-selection')?.addEventListener('change', invalidateSiteContent);
    // EN: Collect configuration without executing any independent component import.
    // The server freezes and revalidates the complete selection before one confirmation.
    //
    // HR: Prikupi postavke bez pokretanja neovisnih komponentnih uvoza.
    // Poslužitelj zaključava i ponovno provjerava cjelokupni odabir prije jedne potvrde.
    const siteDraftPayload = () => {
        const spaceIds = Array.from(document.querySelectorAll('#confluence-site-selection [name="space_ids[]"]:checked'), (input) => input.value);
        const content = {spaces: {}};
        siteContentForm?.querySelectorAll('[data-site-space]').forEach((row) => {
            if (!spaceIds.includes(row.dataset.siteSpace)) return;
            content.spaces[row.dataset.siteSpace] = {
                name: row.querySelector('[data-space-name]').value,
                slug: row.querySelector('[data-space-slug]').value,
                strategy: row.querySelector('[data-space-strategy]').value,
                owner_mode: row.querySelector('[data-space-owner-mode]')?.value || 'mapped',
            };
        });
        if (siteContentForm) {
            content.language = siteContentForm.elements.language.value;
            ['include_attachments', 'include_comments', 'include_history', 'include_drafts', 'include_deleted'].forEach((flag) => { content[flag] = siteContentForm.elements[flag].checked; });
        }
        const calendarIds = Array.from(calendarForm?.querySelectorAll('[name="calendar_ids[]"]:checked') || [], (input) => input.value);
        const calendarTypes = {}, calendarOwners = {};
        calendarForm?.querySelectorAll('[data-calendar-row]').forEach((row) => {
            const id = row.querySelector('[name="calendar_ids[]"]').value;
            if (!calendarIds.includes(id)) return;
            calendarTypes[id] = row.querySelector('[data-calendar-type]').value;
            calendarOwners[id] = row.querySelector('[data-calendar-owner]')?.value || '';
        });
        const identities = siteAccessForm ? siteReviewPayload() : null;
        return {space_ids: spaceIds, content, calendar_ids: calendarIds, calendar_types: calendarTypes, calendar_owners: calendarOwners,
            ...(identities?.source_url ? {source_url: identities.source_url, identities} : {})};
    };
    const workflowButton = query('#site-workflow-review') || query('#site-workflow-resume') || workflowStart;
    const saveSiteDraft = () => post(config.siteWorkflow, {action: 'draft', uuid: workflowButton.dataset.uuid, ...siteDraftPayload()});
    const workflowStatus = query('#site-workflow-status');
    const workflowProgress = query('#site-workflow-progress');
    const workflowBar = query('#site-workflow-progress-bar');
    const workflowPercent = query('#site-workflow-percent');
    // EN: Show committed progress for the current step, not a guessed whole-import ETA.
    // Preparation and linking have no known denominator and remain indeterminate.
    //
    // HR: Prikazuje potvrđeni napredak trenutačnog koraka, ne procjenu trajanja cijelog uvoza.
    // Priprema i povezivanje nemaju poznat ukupan broj pa prikazuju obradu bez postotka.
    const renderSiteWorkflowProgress = (result) => {
        if (!workflowProgress || !workflowBar || !workflowPercent || !workflowStatus) return;
        workflowProgress.classList.remove('d-none');
        const measured = typeof result.progress === 'number' && Number.isFinite(result.progress);
        const percent = measured ? Math.max(0, Math.min(100, Math.floor(result.progress))) : null;
        workflowPercent.textContent = percent === null ? config.siteWorkflowWorking : `${percent}%`;
        workflowBar.style.width = `${percent ?? 100}%`;
        workflowBar.classList.toggle('progress-bar-striped', percent === null);
        workflowBar.classList.toggle('progress-bar-animated', percent === null);
        workflowBar.classList.remove('bg-danger');
        if (percent === null) workflowBar.removeAttribute('aria-valuenow');
        else workflowBar.setAttribute('aria-valuenow', String(percent));
        let label = config.siteWorkflowPhases[result.phase] || config.importing;
        const current = result.current || {};
        if (result.phase === 'calendars') label += ` · ${Number(current.calendars_done || 0)} / ${Number(current.calendars_total || 0)}`;
        if (result.phase === 'content' && Number(current.spaces_total) > 0) label += ` · ${Number(current.spaces_done || 0)} / ${Number(current.spaces_total)}`;
        workflowStatus.textContent = label;
    };
    query('#site-workflow-review')?.addEventListener('click', async (event) => {
        if (!siteAccessForm?.reportValidity() || (siteContentForm && !siteContentForm.reportValidity())) return;
        const button = event.currentTarget;
        button.disabled = true;
        try {
            const identities = siteReviewPayload();
            await post(config.siteWorkflow, {action: 'review', uuid: button.dataset.uuid,
                ...siteDraftPayload(), source_url: identities.source_url, identities});
            window.location.reload();
        } catch (error) { workflowStatus.textContent = error.message || config.failed; button.disabled = false; }
    });
    const runSiteWorkflow = async (button, action) => {
        if (action === 'start' && !window.confirm(config.confirmImport)) return;
        button.disabled = true;
        renderSiteWorkflowProgress({phase: action === 'start' ? 'identities' : '', progress: null});
        try {
            let result = await post(config.siteWorkflow, {action, uuid: button.dataset.uuid, token: button.dataset.token || ''});
            renderSiteWorkflowProgress(result);
            while (!result.completed) {
                result = await post(config.siteWorkflow, {action: 'process', uuid: button.dataset.uuid});
                renderSiteWorkflowProgress(result);
            }
            window.location.reload();
        } catch (error) {
            workflowStatus.textContent = error.message || config.failed;
            workflowBar?.classList.remove('progress-bar-striped', 'progress-bar-animated');
            workflowBar?.classList.add('bg-danger');
            if (workflowBar && !workflowBar.hasAttribute('aria-valuenow')) workflowPercent.textContent = config.siteWorkflowStopped;
            button.disabled = false;
        }
    };
    workflowStart?.addEventListener('click', () => { void runSiteWorkflow(workflowStart, 'start'); });
    query('#site-workflow-resume')?.addEventListener('click', (event) => { void runSiteWorkflow(event.currentTarget, 'process'); });

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
            source_base_url: form.elements.source_base_url.value,
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
            const started = await startQueuedImport(payload);
            const data = await finishQueuedImport(started, payload.uuid, (current) => {
                status.textContent = updateImportProgress(
                    current,
                    status.textContent,
                    progress.querySelector('.progress-bar'),
                );
            });
            status.textContent = data.message || config.ready;
            result.classList.remove('d-none');
            renderImportResult(result, data.summary || data);
            toast(status.textContent, Number(data.summary?.attachment_report?.current_failed || 0) > 0 ? 'danger' : 'success');
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
            if (job.can_resume) { const resume = document.createElement('button'); resume.className = 'btn btn-sm btn-primary'; resume.type = 'button'; resume.dataset.resumeJob = String(job.uuid || ''); resume.textContent = config.resumeImport; actions.appendChild(resume); }
            if (job.can_cancel) { const cancel = document.createElement('button'); cancel.className = 'btn btn-sm btn-danger'; cancel.type = 'button'; cancel.dataset.cancelJob = String(job.uuid || ''); cancel.title = config.cancelImport; cancel.setAttribute('aria-label', config.cancelImport); cancel.textContent = '×'; actions.appendChild(cancel); }
            action.appendChild(actions);
            row.appendChild(action); body.appendChild(row);
        });
    };
    const refreshJobs = async () => {
        try { const response = await fetch(config.jobs, {headers: {Accept: 'application/json'}, cache: 'no-store'}); const data = await responsePayload(response); if (response.ok && Array.isArray(data.jobs)) renderJobs(data.jobs); } catch (_error) { /* The next refresh retries. */ }
    };
    document.addEventListener('click', async (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-resume-job]') : null;
        if (!(button instanceof HTMLButtonElement)) return;
        const uuid = button.dataset.resumeJob || '';
        if (uuid === '') return;

        button.disabled = true;
        try {
            const result = await finishQueuedImport({completed: false, phase: 'finalizing', progress: 0}, uuid, () => {});
            toast(result.message || config.importFinished, 'success');
            await refreshJobs();
        } catch (error) {
            toast(error instanceof Error ? error.message : config.failed, 'danger');
            button.disabled = false;
            await refreshJobs();
        }
    });
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
    if (calendarPreparation) {
        if (calendarPreparation.dataset.state === 'running') renderCalendarPreparation({status: 'running', phase: 'scan', done: 0, total: null});
        if (calendarPreparation.dataset.state !== 'idle') pollCalendarPreparation();
    }
    if (config.initialError) toast(config.initialError, 'danger');
})();
</script>
