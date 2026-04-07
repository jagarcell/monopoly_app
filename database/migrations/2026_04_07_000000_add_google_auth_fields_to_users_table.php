<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Logic: Adds google_id and avatar columns to the users table for Google OAuth support.
     * Makes the password column nullable so OAuth-only users don't need a password.
     * Indexes google_id since it will be used in WHERE clauses during login lookups.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable()->after('email')->index();
            $table->string('avatar')->nullable()->after('remember_token');
            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Logic: Removes the google_id and avatar columns and restores password to non-nullable.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['google_id']);
            $table->dropColumn(['google_id', 'avatar']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
