<?php

declare(strict_types=1);

// EN: Cross-site restore of synthetic C5 data only; both roots must be disposable clean-matrix installations.
//
// HR: Vraćanje samo sintetičkih C5 podataka na drugi site; oba korijena moraju biti potrošne clean-matrix instalacije.
$source = realpath($argv[1] ?? '');
$target = realpath($argv[2] ?? '');
$allowed = realpath(sys_get_temp_dir() . '/heartphrame-clean-matrix');
foreach ([$source, $target] as $root) {
    if (!is_string($root) || !is_string($allowed) || dirname($root) !== $allowed || preg_match('/^(?:e2e-all|all)-[a-f0-9]+$/D', basename($root)) !== 1) {
        throw new RuntimeException('Disposable clean-matrix roots required.');
    }
}
if ($source === $target) {
    throw new RuntimeException('Use two different disposable sites.');
}
require $source . '/vendor/autoload.php';
putenv('HPH_APP_PATH=' . $source);
putenv('HPH_CONFIG_PATH=' . $source . '/config');
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['HTTP_HOST'] = 'c5-source.test';
$_SERVER['REQUEST_URI'] = '/';
$app = new HeartPhrame\App($source . '/config');
$app->loadCommands();
$container = $app->getContainer();
$db = $container->get(AaiEduHr\HeartPhrameModuleOrm\Database\Database::class);
$parent = $db->table('simbioza_confluence_import_jobs')->where('operation', '=', 'site_import')->where('status', '=', 'completed')->orderBy('id', 'DESC')->first();
if (!is_array($parent)) {
    throw new RuntimeException('Run site_acceptance.php on the source first.');
}
$repo = $container->get(AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportRepository::class);
$before = $container->get(AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteReportService::class)->overview($repo->jobByUuid($parent['uuid']));
$scope = new AaiEduHr\HeartPhrameModuleBackup\Value\BackupScope('site');
$passphrase = 'C5-synthetic-backup-only!';
$archive = $container->get(AaiEduHr\HeartPhrameModuleBackup\Service\BackupManager::class)->create(
    new AaiEduHr\HeartPhrameModuleBackup\Value\BackupExportContext($scope, [], options: ['site_base_url' => 'https://c5-source.test'], actorUserId: (int)$parent['actor_user_id'], passphrase: $passphrase),
    'c5-portable-site'
);
putenv('HPH_APP_PATH=' . $target);
putenv('HPH_CONFIG_PATH=' . $target . '/config');
$_SERVER['HTTP_HOST'] = 'c5-destination.test';
$destination = new HeartPhrame\App($target . '/config');
$destination->loadCommands();
$targetContainer = $destination->getContainer();
$manager = $targetContainer->get(AaiEduHr\HeartPhrameModuleBackup\Service\BackupManager::class);
$context = new AaiEduHr\HeartPhrameModuleBackup\Value\BackupImportContext($scope, 'merge', [], options: ['site_base_url' => 'https://c5-destination.test'], passphrase: $passphrase);
$preflight = $manager->preflight($archive, $context);
if (!$preflight->isAllowed()) {
    throw new RuntimeException('Cross-site preflight failed: ' . implode('; ', $preflight->errors));
}
$manager->restore($archive, $context);
$targetRepo = $targetContainer->get(AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceImportRepository::class);
$restored = $targetRepo->jobByUuid($parent['uuid']);
$after = $targetContainer->get(AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteReportService::class)->overview($restored);
if ($restored['archive_path'] !== '' || $after['unavailable_reports'] !== 0 || count($before['reports']) !== count($after['reports'])) {
    throw new RuntimeException('Cross-site restore lost linked reports or retained local source paths.');
}
$sourceUrl = $before['source_url'];
if ($targetRepo->calendarMappings($sourceUrl) === []) {
    throw new RuntimeException('Calendar source aliases were not restored.');
}
echo json_encode(['cross_site_backup' => 'passed', 'linked_reports' => count($after['reports']), 'local_source_paths' => 'absent'], JSON_THROW_ON_ERROR), PHP_EOL;
