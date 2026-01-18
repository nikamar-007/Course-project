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

    $id = $_GET['id'];
    $userId = $_SESSION['user_id'] ?? null;
    $userRole = $_SESSION['user_role'] ?? null;

    try {
        $stmt = $pdo->prepare("
            SELECT 
                f.id,
                f.user_id,
                f.name,
                f.email,
                f.type,
                f.message,
                f.admin_reply,
                f.created_at,
                f.replied_at
            FROM feedback f
            WHERE f.id = :id
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
        }
        else if ($userId && $feedback['user_id'] == $userId) {
            $hasAccess = true;
        }
        else if (!$userId) {
            $guestEmail = $_SESSION['guest_feedback_email'] ?? null;
            if ($guestEmail && $feedback['email'] == $guestEmail) {
                $hasAccess = true;
            }
            else {
                $guestFeedbacks = $_SESSION['guest_feedbacks'] ?? [];
                if (in_array($id, $guestFeedbacks)) {
                    $hasAccess = true;
                }
            }
        }

        if (!$hasAccess) {
            http_response_code(403);
            echo json_encode(['error' => 'Доступ запрещен. Возможно, вы не автор этого обращения.']);
            exit;
        }

        $files = [];
        $fileUrls = [];
        
        try {
            $stmt = $pdo->prepare("
                SELECT id, file_path 
                FROM feedback_files 
                WHERE feedback_id = :id
            ");
            
            if ($stmt) {
                $stmt->execute(['id' => $id]);
                $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
            }
        } catch (Exception $e) {
            error_log('Ошибка при получении файлов: ' . $e->getMessage());
        }

        $response = [
            'success' => true,
            'id' => $feedback['id'],
            'type' => $feedback['type'] ?? 'unknown',
            'message' => $feedback['message'] ?? '',
            'admin_reply' => $feedback['admin_reply'] ?? null,
            'created_at' => $feedback['created_at'] ?? date('Y-m-d H:i:s'),
            'replied_at' => $feedback['replied_at'] ?? null,
            'files' => $fileUrls
        ];

        echo json_encode($response);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Ошибка сервера',
            'message' => $e->getMessage()
        ]);
        
        error_log('Feedback API GET Error: ' . $e->getMessage());
    }
    
} elseif ($method === 'POST') {
    
    try {
        $userId = $_SESSION['user_id'] ?? null;
        $userEmail = $_SESSION['user_email'] ?? null;
        
        if ($userId) {
            $name = $_POST['name'] ?? '';
            $email = $userEmail; 
            $type = $_POST['type'] ?? 'other';
            $message = $_POST['message'] ?? '';
        } else {
            $name = $_POST['name'] ?? '';
            $email = $_POST['email'] ?? '';
            $type = $_POST['type'] ?? 'other';
            $message = $_POST['message'] ?? '';
        }
        
        if (empty($message)) {
            http_response_code(400);
            echo json_encode(['error' => 'Сообщение не может быть пустым']);
            exit;
        }
        
        if (!$userId && empty($email)) {
            http_response_code(400);
            echo json_encode(['error' => 'Для гостей email обязателен']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO feedback (user_id, name, email, type, message, created_at)
            VALUES (:user_id, :name, :email, :type, :message, NOW())
        ");
        
        $stmt->execute([
            'user_id' => $userId,
            'name' => $name,
            'email' => $email,
            'type' => $type,
            'message' => $message
        ]);
        
        $feedbackId = $pdo->lastInsertId();
        
        $uploadedFiles = [];
        if (!empty($_FILES['files'])) {
            $uploadDir = __DIR__ . '/../uploads/feedback/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $filesCount = count($_FILES['files']['name']);
            for ($i = 0; $i < $filesCount; $i++) {
                if ($_FILES['files']['error'][$i] === UPLOAD_ERR_OK) {
                    $tmpName = $_FILES['files']['tmp_name'][$i];
                    $originalName = $_FILES['files']['name'][$i];
                    
                    $fileExtension = pathinfo($originalName, PATHINFO_EXTENSION);
                    $uniqueName = uniqid() . '_' . time() . '.' . $fileExtension;
                    $uploadPath = $uploadDir . $uniqueName;
                    
                    if (move_uploaded_file($tmpName, $uploadPath)) {
                        $stmt = $pdo->prepare("
                            INSERT INTO feedback_files (feedback_id, file_path)
                            VALUES (:feedback_id, :file_path)
                        ");
                        
                        $stmt->execute([
                            'feedback_id' => $feedbackId,
                            'file_path' => $uniqueName
                        ]);
                        
                        $uploadedFiles[] = [
                            'original_name' => $originalName,
                            'saved_name' => $uniqueName,
                            'path' => '/uploads/feedback/' . $uniqueName
                        ];
                    }
                }
            }
        }

        echo json_encode([
            'success' => true,
            'id' => $feedbackId,
            'message' => 'Обращение успешно отправлено',
            'files' => $uploadedFiles
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Ошибка при сохранении обращения',
            'message' => $e->getMessage()
        ]);
        
        error_log('Feedback API POST Error: ' . $e->getMessage());
    }
    
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Метод не поддерживается']);
}