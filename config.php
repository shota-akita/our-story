<?php
// Configuration precedence: environment variables > .env > local defaults.

const LOCAL_DEMO_USERNAMES = ['demo-editor', 'demo-viewer'];

(function () {
    $envPath = __DIR__ . '/.env';
    if (!is_readable($envPath)) {
        return;
    }
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        if (strlen($value) >= 2
            && ($value[0] === '"' || $value[0] === "'")
            && $value[strlen($value) - 1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        // Preserve values injected by Docker or the host.
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
})();

function db_config(): array
{
    return [
        'host'   => getenv('DB_HOST')     ?: 'localhost',
        'user'   => getenv('DB_USER')     ?: 'root',
        'pass'   => getenv('DB_PASSWORD') ?: '',
        // Used only when DB_HOST is localhost.
        'socket' => getenv('DB_SOCKET')   ?: '/var/lib/mysql/mysql.sock',
    ];
}

function db_connect(string $dbname): mysqli
{
    // Report connection failures consistently through connect_error.
    mysqli_report(MYSQLI_REPORT_OFF);
    $c = db_config();
    return new mysqli($c['host'], $c['user'], $c['pass'], $dbname, 0, $c['socket']);
}

function use_dynamodb(): bool
{
    return strtolower(getenv('DB_DRIVER') ?: 'mysql') === 'dynamodb';
}

function db_engine_label(): string
{
    return use_dynamodb() ? 'DynamoDB' : 'MySQL 8.4';
}

function app_platform_label(): string
{
    return strtolower(getenv('APP_PLATFORM') ?: '') === 'ec2' ? 'Docker on Amazon EC2' : 'Docker';
}

if (use_dynamodb()) {
    require_once __DIR__ . '/dynamo.php';
}
