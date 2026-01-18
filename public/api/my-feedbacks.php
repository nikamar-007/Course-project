<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require __DIR__ . '/db.php';

$userId = $_SESSION['user_id'] ?? null;
$userRole = $_SESSION['user_role'] ?? null;

try {
    if ($userId) {
        $stmt = $pdo->prepare("
            SELECT 
                id, type, message, admin_reply, created_at, replied_at
            FROM feedback 
            WHERE user_id = :user_id
            ORDER BY created_at DESC
        ");
        
        $stmt->execute(['user_id' => $userId]);
        $feedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $guestEmail = $_SESSION['guest_feedback_email'] ?? null;
        $guestFeedbacks = $_SESSION['guest_feedbacks'] ?? [];
        
        if ($guestEmail) {
            $stmt = $pdo->prepare("
                SELECT 
                    id, type, message, admin_reply, created_at, replied_at
                FROM feedback 
                WHERE email = :email
                ORDER BY created_at DESC
            ");
            
            $stmt->execute(['email' => $guestEmail]);
            $feedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif (!empty($guestFeedbacks)) {
            $placeholders = str_repeat('?,', count($guestFeedbacks) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT 
                    id, type, message, admin_reply, created_at, replied_at
                FROM feedback 
                WHERE id IN ($placeholders)
                ORDER BY created_at DESC
            ");
            
            $stmt->execute($guestFeedbacks);
            $feedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $feedbacks = [];
        }
    }

    echo json_encode($feedbacks);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Ошибка сервера',
        'message' => $e->getMessage()
    ]);
}