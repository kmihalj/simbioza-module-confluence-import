<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleConfluenceImport\Tests;

use AaiEduHr\SimbiozaModuleConfluenceImport\Service\ConfluenceSiteJobOptions;
use PHPUnit\Framework\TestCase;

/**
 * EN: Protects completed backups from private execution state while preserving normal import options.
 *
 * HR: Štiti dovršene sigurnosne kopije od privatnog izvršnog stanja uz očuvanje običnih opcija uvoza.
 */
final class ConfluenceSiteJobOptionsTest extends TestCase
{
    /**
     * EN: Namespace context, local mapping IDs and preview tokens must not become restored configuration.
     *
     * HR: Kontekst izvora, lokalni ID-jevi i tokeni pregleda ne smiju postati obnovljena konfiguracija.
     */
    public function testPortableSiteChildAndParentOptions(): void
    {
        foreach (['_site_child', 'site_content'] as $key) {
            $json = json_encode([$key => ['token' => 'PRIVATE-TOKEN'], 'group_map' => ['staff' => 42],
                'identity_map' => ['user' => 77], 'site_access' => ['preview' => 'PRIVATE-REVIEW'],
                '_append_existing' => true, '_append_workspace_id' => 23,
                '_append_parent_nodes' => ['100' => 45], '_append_preserved' => 1,
                'include_attachments' => true, 'source_base_url' => 'https://source.test'], JSON_THROW_ON_ERROR);
            $portable = ConfluenceSiteJobOptions::portable($json);
            self::assertStringNotContainsString('PRIVATE-', $portable);
            self::assertSame(['include_attachments' => true, 'source_base_url' => 'https://source.test'], json_decode($portable, true));
        }
        self::assertNull(ConfluenceSiteJobOptions::portable(null));
        self::assertSame('{"include_attachments":true}', ConfluenceSiteJobOptions::portable('{"include_attachments":true}'));
        foreach (['site_workflow', 'site_workflow_draft'] as $key) {
            self::assertSame('[]', ConfluenceSiteJobOptions::portable(json_encode([$key => ['token' => 'PRIVATE-TOKEN']], JSON_THROW_ON_ERROR)));
        }
    }
}
