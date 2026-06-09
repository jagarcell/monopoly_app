<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_pending_builds', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('game_id');
            $table->unsignedInteger('owner_join_order');
            $table->unsignedInteger('square_index');
            $table->integer('houses_delta')->default(0); // +1 for a house build
            $table->boolean('has_hotel')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['game_id']);
            $table->index(['game_id', 'owner_join_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_pending_builds');
    }
};
