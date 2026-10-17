<?php

declare(strict_types=1);

// EN: Add-only personal imports with real services, restricted by the disposable fixture guard.
// Existing page bytes, metadata, homepage and ACL must survive both repeated and extended imports.
//
// HR: Osobni uvoz dodavanjem sa stvarnim servisima, ograničen zaštitom potrošnog fixturea.
// Bajtovi, metapodaci, početna stranica i ACL postojećih stranica moraju preživjeti ponavljanje i dopunu.
require __DIR__ . '/site_acceptance.php';

$workflow = $c->get(AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteWorkflow::class);
$personalService = $c->get(AaiEduHr\SimbiozaModuleUser\Service\PersonalWorkspaceService::class);
$workspaces = $c->get(AaiEduHr\SimbiozaModuleWorkspace\Service\WorkspaceRepository::class);
$editor = $c->get(AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorService::class);
$attachmentApi = $c->get(AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorWorkspaceIntegration::class);
$suffix = bin2hex(random_bytes(4));
$owner = $users->createUserFromSetup(
    'append-' . $suffix,
    'Append fixture',
    'Append',
    'Fixture',
    'append-' . $suffix . '@example.invalid',
    ['local' => false],
    false,
    false,
    ''
);
$ownerId = (int)$owner['id'];
$personal = $personalService->ensureForUser($ownerId, $uid, false);
$workspace = $personal['workspace'];
$wid = (int)$workspace['id'];
$nativeDocument = $actorContext->runAs($actor, fn() => $editor->createWithContent('Append home', 'append-home', 'en', '<p>KEEP native bytes</p>'));
$nativeNode = $actorContext->runAs($actor, fn() => $attachmentApi->attachDocument($workspace['slug'], 0, $nativeDocument->id, 'Append home', 'append-home', 'en', 1));
$workspaces->saveNode($wid, [...$nativeNode, 'primary_language' => 'en', 'supported_languages' => ['en'],
    'title_translations' => ['en' => 'Append home'], 'is_homepage' => true], $uid);
$baselineWorkspace = $workspaces->findWorkspaceById($wid, true);
$baselineNode = $db->table('workspace_nodes')->where('id', '=', $nativeNode['id'])->first();
$baselineAcl = $workspaces->workspaceAclRows($wid);
$loadDocument = static fn(string $key) => $actorContext->runAs($actor, fn() => $editor->load($key, 'en'));
$baselineNativeDocument = $loadDocument($nativeDocument->id);
$assert($baselineNativeDocument !== null, 'Native fixture document cannot be loaded before import: ' . $nativeDocument->id);
$url = 'https://append-' . $suffix . '.test';
$sourceKey = '~append-' . $suffix;
$writeArchive = static function (string $marker, bool $extra = false) use ($cfg, $sourceKey, $suffix): string {
    $path = $cfg->uploadDirectory() . '/append-' . $suffix . '-' . bin2hex(random_bytes(3)) . '.zip';
    $xml = '<hibernate-generic><object class="Space"><id>1</id><property name="key">' . $sourceKey
        . '</property><property name="name">Append source</property><property name="spaceType">personal</property>'
        . '<property name="creator"><id>alice-key</id></property><property name="homePage"><id>100</id></property></object>'
        . '<object class="InternalUser"><id>1</id><property name="name">append-source-' . $suffix . '</property></object>'
        . '<object class="ConfluenceUserImpl"><id>alice-key</id><property name="name">append-source-' . $suffix . '</property></object>';
    foreach (['100' => ['Append home', ''], '101' => ['Append child', '100'], ...($extra ? ['102' => ['Append later', '100']] : [])] as $id => [$title, $parent]) {
        $homeTitle = $marker === 'FIRST' ? 'Append home' : 'Changed remote home';
        if ((string)$id === '100') {
            $title = $homeTitle;
        }
        $body = '<p>' . $marker . ' ' . $id . '</p>';
        if ((string)$id === '102') {
            $body .= '<ac:link><ri:page ri:content-title="' . $homeTitle . '"/></ac:link>';
        }
        $xml .= '<object class="Page"><id>' . $id . '</id><property name="space"><id>1</id></property>'
            . '<property name="title">' . $title . '</property><property name="version">1</property>'
            . ($parent !== '' ? '<property name="parent"><id>' . $parent . '</id></property>' : '')
            . '<property name="creator"><id>alice-key</id></property></object>'
            . '<object class="BodyContent"><id>b' . $id . '</id><property name="content"><id>' . $id . '</id></property>'
            . '<property name="body">' . htmlspecialchars($body, ENT_XML1) . '</property></object>';
    }
    foreach (['100', ...($extra ? ['102'] : [])] as $id) {
        $xml .= '<object class="Attachment"><id>a' . $id . '</id><property name="containerContent"><id>' . $id . '</id></property>'
            . '<property name="space"><id>1</id></property><property name="title">sample-' . $id . '.txt</property>'
            . '<property name="version">1</property><property name="contentStatus">current</property></object>';
    }
    $xml .= '</hibernate-generic>';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('exportDescriptor.properties', "exportType=site\nbackupAttachments=true\n");
    $zip->addFromString('entities.xml', $xml);
    foreach (['100', ...($extra ? ['102'] : [])] as $id) {
        $zip->addFromString('attachments/' . $id . '/a' . $id . '/1', $marker . ' attachment ' . $id);
    }
    $zip->close();
    return $path;
};
$runAppend = static function (string $archive) use ($repo, $scanner, $access, $workflow, $uid, $ownerId, $actor, $url, $sourceKey, $assert): array {
    $job = $repo->createUploadJob('personal-site.zip', $archive, filesize($archive), 0, $uid, time() + 3600);
    $scan = $scanner->scan($archive);
    $repo->saveScan((int)$job['id'], $scan);
    $access->prepare($job['uuid'], $uid);
    $input = ['source_url' => $url, 'space_ids' => ['1'], 'calendar_ids' => [],
        'identities' => ['users' => ['internal-user:1' => (string)$ownerId], 'groups' => [], 'memberships' => false],
        'content' => ['language' => 'en', 'spaces' => ['1' => ['name' => 'Ignored source name', 'slug' => $sourceKey, 'strategy' => 'append']]]];
    $workflow->draft($job['uuid'], $uid, $input);
    $workflow->review($job['uuid'], $actor, $input);
    $plan = $workflow->state($job['uuid'], $uid);
    $progress = $workflow->start($job['uuid'], $actor, $plan['token']);
    for ($i = 0; $i < 100 && !$progress['completed']; ++$i) {
        $progress = $workflow->process($job['uuid'], $actor);
    }
    $assert($progress['completed'], 'Add-only workflow did not finish.');
    $parent = $repo->jobByUuid($job['uuid'], $uid);
    return $repo->jobByUuid(array_values($parent['options']['site_content']['children'])[0], $uid);
};
$first = $runAppend($writeArchive('FIRST'));
$assert($first['summary']['pages_imported'] === 2 && $first['summary']['pages_preserved'] === 0, 'First append counts incorrect.');
$assert($first['summary']['attachments_imported'] === 1, 'New-page attachment missing.');
$sourceHome = $repo->withSiteContext($url, [], fn() => $repo->contentBySource($sourceKey, '100'));
$assert($sourceHome['target_slug'] !== 'append-home', 'Occupied native slug was reused.');
$sourceDocument = $loadDocument($sourceHome['target_document_key']);
$assert($sourceDocument !== null, 'Imported source document is unreadable.');
$sourceNode = $db->table('workspace_nodes')->where('id', '=', $sourceHome['target_node_id'])->first();
$second = $runAppend($writeArchive('CHANGED'));
$assert($second['summary']['pages_imported'] === 0 && $second['summary']['pages_preserved'] === 2, 'Repeat append duplicated pages.');
$assert($second['summary']['attachments_imported'] === 0, 'Repeat append imported a changed attachment of an old page.');
$third = $runAppend($writeArchive('CHANGED', true));
$assert($third['summary']['pages_imported'] === 1 && $third['summary']['pages_preserved'] === 2, 'Extended append did not add only the new page.');
$assert($third['summary']['attachments_imported'] === 1, 'Extended append did not restrict attachments to the new page.');
$later = $repo->withSiteContext($url, [], fn() => $repo->contentBySource($sourceKey, '102'));
$laterNode = $db->table('workspace_nodes')->where('id', '=', $later['target_node_id'])->first();
$assert((int)$laterNode['parent_id'] === (int)$sourceHome['target_node_id'], 'New child lost its preserved parent.');
$assert($repo->unresolvedLinksForJob((int)$third['id']) === [], 'New page could not link to an old page whose source title changed.');
$assert($baselineWorkspace === $workspaces->findWorkspaceById($wid, true), 'Existing workspace metadata changed.');
$assert($baselineNode === $db->table('workspace_nodes')->where('id', '=', $nativeNode['id'])->first(), 'Native node/homepage changed.');
$assert($baselineAcl === $workspaces->workspaceAclRows($wid), 'Workspace ACL changed.');
$assert($loadDocument($nativeDocument->id) == $baselineNativeDocument, 'Native HTML changed.');
$assert($sourceDocument == $loadDocument($sourceHome['target_document_key']), 'Previously imported document changed.');
$assert($sourceNode === $db->table('workspace_nodes')->where('id', '=', $sourceHome['target_node_id'])->first(), 'Previously imported node changed.');
$beforeRetry = $db->table('workspace_nodes')->where('workspace_id', '=', $wid)->get();
$repo->updateJob((int)$third['id'], ['status' => 'failed']);
try {
    $c->get(AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportService::class)->retrySiteChild($third['uuid'], $actor);
    throw new RuntimeException('Append recovery was allowed to replace the workspace.');
} catch (AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException) {
    $assert($beforeRetry === $db->table('workspace_nodes')->where('workspace_id', '=', $wid)->get(), 'Failed append recovery deleted content.');
}
echo json_encode(['personal_append' => 'passed', 'document_store' => $store, 'native_content' => 'preserved',
    'existing_source_content' => 'preserved', 'repeat' => 'no-duplicates', 'extended' => 'new-page-only'], JSON_THROW_ON_ERROR), PHP_EOL;
