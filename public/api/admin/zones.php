<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

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
    
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user || $user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Доступ запрещен']);
        exit;
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->query("
            SELECT 
                rz.*,
                u.email,
                u.nickname
            FROM repair_zones rz
            LEFT JOIN users u ON u.id = rz.creator_id
            ORDER BY rz.created_at DESC
        ");
        
        $zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($zones);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $zoneId = $_GET['id'] ?? null;
        
        if (!$zoneId) {
            http_response_code(400);
            echo json_encode(['error' => 'Не указан ID зоны']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            UPDATE repair_zones 
            SET is_active = false, 
                status = 'deleted'
            WHERE id = ?
        ");
        $stmt->execute([$zoneId]);
        
        echo json_encode(['status' => 'success', 'message' => 'Зона помечена как удаленная']);
        exit;
    }
    
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}