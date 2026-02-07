<?php
define('APP_ACCESS', true);
require_once '../config.php';
require_once '../functions.php';
require_once 'functions.php';

require_admin_login();

$admin_id = $_SESSION['admin_id'];
$admin = get_admin_data($pdo, $admin_id);

if (!$admin) {
    session_destroy();
    header('Location: ../login.php');
    exit;
}

$lang = get_current_lang();
$dir = is_rtl() ? 'rtl' : 'ltr';
$success_message = false;
$error_message = '';
$api_status = '';
$api_response_data = null;
$email_test_result = '';
$active_tab = sanitize($_GET['tab'] ?? $_POST['tab'] ?? 'platform');

// Handle AJAX requests for trading pairs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');

    if ($_POST['ajax_action'] === 'toggle_pair') {
        $pair_id = intval($_POST['pair_id']);
        $field = sanitize($_POST['field'] ?? 'is_active');
        $allowed_fields = ['is_active', 'spot_enabled', 'binary_enabled'];

        if (in_array($field, $allowed_fields)) {
            try {
                $stmt = $pdo->prepare("UPDATE trading_pairs SET {$field} = NOT {$field} WHERE id = ?");
                $stmt->execute([$pair_id]);
                $stmt2 = $pdo->prepare("SELECT {$field} FROM trading_pairs WHERE id = ?");
                $stmt2->execute([$pair_id]);
                $new_val = $stmt2->fetchColumn();
                echo json_encode(['success' => true, 'new_value' => (int)$new_val]);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid field']);
        }
        exit;
    }

    if ($_POST['ajax_action'] === 'update_pair') {
        $pair_id = intval($_POST['pair_id']);
        $min_amount = floatval($_POST['min_amount'] ?? 0);
        $max_amount = floatval($_POST['max_amount'] ?? 0);

        try {
            $stmt = $pdo->prepare("UPDATE trading_pairs SET min_amount = ?, max_amount = ? WHERE id = ?");
            $stmt->execute([$min_amount, $max_amount, $pair_id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    exit;
}

// Handle POST for saving settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $tab = sanitize($_POST['tab'] ?? 'platform');
    $active_tab = $tab;

    try {
        switch ($tab) {
            case 'platform':
                $fields = ['platform_name_en', 'platform_name_ar', 'site_name_en', 'site_name_ar',
                           'support_email', 'support_phone', 'default_language'];
                foreach ($fields as $field) {
                    if (isset($_POST[$field])) {
                        update_setting($pdo, $field, sanitize($_POST[$field]));
                    }
                }
                // Handle maintenance_mode as boolean
                update_setting($pdo, 'maintenance_mode', isset($_POST['maintenance_mode']) ? '1' : '0');
                if (isset($_POST['maintenance_message_ar'])) {
                    update_setting($pdo, 'maintenance_message_ar', sanitize($_POST['maintenance_message_ar']));
                }
                if (isset($_POST['maintenance_message_en'])) {
                    update_setting($pdo, 'maintenance_message_en', sanitize($_POST['maintenance_message_en']));
                }
                break;

            case 'trading':
                $fields = ['min_deposit', 'max_deposit', 'spot_trading_fee', 'binary_payout_percentage',
                           'binary_min_duration', 'binary_max_duration'];
                foreach ($fields as $field) {
                    if (isset($_POST[$field])) {
                        update_setting($pdo, $field, $_POST[$field]);
                    }
                }
                // Boolean toggles
                $toggles = ['spot_trading_enabled', 'binary_trading_enabled', 'forex_enabled',
                            'indices_enabled', 'stocks_enabled'];
                foreach ($toggles as $toggle) {
                    update_setting($pdo, $toggle, isset($_POST[$toggle]) ? '1' : '0');
                }
                break;

            case 'limits':
                $fields = ['min_deposit', 'max_deposit', 'min_withdrawal', 'max_withdrawal',
                           'daily_withdrawal_limit', 'withdrawal_processing_hours',
                           'auto_approve_deposit_under', 'auto_approve_withdrawal_under'];
                foreach ($fields as $field) {
                    if (isset($_POST[$field])) {
                        update_setting($pdo, $field, $_POST[$field]);
                    }
                }
                break;

            case 'email':
                $fields = ['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username',
                           'smtp_password', 'smtp_from_email', 'smtp_from_name'];
                foreach ($fields as $field) {
                    if (isset($_POST[$field])) {
                        update_setting($pdo, $field, sanitize($_POST[$field]));
                    }
                }
                break;

            case 'api':
                $fields = ['twelvedata_api_key', 'price_update_interval', 'api_plan'];
                foreach ($fields as $field) {
                    if (isset($_POST[$field])) {
                        update_setting($pdo, $field, sanitize($_POST[$field]));
                    }
                }
                break;

            case 'security':
                $fields = ['max_login_attempts', 'session_timeout'];
                foreach ($fields as $field) {
                    if (isset($_POST[$field])) {
                        update_setting($pdo, $field, $_POST[$field]);
                    }
                }
                $toggles = ['enable_2fa', 'require_email_verification', 'enable_kyc', 'log_all_activities'];
                foreach ($toggles as $toggle) {
                    update_setting($pdo, $toggle, isset($_POST[$toggle]) ? '1' : '0');
                }
                break;

            case 'maintenance':
                update_setting($pdo, 'maintenance_mode', isset($_POST['maintenance_mode']) ? '1' : '0');
                if (isset($_POST['maintenance_message_ar'])) {
                    update_setting($pdo, 'maintenance_message_ar', sanitize($_POST['maintenance_message_ar']));
                }
                if (isset($_POST['maintenance_message_en'])) {
                    update_setting($pdo, 'maintenance_message_en', sanitize($_POST['maintenance_message_en']));
                }
                break;
        }

        log_admin_activity($pdo, $admin_id, 'settings_update', "Updated {$tab} settings");
        $success_message = true;

    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Handle Test API
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_api'])) {
    $active_tab = 'api';
    $api_key = get_setting($pdo, 'twelvedata_api_key', '');
    if (!empty($api_key)) {
        $test_url = "https://api.twelvedata.com/quote?symbol=BTC/USD&apikey={$api_key}";
        $response = @file_get_contents($test_url);
        if ($response) {
            $api_response_data = json_decode($response, true);
            $api_status = (isset($api_response_data['symbol'])) ? 'connected' : 'failed';
        } else {
            $api_status = 'failed';
        }
    } else {
        $api_status = 'no_key';
    }
}

// Handle Test Email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_email'])) {
    $active_tab = 'email';
    $test_to = sanitize($_POST['test_email_to'] ?? '');
    if (!empty($test_to) && filter_var($test_to, FILTER_VALIDATE_EMAIL)) {
        $smtp_host = get_setting($pdo, 'smtp_host', '');
        if (!empty($smtp_host)) {
            $email_test_result = 'sent';
        } else {
            $email_test_result = 'no_config';
        }
    } else {
        $email_test_result = 'invalid_email';
    }
}

// Handle Add Trading Pair
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_trading_pair'])) {
    $active_tab = 'pairs';
    $symbol = strtoupper(sanitize($_POST['symbol']));
    $base = strtoupper(sanitize($_POST['base_currency']));
    $quote = strtoupper(sanitize($_POST['quote_currency']));
    $name_ar = sanitize($_POST['name_ar']);
    $name_en = sanitize($_POST['name_en']);
    $icon = sanitize($_POST['icon']);
    $asset_type = sanitize($_POST['asset_type'] ?? 'crypto');
    $initial_price = floatval($_POST['initial_price']);
    $min_amount = floatval($_POST['min_amount'] ?? 10);
    $max_amount = floatval($_POST['max_amount'] ?? 100000);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO trading_pairs (
                symbol, base_currency, quote_currency, name_ar, name_en,
                icon, asset_type, current_price, min_amount, max_amount,
                spot_enabled, binary_enabled, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, 1)
        ");
        $stmt->execute([$symbol, $base, $quote, $name_ar, $name_en, $icon, $asset_type, $initial_price, $min_amount, $max_amount]);

        $success_message = true;
        log_admin_activity($pdo, $admin_id, 'trading_pair_add', "Added trading pair: $symbol");
    } catch (PDOException $e) {
        $error_message = $lang === 'ar' ? 'حدث خطأ أثناء الإضافة (ربما الرمز موجود بالفعل)' : 'Error adding pair (symbol may already exist)';
    }
}

// Get trading pairs grouped by asset_type
try {
    $stmt = $pdo->query("
        SELECT tp.*,
            COUNT(DISTINCT st.id) as spot_trades,
            COUNT(DISTINCT bt.id) as binary_trades
        FROM trading_pairs tp
        LEFT JOIN spot_trades st ON tp.id = st.pair_id
        LEFT JOIN binary_trades bt ON tp.id = bt.pair_id
        GROUP BY tp.id
        ORDER BY tp.asset_type ASC, tp.is_active DESC, tp.symbol ASC
    ");
    $trading_pairs = $stmt->fetchAll();
} catch (PDOException $e) {
    $trading_pairs = [];
}

// Group pairs by asset_type
$pairs_grouped = [];
foreach ($trading_pairs as $pair) {
    $type = $pair['asset_type'] ?? 'crypto';
    $pairs_grouped[$type][] = $pair;
}

$asset_type_labels = [
    'crypto' => ['en' => 'Cryptocurrencies', 'ar' => 'العملات الرقمية'],
    'forex' => ['en' => 'Forex', 'ar' => 'الفوركس'],
    'indices' => ['en' => 'Indices', 'ar' => 'المؤشرات'],
    'stocks' => ['en' => 'Stocks', 'ar' => 'الأسهم'],
    'commodities' => ['en' => 'Commodities', 'ar' => 'السلع'],
];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $lang === 'ar' ? 'الإعدادات - لوحة التحكم' : 'Settings - Admin Panel' ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #0F172A;
            --secondary: #1E293B;
            --accent: #F59E0B;
            --success: #10B981;
            --danger: #EF4444;
            --info: #3B82F6;
            --warning: #F59E0B;
            --purple: #8B5CF6;
            --text: #F8FAFC;
            --text-muted: #94A3B8;
            --border: rgba(148, 163, 184, 0.1);
        }

        body {
            font-family: <?= is_rtl() ? "'Tajawal', sans-serif" : "'Poppins', 'Tajawal', sans-serif" ?>;
            background: var(--primary);
            color: var(--text);
            line-height: 1.6;
        }

        /* ========== Sidebar ========== */
        .sidebar {
            position: fixed;
            <?= is_rtl() ? 'right' : 'left' ?>: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: var(--secondary);
            border-<?= is_rtl() ? 'left' : 'right' ?>: 1px solid var(--border);
            z-index: 1000;
            overflow-y: auto;
        }

        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-thumb { background: var(--accent); border-radius: 2px; }

        .logo {
            padding: 30px;
            border-bottom: 1px solid var(--border);
        }

        .logo-content {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #DC2626, var(--accent));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }

        .logo-text {
            font-size: 22px;
            font-weight: 900;
            background: linear-gradient(135deg, #DC2626, var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .admin-badge {
            background: linear-gradient(135deg, #DC2626, var(--accent));
            color: white;
            font-size: 10px;
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .admin-card {
            padding: 20px;
            margin: 20px;
            background: rgba(220, 38, 38, 0.05);
            border: 1px solid rgba(220, 38, 38, 0.1);
            border-radius: 16px;
        }

        .admin-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .admin-avatar {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #DC2626, var(--accent));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 700;
            color: white;
        }

        .admin-name {
            font-weight: 700;
            font-size: 16px;
        }

        .nav-menu {
            padding: 20px 0;
        }

        .nav-section-title {
            padding: 0 20px 10px;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            color: var(--text-muted);
            text-decoration: none;
            transition: all 0.3s;
            position: relative;
        }

        .nav-item:hover,
        .nav-item.active {
            color: var(--text);
            background: rgba(220, 38, 38, 0.05);
        }

        .nav-item.active::before {
            content: '';
            position: absolute;
            <?= is_rtl() ? 'right' : 'left' ?>: 0;
            top: 0;
            height: 100%;
            width: 3px;
            background: linear-gradient(135deg, #DC2626, var(--accent));
        }

        .nav-item i {
            width: 20px;
            text-align: center;
            font-size: 18px;
        }

        .nav-badge {
            margin-<?= is_rtl() ? 'right' : 'left' ?>: auto;
            background: var(--danger);
            color: white;
            font-size: 10px;
            padding: 3px 8px;
            border-radius: 10px;
            font-weight: 700;
        }

        /* ========== Main Content ========== */
        .main {
            margin-<?= is_rtl() ? 'right' : 'left' ?>: 280px;
            padding: 30px;
            min-height: 100vh;
        }

        .header {
            margin-bottom: 32px;
        }

        .page-title {
            font-size: 32px;
            font-weight: 900;
            background: linear-gradient(135deg, var(--text), #DC2626);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: var(--text-muted);
        }

        .breadcrumb a {
            color: var(--text-muted);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            color: var(--accent);
        }

        /* ========== Alert Messages ========== */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: var(--success);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: var(--danger);
        }

        .alert-info {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.2);
            color: var(--info);
        }

        .alert-warning {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.2);
            color: var(--warning);
        }

        /* ========== Tabs ========== */
        .tabs-wrapper {
            margin-bottom: 24px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .tabs-wrapper::-webkit-scrollbar { height: 0; }

        .tabs {
            display: flex;
            gap: 4px;
            border-bottom: 2px solid var(--border);
            min-width: max-content;
        }

        .tab-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            background: none;
            border: none;
            color: var(--text-muted);
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            font-family: inherit;
            white-space: nowrap;
        }

        .tab-btn:hover {
            color: var(--text);
            background: rgba(255, 255, 255, 0.02);
        }

        .tab-btn.active {
            color: var(--accent);
            border-bottom-color: var(--accent);
        }

        .tab-btn i {
            font-size: 14px;
        }

        /* ========== Tab Content ========== */
        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* ========== Settings Card ========== */
        .settings-card {
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 24px;
        }

        .settings-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .settings-title i {
            color: var(--accent);
            font-size: 20px;
        }

        .settings-subtitle {
            color: var(--text-muted);
            font-size: 13px;
            margin-bottom: 24px;
        }

        /* ========== Form Elements ========== */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .form-grid-3 {
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-label .required {
            color: var(--danger);
        }

        .form-input,
        .form-select,
        .form-textarea {
            padding: 12px 16px;
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text);
            font-size: 14px;
            font-family: inherit;
            transition: all 0.3s;
            width: 100%;
        }

        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.1);
        }

        .form-input::placeholder {
            color: rgba(148, 163, 184, 0.5);
        }

        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%2394A3B8' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10l-5 5z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: <?= is_rtl() ? 'left 16px center' : 'right 16px center' ?>;
            padding-<?= is_rtl() ? 'left' : 'right' ?>: 40px;
        }

        .form-select option {
            background: var(--secondary);
            color: var(--text);
        }

        .form-textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-hint {
            font-size: 12px;
            color: var(--text-muted);
            opacity: 0.7;
        }

        /* ========== Toggle Switch ========== */
        .toggle-group {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid var(--border);
            border-radius: 8px;
            margin-bottom: 12px;
            transition: all 0.3s;
        }

        .toggle-group:hover {
            border-color: rgba(148, 163, 184, 0.2);
        }

        .toggle-label {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .toggle-label-text {
            font-weight: 600;
            font-size: 14px;
        }

        .toggle-label-hint {
            font-size: 12px;
            color: var(--text-muted);
        }

        .toggle-switch {
            position: relative;
            width: 48px;
            height: 26px;
            flex-shrink: 0;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background: rgba(148, 163, 184, 0.3);
            border-radius: 26px;
            transition: all 0.3s;
        }

        .toggle-slider::before {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: white;
            top: 3px;
            <?= is_rtl() ? 'right' : 'left' ?>: 3px;
            transition: all 0.3s;
        }

        .toggle-switch input:checked + .toggle-slider {
            background: var(--success);
        }

        .toggle-switch input:checked + .toggle-slider::before {
            transform: translateX(<?= is_rtl() ? '-22px' : '22px' ?>);
        }

        /* ========== Buttons ========== */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
            font-family: inherit;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #DC2626, var(--accent));
            color: white;
            box-shadow: 0 8px 24px rgba(220, 38, 38, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(220, 38, 38, 0.4);
        }

        .btn-secondary {
            background: rgba(148, 163, 184, 0.1);
            color: var(--text);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: rgba(148, 163, 184, 0.2);
        }

        .btn-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .btn-success:hover {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .btn-danger:hover {
            background: var(--danger);
            color: white;
        }

        .btn-info {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .btn-info:hover {
            background: var(--info);
            color: white;
        }

        .btn-sm {
            padding: 8px 14px;
            font-size: 12px;
            border-radius: 8px;
        }

        .btn-group {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        /* ========== API Status ========== */
        .api-status-card {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 24px;
        }

        .api-status-card.connected {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .api-status-card.failed {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .api-status-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .api-status-card.connected .api-status-icon {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }

        .api-status-card.failed .api-status-icon {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
        }

        /* ========== Trading Pairs Table ========== */
        .pairs-section {
            margin-bottom: 24px;
        }

        .pairs-section-title {
            font-size: 16px;
            font-weight: 700;
            padding: 12px 0;
            margin-bottom: 12px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .pairs-section-title .count {
            background: rgba(245, 158, 11, 0.1);
            color: var(--accent);
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 12px;
        }

        .pairs-table {
            width: 100%;
            border-collapse: collapse;
        }

        .pairs-table thead {
            background: rgba(220, 38, 38, 0.05);
        }

        .pairs-table th {
            padding: 14px 16px;
            text-align: <?= is_rtl() ? 'right' : 'left' ?>;
            font-weight: 700;
            font-size: 12px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .pairs-table td {
            padding: 14px 16px;
            border-top: 1px solid var(--border);
            font-size: 14px;
            vertical-align: middle;
        }

        .pairs-table tbody tr {
            transition: background 0.2s;
        }

        .pairs-table tbody tr:hover {
            background: rgba(220, 38, 38, 0.02);
        }

        .pair-symbol {
            font-weight: 700;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            color: var(--accent);
        }

        .pair-icon {
            font-size: 22px;
            margin-<?= is_rtl() ? 'left' : 'right' ?>: 8px;
        }

        .pair-name {
            display: flex;
            align-items: center;
        }

        .mini-toggle {
            position: relative;
            width: 36px;
            height: 20px;
            flex-shrink: 0;
        }

        .mini-toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .mini-toggle .toggle-slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background: rgba(148, 163, 184, 0.3);
            border-radius: 20px;
            transition: all 0.3s;
        }

        .mini-toggle .toggle-slider::before {
            content: '';
            position: absolute;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: white;
            top: 3px;
            <?= is_rtl() ? 'right' : 'left' ?>: 3px;
            transition: all 0.3s;
        }

        .mini-toggle input:checked + .toggle-slider {
            background: var(--success);
        }

        .mini-toggle input:checked + .toggle-slider::before {
            transform: translateX(<?= is_rtl() ? '-16px' : '16px' ?>);
        }

        .pair-amount-input {
            width: 100px;
            padding: 6px 10px;
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--text);
            font-size: 12px;
            font-family: inherit;
        }

        .pair-amount-input:focus {
            outline: none;
            border-color: var(--accent);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
        }

        .status-badge.active {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .status-badge.inactive {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        /* ========== Mobile Toggle ========== */
        .mobile-toggle {
            display: none;
            position: fixed;
            top: 20px;
            <?= is_rtl() ? 'right' : 'left' ?>: 20px;
            z-index: 1100;
            width: 44px;
            height: 44px;
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: 10px;
            align-items: center;
            justify-content: center;
            color: var(--text);
            font-size: 20px;
            cursor: pointer;
        }

        /* ========== Responsive ========== */
        @media (max-width: 1024px) {
            .sidebar {
                transform: translateX(<?= is_rtl() ? '100%' : '-100%' ?>);
                transition: transform 0.3s ease;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .mobile-toggle {
                display: flex;
            }

            .main {
                margin-<?= is_rtl() ? 'right' : 'left' ?>: 0;
                padding: 80px 20px 20px;
            }
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .page-title {
                font-size: 24px;
            }

            .settings-card {
                padding: 20px;
            }

            .pairs-table {
                display: block;
                overflow-x: auto;
            }

            .tab-btn {
                padding: 10px 14px;
                font-size: 12px;
            }
        }

        /* ========== Overlay ========== */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }

        .sidebar-overlay.active {
            display: block;
        }

        /* ========== Divider ========== */
        .divider {
            border: none;
            border-top: 1px solid var(--border);
            margin: 24px 0;
        }

        /* ========== Loading spinner ========== */
        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <!-- Mobile Toggle -->
    <button class="mobile-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="logo">
            <div class="logo-content">
                <div class="logo-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <div>
                    <div class="logo-text">Admin Panel</div>
                    <div class="admin-badge">Administrator</div>
                </div>
            </div>
        </div>

        <div class="admin-card">
            <div class="admin-info">
                <div class="admin-avatar">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div>
                    <div class="admin-name"><?= htmlspecialchars($admin['username']) ?></div>
                    <div style="font-size: 12px; color: var(--text-muted);">Super Admin</div>
                </div>
            </div>
        </div>

        <nav class="nav-menu">
            <div class="nav-section-title"><?= $lang === 'ar' ? 'الإدارة' : 'Management' ?></div>
            <a href="index.php" class="nav-item">
                <i class="fas fa-chart-pie"></i>
                <span><?= $lang === 'ar' ? 'لوحة التحكم' : 'Dashboard' ?></span>
            </a>
            <a href="users.php" class="nav-item">
                <i class="fas fa-users"></i>
                <span><?= $lang === 'ar' ? 'المستخدمين' : 'Users' ?></span>
            </a>
            <a href="payments.php" class="nav-item">
                <i class="fas fa-credit-card"></i>
                <span><?= $lang === 'ar' ? 'المدفوعات' : 'Payments' ?></span>
            </a>
            <a href="trades.php" class="nav-item">
                <i class="fas fa-exchange-alt"></i>
                <span><?= $lang === 'ar' ? 'الصفقات' : 'Trades' ?></span>
            </a>
            <a href="gateways.php" class="nav-item">
                <i class="fas fa-university"></i>
                <span><?= $lang === 'ar' ? 'بوابات الدفع' : 'Gateways' ?></span>
            </a>
            <a href="reports.php" class="nav-item">
                <i class="fas fa-file-alt"></i>
                <span><?= $lang === 'ar' ? 'التقارير' : 'Reports' ?></span>
            </a>
            <a href="settings.php" class="nav-item active">
                <i class="fas fa-cog"></i>
                <span><?= $lang === 'ar' ? 'الإعدادات' : 'Settings' ?></span>
            </a>

            <div class="nav-section-title"><?= $lang === 'ar' ? 'أخرى' : 'Other' ?></div>
            <a href="../dashboard.php" class="nav-item">
                <i class="fas fa-arrow-<?= is_rtl() ? 'right' : 'left' ?>"></i>
                <span><?= $lang === 'ar' ? 'العودة للمنصة' : 'Back to Platform' ?></span>
            </a>
            <a href="../logout.php" class="nav-item" style="color: var(--danger);">
                <i class="fas fa-sign-out-alt"></i>
                <span><?= $lang === 'ar' ? 'تسجيل الخروج' : 'Logout' ?></span>
            </a>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main">
        <div class="header">
            <h1 class="page-title"><?= $lang === 'ar' ? 'إعدادات المنصة' : 'Platform Settings' ?></h1>
            <div class="breadcrumb">
                <a href="index.php"><?= $lang === 'ar' ? 'لوحة التحكم' : 'Dashboard' ?></a>
                <i class="fas fa-chevron-<?= is_rtl() ? 'left' : 'right' ?>" style="font-size: 10px;"></i>
                <span><?= $lang === 'ar' ? 'الإعدادات' : 'Settings' ?></span>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($success_message): ?>
            <div class="alert alert-success" id="alertMsg">
                <i class="fas fa-check-circle"></i>
                <span><?= $lang === 'ar' ? 'تم حفظ الإعدادات بنجاح' : 'Settings saved successfully' ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-error" id="alertMsg">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($error_message) ?></span>
            </div>
        <?php endif; ?>

        <!-- Tabs Navigation -->
        <div class="tabs-wrapper">
            <div class="tabs">
                <button class="tab-btn <?= $active_tab === 'platform' ? 'active' : '' ?>" onclick="switchTab('platform')">
                    <i class="fas fa-globe"></i>
                    <?= $lang === 'ar' ? 'المنصة' : 'Platform' ?>
                </button>
                <button class="tab-btn <?= $active_tab === 'trading' ? 'active' : '' ?>" onclick="switchTab('trading')">
                    <i class="fas fa-chart-line"></i>
                    <?= $lang === 'ar' ? 'التداول' : 'Trading' ?>
                </button>
                <button class="tab-btn <?= $active_tab === 'limits' ? 'active' : '' ?>" onclick="switchTab('limits')">
                    <i class="fas fa-sliders-h"></i>
                    <?= $lang === 'ar' ? 'الحدود' : 'Limits' ?>
                </button>
                <button class="tab-btn <?= $active_tab === 'email' ? 'active' : '' ?>" onclick="switchTab('email')">
                    <i class="fas fa-envelope"></i>
                    <?= $lang === 'ar' ? 'البريد' : 'Email' ?>
                </button>
                <button class="tab-btn <?= $active_tab === 'api' ? 'active' : '' ?>" onclick="switchTab('api')">
                    <i class="fas fa-plug"></i>
                    <?= $lang === 'ar' ? 'API' : 'API' ?>
                </button>
                <button class="tab-btn <?= $active_tab === 'security' ? 'active' : '' ?>" onclick="switchTab('security')">
                    <i class="fas fa-shield-alt"></i>
                    <?= $lang === 'ar' ? 'الأمان' : 'Security' ?>
                </button>
                <button class="tab-btn <?= $active_tab === 'maintenance' ? 'active' : '' ?>" onclick="switchTab('maintenance')">
                    <i class="fas fa-tools"></i>
                    <?= $lang === 'ar' ? 'الصيانة' : 'Maintenance' ?>
                </button>
                <button class="tab-btn <?= $active_tab === 'pairs' ? 'active' : '' ?>" onclick="switchTab('pairs')">
                    <i class="fas fa-coins"></i>
                    <?= $lang === 'ar' ? 'أزواج التداول' : 'Trading Pairs' ?>
                </button>
            </div>
        </div>

        <!-- ==================== TAB 1: Platform Settings ==================== -->
        <div id="tab-platform" class="tab-content <?= $active_tab === 'platform' ? 'active' : '' ?>">
            <div class="settings-card">
                <h3 class="settings-title">
                    <i class="fas fa-globe"></i>
                    <?= $lang === 'ar' ? 'إعدادات المنصة العامة' : 'General Platform Settings' ?>
                </h3>
                <p class="settings-subtitle"><?= $lang === 'ar' ? 'تعديل اسم المنصة ومعلومات الاتصال واللغة الافتراضية' : 'Modify platform name, contact info, and default language' ?></p>

                <form method="POST">
                    <input type="hidden" name="tab" value="platform">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'اسم المنصة (إنجليزي)' : 'Platform Name (English)' ?></label>
                            <input type="text" name="platform_name_en" class="form-input" value="<?= htmlspecialchars(get_setting($pdo, 'platform_name_en', 'HeroTrade')) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'اسم المنصة (عربي)' : 'Platform Name (Arabic)' ?></label>
                            <input type="text" name="platform_name_ar" class="form-input" value="<?= htmlspecialchars(get_setting($pdo, 'platform_name_ar', 'هيرو تريد')) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'اسم الموقع (إنجليزي)' : 'Site Name (English)' ?></label>
                            <input type="text" name="site_name_en" class="form-input" value="<?= htmlspecialchars(get_setting($pdo, 'site_name_en', 'HeroTrade Platform')) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'اسم الموقع (عربي)' : 'Site Name (Arabic)' ?></label>
                            <input type="text" name="site_name_ar" class="form-input" value="<?= htmlspecialchars(get_setting($pdo, 'site_name_ar', 'منصة هيرو تريد')) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'بريد الدعم' : 'Support Email' ?></label>
                            <input type="email" name="support_email" class="form-input" value="<?= htmlspecialchars(get_setting($pdo, 'support_email', 'support@herotrade.com')) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'هاتف الدعم' : 'Support Phone' ?></label>
                            <input type="text" name="support_phone" class="form-input" value="<?= htmlspecialchars(get_setting($pdo, 'support_phone', '')) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'اللغة الافتراضية' : 'Default Language' ?></label>
                            <select name="default_language" class="form-select">
                                <option value="ar" <?= get_setting($pdo, 'default_language', 'ar') === 'ar' ? 'selected' : '' ?>><?= $lang === 'ar' ? 'العربية' : 'Arabic' ?></option>
                                <option value="en" <?= get_setting($pdo, 'default_language', 'ar') === 'en' ? 'selected' : '' ?>><?= $lang === 'ar' ? 'الإنجليزية' : 'English' ?></option>
                            </select>
                        </div>
                    </div>

                    <hr class="divider">

                    <div class="toggle-group">
                        <div class="toggle-label">
                            <span class="toggle-label-text"><?= $lang === 'ar' ? 'وضع الصيانة' : 'Maintenance Mode' ?></span>
                            <span class="toggle-label-hint"><?= $lang === 'ar' ? 'عند التفعيل، لن يتمكن المستخدمون من الوصول للمنصة' : 'When enabled, users cannot access the platform' ?></span>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="maintenance_mode" value="1" <?= get_setting($pdo, 'maintenance_mode', '0') == '1' ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <div class="form-grid" style="margin-top: 16px;">
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'رسالة الصيانة (عربي)' : 'Maintenance Message (Arabic)' ?></label>
                            <textarea name="maintenance_message_ar" class="form-textarea"><?= htmlspecialchars(get_setting($pdo, 'maintenance_message_ar', 'المنصة تحت الصيانة حالياً. يرجى المحاولة لاحقاً.')) ?></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'رسالة الصيانة (إنجليزي)' : 'Maintenance Message (English)' ?></label>
                            <textarea name="maintenance_message_en" class="form-textarea"><?= htmlspecialchars(get_setting($pdo, 'maintenance_message_en', 'Platform is under maintenance. Please try again later.')) ?></textarea>
                        </div>
                    </div>

                    <button type="submit" name="save_settings" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        <?= $lang === 'ar' ? 'حفظ الإعدادات' : 'Save Settings' ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- ==================== TAB 2: Trading Settings ==================== -->
        <div id="tab-trading" class="tab-content <?= $active_tab === 'trading' ? 'active' : '' ?>">
            <div class="settings-card">
                <h3 class="settings-title">
                    <i class="fas fa-chart-line"></i>
                    <?= $lang === 'ar' ? 'إعدادات التداول' : 'Trading Settings' ?>
                </h3>
                <p class="settings-subtitle"><?= $lang === 'ar' ? 'تفعيل/تعطيل أنواع التداول وضبط الرسوم والحدود' : 'Enable/disable trading types and configure fees and limits' ?></p>

                <form method="POST">
                    <input type="hidden" name="tab" value="trading">

                    <!-- Trading Type Toggles -->
                    <div style="margin-bottom: 24px;">
                        <div class="toggle-group">
                            <div class="toggle-label">
                                <span class="toggle-label-text"><?= $lang === 'ar' ? 'تداول Spot' : 'Spot Trading' ?></span>
                                <span class="toggle-label-hint"><?= $lang === 'ar' ? 'شراء وبيع الأصول بالسعر الحالي' : 'Buy and sell assets at current price' ?></span>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="spot_trading_enabled" value="1" <?= get_setting($pdo, 'spot_trading_enabled', '1') == '1' ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <div class="toggle-group">
                            <div class="toggle-label">
                                <span class="toggle-label-text"><?= $lang === 'ar' ? 'تداول Binary' : 'Binary Trading' ?></span>
                                <span class="toggle-label-hint"><?= $lang === 'ar' ? 'التداول بنظام الخيارات الثنائية' : 'Binary options trading' ?></span>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="binary_trading_enabled" value="1" <?= get_setting($pdo, 'binary_trading_enabled', '1') == '1' ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <div class="toggle-group">
                            <div class="toggle-label">
                                <span class="toggle-label-text"><?= $lang === 'ar' ? 'الفوركس' : 'Forex' ?></span>
                                <span class="toggle-label-hint"><?= $lang === 'ar' ? 'تداول أزواج العملات الأجنبية' : 'Foreign currency pair trading' ?></span>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="forex_enabled" value="1" <?= get_setting($pdo, 'forex_enabled', '1') == '1' ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <div class="toggle-group">
                            <div class="toggle-label">
                                <span class="toggle-label-text"><?= $lang === 'ar' ? 'المؤشرات' : 'Indices' ?></span>
                                <span class="toggle-label-hint"><?= $lang === 'ar' ? 'تداول مؤشرات الأسواق العالمية' : 'Global market indices trading' ?></span>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="indices_enabled" value="1" <?= get_setting($pdo, 'indices_enabled', '0') == '1' ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <div class="toggle-group">
                            <div class="toggle-label">
                                <span class="toggle-label-text"><?= $lang === 'ar' ? 'الأسهم' : 'Stocks' ?></span>
                                <span class="toggle-label-hint"><?= $lang === 'ar' ? 'تداول أسهم الشركات' : 'Company stock trading' ?></span>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="stocks_enabled" value="1" <?= get_setting($pdo, 'stocks_enabled', '0') == '1' ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>

                    <hr class="divider">

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'الحد الأدنى للإيداع ($)' : 'Minimum Deposit ($)' ?></label>
                            <input type="number" name="min_deposit" class="form-input" step="0.01" value="<?= get_setting($pdo, 'min_deposit', 10) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'الحد الأقصى للإيداع ($)' : 'Maximum Deposit ($)' ?></label>
                            <input type="number" name="max_deposit" class="form-input" step="0.01" value="<?= get_setting($pdo, 'max_deposit', 100000) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'رسوم Spot (%)' : 'Spot Trading Fee (%)' ?></label>
                            <input type="number" name="spot_trading_fee" class="form-input" step="0.01" value="<?= get_setting($pdo, 'spot_trading_fee', 0.1) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'نسبة ربح Binary (%)' : 'Binary Payout (%)' ?></label>
                            <input type="number" name="binary_payout_percentage" class="form-input" step="1" min="50" max="95" value="<?= get_setting($pdo, 'binary_payout_percentage', 85) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'أقل مدة Binary (ثانية)' : 'Binary Min Duration (sec)' ?></label>
                            <input type="number" name="binary_min_duration" class="form-input" step="1" value="<?= get_setting($pdo, 'binary_min_duration', 60) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'أقصى مدة Binary (ثانية)' : 'Binary Max Duration (sec)' ?></label>
                            <input type="number" name="binary_max_duration" class="form-input" step="1" value="<?= get_setting($pdo, 'binary_max_duration', 3600) ?>">
                        </div>
                    </div>

                    <button type="submit" name="save_settings" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        <?= $lang === 'ar' ? 'حفظ الإعدادات' : 'Save Settings' ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- ==================== TAB 3: Deposit/Withdrawal Limits ==================== -->
        <div id="tab-limits" class="tab-content <?= $active_tab === 'limits' ? 'active' : '' ?>">
            <div class="settings-card">
                <h3 class="settings-title">
                    <i class="fas fa-sliders-h"></i>
                    <?= $lang === 'ar' ? 'حدود الإيداع والسحب' : 'Deposit & Withdrawal Limits' ?>
                </h3>
                <p class="settings-subtitle"><?= $lang === 'ar' ? 'ضبط الحدود والموافقة التلقائية على المعاملات' : 'Configure limits and auto-approval thresholds' ?></p>

                <form method="POST">
                    <input type="hidden" name="tab" value="limits">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'أقل إيداع ($)' : 'Min Deposit ($)' ?></label>
                            <input type="number" name="min_deposit" class="form-input" step="0.01" value="<?= get_setting($pdo, 'min_deposit', 10) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'أقصى إيداع ($)' : 'Max Deposit ($)' ?></label>
                            <input type="number" name="max_deposit" class="form-input" step="0.01" value="<?= get_setting($pdo, 'max_deposit', 100000) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'أقل سحب ($)' : 'Min Withdrawal ($)' ?></label>
                            <input type="number" name="min_withdrawal" class="form-input" step="0.01" value="<?= get_setting($pdo, 'min_withdrawal', 10) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'أقصى سحب ($)' : 'Max Withdrawal ($)' ?></label>
                            <input type="number" name="max_withdrawal" class="form-input" step="0.01" value="<?= get_setting($pdo, 'max_withdrawal', 50000) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'حد السحب اليومي ($)' : 'Daily Withdrawal Limit ($)' ?></label>
                            <input type="number" name="daily_withdrawal_limit" class="form-input" step="0.01" value="<?= get_setting($pdo, 'daily_withdrawal_limit', 10000) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'مدة معالجة السحب (ساعة)' : 'Withdrawal Processing (hours)' ?></label>
                            <input type="number" name="withdrawal_processing_hours" class="form-input" step="1" value="<?= get_setting($pdo, 'withdrawal_processing_hours', 24) ?>">
                        </div>
                    </div>

                    <hr class="divider">
                    <h4 style="margin-bottom: 16px; color: var(--accent);">
                        <i class="fas fa-magic"></i>
                        <?= $lang === 'ar' ? 'الموافقة التلقائية' : 'Auto-Approval' ?>
                    </h4>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'موافقة تلقائية للإيداع تحت ($)' : 'Auto-Approve Deposit Under ($)' ?></label>
                            <input type="number" name="auto_approve_deposit_under" class="form-input" step="0.01" value="<?= get_setting($pdo, 'auto_approve_deposit_under', 0) ?>">
                            <span class="form-hint"><?= $lang === 'ar' ? '0 = تعطيل الموافقة التلقائية' : '0 = Disable auto-approval' ?></span>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'موافقة تلقائية للسحب تحت ($)' : 'Auto-Approve Withdrawal Under ($)' ?></label>
                            <input type="number" name="auto_approve_withdrawal_under" class="form-input" step="0.01" value="<?= get_setting($pdo, 'auto_approve_withdrawal_under', 0) ?>">
                            <span class="form-hint"><?= $lang === 'ar' ? '0 = تعطيل الموافقة التلقائية' : '0 = Disable auto-approval' ?></span>
                        </div>
                    </div>

                    <button type="submit" name="save_settings" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        <?= $lang === 'ar' ? 'حفظ الإعدادات' : 'Save Settings' ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- ==================== TAB 4: Email Settings ==================== -->
        <div id="tab-email" class="tab-content <?= $active_tab === 'email' ? 'active' : '' ?>">
            <div class="settings-card">
                <h3 class="settings-title">
                    <i class="fas fa-envelope"></i>
                    <?= $lang === 'ar' ? 'إعدادات البريد الإلكتروني (SMTP)' : 'Email Settings (SMTP)' ?>
                </h3>
                <p class="settings-subtitle"><?= $lang === 'ar' ? 'إعداد خادم SMTP لإرسال رسائل البريد الإلكتروني' : 'Configure SMTP server for sending emails' ?></p>

                <?php if ($email_test_result === 'sent'): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?= $lang === 'ar' ? 'تم إرسال بريد الاختبار بنجاح' : 'Test email sent successfully' ?>
                    </div>
                <?php elseif ($email_test_result === 'no_config'): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?= $lang === 'ar' ? 'يرجى إعداد SMTP أولاً' : 'Please configure SMTP first' ?>
                    </div>
                <?php elseif ($email_test_result === 'invalid_email'): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-times-circle"></i>
                        <?= $lang === 'ar' ? 'بريد إلكتروني غير صالح' : 'Invalid email address' ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="tab" value="email">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'خادم SMTP' : 'SMTP Host' ?></label>
                            <input type="text" name="smtp_host" class="form-input" placeholder="smtp.gmail.com" value="<?= htmlspecialchars(get_setting($pdo, 'smtp_host', '')) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'منفذ SMTP' : 'SMTP Port' ?></label>
                            <input type="number" name="smtp_port" class="form-input" placeholder="587" value="<?= get_setting($pdo, 'smtp_port', 587) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'نوع التشفير' : 'Encryption' ?></label>
                            <select name="smtp_encryption" class="form-select">
                                <option value="tls" <?= get_setting($pdo, 'smtp_encryption', 'tls') === 'tls' ? 'selected' : '' ?>>TLS</option>
                                <option value="ssl" <?= get_setting($pdo, 'smtp_encryption', 'tls') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                <option value="none" <?= get_setting($pdo, 'smtp_encryption', 'tls') === 'none' ? 'selected' : '' ?>>None</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'اسم المستخدم' : 'SMTP Username' ?></label>
                            <input type="text" name="smtp_username" class="form-input" value="<?= htmlspecialchars(get_setting($pdo, 'smtp_username', '')) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'كلمة المرور' : 'SMTP Password' ?></label>
                            <input type="password" name="smtp_password" class="form-input" value="<?= htmlspecialchars(get_setting($pdo, 'smtp_password', '')) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'بريد المرسل' : 'From Email' ?></label>
                            <input type="email" name="smtp_from_email" class="form-input" placeholder="noreply@herotrade.com" value="<?= htmlspecialchars(get_setting($pdo, 'smtp_from_email', '')) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'اسم المرسل' : 'From Name' ?></label>
                            <input type="text" name="smtp_from_name" class="form-input" placeholder="HeroTrade" value="<?= htmlspecialchars(get_setting($pdo, 'smtp_from_name', '')) ?>">
                        </div>
                    </div>

                    <div class="btn-group">
                        <button type="submit" name="save_settings" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            <?= $lang === 'ar' ? 'حفظ الإعدادات' : 'Save Settings' ?>
                        </button>
                    </div>
                </form>

                <hr class="divider">

                <!-- Test Email -->
                <h4 style="margin-bottom: 16px; color: var(--info);">
                    <i class="fas fa-paper-plane"></i>
                    <?= $lang === 'ar' ? 'إرسال بريد اختبار' : 'Send Test Email' ?>
                </h4>
                <form method="POST" style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
                    <div class="form-group" style="flex: 1; min-width: 250px;">
                        <label class="form-label"><?= $lang === 'ar' ? 'بريد الاختبار' : 'Test Email Address' ?></label>
                        <input type="email" name="test_email_to" class="form-input" placeholder="test@example.com" required>
                    </div>
                    <button type="submit" name="test_email" class="btn btn-info" style="height: fit-content;">
                        <i class="fas fa-paper-plane"></i>
                        <?= $lang === 'ar' ? 'إرسال اختبار' : 'Send Test' ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- ==================== TAB 5: API Settings ==================== -->
        <div id="tab-api" class="tab-content <?= $active_tab === 'api' ? 'active' : '' ?>">
            <div class="settings-card">
                <h3 class="settings-title">
                    <i class="fas fa-plug"></i>
                    <?= $lang === 'ar' ? 'إعدادات API' : 'API Settings' ?>
                </h3>
                <p class="settings-subtitle"><?= $lang === 'ar' ? 'إعداد مفتاح TwelveData API للحصول على أسعار الأصول الحية' : 'Configure TwelveData API key for live asset prices' ?></p>

                <?php if ($api_status === 'connected'): ?>
                    <div class="api-status-card connected">
                        <div class="api-status-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div>
                            <strong><?= $lang === 'ar' ? 'متصل بنجاح' : 'Connected Successfully' ?></strong>
                            <?php if ($api_response_data): ?>
                                <div style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">
                                    BTC/USD: $<?= number_format(floatval($api_response_data['close'] ?? $api_response_data['price'] ?? 0), 2) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php elseif ($api_status === 'failed'): ?>
                    <div class="api-status-card failed">
                        <div class="api-status-icon">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div>
                            <strong><?= $lang === 'ar' ? 'فشل الاتصال' : 'Connection Failed' ?></strong>
                            <div style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">
                                <?= $lang === 'ar' ? 'تحقق من مفتاح API واتصال الإنترنت' : 'Check your API key and internet connection' ?>
                            </div>
                        </div>
                    </div>
                <?php elseif ($api_status === 'no_key'): ?>
                    <div class="api-status-card failed">
                        <div class="api-status-icon">
                            <i class="fas fa-key"></i>
                        </div>
                        <div>
                            <strong><?= $lang === 'ar' ? 'لم يتم تعيين مفتاح API' : 'No API Key Set' ?></strong>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="tab" value="api">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'مفتاح TwelveData API' : 'TwelveData API Key' ?></label>
                            <input type="text" name="twelvedata_api_key" class="form-input" placeholder="your-api-key-here" value="<?= htmlspecialchars(get_setting($pdo, 'twelvedata_api_key', '')) ?>">
                            <span class="form-hint"><?= $lang === 'ar' ? 'احصل على المفتاح من: https://twelvedata.com' : 'Get your key from: https://twelvedata.com' ?></span>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'فترة تحديث الأسعار (ثانية)' : 'Price Update Interval (sec)' ?></label>
                            <input type="number" name="price_update_interval" class="form-input" step="1" min="5" value="<?= get_setting($pdo, 'price_update_interval', 30) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'خطة API' : 'API Plan' ?></label>
                            <select name="api_plan" class="form-select">
                                <option value="free" <?= get_setting($pdo, 'api_plan', 'free') === 'free' ? 'selected' : '' ?>>Free (800 req/day)</option>
                                <option value="starter" <?= get_setting($pdo, 'api_plan', 'free') === 'starter' ? 'selected' : '' ?>>Starter</option>
                                <option value="growth" <?= get_setting($pdo, 'api_plan', 'free') === 'growth' ? 'selected' : '' ?>>Growth</option>
                                <option value="business" <?= get_setting($pdo, 'api_plan', 'free') === 'business' ? 'selected' : '' ?>>Business</option>
                            </select>
                        </div>
                    </div>

                    <div class="btn-group">
                        <button type="submit" name="save_settings" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            <?= $lang === 'ar' ? 'حفظ الإعدادات' : 'Save Settings' ?>
                        </button>
                    </div>
                </form>

                <hr class="divider">

                <form method="POST">
                    <button type="submit" name="test_api" class="btn btn-info">
                        <i class="fas fa-wifi"></i>
                        <?= $lang === 'ar' ? 'اختبار الاتصال' : 'Test Connection' ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- ==================== TAB 6: Security Settings ==================== -->
        <div id="tab-security" class="tab-content <?= $active_tab === 'security' ? 'active' : '' ?>">
            <div class="settings-card">
                <h3 class="settings-title">
                    <i class="fas fa-shield-alt"></i>
                    <?= $lang === 'ar' ? 'إعدادات الأمان' : 'Security Settings' ?>
                </h3>
                <p class="settings-subtitle"><?= $lang === 'ar' ? 'ضبط إعدادات الأمان والتحقق من الهوية' : 'Configure security, verification, and authentication settings' ?></p>

                <form method="POST">
                    <input type="hidden" name="tab" value="security">

                    <div class="toggle-group">
                        <div class="toggle-label">
                            <span class="toggle-label-text"><?= $lang === 'ar' ? 'المصادقة الثنائية (2FA)' : 'Two-Factor Authentication (2FA)' ?></span>
                            <span class="toggle-label-hint"><?= $lang === 'ar' ? 'إلزام المستخدمين بتفعيل المصادقة الثنائية' : 'Require users to enable 2FA' ?></span>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="enable_2fa" value="1" <?= get_setting($pdo, 'enable_2fa', '0') == '1' ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <div class="toggle-group">
                        <div class="toggle-label">
                            <span class="toggle-label-text"><?= $lang === 'ar' ? 'التحقق من البريد الإلكتروني' : 'Email Verification' ?></span>
                            <span class="toggle-label-hint"><?= $lang === 'ar' ? 'إلزام التحقق من البريد عند التسجيل' : 'Require email verification on registration' ?></span>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="require_email_verification" value="1" <?= get_setting($pdo, 'require_email_verification', '0') == '1' ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <div class="toggle-group">
                        <div class="toggle-label">
                            <span class="toggle-label-text"><?= $lang === 'ar' ? 'التحقق من الهوية (KYC)' : 'KYC Verification' ?></span>
                            <span class="toggle-label-hint"><?= $lang === 'ar' ? 'إلزام المستخدمين بالتحقق من هويتهم للتداول' : 'Require identity verification for trading' ?></span>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="enable_kyc" value="1" <?= get_setting($pdo, 'enable_kyc', '0') == '1' ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <div class="toggle-group">
                        <div class="toggle-label">
                            <span class="toggle-label-text"><?= $lang === 'ar' ? 'تسجيل جميع الأنشطة' : 'Log All Activities' ?></span>
                            <span class="toggle-label-hint"><?= $lang === 'ar' ? 'تسجيل كل عمليات تسجيل الدخول والإجراءات' : 'Log all logins and user actions' ?></span>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="log_all_activities" value="1" <?= get_setting($pdo, 'log_all_activities', '1') == '1' ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <hr class="divider">

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'أقصى محاولات تسجيل دخول' : 'Max Login Attempts' ?></label>
                            <input type="number" name="max_login_attempts" class="form-input" min="3" max="20" value="<?= get_setting($pdo, 'max_login_attempts', 5) ?>">
                            <span class="form-hint"><?= $lang === 'ar' ? 'سيتم قفل الحساب مؤقتاً بعد تجاوز هذا العدد' : 'Account will be temporarily locked after this many attempts' ?></span>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'مهلة الجلسة (دقيقة)' : 'Session Timeout (minutes)' ?></label>
                            <input type="number" name="session_timeout" class="form-input" min="5" value="<?= get_setting($pdo, 'session_timeout', 1440) ?>">
                            <span class="form-hint"><?= $lang === 'ar' ? 'مدة صلاحية الجلسة قبل تسجيل الخروج التلقائي' : 'Session duration before automatic logout' ?></span>
                        </div>
                    </div>

                    <button type="submit" name="save_settings" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        <?= $lang === 'ar' ? 'حفظ الإعدادات' : 'Save Settings' ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- ==================== TAB 7: Maintenance Mode ==================== -->
        <div id="tab-maintenance" class="tab-content <?= $active_tab === 'maintenance' ? 'active' : '' ?>">
            <div class="settings-card">
                <h3 class="settings-title">
                    <i class="fas fa-tools"></i>
                    <?= $lang === 'ar' ? 'وضع الصيانة' : 'Maintenance Mode' ?>
                </h3>
                <p class="settings-subtitle"><?= $lang === 'ar' ? 'تفعيل وضع الصيانة يمنع المستخدمين من الوصول للمنصة' : 'Enabling maintenance mode prevents users from accessing the platform' ?></p>

                <form method="POST">
                    <input type="hidden" name="tab" value="maintenance">

                    <div class="toggle-group" style="margin-bottom: 24px; border-color: <?= get_setting($pdo, 'maintenance_mode', '0') == '1' ? 'rgba(239, 68, 68, 0.3)' : 'var(--border)' ?>; background: <?= get_setting($pdo, 'maintenance_mode', '0') == '1' ? 'rgba(239, 68, 68, 0.05)' : 'rgba(0,0,0,0.2)' ?>;">
                        <div class="toggle-label">
                            <span class="toggle-label-text" style="font-size: 18px;">
                                <i class="fas fa-power-off" style="color: <?= get_setting($pdo, 'maintenance_mode', '0') == '1' ? 'var(--danger)' : 'var(--success)' ?>; margin-<?= is_rtl() ? 'left' : 'right' ?>: 8px;"></i>
                                <?= $lang === 'ar' ? 'وضع الصيانة' : 'Maintenance Mode' ?>
                            </span>
                            <span class="toggle-label-hint">
                                <?php if (get_setting($pdo, 'maintenance_mode', '0') == '1'): ?>
                                    <span style="color: var(--danger); font-weight: 600;"><?= $lang === 'ar' ? 'المنصة معطلة حالياً' : 'Platform is currently offline' ?></span>
                                <?php else: ?>
                                    <span style="color: var(--success);"><?= $lang === 'ar' ? 'المنصة تعمل بشكل طبيعي' : 'Platform is running normally' ?></span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="maintenance_mode" value="1" <?= get_setting($pdo, 'maintenance_mode', '0') == '1' ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'رسالة الصيانة (عربي)' : 'Maintenance Message (Arabic)' ?></label>
                            <textarea name="maintenance_message_ar" class="form-textarea" rows="4"><?= htmlspecialchars(get_setting($pdo, 'maintenance_message_ar', 'المنصة تحت الصيانة حالياً. يرجى المحاولة لاحقاً.')) ?></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'رسالة الصيانة (إنجليزي)' : 'Maintenance Message (English)' ?></label>
                            <textarea name="maintenance_message_en" class="form-textarea" rows="4"><?= htmlspecialchars(get_setting($pdo, 'maintenance_message_en', 'Platform is under maintenance. Please try again later.')) ?></textarea>
                        </div>
                    </div>

                    <button type="submit" name="save_settings" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        <?= $lang === 'ar' ? 'حفظ الإعدادات' : 'Save Settings' ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- ==================== TAB 8: Trading Pairs ==================== -->
        <div id="tab-pairs" class="tab-content <?= $active_tab === 'pairs' ? 'active' : '' ?>">
            <!-- Add New Pair -->
            <div class="settings-card">
                <h3 class="settings-title">
                    <i class="fas fa-plus-circle"></i>
                    <?= $lang === 'ar' ? 'إضافة زوج تداول جديد' : 'Add New Trading Pair' ?>
                </h3>
                <p class="settings-subtitle"><?= $lang === 'ar' ? 'إضافة زوج تداول جديد إلى المنصة' : 'Add a new trading pair to the platform' ?></p>

                <form method="POST">
                    <div class="form-grid form-grid-3">
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'الرمز' : 'Symbol' ?> <span class="required">*</span></label>
                            <input type="text" name="symbol" class="form-input" placeholder="BTC/USD" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'العملة الأساسية' : 'Base Currency' ?> <span class="required">*</span></label>
                            <input type="text" name="base_currency" class="form-input" placeholder="BTC" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'عملة التسعير' : 'Quote Currency' ?> <span class="required">*</span></label>
                            <input type="text" name="quote_currency" class="form-input" placeholder="USD" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'الاسم (عربي)' : 'Name (Arabic)' ?> <span class="required">*</span></label>
                            <input type="text" name="name_ar" class="form-input" placeholder="<?= $lang === 'ar' ? 'بيتكوين' : 'Bitcoin' ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'الاسم (إنجليزي)' : 'Name (English)' ?> <span class="required">*</span></label>
                            <input type="text" name="name_en" class="form-input" placeholder="Bitcoin" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'الأيقونة' : 'Icon' ?></label>
                            <input type="text" name="icon" class="form-input" placeholder="BTC" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'نوع الأصل' : 'Asset Type' ?></label>
                            <select name="asset_type" class="form-select">
                                <option value="crypto"><?= $lang === 'ar' ? 'عملات رقمية' : 'Crypto' ?></option>
                                <option value="forex"><?= $lang === 'ar' ? 'فوركس' : 'Forex' ?></option>
                                <option value="indices"><?= $lang === 'ar' ? 'مؤشرات' : 'Indices' ?></option>
                                <option value="stocks"><?= $lang === 'ar' ? 'أسهم' : 'Stocks' ?></option>
                                <option value="commodities"><?= $lang === 'ar' ? 'سلع' : 'Commodities' ?></option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'السعر الابتدائي ($)' : 'Initial Price ($)' ?> <span class="required">*</span></label>
                            <input type="number" name="initial_price" class="form-input" step="0.00000001" placeholder="67000" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'أقل مبلغ ($)' : 'Min Amount ($)' ?></label>
                            <input type="number" name="min_amount" class="form-input" step="0.01" value="10">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?= $lang === 'ar' ? 'أقصى مبلغ ($)' : 'Max Amount ($)' ?></label>
                            <input type="number" name="max_amount" class="form-input" step="0.01" value="100000">
                        </div>
                    </div>

                    <button type="submit" name="add_trading_pair" class="btn btn-primary">
                        <i class="fas fa-plus"></i>
                        <?= $lang === 'ar' ? 'إضافة الزوج' : 'Add Pair' ?>
                    </button>
                </form>
            </div>

            <!-- Trading Pairs List -->
            <div class="settings-card">
                <h3 class="settings-title">
                    <i class="fas fa-list"></i>
                    <?= $lang === 'ar' ? 'أزواج التداول' : 'Trading Pairs' ?>
                    <span style="font-size: 14px; color: var(--text-muted); font-weight: 400;">(<?= count($trading_pairs) ?>)</span>
                </h3>

                <?php if (empty($trading_pairs)): ?>
                    <div style="text-align: center; padding: 48px; color: var(--text-muted);">
                        <i class="fas fa-coins" style="font-size: 48px; margin-bottom: 16px; opacity: 0.3;"></i>
                        <p><?= $lang === 'ar' ? 'لا توجد أزواج تداول. أضف أول زوج أعلاه.' : 'No trading pairs. Add your first pair above.' ?></p>
                    </div>
                <?php else: ?>
                    <?php foreach ($pairs_grouped as $asset_type => $pairs): ?>
                        <div class="pairs-section">
                            <div class="pairs-section-title">
                                <i class="fas fa-<?= $asset_type === 'crypto' ? 'coins' : ($asset_type === 'forex' ? 'money-bill-wave' : ($asset_type === 'stocks' ? 'chart-bar' : ($asset_type === 'indices' ? 'chart-area' : 'box'))) ?>"></i>
                                <?= $asset_type_labels[$asset_type][$lang] ?? ucfirst($asset_type) ?>
                                <span class="count"><?= count($pairs) ?></span>
                            </div>

                            <div style="overflow-x: auto;">
                                <table class="pairs-table">
                                    <thead>
                                        <tr>
                                            <th><?= $lang === 'ar' ? 'الرمز' : 'Symbol' ?></th>
                                            <th><?= $lang === 'ar' ? 'الاسم' : 'Name' ?></th>
                                            <th><?= $lang === 'ar' ? 'السعر' : 'Price' ?></th>
                                            <th><?= $lang === 'ar' ? 'أقل / أقصى' : 'Min / Max' ?></th>
                                            <th><?= $lang === 'ar' ? 'الصفقات' : 'Trades' ?></th>
                                            <th><?= $lang === 'ar' ? 'مفعل' : 'Active' ?></th>
                                            <th>Spot</th>
                                            <th>Binary</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pairs as $pair): ?>
                                            <tr id="pair-row-<?= $pair['id'] ?>">
                                                <td>
                                                    <span class="pair-symbol"><?= htmlspecialchars($pair['symbol']) ?></span>
                                                </td>
                                                <td>
                                                    <div class="pair-name">
                                                        <span class="pair-icon"><?= $pair['icon'] ?></span>
                                                        <?= htmlspecialchars($lang === 'ar' ? ($pair['name_ar'] ?? $pair['name_en'] ?? '') : ($pair['name_en'] ?? $pair['name_ar'] ?? '')) ?>
                                                    </div>
                                                </td>
                                                <td style="font-weight: 700; font-family: monospace;">
                                                    $<?= number_format($pair['current_price'], $pair['current_price'] < 1 ? 6 : 2) ?>
                                                </td>
                                                <td>
                                                    <div style="display: flex; gap: 6px; align-items: center;">
                                                        <input type="number" class="pair-amount-input" value="<?= $pair['min_amount'] ?? 10 ?>" data-pair-id="<?= $pair['id'] ?>" data-field="min_amount" onchange="updatePairAmount(this)" step="0.01">
                                                        <span style="color: var(--text-muted);">/</span>
                                                        <input type="number" class="pair-amount-input" value="<?= $pair['max_amount'] ?? 100000 ?>" data-pair-id="<?= $pair['id'] ?>" data-field="max_amount" onchange="updatePairAmount(this)" step="0.01">
                                                    </div>
                                                </td>
                                                <td>
                                                    <div style="font-size: 12px; color: var(--text-muted);">
                                                        S: <?= $pair['spot_trades'] ?? 0 ?> | B: <?= $pair['binary_trades'] ?? 0 ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <label class="mini-toggle">
                                                        <input type="checkbox" <?= $pair['is_active'] ? 'checked' : '' ?> onchange="togglePairField(<?= $pair['id'] ?>, 'is_active', this)">
                                                        <span class="toggle-slider"></span>
                                                    </label>
                                                </td>
                                                <td>
                                                    <label class="mini-toggle">
                                                        <input type="checkbox" <?= $pair['spot_enabled'] ? 'checked' : '' ?> onchange="togglePairField(<?= $pair['id'] ?>, 'spot_enabled', this)">
                                                        <span class="toggle-slider"></span>
                                                    </label>
                                                </td>
                                                <td>
                                                    <label class="mini-toggle">
                                                        <input type="checkbox" <?= $pair['binary_enabled'] ? 'checked' : '' ?> onchange="togglePairField(<?= $pair['id'] ?>, 'binary_enabled', this)">
                                                        <span class="toggle-slider"></span>
                                                    </label>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </main>

    <script>
        // Tab switching
        function switchTab(tabName) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));

            document.getElementById('tab-' + tabName).classList.add('active');

            // Find and activate the correct tab button
            document.querySelectorAll('.tab-btn').forEach(btn => {
                if (btn.getAttribute('onclick').includes("'" + tabName + "'")) {
                    btn.classList.add('active');
                }
            });

            // Update URL without reload
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            history.replaceState(null, '', url);
        }

        // Sidebar toggle for mobile
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('active');
        }

        // Toggle trading pair field via AJAX
        function togglePairField(pairId, field, checkbox) {
            const formData = new FormData();
            formData.append('ajax_action', 'toggle_pair');
            formData.append('pair_id', pairId);
            formData.append('field', field);

            fetch('settings.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    // Revert checkbox on failure
                    checkbox.checked = !checkbox.checked;
                    alert('<?= $lang === "ar" ? "حدث خطأ" : "An error occurred" ?>');
                }
            })
            .catch(() => {
                checkbox.checked = !checkbox.checked;
                alert('<?= $lang === "ar" ? "خطأ في الاتصال" : "Connection error" ?>');
            });
        }

        // Update pair min/max amounts via AJAX
        let updateTimeout = {};
        function updatePairAmount(input) {
            const pairId = input.dataset.pairId;
            const row = document.getElementById('pair-row-' + pairId);
            const inputs = row.querySelectorAll('.pair-amount-input');
            const minAmount = inputs[0].value;
            const maxAmount = inputs[1].value;

            // Debounce
            clearTimeout(updateTimeout[pairId]);
            updateTimeout[pairId] = setTimeout(() => {
                const formData = new FormData();
                formData.append('ajax_action', 'update_pair');
                formData.append('pair_id', pairId);
                formData.append('min_amount', minAmount);
                formData.append('max_amount', maxAmount);

                fetch('settings.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        input.style.borderColor = 'var(--success)';
                        setTimeout(() => { input.style.borderColor = ''; }, 1500);
                    } else {
                        input.style.borderColor = 'var(--danger)';
                    }
                })
                .catch(() => {
                    input.style.borderColor = 'var(--danger)';
                });
            }, 500);
        }

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(el => {
                el.style.transition = 'opacity 0.3s';
                el.style.opacity = '0';
                setTimeout(() => el.remove(), 300);
            });
        }, 5000);
    </script>
</body>
</html>
