<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Base Path
    |--------------------------------------------------------------------------
    |
    | Some deployments serve the app from a subdirectory (for example,
    | "/sumacot/egg1.3") instead of the web root. Keep that path separate
    | from APP_URL so local root-host setups and subdirectory setups can be
    | configured independently. APP_URL path is still used as a fallback for
    | backward compatibility when APP_WEB_PATH is not set.
    |
    */

    'base_path' => trim((string) env(
        'APP_WEB_PATH',
        (string) parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_PATH)
    ), '/'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. Asia/Manila is
    | the safe fallback until the admin-configured timezone is loaded.
    |
    */

    'timezone' => env('APP_TIMEZONE', 'Asia/Manila'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Login Click Bypass
    |--------------------------------------------------------------------------
    |
    | Hidden click-to-login rules for the cover login screen. Defaults are
    | optional and may be seeded into the database on first use.
    |
    */

    'login_click_bypass' => [
        'allowed' => (bool) env('LOGIN_CLICK_BYPASS_ALLOWED', true),
        'enabled_default' => (bool) env('LOGIN_CLICK_BYPASS_ENABLED', true),
        'default_rules' => [
            [
                'click_count' => 3,
                'window_seconds' => (int) env('LOGIN_CLICK_BYPASS_ADMIN_WINDOW', 3),
                'username' => env('LOGIN_CLICK_BYPASS_ADMIN_USERNAME', 'admin'),
                'label' => 'Seed: Admin quick login',
            ],
            [
                'click_count' => 5,
                'window_seconds' => (int) env('LOGIN_CLICK_BYPASS_OWNER_WINDOW', 3),
                'username' => env('LOGIN_CLICK_BYPASS_OWNER_USERNAME', ''),
                'label' => 'Seed: Owner quick login',
            ],
            [
                'click_count' => 7,
                'window_seconds' => (int) env('LOGIN_CLICK_BYPASS_STAFF_WINDOW', 3),
                'username' => env('LOGIN_CLICK_BYPASS_STAFF_USERNAME', ''),
                'label' => 'Seed: Staff quick login',
            ],
        ],
    ],

];
