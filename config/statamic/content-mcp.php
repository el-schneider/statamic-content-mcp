<?php

declare(strict_types=1);

return [
    'tools' => [
        'site' => true,
        'blueprints' => true,
        'entries' => true,
        'globals' => true,
        'taxonomies' => true,
        'assets' => true,
        'search' => true,
    ],

    'collections' => null,

    'default_published' => false,

    'auth' => 'sanctum',

    'pagination' => [
        'default_limit' => 20,
        'max_limit' => 100,
    ],
];
