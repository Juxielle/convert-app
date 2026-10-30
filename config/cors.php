<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'DELETE'],
    // The SPA and API share one origin. No third-party origin is authorized.
    'allowed_origins' => [],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Content-Type', 'X-CSRF-TOKEN'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
