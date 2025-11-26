<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout', 'auth/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['frontend-agrocean-fh3j.vercel.app','http://localhost:4200','http://localhost:3000'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,

];
