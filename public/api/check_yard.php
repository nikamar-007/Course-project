<?php
header('Content-Type: application/json; charset=utf-8');
error_reporting(0);

require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'Метод не поддерживается']);
  exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

$lat = $data['lat'] ?? null;
$lng = $data['lng'] ?? null;

if ($lat === null || $lng === null || !is_numeric($lat) || !is_numeric($lng)) {
  http_response_code(400);
  echo json_encode(['error' => 'Нужны координаты lat/lng']);
  exit;
}

try {
  $stmt = $pdo->prepare("
    SELECT id
    FROM yards
    WHERE ST_Contains(
      geometry,
      ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)
    )
    LIMIT 1
  ");
  $stmt->execute([
    'lat' => (float)$lat,
    'lng' => (float)$lng
  ]);

  $yardId = $stmt->fetchColumn();

  echo json_encode([
    'inside' => $yardId ? true : false,
    'yard_id' => $yardId ? (int)$yardId : null
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => 'Ошибка сервера', 'message' => $e->getMessage()]);
}
