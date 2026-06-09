<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Monopoly game configuration
    |--------------------------------------------------------------------------
    |
    | Centralised defaults for game mechanics. Use environment variables to
    | override these values per-deployment. If a value is not set elsewhere
    | in the app, reference these via `config('monopoly.bank.houses')` etc.
    |
    */

    'bank' => [
        // Number of houses available in the bank by default
        'houses' => env('MONOPOLY_BANK_HOUSES', 32),

        // Number of hotels available in the bank by default
        'hotels' => env('MONOPOLY_BANK_HOTELS', 12),
    ],
];
