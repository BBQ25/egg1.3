<?php

use App\Enums\UserRole;

return [
    /*
    |--------------------------------------------------------------------------
    | Legacy Doc-Ease Gateway
    |--------------------------------------------------------------------------
    |
    | These values define the temporary Laravel-controlled access boundary for
    | the legacy Doc-Ease subtree hosted in /public/doc-ease.
    |
    */
    'enabled' => (bool) env('DOC_EASE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Legacy Entrypoint
    |--------------------------------------------------------------------------
    |
    | Public URL path to the legacy app entry file.
    |
    */
    'entrypoint' => (string) env('DOC_EASE_ENTRYPOINT', '/doc-ease/index.php'),

    /*
    |--------------------------------------------------------------------------
    | Allowed Roles
    |--------------------------------------------------------------------------
    |
    | Comma-separated role values allowed to access the Laravel Doc-Ease
    | gateway pages.
    |
    */
    'allowed_roles' => array_values(array_filter(array_map(
        static fn (string $role): string => trim($role),
        explode(',', (string) env('DOC_EASE_ALLOWED_ROLES', UserRole::ADMIN->value))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Bridge Mode (Laravel -> Doc-Ease Session Bootstrap)
    |--------------------------------------------------------------------------
    |
    | When enabled, Laravel launch flow redirects to a signed bridge endpoint
    | in the legacy app. The bridge endpoint validates the token and creates
    | native Doc-Ease session keys before redirecting to the entrypoint.
    |
    */
    'bridge' => [
        'enabled' => (bool) env('DOC_EASE_BRIDGE_ENABLED', false),
        'path' => (string) env('DOC_EASE_BRIDGE_PATH', '/doc-ease/bridge-login.php'),
        'secret' => (string) env('DOC_EASE_BRIDGE_SECRET', ''),
        'ttl_seconds' => (int) env('DOC_EASE_BRIDGE_TTL_SECONDS', 90),
        'admin_is_superadmin' => (bool) env('DOC_EASE_BRIDGE_ADMIN_IS_SUPERADMIN', true),
        'role_map' => [
            'ADMIN' => (string) env('DOC_EASE_BRIDGE_ROLE_ADMIN', 'admin'),
            'OWNER' => (string) env('DOC_EASE_BRIDGE_ROLE_OWNER', 'teacher'),
            'WORKER' => (string) env('DOC_EASE_BRIDGE_ROLE_WORKER', 'teacher'),
            'CUSTOMER' => (string) env('DOC_EASE_BRIDGE_ROLE_CUSTOMER', 'student'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Direct-Path Lock Coordination
    |--------------------------------------------------------------------------
    |
    | These env values are consumed by the legacy app to enforce "Laravel
    | gateway first" access when direct-path lock is enabled.
    |
    */
    'direct_lock' => [
        'enabled' => (bool) env('DOC_EASE_DIRECT_PATH_LOCK', false),
        'gateway_path' => (string) env('DOC_EASE_LARAVEL_GATEWAY_PATH', '/legacy/doc-ease'),
    ],
];
