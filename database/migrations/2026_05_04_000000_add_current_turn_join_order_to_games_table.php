<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Logic: Adds a `current_turn_join_order` column to the games table to track
     * which player's turn it is. Defaults to 1 (the creator, who always has
     * join_order = 1 and always goes first). An index is added because the column
     * is used in a WHERE clause when validating whose turn it is before a roll.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->unsignedTinyInteger('current_turn_join_order')->default(1)->after('max_players');
            $table->index('current_turn_join_order');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Logic: Drops the index first (required before dropping the column on some
     * engines), then removes the column from the games table.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropIndex(['current_turn_join_order']);
            $table->dropColumn('current_turn_join_order');
        });
    }
};
