<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Требуется авторизация']);
    exit;
}

$userId = $_SESSION['user_id'];

try {
    $pdo = new PDO(
        'pgsql:host=localhost;port=5432;dbname=walk_routes',
        'postgres',
        'qwerty12345@',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $userStmt = $pdo->prepare("
        SELECT lat, lon FROM users WHERE id = ?
    ");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();

    if (!$user || !$user['lat'] || !$user['lon']) {
        echo json_encode([]);
        exit;
    }
    $yardsStmt = $pdo->prepare("
        SELECT y.id 
        FROM yards y
        WHERE ST_Contains(y.geometry, ST_SetSRID(ST_MakePoint(?, ?), 4326))
    ");
    $yardsStmt->execute([$user['lon'], $user['lat']]);
    $yardIds = $yardsStmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($yardIds)) {
        echo json_encode([]);
        exit;
    }
    $placeholders = implode(',', array_fill(0, count($yardIds), '?'));
    $zonesStmt = $pdo->prepare("
        SELECT DISTINCT
            rz.id,
            rz.description,
            ST_AsGeoJSON(rz.geometry) AS geometry,
            rz.confirm_count,
            rz.created_at,
            rz.last_confirmed_at,
            rz.creator_id,
            rz.status,
            CASE 
                WHEN rz.confirm_count = 0 AND rz.created_at >= NOW() - INTERVAL '24 hours' 
                    THEN '#9E9E9E'
                WHEN rz.confirm_count > 0 AND rz.created_at >= NOW() - INTERVAL '7 days' 
                    THEN '#E53935'
                WHEN rz.confirm_count > 0 AND rz.created_at >= NOW() - INTERVAL '14 days' 
                    THEN '#FBC02D'
                WHEN rz.confirm_count > 0 AND rz.created_at < NOW() - INTERVAL '14 days'
                    AND rz.last_confirmed_at >= NOW() - INTERVAL '24 hours'
                    THEN '#43A047'
                ELSE NULL
            END AS color
        FROM repair_zones rz
        INNER JOIN yards y ON ST_Intersects(rz.geometry, y.geometry)
        WHERE y.id IN ($placeholders)
            AND rz.is_active = true
        ORDER BY rz.created_at DESC
    ");

    $zonesStmt->execute($yardIds);
    $zones = $zonesStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($zones);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка загрузки зон: ' . $e->getMessage()]);
}
?>