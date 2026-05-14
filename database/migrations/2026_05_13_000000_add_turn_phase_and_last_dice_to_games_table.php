<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add turn_phase, last_die1, and last_die2 columns to the games table.
 *
 * These columns allow the frontend to restore the dice display and the
 * Roll/Done button state correctly after a page refresh:
 *
 *   turn_phase — 'roll' (active player has not yet rolled) or 'done'
 *                (active player has rolled and must click Done). Defaults
 *                to 'roll' so all existing games start in a valid state.
 *   last_die1  — face value of die 1 from the most recent roll (1–6).
 *                Nullable: null before the first roll of each turn.
 *   last_die2  — face value of die 2 from the most recent roll (1–6).
 *                Nullable: null before the first roll of each turn.
 *
 * Neither last_die1 nor last_die2 is ever used in WHERE/JOIN/ORDER BY
 * clauses, so no index is added.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Logic: Adds turn_phase (string with default 'roll') and last_die1 /
     * last_die2 (nullable unsigned tinyint, range 1–6) to the games table,
     * positioned after current_turn_join_order.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->string('turn_phase')->default('roll')->after('current_turn_join_order');
            $table->unsignedTinyInteger('last_die1')->nullable()->after('turn_phase');
            $table->unsignedTinyInteger('last_die2')->nullable()->after('last_die1');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Logic: Drops turn_phase, last_die1, and last_die2, restoring the
     * games table to its prior schema.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['turn_phase', 'last_die1', 'last_die2']);
        });
    }
};
