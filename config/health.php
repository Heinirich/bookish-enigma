<?php

return [
    'target_url' => env('HEALTH_TARGET_URL', 'http://127.0.0.1:8000/healthz'),

    // Cache key holding the live chaos profile shaping /healthz responses.
    'chaos_cache_key' => 'connector:chaos-profile',

    'default_profile' => [
        'scenario_key' => null,
        'label' => 'Healthy',
        'base_latency_ms' => 25,
        'jitter_ms' => 15,
        'error_rate' => 0.0,
        'deploy_sha' => null,
        'activated_at' => null,
    ],
];
