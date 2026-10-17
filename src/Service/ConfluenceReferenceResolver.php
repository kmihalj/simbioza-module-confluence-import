<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use HeartPhrame\Routing\UrlGenerator;

use function array_key_exists;
use function base64_decode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function ltrim;
use function parse_url;
use function preg_replace_callback;
use function rawurldecode;
use function rawurlencode;
use function str_contains;
use function strcasecmp;
use function strtolower;
use function trim;

use const PHP_URL_HOST;
use const PHP_URL_SCHEME;

/**
 * EN: Resolves Confluence references after all target pages are known.
 *
 * HR: Razrješava Confluence reference nakon što su sve ciljne stranice poznate.
 */
final readonly class ConfluenceReferenceResolver
{
    /**
     * EN: Receives mappings and the portable URL generator.
     *
     * HR: Prima mapiranja i generator prenosivih URL-ova.
     */
    public function __construct(
        private ConfluenceImportRepository $repository,
        private UrlGenerator $urls,
    ) {
    }

    /**
     * EN: Replaces temporary tokens with real URLs and records unresolved cross-space links.
     *
     * HR: Zamjenjuje privremene tokene stvarnim URL-ovima i bilježi neriješene cross-space poveznice.
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
        $links = [];
        $localSourceIds = [];
        foreach ($localTargetsById as $sourceId => $path) {
            if (!array_key_exists($path, $localSourceIds)) {
                $localSourceIds[$path] = (string)$sourceId;
            } elseif ($localSourceIds[$path] !== (string)$sourceId) {
                $localSourceIds[$path] = '';
            }
        }
        $html = preg_replace_callback(
            '~__SIMBIOZA_CONFLUENCE_LINK__([^"\'<>\s]+)~',
            function (array $match) use (
                &$links,
                $sourcePageId,
                $sourceSpaceKey,
                $localTargetsById,
                $localTargetsByTitle,
                $localSourceIds,
            ): string {
                $reference = $this->decode($match[1]);
                $destinationSpace = trim((string)($reference['destination_space_key'] ?? $sourceSpaceKey));
                $destinationId = trim((string)($reference['destination_page_id'] ?? ''));
                $destinationTitle = trim((string)($reference['destination_page_title'] ?? ''));
                $fragment = trim((string)($reference['fragment'] ?? ''));
                $referenceType = trim((string)($reference['reference_type'] ?? 'page'));
                $originalTarget = trim((string)($reference['original_target'] ?? ''));
                $target = '#';
                if (
                    $referenceType !== 'unknown'
                    && ($destinationSpace === '' || strcasecmp($destinationSpace, $sourceSpaceKey) === 0)
                ) {
                    $target = $destinationId !== ''
                        ? ($localTargetsById[$destinationId] ?? '#')
                        : ($destinationTitle !== '' ? ($localTargetsByTitle[$destinationTitle] ?? '#') : '#');
                    // EN: Persist the scan-verified page identity behind an unambiguous title link.
                    // A preserved page may keep a different local title; later reconciliation uses its ID.
                    //
                    // HR: Za jednoznačnu vezu po naslovu čuva identitet stranice potvrđen pregledom.
                    // Sačuvana stranica smije imati drugi lokalni naslov; kasnije usklađivanje koristi ID.
                    if ($destinationId === '' && $target !== '#') {
                        $destinationId = $localSourceIds[$target] ?? '';
                        $destinationSpace = $destinationSpace !== '' ? $destinationSpace : $sourceSpaceKey;
                    }
                }
                if ($target === '#' && $referenceType !== 'unknown') {
                    if ($destinationId !== '') {
                        $mapping = $destinationSpace !== ''
                            ? $this->repository->contentBySource($destinationSpace, $destinationId)
                            : $this->repository->contentByAnySourceId($destinationId);
                    } elseif ($destinationTitle !== '') {
                        $mapping = $this->repository->contentByTitle(
                            $destinationSpace !== '' ? $destinationSpace : $sourceSpaceKey,
                            $destinationTitle,
                        );
                    } else {
                        $mapping = $destinationSpace !== ''
                            ? $this->repository->homepageContentBySpaceKey($destinationSpace)
                            : null;
                    }
                    $target = is_array($mapping) ? $this->workspaceNodePath($mapping) : '#';
                }
                $target = $target !== '#' ? $this->withFragment($target, $fragment) : '#';
                $linkUuid = $this->repository->newLinkUuid();
                $links[] = [
                    'uuid' => $linkUuid,
                    'source_page_id' => $sourcePageId,
                    'source_space_key' => $sourceSpaceKey,
                    'destination_space_key' => $destinationSpace,
                    'destination_page_id' => $destinationId,
                    'destination_page_title' => $destinationTitle,
                    // EN: A semantic ac:link reference has no source URL. The
                    //     internal conversion token is not user-facing report data.
                    //
                    // HR: Semantička ac:link referenca nema izvorni URL. Interni
                    //     pretvorbeni token nije korisnički URL i ne smije u izvještaj.
                    'original_target' => $originalTarget,
                    'resolved_target' => $target !== '#' ? $target : '',
                    'status' => $target !== '#' ? 'resolved' : 'unresolved',
                ];

                if ($referenceType === 'unknown' && $this->safeExternalTarget($originalTarget)) {
                    return $originalTarget;
                }

                return $target !== '#' ? $target : $this->unresolvedPath($linkUuid);
            },
            $html,
        ) ?? $html;
        $this->repository->recordLinks($links, $jobId);

        return preg_replace_callback(
            '~__SIMBIOZA_CONFLUENCE_ATTACHMENT__([^"\'<>\s]+)~',
            function (array $match) use ($attachmentUrlsByName): string {
                $reference = $this->decode($match[1]);
                $filename = trim((string)($reference['filename'] ?? ''));
                $target = $attachmentUrlsByName[$filename] ?? '#';

                // EN: Images remain renderable inside the content, while a file
                //     link uses Editor's public download contract.
                //
                // HR: Slike ostaju prikazive unutar sadržaja, dok poveznica na
                //     datoteku koristi javni Editor ugovor za preuzimanje.
                if ($target !== '#' && ($reference['kind'] ?? 'file') !== 'image') {
                    return $target . (str_contains($target, '?') ? '&' : '?') . 'download=1';
                }

                return $target;
            },
            $html,
        ) ?? $html;
    }

    /**
     * EN: Decodes a validated temporary reference token.
     *
     * HR: Dekodira provjereni privremeni token reference.
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
     * EN: Builds a local path from a durable page mapping.
     *
     * HR: Gradi lokalnu putanju iz trajnog mapiranja stranice.
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

    /**
     * EN: Fetches the target Workspace slug from the mapping repository.
     *
     * HR: Dohvaća ciljni slug područja iz repozitorija mapiranja.
     */
    private function repositorySpaceSlug(int $workspaceId): string
    {
        if ($workspaceId <= 0) {
            return '';
        }

        $mapping = $this->repository->spaceByWorkspaceId($workspaceId);

        return is_array($mapping) ? trim((string)($mapping['target_workspace_slug'] ?? '')) : '';
    }

    /**
     * EN: Preserves a safe source-URL fragment without affecting the target path.
     *
     * HR: Čuva sigurni fragment iz izvornog URL-a bez utjecaja na ciljnu putanju.
     */
    private function withFragment(string $target, string $fragment): string
    {
        $fragment = preg_replace(
            '~[^A-Za-z0-9._\\~!$&\'()*+,;=:@%/\\-]~',
            '',
            ltrim($fragment, '#'),
        ) ?? '';

        return $fragment !== '' ? $target . '#' . $fragment : $target;
    }

    /**
     * EN: Keeps an unknown Confluence link usable only as a safe HTTP(S) URL.
     *
     * HR: Nepoznatu Confluence poveznicu ostavlja uporabljivom samo kao siguran HTTP(S) URL.
     */
    private function safeExternalTarget(string $target): bool
    {
        $scheme = strtolower((string)(parse_url($target, PHP_URL_SCHEME) ?? ''));
        $host = trim((string)(parse_url($target, PHP_URL_HOST) ?? ''));

        return in_array($scheme, ['http', 'https'], true) && $host !== '';
    }

    /**
     * EN: Builds a stable intermediary link that can resolve after a later space import.
     *
     * HR: Gradi stabilnu posredničku poveznicu koja može proraditi nakon kasnijeg importa prostora.
     */
    private function unresolvedPath(string $linkUuid): string
    {
        if ($this->urls->namedRouteExists('simbioza-confluence-import.link')) {
            return $this->urls->getPathFor('simbioza-confluence-import.link', ['uuid' => $linkUuid]);
        }

        return rtrim($this->urls->getBasePath(), '/') . '/confluence-import/link/' . rawurlencode($linkUuid);
    }
}
