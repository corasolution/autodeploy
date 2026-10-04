<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deploy_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->tinyInteger('phase');
            $table->integer('step');
            $table->text('command')->nullable();
            $table->text('output')->nullable();
            $table->integer('exit_code')->nullable();
            $table->json('ai_diagnosis')->nullable();
            $table->enum('status', ['info', 'success', 'warning', 'error'])->default('info');
            $table->timestamp('logged_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deploy_logs');
    }
};
