<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;
use AaiEduHr\SimbiozaModuleConfluenceImport\ModuleSimbiozaConfluenceImport;

return new class implements ReversibleMigrationInterface {
    /**
     * HR: Izolira identitet privitka unutar jednoga import posla.
     * EN: Scopes attachment identity to a single import job.
     */
    public function up(Database $db): void
    {
        $schema = $db->schema();
        if (!$schema->hasTable(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)) {
            return;
        }

        if (
            $schema->hasIndex(
                ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS,
                'simbioza_confluence_attachment_source_uq',
                'unique',
            )
        ) {
            $schema->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS, static function (Blueprint $table): void {
                $table->dropUnique(
                    ['source_attachment_id', 'source_version'],
                    'simbioza_confluence_attachment_source_uq',
                );
            });
        }

        if (
            !$schema->hasIndex(
                ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS,
                'simbioza_confluence_attachment_job_source_uq',
                'unique',
            )
        ) {
            $schema->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS, static function (Blueprint $table): void {
                $table->unique(
                    ['job_id', 'source_attachment_id', 'source_version'],
                    'simbioza_confluence_attachment_job_source_uq',
                );
            });
        }
    }

    /**
     * HR: Vraća raniji globalni identitet izvornog privitka.
     * EN: Restores the earlier global source-attachment identity.
     */
    public function down(Database $db): void
    {
        $schema = $db->schema();
        if (!$schema->hasTable(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)) {
            return;
        }

        if (
            $schema->hasIndex(
                ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS,
                'simbioza_confluence_attachment_job_source_uq',
                'unique',
            )
        ) {
            $schema->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS, static function (Blueprint $table): void {
                $table->dropUnique(
                    ['job_id', 'source_attachment_id', 'source_version'],
                    'simbioza_confluence_attachment_job_source_uq',
                );
            });
        }

        if (
            !$schema->hasIndex(
                ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS,
                'simbioza_confluence_attachment_source_uq',
                'unique',
            )
        ) {
            $schema->table(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS, static function (Blueprint $table): void {
                $table->unique(
                    ['source_attachment_id', 'source_version'],
                    'simbioza_confluence_attachment_source_uq',
                );
            });
        }
    }
};
