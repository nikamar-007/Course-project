<?php
session_start();
require __DIR__ . '/db.php';

$data = json_decode(file_get_contents('php://input'), true);

$email = trim($data['email'] ?? '');
$password = $data['password'] ?? '';

if ($email === '' || $password === '') {
  http_response_code(400);
  echo json_encode(['error' => 'Введите email и пароль']);
  exit;
}

$stmt = $pdo->prepare("
  SELECT id, email, password_hash, role
  FROM users
  WHERE email = ?
");
$stmt->execute([$email]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
  http_response_code(401);
  echo json_encode(['error' => 'Неверный логин или пароль']);
  exit;
}

if (!password_verify($password, $user['password_hash'])) {
  http_response_code(401);
  echo json_encode(['error' => 'Неверный логин или пароль']);
  exit;
}
$_SESSION['user_id'] = $user['id'];
$_SESSION['role'] = $user['role'];

$pdo->prepare("
  UPDATE users SET last_login = NOW() WHERE id = ?
")->execute([$user['id']]);

echo json_encode(['ok' => true]);
