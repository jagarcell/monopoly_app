<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Logic: Adds consecutive_doubles_count to games so the service can enforce
     * Monopoly triple-double jail rules within a single turn across API calls.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->unsignedTinyInteger('consecutive_doubles_count')
                ->default(0)
                ->after('last_die2');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Logic: Removes consecutive_doubles_count, restoring the previous games
     * schema when rolling back.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->dropColumn('consecutive_doubles_count');
        });
    }
};
