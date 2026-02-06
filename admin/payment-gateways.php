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
$rtl = is_rtl();
$dir = $rtl ? 'rtl' : 'ltr';
$csrf_token = generate_csrf_token();

$success_message = '';
$error_message = '';

// Handle AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        if (in_array($_POST['action'], ['toggle_status', 'delete_gateway'])) {
            json_response(['success' => false, 'message' => t('invalid_csrf', 'Invalid security token')], 403);
        }
        $error_message = t('invalid_csrf', 'Invalid security token');
    } else {

        // Toggle gateway status
        if ($_POST['action'] === 'toggle_status') {
            $gateway_id = intval($_POST['gateway_id'] ?? 0);

            try {
                $stmt = $pdo->prepare("UPDATE payment_gateways SET is_active = NOT is_active WHERE id = ?");
                $stmt->execute([$gateway_id]);

                log_admin_activity($pdo, $admin_id, 'gateway_toggle', "Toggled gateway status #$gateway_id");

                json_response(['success' => true]);
            } catch (PDOException $e) {
                error_log("Toggle gateway error: " . $e->getMessage());
                json_response(['success' => false, 'message' => t('error_occurred', 'An error occurred')], 500);
            }
        }

        // Delete gateway
        if ($_POST['action'] === 'delete_gateway') {
            $gateway_id = intval($_POST['gateway_id'] ?? 0);

            try {
                // Check if gateway has transactions
                $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM transactions WHERE gateway_id = ?");
                $stmt->execute([$gateway_id]);
                $tx_count = $stmt->fetch();

                if ($tx_count && $tx_count['cnt'] > 0) {
                    json_response(['success' => false, 'message' => t('gateway_has_transactions', 'Cannot delete gateway with existing transactions')], 400);
                }

                // Get logo to delete file
                $stmt = $pdo->prepare("SELECT logo FROM payment_gateways WHERE id = ?");
                $stmt->execute([$gateway_id]);
                $gw = $stmt->fetch();

                if ($gw && !empty($gw['logo'])) {
                    $logo_path = '../assets/gateways/' . $gw['logo'];
                    if (file_exists($logo_path)) {
                        unlink($logo_path);
                    }
                }

                $stmt = $pdo->prepare("DELETE FROM payment_gateways WHERE id = ?");
                $stmt->execute([$gateway_id]);

                log_admin_activity($pdo, $admin_id, 'gateway_delete', "Deleted gateway #$gateway_id");

                json_response(['success' => true]);
            } catch (PDOException $e) {
                error_log("Delete gateway error: " . $e->getMessage());
                json_response(['success' => false, 'message' => t('error_occurred', 'An error occurred')], 500);
            }
        }

        // Add gateway
        if ($_POST['action'] === 'add_gateway') {
            $name = sanitize($_POST['name'] ?? '');
            $display_name_en = sanitize($_POST['display_name_en'] ?? '');
            $display_name_ar = sanitize($_POST['display_name_ar'] ?? '');
            $type = sanitize($_POST['type'] ?? 'bank_transfer');
            $icon = sanitize($_POST['icon'] ?? '');
            $account_info = sanitize($_POST['account_info'] ?? '');
            $instructions_en = sanitize($_POST['instructions_en'] ?? '');
            $instructions_ar = sanitize($_POST['instructions_ar'] ?? '');
            $min_deposit = floatval($_POST['min_deposit'] ?? 10);
            $max_deposit = floatval($_POST['max_deposit'] ?? 100000);
            $fee_percentage = floatval($_POST['fee_percentage'] ?? 0);
            $fee_fixed = floatval($_POST['fee_fixed'] ?? 0);
            $sort_order = intval($_POST['sort_order'] ?? 0);
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            // Validate required fields
            if (empty($name) || empty($display_name_en) || empty($display_name_ar)) {
                $error_message = t('fill_required_fields', 'Please fill all required fields');
            } elseif (!in_array($type, ['bank_transfer', 'crypto', 'e_wallet', 'card', 'mobile_wallet', 'p2p'])) {
                $error_message = t('invalid_gateway_type', 'Invalid gateway type');
            } elseif ($min_deposit < 0 || $max_deposit < 0 || $fee_percentage < 0 || $fee_fixed < 0) {
                $error_message = t('invalid_amounts', 'Amounts cannot be negative');
            } elseif ($max_deposit > 0 && $min_deposit > $max_deposit) {
                $error_message = t('min_exceeds_max', 'Minimum deposit cannot exceed maximum deposit');
            } else {
                // Handle logo upload
                $logo_filename = null;
                if (isset($_FILES['logo']) && $_FILES['logo']['error'] === 0) {
                    $allowed = ['jpg', 'jpeg', 'png', 'svg', 'webp'];
                    $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, $allowed) && $_FILES['logo']['size'] < 500000) {
                        $logo_filename = 'gateway_' . time() . '.' . $ext;
                        $upload_dir = '../assets/gateways/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }
                        move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . $logo_filename);
                    } else {
                        $error_message = t('invalid_logo', 'Invalid logo file. Allowed: jpg, png, svg, webp. Max 500KB.');
                    }
                }

                if (empty($error_message)) {
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO payment_gateways
                            (name, display_name_en, display_name_ar, type, icon, logo, account_info,
                             instructions_en, instructions_ar, min_deposit, max_deposit,
                             fee_percentage, fee_fixed, sort_order, is_active)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $name, $display_name_en, $display_name_ar, $type, $icon, $logo_filename,
                            $account_info, $instructions_en, $instructions_ar,
                            $min_deposit, $max_deposit, $fee_percentage, $fee_fixed, $sort_order, $is_active
                        ]);

                        $success_message = t('gateway_added', 'Payment gateway added successfully');
                        log_admin_activity($pdo, $admin_id, 'gateway_add', "Added gateway: $name");
                    } catch (PDOException $e) {
                        error_log("Add gateway error: " . $e->getMessage());
                        if (strpos($e->getMessage(), 'Duplicate') !== false) {
                            $error_message = t('gateway_name_exists', 'A gateway with this name already exists');
                        } else {
                            $error_message = t('error_adding_gateway', 'Error adding payment gateway');
                        }
                    }
                }
            }
        }

        // Edit gateway
        if ($_POST['action'] === 'edit_gateway') {
            $gateway_id = intval($_POST['gateway_id'] ?? 0);
            $name = sanitize($_POST['name'] ?? '');
            $display_name_en = sanitize($_POST['display_name_en'] ?? '');
            $display_name_ar = sanitize($_POST['display_name_ar'] ?? '');
            $type = sanitize($_POST['type'] ?? 'bank_transfer');
            $icon = sanitize($_POST['icon'] ?? '');
            $account_info = sanitize($_POST['account_info'] ?? '');
            $instructions_en = sanitize($_POST['instructions_en'] ?? '');
            $instructions_ar = sanitize($_POST['instructions_ar'] ?? '');
            $min_deposit = floatval($_POST['min_deposit'] ?? 10);
            $max_deposit = floatval($_POST['max_deposit'] ?? 100000);
            $fee_percentage = floatval($_POST['fee_percentage'] ?? 0);
            $fee_fixed = floatval($_POST['fee_fixed'] ?? 0);
            $sort_order = intval($_POST['sort_order'] ?? 0);
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if (empty($name) || empty($display_name_en) || empty($display_name_ar) || $gateway_id <= 0) {
                $error_message = t('fill_required_fields', 'Please fill all required fields');
            } elseif (!in_array($type, ['bank_transfer', 'crypto', 'e_wallet', 'card', 'mobile_wallet', 'p2p'])) {
                $error_message = t('invalid_gateway_type', 'Invalid gateway type');
            } elseif ($min_deposit < 0 || $max_deposit < 0 || $fee_percentage < 0 || $fee_fixed < 0) {
                $error_message = t('invalid_amounts', 'Amounts cannot be negative');
            } elseif ($max_deposit > 0 && $min_deposit > $max_deposit) {
                $error_message = t('min_exceeds_max', 'Minimum deposit cannot exceed maximum deposit');
            } else {
                // Handle logo upload
                $logo_filename = null;
                $old_logo = null;

                if (isset($_FILES['logo']) && $_FILES['logo']['error'] === 0) {
                    $allowed = ['jpg', 'jpeg', 'png', 'svg', 'webp'];
                    $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, $allowed) && $_FILES['logo']['size'] < 500000) {
                        $logo_filename = 'gateway_' . time() . '.' . $ext;
                        $upload_dir = '../assets/gateways/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }
                        move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . $logo_filename);

                        // Get old logo for deletion
                        $stmt = $pdo->prepare("SELECT logo FROM payment_gateways WHERE id = ?");
                        $stmt->execute([$gateway_id]);
                        $old_gw = $stmt->fetch();
                        if ($old_gw && !empty($old_gw['logo'])) {
                            $old_logo = $old_gw['logo'];
                        }
                    } else {
                        $error_message = t('invalid_logo', 'Invalid logo file. Allowed: jpg, png, svg, webp. Max 500KB.');
                    }
                }

                if (empty($error_message)) {
                    try {
                        $sql = "
                            UPDATE payment_gateways SET
                                name = ?, display_name_en = ?, display_name_ar = ?, type = ?, icon = ?,
                                account_info = ?, instructions_en = ?, instructions_ar = ?,
                                min_deposit = ?, max_deposit = ?, fee_percentage = ?, fee_fixed = ?,
                                sort_order = ?, is_active = ?
                        ";
                        $params = [
                            $name, $display_name_en, $display_name_ar, $type, $icon,
                            $account_info, $instructions_en, $instructions_ar,
                            $min_deposit, $max_deposit, $fee_percentage, $fee_fixed, $sort_order, $is_active
                        ];

                        if ($logo_filename !== null) {
                            $sql .= ", logo = ?";
                            $params[] = $logo_filename;
                        }

                        $sql .= " WHERE id = ?";
                        $params[] = $gateway_id;

                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);

                        // Remove old logo file
                        if ($old_logo) {
                            $old_path = '../assets/gateways/' . $old_logo;
                            if (file_exists($old_path)) {
                                unlink($old_path);
                            }
                        }

                        $success_message = t('gateway_updated', 'Payment gateway updated successfully');
                        log_admin_activity($pdo, $admin_id, 'gateway_edit', "Updated gateway #$gateway_id: $name");
                    } catch (PDOException $e) {
                        error_log("Edit gateway error: " . $e->getMessage());
                        if (strpos($e->getMessage(), 'Duplicate') !== false) {
                            $error_message = t('gateway_name_exists', 'A gateway with this name already exists');
                        } else {
                            $error_message = t('error_updating_gateway', 'Error updating payment gateway');
                        }
                    }
                }
            }
        }
    }

    // Exit for AJAX actions
    if (in_array($_POST['action'] ?? '', ['toggle_status', 'delete_gateway'])) {
        exit;
    }
}

// Get all gateways with transaction stats
try {
    $stmt = $pdo->query("
        SELECT
            pg.*,
            COUNT(t.id) as transaction_count,
            SUM(CASE WHEN t.status = 'completed' THEN t.amount ELSE 0 END) as total_volume,
            COUNT(CASE WHEN t.status = 'pending' THEN 1 END) as pending_count
        FROM payment_gateways pg
        LEFT JOIN transactions t ON pg.id = t.gateway_id
        GROUP BY pg.id
        ORDER BY pg.sort_order ASC, pg.is_active DESC, pg.name ASC
    ");
    $gateways = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Get gateways error: " . $e->getMessage());
    $gateways = [];
}

// Gateway stats
$total_gateways = count($gateways);
$active_gateways = 0;
$total_volume = 0;
$total_transactions = 0;
foreach ($gateways as $gw) {
    if ($gw['is_active']) $active_gateways++;
    $total_volume += floatval($gw['total_volume'] ?? 0);
    $total_transactions += intval($gw['transaction_count'] ?? 0);
}

// Gateway type labels
$type_labels = [
    'bank_transfer' => ['en' => 'Bank Transfer', 'ar' => 'تحويل بنكي'],
    'crypto'        => ['en' => 'Cryptocurrency', 'ar' => 'عملة رقمية'],
    'e_wallet'      => ['en' => 'E-Wallet', 'ar' => 'محفظة إلكترونية'],
    'card'          => ['en' => 'Card', 'ar' => 'بطاقة'],
    'mobile_wallet' => ['en' => 'Mobile Wallet', 'ar' => 'محفظة موبايل'],
    'p2p'           => ['en' => 'P2P', 'ar' => 'نظير إلى نظير'],
];

$type_colors = [
    'bank_transfer' => '#3B82F6',
    'crypto'        => '#F59E0B',
    'e_wallet'      => '#8B5CF6',
    'card'          => '#10B981',
    'mobile_wallet' => '#EC4899',
    'p2p'           => '#06B6D4',
];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('payment_gateways', 'Payment Gateways') ?> - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;900&display=swap" rel="stylesheet">
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
            font-family: 'Tajawal', sans-serif;
            background: var(--primary);
            color: var(--text);
            line-height: 1.6;
        }

        /* ============ Sidebar ============ */
        .sidebar {
            position: fixed;
            <?= $rtl ? 'right' : 'left' ?>: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: var(--secondary);
            border-<?= $rtl ? 'left' : 'right' ?>: 1px solid var(--border);
            z-index: 1000;
            overflow-y: auto;
            transition: transform 0.3s ease;
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
            min-height: 44px;
        }

        .nav-item:hover,
        .nav-item.active {
            color: var(--text);
            background: rgba(220, 38, 38, 0.05);
        }

        .nav-item.active::before {
            content: '';
            position: absolute;
            <?= $rtl ? 'right' : 'left' ?>: 0;
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
            margin-<?= $rtl ? 'right' : 'left' ?>: auto;
            background: var(--danger);
            color: white;
            font-size: 10px;
            padding: 3px 8px;
            border-radius: 10px;
            font-weight: 700;
        }

        .lang-switcher {
            display: flex;
            gap: 8px;
            padding: 10px 20px;
        }

        .lang-btn {
            flex: 1;
            padding: 8px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: transparent;
            color: var(--text-muted);
            font-family: 'Tajawal', sans-serif;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            min-height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }

        .lang-btn.active {
            background: linear-gradient(135deg, #DC2626, var(--accent));
            color: white;
            border-color: transparent;
        }

        .lang-btn:hover:not(.active) {
            border-color: var(--accent);
            color: var(--text);
        }

        /* ============ Main Content ============ */
        .main {
            margin-<?= $rtl ? 'right' : 'left' ?>: 280px;
            padding: 30px;
            min-height: 100vh;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 32px;
            flex-wrap: wrap;
            gap: 16px;
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

        /* Mobile hamburger */
        .mobile-menu-btn {
            display: none;
            width: 44px;
            height: 44px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--secondary);
            color: var(--text);
            font-size: 20px;
            cursor: pointer;
            align-items: center;
            justify-content: center;
        }

        /* ============ Alerts ============ */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        /* ============ Stats Grid ============ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 16px;
        }

        .stat-label {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 4px;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 900;
        }

        /* ============ Buttons ============ */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            font-family: 'Tajawal', sans-serif;
            min-height: 44px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #DC2626, var(--accent));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(220, 38, 38, 0.3);
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

        .btn-outline {
            background: transparent;
            color: var(--text-muted);
            border: 1px solid var(--border);
        }

        .btn-outline:hover {
            border-color: var(--accent);
            color: var(--accent);
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
            min-height: 36px;
        }

        /* ============ Gateway Cards ============ */
        .gateways-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .gateways-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .gateway-card {
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            transition: all 0.3s;
        }

        .gateway-card:hover {
            border-color: rgba(245, 158, 11, 0.3);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
        }

        .gateway-card.inactive {
            opacity: 0.6;
        }

        .gateway-card-header {
            padding: 24px 24px 0;
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }

        .gateway-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
        }

        .gateway-icon img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 14px;
        }

        .gateway-info {
            flex: 1;
            min-width: 0;
        }

        .gateway-name {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .gateway-name-sub {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .type-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .gateway-card-body {
            padding: 20px 24px;
        }

        .gateway-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }

        .detail-item {
            background: rgba(15, 23, 42, 0.5);
            padding: 12px;
            border-radius: 10px;
        }

        .detail-label {
            font-size: 11px;
            color: var(--text-muted);
            margin-bottom: 4px;
            text-transform: uppercase;
            font-weight: 600;
        }

        .detail-value {
            font-size: 15px;
            font-weight: 700;
        }

        .gateway-stats {
            display: flex;
            gap: 16px;
            padding: 16px 0;
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
            margin-bottom: 16px;
        }

        .gateway-stat {
            flex: 1;
            text-align: center;
        }

        .gateway-stat-value {
            font-size: 16px;
            font-weight: 700;
        }

        .gateway-stat-label {
            font-size: 11px;
            color: var(--text-muted);
        }

        .gateway-card-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        /* Toggle Switch */
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
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(148, 163, 184, 0.2);
            transition: 0.3s;
            border-radius: 26px;
        }

        .toggle-slider::before {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: white;
            top: 3px;
            <?= $rtl ? 'right' : 'left' ?>: 3px;
            transition: 0.3s;
        }

        .toggle-switch input:checked + .toggle-slider {
            background: var(--success);
        }

        .toggle-switch input:checked + .toggle-slider::before {
            transform: translateX(<?= $rtl ? '-22px' : '22px' ?>);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        /* ============ Modal ============ */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 2000;
            align-items: flex-start;
            justify-content: center;
            overflow-y: auto;
            padding: 40px 16px;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-content {
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 32px;
            max-width: 700px;
            width: 100%;
            position: relative;
        }

        .modal-close {
            position: absolute;
            top: 16px;
            <?= $rtl ? 'left' : 'right' ?>: 16px;
            width: 36px;
            height: 36px;
            border: none;
            border-radius: 8px;
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }

        .modal-close:hover {
            background: var(--danger);
            color: white;
        }

        .modal-title {
            font-size: 24px;
            font-weight: 900;
            margin-bottom: 24px;
            padding-<?= $rtl ? 'left' : 'right' ?>: 40px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .form-label .required {
            color: var(--danger);
        }

        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 12px 16px;
            background: var(--primary);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            font-size: 14px;
            font-family: 'Tajawal', sans-serif;
            transition: border-color 0.3s;
            min-height: 44px;
        }

        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: var(--accent);
        }

        .form-textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%2394A3B8' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: <?= $rtl ? '16px' : 'calc(100% - 16px)' ?> center;
            padding-<?= $rtl ? 'left' : 'right' ?>: 36px;
        }

        .form-file {
            width: 100%;
            padding: 10px 16px;
            background: var(--primary);
            border: 2px dashed var(--border);
            border-radius: 10px;
            color: var(--text-muted);
            font-size: 14px;
            font-family: 'Tajawal', sans-serif;
            cursor: pointer;
            min-height: 44px;
        }

        .form-file:hover {
            border-color: var(--accent);
        }

        .form-hint {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
        }

        .form-check input[type="checkbox"] {
            width: 20px;
            height: 20px;
            accent-color: var(--accent);
            cursor: pointer;
        }

        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            padding-top: 16px;
            border-top: 1px solid var(--border);
        }

        /* ============ Empty State ============ */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 64px;
            opacity: 0.3;
            margin-bottom: 16px;
        }

        .empty-state p {
            font-size: 16px;
        }

        /* ============ Confirm Dialog ============ */
        .confirm-dialog {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 3000;
            align-items: center;
            justify-content: center;
        }

        .confirm-dialog.active {
            display: flex;
        }

        .confirm-box {
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 32px;
            max-width: 400px;
            width: 90%;
            text-align: center;
        }

        .confirm-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 16px;
            border-radius: 50%;
            background: rgba(239, 68, 68, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: var(--danger);
        }

        .confirm-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .confirm-text {
            color: var(--text-muted);
            margin-bottom: 24px;
            font-size: 14px;
        }

        .confirm-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }

        /* ============ Responsive ============ */
        @media (max-width: 1024px) {
            .gateways-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(<?= $rtl ? '100%' : '-100%' ?>);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .mobile-menu-btn {
                display: flex;
            }

            .main {
                margin-<?= $rtl ? 'right' : 'left' ?>: 0;
                padding: 20px;
            }

            .page-title {
                font-size: 24px;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .modal-content {
                padding: 24px;
            }

            .gateway-details {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .gateways-header {
                flex-direction: column;
                align-items: stretch;
            }
        }

        /* Sidebar overlay for mobile */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }

        .sidebar-overlay.active {
            display: block;
        }
    </style>
</head>
<body>
    <!-- Sidebar Overlay (mobile) -->
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
            <div class="nav-section-title"><?= t('admin_management', 'Management') ?></div>
            <a href="index.php" class="nav-item">
                <i class="fas fa-chart-pie"></i>
                <span><?= t('admin_dashboard', 'Dashboard') ?></span>
            </a>
            <a href="users.php" class="nav-item">
                <i class="fas fa-users"></i>
                <span><?= t('admin_users', 'Users') ?></span>
            </a>
            <a href="payments.php" class="nav-item">
                <i class="fas fa-credit-card"></i>
                <span><?= t('admin_payments', 'Payments') ?></span>
            </a>
            <a href="trades.php" class="nav-item">
                <i class="fas fa-exchange-alt"></i>
                <span><?= t('admin_trades', 'Trades') ?></span>
            </a>
            <a href="payment-gateways.php" class="nav-item active">
                <i class="fas fa-gateway"></i>
                <i class="fas fa-money-check-alt"></i>
                <span><?= t('admin_payment_gateways', 'Payment Gateways') ?></span>
            </a>
            <a href="reports.php" class="nav-item">
                <i class="fas fa-chart-bar"></i>
                <span><?= t('admin_reports', 'Reports') ?></span>
            </a>
            <a href="settings.php" class="nav-item">
                <i class="fas fa-cog"></i>
                <span><?= t('admin_settings', 'Settings') ?></span>
            </a>

            <div class="nav-section-title"><?= t('admin_other', 'Other') ?></div>
            <a href="../dashboard.php" class="nav-item">
                <i class="fas fa-arrow-<?= $rtl ? 'right' : 'left' ?>"></i>
                <span><?= t('back_to_platform', 'Back to Platform') ?></span>
            </a>
            <a href="../logout.php" class="nav-item" style="color: var(--danger);">
                <i class="fas fa-sign-out-alt"></i>
                <span><?= t('logout', 'Logout') ?></span>
            </a>
        </nav>

        <div class="lang-switcher">
            <a href="?lang=ar" class="lang-btn <?= $lang === 'ar' ? 'active' : '' ?>">العربية</a>
            <a href="?lang=en" class="lang-btn <?= $lang === 'en' ? 'active' : '' ?>">English</a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main">
        <!-- Header -->
        <div class="header">
            <div>
                <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 8px;">
                    <button class="mobile-menu-btn" onclick="toggleSidebar()">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h1 class="page-title"><?= t('payment_gateways', 'Payment Gateways') ?></h1>
                </div>
                <div class="breadcrumb">
                    <a href="index.php"><?= t('admin_dashboard', 'Dashboard') ?></a>
                    <i class="fas fa-chevron-<?= $rtl ? 'left' : 'right' ?>" style="font-size: 10px;"></i>
                    <span><?= t('payment_gateways', 'Payment Gateways') ?></span>
                </div>
            </div>
            <button class="btn btn-primary" onclick="openAddModal()">
                <i class="fas fa-plus"></i>
                <?= t('add_gateway', 'Add Gateway') ?>
            </button>
        </div>

        <!-- Alerts -->
        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?= htmlspecialchars($success_message) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($error_message) ?></span>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(59, 130, 246, 0.1); color: var(--info);">
                    <i class="fas fa-money-check-alt"></i>
                </div>
                <div class="stat-label"><?= t('total_gateways', 'Total Gateways') ?></div>
                <div class="stat-value"><?= $total_gateways ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--success);">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-label"><?= t('active_gateways', 'Active Gateways') ?></div>
                <div class="stat-value" style="color: var(--success);"><?= $active_gateways ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); color: var(--accent);">
                    <i class="fas fa-receipt"></i>
                </div>
                <div class="stat-label"><?= t('total_transactions', 'Total Transactions') ?></div>
                <div class="stat-value"><?= number_format($total_transactions) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(139, 92, 246, 0.1); color: var(--purple);">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="stat-label"><?= t('total_volume', 'Total Volume') ?></div>
                <div class="stat-value">$<?= number_format($total_volume, 2) ?></div>
            </div>
        </div>

        <!-- Gateways Grid -->
        <div class="gateways-header">
            <h2 style="font-size: 20px; font-weight: 700;"><?= t('all_gateways', 'All Gateways') ?> (<?= $total_gateways ?>)</h2>
        </div>

        <?php if (empty($gateways)): ?>
            <div class="empty-state">
                <i class="fas fa-money-check-alt"></i>
                <p><?= t('no_gateways', 'No payment gateways found. Click "Add Gateway" to create one.') ?></p>
            </div>
        <?php else: ?>
            <div class="gateways-grid">
                <?php foreach ($gateways as $gateway): ?>
                    <?php
                        $gw_type = $gateway['type'] ?? 'bank_transfer';
                        $gw_color = $type_colors[$gw_type] ?? '#94A3B8';
                        $gw_type_label = $type_labels[$gw_type][$lang] ?? $gw_type;
                        $display_name = $lang === 'ar'
                            ? ($gateway['display_name_ar'] ?: $gateway['display_name_en'])
                            : ($gateway['display_name_en'] ?: $gateway['display_name_ar']);
                        $other_name = $lang === 'ar'
                            ? $gateway['display_name_en']
                            : $gateway['display_name_ar'];
                    ?>
                    <div class="gateway-card <?= $gateway['is_active'] ? '' : 'inactive' ?>" id="gateway-<?= $gateway['id'] ?>">
                        <div class="gateway-card-header">
                            <div class="gateway-icon" style="background: <?= $gw_color ?>20;">
                                <?php if (!empty($gateway['logo'])): ?>
                                    <img src="../assets/gateways/<?= htmlspecialchars($gateway['logo']) ?>" alt="<?= htmlspecialchars($display_name) ?>">
                                <?php elseif (!empty($gateway['icon'])): ?>
                                    <?php if (strpos($gateway['icon'], 'fa') === 0): ?>
                                        <i class="<?= htmlspecialchars($gateway['icon']) ?>" style="color: <?= $gw_color ?>;"></i>
                                    <?php else: ?>
                                        <span style="font-size: 28px;"><?= htmlspecialchars($gateway['icon']) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <i class="fas fa-university" style="color: <?= $gw_color ?>;"></i>
                                <?php endif; ?>
                            </div>
                            <div class="gateway-info">
                                <div class="gateway-name"><?= htmlspecialchars($display_name) ?></div>
                                <div class="gateway-name-sub"><?= htmlspecialchars($other_name) ?></div>
                                <span class="type-badge" style="background: <?= $gw_color ?>15; color: <?= $gw_color ?>;">
                                    <?= htmlspecialchars($gw_type_label) ?>
                                </span>
                            </div>
                        </div>
                        <div class="gateway-card-body">
                            <div class="gateway-details">
                                <div class="detail-item">
                                    <div class="detail-label"><?= t('min_deposit', 'Min Deposit') ?></div>
                                    <div class="detail-value" style="color: var(--success);">$<?= number_format($gateway['min_deposit'], 2) ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label"><?= t('max_deposit', 'Max Deposit') ?></div>
                                    <div class="detail-value" style="color: var(--info);">$<?= number_format($gateway['max_deposit'], 2) ?></div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label"><?= t('fee_percentage', 'Fee %') ?></div>
                                    <div class="detail-value" style="color: var(--warning);"><?= number_format($gateway['fee_percentage'], 2) ?>%</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label"><?= t('fee_fixed', 'Fixed Fee') ?></div>
                                    <div class="detail-value">$<?= number_format($gateway['fee_fixed'], 2) ?></div>
                                </div>
                            </div>

                            <div class="gateway-stats">
                                <div class="gateway-stat">
                                    <div class="gateway-stat-value"><?= number_format($gateway['transaction_count'] ?? 0) ?></div>
                                    <div class="gateway-stat-label"><?= t('transactions', 'Transactions') ?></div>
                                </div>
                                <div class="gateway-stat">
                                    <div class="gateway-stat-value" style="color: var(--success);">$<?= number_format($gateway['total_volume'] ?? 0, 2) ?></div>
                                    <div class="gateway-stat-label"><?= t('volume', 'Volume') ?></div>
                                </div>
                                <div class="gateway-stat">
                                    <div class="gateway-stat-value" style="color: var(--warning);"><?= number_format($gateway['pending_count'] ?? 0) ?></div>
                                    <div class="gateway-stat-label"><?= t('pending', 'Pending') ?></div>
                                </div>
                            </div>

                            <div class="gateway-card-footer">
                                <label class="toggle-switch" title="<?= t('toggle_status', 'Toggle Status') ?>">
                                    <input type="checkbox"
                                           <?= $gateway['is_active'] ? 'checked' : '' ?>
                                           onchange="toggleGateway(<?= $gateway['id'] ?>, this)">
                                    <span class="toggle-slider"></span>
                                </label>
                                <div class="action-buttons">
                                    <button class="btn btn-outline btn-sm" onclick="openEditModal(<?= htmlspecialchars(json_encode($gateway, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>)" title="<?= t('edit', 'Edit') ?>">
                                        <i class="fas fa-edit"></i>
                                        <?= t('edit', 'Edit') ?>
                                    </button>
                                    <button class="btn btn-danger btn-sm" onclick="confirmDelete(<?= $gateway['id'] ?>, '<?= htmlspecialchars($display_name, ENT_QUOTES) ?>')" title="<?= t('delete', 'Delete') ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <!-- Add/Edit Gateway Modal -->
    <div class="modal-overlay" id="gatewayModal">
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal()">
                <i class="fas fa-times"></i>
            </button>
            <h2 class="modal-title" id="modalTitle"><?= t('add_gateway', 'Add Gateway') ?></h2>
            <form method="POST" enctype="multipart/form-data" id="gatewayForm">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" id="formAction" value="add_gateway">
                <input type="hidden" name="gateway_id" id="formGatewayId" value="">

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('gateway_name_key', 'Gateway Name (Key)') ?> <span class="required">*</span></label>
                        <input type="text" name="name" id="fName" class="form-input" required
                               placeholder="e.g. bank_transfer" pattern="[a-zA-Z0-9_]+" title="Only letters, numbers and underscores">
                        <div class="form-hint"><?= t('gateway_name_hint', 'Unique identifier. Letters, numbers, underscores only.') ?></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('gateway_type', 'Type') ?> <span class="required">*</span></label>
                        <select name="type" id="fType" class="form-select" required>
                            <option value="bank_transfer"><?= $type_labels['bank_transfer'][$lang] ?></option>
                            <option value="crypto"><?= $type_labels['crypto'][$lang] ?></option>
                            <option value="e_wallet"><?= $type_labels['e_wallet'][$lang] ?></option>
                            <option value="card"><?= $type_labels['card'][$lang] ?></option>
                            <option value="mobile_wallet"><?= $type_labels['mobile_wallet'][$lang] ?></option>
                            <option value="p2p"><?= $type_labels['p2p'][$lang] ?></option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('display_name_en', 'Display Name (English)') ?> <span class="required">*</span></label>
                        <input type="text" name="display_name_en" id="fDisplayNameEn" class="form-input" required placeholder="Bank Transfer">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('display_name_ar', 'Display Name (Arabic)') ?> <span class="required">*</span></label>
                        <input type="text" name="display_name_ar" id="fDisplayNameAr" class="form-input" required placeholder="تحويل بنكي" dir="rtl">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('icon', 'Icon') ?></label>
                        <input type="text" name="icon" id="fIcon" class="form-input" placeholder="fas fa-university or emoji">
                        <div class="form-hint"><?= t('icon_hint', 'FontAwesome class or emoji character') ?></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('logo', 'Logo') ?></label>
                        <input type="file" name="logo" id="fLogo" class="form-file" accept=".jpg,.jpeg,.png,.svg,.webp">
                        <div class="form-hint"><?= t('logo_hint', 'JPG, PNG, SVG, WebP. Max 500KB.') ?></div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><?= t('account_info', 'Account Info') ?></label>
                    <textarea name="account_info" id="fAccountInfo" class="form-textarea" rows="3" placeholder="<?= t('account_info_placeholder', 'Bank name, account number, IBAN, etc.') ?>"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('instructions_en', 'Instructions (English)') ?></label>
                        <textarea name="instructions_en" id="fInstructionsEn" class="form-textarea" rows="3" placeholder="Deposit instructions in English..."></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('instructions_ar', 'Instructions (Arabic)') ?></label>
                        <textarea name="instructions_ar" id="fInstructionsAr" class="form-textarea" rows="3" placeholder="تعليمات الإيداع بالعربية..." dir="rtl"></textarea>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('min_deposit', 'Min Deposit') ?> ($)</label>
                        <input type="number" name="min_deposit" id="fMinDeposit" class="form-input" step="0.01" min="0" value="10">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('max_deposit', 'Max Deposit') ?> ($)</label>
                        <input type="number" name="max_deposit" id="fMaxDeposit" class="form-input" step="0.01" min="0" value="100000">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('fee_percentage', 'Fee %') ?></label>
                        <input type="number" name="fee_percentage" id="fFeePercentage" class="form-input" step="0.01" min="0" max="100" value="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('fee_fixed', 'Fixed Fee') ?> ($)</label>
                        <input type="number" name="fee_fixed" id="fFeeFixed" class="form-input" step="0.01" min="0" value="0">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('sort_order', 'Sort Order') ?></label>
                        <input type="number" name="sort_order" id="fSortOrder" class="form-input" min="0" value="0">
                    </div>
                    <div class="form-group" style="display: flex; align-items: flex-end; padding-bottom: 4px;">
                        <label class="form-check">
                            <input type="checkbox" name="is_active" id="fIsActive" checked>
                            <span style="font-weight: 600;"><?= t('active', 'Active') ?></span>
                        </label>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="closeModal()"><?= t('cancel', 'Cancel') ?></button>
                    <button type="submit" class="btn btn-primary" id="formSubmitBtn">
                        <i class="fas fa-save"></i>
                        <span id="formSubmitText"><?= t('add_gateway', 'Add Gateway') ?></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Dialog -->
    <div class="confirm-dialog" id="deleteDialog">
        <div class="confirm-box">
            <div class="confirm-icon">
                <i class="fas fa-trash-alt"></i>
            </div>
            <div class="confirm-title"><?= t('confirm_delete', 'Delete Gateway?') ?></div>
            <div class="confirm-text" id="deleteConfirmText">
                <?= t('delete_gateway_confirm', 'Are you sure you want to delete this payment gateway? This action cannot be undone.') ?>
            </div>
            <div class="confirm-actions">
                <button class="btn btn-outline" onclick="closeDeleteDialog()"><?= t('cancel', 'Cancel') ?></button>
                <button class="btn btn-danger" id="confirmDeleteBtn" onclick="executeDelete()">
                    <i class="fas fa-trash"></i>
                    <?= t('delete', 'Delete') ?>
                </button>
            </div>
        </div>
    </div>

    <script>
        const csrfToken = '<?= $csrf_token ?>';
        let deleteGatewayId = null;

        // Sidebar toggle
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('active');
        }

        // Modal functions
        function openAddModal() {
            document.getElementById('modalTitle').textContent = '<?= t('add_gateway', 'Add Gateway') ?>';
            document.getElementById('formAction').value = 'add_gateway';
            document.getElementById('formGatewayId').value = '';
            document.getElementById('formSubmitText').textContent = '<?= t('add_gateway', 'Add Gateway') ?>';
            document.getElementById('gatewayForm').reset();
            document.getElementById('fIsActive').checked = true;
            document.getElementById('fMinDeposit').value = '10';
            document.getElementById('fMaxDeposit').value = '100000';
            document.getElementById('fFeePercentage').value = '0';
            document.getElementById('fFeeFixed').value = '0';
            document.getElementById('fSortOrder').value = '0';
            document.getElementById('gatewayModal').classList.add('active');
        }

        function openEditModal(gateway) {
            document.getElementById('modalTitle').textContent = '<?= t('edit_gateway', 'Edit Gateway') ?>';
            document.getElementById('formAction').value = 'edit_gateway';
            document.getElementById('formGatewayId').value = gateway.id;
            document.getElementById('formSubmitText').textContent = '<?= t('save_changes', 'Save Changes') ?>';

            document.getElementById('fName').value = gateway.name || '';
            document.getElementById('fType').value = gateway.type || 'bank_transfer';
            document.getElementById('fDisplayNameEn').value = gateway.display_name_en || '';
            document.getElementById('fDisplayNameAr').value = gateway.display_name_ar || '';
            document.getElementById('fIcon').value = gateway.icon || '';
            document.getElementById('fAccountInfo').value = gateway.account_info || '';
            document.getElementById('fInstructionsEn').value = gateway.instructions_en || '';
            document.getElementById('fInstructionsAr').value = gateway.instructions_ar || '';
            document.getElementById('fMinDeposit').value = gateway.min_deposit || 10;
            document.getElementById('fMaxDeposit').value = gateway.max_deposit || 100000;
            document.getElementById('fFeePercentage').value = gateway.fee_percentage || 0;
            document.getElementById('fFeeFixed').value = gateway.fee_fixed || 0;
            document.getElementById('fSortOrder').value = gateway.sort_order || 0;
            document.getElementById('fIsActive').checked = gateway.is_active == 1;
            document.getElementById('fLogo').value = '';

            document.getElementById('gatewayModal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('gatewayModal').classList.remove('active');
        }

        // Toggle gateway status
        function toggleGateway(gatewayId, checkbox) {
            fetch('payment-gateways.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=toggle_status&gateway_id=${gatewayId}&csrf_token=${encodeURIComponent(csrfToken)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const card = document.getElementById('gateway-' + gatewayId);
                    if (card) {
                        card.classList.toggle('inactive');
                    }
                } else {
                    checkbox.checked = !checkbox.checked;
                    alert(data.message || '<?= t('error_occurred', 'An error occurred') ?>');
                }
            })
            .catch(() => {
                checkbox.checked = !checkbox.checked;
                alert('<?= t('error_occurred', 'An error occurred') ?>');
            });
        }

        // Delete gateway
        function confirmDelete(gatewayId, gatewayName) {
            deleteGatewayId = gatewayId;
            document.getElementById('deleteConfirmText').textContent =
                '<?= t('delete_gateway_confirm_name', 'Are you sure you want to delete') ?> "' + gatewayName + '"? <?= t('action_cannot_be_undone', 'This action cannot be undone.') ?>';
            document.getElementById('deleteDialog').classList.add('active');
        }

        function closeDeleteDialog() {
            document.getElementById('deleteDialog').classList.remove('active');
            deleteGatewayId = null;
        }

        function executeDelete() {
            if (!deleteGatewayId) return;

            const btn = document.getElementById('confirmDeleteBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <?= t('deleting', 'Deleting...') ?>';

            fetch('payment-gateways.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=delete_gateway&gateway_id=${deleteGatewayId}&csrf_token=${encodeURIComponent(csrfToken)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || '<?= t('error_occurred', 'An error occurred') ?>');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-trash"></i> <?= t('delete', 'Delete') ?>';
                }
            })
            .catch(() => {
                alert('<?= t('error_occurred', 'An error occurred') ?>');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-trash"></i> <?= t('delete', 'Delete') ?>';
            });
        }

        // Close modals on overlay click
        document.getElementById('gatewayModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        document.getElementById('deleteDialog').addEventListener('click', function(e) {
            if (e.target === this) closeDeleteDialog();
        });

        // Close modals on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeDeleteDialog();
            }
        });

        // Language switcher
        document.querySelectorAll('.lang-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const lang = this.href.split('lang=')[1];
                fetch('../change_language.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'lang=' + lang
                }).then(() => {
                    location.reload();
                }).catch(() => {
                    // Fallback: set cookie and reload
                    document.cookie = 'lang=' + lang + ';path=/;max-age=31536000';
                    location.reload();
                });
            });
        });
    </script>
</body>
</html>
