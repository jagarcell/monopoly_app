<?php

namespace App\Http\Controllers;

use App\Repositories\PlayerIconRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class PlayerIconController extends Controller
{
    public function __construct(
        private readonly PlayerIconRepository $playerIconRepository,
    ) {}

    /**
     * Return all available player icons ordered by sort_order.
     *
     * Logic: Delegates to PlayerIconRepository::getAll() which selects only the
     * columns needed for the icon picker (id, name, image_url, sort_order) and
     * orders by sort_order ascending. Returns them under the descriptive key
     * 'player_icons' for a consistent API response shape.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        try {
            $icons = $this->playerIconRepository->getAll();

            return response()->json(['player_icons' => $icons]);
        } catch (\Throwable $e) {
            Log::error('Failed to fetch player icons', [
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to fetch player icons.',
                'errors'  => [],
            ], 500);
        }
    }
}
