<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Ziggy Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration determines which routes will be included in the
    | generated Ziggy routes file.
    |
    */

    'except' => [
        'sanctum.*',
        'storage.*',
        '_debugbar.*',
    ],

    'groups' => [
        'web' => [
            'edificios.*',
            'login',
            'register',
            'logout',
            'dashboard',
        ],
    ],
];
