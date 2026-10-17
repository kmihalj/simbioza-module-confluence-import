<?php

declare(strict_types=1);

// EN: Branch CI temporarily aliases only declared dependencies and their runtime closure.
// Tag CI retains stable constraints; production metadata and Framework stay unchanged.
//
// HR: CI grane privremeno aliasira samo deklarirane ovisnosti i njihove runtime ovisnosti.
// CI taga zadržava stabilne uvjete; produkcijski metapodaci i Framework ostaju isti.

if (PHP_SAPI !== 'cli') {
    exit(2);
}
$root = dirname(__DIR__);
$manifest = json_decode((string)file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$stable = in_array('--stable', $argv, true);
if (!$stable) {
    $packages = [
        'aaieduhr/heartphrame-module-api' => 'https://gitlab.opencode.hr/srce/aai-eduhr/heartphrame/heartphrame-module-api.git',
        'aaieduhr/heartphrame-module-audit' => 'https://gitlab.opencode.hr/srce/aai-eduhr/heartphrame/heartphrame-module-audit.git',
        'aaieduhr/heartphrame-module-auth' => 'https://gitlab.opencode.hr/srce/aai-eduhr/heartphrame/heartphrame-module-auth.git',
        'aaieduhr/heartphrame-module-backup' => 'https://gitlab.opencode.hr/srce/aai-eduhr/heartphrame/heartphrame-module-backup.git',
        'aaieduhr/heartphrame-module-calendar' => 'https://gitlab.opencode.hr/srce/aai-eduhr/heartphrame/heartphrame-module-calendar.git',
        'aaieduhr/heartphrame-module-comment' => 'https://gitlab.opencode.hr/srce/aai-eduhr/heartphrame/heartphrame-module-comment.git',
        'aaieduhr/heartphrame-module-editor-html' => 'https://gitlab.opencode.hr/srce/aai-eduhr/heartphrame/heartphrame-module-html-editor.git',
        'aaieduhr/heartphrame-module-menu' => 'https://gitlab.opencode.hr/srce/aai-eduhr/heartphrame/heartphrame-module-menu.git',
        'aaieduhr/heartphrame-module-notification' => 'https://gitlab.opencode.hr/srce/aai-eduhr/heartphrame/heartphrame-module-notification.git',
        'aaieduhr/heartphrame-module-orm' => 'https://gitlab.opencode.hr/srce/aai-eduhr/heartphrame/heartphrame-module-orm.git',
        'aaieduhr/simbioza-module-user' => 'https://github.com/kmihalj/simbioza-module-user.git',
        'aaieduhr/simbioza-module-workspace' => 'https://github.com/kmihalj/simbioza-module-workspace.git',
        'aaieduhr/simbioza-module-workspace-search' => 'https://github.com/kmihalj/simbioza-module-workspace-search.git',
    ];
    $repositories = $manifest['repositories'] ?? [];
    foreach ($packages as $package => $url) {
        $section = isset($manifest['require'][$package]) ? 'require' : 'require-dev';
        $constraint = $manifest[$section][$package] ?? '^0.3.0';
        if (preg_match('/\A\^?(\d+\.\d+\.\d+)\z/D', $constraint, $match) !== 1) {
            throw new RuntimeException('Unsupported CI candidate constraint: ' . $package);
        }
        $manifest[$section][$package] = 'dev-main as ' . $match[1];
        $found = false;
        foreach ($repositories as $repository) {
            if (($repository['url'] ?? null) === $url) {
                $found = true;
            }
        }
        if (!$found) {
            $repositories[] = ['type' => 'vcs', 'url' => $url];
        }
    }
    $manifest['repositories'] = $repositories;
}
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($root . '/composer.ci.json', $json, LOCK_EX) === false) {
    throw new RuntimeException('Cannot write the disposable CI manifest.');
}
echo $stable ? "Stable dependency CI manifest ready.\n" : "Development dependency CI manifest ready.\n";
