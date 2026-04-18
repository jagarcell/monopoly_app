<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds a join_order column to game_player_icons so the sequence in which
     * players joined a game can be preserved and used to distribute them across
     * the two side panels (Space 1 = odd positions, Space 2 = even positions).
     *
     * Columns added:
     * - join_order: TINYINT UNSIGNED, NOT NULL — the 1-based position in which
     *   the player joined the game. The creator always receives join_order = 1.
     *   A unique constraint on (game_id, join_order) ensures no two players in
     *   the same game share the same sequence number. An index on
     *   (game_id, join_order) supports the ORDER BY used in getPlayersForGame.
     *
     * @return void
     */
    public function up(): void
    {
        // Step 1: add the column as nullable so existing rows can be backfilled
        // before the NOT NULL / unique constraints are applied.
        Schema::table('game_player_icons', function (Blueprint $table) {
            $table->unsignedTinyInteger('join_order')->nullable()->after('player_icon_id');
        });

        // Step 2: backfill each row with a per-game sequential number ordered by
        // the row's primary key so the original insertion order is preserved.
        DB::statement('
            UPDATE game_player_icons gpi
            JOIN (
                SELECT id,
                       ROW_NUMBER() OVER (PARTITION BY game_id ORDER BY id) AS rn
                FROM game_player_icons
            ) ranked ON ranked.id = gpi.id
            SET gpi.join_order = ranked.rn
        ');

        // Step 3: enforce NOT NULL now that every row has a value, then add the
        // unique and index constraints.
        Schema::table('game_player_icons', function (Blueprint $table) {
            $table->unsignedTinyInteger('join_order')->nullable(false)->change();
            $table->unique(['game_id', 'join_order'], 'uq_gpi_game_join_order');
            $table->index(['game_id', 'join_order'], 'idx_gpi_game_join_order');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Logic: Drops the unique constraint, index, and join_order column in the
     * correct order to avoid FK/constraint errors on rollback.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('game_player_icons', function (Blueprint $table) {
            $table->dropUnique('uq_gpi_game_join_order');
            $table->dropIndex('idx_gpi_game_join_order');
            $table->dropColumn('join_order');
        });
    }
};
