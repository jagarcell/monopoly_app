<?php

namespace Database\Seeders;

use App\Models\User;
use App\Repositories\ChanceCardRepository;
use App\Repositories\CommunityChestCardRepository;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Logic: Seeds the canonical 16-card master decks for both Chance and Community
     * Chest into their respective tables, then creates a default test user. The
     * master decks must exist before any game is created so that createDeckForGame
     * can reference card IDs through the pivot tables.
     *
     * @return void
     */
    public function run(): void
    {
        /** @var ChanceCardRepository $chanceCards */
        $chanceCards = app(ChanceCardRepository::class);
        $chanceCards->seedMasterDeck();

        /** @var CommunityChestCardRepository $communityChestCards */
        $communityChestCards = app(CommunityChestCardRepository::class);
        $communityChestCards->seedMasterDeck();

        User::factory()->create([
            'name'  => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
