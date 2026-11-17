<?php

declare(strict_types=1);

return [
    'domain' => env('HORIZON_DOMAIN'),
    'path' => env('HORIZON_PATH', 'horizon'),
    'use' => 'default',
    'prefix' => env('HORIZON_PREFIX', 'betterlink_horizon:'),
    'middleware' => ['web', 'auth'],

    'waits' => [
        'redis:webhooks-high' => 15,
        'redis:ai-diagnosis' => 30,
        'redis:notifications' => 30,
        'redis:rag-ingestion' => 120,
        'redis:campaigns-low' => 180,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,
    'memory_limit' => 256,

    'defaults' => [
        'supervisor-webhooks' => [
            'connection' => 'redis',
            'queue' => ['webhooks-high'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 10,
            'maxTime' => 0,
            'maxJobs' => 500,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 30,
            'nice' => 0,
        ],
        'supervisor-ai-diagnosis' => [
            'connection' => 'redis',
            'queue' => ['ai-diagnosis'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 12,
            'maxTime' => 0,
            'maxJobs' => 250,
            'memory' => 512,
            'tries' => 2,
            'timeout' => 60,
            'nice' => 0,
        ],
        'supervisor-notifications' => [
            'connection' => 'redis',
            'queue' => ['notifications'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 6,
            'maxTime' => 0,
            'maxJobs' => 500,
            'memory' => 256,
            'tries' => 5,
            'backoff' => [5, 15, 45, 120],
            'timeout' => 30,
            'nice' => 0,
        ],
        'supervisor-rag-and-campaigns' => [
            'connection' => 'redis',
            'queue' => ['rag-ingestion', 'campaigns-low', 'default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'size',
            'maxProcesses' => 4,
            'maxTime' => 0,
            'maxJobs' => 100,
            'memory' => 512,
            'tries' => 3,
            'timeout' => 300,
            'nice' => 5,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-webhooks' => [
                'minProcesses' => 4,
                'maxProcesses' => 12,
                'balanceMaxShift' => 2,
                'balanceCooldown' => 3,
            ],
            'supervisor-ai-diagnosis' => [
                'minProcesses' => 4,
                'maxProcesses' => 16,
                'balanceMaxShift' => 2,
                'balanceCooldown' => 3,
            ],
            'supervisor-notifications' => [
                'minProcesses' => 2,
                'maxProcesses' => 8,
            ],
            'supervisor-rag-and-campaigns' => [
                'minProcesses' => 2,
                'maxProcesses' => 6,
            ],
        ],

        'local' => [
            'supervisor-webhooks' => [
                'minProcesses' => 1,
                'maxProcesses' => 3,
            ],
            'supervisor-ai-diagnosis' => [
                'minProcesses' => 1,
                'maxProcesses' => 3,
            ],
            'supervisor-notifications' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
            'supervisor-rag-and-campaigns' => [
                'minProcesses' => 1,
                'maxProcesses' => 2,
            ],
        ],
    ],
];
