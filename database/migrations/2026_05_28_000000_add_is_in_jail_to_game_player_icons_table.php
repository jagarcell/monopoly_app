<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('game_player_icons', function (Blueprint $table) {
            $table->boolean('is_in_jail')->default(false)->after('square_index');
            $table->index('is_in_jail');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('game_player_icons', function (Blueprint $table) {
            $table->dropIndex(['is_in_jail']);
            $table->dropColumn('is_in_jail');
        });
    }
};
