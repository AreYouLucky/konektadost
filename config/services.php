<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-1.5-flash'),
    ],

    // Which Responses API serves GeoAgent and the assessment AI: "gates" or "openai".
    // Defaults to GATES as soon as GATES_API_KEY is set; OpenAI stays as the fallback.
    'ai' => [
        'provider' => env('AI_PROVIDER', env('GATES_API_KEY') ? 'gates' : 'openai'),
    ],

    // GATES LLM gateway (OpenAI-compatible Responses API; see gates_llm-0.2.0/README.md).
    'gates' => [
        'api_key' => env('GATES_API_KEY'),
        // The gateway origin only, without /v1 or /responses.
        'base_url' => env('GATES_BASE_URL'),
        // Must be a model returned by `php artisan ai:check`.
        'model' => env('GATES_MODEL', 'openai.gpt-6-luna'),
        'timeout' => (int) env('GATES_TIMEOUT', 60),
        // The gateway's default body limit; requests above it are refused before sending.
        'max_request_bytes' => (int) env('GATES_MAX_REQUEST_BYTES', 2_000_000),
        // Verified GATES option for faster structured explanations; empty disables it.
        'reasoning_effort' => env('GATES_REASONING_EFFORT', 'low'),
        'ca_bundle' => env('GATES_CA_BUNDLE', env('OPENAI_CA_BUNDLE')),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 60),
        'ca_bundle' => env('OPENAI_CA_BUNDLE'),
    ],

];
