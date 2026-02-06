<?php
session_start();
if (isset($_POST['lang']) && in_array($_POST['lang'], ['ar', 'en'])) {
    $_SESSION['language'] = $_POST['lang'];
    setcookie('lang', $_POST['lang'], time() + (86400 * 7), '/');

    // Update user preference in database if logged in
    if (isset($_SESSION['user_id'])) {
        define('APP_ACCESS', true);
        require_once 'config.php';
        try {
            $stmt = $pdo->prepare("UPDATE users SET language = ? WHERE id = ?");
            $stmt->execute([$_POST['lang'], $_SESSION['user_id']]);
        } catch (PDOException $e) {
            // Silently fail
        }
    }

    echo json_encode(['success' => true]);
} else {
    http_response_code(400);
    echo json_encode(['success' => false]);
}
