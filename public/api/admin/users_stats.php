<?php
header('Content-Type: application/json');

require __DIR__ . '/admin_guard.php';
require __DIR__ . '/../db.php';


$stats = [];

try {
    $stats['total_users'] = $pdo->query("SELECT COUNT(*) as count FROM users")->fetchColumn();

    $stats['admin_users'] = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'admin'")->fetchColumn();

    $stats['regular_users'] = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'user'")->fetchColumn();

    $stats['users_without_yard'] = $pdo->query("SELECT COUNT(*) as count FROM users WHERE yard_id IS NULL")->fetchColumn();

    $today = date('Y-m-d');
    $stats['users_today'] = $pdo->query("SELECT COUNT(*) as count FROM users WHERE DATE(created_at) = '$today'")->fetchColumn();

    $stats['users_with_nickname'] = $pdo->query("SELECT COUNT(*) as count FROM users WHERE nickname IS NOT NULL AND nickname != ''")->fetchColumn();

    $weekAgo = date('Y-m-d', strtotime('-7 days'));
    $stats['users_last_week'] = $pdo->query("SELECT COUNT(*) as count FROM users WHERE created_at >= '$weekAgo'")->fetchColumn();

    echo json_encode($stats);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка получения статистики', 'message' => $e->getMessage()]);
}