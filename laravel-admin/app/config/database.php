<?php

return [
    'default' => 'sqlite',
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 5000,
        ],
        'asterisk' => [
            'driver' => 'pgsql',
            'host' => env('REALTIME_DB_HOST', 'pbx.example.invalid'),
            'port' => env('REALTIME_DB_PORT', '5432'),
            'database' => env('REALTIME_DB_DATABASE', 'asterisk'),
            'username' => env('REALTIME_DB_USERNAME', 'laravel_readonly'),
            'password' => env('REALTIME_DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => env('REALTIME_DB_SSLMODE', 'prefer'),
            'server_options' => [
                'default_transaction_read_only' => 'on',
                'statement_timeout' => 3000,
            ],
            'options' => [
                PDO::ATTR_TIMEOUT => 3,
            ],
        ],
    ],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
];
