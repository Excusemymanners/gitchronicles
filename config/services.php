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

    /*
    | GitChronicle's code analyzer (analyzer/analyze.py, Python + tree-sitter). "python" is the
    | interpreter that runs it: by default the one of analyzer/.venv (composer analyzer:install).
    */
    'analyzer' => [
        'python' => env('ANALYZER_PYTHON', base_path('analyzer/.venv/bin/python')),
    ],

    /*
    | GitHub. "webhook_secret" is the secret typed in the webhook's settings on GitHub: the webhook
    | (POST /webhooks/github) starts an incremental update at every push and every delivery is signed
    | with it (X-Hub-Signature-256). Without a secret the endpoint does not exist (404).
    | "token" is optional and used by the „Descoperă” leaderboard: without it the REST API works with
    | the public limits (10 searches per minute); a token raises them (30 searches per minute). Any
    | personal access token works, it needs no permissions because only public data is read.
    */
    'github' => [
        'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
        'token' => env('GITHUB_TOKEN'),
    ],

];
