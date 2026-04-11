<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the chance_cards table to store the shuffled Chance deck for each game.
     * Columns:
     * - id: auto-incrementing primary key
     * - game_id: FK to games table (each deck belongs to one game)
     * - action: enum of ChanceCardAction values driving game-logic behaviour
     * - text: the card's display text shown to the player
     * - amount: nullable monetary amount for collect/pay actions
     * - house_cost: nullable per-house repair cost for property_repairs action
     * - hotel_cost: nullable per-hotel repair cost for property_repairs action
     * - target: nullable board-space name for advance_to / advance_to_nearest actions
     * - spaces: nullable number of spaces for move_back action
     * - sort_order: shuffle position (1–16) determining draw sequence
     * - timestamps
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('chance_cards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('game_id');
            $table->foreign('game_id', 'fk_chance_cards_game_id')
                ->references('id')
                ->on('games')
                ->cascadeOnDelete();
            $table->enum('action', [
                'advance_to',
                'advance_to_nearest',
                'collect',
                'pay',
                'pay_each_player',
                'move_back',
                'go_to_jail',
                'get_out_of_jail_free',
                'property_repairs',
            ]);
            $table->string('text');
            $table->unsignedInteger('amount')->nullable();
            $table->unsignedInteger('house_cost')->nullable();
            $table->unsignedInteger('hotel_cost')->nullable();
            $table->string('target')->nullable();
            $table->unsignedTinyInteger('spaces')->nullable();
            $table->unsignedTinyInteger('sort_order');
            $table->timestamps();

            $table->index(['game_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('chance_cards');
    }
};
