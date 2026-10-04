<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Absolute local path to the target project source on the machine
            // running autodeploy (e.g. "D:\\My project\\ecommer_solar").
            // If null, phase 2/3 fall back to autodeploy's own base_path()
            // for backwards compatibility.
            $table->string('source_path', 500)->nullable()->after('deploy_path');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('source_path');
        });
    }
};
