<?php

return [
    'enabled' => (bool) env('SIMULATION_MODE', false),
    'allow_external_apis' => (bool) env('SIMULATION_ALLOW_EXTERNAL_APIS', false),
    'dataset' => env('SIMULATION_DATASET') ?: storage_path('app/private/simulation/dataset'),
];
