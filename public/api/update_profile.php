<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authorized']);
    exit;
}

require __DIR__ . '/db.php';

$userId = $_SESSION['user_id'];

$nickname   = $_POST['nickname'] ?? null;
$address    = $_POST['address'] ?? null;
$gender     = $_POST['gender'] ?? null;
$birth_date = $_POST['birth_date'] ?? null;
if ($birth_date === '') {
    $birth_date = null;
}

$lat        = $_POST['lat'] ?? null;
$lon        = $_POST['lon'] ?? null;
$password   = $_POST['password'] ?? null;
$password_repeat = $_POST['password_repeat'] ?? null;

$avatarPath = null;
if (!empty($_FILES['avatar']['name'])) {
    $ext = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '.' . $ext;
    $uploadDir = __DIR__ . '/../uploads/avatars/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadDir . $filename);
    $avatarPath = '/uploads/avatars/' . $filename;
}

$sql = "
UPDATE users SET
  nickname   = :nickname,
  address    = :address,
  gender     = :gender,
  birth_date = :birth_date,
  lat        = :lat,
  lon        = :lon
";

$params = [
  ':nickname'   => $nickname,
  ':address'    => $address,
  ':gender'     => $gender,
  ':birth_date' => $birth_date,
  ':lat'        => $lat,
  ':lon'        => $lon,
];

if (!empty($password)) {
    if ($password !== $password_repeat) {
        echo json_encode(['error' => 'Пароли не совпадают']);
        exit;
    }
    $sql .= ", password_hash = :password_hash";
    $params[':password_hash'] = password_hash($password, PASSWORD_DEFAULT);
}

if ($avatarPath) {
    $sql .= ", avatar_path = :avatar_path";
    $params[':avatar_path'] = $avatarPath;
}

$sql .= " WHERE id = :id";
$params[':id'] = $userId;

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log("Ошибка при обновлении профиля: " . $e->getMessage());
    echo json_encode(['error' => 'Ошибка базы данных: ' . $e->getMessage()]);
}