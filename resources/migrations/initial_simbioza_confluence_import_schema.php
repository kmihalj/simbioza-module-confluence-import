<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;
use AaiEduHr\SimbiozaModuleConfluenceImport\ModuleSimbiozaConfluenceImport;

return new class implements ReversibleMigrationInterface {
    /**
     * HR: Kreira prenosivu shemu poslova, mapiranja, neriješenih poveznica i privatnih privitaka.
     * EN: Creates the portable jobs, mapping, unresolved-link, and private-attachment schema.
     */
    public function up(Database $db): void
    {
        $schema = $db->schema();

        if (!$schema->hasTable(ModuleSimbiozaConfluenceImport::TABLE_JOBS)) {
            $schema->create(ModuleSimbiozaConfluenceImport::TABLE_JOBS, static function (Blueprint $table): void {
                $table->id();
                $table->string('uuid', 36)->unique();
                $table->string('operation', 24)->default('import')->index();
                $table->string('status', 24)->default('uploading')->index();
                $table->string('stage', 48)->default('upload')->index();
                $table->string('original_name', 255);
                $table->string('archive_path', 1024);
                $table->bigInteger('archive_size')->unsigned()->default(0);
                $table->bigInteger('next_offset')->unsigned()->default(0);
                $table->bigInteger('chunk_size')->unsigned()->default(0);
                $table->string('sha256', 64)->nullable();
                $table->string('source_space_key', 255)->nullable()->index();
                $table->string('source_space_name', 255)->nullable();
                $table->string('source_space_type', 24)->nullable()->index();
                $table->longText('options_json')->nullable();
                $table->longText('summary_json')->nullable();
                $table->longText('error_message')->nullable();
                $table->bigInteger('workspace_id')->unsigned()->nullable()->index();
                $table->bigInteger('actor_user_id')->unsigned()->nullable()->index();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!$schema->hasTable(ModuleSimbiozaConfluenceImport::TABLE_SPACES)) {
            $schema->create(ModuleSimbiozaConfluenceImport::TABLE_SPACES, static function (Blueprint $table): void {
                $table->id();
                $table->string('source_instance', 64)->default('archive')->index();
                $table->string('source_space_id', 190)->index();
                $table->string('source_space_key', 255)->index();
                $table->string('source_space_type', 24)->default('global')->index();
                $table->string('source_space_name', 255);
                $table->string('source_owner_key', 190)->nullable()->index();
                $table->bigInteger('target_workspace_id')->unsigned()->index();
                $table->string('target_workspace_slug', 190)->index();
                $table->bigInteger('job_id')->unsigned()->index();
                $table->longText('source_metadata_json')->nullable();
                $table->timestamps();
                $table->unique(['source_instance', 'source_space_id'], 'simbioza_confluence_space_source_uq');
            });
        }

        if (!$schema->hasTable(ModuleSimbiozaConfluenceImport::TABLE_CONTENT)) {
            $schema->create(ModuleSimbiozaConfluenceImport::TABLE_CONTENT, static function (Blueprint $table): void {
                $table->id();
                $table->string('source_content_id', 190)->index();
                $table->string('logical_source_id', 190)->index();
                $table->string('source_space_key', 255)->index();
                $table->string('source_type', 32)->default('page')->index();
                $table->string('source_status', 24)->default('current')->index();
                $table->integer('source_version')->unsigned()->default(1);
                $table->string('source_parent_id', 190)->nullable()->index();
                $table->string('source_title', 1024)->nullable();
                $table->bigInteger('target_workspace_id')->unsigned()->index();
                $table->bigInteger('target_node_id')->unsigned()->nullable()->index();
                $table->string('target_document_key', 190)->nullable()->index();
                $table->string('target_slug', 190)->nullable();
                $table->string('import_status', 32)->default('pending')->index();
                $table->bigInteger('job_id')->unsigned()->index();
                $table->longText('source_metadata_json')->nullable();
                $table->longText('note')->nullable();
                $table->timestamps();
                $table->unique(['source_space_key', 'source_content_id'], 'simbioza_confluence_content_source_uq');
            });
        }

        if (!$schema->hasTable(ModuleSimbiozaConfluenceImport::TABLE_IDENTITIES)) {
            $schema->create(ModuleSimbiozaConfluenceImport::TABLE_IDENTITIES, static function (Blueprint $table): void {
                $table->id();
                $table->string('source_user_key', 190)->unique();
                $table->string('source_username', 255)->nullable()->index();
                $table->string('source_display_name', 255)->nullable();
                $table->string('source_email', 255)->nullable()->index();
                $table->bigInteger('target_user_id')->unsigned()->nullable()->index();
                $table->string('mapping_status', 24)->default('unresolved')->index();
                $table->boolean('confirmed')->default(false)->index();
                $table->bigInteger('job_id')->unsigned()->index();
                $table->timestamps();
            });
        }

        if (!$schema->hasTable(ModuleSimbiozaConfluenceImport::TABLE_GROUPS)) {
            $schema->create(ModuleSimbiozaConfluenceImport::TABLE_GROUPS, static function (Blueprint $table): void {
                $table->id();
                $table->string('source_group_name', 255)->unique();
                $table->bigInteger('target_group_id')->unsigned()->nullable()->index();
                $table->string('mapping_status', 24)->default('unresolved')->index();
                $table->boolean('confirmed')->default(false)->index();
                $table->bigInteger('job_id')->unsigned()->index();
                $table->timestamps();
            });
        }

        if (!$schema->hasTable(ModuleSimbiozaConfluenceImport::TABLE_LINKS)) {
            $schema->create(ModuleSimbiozaConfluenceImport::TABLE_LINKS, static function (Blueprint $table): void {
                $table->id();
                $table->string('uuid', 36)->unique();
                $table->bigInteger('job_id')->unsigned()->index();
                $table->string('source_page_id', 190)->index();
                $table->string('source_space_key', 255)->index();
                $table->string('destination_space_key', 255)->nullable()->index();
                $table->string('destination_page_id', 190)->nullable()->index();
                $table->string('destination_page_title', 1024)->nullable();
                $table->string('original_target', 2048)->nullable();
                $table->string('resolved_target', 2048)->nullable();
                $table->string('status', 24)->default('unresolved')->index();
                $table->timestamps();
                $table->index(['destination_space_key', 'status'], 'simbioza_confluence_link_reconcile_idx');
            });
        }

        if (!$schema->hasTable(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS)) {
            $schema->create(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS, static function (Blueprint $table): void {
                $table->id();
                $table->string('uuid', 36)->unique();
                $table->bigInteger('job_id')->unsigned()->index();
                $table->string('source_attachment_id', 190);
                $table->string('logical_source_id', 190)->index();
                $table->string('source_page_id', 190)->index();
                $table->integer('source_version')->unsigned()->default(1);
                $table->string('original_name', 1024);
                $table->string('mime_type', 255)->default('application/octet-stream')->index();
                $table->bigInteger('file_size')->unsigned()->default(0);
                $table->string('storage_path', 1024)->nullable();
                $table->bigInteger('target_workspace_id')->unsigned()->nullable()->index();
                $table->bigInteger('target_node_id')->unsigned()->nullable()->index();
                $table->string('target_document_key', 190)->nullable()->index();
                $table->string('status', 32)->default('pending')->index();
                $table->longText('error_message')->nullable();
                $table->timestamps();
                $table->index('source_attachment_id', 'simbioza_confluence_attachment_source_idx');
                $table->unique(['source_attachment_id', 'source_version'], 'simbioza_confluence_attachment_source_uq');
            });
        }
    }

    /** HR: Uklanja isključivo podatke kojima upravlja ovaj modul, obrnutim redom. EN: Drops only module-owned data in reverse order. */
    public function down(Database $db): void
    {
        $schema = $db->schema();
        $schema->dropIfExists(ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS);
        $schema->dropIfExists(ModuleSimbiozaConfluenceImport::TABLE_LINKS);
        $schema->dropIfExists(ModuleSimbiozaConfluenceImport::TABLE_GROUPS);
        $schema->dropIfExists(ModuleSimbiozaConfluenceImport::TABLE_IDENTITIES);
        $schema->dropIfExists(ModuleSimbiozaConfluenceImport::TABLE_CONTENT);
        $schema->dropIfExists(ModuleSimbiozaConfluenceImport::TABLE_SPACES);
        $schema->dropIfExists(ModuleSimbiozaConfluenceImport::TABLE_JOBS);
    }
};
