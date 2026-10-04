<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UPGRADE-v2 Phase 4 — approval gate.
 *
 * A pre-flight audit returning risk_level=high parks the deployment in
 * `awaiting_approval` until someone approves or cancels it in the UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        // sqlite (tests) stores enums as plain text and accepts the new value
        // already; only MySQL needs the column widened.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE deployments MODIFY COLUMN status '
                ."ENUM('pending','running','success','failed','rolled_back','awaiting_approval') "
                ."NOT NULL DEFAULT 'pending'"
            );
        }

        Schema::table('deployments', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('needs_attention');
            $table->foreignId('approved_by')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn(['approved_at', 'approved_by']);
        });

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::table('deployments')->where('status', 'awaiting_approval')->update(['status' => 'pending']);

        DB::statement(
            'ALTER TABLE deployments MODIFY COLUMN status '
            ."ENUM('pending','running','success','failed','rolled_back') "
            ."NOT NULL DEFAULT 'pending'"
        );
    }
};
