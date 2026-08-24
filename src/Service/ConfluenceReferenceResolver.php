<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use HeartPhrame\Routing\UrlGenerator;

use function base64_decode;
use function is_array;
use function is_string;
use function json_decode;
use function ltrim;
use function preg_replace_callback;
use function rawurldecode;
use function rawurlencode;
use function str_contains;
use function trim;

/** HR: Razrješava Confluence reference nakon što su sve ciljne stranice poznate. EN: Resolves Confluence references after all target pages are known. */
final readonly class ConfluenceReferenceResolver
{
    /** HR: Prima mapiranja i generator prenosivih URL-ova. EN: Receives mappings and the portable URL generator. */
    public function __construct(
        private ConfluenceImportRepository $repository,
        private UrlGenerator $urls,
    ) {
    }

    /**
     * HR: Zamjenjuje privremene tokene stvarnim URL-ovima i bilježi neriješene cross-space poveznice.
     * EN: Replaces temporary tokens with real URLs and records unresolved cross-space links.
     *
     * @param array<string,string> $attachmentUrlsByName
     * @param array<string,string> $localTargetsById
     * @param array<string,string> $localTargetsByTitle
     */
    public function resolve(
        string $html,
        string $sourcePageId,
        string $sourceSpaceKey,
        int $jobId,
        array $attachmentUrlsByName = [],
        array $localTargetsById = [],
        array $localTargetsByTitle = [],
    ): string {
        $html = preg_replace_callback(
            '~__SIMBIOZA_CONFLUENCE_LINK__([^"\'<>\s]+)~',
            function (array $match) use (
                $sourcePageId,
                $sourceSpaceKey,
                $jobId,
                $localTargetsById,
                $localTargetsByTitle,
            ): string {
                $reference = $this->decode($match[1]);
                $destinationSpace = trim((string)($reference['destination_space_key'] ?? $sourceSpaceKey));
                $destinationId = trim((string)($reference['destination_page_id'] ?? ''));
                $destinationTitle = trim((string)($reference['destination_page_title'] ?? ''));
                $fragment = trim((string)($reference['fragment'] ?? ''));
                $target = '#';
                if ($destinationSpace === '' || $destinationSpace === $sourceSpaceKey) {
                    $target = $destinationId !== ''
                        ? ($localTargetsById[$destinationId] ?? '#')
                        : ($localTargetsByTitle[$destinationTitle] ?? '#');
                }
                if ($target === '#') {
                    if ($destinationId !== '') {
                        $mapping = $destinationSpace !== ''
                            ? $this->repository->contentBySource($destinationSpace, $destinationId)
                            : $this->repository->contentByAnySourceId($destinationId);
                    } else {
                        $mapping = $this->repository->contentByTitle(
                            $destinationSpace !== '' ? $destinationSpace : $sourceSpaceKey,
                            $destinationTitle,
                        );
                    }
                    $target = is_array($mapping) ? $this->workspaceNodePath($mapping) : '#';
                }
                $target = $target !== '#' ? $this->withFragment($target, $fragment) : '#';
                $linkUuid = $this->repository->recordLink([
                    'source_page_id' => $sourcePageId,
                    'source_space_key' => $sourceSpaceKey,
                    'destination_space_key' => $destinationSpace,
                    'destination_page_id' => $destinationId,
                    'destination_page_title' => $destinationTitle,
                    'original_target' => trim((string)($reference['original_target'] ?? ''))
                        ?: $match[0],
                    'resolved_target' => $target !== '#' ? $target : '',
                    'status' => $target !== '#' ? 'resolved' : 'unresolved',
                ], $jobId);

                return $target !== '#' ? $target : $this->unresolvedPath($linkUuid);
            },
            $html,
        ) ?? $html;

        return preg_replace_callback(
            '~__SIMBIOZA_CONFLUENCE_ATTACHMENT__([^"\'<>\s]+)~',
            function (array $match) use ($attachmentUrlsByName): string {
                $reference = $this->decode($match[1]);
                $filename = trim((string)($reference['filename'] ?? ''));
                $target = $attachmentUrlsByName[$filename] ?? '#';

                // HR: Slike ostaju prikazive unutar sadržaja, dok poveznica na
                //     datoteku koristi javni Editor ugovor za preuzimanje.
                // EN: Images remain renderable inside the content, while a file
                //     link uses Editor's public download contract.
                if ($target !== '#' && ($reference['kind'] ?? 'file') !== 'image') {
                    return $target . (str_contains($target, '?') ? '&' : '?') . 'download=1';
                }

                return $target;
            },
            $html,
        ) ?? $html;
    }

    /**
     * HR: Dekodira provjereni privremeni token reference.
     * EN: Decodes a validated temporary reference token.
     *
     * @return array<string,mixed>
     */
    private function decode(string $token): array
    {
        $json = base64_decode(rawurldecode($token), true);
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * HR: Gradi lokalnu putanju iz trajnog mapiranja stranice.
     * EN: Builds a local path from a durable page mapping.
     *
     * @param array<string,mixed> $mapping
     */
    private function workspaceNodePath(array $mapping): string
    {
        $space = $this->repositorySpaceSlug((int)($mapping['target_workspace_id'] ?? 0));
        $slug = trim((string)($mapping['target_slug'] ?? ''));
        if ($space === '' || $slug === '') {
            return '#';
        }

        if ($this->urls->namedRouteExists('workspace.node.show')) {
            return $this->urls->getPathFor('workspace.node.show', [
                'workspaceSlug' => $space,
                'nodeSlug' => $slug,
            ]);
        }

        return rtrim($this->urls->getBasePath(), '/') . '/workspace/'
            . rawurlencode($space) . '/' . rawurlencode($slug);
    }

    /** HR: Dohvaća ciljni slug područja iz repozitorija mapiranja. EN: Fetches the target Workspace slug from the mapping repository. */
    private function repositorySpaceSlug(int $workspaceId): string
    {
        if ($workspaceId <= 0) {
            return '';
        }

        $mapping = $this->repository->spaceByWorkspaceId($workspaceId);

        return is_array($mapping) ? trim((string)($mapping['target_workspace_slug'] ?? '')) : '';
    }

    /** HR: Čuva sigurni fragment iz izvornog URL-a bez utjecaja na ciljnu putanju. EN: Preserves a safe source-URL fragment without affecting the target path. */
    private function withFragment(string $target, string $fragment): string
    {
        $fragment = preg_replace(
            '~[^A-Za-z0-9._\\~!$&\'()*+,;=:@%/\\-]~',
            '',
            ltrim($fragment, '#'),
        ) ?? '';

        return $fragment !== '' ? $target . '#' . $fragment : $target;
    }

    /** HR: Gradi stabilnu posredničku poveznicu koja može proraditi nakon kasnijeg importa prostora. EN: Builds a stable intermediary link that can resolve after a later space import. */
    private function unresolvedPath(string $linkUuid): string
    {
        if ($this->urls->namedRouteExists('simbioza-confluence-import.link')) {
            return $this->urls->getPathFor('simbioza-confluence-import.link', ['uuid' => $linkUuid]);
        }

        return rtrim($this->urls->getBasePath(), '/') . '/confluence-import/link/' . rawurlencode($linkUuid);
    }
}
