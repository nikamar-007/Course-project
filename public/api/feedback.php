<?php
header('Content-Type: application/json; charset=utf-8');
error_reporting(0);

session_start();
require __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (!isset($_GET['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Нет id обращения']);
        exit;
    }

    $id = (int)$_GET['id'];
    $userId = $_SESSION['user_id'] ?? null;
    $userRole = $_SESSION['user_role'] ?? null;

    try {
        $stmt = $pdo->prepare("
            SELECT 
                f.id,
                f.user_id,
                f.type,
                f.message,
                f.admin_reply,
                f.created_at,
                f.replied_at
            FROM feedback f
            WHERE f.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $id]);
        $feedback = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$feedback) {
            http_response_code(404);
            echo json_encode(['error' => 'Обращение не найдено']);
            exit;
        }
        $hasAccess = false;
        if ($userRole === 'admin') {
            $hasAccess = true;
        } elseif ($userId && (int)$feedback['user_id'] === (int)$userId) {
            $hasAccess = true;
        }

        if (!$hasAccess) {
            http_response_code(403);
            echo json_encode(['error' => 'Доступ запрещён']);
            exit;
        }
        $stmt = $pdo->prepare("
            SELECT id, file_path
            FROM feedback_files
            WHERE feedback_id = :id
            ORDER BY id ASC
        ");
        $stmt->execute(['id' => $id]);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $fileUrls = [];
        foreach ($files as $file) {
            if (!empty($file['file_path'])) {
                $fileUrl = $file['file_path'];
                if (strpos($fileUrl, '/') !== 0 && strpos($fileUrl, 'http') !== 0) {
                    $fileUrl = '/uploads/feedback/' . $fileUrl;
                }
                $fileUrls[] = [
                    'id' => $file['id'],
                    'name' => basename($file['file_path']),
                    'url' => $fileUrl
                ];
            }
        }

        echo json_encode([
            'success' => true,
            'id' => $feedback['id'],
            'type' => $feedback['type'] ?? 'unknown',
            'message' => $feedback['message'] ?? '',
            'admin_reply' => $feedback['admin_reply'] ?? null,
            'created_at' => $feedback['created_at'] ?? date('Y-m-d H:i:s'),
            'replied_at' => $feedback['replied_at'] ?? null,
            'files' => $fileUrls
        ]);
        exit;

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Ошибка сервера', 'message' => $e->getMessage()]);
        error_log('Feedback API GET Error: ' . $e->getMessage());
        exit;
    }
}

if ($method === 'POST') {
    try {
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['error' => 'Требуется авторизация']);
            exit;
        }
        $type = $_POST['type'] ?? 'other';
        $message = $_POST['message'] ?? '';

        if (trim($message) === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Сообщение не может быть пустым']);
            exit;
        }
        $stmt = $pdo->prepare("
        INSERT INTO feedback (user_id, type, message, created_at, admin_reply_read)
        VALUES (:user_id, :type, :message, NOW(), FALSE)
        RETURNING id
        ");
        $stmt->execute([
        'user_id' => $userId,
        'type' => $type,
        'message' => $message
        ]);

        $feedbackId = (int)$stmt->fetchColumn();

        $uploadedFiles = [];

        if (isset($_FILES['files']) && is_array($_FILES['files']['name'])) {
            $uploadDir = __DIR__ . '/../uploads/feedback/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $maxFiles = 10;
            $maxFileSize = 10 * 1024 * 1024; 
            $allowedExt = ['jpg','jpeg','png','gif','webp','pdf'];

            $filesCount = count($_FILES['files']['name']);
            if ($filesCount > $maxFiles) {
                throw new RuntimeException("Слишком много файлов. Максимум: {$maxFiles}");
            }
            $finfo = new finfo(FILEINFO_MIME_TYPE);

            for ($i = 0; $i < $filesCount; $i++) {
                $err = $_FILES['files']['error'][$i];
                if ($err === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($err !== UPLOAD_ERR_OK) {
                    throw new RuntimeException("Ошибка загрузки файла #".($i+1).": код {$err}");
                }

                $tmpName = $_FILES['files']['tmp_name'][$i];
                $originalName = $_FILES['files']['name'][$i];
                $size = (int)$_FILES['files']['size'][$i];

                if ($size <= 0) {
                    continue;
                }
                if ($size > $maxFileSize) {
                    throw new RuntimeException("Файл {$originalName} слишком большой (макс 10MB).");
                }

                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExt, true)) {
                    throw new RuntimeException("Недопустимый тип файла: {$originalName}");
                }
                $mime = $finfo->file($tmpName);
                $allowedMime = [
                    'image/jpeg','image/png','image/gif','image/webp','application/pdf'
                ];
                if (!in_array($mime, $allowedMime, true)) {
                    throw new RuntimeException("Недопустимый MIME ({$mime}) у файла: {$originalName}");
                }

                $uniqueName = bin2hex(random_bytes(16)) . '.' . $ext;
                $uploadPath = $uploadDir . $uniqueName;

                if (!move_uploaded_file($tmpName, $uploadPath)) {
                    throw new RuntimeException("Не удалось сохранить файл: {$originalName}");
                }
                $stmtFile = $pdo->prepare("
                    INSERT INTO feedback_files (feedback_id, file_path)
                    VALUES (:feedback_id, :file_path)
                ");
                $stmtFile->execute([
                    'feedback_id' => $feedbackId,
                    'file_path' => $uniqueName
                ]);

                $uploadedFiles[] = [
                    'name' => $originalName,
                    'stored' => $uniqueName,
                    'url' => '/uploads/feedback/' . $uniqueName
                ];
            }
        }


        echo json_encode([
            'success' => true,
            'id' => $feedbackId,
            'message' => 'Обращение успешно отправлено',
            'files' => $uploadedFiles
        ]);
        exit;

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Ошибка при сохранении обращения', 'message' => $e->getMessage()]);
        error_log('Feedback API POST Error: ' . $e->getMessage());
        exit;
    }
}
http_response_code(405);
echo json_encode(['error' => 'Метод не поддерживается']);
