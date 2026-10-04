<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->enum('panel_type', ['cpanel', 'aapanel']);
            $table->string('host', 255);
            $table->integer('ssh_port')->default(22);
            $table->string('ssh_user', 100);
            $table->enum('ssh_auth', ['password', 'key'])->default('key');
            $table->text('ssh_password')->nullable();
            $table->text('ssh_private_key')->nullable();
            $table->string('panel_url', 255)->nullable();
            $table->text('panel_token')->nullable();
            $table->string('deploy_path', 500);
            $table->string('php_binary', 200)->default('php');
            $table->string('app_url', 255)->nullable();
            $table->string('webhook_secret', 100)->nullable();
            $table->string('cpanel_git_repo', 255)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
