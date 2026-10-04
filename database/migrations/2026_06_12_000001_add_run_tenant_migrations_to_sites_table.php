<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // When true, Phase 4 also runs `php artisan tenants:migrate --force`
            // after the standard migrate — required for multi-tenant apps (stancl/tenancy).
            $table->boolean('run_tenant_migrations')->default(false)->after('run_seeders');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('run_tenant_migrations');
        });
    }
};
