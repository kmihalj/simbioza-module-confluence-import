<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\SimbiozaModuleWorkspace\Contract\WorkspaceExternalReferenceProviderInterface;

use function is_array;
use function is_scalar;
use function trim;

/**
 * HR: Povezuje prijenosni Confluence `spaceKey` s lokalnim područjem tek nakon
 *     što je njegovo mapiranje stvarno spremljeno uvoznim postupkom.
 * EN: Connects a portable Confluence `spaceKey` to a local Workspace only after
 *     the importer has actually persisted that mapping.
 */
final readonly class ConfluenceWorkspaceReferenceProvider implements WorkspaceExternalReferenceProviderInterface
{
    /** HR: Prima spremište spremljenih mapiranja importa. EN: Receives the persisted import-mapping repository. */
    public function __construct(private ConfluenceImportRepository $repository)
    {
    }

    /** HR: Vraća stabilni identifikator izvornog sustava. EN: Returns the stable source-system identifier. */
    public function provider(): string
    {
        return 'confluence';
    }

    /**
     * HR: Razrješava Confluence ključ tek kada postoji lokalno mapiranje područja.
     * EN: Resolves a Confluence key only after its local Workspace mapping exists.
     *
     * @return array{slug:string,title:string}|null
     */
    public function resolve(string $reference): ?array
    {
        $mapping = $this->repository->spaceBySourceKey($reference);
        if (!is_array($mapping)) {
            return null;
        }

        $slug = is_scalar($mapping['target_workspace_slug'] ?? null)
            ? trim((string)$mapping['target_workspace_slug'])
            : '';
        if ($slug === '') {
            return null;
        }
        $title = is_scalar($mapping['source_space_name'] ?? null)
            ? trim((string)$mapping['source_space_name'])
            : '';

        return ['slug' => $slug, 'title' => $title !== '' ? $title : $slug];
    }
}
