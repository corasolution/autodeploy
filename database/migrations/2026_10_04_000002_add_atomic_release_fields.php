<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UPGRADE-v2 Phase 2 — atomic releases.
 *
 * release_mode defaults to `in_place` so every existing site keeps the exact
 * pipeline it has today. Atomic is opt-in per site, after running
 * `deploy:convert-atomic` to reshape the server directory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('release_mode', 20)->default('in_place')->after('environment');
            $table->unsignedSmallInteger('keep_releases')->default(5)->after('release_mode');

            // Atomic deploys migrate against the new release while the old one
            // still serves traffic. Sites with destructive (non expand/contract)
            // migrations can opt back into a maintenance window.
            $table->boolean('maintenance_on_migrate')->default(false)->after('keep_releases');

            // Opcache caches the resolved realpath, so swapping the symlink is
            // not enough on its own — PHP-FPM has to be reloaded.
            $table->string('reload_command', 255)->nullable()->after('maintenance_on_migrate');
        });

        Schema::table('deployments', function (Blueprint $table) {
            $table->string('release_path', 500)->nullable()->after('commit_hash');
            $table->string('previous_release', 500)->nullable()->after('release_path');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['release_mode', 'keep_releases', 'maintenance_on_migrate', 'reload_command']);
        });

        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn(['release_path', 'previous_release']);
        });
    }
};
