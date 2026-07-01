<?php
/**
 * index.php - entry point; redirect based on auth status.
 */
define('BASE_URL', '/stockboard_dealer/');
require_once __DIR__ . '/includes/auth.php';
if (isLoggedIn()) header('Location: dashboard.php');
else              header('Location: login.php');
exit;

