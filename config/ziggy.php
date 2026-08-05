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
            'clientes.*',
            'login',
            'register',
            'logout',
            'dashboard',
            'customers',
            'inbox',
            'settings',
            'settings.*',
        ],
    ],
];
