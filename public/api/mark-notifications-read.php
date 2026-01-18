<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Не авторизован']);
    exit;
}

require __DIR__ . '/db.php';

$userId = $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("
        UPDATE notifications 
        SET is_read = true 
        WHERE user_id = :uid AND is_read = false
    ");
    $stmt->execute(['uid' => $userId]);
    
    $stmt = $pdo->prepare("
        UPDATE feedback 
        SET admin_reply_read = true 
        WHERE user_id = :uid 
          AND admin_reply IS NOT NULL 
          AND admin_reply_read = false
    ");
    $stmt->execute(['uid' => $userId]);
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}