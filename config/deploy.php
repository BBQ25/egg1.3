<?php

return [
    'github' => [
        'enabled' => filter_var(env('EGGS_DEPLOY_WEBHOOK_ENABLED', false), FILTER_VALIDATE_BOOL),
        'secret' => env('EGGS_DEPLOY_WEBHOOK_SECRET'),
        'repository' => env('EGGS_DEPLOY_WEBHOOK_REPOSITORY', 'BBQ25/egg1.3'),
        'branch' => env('EGGS_DEPLOY_WEBHOOK_BRANCH', 'main'),
        'trigger_file' => env('EGGS_DEPLOY_WEBHOOK_TRIGGER_FILE', storage_path('app/deploy/github-webhook-trigger.json')),
        'log_file' => env('EGGS_DEPLOY_WEBHOOK_LOG_FILE', '/www/wwwlogs/eggs-auto-sync.log'),
    ],
];
