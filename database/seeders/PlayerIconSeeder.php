<?php

namespace Database\Seeders;

use App\Models\PlayerIcon;
use Illuminate\Database\Seeder;

class PlayerIconSeeder extends Seeder
{
    /**
     * Seed the player_icons table with the 8 classic Monopoly token icons.
     *
     * Logic: Uses insertOrIgnore so the seeder is safe to run multiple times without
     * duplicating rows. Each entry maps a token display name to its SVG asset path
     * under /images/icons/ and assigns an explicit sort_order (1–8) that controls
     * the display order in the icon picker.
     *
     * @return void
     */
    public function run(): void
    {
        $now = now();

        $icons = [
            ['name' => 'Top Hat',     'image_url' => '/images/icons/top-hat.svg',     'sort_order' => 1],
            ['name' => 'Scottie Dog', 'image_url' => '/images/icons/scottie-dog.svg', 'sort_order' => 2],
            ['name' => 'Racing Car',  'image_url' => '/images/icons/racing-car.svg',  'sort_order' => 3],
            ['name' => 'Battleship',  'image_url' => '/images/icons/battleship.svg',  'sort_order' => 4],
            ['name' => 'Boot',        'image_url' => '/images/icons/boot.svg',        'sort_order' => 5],
            ['name' => 'Iron',        'image_url' => '/images/icons/iron.svg',        'sort_order' => 6],
            ['name' => 'Thimble',     'image_url' => '/images/icons/thimble.svg',     'sort_order' => 7],
            ['name' => 'Wheelbarrow', 'image_url' => '/images/icons/wheelbarrow.svg', 'sort_order' => 8],
        ];

        PlayerIcon::insertOrIgnore(
            array_map(
                fn (array $icon) => array_merge($icon, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ]),
                $icons,
            )
        );
    }
}
