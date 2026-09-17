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

    'resend' => [
        'key' => env('RESEND_KEY'),
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
    'shopee' => [
        'partner_id' => env('SHOPEE_PARTNER_ID'),
        'partner_key' => env('SHOPEE_PARTNER_KEY'),
    ],
    
    'tiktok' => [
        'app_key' => env('TIKTOK_APP_KEY'),
        'app_secret' => env('TIKTOK_APP_SECRET'),
        'redirect_uri' => env('TIKTOK_REDIRECT_URI'),
        'open_url' => env('APP_ENV') === 'production' 
            ? env('TIKTOK_OPEN_URL_PRODUCTION', 'https://services.tiktokshop.com/open/authorize')
            : env('TIKTOK_OPEN_URL_SANDBOX', 'https://services.tiktokshop.com/open/authorize'),
        'api_url' => env('APP_ENV') === 'production'
            ? env('TIKTOK_API_URL_PRODUCTION', 'https://open-api.tiktokglobalshop.com')
            : env('TIKTOK_API_URL_SANDBOX', 'https://open-api.tiktokglobalshop.com'),
        'auth_url' => env('APP_ENV') === 'production'
            ? env('TIKTOK_AUTH_URL_PRODUCTION', 'https://auth.tiktok-shops.com')
            : env('TIKTOK_AUTH_URL_SANDBOX', 'https://auth.tiktok-shops.com'),
    ],

];
