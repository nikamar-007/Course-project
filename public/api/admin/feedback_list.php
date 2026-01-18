<?php
require __DIR__ . '/admin_guard.php';
require __DIR__ . '/../db.php';

$stmt = $pdo->query("
  SELECT
    f.id,
    f.type,
    f.message,
    f.created_at,
    f.admin_reply,
    u.email
  FROM feedback f
  LEFT JOIN users u ON u.id = f.user_id
  ORDER BY f.created_at DESC
");

$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json; charset=utf-8');
echo json_encode($data);
