<?php

return [
    'paths'             => ['api/*', 'sanctum/csrf-cookie', '*'],
    'allowed_methods'   => ['POST', 'GET', 'OPTIONS', 'PUT', 'DELETE'],
    'allowed_origins'   => [
        'https://papayawhip-starling-859279.hostingersite.com',
        'https://plum-badger-438049.hostingersite.com',
        'https://sandybrown-panther-435356.hostingersite.com',
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ],
    'allowed_headers'   => ['*'],
    'supports_credentials' => true, // needed for cookies / Sanctum
];
