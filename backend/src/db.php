<?php
declare(strict_types=1);

function env(string $key, ?string $default = null): ?string {
    static $vars = null;
    if ($vars === null) {
        $vars = [];
        $envPath = __DIR__ . '/../.env';
        if (is_file($envPath)) {
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) continue;
                [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
                $vars[trim($k)] = trim($v);
            }
        }
    }
    return $vars[$key] ?? $default;
}

function db(): PDO {
    $host = env('DB_HOST', '127.0.0.1');
    $port = env('DB_PORT', '5432');
    $dbname = env('DB_NAME', 'walk_routes');
    $user = env('DB_USER', 'postgres');
    $pass = env('DB_PASS', '');

    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}
