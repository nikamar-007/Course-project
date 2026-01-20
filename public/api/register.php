<?php
session_start();
require __DIR__ . '/db.php';

$data = json_decode(file_get_contents('php://input'), true);

$email    = trim($data['email'] ?? '');
$password = $data['password'] ?? '';
$nickname = trim($data['nickname'] ?? '');

$address = $data['address'] ?? null;  

if (empty($address)) {
  http_response_code(400);
  echo json_encode(['error' => 'Введите адрес']);
  exit;
}


$lat = isset($data['lat']) ? (float)$data['lat'] : null;
$lon = isset($data['lon']) ? (float)$data['lon'] : null;

if (
  $email === '' ||
  $password === '' || 
  $lat === null ||
  $lon === null
) {
  http_response_code(400);
  echo json_encode(['error' => 'Заполните все поля']);
  exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(400);
  echo json_encode(['error' => 'Некорректный email']);
  exit;
}

if (strlen($password) < 6) {
  http_response_code(400);
  echo json_encode(['error' => 'Пароль слишком короткий']);
  exit;
}
$check = $pdo->prepare("SELECT 1 FROM users WHERE email = ?");
$check->execute([$email]);

if ($check->fetch()) {
  http_response_code(409);
  echo json_encode(['error' => 'Email уже зарегистрирован']);
  exit;
}
$yardStmt = $pdo->prepare("
  SELECT id
  FROM yards
  WHERE ST_Contains(
    geometry,
    ST_SetSRID(ST_Point(:lon, :lat), 4326)
  )
  LIMIT 1
");
$yardStmt->execute([
  'lon' => $lon,
  'lat' => $lat
]);
$yard_id = $yardStmt->fetchColumn();

if (!$yard_id) {
  http_response_code(400);
  echo json_encode(['error' => 'Адрес не относится к дворовой территории']);
  exit;
}

$hash = password_hash($password, PASSWORD_ARGON2ID);

$stmt = $pdo->prepare("
  INSERT INTO users (email, password_hash, nickname, yard_id, address)
  VALUES (?, ?, ?, ?, ?)
  RETURNING id, role
");
$stmt->execute([$email, $hash, $nickname, $yard_id, $address]);


$user = $stmt->fetch();

$_SESSION['user_id'] = $user['id'];
$_SESSION['role'] = $user['role'];

echo json_encode(['ok' => true]);
