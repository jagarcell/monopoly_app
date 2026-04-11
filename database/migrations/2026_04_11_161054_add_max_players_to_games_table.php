<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds a max_players column to the games table representing the maximum
     * number of players allowed in the game. Values are constrained to 2–8
     * by application-level validation (StoreGameRequest). The column defaults
     * to 2 to keep existing rows valid.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->tinyInteger('max_players')->unsigned()->default(2)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('max_players');
        });
    }
};
