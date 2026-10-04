<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * panel_type is a MySQL ENUM. Doctrine/Laravel's ->change() doesn't handle
     * enum value changes portably, so the column is altered with raw SQL.
     */
    public function up(): void
    {
        // sqlite (the test connection) has no ENUM type and no MODIFY COLUMN;
        // its text column already accepts the new value, so there is nothing to
        // alter. Without this guard the raw statement aborts the whole suite.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE servers MODIFY COLUMN panel_type ENUM('cpanel', 'aapanel', 'openpanel') NOT NULL");
    }

    public function down(): void
    {
        // Any rows already on the removed value would break the narrowed enum —
        // park them on aapanel (closest match: root-level VPS, not a shared account).
        DB::table('servers')->where('panel_type', 'openpanel')->update(['panel_type' => 'aapanel']);

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE servers MODIFY COLUMN panel_type ENUM('cpanel', 'aapanel') NOT NULL");
    }
};
