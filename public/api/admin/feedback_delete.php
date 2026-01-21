<?php
require __DIR__ . '/admin_guard.php';
require __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Некорректный id']);
  exit;
}

try {
  $pdo->beginTransaction();

  $stmt = $pdo->prepare('DELETE FROM feedback_files WHERE feedback_id = ?');
  $stmt->execute([$id]);

  $stmt = $pdo->prepare('DELETE FROM feedback WHERE id = ?');
  $stmt->execute([$id]);

  $pdo->commit();
  echo json_encode(['success' => true]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Ошибка сервера', 'message' => $e->getMessage()]);
}
