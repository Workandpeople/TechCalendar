<?php

return [
    'log_path' => env('HEALTH_LOG_PATH', storage_path('logs/laravel.log')),
    'disk_warning_percent' => (float) env('HEALTH_DISK_WARNING_PERCENT', 15),
    'disk_failure_percent' => (float) env('HEALTH_DISK_FAILURE_PERCENT', 5),
];
