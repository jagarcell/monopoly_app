<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Logic: Adds a nullable holder_join_order column to game_chance_cards so
     * get-out-of-jail-free cards can be assigned to a player and persist across
     * page refreshes. Adds a composite index for fast lookups by game and holder.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('game_chance_cards', function (Blueprint $table) {
            $table->unsignedTinyInteger('holder_join_order')
                ->nullable()
                ->after('sort_order');

            $table->index(
                ['game_id', 'holder_join_order'],
                'idx_game_chance_cards_game_holder'
            );
        });
    }

    /**
     * Reverse the migrations.
     *
     * Logic: Drops the holder index first, then removes holder_join_order so the
     * table schema matches its original shape before this migration.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('game_chance_cards', function (Blueprint $table) {
            $table->dropIndex('idx_game_chance_cards_game_holder');
            $table->dropColumn('holder_join_order');
        });
    }
};
