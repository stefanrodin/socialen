<?php
declare(strict_types=1);

require_once __DIR__ . '/App.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/OAuth.php';
require_once __DIR__ . '/Publisher.php';
require_once __DIR__ . '/Policies.php';
require_once __DIR__ . '/GoogleAccess.php';

function app(): App
{
    static $app;
    if ($app instanceof App) return $app;
    $root = dirname(__DIR__);
    $demo = getenv('PUBLICERA_DEMO') === '1';
    if ($demo) {
        if (PHP_SAPI !== 'cli' && (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true))) {
            throw new RuntimeException('Demoläget får endast köras lokalt.');
        }
        $config = [
            'name' => 'Publicera', 'url' => 'http://127.0.0.1:8098', 'timezone' => 'Europe/Stockholm',
            'key' => base64_encode(hash('sha256', 'publicera-local-demo-only', true)),
            'database' => ['dsn' => 'sqlite:' . $root . '/storage/demo.sqlite', 'user' => null, 'password' => null],
            'upload_limit_mb' => 100, 'google' => [], 'meta' => [],
        ];
    } else {
        if (!is_file($root . '/config.php')) throw new RuntimeException('Konfiguration saknas. Följ installationsguiden i README.md.');
        $config = require $root . '/config.php';
    }
    return $app = new App($root, $config, $demo);
}

function jsonResponse(array $value, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}
