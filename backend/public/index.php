<?php
declare(strict_types=1);

require __DIR__ . '/../src/db.php';
header('Content-Type: application/json; charset=utf-8');

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

if (str_starts_with($path, '/api')) {
    $path = substr($path, 4);
}

try {
    if ($method === 'GET' && $path === '/health') {
        echo json_encode(['ok' => true, 'time' => db()->query("SELECT now()")->fetchColumn()]);
        exit;
    }

    if ($method === 'GET' && $path === '/reports') {
        $rows = db()->query("SELECT id, severity, status, title, description, ST_AsGeoJSON(geom::geometry) AS geom FROM reports")->fetchAll();
        $features = array_map(fn($r) => [
            "type" => "Feature",
            "geometry" => json_decode($r['geom'], true),
            "properties" => [
                "id" => $r['id'],
                "severity" => $r['severity'],
                "status" => $r['status'],
                "title" => $r['title'],
                "description" => $r['description'],
            ]
        ], $rows);

        echo json_encode(["type" => "FeatureCollection", "features" => $features], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
