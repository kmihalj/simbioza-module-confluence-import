<?php

declare(strict_types=1);

// EN: Synthetic localhost probe only; never install this file in a public application.
//
// HR: Samo sintetička localhost provjera; ovu datoteku ne instalirati u javnu aplikaciju.
$root = dirname(__DIR__);
if (preg_match('~^/srv/simbioza-c5-acceptance/(normal|secure|modphp)$~D', $root) !== 1) {
    throw new RuntimeException('Use only the disposable C5 HTTP fixture.');
}
$store = ($_GET['store'] ?? '') === 'database' ? 'database' : 'filesystem';
if (basename($root) === 'secure' && is_writable($root . '/src')) {
    throw new RuntimeException('Secured code is writable by the FPM worker.');
}
header('Content-Type: text/plain; charset=utf-8');
$argv = ['site_acceptance.php', $root, $store];
require $root . '/vendor/aaieduhr/simbioza-module-confluence-import/tests/Fixtures/site_acceptance.php';
echo json_encode(['sapi' => PHP_SAPI, 'mode' => basename($root), 'code_writable' => is_writable($root . '/src'),
    'config_writable' => is_writable(getenv('HPH_CONFIG_PATH') ?: $root . '/config'), 'data_writable' => is_writable($root . '/data')], JSON_THROW_ON_ERROR), PHP_EOL;
