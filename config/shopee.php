<?php
return [
    'partner_id' => env('SHOPEE_PARTNER_ID'),
    'partner_key' => env('SHOPEE_PARTNER_KEY'),
    'base_url' => env('APP_ENV') === 'production' 
        ? env('SHOPEE_BASE_URL_PRODUCTION', 'https://partner.shopeemobile.com') 
        : env('SHOPEE_BASE_URL_SANDBOX', 'https://openplatform.sandbox.test-stable.shopee.sg'),
    'redirect_uri' => env('APP_ENV') === 'production'
        ? env('SHOPEE_REDIRECT_URI_PRODUCTION', 'https://{your-domain}/shopee/callback')
        : env('SHOPEE_REDIRECT_URI_SANDBOX', 'https://groggy-enjoyable-unfair.ngrok-free.dev/shopee/callback'),
];