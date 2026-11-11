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

    // The Stripe price for each plan and billing interval (Stripe dashboard ->
    // Product catalog). The amounts themselves live in App\Support\Plan - the
    // prices in Stripe must match them. Keys and the webhook secret are
    // Cashier's own: STRIPE_KEY, STRIPE_SECRET, STRIPE_WEBHOOK_SECRET.
    'stripe' => [
        'prices' => [
            'starter' => [
                'monthly' => env('STRIPE_PRICE_STARTER_MONTHLY'),
                'annual' => env('STRIPE_PRICE_STARTER_ANNUAL'),
            ],
            'pro' => [
                'monthly' => env('STRIPE_PRICE_PRO_MONTHLY'),
                'annual' => env('STRIPE_PRICE_PRO_ANNUAL'),
            ],
        ],
    ],

    // Cloudflare Turnstile - the bot check on sign-up, sign-in and password
    // reset (docs/PLAN.md section 4). Without a secret it's skipped (local
    // development); production refuses to start without one.
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret' => env('TURNSTILE_SECRET'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
