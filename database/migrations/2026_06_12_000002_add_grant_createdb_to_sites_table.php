<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // When true, Phase 4 runs ALTER USER {DB_USERNAME} CREATEDB
            // via SSH after writing .env — required for stancl/tenancy to
            // auto-create tenant databases on PostgreSQL.
            $table->boolean('grant_createdb')->default(false)->after('run_tenant_migrations');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('grant_createdb');
        });
    }
};
