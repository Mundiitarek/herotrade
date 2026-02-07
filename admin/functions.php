<?php
/**
 * ==========================================
 * ADMIN FUNCTIONS
 * Compatible with PHP 7.4+
 * ==========================================
 */

if (!defined('APP_ACCESS')) {
    die('Direct access not permitted');
}

/**
 * Get admin data
 */
if (!function_exists('get_admin_data')) {
    function get_admin_data($pdo, $admin_id) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE id = ? AND is_active = 1");
            $stmt->execute([$admin_id]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Get admin data error: " . $e->getMessage());
            return null;
        }
    }
}

/**
 * Log admin activity
 */
if (!function_exists('log_admin_activity')) {
    function log_admin_activity($pdo, $admin_id, $action, $description) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?)
            ");

            return $stmt->execute([
                $admin_id,
                $action,
                $description,
                $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
        } catch (PDOException $e) {
            error_log("Log admin activity error: " . $e->getMessage());
            return false;
        }
    }
}
?>
