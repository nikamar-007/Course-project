<?php
declare(strict_types=1);

require __DIR__ . '/../src/db.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';

function readJsonBody(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

function jsonOut($data, int $code = 200): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Главная страница
if ($method === 'GET' && ($path === '/' || $path === '/index.html')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    exit;
}

// health
if ($method === 'GET' && $path === '/api/health') {
    jsonOut(['ok' => true]);
}

// repairs (FeatureCollection)
if ($method === 'GET' && $path === '/api/repairs') {
    $pdo = db();

    // Таблица зон работ. Если у вас она называется иначе — поменяйте здесь.
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS public.repairs (
        id BIGSERIAL PRIMARY KEY,
        title TEXT,
        description TEXT,
        status TEXT NOT NULL DEFAULT 'active',
        severity TEXT NOT NULL DEFAULT 'red',
        geom geometry(MultiPolygon, 4326) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT NOW(),
        updated_at TIMESTAMP NOT NULL DEFAULT NOW()
      );
    ");

    $stmt = $pdo->query("
      SELECT
        id,
        title,
        description,
        status,
        severity,
        created_at,
        updated_at,
        ST_AsGeoJSON(geom)::json AS geometry
      FROM public.repairs
      WHERE status = 'active'
      ORDER BY id ASC
      LIMIT 2000
    ");

    $features = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $features[] = [
            'type' => 'Feature',
            'geometry' => $row['geometry'],
            'properties' => [
                'id' => (int)$row['id'],
                'title' => $row['title'],
                'description' => $row['description'],
                'status' => $row['status'],
                'severity' => $row['severity'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ],
        ];
    }

    jsonOut([
        'type' => 'FeatureCollection',
        'features' => $features,
    ]);
}

// если что-то не нашли
jsonOut(['error' => 'Not found'], 404);
