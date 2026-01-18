<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    $pdo = new PDO(
        'pgsql:host=localhost;port=5432;dbname=walk_routes',
        'postgres',
        'qwerty12345@',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['geometry'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No geometry provided']);
    exit;
}

try {
    $geoJson = json_encode($data['geometry']);
   
    $stmt = $pdo->prepare("
        SELECT 
            rz.id,
            rz.description,
            ST_AsGeoJSON(rz.geometry) AS geometry,
            rz.confirm_count,
            rz.created_at,
            rz.status,
            CASE 
                WHEN rz.status = 'pending' THEN '#FF9800'
                WHEN rz.status = 'active' THEN '#F44336'
                WHEN rz.status = 'warning' THEN '#FFEB3B'
                WHEN rz.status = 'expired' THEN '#4CAF50'
                ELSE '#9E9E9E'
            END as color
        FROM repair_zones rz
        WHERE rz.status IN ('pending', 'active', 'warning')
            AND ST_Intersects(
                rz.geometry, 
                ST_SetSRID(ST_GeomFromGeoJSON(:geometry), 4326)
            )
    ");
    
    $stmt->execute([':geometry' => $geoJson]);
    $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
 
    error_log("Check route: Found " . count($zones) . " intersecting zones");
    
    if (count($zones) > 0) {
        echo json_encode([
            'intersects' => true,
            'count' => count($zones),
            'message' => 'Маршрут пересекает ' . count($zones) . ' ремонтных зон',
            'zones' => $zones
        ]);
    } else {
        echo json_encode([
            'intersects' => false,
            'message' => 'Маршрут безопасен, не пересекает ремонтные зоны'
        ]);
    }
} catch (Exception $e) {
    error_log("Route check error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to check route: ' . $e->getMessage()]);
}