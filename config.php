<?php
/**
 * ==========================================
 * TRADING PLATFORM - CONFIGURATION FILE
 * Compatible with PHP 7.4 / 8.3
 * ==========================================
 */

// Prevent direct access
if (!defined('APP_ACCESS')) {
    define('APP_ACCESS', true);
}

// ==========================================
// ERROR REPORTING (Change to 0 in production)
// ==========================================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt');

// ==========================================
// DATABASE CONFIGURATION
// ==========================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'smm2355_typescript');
define('DB_USER', 'smm2355_typescript');
define('DB_PASS', 'smm2355_typescript');
define('DB_CHARSET', 'utf8mb4');

// ==========================================
// SITE CONFIGURATION
// ==========================================
define('SITE_URL', 'http://mts-panel.shop');
define('ADMIN_URL', SITE_URL . '/admin');

// ==========================================
// API KEYS
// ==========================================
define('TWELVEDATA_API_KEY', 'YOUR_TWELVEDATA_API_KEY_HERE'); // Get from: https://twelvedata.com

// ==========================================
// SECURITY SETTINGS
// ==========================================
define('SESSION_LIFETIME', 86400); // 24 hours in seconds
define('PASSWORD_MIN_LENGTH', 8);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_TIMEOUT', 900); // 15 minutes

// ==========================================
// TRADING SETTINGS
// ==========================================
define('MIN_TRADE_AMOUNT', 10);
define('MAX_TRADE_AMOUNT', 100000);
define('BINARY_PAYOUT_PERCENTAGE', 85);

// ==========================================
// DATABASE CONNECTION
// ==========================================
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    die(json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . $e->getMessage()
    ]));
}

// ==========================================
// SESSION CONFIGURATION
// ==========================================
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? 1 : 0);
    ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
    session_start();
}

// ==========================================
// TIMEZONE
// ==========================================
date_default_timezone_set('Africa/Cairo');

// ==========================================
// HELPER FUNCTIONS
// ==========================================

/**
 * Get user IP address
 */
function get_client_ip() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/**
 * Get user agent
 */
function get_user_agent() {
    return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
}

/**
 * Sanitize input
 */
function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

/**
 * Generate CSRF token
 */
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Log activity
 */
function log_activity($pdo, $action, $description = null, $user_id = null, $admin_id = null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, admin_id, action, description, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user_id,
            $admin_id,
            $action,
            $description,
            get_client_ip(),
            get_user_agent()
        ]);
    } catch (PDOException $e) {
        error_log("Activity log failed: " . $e->getMessage());
    }
}

/**
 * Format number with currency
 */
function format_currency($amount, $currency = 'USD') {
    return $currency . ' ' . number_format($amount, 2, '.', ',');
}

/**
 * Format crypto amount
 */
function format_crypto($amount, $decimals = 8) {
    return rtrim(rtrim(number_format($amount, $decimals, '.', ''), '0'), '.');
}

/**
 * Calculate percentage change
 */
function calculate_percentage_change($old_value, $new_value) {
    if ($old_value == 0) return 0;
    return (($new_value - $old_value) / $old_value) * 100;
}

/**
 * Generate random string
 */
function generate_random_string($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Send JSON response
 */
function json_response($data, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Check if user is logged in
 */
function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Check if admin is logged in
 */
function is_admin_logged_in() {
    return isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']);
}

/**
 * Require login
 */
function require_login() {
    if (!is_logged_in()) {
        header('Location: ' . SITE_URL . '/login.php');
        exit;
    }
}

/**
 * Require admin login
 */
function require_admin_login() {
    if (!is_admin_logged_in()) {
        header('Location: ' . ADMIN_URL . '/login.php');
        exit;
    }
}

/**
 * Get user data
 */
function get_user_data($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$user_id]);
    return $stmt->fetch();
}

/**
 * Get setting value
 */
function get_setting($pdo, $key, $default = null) {
    static $cache = [];
    
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    
    $stmt = $pdo->prepare("SELECT value, type FROM settings WHERE `key` = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetch();
    
    if (!$result) {
        $cache[$key] = $default;
        return $default;
    }
    
    $value = $result['value'];
    
    // Convert based on type
    switch ($result['type']) {
        case 'boolean':
            $value = (bool)$value;
            break;
        case 'number':
            $value = is_numeric($value) ? (float)$value : $default;
            break;
        case 'json':
            $value = json_decode($value, true) ?? $default;
            break;
    }
    
    $cache[$key] = $value;
    return $value;
}

/**
 * Update setting
 */
function update_setting($pdo, $key, $value) {
    try {
        $stmt = $pdo->prepare("UPDATE settings SET value = ?, updated_at = NOW() WHERE `key` = ?");
        return $stmt->execute([$value, $key]);
    } catch (PDOException $e) {
        error_log("Update setting failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Time ago function
 */
function time_ago($datetime, $lang = 'en') {
    $time = strtotime($datetime);
    $diff = time() - $time;
    
    if ($lang === 'ar') {
        $units = [
            31536000 => 'سنة',
            2592000 => 'شهر',
            604800 => 'أسبوع',
            86400 => 'يوم',
            3600 => 'ساعة',
            60 => 'دقيقة',
            1 => 'ثانية'
        ];
        $suffix = 'منذ';
    } else {
        $units = [
            31536000 => 'year',
            2592000 => 'month',
            604800 => 'week',
            86400 => 'day',
            3600 => 'hour',
            60 => 'minute',
            1 => 'second'
        ];
        $suffix = 'ago';
    }
    
    foreach ($units as $unit => $text) {
        if ($diff < $unit) continue;
        $numberOfUnits = floor($diff / $unit);
        return ($lang === 'ar' ? $suffix . ' ' : '') . 
               $numberOfUnits . ' ' . $text . 
               ($numberOfUnits > 1 && $lang !== 'ar' ? 's' : '') . 
               ($lang !== 'ar' ? ' ' . $suffix : '');
    }
    
    return $lang === 'ar' ? 'الآن' : 'just now';
}

// ==========================================
// AUTO-LOAD TRANSLATIONS
// ==========================================

// Determine current language from session, cookie, or database default
if (isset($_SESSION['language'])) {
    $current_language = $_SESSION['language'];
} elseif (isset($_COOKIE['lang']) && in_array($_COOKIE['lang'], ['ar', 'en'])) {
    $current_language = $_COOKIE['lang'];
    $_SESSION['language'] = $current_language;
} else {
    $current_language = get_setting($pdo, 'default_language', 'ar');
    $_SESSION['language'] = $current_language;
}

// Load translation files
$lang_file = __DIR__ . '/includes/lang/' . $current_language . '.php';
if (file_exists($lang_file)) {
    $translations = require $lang_file;
} else {
    $translations = require __DIR__ . '/includes/lang/ar.php';
}

/**
 * Translation function
 */
function t($key, $default = null) {
    global $translations;
    return $translations[$key] ?? $default ?? $key;
}

/**
 * Get current language
 */
function get_current_lang() {
    global $current_language;
    return $current_language ?? 'ar';
}

/**
 * Check if current language is RTL
 */
function is_rtl() {
    return get_current_lang() === 'ar';
}

/**
 * Get text direction
 */
function get_dir() {
    return is_rtl() ? 'rtl' : 'ltr';
}

/**
 * Get font family based on language
 */
function get_font() {
    return is_rtl() ? "'Tajawal', sans-serif" : "'Poppins', 'Tajawal', sans-serif";
}

// ==========================================
// END OF CONFIG
// ==========================================
?>
