<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Motafawweq AI Microservice Configuration
    |--------------------------------------------------------------------------
    |
    | Central configuration for Laravel <-> Motafawweq-ai (FastAPI) calls.
    | Supports Hybrid Speech-to-Text (QwenCleo-ASR + Whisper large-v3) and
    | Local Bilingual LLM (Chapters, NotebookLM Concept Maps, Smart Quizzes).
    |
    */

    'service_url' => rtrim((string) env('AI_SERVICE_URL', 'http://127.0.0.1:8001'), '/'),

    'internal_secret' => (string) env('AI_SERVICE_SECRET', 'motafawweq-local-ai-secret-change-in-prod'),

    'connect_timeout' => (int) env('AI_CONNECT_TIMEOUT', 5),

    'request_timeout' => (int) env('AI_REQUEST_TIMEOUT', 120),

    'stt' => [
        'default_model' => (string) env('AI_STT_MODEL', 'mohammedaly22/QwenCleo-ASR'),
        'max_media_bytes' => (int) env('AI_STT_MAX_MEDIA_BYTES', 104857600), // 100 MB
    ],

    'llm' => [
        'default_model' => (string) env('AI_LLM_MODEL', 'unsloth/Qwen2.5-3B-Instruct-bnb-4bit'),
        'default_quiz_count' => (int) env('AI_QUIZ_COUNT', 5),
    ],

];
