<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport;

/**
 * EN: Contains stable Confluence-import identifiers owned exclusively by this module.
 *
 * HR: Sadrži stabilne identifikatore Confluence importa koji pripadaju samo ovom modulu.
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

    public const TABLE_CALENDARS = 'simbioza_confluence_import_calendars';

    /**
     * EN: The static catalog cannot be instantiated.
     *
     * HR: Statički katalog nije moguće instancirati.
     */
    private function __construct()
    {
    }
}
