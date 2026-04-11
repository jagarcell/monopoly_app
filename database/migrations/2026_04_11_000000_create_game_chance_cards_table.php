<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the game_chance_cards pivot table which associates a game with its
     * shuffled Chance deck. Card definitions live in the chance_cards master table;
     * only the per-game draw order (sort_order) is stored here.
     * Columns:
     * - id: auto-incrementing primary key
     * - game_id: FK to games table (cascades on delete)
     * - chance_card_id: FK to chance_cards master table (cascades on delete)
     * - sort_order: shuffle position (1–16) for this game's draw sequence
     * - timestamps
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('game_chance_cards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('game_id');
            $table->foreign('game_id', 'fk_game_chance_cards_game_id')
                ->references('id')
                ->on('games')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('chance_card_id');
            $table->foreign('chance_card_id', 'fk_game_chance_cards_chance_card_id')
                ->references('id')
                ->on('chance_cards')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('sort_order');
            $table->timestamps();

            $table->index(['game_id', 'sort_order'], 'idx_game_chance_cards_game_sort');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('game_chance_cards');
    }
};
