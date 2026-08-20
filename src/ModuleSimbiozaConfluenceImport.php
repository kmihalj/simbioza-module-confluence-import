<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport;

/**
 * HR: Sadrži stabilne identifikatore Confluence importa koji pripadaju samo ovom modulu.
 * EN: Contains stable Confluence-import identifiers owned exclusively by this module.
 */
final class ModuleSimbiozaConfluenceImport
{
    public const PACKAGE_NAME = 'aaieduhr/simbioza-module-confluence-import';

    public const TABLE_JOBS = 'simbioza_confluence_import_jobs';

    public const TABLE_SPACES = 'simbioza_confluence_import_spaces';

    public const TABLE_CONTENT = 'simbioza_confluence_import_content';

    public const TABLE_IDENTITIES = 'simbioza_confluence_import_identities';

    public const TABLE_GROUPS = 'simbioza_confluence_import_groups';

    public const TABLE_LINKS = 'simbioza_confluence_import_links';

    public const TABLE_ATTACHMENTS = 'simbioza_confluence_import_attachments';

    /** HR: Statički katalog nije moguće instancirati. EN: The static catalog cannot be instantiated. */
    private function __construct()
    {
    }
}
