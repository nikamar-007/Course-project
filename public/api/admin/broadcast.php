<?php
require __DIR__ . '/admin_guard.php';
require __DIR__ . '/../db.php';

$data = json_decode(file_get_contents('php://input'), true);

$title = trim($data['title'] ?? '');
$message = trim($data['message'] ?? '');

if ($title === '' || $message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Заполните заголовок и сообщение']);
    exit;
}

$stmt = $pdo->prepare("
    INSERT INTO notifications (user_id, type, title, message, is_read)
    SELECT id, 'admin_broadcast', :title, :message, false
    FROM users
");

$stmt->execute([
    'title' => $title,
    'message' => $message
]);

echo json_encode(['ok' => true]);
