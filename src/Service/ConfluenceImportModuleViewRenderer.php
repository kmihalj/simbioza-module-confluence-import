<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

use AaiEduHr\SimbiozaModuleConfluenceImport\ModuleSimbiozaConfluenceImport;
use HeartPhrame\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;

/**
 * EN: Renders Confluence-import views through the main application layout.
 *
 * HR: Renderira Confluence-import prikaze kroz glavni layout aplikacije.
 */
final readonly class ConfluenceImportModuleViewRenderer
{
    /**
     * EN: Receives the shared HTTP factory.
     *
     * HR: Prima zajedničku HTTP tvornicu.
     */
    public function __construct(private ResponseFactory $responses)
    {
    }

    /**
     * EN: Renders a module template with the host application's theme, menus, and localization.
     *
     * HR: Renderira modularni predložak uz temu, izbornike i lokalizaciju host aplikacije.
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
