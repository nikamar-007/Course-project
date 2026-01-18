<?php
session_start();

echo json_encode([
  'logged' => isset($_SESSION['user_id']),
  'role' => $_SESSION['role'] ?? null
]);
