<?php

declare(strict_types=1);

// EN: Reuses the guarded disposable application setup and verifies the unified workflow with real module APIs.
// This fixture must never run against HFC or an ordinary installation.
//
// HR: Koristi zaštićenu potrošnu aplikaciju i provjerava zajednički uvoz stvarnim API-jima modula.
// Ovaj test nikada se ne smije pokrenuti na HFC-u ili običnoj instalaciji.
require __DIR__ . '/site_acceptance.php';

$workflow = $c->get(AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteWorkflow::class);
$url = 'https://workflow-' . bin2hex(random_bytes(4)) . '.test';
$suffix = bin2hex(random_bytes(4));
$path = $cfg->uploadDirectory() . '/workflow-' . $suffix . '.zip';
$tables = AaiEduHr\SimbiozaModuleConfluenceImport\Tests\Support\TeamCalendarsFixture::tables();
$tables['SUBCALS'][] = array_replace($tables['SUBCALS'][0], ['ID' => 'future-calendar', 'NAME' => 'Future personal owner']);
$tables['EVENTS'][] = array_replace($tables['EVENTS'][0], ['ID' => 'future-event', 'SUB_CALENDAR_ID' => 'future-calendar', 'VEVENT_UID' => 'future-event@test']);
AaiEduHr\SimbiozaModuleConfluenceImport\Tests\Support\TeamCalendarsFixture::archive($path, $tables);
$zip = new ZipArchive();
$zip->open($path);
$xml = str_replace('>alice<', '>workflow-' . $suffix . '<', $zip->getFromName('entities.xml'));
$xml = str_replace('</hibernate-generic>', '<object class="Space"><id>2</id><property name="key">TWO</property><property name="name">Two</property></object></hibernate-generic>', $xml);
foreach (['1' => ['ONE', 'TWO', 'Home one', 'Home two'], '2' => ['TWO', 'ONE', 'Home two', 'Home one']] as $space => [$key, $other, $title, $otherTitle]) {
    $body = '<p>Workflow ' . $key . '</p><ac:link><ri:page ri:space-key="' . $other . '" ri:content-title="' . $otherTitle . '"/></ac:link>';
    if ($space === 1) {
        $body .= '<ac:structured-macro ac:name="calendar"><ac:parameter ac:name="id">calendar-one</ac:parameter></ac:structured-macro>';
    }
    $page = '<object class="Page"><id>page-' . $key . '</id><property name="space"><id>' . $space . '</id></property>'
        . '<property name="title">' . $title . '</property><property name="creator"><id>alice-key</id></property>'
        . '<property name="lastModifier"><id>alice-key</id></property><property name="version">1</property></object>'
        . '<object class="BodyContent"><id>body-' . $key . '</id><property name="content"><id>page-' . $key . '</id></property>'
        . '<property name="body">' . htmlspecialchars($body, ENT_XML1) . '</property></object>';
    $xml = str_replace('</hibernate-generic>', $page . '</hibernate-generic>', $xml);
}
$zip->addFromString('entities.xml', $xml);
$zip->close();
$newJob = static function (?string $archive = null) use ($repo, $scanner, $access, $calendars, $path, $uid): array {
    $path = $archive ?? $path;
    $job = $repo->createUploadJob('workflow.zip', $path, filesize($path), 0, $uid, time() + 3600);
    $repo->saveScan((int)$job['id'], $scanner->scan($path));
    $access->prepare($job['uuid'], $uid);
    $calendars->prepare($job['uuid'], $uid);
    return $job;
};
$business = static function () use ($db): array {
    $rows = [];
    foreach (['auth_users', 'auth_groups', 'calendar_calendars', 'calendar_events', 'workspaces', 'workspace_nodes'] as $table) {
        $rows[$table] = $db->table($table)->get();
    }
    return $rows;
};
$run = static function (array $job, array $input) use ($workflow, $actor, $uid, $assert, $business, $uploads, $repo, $cfg): array {
    $before = $business();
    $workflow->draft($job['uuid'], $uid, $input);
    $workflow->review($job['uuid'], $actor, $input);
    $assert($before === $business(), 'Preview wrote business rows.');
    $state = $workflow->state($job['uuid'], $uid);
    $workflow->draft($job['uuid'], $uid, $input);
    try {
        $workflow->start($job['uuid'], $actor, $state['token']);
        throw new RuntimeException('An edited draft accepted the old confirmation.');
    } catch (AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException) {
        $assert($before === $business(), 'Stale confirmation wrote business rows.');
    }
    $workflow->review($job['uuid'], $actor, $input);
    $state = $workflow->state($job['uuid'], $uid);
    $progress = $workflow->start($job['uuid'], $actor, $state['token']);
    $assert($progress['phase'] === 'identities', 'Execution must begin with identities.');
    $assert(!$uploads->canCancel($repo->jobByUuid($job['uuid'], $uid)), 'Frozen identities phase remained cancellable.');
    $assert($workflow->start($job['uuid'], $actor, $state['token']) === $progress, 'Repeated confirmation changed the run.');
    try {
        $repo->assertSiteSetupMutable($repo->jobByUuid($job['uuid'], $uid));
        throw new RuntimeException('Component execution bypassed the coordinator.');
    } catch (AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException) {
        $assert(true, 'Coordinator guard checked.');
    }
    $lock = fopen($cfg->uploadDirectory() . '/' . $job['uuid'] . '.workflow.lock', 'c+b');
    flock($lock, LOCK_EX);
    $assert(($workflow->process($job['uuid'], $actor)['busy'] ?? false) === true, 'Concurrent request was not deferred.');
    flock($lock, LOCK_UN);
    fclose($lock);
    for ($i = 0; $i < 100 && !$progress['completed']; ++$i) {
        $progress = $workflow->process($job['uuid'], $actor);
    }
    $assert($progress['completed'], 'Unified workflow failed to finish.');
    $after = $business();
    $assert($workflow->process($job['uuid'], $actor)['completed'], 'Lost completion response cannot be resumed.');
    $assert($after === $business(), 'Repeated completion duplicated business data.');
    return $repo->jobByUuid($job['uuid'], $uid);
};
$input = ['source_url' => $url, 'space_ids' => ['1'], 'calendar_ids' => ['future-calendar'],
    'calendar_types' => ['future-calendar' => 'personal'], 'calendar_owners' => ['future-calendar' => 'internal-user:1'],
    'identities' => ['users' => ['internal-user:1' => '__create__'], 'groups' => [], 'memberships' => false],
    'content' => ['language' => 'en', 'spaces' => ['1' => ['name' => 'Workflow One', 'slug' => 'workflow-one-' . $suffix, 'strategy' => 'new']]]];
$first = $run($newJob(), $input);
$owner = $repo->mappedUserId(AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteAccessService::mappingKey($url, 'users', 'internal-user:1'));
$assert($owner !== null && $owner !== $uid, 'Future account was not created before content.');
$one = $repo->withSiteContext($url, [], fn() => $repo->contentBySource('ONE', 'page-ONE'));
$document = $db->table('editor_html_documents')->where('document_key', '=', $one['target_document_key'])->first();
$assert((int)$document['created_by_user_id'] === $owner && (int)$document['updated_by_user_id'] === $owner, 'Author/editor was not mapped before content.');
$oldChild = $repo->jobByUuid(array_values($first['options']['site_content']['children'])[0], $uid);
$beforeReport = $overviewService->overview($first);
$assert(($beforeReport['totals']['links_unresolved'] ?? 0) === 1, 'Missing cross-space link not reported.');
$input['space_ids'] = ['2'];
$input['identities']['users']['internal-user:1'] = (string)$owner;
$input['content']['spaces'] = ['2' => ['name' => 'Workflow Two', 'slug' => 'workflow-two-' . $suffix, 'strategy' => 'new']];
$input['calendar_ids'] = ['calendar-one'];
$input['calendar_types'] = ['calendar-one' => 'personal'];
$input['calendar_owners'] = ['calendar-one' => 'internal-user:1'];
$second = $run($newJob(), $input);
$afterReport = $overviewService->overview($repo->jobByUuid($first['uuid'], $uid));
$assert(($afterReport['totals']['links_unresolved'] ?? 0) === 0 && ($afterReport['totals']['links_resolved'] ?? 0) === 1, 'Old space report did not reflect later resolution.');
$assert(($overviewService->overview($second)['totals']['links_unresolved'] ?? 0) === 0, 'New space failed to link to an older space.');
$updatedChild = $repo->jobByUuid($oldChild['uuid'], $uid);
$issues = array_merge(...array_column($updatedChild['summary']['review_pages'], 'issues'));
$calendarIssues = array_values(array_filter($issues, static fn(array $issue): bool => $issue['type'] === 'calendar'));
$assert(count($calendarIssues) === 1 && $calendarIssues[0]['resolved'], 'Later calendar was not activated on an older imported page.');
$assert(
    count($overviewService->overview($first)['reports']) === 3 && count($overviewService->overview($second)['reports']) === 3,
    'Identity/calendar/per-space reports were collapsed or lost.'
);

// EN: Mix a real standalone space import with a later site import from the same verified source.
// Both directions and the earlier standalone report/calendar marker must reconcile without moving mappings.
//
// HR: Miješa stvarni samostalni uvoz područja s kasnijim uvozom sitea iz istog potvrđenog izvora.
// Oba smjera i raniji samostalni izvještaj/oznaka kalendara moraju se uskladiti bez premještanja mapiranja.
$spaceXml = static function (string $key, string $id, string $other, bool $calendar = false): string {
    $body = '<ac:link><ri:page ri:space-key="' . $other . '" ri:content-title="Home ' . $other . '"/></ac:link>';
    if ($calendar) {
        $body .= '<ac:structured-macro ac:name="calendar"><ac:parameter ac:name="id">future-calendar</ac:parameter></ac:structured-macro>';
    }
    return '<hibernate-generic><object class="Space"><id>' . $id . '</id><property name="key">' . $key . '</property><property name="name">' . $key . '</property></object>'
        . '<object class="InternalUser"><id>1</id><property name="name">alice</property></object>'
        . '<object class="ConfluenceUserImpl"><id>alice-key</id><property name="name">alice</property></object>'
        . '<object class="Page"><id>page-' . $key . '</id><property name="space"><id>' . $id . '</id></property><property name="title">Home ' . $key . '</property><property name="version">1</property></object>'
        . '<object class="BodyContent"><id>body-' . $key . '</id><property name="content"><id>page-' . $key . '</id></property><property name="body">' . htmlspecialchars($body, ENT_XML1) . '</property></object></hibernate-generic>';
};
$singlePath = $cfg->uploadDirectory() . '/mixed-space-' . $suffix . '.zip';
$thirdKey = 'THREE' . $suffix;
$fourthKey = 'FOUR' . $suffix;
$thirdId = (string)random_int(1000000, 2000000000);
$zip->open($singlePath, ZipArchive::CREATE);
$zip->addFromString('exportDescriptor.properties', "exportType=space\nspaceKey=" . $thirdKey . "\nbackupAttachments=false\n");
$zip->addFromString('entities.xml', $spaceXml($thirdKey, $thirdId, $fourthKey, true));
$zip->close();
$single = $repo->createUploadJob('mixed-space.zip', $singlePath, filesize($singlePath), 0, $uid, time() + 3600);
$repo->saveScan((int)$single['id'], $scanner->scan($singlePath));
$engine = $c->get(AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportService::class);
$engine->queue($single['uuid'], ['workspace_name' => 'Mixed Three', 'workspace_slug' => 'mixed-three-' . $suffix,
    'source_base_url' => $url, 'language' => 'en'], $actor);
for ($i = 0; $i < 100; ++$i) {
    if ($engine->process($single['uuid'], $actor)['completed']) {
        break;
    }
}
$assert(count($repo->unresolvedLinksForJob((int)$single['id'])) === 1, 'Standalone forward link not recorded.');
$path = $cfg->uploadDirectory() . '/mixed-site-' . $suffix . '.zip';
AaiEduHr\SimbiozaModuleConfluenceImport\Tests\Support\TeamCalendarsFixture::archive($path, $tables);
$zip->open($path);
$zip->addFromString('entities.xml', $spaceXml($fourthKey, '4', $thirdKey));
$zip->close();
$input['space_ids'] = ['4'];
$input['content']['spaces'] = ['4' => ['name' => 'Mixed Four', 'slug' => 'mixed-four-' . $suffix, 'strategy' => 'new']];
$input['calendar_ids'] = $input['calendar_types'] = $input['calendar_owners'] = [];
$mixed = $run($newJob($path), $input);
$assert($repo->unresolvedLinksForJob((int)$single['id']) === [], 'Site import did not update the earlier standalone report.');
$assert(($overviewService->overview($mixed)['totals']['links_unresolved'] ?? 0) === 0, 'Site page failed to link to the standalone page.');
$singleReport = $repo->jobByUuid($single['uuid'], $uid);
$singleIssues = array_merge(...array_column($singleReport['summary']['review_pages'], 'issues'));
$singleCalendars = array_values(array_filter($singleIssues, static fn(array $issue): bool => $issue['type'] === 'calendar'));
$assert(count($singleCalendars) === 1 && $singleCalendars[0]['resolved'], 'Earlier standalone calendar marker was not activated.');
echo json_encode(['workflow' => 'passed', 'document_store' => $store, 'two_way_links' => 'passed', 'older_reports' => 'passed',
    'deferred_user_authorship' => 'passed', 'later_calendar_embed' => 'passed', 'mixed_standalone_site' => 'passed'], JSON_THROW_ON_ERROR), PHP_EOL;
