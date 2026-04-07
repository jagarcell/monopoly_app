<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Logic: Adds an apple_id column to the users table for Apple Sign In support.
     * The column is nullable to accommodate users who authenticate via other methods.
     * An index is added because apple_id is used in WHERE clauses on every Apple login.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('apple_id')->nullable()->after('google_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Logic: Drops the apple_id column and its index from the users table.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['apple_id']);
            $table->dropColumn('apple_id');
        });
    }
};
