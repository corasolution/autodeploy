<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('deploy_path', 500)->nullable()->default(null)->change();
            $table->string('php_binary', 200)->nullable()->default(null)->change();
            $table->string('app_url')->nullable()->default(null)->change();
            $table->string('cpanel_git_repo')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('deploy_path', 500)->nullable(false)->change();
            $table->string('php_binary', 200)->nullable(false)->change();
            $table->string('app_url')->nullable(false)->change();
            $table->string('cpanel_git_repo')->nullable(false)->change();
        });
    }
};
