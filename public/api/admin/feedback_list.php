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
    u.email,
    u.nickname AS nickname,
    COALESCE(
      string_agg(
        CASE
          WHEN ff.file_path IS NULL OR ff.file_path = '' THEN NULL
          WHEN ff.file_path LIKE '/%' OR ff.file_path LIKE 'http%' THEN ff.file_path
          ELSE '/uploads/feedback/' || ff.file_path
        END,
        ',' ORDER BY ff.id
      ),
      ''
    ) AS files
  FROM feedback f
  LEFT JOIN users u ON u.id = f.user_id
  LEFT JOIN feedback_files ff ON ff.feedback_id = f.id
  GROUP BY f.id, u.email, u.nickname
  ORDER BY f.created_at DESC
");

$data = $stmt->fetchAll(PDO::FETCH_ASSOC);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($data);
