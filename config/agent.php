<?php

return [
    /*
    | Jan exposes an OpenAI-compatible API on localhost. The same endpoint serves
    | the local llama.cpp model and Jan's remote providers, so switching the
    | synthesis model is a one-key change with no code impact.
    */
    'jan' => [
        'base_url' => env('JAN_BASE_URL', 'http://127.0.0.1:1337/v1'),
        'api_key' => env('JAN_API_KEY', ''),
        'timeout' => (int) env('JAN_TIMEOUT', 180),
    ],

    'models' => [
        // Cheap, fast steps: tool selection during exploration.
        'explore' => env('JAN_MODEL', 'Jan-v3.5-4B-Q4_K_XL'),
        // The step that produces hypotheses. Point at a stronger model if 4B underdelivers.
        'synthesis' => env('JAN_SYNTHESIS_MODEL', env('JAN_MODEL', 'Jan-v3.5-4B-Q4_K_XL')),
    ],

    'max_tool_iterations' => (int) env('AGENT_MAX_TOOL_ITERATIONS', 6),

    /*
    | Ablation switch, for measuring what each guardrail actually contributes.
    | Never set outside an eval run.
    |
    |   null            everything on
    |   no-correlator   withhold the computed changepoint and ranked candidates,
    |                   leaving the model to correlate raw metrics against a bare
    |                   deployment list on its own
    |   no-validator    accept every hypothesis unchecked: no repair turn, no
    |                   rejection, so fabricated citations reach the output
    */
    'ablation' => null,

    // Write actions hit real Slack/Jira/Notion. Off by default: they queue for approval.
    'auto_approve_writes' => (bool) env('AGENT_AUTO_APPROVE_WRITES', false),

    'detection' => [
        // Latency spike: last `spike_window` p95 vs the preceding `baseline_window` p95.
        'baseline_window_minutes' => 30,
        'spike_window_minutes' => 3,
        'latency_multiplier' => 3.0,
        'min_baseline_samples' => 20,
        'error_rate_threshold' => 0.20,
        'cooldown_minutes' => 15,
    ],

    'correlation' => [
        // How far either side of the changepoint to consider deployments.
        'lookback_minutes' => 30,
        'lookahead_minutes' => 5,
    ],
];
