<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\SimbiozaModuleConfluenceImport\Exception\ConfluenceImportException;
use AaiEduHr\SimbiozaModuleConfluenceImport\Value\ConfluenceObject;

use function array_key_exists;
use function array_values;
use function count;
use function html_entity_decode;
use function ksort;
use function preg_match_all;
use function sort;
use function strtolower;
use function trim;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/** HR: Izvodi read-only pregled prostora, sadržaja, identiteta, ACL-a, makroa i privitaka. EN: Builds a read-only inventory of space, content, identities, ACL, macros, and attachments. */
final readonly class ConfluenceExportScanner
{
    /** @var list<string> */
    private const SUPPORTED_MACROS = [
        'anchor',
        'attachments',
        'chart',
        'children',
        'content-report-table',
        'contentbylabel',
        'code',
        'create-from-template',
        'details',
        'detailssummary',
        'gallery',
        'include',
        'livesearch',
        'multimedia',
        'noformat',
        'info',
        'note',
        'pagetree',
        'pagetreesearch',
        'panel',
        'profile',
        'recently-updated',
        'recently-updated-dashboard',
        'status',
        'tip',
        'toc',
        'warning',
    ];

    /** HR: Prima provjereni arhiv i stream XML čitač. EN: Receives the validated archive and streaming XML reader. */
    public function __construct(
        private ConfluenceArchive $archive,
        private ConfluenceExportReader $reader,
    ) {
    }

    /**
     * HR: Skenira bez promjene baze ili sadržaja aplikacije.
     * EN: Scans without changing application data or content.
     *
     * @return array<string,mixed>
     */
    public function scan(string $archivePath): array
    {
        $archive = $this->archive->inspect($archivePath);
        $counts = [];
        $statuses = [];
        $macros = [];
        $spaces = [];
        $users = [];
        $groups = [];
        $pages = [];
        $attachments = [];
        $spacePermissions = [];
        $contentPermissionSets = [];
        $contentPermissions = [];
        $comments = 0;
        $labels = [];
        $labellings = [];

        foreach ($this->reader->objects($archivePath) as $object) {
            $counts[$object->className] = ($counts[$object->className] ?? 0) + 1;
            switch ($object->className) {
                case 'Space':
                    $spaces[] = $this->space($object);
                    break;
                case 'ConfluenceUserImpl':
                    $users[$object->string('id')] = $this->user($object);
                    break;
                case 'InternalUser':
                    $key = $object->string('id') ?: $object->string('name');
                    if ($key !== '') {
                        $users[$key] = [...($users[$key] ?? []), ...$this->user($object)];
                    }
                    break;
                case 'InternalGroup':
                    $name = $object->string('name');
                    if ($name !== '') {
                        $groups[$name] = ['source_name' => $name, 'target_group_id' => null];
                    }
                    break;
                case 'Page':
                    $page = $this->page($object);
                    $pages[] = $page;
                    $statusBucket = in_array($page['status'], ['draft', 'deleted'], true)
                        ? $page['status']
                        : ($page['original_version_id'] !== '' ? 'history' : 'current');
                    $statuses[$statusBucket] = ($statuses[$statusBucket] ?? 0) + 1;
                    break;
                case 'Attachment':
                    $attachments[] = $this->attachment($object);
                    $attachmentStatus = 'attachment_' . ($object->string('contentStatus') ?: 'current');
                    $statuses[$attachmentStatus] = ($statuses[$attachmentStatus] ?? 0) + 1;
                    break;
                case 'BodyContent':
                    $this->countMacros($object->string('body'), $macros);
                    break;
                case 'SpacePermission':
                    $permission = $this->permission($object);
                    $spacePermissions[] = $permission;
                    $this->collectPermissionGroup($permission, $groups);
                    break;
                case 'ContentPermissionSet':
                    $contentPermissionSets[] = $this->permissionSet($object);
                    break;
                case 'ContentPermission':
                    $permission = $this->permission($object);
                    $contentPermissions[] = $permission;
                    $this->collectPermissionGroup($permission, $groups);
                    break;
                case 'Comment':
                    ++$comments;
                    break;
                case 'Label':
                    $labelId = $object->string('id');
                    if ($labelId !== '') {
                        $labels[$labelId] = $object->string('name');
                    }
                    break;
                case 'Labelling':
                    $labellings[] = [
                        'page_id' => $object->reference('content') ?: $object->string('labelableId'),
                        'label_id' => $object->reference('label'),
                    ];
                    break;
            }
        }

        ksort($counts);
        ksort($statuses);
        ksort($macros);
        ksort($users);
        ksort($groups);

        if (count($spaces) !== 1) {
            throw new ConfluenceImportException(__('Arhiva mora sadržavati točno jedno Confluence područje.'));
        }

        $warnings = $this->warnings($pages, $users, $macros);
        $pageLabels = [];
        foreach ($labellings as $labelling) {
            $pageId = $labelling['page_id'];
            $label = $labels[$labelling['label_id']] ?? '';
            if ($pageId !== '' && $label !== '') {
                $pageLabels[$pageId][] = $label;
            }
        }

        return [
            'archive' => $archive,
            'source' => [
                'confluence_version' => $archive['descriptor']['createdByVersionNumber'] ?? '',
                'build_number' => $archive['descriptor']['buildNumber'] ?? '',
                'space_key' => $archive['descriptor']['spaceKey'] ?? '',
                'attachments' => strtolower($archive['descriptor']['backupAttachments'] ?? '') === 'true',
            ],
            'spaces' => $spaces,
            'pages' => $pages,
            'users' => array_values($users),
            'groups' => array_values($groups),
            'attachments' => $attachments,
            'space_permissions' => $spacePermissions,
            'content_permission_sets' => $contentPermissionSets,
            'content_permissions' => $contentPermissions,
            'counts' => $counts,
            'statuses' => $statuses,
            'macros' => $macros,
            'comments' => $comments,
            'page_labels' => $pageLabels,
            'warnings' => $warnings,
            'defaults' => [
                'include_history' => false,
                'include_deleted' => false,
                'include_drafts' => false,
                'include_attachments' => true,
                'unresolved_acl' => 'deny',
            ],
        ];
    }

    /**
     * HR: Pretvara Confluence prostor u prenosivi zapis.
     * EN: Converts a Confluence space into a portable record.
     *
     * @return array<string,mixed>
     */
    private function space(ConfluenceObject $object): array
    {
        return [
            'source_id' => $object->string('id'),
            'source_key' => $object->string('key'),
            'name' => $object->string('name'),
            'type' => strtolower($object->string('spaceType', 'global')),
            'status' => strtolower($object->string('spaceStatus', 'current')),
            'home_page_id' => $object->reference('homePage'),
            'owner_source_key' => $object->reference('creator'),
            'created_at' => $object->string('creationDate'),
            'updated_at' => $object->string('lastModificationDate'),
        ];
    }

    /**
     * HR: Pretvara Confluence stranicu u zapis pregleda.
     * EN: Converts a Confluence page into an inventory record.
     *
     * @return array<string,mixed>
     */
    private function page(ConfluenceObject $object): array
    {
        $sourceId = $object->string('id');
        $original = $object->reference('originalVersion');

        return [
            'source_id' => $sourceId,
            'logical_source_id' => $original !== '' ? $original : $sourceId,
            'original_version_id' => $original,
            'space_id' => $object->reference('space'),
            'parent_id' => $object->reference('parent'),
            'title' => $object->string('title'),
            'version' => $object->integer('version', 1),
            'status' => strtolower($object->string('contentStatus', 'current')),
            'position' => $object->integer('position', 100),
            'creator_source_key' => $object->reference('creator'),
            'modifier_source_key' => $object->reference('lastModifier'),
            'created_at' => $object->string('creationDate'),
            'updated_at' => $object->string('lastModificationDate'),
            'version_comment' => $object->string('versionComment'),
        ];
    }

    /**
     * HR: Pretvara Confluence privitak u zapis pregleda.
     * EN: Converts a Confluence attachment into an inventory record.
     *
     * @return array<string,mixed>
     */
    private function attachment(ConfluenceObject $object): array
    {
        return [
            'source_id' => $object->string('id'),
            'logical_source_id' => $object->reference('originalVersion') ?: $object->string('id'),
            'page_id' => $object->reference('containerContent'),
            'space_id' => $object->reference('space'),
            'title' => $object->string('title'),
            'version' => $object->integer('version', 1),
            'status' => strtolower($object->string('contentStatus', 'current')),
            'created_at' => $object->string('creationDate'),
            'updated_at' => $object->string('lastModificationDate'),
        ];
    }

    /**
     * HR: Pretvara Confluence identitet u prijedlog mapiranja.
     * EN: Converts a Confluence identity into a mapping suggestion.
     *
     * @return array<string,mixed>
     */
    private function user(ConfluenceObject $object): array
    {
        return [
            'source_key' => $object->string('id') ?: $object->string('key'),
            'username' => $object->string('username') ?: $object->string('name'),
            'display_name' => $object->string('displayName') ?: $object->string('fullName'),
            'first_name' => $object->string('firstName') ?: $object->string('givenName'),
            'last_name' => $object->string('lastName') ?: $object->string('surname'),
            'email' => strtolower($object->string('emailAddress') ?: $object->string('email')),
            'target_user_id' => null,
        ];
    }

    /**
     * HR: Pretvara jedan izvorni ACL zapis.
     * EN: Converts one source ACL record.
     *
     * @return array<string,mixed>
     */
    private function permission(ConfluenceObject $object): array
    {
        return [
            'source_id' => $object->string('id'),
            'type' => $object->string('type'),
            'space_id' => $object->reference('space'),
            'set_id' => $object->reference('owningSet'),
            'user_source_key' => $object->reference('userSubject'),
            'group_name' => $object->string('group') ?: $object->string('groupName'),
        ];
    }

    /**
     * HR: Pretvara skup ograničenja jedne stranice.
     * EN: Converts one page restriction set.
     *
     * @return array<string,mixed>
     */
    private function permissionSet(ConfluenceObject $object): array
    {
        return [
            'source_id' => $object->string('id'),
            'type' => strtolower($object->string('type')),
            'page_id' => $object->reference('owningContent'),
            'permission_ids' => $object->references('contentPermissions'),
        ];
    }

    /**
     * HR: Space export često ne sadrži InternalGroup objekte, iako ACL izravno
     * navodi grupe. Takve nazive svejedno nudimo za eksplicitno mapiranje.
     * EN: A space export often omits InternalGroup objects even though ACL rows
     * directly name groups. Those names are still offered for explicit mapping.
     *
     * @param array<string,mixed> $permission
     * @param array<string,array<string,mixed>> $groups
     */
    private function collectPermissionGroup(array $permission, array &$groups): void
    {
        $name = trim((string)($permission['group_name'] ?? ''));
        if ($name !== '') {
            $groups[$name] = ['source_name' => $name, 'target_group_id' => null];
        }
    }

    /**
     * HR: Broji makroe pronađene u tijelu stranice.
     * EN: Counts macros found in a page body.
     *
     * @param array<string,int> $macros
     */
    private function countMacros(string $body, array &$macros): void
    {
        if ($body === '') {
            return;
        }

        $body = html_entity_decode($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        preg_match_all('/<ac:structured-macro\b[^>]*\bac:name=(?:"([^"]+)"|\'([^\']+)\')/iu', $body, $matches);
        foreach ($matches[1] as $index => $doubleQuoted) {
            $name = strtolower(trim($doubleQuoted !== '' ? $doubleQuoted : $matches[2][$index]));
            if ($name !== '') {
                $macros[$name] = ($macros[$name] ?? 0) + 1;
            }
        }
    }

    /**
     * HR: Gradi jasna upozorenja preflight pregleda.
     * EN: Builds clear preflight inventory warnings.
     *
     * @param list<array<string,mixed>> $pages
     * @param array<string,array<string,mixed>> $users
     * @param array<string,int> $macros
     * @return list<string>
     */
    private function warnings(array $pages, array $users, array $macros): array
    {
        $warnings = [];
        if ($pages === []) {
            $warnings[] = __('Arhiva nema stranice za uvoz.');
        }

        if ($users !== []) {
            $warnings[] = __('Confluence identiteti neće automatski izraditi lokalne korisnike; potrebno je potvrditi mapiranje.');
        }

        $hasUnsupportedMacro = false;
        foreach (array_keys($macros) as $macro) {
            if (!in_array($macro, self::SUPPORTED_MACROS, true)) {
                $hasUnsupportedMacro = true;
                break;
            }
        }

        if ($hasUnsupportedMacro) {
            $warnings[] = __('Nepodržani Confluence makroi bit će sačuvani kao jasno označen statički prikaz i izvještaj.');
        }

        return $warnings;
    }
}
