<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UPGRADE-v2 Phase 5 — deploy notifications.
 *
 * Per-server chat id so different clients' deploys can go to different chats;
 * the bot token itself is global (TELEGRAM_BOT_TOKEN) since it belongs to the
 * AutoPilot install, not to any one server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('telegram_chat_id', 64)->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('telegram_chat_id');
        });
    }
};
