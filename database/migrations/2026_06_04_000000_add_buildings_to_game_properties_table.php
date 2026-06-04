<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Logic: Adds `houses_count` and `has_hotel` columns to `game_properties`
     * so the service layer can persist buildings per property.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('game_properties', function (Blueprint $table) {
            if (!Schema::hasColumn('game_properties', 'houses_count')) {
                $table->unsignedTinyInteger('houses_count')->default(0)->after('is_mortgaged');
            }

            if (!Schema::hasColumn('game_properties', 'has_hotel')) {
                $table->boolean('has_hotel')->default(false)->after('houses_count');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('game_properties', function (Blueprint $table) {
            if (Schema::hasColumn('game_properties', 'has_hotel')) {
                $table->dropColumn('has_hotel');
            }

            if (Schema::hasColumn('game_properties', 'houses_count')) {
                $table->dropColumn('houses_count');
            }
        });
    }
};
