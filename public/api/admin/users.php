<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/admin_guard.php';
require __DIR__ . '/../db.php';

$users = $pdo->query("
    SELECT
        u.id,
        u.email,
        u.nickname,
        u.address,
        u.created_at,
        u.role,
        y.id AS yard_id
    FROM users u
    LEFT JOIN yards y ON y.id = u.yard_id
    ORDER BY u.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($users);
