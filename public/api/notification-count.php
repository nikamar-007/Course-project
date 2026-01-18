<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['count' => 0]);
    exit;
}

require __DIR__ . '/db.php';

$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT 
        (
            SELECT COUNT(*) 
            FROM notifications 
            WHERE user_id = :uid 
            AND is_read = false
        ) + 
        (
            SELECT COUNT(*) 
            FROM feedback 
            WHERE user_id = :uid 
            AND admin_reply IS NOT NULL 
            AND admin_reply_read = false
        ) AS total_count
");
$stmt->execute(['uid' => $userId]);
$totalCount = (int)$stmt->fetchColumn();

echo json_encode(['count' => $totalCount]);