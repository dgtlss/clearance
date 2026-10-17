<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;

return [
    'lock_path' => base_path('clearance.lock.json'),
    'markdown_path' => base_path('CLEARANCE.md'),
    'personas' => 'App\\Clearance\\Personas',

    'routes' => [
        'include' => ['*'],
        'exclude' => ['horizon.*', 'telescope.*', '_debugbar.*', 'sanctum.csrf-cookie', 'livewire.*'],
        'admin_prefixes' => ['admin', 'nova', 'filament'],
    ],

    'dynamic' => [
        'enabled' => true,
        'test_case' => 'Tests\\TestCase',
        'database_trait' => DatabaseTransactions::class,
        'methods' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'],
        'fake' => ['mail', 'notifications', 'queue', 'events', 'http', 'storage'],
        'read_only' => [],
        'timeout_ms' => 2000,
        'process_timeout' => 300,
        'payloads' => [],
        'parameters' => [],
        // 'connections' => ['sqlite', 'tenant'], // Defaults to the application's default connection.
    ],

    'detect' => [
        'ability_middleware' => ['can', 'role', 'permission'],
        'custom_checks' => [],
        'detectors' => [],
    ],

    'gate' => [
        'fail_on' => ['widening', 'guard_removed', 'instance_blind', 'coverage_lost'],
        'max_errors' => 0,
    ],

    'report' => [
        'github_annotations' => env('GITHUB_ACTIONS', false),
        'warden' => true,
    ],
];
