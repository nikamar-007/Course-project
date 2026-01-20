<?php
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/admin_guard.php';
require __DIR__ . '/../db.php'; 

$sql = "
WITH created AS (
  SELECT
    rz.creator_id AS user_id,
    COUNT(*)::int AS created_count
  FROM repair_zones rz
  GROUP BY rz.creator_id
),
confirmed AS (
  SELECT
    zc.user_id,
    COUNT(*)::int AS confirmed_count
  FROM zone_confirmations zc
  GROUP BY zc.user_id
)
SELECT
  u.email,
  COALESCE(c.created_count, 0) AS created_count,
  COALESCE(f.confirmed_count, 0) AS confirmed_count
FROM users u
LEFT JOIN created c ON c.user_id = u.id
LEFT JOIN confirmed f ON f.user_id = u.id
WHERE
  COALESCE(c.created_count, 0) > 0
  OR COALESCE(f.confirmed_count, 0) > 0
ORDER BY
  (COALESCE(c.created_count,0) + COALESCE(f.confirmed_count,0)) DESC
LIMIT 5000
";

try {
  $stmt = $pdo->query($sql);
  echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'error' => 'users_activity failed',
    'message' => $e->getMessage()
  ], JSON_UNESCAPED_UNICODE);
}
