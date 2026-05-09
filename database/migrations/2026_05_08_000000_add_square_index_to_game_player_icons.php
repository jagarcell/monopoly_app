<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the square_index column to game_player_icons.
 *
 * square_index stores which of the 40 board squares (0 = GO … 39) a player is
 * currently standing on.  The default of 0 puts every existing and new player
 * on GO, which is the correct starting position for Monopoly.  The range
 * 0–39 fits in an unsigned tiny-integer so storage is minimal.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Logic: Adds an unsigned tiny-integer column `square_index` with a default
     * of 0 (GO) after the `capital` column.  No index is added because
     * square_index is never used in a WHERE/JOIN/ORDER BY clause — it is only
     * read as part of the full player row.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('game_player_icons', function (Blueprint $table) {
            $table->unsignedTinyInteger('square_index')->default(0)->after('capital');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Logic: Drops the square_index column, restoring the table to its prior state.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('game_player_icons', function (Blueprint $table) {
            $table->dropColumn('square_index');
        });
    }
};
