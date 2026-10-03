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

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
        'demo_mode' => env('AI_DEMO_MODE', true),
        'allow_generic_endpoint' => env('AI_ALLOW_GENERIC_ENDPOINT', false),
        'max_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 1200),
        'max_prompt_chars' => (int) env('AI_MAX_PROMPT_CHARS', 20000),
        'max_system_chars' => (int) env('AI_MAX_SYSTEM_CHARS', 4000),
        'requests_per_minute' => (int) env('AI_REQUESTS_PER_MINUTE', 5),
        'daily_request_limit' => (int) env('AI_DAILY_REQUEST_LIMIT', 100),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
