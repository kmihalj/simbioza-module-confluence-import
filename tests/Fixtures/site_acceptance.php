<?php

declare(strict_types=1);

// EN: Synthetic full-service validation restricted to a disposable clean-matrix installation.
//
// HR: Sintetička provjera svih servisa ograničena na potrošnu clean-matrix instalaciju.
$root = realpath($argv[1] ?? '');
$allowed = realpath(sys_get_temp_dir() . '/heartphrame-clean-matrix');
if (!is_string($root) || !(is_string($allowed) && dirname($root) === $allowed && str_starts_with(basename($root), 'e2e-all-')) && preg_match('~^/srv/simbioza-c5-acceptance/(normal|secure|modphp)$~D', $root) !== 1) {
    throw new RuntimeException('Disposable matrix application required.');
}
$store = $argv[2] ?? 'filesystem';
if (!in_array($store, ['filesystem', 'database'], true)) {
    throw new RuntimeException('Unsupported document store.');
}
$configPath = getenv('HPH_CONFIG_PATH') ?: $root . '/config';
if (realpath($configPath) !== $root . '/config' && ($root !== '/srv/simbioza-c5-acceptance/secure' || realpath($configPath) !== '/srv/simbioza-c5-acceptance/secure-config')) {
    throw new RuntimeException('Configuration must belong to the disposable fixture.');
}
$settingsPath = $configPath . '/editor-html/editor-html.json';
$settings = is_file($settingsPath) ? json_decode(file_get_contents($settingsPath), true, 512, JSON_THROW_ON_ERROR) : [];
if (($settings['storage']['driver'] ?? '') !== $store) {
    $settings['storage']['driver'] = $store;
    if (!is_dir(dirname($settingsPath))) {
        mkdir(dirname($settingsPath), 0770, true);
    }
    file_put_contents($settingsPath, json_encode($settings, JSON_THROW_ON_ERROR));
}
putenv('HPH_APP_PATH=' . $root);
putenv('HPH_CONFIG_PATH=' . $configPath);
putenv('HPH_CONFIG_OVERRIDE_PATHS');
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
require $root . '/vendor/autoload.php';
require __DIR__ . '/../Support/TeamCalendarsFixture.php';
$app = new HeartPhrame\App($root . '/config');
$app->loadCommands();
$c = $app->getContainer();
$db = $c->get(AaiEduHr\HeartPhrameModuleOrm\Database\Database::class);
if ($c->get(AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorHtmlConfig::class)->storageDriver() !== $store) {
    throw new RuntimeException('The selected document store was not loaded.');
}
$repo = $c->get(AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportRepository::class);
$access = $c->get(AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteAccessService::class);
$site = $c->get(AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteContentService::class);
$calendars = $c->get(AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteCalendarService::class);
$manager = $c->get(AaiEduHr\HeartPhrameModuleCalendar\Service\CalendarManagerInterface::class);
$scanner = $c->get(AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceExportScanner::class);
$uploads = $c->get(AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportUploadService::class);
$cfg = $c->get(AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportConfig::class);
$users = $c->get(AaiEduHr\HeartPhrameModuleAuth\Service\AuthUserService::class);
$actor = $users->findByLoginIdentifier('e2e-admin');
$targetUser = $users->findByLoginIdentifier('e2e-user');
if (!(bool)($actor['is_admin'] ?? false) || !is_array($targetUser)) {
    throw new RuntimeException('E2E users required.');
}
$uid = (int)$actor['id'];
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$events = [];
$dispatcher = $c->get(Psr\EventDispatcher\EventDispatcherInterface::class);
$actorContext = $c->get(AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorApiActorContext::class);
$path = $cfg->uploadDirectory() . '/c4-synthetic-' . bin2hex(random_bytes(4)) . '.zip';
$tables = AaiEduHr\SimbiozaModuleConfluenceImport\Tests\Support\TeamCalendarsFixture::tables();
$tables['SUBCALS'][0]['COLOUR'] = 'subcalendar-green2';
$tables['EVENTS'][0]['RECURRENCE_RULE'] = 'FREQ=WEEKLY;COUNT=4;BYDAY=MO';
$tables['EVENTS'][] = array_replace($tables['EVENTS'][0], ['ID' => 'moved', 'RECURRENCE_ID_TIMESTAMP' => '1782723600000', 'START' => '1782810000000', 'UTC_START' => '1782810000000', 'END' => '1782813600000', 'UTC_END' => '1782813600000']);
$tables['SUBCALS'][] = array_replace($tables['SUBCALS'][0], ['ID' => 'personal-calendar', 'NAME' => 'Personal imported']);
$tables['EVENTS'][] = array_replace($tables['EVENTS'][0], ['ID' => 'personal-event', 'SUB_CALENDAR_ID' => 'personal-calendar', 'VEVENT_UID' => 'personal-event@test']);
AaiEduHr\SimbiozaModuleConfluenceImport\Tests\Support\TeamCalendarsFixture::archive($path, $tables);
$zip = new ZipArchive();
$zip->open($path);
$xml = $zip->getFromName('entities.xml');
$body = '<p>C4 calendar page</p><ac:structured-macro ac:name="calendar"><ac:parameter ac:name="id">calendar-one</ac:parameter></ac:structured-macro>';
$xml = str_replace('</hibernate-generic>', '<object class="Page"><id>c4-page</id><property name="space"><id>1</id></property><property name="title">C4 calendar page</property><property name="version">1</property></object><object class="BodyContent"><id>c4-body</id><property name="content"><id>c4-page</id></property><property name="body">' . htmlspecialchars($body, ENT_XML1) . '</property></object></hibernate-generic>', $xml);
$zip->addFromString('entities.xml', $xml);
$zip->close();
$job = $repo->createUploadJob('c4-synthetic.zip', $path, filesize($path), 0, $uid, time() + 3600);
$scan = $scanner->scan($path);
$repo->saveScan((int)$job['id'], $scan);
$url = 'https://c4-source-' . bin2hex(random_bytes(4)) . '.test';
$access->prepare($job['uuid'], $uid);
$localGroup = $c->get(AaiEduHr\HeartPhrameModuleAuth\Service\AuthGroupService::class)->createGroupForApi('C4 staff ' . bin2hex(random_bytes(3)), true);
$preview = $access->review($job['uuid'], $uid, $url, ['internal-user:1' => (string)$targetUser['id']], ['internal-group:11' => (string)$localGroup['id']], false);
$access->apply($job['uuid'], $uid, $preview['token']);
$authBefore = $db->table('auth_users')->get();
$calendars->prepare($job['uuid'], $uid);
$calendars->review($job['uuid'], $actor, $url, ['calendar-one', 'personal-calendar'], ['calendar-one' => 'team', 'personal-calendar' => 'personal'], ['personal-calendar' => 'internal-user:1']);
$plan = $calendars->state($job['uuid'], $uid)['plan'];
$calendars->start($job['uuid'], $actor, $plan['token']);
$result = $calendars->process($job['uuid'], $actor);
$assert(!$result['completed'], 'Second calendar must have its own batch.');
$result = $calendars->process($job['uuid'], $actor);
$assert($result['completed'], 'Calendar import not completed.');
$mappings = $repo->calendarMappings($url);
$calendar = $manager->calendar($mappings['calendar-one'], $actor);
$personal = $manager->calendar($mappings['personal-calendar'], $actor);
$assert($calendar['calendar_type'] === 'team', 'Explicit team choice lost.');
$assert($personal['calendar_type'] === 'personal' && (int)$personal['owner_user_id'] === (int)$targetUser['id'], 'Mapped personal owner lost.');
$assert($calendar['color'] === '#00875A', 'Named Confluence palette color lost.');
$assert($db->table('calendar_calendar_followers')->whereIn('calendar_id', [(int)$calendar['id'], (int)$personal['id']])->where('user_id', '=', $uid)->get() === [], 'The importing administrator was subscribed.');
$assert(count($db->table('calendar_calendar_followers')->where('calendar_id', '=', (int)$personal['id'])->where('user_id', '=', (int)$targetUser['id'])->get()) === 1, 'The personal owner was not subscribed.');
$assert($manager->calendar((int)$personal['id'], $targetUser) !== null, 'Personal owner lacks access.');
$assert($calendar['is_public_read'] === false && $calendar['is_authenticated_read'] === false, 'Unexpected public access.');
$assert($manager->calendar($mappings['calendar-one'], $targetUser) !== null, 'Mapped source owner lacks access.');
$rows = $db->table('calendar_events')->where('calendar_id', '=', $calendar['id'])->get();
$assert(count($rows) === 2, 'Master/exception events missing.');
$assert(array_unique(array_column($rows, 'timezone')) === ['Europe/Zagreb'], 'Timezone lost.');
$types = array_unique(array_column($rows, 'event_type_id'));
$assert(count($types) === 1, 'Imported type not reused.');
$type = $db->table('calendar_event_types')->where('id', '=', $types[0])->first();
$assert($type['label'] === 'Meeting', 'Event type label lost.');
$range = $manager->eventsForCalendar((int)$calendar['id'], '2026-06-01 00:00:00', '2026-07-31 23:59:59', $actor, true);
$starts = array_column($range, 'starts_at');
$assert(!in_array('2026-06-29 11:00:00', $starts, true), 'Moved master occurrence not excluded.');
$assert(in_array('2026-06-30 11:00:00', $starts, true), 'Moved occurrence missing.');
$calendars->review($job['uuid'], $actor, $url, ['calendar-one'], ['calendar-one' => 'team']);
$calendars->start($job['uuid'], $actor, $calendars->state($job['uuid'], $uid)['plan']['token']);
$assert($calendars->process($job['uuid'], $actor)['completed'], 'Repeat import failed.');
$assert(count($db->table('calendar_events')->where('calendar_id', '=', $calendar['id'])->get()) === 2, 'Repeat import duplicated events.');
$uploads->saveInventorySelection($job['uuid'], $uid, $scan['source_sha256'], ['1']);
$site->review($job['uuid'], $uid, ['source_url' => $url, 'language' => 'en', 'spaces' => ['1' => ['name' => 'C4 integrated', 'slug' => 'c4-integrated-' . bin2hex(random_bytes(3)), 'strategy' => 'new']]]);
$site->queue($job['uuid'], $uid, $site->state($job['uuid'], $uid)['plan']['token']);
for ($i = 0; $i < 50; ++$i) {
    $progress = $site->process($job['uuid'], $actor);
    if ($progress['completed']) {
        break;
    }
}
$assert($progress['completed'], 'C3 after C4 did not complete.');
$pageMapping = $repo->withSiteContext($url, [], fn() => $repo->contentBySource('ONE', 'c4-page'));
$document = $db->table('editor_html_documents')->where('document_key', '=', $pageMapping['target_document_key'])->first();
$versions = $db->table('editor_html_document_versions')->where('document_id', '=', $document['id'])->get();
$assert($versions !== [] && array_unique(array_column($versions, 'storage_driver')) === [$store], 'Imported HTML used the wrong document store.');
$linked = $calendars->linkPages($job['uuid'], $actor);
$assert($linked['linked'] === 1 && $linked['unresolved'] === [], 'Imported page calendar not resolved.');
$assert($calendars->linkPages($job['uuid'], $actor)['linked'] === 0, 'Resolved page was rewritten twice.');
$assert($authBefore === $db->table('auth_users')->get(), 'C4 changed existing Auth users.');
echo "C4 real Calendar API: explicit personal/team types, mapped personal owner, mapped group ACL, TZID, event types, recurrence exception, repeat import, C3 page linking and unchanged Auth passed.\n";

$parent = $repo->jobByUuid($job['uuid'], $uid);
$overviewService = $c->get(AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteReportService::class);
$overview = $overviewService->overview($parent);
$assert(count($overview['reports']) === 4, 'Combined C2/C3/repeated-C4 reports are missing.');
$assert($overview['unavailable_reports'] === 0, 'Combined report references are unavailable.');
$portable = AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteJobOptions::portable($parent['options_json']);
$restored = $parent;
$restored['options'] = json_decode($portable, true, 512, JSON_THROW_ON_ERROR);
$restored['archive_path'] = '';
$assert($overviewService->overview($restored)['reports'] === $overview['reports'], 'Portable report links were lost.');
$uploads->releaseSiteSource($job['uuid'], $uid);
$assert(!is_file($path) && !is_dir($path . '.calendars') && !is_dir($path . '.content'), 'Source artifacts remain after explicit cleanup.');
$after = $repo->jobByUuid($job['uuid'], $uid);
$assert($overviewService->overview($after)['reports'] === $overview['reports'], 'Cleanup lost reports.');
$assert($manager->calendar($calendar['id'], $actor) !== null, 'Cleanup removed a native calendar.');
echo json_encode(['database' => $db->getDriverName(), 'document_store' => $store, 'site_calendars_reports_cleanup' => 'passed'], JSON_THROW_ON_ERROR), PHP_EOL;
