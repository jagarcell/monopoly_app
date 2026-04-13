<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Allows guest players (invited via email, no user account) to be recorded
     * in game_player_icons by:
     * 1. Making user_id nullable so rows without an authenticated user are valid.
     * 2. Adding a nullable invitation_id FK → game_invitations.id so the row can
     *    be linked back to the specific invitation token used to join.
     * 3. Adding a unique constraint on (game_id, invitation_id) so one invitation
     *    cannot be used to claim multiple icons.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('game_player_icons', function (Blueprint $table) {
            // Drop the original non-nullable FK so we can redefine it as nullable.
            $table->dropForeign('fk_gpi_user_id');

            $table->unsignedBigInteger('user_id')->nullable()->change();

            $table->foreign('user_id', 'fk_gpi_user_id')
                ->references('id')->on('users')->onDelete('cascade');

            $table->unsignedBigInteger('invitation_id')->nullable()->after('user_id');

            $table->foreign('invitation_id', 'fk_gpi_invitation_id')
                ->references('id')->on('game_invitations')->onDelete('set null');

            $table->index('invitation_id', 'idx_gpi_invitation_id');

            // One invitation → one icon slot
            $table->unique(['game_id', 'invitation_id'], 'uq_gpi_game_invitation');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('game_player_icons', function (Blueprint $table) {
            $table->dropUnique('uq_gpi_game_invitation');
            $table->dropIndex('idx_gpi_invitation_id');
            $table->dropForeign('fk_gpi_invitation_id');
            $table->dropColumn('invitation_id');

            $table->dropForeign('fk_gpi_user_id');
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id', 'fk_gpi_user_id')
                ->references('id')->on('users')->onDelete('cascade');
        });
    }
};
