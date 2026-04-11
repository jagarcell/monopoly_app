<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the game_community_chest_cards pivot table which associates a game with
     * its shuffled Community Chest deck. Card definitions live in the
     * community_chest_cards master table; only the per-game draw order (sort_order)
     * is stored here.
     * Columns:
     * - id: auto-incrementing primary key
     * - game_id: FK to games table (cascades on delete)
     * - community_chest_card_id: FK to community_chest_cards master table (cascades on delete)
     * - sort_order: shuffle position (1–16) for this game's draw sequence
     * - timestamps
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('game_community_chest_cards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('game_id');
            $table->foreign('game_id', 'fk_game_community_chest_cards_game_id')
                ->references('id')
                ->on('games')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('community_chest_card_id');
            $table->foreign('community_chest_card_id', 'fk_game_community_chest_cards_card_id')
                ->references('id')
                ->on('community_chest_cards')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('sort_order');
            $table->timestamps();

            $table->index(['game_id', 'sort_order'], 'idx_game_community_chest_cards_game_sort');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('game_community_chest_cards');
    }
};
