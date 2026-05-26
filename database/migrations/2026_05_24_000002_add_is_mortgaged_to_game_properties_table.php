<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Logic: Adds an `is_mortgaged` flag to track whether an owned property
     * still collects rent, and adds a composite index for player property lookups.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('game_properties', function (Blueprint $table) {
            if (!Schema::hasColumn('game_properties', 'is_mortgaged')) {
                $table->boolean('is_mortgaged')->default(false)->after('purchase_price');
            }

            if (!$this->hasIndex('game_properties', 'game_properties_game_id_owner_join_order_index')) {
                $table->index(['game_id', 'owner_join_order'], 'game_properties_game_id_owner_join_order_index');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * Logic: Drops the mortgage flag and supporting composite index.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('game_properties', function (Blueprint $table) {
            if ($this->hasIndex('game_properties', 'game_properties_game_id_owner_join_order_index')) {
                $table->dropIndex('game_properties_game_id_owner_join_order_index');
            }

            if (Schema::hasColumn('game_properties', 'is_mortgaged')) {
                $table->dropColumn('is_mortgaged');
            }
        });
    }

    /**
     * Determine whether a table has a named index.
     *
     * Logic: Queries the MySQL index metadata so the migration can safely skip
     * recreating an index that is already present.
     *
     * @param  string  $tableName  The table to inspect.
     * @param  string  $indexName  The index name to look for.
     * @return bool
     */
    private function hasIndex(string $tableName, string $indexName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return false;
        }

        $rows = DB::select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            [$tableName, $indexName],
        );

        return !empty($rows);
    }
};