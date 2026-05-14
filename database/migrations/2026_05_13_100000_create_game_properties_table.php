<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Logic: Creates the game_properties table which tracks property ownership
     * within a game. Each row represents one board square owned by one player.
     * A unique constraint on (game_id, square_index) prevents two players from
     * owning the same square in the same game.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('game_properties', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('game_id');
            $table->unsignedTinyInteger('square_index');
            $table->unsignedTinyInteger('owner_join_order');
            $table->unsignedInteger('purchase_price');
            $table->timestamps();

            $table->unique(['game_id', 'square_index']);
            $table->index('game_id');

            $table->foreign('game_id')
                ->references('id')
                ->on('games')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('game_properties');
    }
};
