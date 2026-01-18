<?php
require __DIR__ . '/admin_guard.php';
require __DIR__ . '/../db.php';

$stats = [];

$stats['total'] = $pdo->query("SELECT COUNT(*) as count FROM feedback")->fetchColumn();

$stats['pending'] = $pdo->query("SELECT COUNT(*) as count FROM feedback WHERE admin_reply IS NULL OR admin_reply = ''")->fetchColumn();

$stats['answered'] = $pdo->query("SELECT COUNT(*) as count FROM feedback WHERE admin_reply IS NOT NULL AND admin_reply != ''")->fetchColumn();

$stats['bug'] = $pdo->query("SELECT COUNT(*) as count FROM feedback WHERE type = 'bug'")->fetchColumn();
$stats['suggestion'] = $pdo->query("SELECT COUNT(*) as count FROM feedback WHERE type = 'suggestion'")->fetchColumn();
$stats['question'] = $pdo->query("SELECT COUNT(*) as count FROM feedback WHERE type = 'question'")->fetchColumn();
$stats['complaint'] = $pdo->query("SELECT COUNT(*) as count FROM feedback WHERE type = 'complaint'")->fetchColumn();

echo json_encode($stats);
?>