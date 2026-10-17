<?php

declare(strict_types=1);

// EN: Relocates fictional fixture paths only; it never reads the HFC configuration or real database.
//
// HR: Premješta samo izmišljene putanje fixturea; ne čita HFC konfiguraciju ili stvarnu bazu.
$root = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || preg_match('~^/srv/simbioza-c5-acceptance/(normal|secure|modphp)$~D', $root) !== 1 || !is_dir($root . '/vendor')) {
    throw new RuntimeException('Prepared C5 fixture required.');
}
$database = ['default' => 'default', 'connections' => ['default' => ['driver' => 'sqlite', 'database' => $root . '/data/matrix.sqlite']]];
file_put_contents($root . '/config/database.php', '<?php return ' . var_export($database, true) . ';');
$settings = $root . '/config/editor-html/editor-html.json';
$data = json_decode(file_get_contents($settings), true, 512, JSON_THROW_ON_ERROR);
$data['storage']['driver'] = 'filesystem';
file_put_contents($settings, json_encode($data, JSON_THROW_ON_ERROR));
copy(__DIR__ . '/site_http_probe.php', $root . '/public/c5-probe.php');
