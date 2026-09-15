<?php

// Only the keys that differ from the package defaults (vendor/inertiajs/inertia-laravel/config/inertia.php).
return [
    'pages' => [
        'ensure_pages_exist' => false,
        'paths' => [resource_path('js/Pages')],
        'extensions' => ['jsx', 'js'],
    ],

    'testing' => [
        'ensure_pages_exist' => true,
    ],
];
