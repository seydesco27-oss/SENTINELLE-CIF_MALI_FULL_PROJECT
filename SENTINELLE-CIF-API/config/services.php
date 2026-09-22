<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

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

    'groq' => [
        'key' => env('GROQ_API_KEY'),
        'base_url' => env('LLM_BASE_URL', 'https://api.groq.com/openai/v1'),
        'model' => env('LLM_MODEL', 'openai/gpt-oss-120b'),
        'fallback_models' => array_filter(array_map(
            'trim',
            explode(',', (string) env('LLM_FALLBACK_MODELS', 'qwen/qwen3.8-27b,openai/gpt-oss-20b'))
        )),
        'ca_bundle' => env('LLM_CA_BUNDLE', storage_path('certificates/cacert.pem')),
    ],

    'ml' => [
        'url' => env('ML_SCORE_URL', 'http://127.0.0.1:8100'),
    ],

];
