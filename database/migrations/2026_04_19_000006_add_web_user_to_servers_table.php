<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // The OS user that the web server / PHP-FPM runs as. Used by
            // Phase 3 to chown the deploy path so the web server can write
            // to storage/ and bootstrap/cache/.
            //   - aaPanel: typically "www"
            //   - cPanel:  same as the cPanel account user
            // Leave null to skip chown (legacy behaviour).
            $table->string('web_user', 100)->nullable()->after('ssh_user');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('web_user');
        });
    }
};
