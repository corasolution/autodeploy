<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UPGRADE-v2 Phase 3 — health check correctness.
 *
 * The old default was /health, but Laravel ships /up, so almost every site
 * 404'd and the check degraded to a warning that could never fail a deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            if (Schema::hasColumn('sites', 'health_path')) {
                return;
            }

            $table->string('health_path', 255)->nullable()->after('app_url');
            // SSL verification is ON by default now; this is the per-site
            // escape hatch for a genuinely broken/self-signed cert.
            $table->boolean('health_verify_ssl')->default(true)->after('health_path');
        });

        Schema::table('deployments', function (Blueprint $table) {
            // Set when AI log triage returns `critical`. Deliberately not a
            // status value — the deploy succeeded, it just wants a human look.
            $table->boolean('needs_attention')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        // Guarded: a partially-applied up() must still be reversible.
        Schema::table('sites', function (Blueprint $table) {
            foreach (['health_path', 'health_verify_ssl'] as $column) {
                if (Schema::hasColumn('sites', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasColumn('deployments', 'needs_attention')) {
            Schema::table('deployments', function (Blueprint $table) {
                $table->dropColumn('needs_attention');
            });
        }
    }
};
