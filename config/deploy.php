<?php

return [
    'github' => [
        'enabled' => filter_var(env('EGGS_DEPLOY_WEBHOOK_ENABLED', false), FILTER_VALIDATE_BOOL),
        'secret' => env('EGGS_DEPLOY_WEBHOOK_SECRET'),
        'repository' => env('EGGS_DEPLOY_WEBHOOK_REPOSITORY', 'BBQ25/egg1.3'),
        'branch' => env('EGGS_DEPLOY_WEBHOOK_BRANCH', 'main'),
        'script' => env('EGGS_DEPLOY_WEBHOOK_SCRIPT', base_path('scripts/eggs-auto-sync.sh')),
        'log_file' => env('EGGS_DEPLOY_WEBHOOK_LOG_FILE', '/www/wwwlogs/eggs-auto-sync.log'),
    ],
];
