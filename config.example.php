<?php
/**
 * Editorial VCS — Configuration
 *
 * Copy this file to config.php and update the values for your environment.
 * config.php is gitignored and should never be committed.
 */

return [
    // Database connection
    'db' => [
        'host'     => getenv('DB_HOST') ?: 'localhost',
        'name'     => getenv('DB_NAME') ?: 'editorial_vcs',
        'user'     => getenv('DB_USER') ?: 'editorial_user',
        'password' => getenv('DB_PASS') ?: 'CHANGE_THIS_PASSWORD',
        'charset'  => 'utf8mb4',
    ],

    // Application settings
    'app' => [
        'title'           => getenv('APP_TITLE') ?: 'Editorial VCS',
        'timezone'        => getenv('APP_TIMEZONE') ?: 'UTC',
        'max_upload_size' => 10 * 1024 * 1024, // 10MB
    ],

    // Optional features — set to false to disable
    'features' => [
        'lore_bible'         => true,  // Entity/character database with cross-referencing
        'relationship_graph' => true,  // D3.js visualization of entity connections
    ],

    // Security
    'security' => [
        // Set to true if running behind a reverse proxy that handles auth
        'trust_proxy_auth' => false,

        // Header name for proxy-provided username (if trust_proxy_auth is true)
        'proxy_auth_header' => 'X-Forwarded-User',
    ],
];
