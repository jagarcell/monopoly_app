<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('game_player_icons', function (Blueprint $table) {
            $table->unsignedTinyInteger('jail_turns')->default(0)->after('is_in_jail');
            $table->boolean('has_paid_jail_release')->default(false)->after('jail_turns');
            $table->index('jail_turns');
            $table->index('has_paid_jail_release');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('game_player_icons', function (Blueprint $table) {
            $table->dropIndex(['jail_turns']);
            $table->dropIndex(['has_paid_jail_release']);
            $table->dropColumn(['jail_turns', 'has_paid_jail_release']);
        });
    }
};
