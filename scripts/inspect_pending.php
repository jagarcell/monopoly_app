<?php

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    // Try project root in container
    $autoload = __DIR__ . '/vendor/autoload.php';
}
require $autoload;

use Illuminate\Support\Facades\DB;

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$rows = DB::table('game_pending_builds')->where('square_index', 3)->get()->toArray();

$result = ['pending' => array_map(function($r){ return (array)$r; }, $rows)];

if (empty($rows)) {
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit(0);
}

$first = $rows[0];
$gameId = (int) $first->game_id;
$owner = (int) $first->owner_join_order;

$gpi = DB::table('game_player_icons')->where('game_id', $gameId)->where('join_order', $owner)->first();

if ($gpi) {
    $app->make('log')->info('Found player icon', ['game' => $gameId, 'join_order' => $owner, 'gpi' => (array)$gpi]);

    if (!empty($gpi->user_id)) {
        $userId = (int) $gpi->user_id;
        $gs = $app->make(App\Services\GameService::class);
        $props = $gs->getPlayerPropertiesForUser($gameId, $userId);
        $result['player_props'] = $props;
    } else {
        $inv = (int) $gpi->invitation_id;
        $gs = $app->make(App\Services\GameService::class);
        $props = $gs->getPlayerPropertiesForGuest($gameId, $inv);
        $result['player_props'] = $props;
    }
} else {
    $result['player_props'] = null;
}

echo json_encode($result, JSON_PRETTY_PRINT);
