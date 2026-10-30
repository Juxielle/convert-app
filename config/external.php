<?php

return [
    // Commons CC0 requires a verified local marker and contact. A boolean alone never enables it.
    'enabled' => (bool) env('EXTERNAL_DOWNLOADS_ENABLED', false),
    'sources' => [],
    'max_redirects' => 3,
    'max_bytes' => 25_000_000,
    'timeout_seconds' => 30,
    'hourly_jobs' => 2,
    'daily_jobs' => 5,
    'user_agent' => env('EXTERNAL_USER_AGENT', ''),
];
