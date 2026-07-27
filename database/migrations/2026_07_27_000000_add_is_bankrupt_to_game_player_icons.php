<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('game_player_icons', 'is_bankrupt')) {
            Schema::table('game_player_icons', function (Blueprint $table) {
                $table->boolean('is_bankrupt')->default(false)->after('capital');
                $table->index('is_bankrupt');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('game_player_icons', 'is_bankrupt')) {
            Schema::table('game_player_icons', function (Blueprint $table) {
                $table->dropIndex(['is_bankrupt']);
                $table->dropColumn('is_bankrupt');
            });
        }
    }
};
