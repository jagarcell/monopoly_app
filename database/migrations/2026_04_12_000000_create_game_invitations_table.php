<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the game_invitations table that records invitation tokens sent to
     * players by a game creator. Each row represents one pending or accepted
     * invitation to a specific game.
     *
     * Columns:
     * - id:          BIGINT UNSIGNED auto-increment primary key.
     * - game_id:     FK → games.id (cascade delete); indexed for lookups.
     * - email:       The email address of the invited player; indexed.
     * - token:       UUID used in the join URL; must be unique.
     * - accepted_at: Timestamp set when the player accepts; null = pending.
     * - expires_at:  Timestamp after which the invitation is no longer valid.
     * - timestamps:  created_at / updated_at managed by Laravel.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('game_invitations', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('game_id');
            $table->string('email');
            $table->uuid('token');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('expires_at');

            $table->timestamps();

            $table->foreign('game_id', 'fk_gi_game_id')
                ->references('id')->on('games')->onDelete('cascade');

            $table->index('game_id', 'idx_gi_game_id');
            $table->index('email', 'idx_gi_email');
            $table->unique('token', 'uq_gi_token');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('game_invitations');
    }
};
