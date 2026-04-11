<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the player_icons table — a standalone catalogue of the 8 selectable
     * player token icons. Each row represents one icon that a player may choose
     * when joining a game.
     *
     * Columns:
     * - id:         TINYINT UNSIGNED auto-increment primary key.
     * - name:       Display name of the token (e.g. "Top Hat", "Scottie Dog").
     * - image_url:  Relative or absolute path/URL to the icon asset.
     * - sort_order: TINYINT UNSIGNED; explicit display order in the icon picker (1–8).
     *               Indexed to support ORDER BY queries efficiently.
     * - timestamps: created_at / updated_at managed by Laravel.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('player_icons', function (Blueprint $table) {
            $table->tinyIncrements('id');
            $table->string('name', 100)->unique();
            $table->string('image_url', 500);
            $table->unsignedTinyInteger('sort_order')->index();
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
        Schema::dropIfExists('player_icons');
    }
};
