<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Institution defaults
    |--------------------------------------------------------------------------
    |
    | Placeholders until institution settings are stored in the database.
    | The matric pattern is not a live format yet.
    |
    */

    'institution' => [
        'name' => 'University Portal',
        'code' => 'UNI',
        'matric_pattern' => '{DEPT}/{YEAR}/{SEQ}',
        'min_units' => 15,
        'max_units' => 24,
        'approval_required' => true,
        'withhold_results_for_debt' => true,
        'attendance_threshold' => 75,
    ],

    /*
    |--------------------------------------------------------------------------
    | Upload limits
    |--------------------------------------------------------------------------
    |
    | From SECURITY.md: documents 5 MB, logo 1 MB, imports 5 MB and 5,000 rows.
    |
    */

    'uploads' => [
        'document_max_kilobytes' => 5120,
        'logo_max_kilobytes' => 1024,
        'import_max_kilobytes' => 5120,
        'import_max_rows' => 5000,
        'allowed_document_extensions' => ['pdf', 'jpg', 'jpeg', 'png'],
        'allowed_import_extensions' => ['csv', 'xlsx'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Capacity placeholders
    |--------------------------------------------------------------------------
    |
    | Launch targets from ARCHITECTURE.md. These are not a load-test result.
    |
    */

    'capacity' => [
        'launch_students' => 1000,
        'expansion_students' => 2000,
        'peak_concurrent_users' => 300,
        'default_page_size' => 25,
    ],

    /*
    |--------------------------------------------------------------------------
    | Required settings
    |--------------------------------------------------------------------------
    |
    | Captured without fallbacks so a non-local boot can tell "missing" from
    | a framework default. Read by App\Support\EnvironmentGuard.
    |
    */

    'required' => [
        'app_key' => env('APP_KEY'),
        'app_url' => env('APP_URL'),
        'db_connection' => env('DB_CONNECTION'),
        'db_host' => env('DB_HOST'),
        'db_port' => env('DB_PORT'),
        'db_database' => env('DB_DATABASE'),
        'db_username' => env('DB_USERNAME'),
        'db_password' => env('DB_PASSWORD'),
    ],

];
