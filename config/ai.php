<?php

return [
    'engine_url' => rtrim((string) env('AI_ENGINE_URL', 'http://127.0.0.1:8010'), '/'),
    'api_key' => env('AI_ENGINE_API_KEY'),
    'timeout' => (int) env('AI_ENGINE_TIMEOUT', 30),
    'bulk_timeout' => (int) env('AI_ENGINE_BULK_TIMEOUT', 120),
];
