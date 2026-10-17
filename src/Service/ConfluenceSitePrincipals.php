<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use AaiEduHr\SimbiozaModuleConfluenceImport\Value\ConfluenceObject;

/**
 * EN: Streams a site's identity/access metadata into a bounded private sidecar.
 * Only explicit allowlisted fields survive parsing; passwords and tokens never enter the catalog.
 *
 * HR: Tokom čita metapodatke identiteta/pristupa sitea u ograničeni privatni indeks.
 * Nakon parsiranja ostaju samo izričito navedena polja; lozinke i tokeni ne ulaze u pregled.
 */
final readonly class ConfluenceSitePrincipals
{
    private const VERSION = 2;
    private const CLASSES = ['InternalUser', 'ConfluenceUserImpl', 'InternalGroup', 'DirectoryImpl',
        'HibernateMembership', 'SpacePermission', 'ContentPermission', 'ContentPermissionSet'];

    /**
     * EN: Receives ZIP streaming, metadata parsing and private storage policy.
     *
     * HR: Prima tok ZIP podataka, parsiranje metapodataka i pravila privatnog spremišta.
     */
    public function __construct(
        private ConfluenceArchive $archive,
        private ConfluenceExportReader $reader,
        private ConfluenceImportConfig $config,
    ) {
    }

    /**
     * EN: Reads only complete indexes whose source file identity is still unchanged.
     * This inexpensive read never starts a long scan from an ordinary page GET.
     *
     * HR: Čita samo potpune indekse čiji identitet izvorne datoteke nije promijenjen.
     * Ovaj brzi dohvat nikada ne pokreće dugo čitanje iz običnog GET zahtjeva stranice.
     *
     * @return array<string,mixed>|null
     */
    public function cached(string $archivePath, string $sourceHash): ?array
    {
        $path = $this->indexPath($archivePath);
        if (!is_file($path) || filesize($path) > $this->config->maxSiteIdentityIndexSize()) {
            return null;
        }
        $data = json_decode((string)file_get_contents($path), true);
        if (
            !is_array($data) || ($data['version'] ?? null) !== self::VERSION
            || ($data['source_sha256'] ?? null) !== $sourceHash
            || ($data['source_stat'] ?? null) !== $this->sourceStat($archivePath)
            || !is_array($data['users'] ?? null) || !is_array($data['groups'] ?? null)
            || !is_array($data['memberships'] ?? null) || !is_array($data['permissions'] ?? null)
        ) {
            return null;
        }
        return $data;
    }

    /**
     * EN: Verifies source bytes before scanning and publishes only a complete atomic index.
     * A cancelled or replaced upload cannot publish a stale sidecar.
     *
     * HR: Prije čitanja provjerava bajtove izvora i objavljuje samo potpun atomski indeks.
     * Otkazani ili zamijenjeni upload ne može objaviti zastarjeli indeks.
     *
     * @return array<string,mixed>
     */
    public function scan(string $archivePath, string $sourceHash): array
    {
        $cached = $this->cached($archivePath, $sourceHash);
        if ($cached !== null) {
            return $cached;
        }
        if (function_exists('set_time_limit')) {
            try {
                set_time_limit($this->config->importExecutionTimeLimit());
            } catch (\Throwable) {
                // EN: Restricted hosts retain their server-side execution limit.
                //
                // HR: Ograničeni hostovi zadržavaju poslužiteljsko vremensko ograničenje.
            }
        }
        $this->archive->inspect($archivePath);
        if (
            preg_match('/^[a-f0-9]{64}$/D', $sourceHash) !== 1
            || !hash_equals($sourceHash, (string)hash_file('sha256', $archivePath))
        ) {
            throw new ConfluenceImportException(__('Izvornu arhivu nije moguće provjeriti.'));
        }
        $before = $this->sourceStat($archivePath);
        $users = $references = $groups = $directories = $memberships = $permissions = $sets = $counts = $seen = [];
        $retained = 0;
        $framer = new ConfluenceXmlInventoryFramer($this->config->maxInventoryObjectSize(), self::CLASSES);
        foreach ($framer->frames($this->archive->entityChunks($archivePath)) as $frame) {
            if ($frame['xml'] === null) {
                continue;
            }
            if (++$retained > $this->config->maxSiteIdentityRecords()) {
                throw new ConfluenceImportException(__('Broj identiteta i ovlasti sitea prelazi dopušteni limit.'));
            }
            $object = $this->reader->parseObject($frame['xml']);
            $class = $object->className;
            $counts[$class] = ($counts[$class] ?? 0) + 1;
            $id = $this->field($object->string('id'), 190);
            if ($id === '' || isset($seen[$class . ':' . $id])) {
                throw new ConfluenceImportException(__('Confluence identitet ima nevaljan ili ponovljen ID.'));
            }
            $seen[$class . ':' . $id] = true;
            if ($class === 'InternalUser') {
                $key = 'internal-user:' . $id;
                $this->unique($users, $key);
                $users[$key] = ['source_id' => $key, 'directory_id' => $this->field($object->reference('directory'), 190),
                    'username' => $this->field($object->string('name'), 255),
                    'display_name' => $this->field($object->string('displayName'), 255),
                    'first_name' => $this->field($object->string('firstName'), 255),
                    'last_name' => $this->field($object->string('lastName'), 255),
                    'email' => $this->field($object->string('emailAddress'), 255),
                    'account_active' => ConfluenceSourceActivity::flag($object->string('active')), 'aliases' => []];
            } elseif ($class === 'ConfluenceUserImpl') {
                $this->unique($references, $id);
                $references[$id] = $this->field($object->string('name'), 255);
            } elseif ($class === 'InternalGroup') {
                $key = 'internal-group:' . $id;
                $this->unique($groups, $key);
                $groups[$key] = ['source_id' => $key, 'directory_id' => $this->field($object->reference('directory'), 190),
                    'name' => $this->field($object->string('name'), 255),
                    'account_active' => ConfluenceSourceActivity::flag($object->string('active'))];
            } elseif ($class === 'DirectoryImpl') {
                $this->unique($directories, $id);
                $directories[$id] = ['name' => $this->field($object->string('name'), 255),
                    'active' => ConfluenceSourceActivity::flag($object->string('active')),
                    'type' => $this->field($object->string('type'), 64)];
            } elseif ($class === 'HibernateMembership') {
                $memberships[] = ['source_id' => $id,
                    'group_id' => 'internal-group:' . $this->field($object->reference('parentGroup'), 190),
                    'user_id' => $object->reference('userMember') !== ''
                        ? 'internal-user:' . $this->field($object->reference('userMember'), 190) : '',
                    'child_group_id' => $object->reference('groupMember') !== ''
                        ? 'internal-group:' . $this->field($object->reference('groupMember'), 190) : ''];
            } elseif ($class === 'ContentPermissionSet') {
                $sets[$id] = ['type' => $this->field($object->string('type'), 64),
                    'content_id' => $this->field($object->reference('owningContent'), 190)];
            } else {
                $permissions[] = ['source_id' => $id, 'kind' => $class,
                    'type' => $this->field($object->string('type'), 64),
                    'space_id' => $this->field($object->reference('space'), 190),
                    'set_id' => $this->field($object->reference('owningSet'), 190),
                    'user_key' => $this->field($object->reference('userSubject'), 190),
                    'group_name' => $this->field($object->string('group') ?: $object->string('groupName'), 255)];
            }
        }
        $names = [];
        foreach ($users as $key => $user) {
            if ($user['username'] !== '') {
                $names[strtolower($user['username'])][] = $key;
            }
        }
        foreach ($references as $id => $name) {
            $key = 'confluence-user:' . $id;
            $candidates = $name !== '' ? ($names[strtolower($name)] ?? []) : [];
            if (count($candidates) === 1) {
                $users[$candidates[0]]['aliases'][] = $key;
            } else {
                $users[$key] = ['source_id' => $key, 'directory_id' => '', 'username' => $name,
                    'display_name' => $name, 'first_name' => '', 'last_name' => '', 'email' => '',
                    'account_active' => null, 'aliases' => [], 'reference_only' => true];
            }
        }
        foreach ($users as &$user) {
            $directory = $directories[$user['directory_id'] ?? ''] ?? [];
            $user['directory_name'] = $directory['name'] ?? '';
            $user['directory_active'] = $directory['active'] ?? null;
            $user['directory_type'] = $directory['type'] ?? '';
            $status = ConfluenceSourceActivity::status($user);
            $user['active'] = $status === 'unknown' ? null : $status === 'active';
        }
        unset($user);
        foreach ($groups as &$group) {
            $directory = $directories[$group['directory_id']] ?? [];
            $group['directory_name'] = $directory['name'] ?? '';
            $group['directory_active'] = $directory['active'] ?? null;
            $group['directory_type'] = $directory['type'] ?? '';
            $status = ConfluenceSourceActivity::status($group);
            $group['active'] = $status === 'unknown' ? null : $status === 'active';
        }
        unset($group);
        $data = ['version' => self::VERSION, 'source_sha256' => $sourceHash, 'source_stat' => $before,
            'users' => array_values($users), 'groups' => array_values($groups), 'memberships' => $memberships,
            'permissions' => $permissions, 'permission_sets' => $sets, 'counts' => $counts];
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > $this->config->maxSiteIdentityIndexSize()) {
            throw new ConfluenceImportException(__('Indeks identiteta sitea prelazi dopuštenu veličinu.'));
        }
        if ($before !== $this->sourceStat($archivePath)) {
            throw new ConfluenceImportException(__('Izvornu arhivu nije moguće provjeriti.'));
        }
        $path = $this->indexPath($archivePath);
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        try {
            if (
                file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)
                || !chmod($temporary, 0660) || !rename($temporary, $path)
            ) {
                throw new ConfluenceImportException(__('Confluence indeks nije moguće spremiti.'));
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        return $data;
    }

    /**
     * EN: Uses only the managed upload directory; a caller cannot redirect a sidecar through a symlink.
     *
     * HR: Koristi samo upravljani upload direktorij; pozivatelj ne može preusmjeriti indeks symlinkom.
     */
    private function indexPath(string $archivePath): string
    {
        $directory = realpath($this->config->uploadDirectory());
        if (
            !is_string($directory) || is_link($this->config->dataDirectory())
            || is_link($this->config->uploadDirectory()) || is_link($archivePath) || !is_file($archivePath)
            || realpath(dirname($archivePath)) !== $directory || basename($archivePath) !== basename(realpath($archivePath) ?: '')
            || is_link($archivePath . '.principals.json')
        ) {
            throw new ConfluenceImportException(__('Putanja Confluence indeksa nije valjana.'));
        }
        return $archivePath . '.principals.json';
    }

    /**
     * EN: Detects replaced or changed managed archives without rereading gigabytes on every GET.
     *
     * HR: Otkriva zamijenjene ili izmijenjene arhive bez ponovnog čitanja gigabajta pri svakom GET-u.
     *
     * @return array<string,int>
     */
    private function sourceStat(string $path): array
    {
        clearstatcache(true, $path);
        $stat = is_file($path) && !is_link($path) ? stat($path) : false;
        if (!is_array($stat)) {
            throw new ConfluenceImportException(__('Izvornu arhivu nije moguće provjeriti.'));
        }
        return array_intersect_key($stat, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']));
    }

    /**
     * EN: Rejects oversized/NUL-bearing metadata instead of silently truncating identities.
     *
     * HR: Odbija preduge metapodatke i NUL bajtove umjesto prešutnog skraćivanja identiteta.
     */
    private function field(string $value, int $limit): string
    {
        if (strlen($value) > $limit || str_contains($value, "\0")) {
            throw new ConfluenceImportException(__('Confluence metapodaci identiteta nisu valjani.'));
        }
        return $value;
    }

    /**
     * EN: Requires unique source IDs in each retained identity class.
     *
     * HR: Zahtijeva jedinstvene izvorne ID-jeve u svakoj zadržanoj klasi identiteta.
     *
     * @param array<array-key,mixed> $rows
     */
    private function unique(array $rows, string $id): void
    {
        if (array_key_exists($id, $rows)) {
            throw new ConfluenceImportException(__('Confluence identitet ima nevaljan ili ponovljen ID.'));
        }
    }
}
