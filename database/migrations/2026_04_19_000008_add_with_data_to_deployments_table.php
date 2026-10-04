<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            // Per-deployment override: when true, Phase 4 runs
            // `php artisan db:seed --force` regardless of site.run_seeders.
            // Lets users opt-in to seeding for a single deploy without
            // flipping the persistent site setting.
            $table->boolean('with_data')->default(false)->after('branch');
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn('with_data');
        });
    }
};
