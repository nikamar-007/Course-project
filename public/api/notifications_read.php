<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Не авторизован']);
    exit;
}

require __DIR__ . '/db.php';

$stmt = $pdo->prepare("
    UPDATE notifications
    SET is_read = true
    WHERE user_id = :id
      AND is_read = false
");
$stmt->execute([':id' => $_SESSION['user_id']]);

echo json_encode(['ok' => true]);
