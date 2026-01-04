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

if ($method === 'GET' && ($path === '/' || $path === '/index.html')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    exit;
}

if ($method === 'GET' && $path === '/api/health') {
    jsonOut(['ok' => true]);
}

if ($method === 'GET' && $path === '/api/resolve-yard') {
    $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
    $lon = isset($_GET['lon']) ? (float)$_GET['lon'] : null;

    if ($lat === null || $lon === null) {
        jsonOut(['error' => 'lat/lon required'], 400);
    }

    $_SERVER['REQUEST_METHOD'] = 'POST';

}


if ($method === 'POST' && $path === '/api/resolve-yard') {
    $body = readJsonBody();
    $lat = isset($body['lat']) ? (float)$body['lat'] : null;
    $lon = isset($body['lon']) ? (float)$body['lon'] : null;

    if ($lat === null || $lon === null) {
        jsonOut(['error' => 'lat/lon required'], 400);
    }

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT id, global_id, district, address
        FROM public.yard_territories
        WHERE ST_Contains(geom::geometry, ST_SetSRID(ST_Point(:lon, :lat), 4326))
        LIMIT 1
    ");
    $stmt->execute([':lat' => $lat, ':lon' => $lon]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        jsonOut([
            'yard' => [
                'id' => (int)$row['id'],
                'global_id' => (int)$row['global_id'],
                'district' => $row['district'],
                'address' => $row['address'],
                'match_type' => 'contains',
                'distance_m' => null,
            ]
        ]);
    }

    $stmt2 = $pdo->prepare("
        SELECT id, global_id, district, address,
               ST_Distance(
                 geom,
                 ST_SetSRID(ST_Point(:lon, :lat), 4326)::geography
               ) AS dist_m
        FROM public.yard_territories
        ORDER BY geom <-> ST_SetSRID(ST_Point(:lon, :lat), 4326)::geography
        LIMIT 1
    ");
    $stmt2->execute([':lat' => $lat, ':lon' => $lon]);
    $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);

    if (!$row2) {
        jsonOut(['yard' => null]);
    }

    jsonOut([
        'yard' => [
            'id' => (int)$row2['id'],
            'global_id' => (int)$row2['global_id'],
            'district' => $row2['district'],
            'address' => $row2['address'],
            'match_type' => 'nearest',
            'distance_m' => isset($row2['dist_m']) ? (float)$row2['dist_m'] : null,
        ]
    ]);
}

if ($method === 'GET' && $path === '/api/yard-geojson') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) jsonOut(['error' => 'id required'], 400);

    $pdo = db();
    $stmt = $pdo->prepare("
      SELECT
        id,
        global_id,
        district,
        address,
        ST_AsGeoJSON(geom::geometry) AS geojson
      FROM public.yard_territories
      WHERE id = :id
      LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) jsonOut(['error' => 'yard not found'], 404);

    jsonOut([
        'id' => (int)$row['id'],
        'global_id' => (int)$row['global_id'],
        'district' => $row['district'],
        'address' => $row['address'],
        'geojson' => json_decode($row['geojson'], true),
    ]);
}

if ($method === 'POST' && $path === '/api/register') {
    $body = readJsonBody();

    $email = trim((string)($body['email'] ?? ''));
    $password = (string)($body['password'] ?? '');
    $address = trim((string)($body['address'] ?? ''));
    $lat = isset($body['lat']) ? (float)$body['lat'] : null;
    $lon = isset($body['lon']) ? (float)$body['lon'] : null;

    if ($email === '' || $password === '' || $lat === null || $lon === null) {
        jsonOut(['error' => 'email, password, lat, lon required'], 400);
    }

    $pdo = db();

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS public.users (
        id BIGSERIAL PRIMARY KEY,
        email TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        nickname TEXT,
        created_at TIMESTAMP NOT NULL DEFAULT NOW()
      );
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS public.user_locations (
        user_id BIGINT PRIMARY KEY REFERENCES public.users(id) ON DELETE CASCADE,
        address TEXT,
        lat DOUBLE PRECISION,
        lon DOUBLE PRECISION,
        yard_id BIGINT REFERENCES public.yard_territories(id),
        created_at TIMESTAMP NOT NULL DEFAULT NOW()
      );
    ");

    $stmt = $pdo->prepare("
        SELECT id,
               ST_Distance(geom, ST_SetSRID(ST_Point(:lon,:lat),4326)::geography) AS dist_m,
               CASE
                 WHEN ST_Contains(geom::geometry, ST_SetSRID(ST_Point(:lon,:lat),4326)) THEN 'contains'
                 ELSE 'nearest'
               END AS match_type
        FROM public.yard_territories
        ORDER BY geom <-> ST_SetSRID(ST_Point(:lon,:lat),4326)::geography
        LIMIT 1
    ");
    $stmt->execute([':lat' => $lat, ':lon' => $lon]);
    $yard = $stmt->fetch(PDO::FETCH_ASSOC);

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $pdo->beginTransaction();

    $insUser = $pdo->prepare("INSERT INTO public.users (email, password_hash, nickname) VALUES (:e, :h, :n) RETURNING id");
    $insUser->execute([
        ':e' => $email,
        ':h' => $hash,
        ':n' => $body['nickname'] ?? null
    ]);
    $userId = (int)$insUser->fetchColumn();

    $insLoc = $pdo->prepare("
      INSERT INTO public.user_locations (user_id, address, lat, lon, yard_id)
      VALUES (:uid, :addr, :lat, :lon, :yard_id)
    ");
    $insLoc->execute([
        ':uid' => $userId,
        ':addr' => $address,
        ':lat' => $lat,
        ':lon' => $lon,
        ':yard_id' => $yard ? (int)$yard['id'] : null,
    ]);

    $pdo->commit();

    jsonOut([
        'ok' => true,
        'user_id' => $userId,
        'yard' => $yard ? [
            'id' => (int)$yard['id'],
            'match_type' => $yard['match_type'],
            'distance_m' => isset($yard['dist_m']) ? (float)$yard['dist_m'] : null,
        ] : null
    ]);
}
if ($path === '/api/repairs' && $method === 'GET') {
    $pdo = db();

    $stmt = $pdo->query("
      SELECT
        id,
        title,
        description,
        status,
        severity,
        created_at,
        updated_at,
        ST_AsGeoJSON(geom::geometry) AS geojson
      FROM repairs
      WHERE status = 'active'
      ORDER BY updated_at DESC
      LIMIT 500
    ");

    $features = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $geom = json_decode($r['geojson'], true);
        $features[] = [
            'type' => 'Feature',
            'geometry' => $geom,
            'properties' => [
                'id' => (int)$r['id'],
                'title' => $r['title'],
                'description' => $r['description'],
                'status' => $r['status'],
                'severity' => $r['severity'],
                'created_at' => $r['created_at'],
                'updated_at' => $r['updated_at'],
            ],
        ];
    }

    jsonOut([
        'type' => 'FeatureCollection',
        'features' => $features,
    ]);
}
jsonOut(['error' => 'Not found'], 404);
