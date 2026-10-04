<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opt-in flag: when a "push database" deploy runs, also push the stancl/tenancy
 * TENANT databases, not just the central one named in .env.
 *
 * Defaults to FALSE deliberately. A true multi-tenant SaaS (bannalai.com) has one
 * database per paying customer — pushing local copies over those would destroy
 * live customer data. Only single-library installs, where exactly one tenant DB
 * exists and local is the source of truth, should ever turn this on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('push_tenant_databases')
                ->default(false)
                ->after('run_tenant_migrations');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('push_tenant_databases');
        });
    }
};
