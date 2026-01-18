<?php
require __DIR__ . '/admin_guard.php';
require __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true);

$feedbackId = $data['feedback_id'] ?? null;
$reply = trim($data['reply'] ?? '');

if (!$feedbackId || $reply === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Нет данных']);
    exit;
}

$stmt = $pdo->prepare("
  UPDATE feedback
  SET admin_reply = :reply,
      replied_at = NOW()
  WHERE id = :id
");
$stmt->execute([
  'reply' => $reply,
  'id'    => $feedbackId
]);

$stmt = $pdo->prepare("
  SELECT user_id
  FROM feedback
  WHERE id = :id
");
$stmt->execute(['id' => $feedbackId]);
$userId = $stmt->fetchColumn();

if ($userId) {
    $stmt = $pdo->prepare("
      INSERT INTO notifications
        (user_id, type, title, message, is_read, created_at)
      VALUES
        (:uid, 'feedback_reply',
         'Ответ на ваше обращение',
         :msg,
         false,
         NOW())
    ");
    $stmt->execute([
        'uid' => $userId,
        'msg' => $reply
    ]);
}

echo json_encode(['success' => true]);
