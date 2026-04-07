<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the games table with the following columns:
     * - id: auto-incrementing primary key
     * - name: the name of the game
     * - user_id: FK to users table (the user who created the game)
     * - status: enum representing game state (in_progress or finished)
     * - created_at / updated_at: timestamps
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->index();
            $table->enum('status', ['in_progress', 'finished'])->default('in_progress')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
