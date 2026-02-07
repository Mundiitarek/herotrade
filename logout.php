<?php
/**
 * ==========================================
 * LOGOUT PAGE
 * Handles user session termination
 * ==========================================
 */

define('APP_ACCESS', true);
require_once 'config.php';
require_once 'functions.php';

// Check if user is logged in
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    
    // Log activity
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent)
            VALUES (?, 'logout', 'User logged out', ?, ?)
        ");
        $stmt->execute([
            $user_id,
            $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ]);
    } catch (PDOException $e) {
        error_log("Logout activity log error: " . $e->getMessage());
    }
}

// Destroy session
session_unset();
session_destroy();

// Clear all cookies
if (isset($_SERVER['HTTP_COOKIE'])) {
    $cookies = explode(';', $_SERVER['HTTP_COOKIE']);
    foreach($cookies as $cookie) {
        $parts = explode('=', $cookie);
        $name = trim($parts[0]);
        setcookie($name, '', time() - 3600, '/');
    }
}

// Redirect to login page
header('Location: login.php?logout=success');
exit;
