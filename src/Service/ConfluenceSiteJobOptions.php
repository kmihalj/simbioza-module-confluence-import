<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Service;

/**
 * EN: Removes transient site execution context from completed portable job records.
 * Durable mappings and report UUIDs are exported separately; local identity IDs and review tokens are not a new-site configuration.
 *
 * HR: Uklanja privremeni izvršni kontekst sitea iz dovršenih prenosivih poslova.
 * Trajna mapiranja i UUID-jevi izvještaja izvoze se zasebno; lokalni ID-jevi i tokeni pregleda nisu konfiguracija novog sitea.
 */
final class ConfluenceSiteJobOptions
{
    /**
     * EN: Leaves the unchanged standalone-space format alone and strips only new site orchestration data.
     *
     * HR: Ne mijenja format samostalnog područja i uklanja samo nove orkestracijske podatke sitea.
     */
    public static function portable(?string $json): ?string
    {
        $data = is_string($json) ? json_decode($json, true) : null;
        if (
            !is_array($data) || (!isset($data['_site_child']) && !isset($data['site_content']) && !isset($data['site_calendars'])
            && !isset($data['site_access']) && !isset($data['site_workflow']) && !isset($data['site_workflow_draft']))
        ) {
            return $json;
        }
        unset(
            $data['_site_child'],
            $data['site_content'],
            $data['site_calendars'],
            $data['site_access'],
            $data['site_workflow'],
            $data['site_workflow_draft'],
            $data['inventory_selection'],
            $data['identity_map'],
            $data['identity_create'],
            $data['group_map'],
            $data['group_create'],
            $data['_append_existing'],
            $data['_append_workspace_id'],
            $data['_append_parent_nodes'],
            $data['_append_preserved']
        );
        return json_encode($data, JSON_THROW_ON_ERROR);
    }
}
