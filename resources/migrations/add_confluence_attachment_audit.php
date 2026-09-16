<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleOrm\Database\Migration\ReversibleMigrationInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\Schema\Blueprint;
use AaiEduHr\SimbiozaModuleConfluenceImport\ModuleSimbiozaConfluenceImport;

return new class implements ReversibleMigrationInterface {
    /**
     * HR: Čuva izvornog učitavača i vrijeme svake Confluence verzije privitka.
     * EN: Stores the source uploader and timestamp for each Confluence attachment version.
     */
    public function up(Database $db): void
    {
        $schema = $db->schema();
        $tableName = ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS;
        if (!$schema->hasTable($tableName)) {
            return;
        }
        $schema->table($tableName, static function (Blueprint $table) use ($schema, $tableName): void {
            if (!$schema->hasColumn($tableName, 'source_creator_key')) {
                $table->string('source_creator_key', 190)->nullable()->index();
            }
            if (!$schema->hasColumn($tableName, 'source_created_at')) {
                $table->string('source_created_at', 64)->nullable()->index();
            }
        });
    }

    /** HR: Uklanja izvorne audit stupce. EN: Removes the source audit columns. */
    public function down(Database $db): void
    {
        $schema = $db->schema();
        $tableName = ModuleSimbiozaConfluenceImport::TABLE_ATTACHMENTS;
        if (!$schema->hasTable($tableName)) {
            return;
        }
        $schema->table($tableName, static function (Blueprint $table) use ($schema, $tableName): void {
            if ($schema->hasColumn($tableName, 'source_created_at')) {
                $table->dropColumn('source_created_at');
            }
            if ($schema->hasColumn($tableName, 'source_creator_key')) {
                $table->dropColumn('source_creator_key');
            }
        });
    }
};
