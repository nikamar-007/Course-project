<?php
require __DIR__ . '/admin_guard.php';
require __DIR__ . '/../db.php';

$id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("DELETE FROM feedback WHERE id = ?");
$stmt->execute([$id]);

echo json_encode(['success' => true]);
?>