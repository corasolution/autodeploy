<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deploy_logs', function (Blueprint $table) {
            $table->longText('output')->nullable()->change();
            $table->longText('command')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('deploy_logs', function (Blueprint $table) {
            $table->text('output')->nullable()->change();
            $table->text('command')->nullable()->change();
        });
    }
};
