<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\SimbiozaModuleConfluenceImport\ModuleSimbiozaConfluenceImport;
use HeartPhrame\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;

/** HR: Renderira Confluence-import prikaze kroz glavni layout aplikacije. EN: Renders Confluence-import views through the main application layout. */
final readonly class ConfluenceImportModuleViewRenderer
{
    /** HR: Prima zajedničku HTTP tvornicu. EN: Receives the shared HTTP factory. */
    public function __construct(private ResponseFactory $responses)
    {
    }

    /**
     * HR: Renderira modularni predložak uz temu, izbornike i lokalizaciju host aplikacije.
     * EN: Renders a module template with the host application's theme, menus, and localization.
     *
     * @param array<string,mixed> $data
     */
    public function render(string $view, array $data = [], int $status = 200): ResponseInterface
    {
        return $this->responses->viewForModule(
            ModuleSimbiozaConfluenceImport::PACKAGE_NAME,
            $view,
            $data,
            true,
            $status,
        );
    }
}
