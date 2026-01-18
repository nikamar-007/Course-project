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
    
    $stats = [];
 
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM repair_zones");
    $stats['total_zones'] = (int)$stmt->fetch()['count'];
    
    $stmt = $pdo->query("
        SELECT COUNT(*) as count 
        FROM repair_zones 
        WHERE is_active = true 
          AND confirm_count = 0 
          AND created_at >= NOW() - INTERVAL '24 hours'
    ");
    $stats['pending_zones'] = (int)$stmt->fetch()['count'];
   
    $stmt = $pdo->query("
        SELECT COUNT(*) as count 
        FROM repair_zones 
        WHERE is_active = true 
          AND confirm_count > 0 
          AND created_at >= NOW() - INTERVAL '7 days'
    ");
    $stats['active_red_zones'] = (int)$stmt->fetch()['count'];
    
    $stmt = $pdo->query("
        SELECT COUNT(*) as count 
        FROM repair_zones 
        WHERE is_active = true 
          AND confirm_count > 0 
          AND created_at >= NOW() - INTERVAL '14 days' 
          AND created_at < NOW() - INTERVAL '7 days'
    ");
    $stats['active_yellow_zones'] = (int)$stmt->fetch()['count'];

    $stmt = $pdo->query("
        SELECT COUNT(*) as count 
        FROM repair_zones 
        WHERE is_active = true 
          AND confirm_count > 0 
          AND created_at < NOW() - INTERVAL '14 days'
          AND last_confirmed_at >= NOW() - INTERVAL '24 hours'
    ");
    $stats['expired_green_zones'] = (int)$stmt->fetch()['count'];

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM repair_zones WHERE is_active = false");
    $stats['inactive_zones'] = (int)$stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM zone_confirmations");
    $stats['total_confirmations'] = (int)$stmt->fetch()['count'];
    
    $stmt = $pdo->query("SELECT AVG(confirm_count) as avg FROM repair_zones WHERE confirm_count > 0");
    $stats['avg_confirmations'] = round((float)$stmt->fetch()['avg'], 2);
    
    $stmt = $pdo->query("
        SELECT 
            u.id,
            u.nickname,
            u.email,
            COUNT(rz.id) as zones_created
        FROM repair_zones rz
        LEFT JOIN users u ON u.id = rz.creator_id
        WHERE rz.creator_id IS NOT NULL
        GROUP BY u.id, u.nickname, u.email
        ORDER BY zones_created DESC
        LIMIT 5
    ");
    $stats['top_creators'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as zones_created,
            SUM(confirm_count) as confirmations
        FROM repair_zones
        WHERE created_at >= NOW() - INTERVAL '7 days'
        GROUP BY DATE(created_at)
        ORDER BY date DESC
    ");
    $stats['last_7_days'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($stats);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}