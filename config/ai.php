<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Motafawweq AI Microservice Configuration
    |--------------------------------------------------------------------------
    |
    | Central configuration for Laravel <-> Motafawweq-ai (FastAPI) calls.
    | Supports Speech-to-Text (Whisper large-v3) and future EdTech AI tasks.
    |
    */

    'service_url' => rtrim((string) env('AI_SERVICE_URL', 'http://127.0.0.1:8001'), '/'),

    'internal_secret' => (string) env('AI_SERVICE_SECRET', 'motafawweq-local-ai-secret-change-in-prod'),

    'connect_timeout' => (int) env('AI_CONNECT_TIMEOUT', 5),

    'request_timeout' => (int) env('AI_REQUEST_TIMEOUT', 120),

    'stt' => [
        'default_model' => (string) env('AI_STT_MODEL', 'large-v3'),
        'max_media_bytes' => (int) env('AI_STT_MAX_MEDIA_BYTES', 104857600), // 100 MB
    ],

];
