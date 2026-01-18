<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

require __DIR__ . '/db.php';

$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    echo json_encode([]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sql = "
    SELECT
        id,
        'notification' AS source,
        title,
        message,
        link,
        is_read,
        created_at
    FROM notifications
    WHERE user_id = :uid
    ORDER BY created_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['uid' => $userId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT
            id,
            'feedback' AS source,
            'Ответ на ваше обращение' AS title,
            SUBSTRING(admin_reply, 1, 100) || '...' AS message,
            '/feedback.html?id=' || id AS link,
            admin_reply_read AS is_read,
            replied_at AS created_at
        FROM feedback
        WHERE user_id = :uid
          AND admin_reply IS NOT NULL
        ORDER BY replied_at DESC
    ");
    $stmt->execute(['uid' => $userId]);
    $feedbackReplies = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $allNotifications = array_merge($notifications, $feedbackReplies);

    usort($allNotifications, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });

    echo json_encode($allNotifications);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $type = $input['type'] ?? 'all'; 
    $id = $input['id'] ?? null;

    if ($type === 'notifications' || $type === 'all') {
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET is_read = true 
            WHERE user_id = :uid AND is_read = false
        ");
        $stmt->execute(['uid' => $userId]);
    }
    
    if ($type === 'feedback' || $type === 'all') {
        if ($id) {
            $stmt = $pdo->prepare("
                UPDATE feedback 
                SET admin_reply_read = true 
                WHERE id = :id AND user_id = :uid
            ");
            $stmt->execute(['id' => $id, 'uid' => $userId]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE feedback 
                SET admin_reply_read = true 
                WHERE user_id = :uid 
                  AND admin_reply IS NOT NULL 
                  AND admin_reply_read = false
            ");
            $stmt->execute(['uid' => $userId]);
        }
    }
    
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
?>