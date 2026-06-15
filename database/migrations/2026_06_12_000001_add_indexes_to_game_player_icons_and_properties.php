<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_player_icons', function (Blueprint $table) {
            if (Schema::hasColumn('game_player_icons', 'game_id')) {
                $table->index('game_id', 'gpi_game_id_index');
            }
            if (Schema::hasColumn('game_player_icons', 'join_order')) {
                $table->index(['game_id', 'join_order'], 'gpi_game_join_order_index');
            }
        });

        Schema::table('game_properties', function (Blueprint $table) {
            if (Schema::hasColumn('game_properties', 'game_id') && Schema::hasColumn('game_properties', 'square_index')) {
                $table->index(['game_id', 'square_index'], 'gp_game_square_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('game_player_icons', function (Blueprint $table) {
            $table->dropIndex('gpi_game_id_index');
            $table->dropIndex('gpi_game_join_order_index');
        });

        Schema::table('game_properties', function (Blueprint $table) {
            $table->dropIndex('gp_game_square_index');
        });
    }
};
