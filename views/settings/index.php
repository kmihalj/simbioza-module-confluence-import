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
 * @var string $importPath
 * @var string $stylesPath
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
$suggestions = is_array($preparation['identity_suggestions'] ?? null)
    ? $preparation['identity_suggestions']
    : [];
$groupSuggestions = is_array($preparation['group_suggestions'] ?? null)
    ? $preparation['group_suggestions']
    : [];
$ownerSourceKey = is_scalar($space['owner_source_key'] ?? null) ? (string)$space['owner_source_key'] : '';
$counts = is_array($scan['counts'] ?? null) ? $scan['counts'] : [];
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

<div class="row">
    <?php if (is_string($settingsMenuHtml) && $settingsMenuHtml !== '') : ?>
        <?= $settingsMenuHtml ?>
    <?php endif; ?>
    <div class="col confluence-import-shell">
        <div class="container-fluid px-0">
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
                <div>
                    <h1 class="h2 mb-1"><?= $this->escape($title) ?></h1>
                    <p class="text-body-secondary mb-0"><?= $this->escape(__('Uvezite Confluence XML backup jednog područja uz kontrolirano mapiranje korisnika, grupa i ovlasti.')) ?></p>
                </div>
            </div>

            <div class="alert alert-info" role="note">
                <strong><?= $this->escape(__('Sigurni tijek importa')) ?></strong>
                <div><?= $this->escape(__('Arhiva se najprije prenosi i provjerava bez promjene sadržaja. Import počinje tek nakon pregleda mapiranja. Neriješene ovlasti ostaju zatvorene.')) ?></div>
            </div>

            <details class="confluence-import-panel"<?= $preparation === null ? ' open' : '' ?>>
                <summary><?= $this->escape(__('1. Prenesi i provjeri Confluence arhivu')) ?></summary>
                <div class="confluence-import-body">
                    <div class="mb-3">
                        <label class="form-label" for="confluence-import-file"><?= $this->escape(__('Confluence XML ZIP arhiva')) ?></label>
                        <input class="form-control" id="confluence-import-file" type="file" accept=".zip,application/zip">
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

            <?php if ($preparation !== null) : ?>
                <details class="confluence-import-panel mt-3" open>
                    <summary><?= $this->escape(__('2. Pregledaj mapiranja i pokreni import')) ?></summary>
                    <div class="confluence-import-body">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6 col-xl-3"><div class="confluence-import-option h-100"><small class="text-body-secondary d-block"><?= $this->escape(__('Izvorno područje')) ?></small><strong><?= $this->escape((string)($space['name'] ?? '')) ?></strong><div class="confluence-import-source-key"><?= $this->escape((string)($space['source_key'] ?? '')) ?></div></div></div>
                            <div class="col-md-6 col-xl-3"><div class="confluence-import-option h-100"><small class="text-body-secondary d-block"><?= $this->escape(__('Stranice')) ?></small><strong><?= $this->escape((string)($statuses['current'] ?? 0)) ?></strong><div class="small text-body-secondary"><?= $this->escape(sprintf(__('Aktualne: %1$d; povijesne: %2$d; nacrti: %3$d; obrisane: %4$d'), (int)($statuses['current'] ?? 0), (int)($statuses['history'] ?? 0), (int)($statuses['draft'] ?? 0), (int)($statuses['deleted'] ?? 0))) ?></div></div></div>
                            <div class="col-md-6 col-xl-3"><div class="confluence-import-option h-100"><small class="text-body-secondary d-block"><?= $this->escape(__('Privitci')) ?></small><strong><?= $this->escape((string)($counts['Attachment'] ?? 0)) ?></strong><div class="small text-body-secondary"><?= $this->escape(__('Spremaju se privatno i uvijek se preuzimaju kao datoteke.')) ?></div></div></div>
                            <div class="col-md-6 col-xl-3"><div class="confluence-import-option h-100"><small class="text-body-secondary d-block"><?= $this->escape(__('Vrsta područja')) ?></small><strong><?= $this->escape(($space['type'] ?? '') === 'personal' ? __('Osobno područje') : __('Opće područje')) ?></strong><div class="small text-body-secondary"><?= $this->escape(__('Confluence verzija:')) ?> <?= $this->escape((string)($scan['source']['confluence_version'] ?? '')) ?></div></div></div>
                        </div>

                        <?php foreach ($warnings as $warning) : ?>
                            <?php if (is_scalar($warning)) :
                                ?><div class="alert alert-warning py-2"><?= $this->escape((string)$warning) ?></div><?php
                            endif; ?>
                        <?php endforeach; ?>

                        <form id="confluence-import-form">
                            <input type="hidden" name="uuid" value="<?= $this->escape((string)($job['uuid'] ?? '')) ?>">
                            <div class="row g-3">
                                <div class="col-md-5">
                                    <label class="form-label" for="confluence-import-workspace-name"><?= $this->escape(__('Naziv ciljnog područja')) ?></label>
                                    <input class="form-control" id="confluence-import-workspace-name" name="workspace_name" value="<?= $this->escape((string)($space['name'] ?? '')) ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="confluence-import-workspace-slug"><?= $this->escape(__('Slug ciljnog područja')) ?></label>
                                    <input class="form-control" id="confluence-import-workspace-slug" name="workspace_slug" value="<?= $this->escape((string)($space['source_key'] ?? '')) ?>" required>
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
                                    <p class="text-body-secondary"><?= $this->escape(__('Import ne izrađuje lažne lokalne korisnike. Odaberite postojeći račun samo kada ste sigurni da predstavlja istu osobu.')) ?></p>
                                    <input class="form-control mb-3" type="search" data-filter-table="identity" placeholder="<?= $this->escape(__('Pretraži Confluence korisnike')) ?>">
                                    <div class="table-responsive confluence-import-table-wrap">
                                        <table class="table table-sm align-middle mb-0" data-filter-target="identity"><thead><tr><th><?= $this->escape(__('Confluence identitet')) ?></th><th><?= $this->escape(__('Ciljni korisnik')) ?></th></tr></thead><tbody>
                                        <?php foreach ($sourceUsers as $sourceUser) : ?>
                                            <?php if (!is_array($sourceUser)) {
                                                continue;
                                            } $sourceKey = (string)($sourceUser['source_key'] ?? '');
                                            $suggested = is_numeric($suggestions[$sourceKey] ?? null) ? (int)$suggestions[$sourceKey] : 0; ?>
                                            <tr data-filter-row class="<?= $sourceKey !== '' && $sourceKey === $ownerSourceKey ? 'confluence-import-owner-row' : '' ?>">
                                                <td><strong><?= $this->escape((string)($sourceUser['display_name'] ?? $sourceUser['username'] ?? $sourceKey)) ?></strong><?php if ($sourceKey === $ownerSourceKey) :
                                                    ?> <span class="badge text-bg-warning"><?= $this->escape(__('Vlasnik')) ?></span><?php
                                                            endif; ?><div class="small text-body-secondary"><?= $this->escape((string)($sourceUser['email'] ?? '')) ?></div><div class="confluence-import-source-key"><?= $this->escape($sourceKey) ?></div></td>
                                                <td><select class="form-select form-select-sm" data-identity-map="<?= $this->escape($sourceKey) ?>"><option value=""><?= $this->escape(__('Nije mapirano — pristup ostaje blokiran')) ?></option><?php foreach ($targetUsers as $targetUser) :
                                                    ?><?php if (!is_array($targetUser) || !is_numeric($targetUser['id'] ?? null)) {
                                                    continue;
                                                    } $targetId = (int)$targetUser['id']; ?><option value="<?= $targetId ?>"<?= $targetId === $suggested ? ' selected' : '' ?>><?= $this->escape((string)($targetUser['display_name'] ?? $targetUser['login_identifier'] ?? $targetId)) ?><?= ($targetUser['email'] ?? '') !== '' ? ' — ' . $this->escape((string)$targetUser['email']) : '' ?></option><?php
                                                                                                                  endforeach; ?></select></td>
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

                            <div class="progress confluence-import-progress mt-4 d-none" id="confluence-import-run-progress" role="progressbar"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width:100%"></div></div>
                            <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mt-3 confluence-import-actions">
                                <span class="text-body-secondary" id="confluence-import-run-status" aria-live="polite"><?= $this->escape(__('Spremno za import. Izvorna arhiva briše se s poslužitelja tek nakon uspješnog završetka.')) ?></span>
                                <button class="btn btn-primary" type="submit" id="confluence-import-run"><?= $this->escape(__('Uvezi područje')) ?></button>
                            </div>
                        </form>
                        <pre class="alert alert-info confluence-import-result mt-3 d-none" id="confluence-import-result"></pre>
                    </div>
                </details>
            <?php endif; ?>

            <section class="confluence-import-panel mt-3">
                <div class="confluence-import-body">
                    <h2 class="h4 mb-1"><?= $this->escape(__('Nedavni Confluence importi')) ?></h2>
                    <p class="text-body-secondary"><?= $this->escape(__('Popis se automatski osvježava i prikazuje trenutačnu fazu dugotrajnog importa.')) ?></p>
                    <p id="confluence-import-jobs-empty"<?= $jobs === [] ? '' : ' class="d-none"' ?>><?= $this->escape(__('Još nema Confluence import poslova.')) ?></p>
                    <div class="table-responsive<?= $jobs === [] ? ' d-none' : '' ?>" id="confluence-import-jobs-wrap"><table class="table align-middle mb-0"><thead><tr><th><?= $this->escape(__('Vrijeme')) ?></th><th><?= $this->escape(__('Arhiva / područje')) ?></th><th><?= $this->escape(__('Stanje')) ?></th><th><?= $this->escape(__('Faza')) ?></th><th><?= $this->escape(__('Radnja')) ?></th></tr></thead><tbody id="confluence-import-jobs-body">
                    <?php foreach ($jobs as $recent) :
                        ?><tr><td><?= $this->escape((string)($recent['created_at_display'] ?? '')) ?></td><td><strong><?= $this->escape((string)($recent['space_name'] ?: $recent['name'] ?? '')) ?></strong><div class="confluence-import-source-key"><?= $this->escape((string)($recent['space_key'] ?? '')) ?></div></td><td><?= $this->escape((string)($recent['status_label'] ?? '')) ?><?php if (($recent['error'] ?? '') !== '') :
    ?><div class="small text-danger"><?= $this->escape((string)$recent['error']) ?></div><?php
                        endif; ?></td><td><?= $this->escape((string)($recent['stage_label'] ?? '')) ?></td><td><?php if (is_string($recent['mapping_url'] ?? null)) :
    ?><a class="btn btn-sm btn-secondary" href="<?= $this->escape($recent['mapping_url']) ?>" title="<?= $this->escape(__('Otvori mapiranje')) ?>">↗</a><?php
                        elseif (is_string($recent['workspace_url'] ?? null)) :
                            ?><a class="btn btn-sm btn-secondary" href="<?= $this->escape($recent['workspace_url']) ?>" title="<?= $this->escape(__('Otvori područje')) ?>">↗</a><?php
                        endif; ?></td></tr><?php
                    endforeach; ?>
                    </tbody></table></div>
                </div>
            </section>
        </div>
    </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3"><div class="toast border-0" id="confluence-import-toast" role="status" aria-live="polite" aria-atomic="true"><div class="toast-header bg-primary text-white" id="confluence-import-toast-header"><strong class="me-auto" id="confluence-import-toast-title"><?= $this->escape(__('Informacija')) ?></strong><button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="<?= $this->escape(__('Zatvori')) ?>"></button></div><div class="toast-body" id="confluence-import-toast-body"></div></div></div>

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
        'run' => $importPath,
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
        'importing' => __('Import je u tijeku. Velika područja mogu potrajati nekoliko minuta.'),
        'failed' => __('Zahtjev nije uspio.'),
        'successTitle' => __('Uspjeh'),
        'errorTitle' => __('Pogreška'),
        'infoTitle' => __('Informacija'),
        'openMapping' => __('Otvori mapiranje'),
        'openWorkspace' => __('Otvori područje'),
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
        if (text === '') return {};
        try { return JSON.parse(text); } catch (_error) { return {error: text}; }
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
    const refreshCsrf = async () => {
        const response = await fetch(config.csrf, {headers: {Accept: 'application/json'}, cache: 'no-store'});
        const data = await responsePayload(response);
        if (!response.ok || typeof data.csrf_token !== 'string' || data.csrf_token === '') throw new Error(data.error || config.failed);
        config.csrfToken = data.csrf_token;
    };
    const post = async (url, data) => {
        await refreshCsrf();
        const response = await fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json', Accept: 'application/json', [config.csrfHeader]: config.csrfToken}, body: JSON.stringify(data)});
        const payload = await responsePayload(response);
        if (!response.ok) throw new Error(payload.error || config.failed);
        return payload;
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
    uploadButton?.addEventListener('click', async () => {
        const file = query('#confluence-import-file')?.files?.[0];
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
            language: form.elements.language.value,
            include_attachments: form.elements.include_attachments.checked,
            include_comments: form.elements.include_comments.checked,
            include_history: form.elements.include_history.checked,
            include_deleted: form.elements.include_deleted.checked,
            include_drafts: form.elements.include_drafts.checked,
            identity_map: {},
            group_map: {},
            group_create: {},
        };
        document.querySelectorAll('[data-identity-map]').forEach((select) => { if (select.value !== '') payload.identity_map[select.dataset.identityMap] = Number(select.value); });
        document.querySelectorAll('[data-group-map]').forEach((select) => {
            if (select.value === '__create__') payload.group_create[select.dataset.groupMap] = true;
            else if (select.value !== '') payload.group_map[select.dataset.groupMap] = Number(select.value);
        });
        button.disabled = true;
        progress.classList.remove('d-none');
        status.textContent = config.importing;
        try {
            const data = await post(config.run, payload);
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
            const url = job.mapping_url || job.workspace_url;
            if (url) { const link = document.createElement('a'); link.className = 'btn btn-sm btn-secondary'; link.href = url; link.title = job.mapping_url ? config.openMapping : config.openWorkspace; link.textContent = '↗'; action.appendChild(link); }
            row.appendChild(action); body.appendChild(row);
        });
    };
    const refreshJobs = async () => {
        try { const response = await fetch(config.jobs, {headers: {Accept: 'application/json'}, cache: 'no-store'}); const data = await responsePayload(response); if (response.ok && Array.isArray(data.jobs)) renderJobs(data.jobs); } catch (_error) { /* The next refresh retries. */ }
    };
    window.setInterval(refreshJobs, 5000);
    if (config.initialError) toast(config.initialError, 'danger');
})();
</script>
