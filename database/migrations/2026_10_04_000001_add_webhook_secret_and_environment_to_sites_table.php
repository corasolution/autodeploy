<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UPGRADE-v2 Phase 1.
 *
 * webhook_secret moves from Server to Site (F1/F2): the webhook now names a
 * site, so the secret that authenticates it has to live there too. Stored
 * encrypted via the Site model mutator. The server-level column stays for now
 * as a migration fallback.
 *
 * environment (F7) gates `with_database`: pushing local data over a production
 * database must not be one click away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->text('webhook_secret')->nullable()->after('active');
            $table->string('environment', 20)->default('production')->after('webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['webhook_secret', 'environment']);
        });
    }
};
