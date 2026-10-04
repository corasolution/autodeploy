<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            // When true, Phase 4 dumps the local DB (creds read from
            // site.source_path/.env) and imports it into the remote DB
            // (creds parsed from site.env_content). Data-only push:
            // the schema is owned by `migrate --force`, this only
            // overwrites rows.
            $table->boolean('with_database')->default(false)->after('with_data');
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn('with_database');
        });
    }
};
