<?php
/**
 * api/me.php ?" Returns the current PHP session user as JSON.
 * Used by HTML pages to confirm the user is authenticated and retrieve role info.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['logged_in' => false]);
    exit;
}

echo json_encode([
    'logged_in' => true,
    'id'        => (int) $_SESSION['user_id'],
    'username'  => $_SESSION['username']  ?? '',
    'full_name' => $_SESSION['full_name'] ?? '',
    'role'      => $_SESSION['role']      ?? '',
]);

