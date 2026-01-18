<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Не авторизован']);
    exit;
}

require __DIR__ . '/db.php';

$stmt = $pdo->prepare("
    SELECT
        id,
        email,
        nickname,
        address,
        gender,
        birth_date,
        created_at,
        avatar_path,
        role
    FROM users
    WHERE id = :id
");
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$countStmt = $pdo->prepare("
    SELECT 
        (
            SELECT COUNT(*) 
            FROM notifications 
            WHERE user_id = :id 
            AND is_read = false
        ) + 
        (
            SELECT COUNT(*) 
            FROM feedback 
            WHERE user_id = :id 
            AND admin_reply IS NOT NULL 
            AND admin_reply_read = false
        ) AS total_unread
");
$countStmt->execute([':id' => $_SESSION['user_id']]);
$user['unread_notifications'] = (int)$countStmt->fetchColumn();

header('Content-Type: application/json; charset=utf-8');
echo json_encode($user);