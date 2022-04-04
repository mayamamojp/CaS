<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Laravel CORS
    |--------------------------------------------------------------------------
    |
    | allowedOrigins, allowedHeaders and allowedMethods can be set to array('*')
    | to accept any value.
    |
    */

    'supportsCredentials' => false,
    'allowedOrigins' => ['http://localhost:*', 'https://localhost:*'],
    'allowedHeaders' => ['Access-Control-Allow-Origin', 'X-CSRF-TOKEN', 'Content-Type', 'X-Requested-With'],
    'allowedMethods' => ['*'],
    'exposedHeaders' => [],
    'maxAge' => 0,
];
