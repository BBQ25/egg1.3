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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'ces' => [
        'base_url' => env('CES_BASE_URL', 'https://ces.southernleytestateu.edu.ph'),
        'allowed_hosts' => array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', (string) env('CES_ALLOWED_HOSTS', 'ces.southernleytestateu.edu.ph'))
        ))),
        'campus' => env('CES_CAMPUS', 'Bontoc'),
        'username' => env('CES_USERNAME'),
        'password' => env('CES_PASSWORD'),
        'node_binary' => env('CES_NODE_BINARY', 'node'),
        'playwright_timeout' => (int) env('CES_PLAYWRIGHT_TIMEOUT', 240),
        'playwright_slow_mo_ms' => (int) env('CES_PLAYWRIGHT_SLOW_MO_MS', 0),
        'playwright_headless' => filter_var(env('CES_PLAYWRIGHT_HEADLESS', true), FILTER_VALIDATE_BOOL),
    ],

    'hrmis' => [
        'base_url' => env('HRMIS_BASE_URL', 'https://hrmis.southernleytestateu.edu.ph'),
        'allowed_hosts' => array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', (string) env('HRMIS_ALLOWED_HOSTS', 'hrmis.southernleytestateu.edu.ph'))
        ))),
        'my_dtr_path' => env('HRMIS_MY_DTR_PATH', '/my-dtr'),
        'signin_path' => env('HRMIS_SIGNIN_PATH', '/signin'),
        'google_dtr_auth_path' => env('HRMIS_GOOGLE_DTR_AUTH_PATH', '/auth/googledtr'),
        'email' => env('HRMIS_EMAIL', 'jsumacot@southernleytestateu.edu.ph'),
        'username' => env('HRMIS_USERNAME'),
        'password' => env('HRMIS_PASSWORD'),
        'node_binary' => env('HRMIS_NODE_BINARY', 'node'),
        'playwright_timeout' => (int) env('HRMIS_PLAYWRIGHT_TIMEOUT', 240),
        'playwright_slow_mo_ms' => (int) env('HRMIS_PLAYWRIGHT_SLOW_MO_MS', 350),
        'playwright_headless' => filter_var(env('HRMIS_PLAYWRIGHT_HEADLESS', true), FILTER_VALIDATE_BOOL),
    ],

    'reverse_geocoding' => [
        'base_url' => env('REVERSE_GEOCODING_BASE_URL', 'https://nominatim.openstreetmap.org'),
        'user_agent' => env('REVERSE_GEOCODING_USER_AGENT', env('APP_NAME', 'Laravel') . ' reverse-geocoder'),
        'email' => env('REVERSE_GEOCODING_EMAIL'),
        'timeout' => (int) env('REVERSE_GEOCODING_TIMEOUT', 10),
        'cache_seconds' => (int) env('REVERSE_GEOCODING_CACHE_SECONDS', 86400),
    ],

];
