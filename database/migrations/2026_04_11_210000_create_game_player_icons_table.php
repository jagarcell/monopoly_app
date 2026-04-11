<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the game_player_icons pivot table that records which player icon
     * each user has selected for a specific game. A unique constraint on
     * (game_id, user_id) ensures one icon per player per game, and a unique
     * constraint on (game_id, player_icon_id) ensures no two players in the
     * same game share the same icon.
     *
     * Columns:
     * - id:             BIGINT UNSIGNED auto-increment primary key.
     * - game_id:        FK → games.id (cascade delete).
     * - user_id:        FK → users.id (cascade delete).
     * - player_icon_id: FK → player_icons.id (cascade delete).
     * - timestamps:     created_at / updated_at managed by Laravel.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('game_player_icons', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('game_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedTinyInteger('player_icon_id');

            $table->timestamps();

            $table->foreign('game_id', 'fk_gpi_game_id')
                ->references('id')->on('games')->onDelete('cascade');

            $table->foreign('user_id', 'fk_gpi_user_id')
                ->references('id')->on('users')->onDelete('cascade');

            $table->foreign('player_icon_id', 'fk_gpi_player_icon_id')
                ->references('id')->on('player_icons')->onDelete('cascade');

            // One icon per user per game
            $table->unique(['game_id', 'user_id'], 'uq_gpi_game_user');

            // No two players share the same icon in the same game
            $table->unique(['game_id', 'player_icon_id'], 'uq_gpi_game_icon');

            // Indexes for FK lookups
            $table->index('game_id', 'idx_gpi_game_id');
            $table->index('user_id', 'idx_gpi_user_id');
            $table->index('player_icon_id', 'idx_gpi_player_icon_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('game_player_icons');
    }
};
