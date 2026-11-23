<?php
declare(strict_types=1);

// Copy to config.php. Keep this file and storage/ outside the web document root.
return [
    'name' => 'Publicera',
    'url' => 'https://social.example.se',
    'timezone' => 'Europe/Stockholm',
    'key' => '', // php bin/key.php
    'database' => [
        'dsn' => 'mysql:host=localhost;dbname=ACCOUNT_publicera;charset=utf8mb4',
        'user' => 'ACCOUNT_publicera',
        'password' => '',
    ],
    'upload_limit_mb' => 100,
    'google' => ['client_id' => '', 'client_secret' => ''],
    'meta' => ['app_id' => '', 'app_secret' => '', 'version' => 'v27.0', 'login_config_id' => ''],
];
