<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the community_chest_cards master table storing the canonical 16-card
     * Community Chest deck. Card definitions are fixed and shared across all games;
     * per-game shuffle order is stored in the game_community_chest_cards pivot table.
     * Columns:
     * - id: auto-incrementing primary key
     * - action: enum of CommunityChestCardAction values driving game-logic behaviour
     * - text: the card's display text shown to the player
     * - amount: nullable monetary amount for collect/pay/collect_from_each_player actions
     * - house_cost: nullable per-house repair cost for property_repairs action
     * - hotel_cost: nullable per-hotel repair cost for property_repairs action
     * - target: nullable board-space name for advance_to action
     * - timestamps
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('community_chest_cards', function (Blueprint $table) {
            $table->id();
            $table->enum('action', [
                'advance_to',
                'collect',
                'pay',
                'go_to_jail',
                'get_out_of_jail_free',
                'collect_from_each_player',
                'property_repairs',
            ]);
            $table->string('text');
            $table->unsignedInteger('amount')->nullable();
            $table->unsignedInteger('house_cost')->nullable();
            $table->unsignedInteger('hotel_cost')->nullable();
            $table->string('target')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('community_chest_cards');
    }
};
