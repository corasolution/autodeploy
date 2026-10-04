<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('branch', 100)->default('main');
            $table->string('commit_hash', 40)->nullable();
            $table->enum('status', ['pending', 'running', 'success', 'failed', 'rolled_back'])->default('pending');
            $table->string('triggered_by', 100)->nullable();
            $table->enum('ai_risk_level', ['low', 'medium', 'high'])->nullable();
            $table->json('ai_audit_result')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployments');
    }
};
