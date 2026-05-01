<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds a capital column to game_player_icons to track each player's current
     * cash balance. All players start with 1 500 (the standard Monopoly starting
     * amount), which is encoded as the column default so existing rows and new
     * inserts that omit the value receive it automatically.
     *
     * Columns added:
     * - capital: UNSIGNED INT, NOT NULL, DEFAULT 1500 — the player's remaining
     *   cash balance in the game. Stored here rather than on the games or users
     *   table because the amount is scoped to a single player's participation in
     *   a single game.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('game_player_icons', function (Blueprint $table) {
            $table->unsignedInteger('capital')->default(1500)->after('join_order');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('game_player_icons', function (Blueprint $table) {
            $table->dropColumn('capital');
        });
    }
};
