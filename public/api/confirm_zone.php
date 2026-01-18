<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Требуется авторизация']);
    exit;
}

$userId = $_SESSION['user_id'];
$zoneId = $_POST['zone_id'] ?? null;

if (!$zoneId) {
    http_response_code(400);
    echo json_encode(['error' => 'Не указан ID зоны']);
    exit;
}

try {
    $pdo = new PDO(
        'pgsql:host=localhost;port=5432;dbname=walk_routes',
        'postgres',
        'qwerty12345@',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    $pdo->beginTransaction();
    
    $checkStmt = $pdo->prepare("
        SELECT creator_id, status, confirm_count 
        FROM repair_zones 
        WHERE id = ? AND is_active = true
    ");
    $checkStmt->execute([$zoneId]);
    $zone = $checkStmt->fetch();
    
    if (!$zone) {
        throw new Exception('Зона не найдена или неактивна');
    }
    
    if ($zone['creator_id'] == $userId) {
        throw new Exception('Вы не можете подтверждать свою собственную зону');
    }
    
    $alreadyStmt = $pdo->prepare("
        SELECT id FROM zone_confirmations 
        WHERE zone_id = ? AND user_id = ?
    ");
    $alreadyStmt->execute([$zoneId, $userId]);
    
    if ($alreadyStmt->fetch()) {
        throw new Exception('Вы уже подтверждали эту зону');
    }
    
    $confirmStmt = $pdo->prepare("
        INSERT INTO zone_confirmations (zone_id, user_id) 
        VALUES (?, ?)
    ");
    $confirmStmt->execute([$zoneId, $userId]);
    
    $newConfirmCount = $zone['confirm_count'] + 1;
    
    $newStatus = $zone['status'];
    if ($zone['status'] == 'pending' && $newConfirmCount > 0) {
        $newStatus = 'active';
    }
    
    $updateStmt = $pdo->prepare("
        UPDATE repair_zones 
        SET confirm_count = :confirm_count,
            last_confirmed_at = NOW(),
            status = :status,
            is_active = true
        WHERE id = :zone_id
    ");
    
    $updateStmt->execute([
        ':confirm_count' => $newConfirmCount,
        ':status' => $newStatus,
        ':zone_id' => $zoneId
    ]);
    
    $pdo->commit();
    
    echo json_encode([
        'status' => 'success', 
        'message' => 'Зона подтверждена',
        'confirm_count' => $newConfirmCount
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}