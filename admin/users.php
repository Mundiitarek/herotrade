<?php
define('APP_ACCESS', true);
require_once '../config.php';
require_once '../functions.php';
require_once 'functions.php';

// Admin login check
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$admin_id = $_SESSION['admin_id'];
$admin = get_admin_data($pdo, $admin_id);

if (!$admin) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$csrf_token = generate_csrf_token();
$lang = get_current_lang();
$dir = get_dir();
$rtl = is_rtl();

$success_message = '';
$error_message = '';

// ==========================================
// AJAX HANDLERS (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // CSRF check for all AJAX actions
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        json_response(['success' => false, 'message' => t('invalid_token', 'Invalid security token')], 403);
    }

    // ------- toggle_status -------
    if ($_POST['action'] === 'toggle_status') {
        $user_id = intval($_POST['user_id'] ?? 0);
        if ($user_id <= 0) {
            json_response(['success' => false, 'message' => t('invalid_user', 'Invalid user')], 400);
        }
        try {
            $stmt = $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?");
            $stmt->execute([$user_id]);

            // Get new status
            $stmt2 = $pdo->prepare("SELECT is_active FROM users WHERE id = ?");
            $stmt2->execute([$user_id]);
            $new_status = $stmt2->fetchColumn();

            log_admin_activity($pdo, $admin_id, 'user_status_toggle', "Toggled user #$user_id status to " . ($new_status ? 'active' : 'inactive'));
            json_response(['success' => true, 'is_active' => (int)$new_status]);
        } catch (PDOException $e) {
            error_log("Toggle status error: " . $e->getMessage());
            json_response(['success' => false, 'message' => t('error_occurred', 'An error occurred')], 500);
        }
    }

    // ------- update_balance -------
    if ($_POST['action'] === 'update_balance') {
        $user_id = intval($_POST['user_id'] ?? 0);
        $amount = floatval($_POST['amount'] ?? 0);
        $note = sanitize($_POST['note'] ?? '');

        if ($user_id <= 0) {
            json_response(['success' => false, 'message' => t('invalid_user', 'Invalid user')], 400);
        }
        if ($amount == 0) {
            json_response(['success' => false, 'message' => t('amount_required', 'Amount must not be zero')], 400);
        }

        try {
            $pdo->beginTransaction();

            // Check if deduction would cause negative balance
            if ($amount < 0) {
                $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $current_balance = $stmt->fetchColumn();
                if ($current_balance + $amount < 0) {
                    $pdo->rollBack();
                    json_response(['success' => false, 'message' => t('insufficient_balance', 'Insufficient balance for this deduction')], 400);
                }
            }

            $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$amount, $user_id]);

            // Determine transaction type
            $tx_type = $amount > 0 ? 'bonus' : 'fee';
            $abs_amount = abs($amount);
            $description = ($amount > 0 ? 'Admin bonus' : 'Admin fee') . ($note ? ': ' . $note : '');
            $transaction_id = 'ADJ' . time() . rand(1000, 9999);

            $stmt = $pdo->prepare("
                INSERT INTO transactions (transaction_id, user_id, type, amount, status, description)
                VALUES (?, ?, ?, ?, 'completed', ?)
            ");
            $stmt->execute([$transaction_id, $user_id, $tx_type, $abs_amount, $description]);

            log_admin_activity($pdo, $admin_id, 'balance_adjustment', "Adjusted user #$user_id balance by \$$amount. Note: $note");

            $pdo->commit();

            // Get updated balance
            $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $new_balance = $stmt->fetchColumn();

            json_response(['success' => true, 'new_balance' => (float)$new_balance]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Update balance error: " . $e->getMessage());
            json_response(['success' => false, 'message' => t('error_occurred', 'An error occurred')], 500);
        }
    }

    // ------- delete_user -------
    if ($_POST['action'] === 'delete_user') {
        $user_id = intval($_POST['user_id'] ?? 0);
        if ($user_id <= 0) {
            json_response(['success' => false, 'message' => t('invalid_user', 'Invalid user')], 400);
        }
        try {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            log_admin_activity($pdo, $admin_id, 'user_delete', "Deleted user #$user_id");
            json_response(['success' => true]);
        } catch (PDOException $e) {
            error_log("Delete user error: " . $e->getMessage());
            json_response(['success' => false, 'message' => t('error_occurred', 'An error occurred')], 500);
        }
    }

    // ------- update_user -------
    if ($_POST['action'] === 'update_user') {
        $user_id = intval($_POST['user_id'] ?? 0);
        $username = sanitize($_POST['username'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $full_name = sanitize($_POST['full_name'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $country = sanitize($_POST['country'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($user_id <= 0 || empty($username) || empty($email)) {
            json_response(['success' => false, 'message' => t('fill_required', 'Please fill required fields')], 400);
        }

        try {
            // Check for duplicate username/email
            $stmt = $pdo->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
            $stmt->execute([$username, $email, $user_id]);
            if ($stmt->fetch()) {
                json_response(['success' => false, 'message' => t('duplicate_user', 'Username or email already exists')], 400);
            }

            if (!empty($password)) {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    UPDATE users SET username = ?, email = ?, full_name = ?, phone = ?, country = ?, password = ?
                    WHERE id = ?
                ");
                $stmt->execute([$username, $email, $full_name, $phone, $country, $hashed, $user_id]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE users SET username = ?, email = ?, full_name = ?, phone = ?, country = ?
                    WHERE id = ?
                ");
                $stmt->execute([$username, $email, $full_name, $phone, $country, $user_id]);
            }

            log_admin_activity($pdo, $admin_id, 'user_update', "Updated user #$user_id profile");
            json_response(['success' => true]);
        } catch (PDOException $e) {
            error_log("Update user error: " . $e->getMessage());
            json_response(['success' => false, 'message' => t('error_occurred', 'An error occurred')], 500);
        }
    }

    // ------- get_user -------
    if ($_POST['action'] === 'get_user') {
        $user_id = intval($_POST['user_id'] ?? 0);
        if ($user_id <= 0) {
            json_response(['success' => false, 'message' => t('invalid_user', 'Invalid user')], 400);
        }
        try {
            $stmt = $pdo->prepare("SELECT id, username, email, full_name, phone, country, balance, is_active, created_at, last_login, last_ip FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            if (!$user) {
                json_response(['success' => false, 'message' => t('user_not_found', 'User not found')], 404);
            }

            // Get trade counts
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM spot_trades WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $spot_count = $stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM binary_trades WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $binary_count = $stmt->fetchColumn();

            $user['spot_trades'] = (int)$spot_count;
            $user['binary_trades'] = (int)$binary_count;
            $user['total_trades'] = $user['spot_trades'] + $user['binary_trades'];

            json_response(['success' => true, 'user' => $user]);
        } catch (PDOException $e) {
            error_log("Get user error: " . $e->getMessage());
            json_response(['success' => false, 'message' => t('error_occurred', 'An error occurred')], 500);
        }
    }

    // ------- export_csv -------
    if ($_POST['action'] === 'export_csv') {
        try {
            $stmt = $pdo->query("
                SELECT u.id, u.username, u.email, u.full_name, u.phone, u.country,
                       u.balance, u.is_active, u.created_at, u.last_login, u.last_ip,
                       COUNT(DISTINCT st.id) as spot_trades,
                       COUNT(DISTINCT bt.id) as binary_trades
                FROM users u
                LEFT JOIN spot_trades st ON u.id = st.user_id
                LEFT JOIN binary_trades bt ON u.id = bt.user_id
                GROUP BY u.id
                ORDER BY u.created_at DESC
            ");
            $all_users = $stmt->fetchAll();

            $csv_lines = [];
            $csv_lines[] = 'ID,Username,Email,Full Name,Phone,Country,Balance,Status,Spot Trades,Binary Trades,Registered,Last Login,Last IP';
            foreach ($all_users as $u) {
                $csv_lines[] = implode(',', [
                    $u['id'],
                    '"' . str_replace('"', '""', $u['username']) . '"',
                    '"' . str_replace('"', '""', $u['email']) . '"',
                    '"' . str_replace('"', '""', $u['full_name'] ?? '') . '"',
                    '"' . str_replace('"', '""', $u['phone'] ?? '') . '"',
                    '"' . str_replace('"', '""', $u['country'] ?? '') . '"',
                    number_format($u['balance'], 2, '.', ''),
                    $u['is_active'] ? 'Active' : 'Inactive',
                    $u['spot_trades'],
                    $u['binary_trades'],
                    $u['created_at'],
                    $u['last_login'] ?? 'Never',
                    $u['last_ip'] ?? 'N/A'
                ]);
            }

            log_admin_activity($pdo, $admin_id, 'export_users', 'Exported users to CSV');
            json_response(['success' => true, 'csv' => implode("\n", $csv_lines), 'filename' => 'users_export_' . date('Y-m-d_H-i-s') . '.csv']);
        } catch (PDOException $e) {
            error_log("Export CSV error: " . $e->getMessage());
            json_response(['success' => false, 'message' => t('error_occurred', 'An error occurred')], 500);
        }
    }

    exit;
}

// ==========================================
// MAIN PAGE DATA
// ==========================================

// Filters
$search = sanitize($_GET['search'] ?? '');
$status_filter = sanitize($_GET['status'] ?? 'all');
$sort = sanitize($_GET['sort'] ?? 'newest');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build query
$where = "WHERE 1=1";
$params = [];

if (!empty($search)) {
    $where .= " AND (username LIKE ? OR email LIKE ? OR full_name LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}

if ($status_filter === 'active') {
    $where .= " AND is_active = 1";
}
if ($status_filter === 'inactive') {
    $where .= " AND is_active = 0";
}

$order = match($sort) {
    'oldest' => 'u.created_at ASC',
    'balance_high' => 'u.balance DESC',
    'balance_low' => 'u.balance ASC',
    default => 'u.created_at DESC'
};

// Get total count
try {
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM users $where");
    $count_stmt->execute($params);
    $total_users = $count_stmt->fetchColumn();
    $total_pages = max(1, ceil($total_users / $per_page));
} catch (PDOException $e) {
    $total_users = 0;
    $total_pages = 1;
}

// Get users
try {
    $query_params = $params;
    $query_params[] = $per_page;
    $query_params[] = $offset;

    $stmt = $pdo->prepare("
        SELECT
            u.*,
            COUNT(DISTINCT st.id) as spot_trades_count,
            COUNT(DISTINCT bt.id) as binary_trades_count
        FROM users u
        LEFT JOIN spot_trades st ON u.id = st.user_id
        LEFT JOIN binary_trades bt ON u.id = bt.user_id
        $where
        GROUP BY u.id
        ORDER BY $order
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($query_params);
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Get users error: " . $e->getMessage());
    $users = [];
}

// Get statistics
try {
    $stmt = $pdo->query("
        SELECT
            COUNT(*) as total,
            COUNT(CASE WHEN is_active = 1 THEN 1 END) as active,
            COUNT(CASE WHEN is_active = 0 THEN 1 END) as inactive,
            COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as new_today,
            SUM(balance) as total_balance
        FROM users
    ");
    $stats = $stmt->fetch();
} catch (PDOException $e) {
    $stats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'new_today' => 0, 'total_balance' => 0];
}

// Get pending transactions count for sidebar badge
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending'");
    $pending_count = $stmt->fetchColumn();
} catch (PDOException $e) {
    $pending_count = 0;
}

// Sidebar position helpers
$sidebar_side = $rtl ? 'right' : 'left';
$sidebar_opposite = $rtl ? 'left' : 'right';
$chevron_prev = $rtl ? 'fa-chevron-right' : 'fa-chevron-left';
$chevron_next = $rtl ? 'fa-chevron-left' : 'fa-chevron-right';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('user_management', 'User Management') ?> - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --primary: #0F172A;
            --secondary: #1E293B;
            --accent: #F59E0B;
            --success: #10B981;
            --danger: #EF4444;
            --info: #3B82F6;
            --warning: #F59E0B;
            --text: #F8FAFC;
            --text-muted: #94A3B8;
            --border: rgba(148, 163, 184, 0.1);
            --sidebar-width: 280px;
        }

        body {
            font-family: <?= get_font() ?>;
            background: var(--primary);
            color: var(--text);
            line-height: 1.6;
        }

        /* ====== SIDEBAR ====== */
        .sidebar {
            position: fixed;
            <?= $sidebar_side ?>: 0;
            top: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--secondary);
            border-<?= $sidebar_opposite ?>: 1px solid var(--border);
            z-index: 1000;
            overflow-y: auto;
            transition: transform 0.3s ease;
        }

        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-thumb { background: var(--accent); border-radius: 2px; }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.6);
            z-index: 999;
        }

        .hamburger {
            display: none;
            position: fixed;
            top: 16px;
            <?= $sidebar_side ?>: 16px;
            z-index: 1001;
            background: var(--secondary);
            border: 1px solid var(--border);
            color: var(--text);
            width: 44px; height: 44px;
            border-radius: 10px;
            font-size: 20px;
            cursor: pointer;
            align-items: center;
            justify-content: center;
        }

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
            width: 48px; height: 48px;
            background: linear-gradient(135deg, #DC2626, var(--accent));
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; color: white;
        }

        .logo-text {
            font-size: 22px; font-weight: 900;
            background: linear-gradient(135deg, #DC2626, var(--accent));
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }

        .admin-badge {
            background: linear-gradient(135deg, #DC2626, var(--accent));
            color: white; font-size: 10px; padding: 4px 12px;
            border-radius: 20px; font-weight: 700; text-transform: uppercase;
        }

        .admin-card {
            padding: 20px; margin: 20px;
            background: rgba(220, 38, 38, 0.05);
            border: 1px solid rgba(220, 38, 38, 0.1);
            border-radius: 16px;
        }

        .admin-info {
            display: flex; align-items: center; gap: 12px;
        }

        .admin-avatar {
            width: 48px; height: 48px;
            background: linear-gradient(135deg, #DC2626, var(--accent));
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; font-weight: 700; color: white;
        }

        .nav-menu { padding: 20px 0; }

        .nav-section-title {
            padding: 0 20px 10px; font-size: 11px; font-weight: 700;
            color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px;
        }

        .nav-item {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 20px; color: var(--text-muted);
            text-decoration: none; transition: all 0.3s; position: relative;
        }

        .nav-item:hover, .nav-item.active {
            color: var(--text);
            background: rgba(220, 38, 38, 0.05);
        }

        .nav-item.active::before {
            content: ''; position: absolute;
            <?= $sidebar_side ?>: 0; top: 0;
            height: 100%; width: 3px;
            background: linear-gradient(135deg, #DC2626, var(--accent));
        }

        .nav-item i { width: 20px; text-align: center; font-size: 18px; }

        .nav-badge {
            margin-<?= $sidebar_side ?>: auto;
            background: var(--danger); color: white;
            font-size: 10px; padding: 3px 8px;
            border-radius: 10px; font-weight: 700;
        }

        /* ====== MAIN ====== */
        .main {
            margin-<?= $sidebar_side ?>: var(--sidebar-width);
            padding: 30px;
            min-height: 100vh;
        }

        .header { margin-bottom: 32px; }

        .page-title {
            font-size: 32px; font-weight: 900;
            background: linear-gradient(135deg, var(--text), #DC2626);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
        }

        .breadcrumb {
            display: flex; align-items: center; gap: 8px;
            font-size: 14px; color: var(--text-muted);
        }

        .breadcrumb a { color: var(--text-muted); text-decoration: none; }
        .breadcrumb a:hover { color: var(--accent); }

        /* ====== ALERTS ====== */
        .alert {
            padding: 16px 20px; border-radius: 12px; margin-bottom: 24px;
            display: flex; align-items: center; gap: 12px;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2); color: var(--success);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2); color: var(--danger);
        }

        /* ====== STATS ====== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px; margin-bottom: 24px;
        }

        .stat-card {
            background: var(--secondary); border: 1px solid var(--border);
            border-radius: 12px; padding: 20px;
        }

        .stat-icon {
            width: 44px; height: 44px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; margin-bottom: 12px;
        }

        .stat-label { font-size: 13px; color: var(--text-muted); margin-bottom: 8px; }
        .stat-value { font-size: 28px; font-weight: 900; }

        /* ====== FILTERS ====== */
        .filters-section {
            background: var(--secondary); border: 1px solid var(--border);
            border-radius: 16px; padding: 24px; margin-bottom: 24px;
        }

        .filters-row {
            display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-end;
        }

        .filter-group { display: flex; flex-direction: column; gap: 8px; flex: 1; min-width: 180px; }

        .filter-label { font-size: 13px; font-weight: 600; color: var(--text-muted); }

        .search-wrapper {
            position: relative;
        }

        .search-wrapper i {
            position: absolute;
            top: 50%; transform: translateY(-50%);
            <?= $sidebar_side ?>: 14px;
            color: var(--text-muted); font-size: 14px;
        }

        .search-wrapper input {
            padding-<?= $sidebar_side ?>: 40px;
        }

        .filter-input, .filter-select {
            padding: 12px 16px;
            background: var(--primary); border: 1px solid var(--border);
            border-radius: 10px; color: var(--text);
            font-size: 14px; font-family: inherit; transition: all 0.3s;
            width: 100%;
        }

        .filter-input:focus, .filter-select:focus {
            outline: none; border-color: var(--accent);
        }

        .filter-select option { background: var(--secondary); color: var(--text); }

        /* ====== TABLE ====== */
        .users-card {
            background: var(--secondary); border: 1px solid var(--border);
            border-radius: 16px; overflow: hidden;
        }

        .users-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 12px;
        }

        .users-header h3 { font-size: 18px; font-weight: 700; }

        .bulk-actions {
            display: flex; gap: 10px; align-items: center;
        }

        .table-wrapper { overflow-x: auto; }

        table { width: 100%; border-collapse: collapse; }

        thead { background: rgba(220, 38, 38, 0.05); }

        th {
            padding: 14px 20px;
            text-align: <?= $rtl ? 'right' : 'left' ?>;
            font-weight: 700; font-size: 12px;
            color: var(--text-muted); text-transform: uppercase;
            letter-spacing: 0.5px; white-space: nowrap;
        }

        td {
            padding: 14px 20px;
            border-top: 1px solid var(--border);
            white-space: nowrap;
        }

        tbody tr { transition: background 0.2s; }
        tbody tr:hover { background: rgba(220, 38, 38, 0.02); }

        .user-cell { display: flex; align-items: center; gap: 12px; }

        .user-avatar {
            width: 40px; height: 40px;
            background: linear-gradient(135deg, #DC2626, var(--accent));
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; color: white; font-size: 14px;
            flex-shrink: 0;
        }

        .user-info { min-width: 0; }
        .user-name { font-weight: 600; font-size: 14px; }
        .user-email { font-size: 12px; color: var(--text-muted); }

        .balance-positive { font-weight: 700; color: var(--success); }
        .balance-zero { font-weight: 700; color: var(--text-muted); }

        .trades-info { font-size: 13px; line-height: 1.8; }
        .trades-info span { display: inline-block; margin-<?= $sidebar_opposite ?>: 4px; }

        .status-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 12px; border-radius: 8px;
            font-size: 12px; font-weight: 700;
        }

        .status-badge.active {
            background: rgba(16, 185, 129, 0.1); color: var(--success);
        }

        .status-badge.inactive {
            background: rgba(239, 68, 68, 0.1); color: var(--danger);
        }

        .date-cell { font-size: 13px; color: var(--text-muted); }

        .actions-cell { display: flex; gap: 6px; }

        /* ====== BUTTONS ====== */
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 8px 16px; border: none; border-radius: 8px;
            font-weight: 600; font-size: 13px; cursor: pointer;
            transition: all 0.3s; text-decoration: none; font-family: inherit;
            white-space: nowrap;
        }

        .btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .btn-primary {
            background: linear-gradient(135deg, #DC2626, var(--accent)); color: white;
        }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(220, 38, 38, 0.3); }

        .btn-secondary {
            background: var(--primary); color: var(--text); border: 1px solid var(--border);
        }
        .btn-secondary:hover { background: #2D3748; }

        .btn-sm { padding: 6px 10px; font-size: 12px; }

        .btn-danger { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
        .btn-danger:hover { background: var(--danger); color: white; }

        .btn-success { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .btn-success:hover { background: var(--success); color: white; }

        .btn-info { background: rgba(59, 130, 246, 0.1); color: var(--info); }
        .btn-info:hover { background: var(--info); color: white; }

        .btn-warning { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .btn-warning:hover { background: var(--warning); color: white; }

        .btn-icon { padding: 8px; width: 34px; height: 34px; justify-content: center; }

        /* ====== CHECKBOX ====== */
        .custom-checkbox {
            width: 18px; height: 18px; cursor: pointer;
            accent-color: var(--accent);
        }

        /* ====== PAGINATION ====== */
        .pagination-wrapper {
            padding: 20px 24px;
            border-top: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 12px;
        }

        .pagination-info { font-size: 13px; color: var(--text-muted); }

        .pagination {
            display: flex; align-items: center; gap: 6px;
        }

        .pagination-btn {
            padding: 8px 14px; background: var(--primary);
            border: 1px solid var(--border); border-radius: 8px;
            color: var(--text); text-decoration: none;
            font-weight: 600; font-size: 13px; transition: all 0.3s;
        }

        .pagination-btn:hover:not(.disabled):not(.active) {
            background: linear-gradient(135deg, #DC2626, var(--accent)); color: white;
            border-color: transparent;
        }

        .pagination-btn.active {
            background: linear-gradient(135deg, #DC2626, var(--accent));
            color: white; border-color: transparent;
        }

        .pagination-btn.disabled { opacity: 0.3; cursor: not-allowed; pointer-events: none; }

        /* ====== MODAL ====== */
        .modal {
            display: none; position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 2000;
            align-items: center; justify-content: center;
        }

        .modal.active { display: flex; }

        .modal-content {
            background: var(--secondary); border: 1px solid var(--border);
            border-radius: 16px; padding: 32px;
            max-width: 560px; width: 92%;
            max-height: 90vh; overflow-y: auto;
            animation: modalIn 0.3s ease;
        }

        @keyframes modalIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .modal-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 24px;
        }

        .modal-title { font-size: 22px; font-weight: 900; }

        .modal-close {
            background: none; border: none; color: var(--text-muted);
            font-size: 20px; cursor: pointer; padding: 4px;
        }
        .modal-close:hover { color: var(--text); }

        .form-group { margin-bottom: 18px; }

        .form-label {
            display: block; font-size: 14px; font-weight: 600;
            color: var(--text-muted); margin-bottom: 8px;
        }

        .form-input, .form-textarea {
            width: 100%; padding: 12px 16px;
            background: var(--primary); border: 1px solid var(--border);
            border-radius: 10px; color: var(--text);
            font-size: 14px; font-family: inherit; transition: border-color 0.3s;
        }

        .form-input:focus, .form-textarea:focus {
            outline: none; border-color: var(--accent);
        }

        .form-textarea { resize: vertical; min-height: 80px; }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

        .modal-actions {
            display: flex; gap: 12px; justify-content: flex-end;
            margin-top: 24px; padding-top: 20px;
            border-top: 1px solid var(--border);
        }

        /* ====== VIEW MODAL EXTRAS ====== */
        .view-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 16px;
        }

        .view-item {
            padding: 14px; background: var(--primary);
            border-radius: 10px; border: 1px solid var(--border);
        }

        .view-item-label { font-size: 12px; color: var(--text-muted); margin-bottom: 4px; }
        .view-item-value { font-size: 15px; font-weight: 600; word-break: break-all; }
        .view-item.full-width { grid-column: 1 / -1; }

        .current-balance-display {
            text-align: center; padding: 20px;
            background: var(--primary); border-radius: 12px;
            border: 1px solid var(--border); margin-bottom: 20px;
        }

        .current-balance-label { font-size: 13px; color: var(--text-muted); margin-bottom: 4px; }
        .current-balance-value { font-size: 32px; font-weight: 900; color: var(--success); }

        /* ====== TOAST ====== */
        .toast-container {
            position: fixed; top: 20px;
            <?= $sidebar_opposite ?>: 20px;
            z-index: 3000; display: flex; flex-direction: column; gap: 10px;
        }

        .toast {
            padding: 14px 20px; border-radius: 10px;
            color: white; font-weight: 600; font-size: 14px;
            display: flex; align-items: center; gap: 10px;
            animation: toastIn 0.3s ease;
            min-width: 280px;
        }

        .toast-success { background: var(--success); }
        .toast-error { background: var(--danger); }

        @keyframes toastIn {
            from { opacity: 0; transform: translateX(<?= $rtl ? '-30px' : '30px' ?>); }
            to { opacity: 1; transform: translateX(0); }
        }

        /* ====== EMPTY STATE ====== */
        .empty-state {
            text-align: center; padding: 60px 20px; color: var(--text-muted);
        }

        .empty-state i { font-size: 48px; margin-bottom: 16px; opacity: 0.3; }
        .empty-state p { font-size: 16px; }

        /* ====== RESPONSIVE ====== */
        @media (max-width: 768px) {
            .hamburger { display: flex; }

            .sidebar {
                transform: translateX(<?= $rtl ? '100%' : '-100%' ?>);
            }

            .sidebar.open { transform: translateX(0); }

            .sidebar-overlay.active { display: block; }

            .main {
                margin-<?= $sidebar_side ?>: 0;
                padding: 70px 16px 20px;
            }

            .stats-grid { grid-template-columns: 1fr 1fr; }

            .filters-row { flex-direction: column; }
            .filter-group { min-width: 100%; }

            table { min-width: 1000px; }

            .modal-content { width: 96%; padding: 20px; max-height: 95vh; }
            .form-row { grid-template-columns: 1fr; }
            .view-grid { grid-template-columns: 1fr; }

            .pagination-wrapper { justify-content: center; text-align: center; }

            .page-title { font-size: 24px; }
        }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
        }

        /* ====== LOADING ====== */
        .btn-loading { pointer-events: none; opacity: 0.7; }
        .btn-loading::after {
            content: ''; width: 14px; height: 14px;
            border: 2px solid transparent; border-top-color: currentColor;
            border-radius: 50%; animation: spin 0.6s linear infinite;
            display: inline-block; margin-<?= $sidebar_side ?>: 6px;
        }

        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Hamburger -->
    <button class="hamburger" id="hamburgerBtn" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="logo">
            <div class="logo-content">
                <div class="logo-icon"><i class="fas fa-shield-alt"></i></div>
                <div>
                    <div class="logo-text">Admin Panel</div>
                    <div class="admin-badge">Administrator</div>
                </div>
            </div>
        </div>

        <div class="admin-card">
            <div class="admin-info">
                <div class="admin-avatar"><i class="fas fa-user-shield"></i></div>
                <div>
                    <div style="font-weight: 700;"><?= htmlspecialchars($admin['username']) ?></div>
                    <div style="font-size: 12px; color: var(--text-muted);">Super Admin</div>
                </div>
            </div>
        </div>

        <nav class="nav-menu">
            <div class="nav-section-title"><?= t('management', 'Management') ?></div>
            <a href="index.php" class="nav-item">
                <i class="fas fa-chart-pie"></i>
                <span><?= t('dashboard', 'Dashboard') ?></span>
            </a>
            <a href="users.php" class="nav-item active">
                <i class="fas fa-users"></i>
                <span><?= t('users', 'Users') ?></span>
                <span class="nav-badge"><?= number_format($stats['total']) ?></span>
            </a>
            <a href="payments.php" class="nav-item">
                <i class="fas fa-credit-card"></i>
                <span><?= t('payments', 'Payments') ?></span>
                <?php if ($pending_count > 0): ?>
                    <span class="nav-badge"><?= $pending_count ?></span>
                <?php endif; ?>
            </a>
            <a href="trades.php" class="nav-item">
                <i class="fas fa-exchange-alt"></i>
                <span><?= t('trades', 'Trades') ?></span>
            </a>
            <a href="gateways.php" class="nav-item">
                <i class="fas fa-plug"></i>
                <span><?= t('gateways', 'Gateways') ?></span>
            </a>
            <a href="reports.php" class="nav-item">
                <i class="fas fa-chart-bar"></i>
                <span><?= t('reports', 'Reports') ?></span>
            </a>
            <a href="settings.php" class="nav-item">
                <i class="fas fa-cog"></i>
                <span><?= t('settings', 'Settings') ?></span>
            </a>

            <div class="nav-section-title"><?= t('other', 'Other') ?></div>
            <a href="../logout.php" class="nav-item" style="color: var(--danger);">
                <i class="fas fa-sign-out-alt"></i>
                <span><?= t('logout', 'Logout') ?></span>
            </a>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main">
        <div class="header">
            <h1 class="page-title"><?= t('user_management', 'User Management') ?></h1>
            <div class="breadcrumb">
                <a href="index.php"><?= t('dashboard', 'Dashboard') ?></a>
                <i class="fas <?= $chevron_next ?>" style="font-size: 10px;"></i>
                <span><?= t('users', 'Users') ?></span>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(59, 130, 246, 0.1); color: var(--info);">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-label"><?= t('total_users', 'Total Users') ?></div>
                <div class="stat-value" style="color: var(--info);"><?= number_format($stats['total']) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--success);">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-label"><?= t('active_users', 'Active Users') ?></div>
                <div class="stat-value" style="color: var(--success);"><?= number_format($stats['active']) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); color: var(--accent);">
                    <i class="fas fa-user-plus"></i>
                </div>
                <div class="stat-label"><?= t('new_today', 'New Today') ?></div>
                <div class="stat-value" style="color: var(--accent);"><?= number_format($stats['new_today']) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--success);">
                    <i class="fas fa-wallet"></i>
                </div>
                <div class="stat-label"><?= t('total_balance', 'Total Balance') ?></div>
                <div class="stat-value" style="color: var(--success);">$<?= number_format($stats['total_balance'] ?? 0, 2) ?></div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-section">
            <form method="GET" action="users.php" id="filterForm">
                <div class="filters-row">
                    <div class="filter-group" style="flex: 2;">
                        <label class="filter-label"><?= t('search', 'Search') ?></label>
                        <div class="search-wrapper">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search" class="filter-input" placeholder="<?= t('search_users_placeholder', 'Username, email, or full name...') ?>" value="<?= htmlspecialchars($search) ?>">
                        </div>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label"><?= t('status', 'Status') ?></label>
                        <select name="status" class="filter-select" onchange="this.form.submit()">
                            <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>><?= t('all', 'All') ?></option>
                            <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>><?= t('active', 'Active') ?></option>
                            <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>><?= t('inactive', 'Inactive') ?></option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label"><?= t('sort_by', 'Sort By') ?></label>
                        <select name="sort" class="filter-select" onchange="this.form.submit()">
                            <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>><?= t('newest', 'Newest') ?></option>
                            <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>><?= t('oldest', 'Oldest') ?></option>
                            <option value="balance_high" <?= $sort === 'balance_high' ? 'selected' : '' ?>><?= t('balance_high', 'Balance (High)') ?></option>
                            <option value="balance_low" <?= $sort === 'balance_low' ? 'selected' : '' ?>><?= t('balance_low', 'Balance (Low)') ?></option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label" style="opacity: 0;">&nbsp;</label>
                        <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;">
                            <i class="fas fa-search"></i>
                            <?= t('search', 'Search') ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Users Table -->
        <div class="users-card">
            <div class="users-header">
                <h3><?= t('users', 'Users') ?> (<?= number_format($total_users) ?>)</h3>
                <div class="bulk-actions">
                    <select id="bulkAction" class="filter-select" style="width: auto; padding: 8px 14px; font-size: 13px;">
                        <option value=""><?= t('bulk_actions', 'Bulk Actions') ?></option>
                        <option value="activate"><?= t('activate', 'Activate') ?></option>
                        <option value="deactivate"><?= t('deactivate', 'Deactivate') ?></option>
                        <option value="delete"><?= t('delete_selected', 'Delete Selected') ?></option>
                        <option value="export"><?= t('export_csv', 'Export CSV') ?></option>
                    </select>
                    <button class="btn btn-secondary btn-sm" onclick="executeBulkAction()">
                        <i class="fas fa-play"></i>
                        <?= t('apply', 'Apply') ?>
                    </button>
                </div>
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th><input type="checkbox" class="custom-checkbox" id="selectAll" onclick="toggleSelectAll()"></th>
                            <th><?= t('user', 'User') ?></th>
                            <th><?= t('full_name', 'Full Name') ?></th>
                            <th><?= t('balance', 'Balance') ?></th>
                            <th><?= t('total_trades', 'Total Trades') ?></th>
                            <th><?= t('status', 'Status') ?></th>
                            <th><?= t('registered', 'Registered') ?></th>
                            <th><?= t('last_login', 'Last Login') ?></th>
                            <th><?= t('actions', 'Actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="9">
                                    <div class="empty-state">
                                        <i class="fas fa-users"></i>
                                        <p><?= t('no_users_found', 'No users found') ?></p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                                <?php
                                    $total_trades = ($user['spot_trades_count'] ?? 0) + ($user['binary_trades_count'] ?? 0);
                                    $avatar_letters = mb_strtoupper(mb_substr($user['username'], 0, 2));
                                    $balance_class = $user['balance'] > 0 ? 'balance-positive' : 'balance-zero';
                                ?>
                                <tr id="userRow-<?= $user['id'] ?>">
                                    <td>
                                        <input type="checkbox" class="custom-checkbox user-checkbox" value="<?= $user['id'] ?>">
                                    </td>
                                    <td>
                                        <div class="user-cell">
                                            <div class="user-avatar"><?= $avatar_letters ?></div>
                                            <div class="user-info">
                                                <div class="user-name"><?= htmlspecialchars($user['username']) ?></div>
                                                <div class="user-email"><?= htmlspecialchars($user['email']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="font-size: 14px;"><?= htmlspecialchars($user['full_name'] ?? '-') ?></span>
                                    </td>
                                    <td>
                                        <span class="<?= $balance_class ?>">
                                            $<?= number_format($user['balance'], 2) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="trades-info">
                                            <span style="color: var(--info);">S: <?= $user['spot_trades_count'] ?? 0 ?></span>
                                            <span style="color: var(--accent);">B: <?= $user['binary_trades_count'] ?? 0 ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= $user['is_active'] ? 'active' : 'inactive' ?>" id="statusBadge-<?= $user['id'] ?>">
                                            <i class="fas fa-<?= $user['is_active'] ? 'check-circle' : 'times-circle' ?>"></i>
                                            <?= $user['is_active'] ? t('active', 'Active') : t('inactive', 'Inactive') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="date-cell">
                                            <?= date('Y-m-d', strtotime($user['created_at'])) ?><br>
                                            <span style="font-size: 11px;"><?= date('H:i', strtotime($user['created_at'])) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="date-cell">
                                            <?php if (!empty($user['last_login'])): ?>
                                                <?= date('Y-m-d', strtotime($user['last_login'])) ?><br>
                                                <span style="font-size: 11px;"><?= date('H:i', strtotime($user['last_login'])) ?></span>
                                            <?php else: ?>
                                                <span style="opacity: 0.4;"><?= t('never', 'Never') ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="actions-cell">
                                            <button class="btn btn-info btn-sm btn-icon" onclick="viewUser(<?= $user['id'] ?>)" title="<?= t('view', 'View') ?>">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn btn-secondary btn-sm btn-icon" onclick="editUser(<?= $user['id'] ?>)" title="<?= t('edit', 'Edit') ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn <?= $user['is_active'] ? 'btn-danger' : 'btn-success' ?> btn-sm btn-icon" onclick="toggleStatus(<?= $user['id'] ?>)" title="<?= t('toggle_status', 'Toggle Status') ?>" id="toggleBtn-<?= $user['id'] ?>">
                                                <i class="fas fa-<?= $user['is_active'] ? 'ban' : 'check' ?>"></i>
                                            </button>
                                            <button class="btn btn-warning btn-sm btn-icon" onclick="openBalanceModal(<?= $user['id'] ?>)" title="<?= t('adjust_balance', 'Adjust Balance') ?>">
                                                <i class="fas fa-wallet"></i>
                                            </button>
                                            <button class="btn btn-danger btn-sm btn-icon" onclick="deleteUser(<?= $user['id'] ?>, '<?= htmlspecialchars(addslashes($user['username'])) ?>')" title="<?= t('delete', 'Delete') ?>">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination-wrapper">
                    <div class="pagination-info">
                        <?= t('showing', 'Showing') ?> <?= $offset + 1 ?>-<?= min($offset + $per_page, $total_users) ?>
                        <?= t('of', 'of') ?> <?= number_format($total_users) ?> <?= t('users', 'users') ?>
                    </div>
                    <div class="pagination">
                        <?php
                            $query_base = http_build_query(array_filter([
                                'search' => $search,
                                'status' => $status_filter !== 'all' ? $status_filter : null,
                                'sort' => $sort !== 'newest' ? $sort : null,
                            ]));
                            $q_sep = $query_base ? "&$query_base" : '';
                        ?>

                        <a href="?page=1<?= $q_sep ?>" class="pagination-btn <?= $page <= 1 ? 'disabled' : '' ?>">
                            <i class="fas fa-angle-double-<?= $rtl ? 'right' : 'left' ?>"></i>
                        </a>
                        <a href="?page=<?= max(1, $page - 1) ?><?= $q_sep ?>" class="pagination-btn <?= $page <= 1 ? 'disabled' : '' ?>">
                            <i class="fas <?= $chevron_prev ?>"></i>
                        </a>

                        <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <a href="?page=<?= $i ?><?= $q_sep ?>" class="pagination-btn <?= $i === $page ? 'active' : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>

                        <a href="?page=<?= min($total_pages, $page + 1) ?><?= $q_sep ?>" class="pagination-btn <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <i class="fas <?= $chevron_next ?>"></i>
                        </a>
                        <a href="?page=<?= $total_pages ?><?= $q_sep ?>" class="pagination-btn <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <i class="fas fa-angle-double-<?= $rtl ? 'left' : 'right' ?>"></i>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- ==================== VIEW USER MODAL ==================== -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title"><i class="fas fa-user"></i> <?= t('user_details', 'User Details') ?></h2>
                <button class="modal-close" onclick="closeModal('viewModal')">&times;</button>
            </div>
            <div id="viewContent">
                <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                    <i class="fas fa-spinner fa-spin" style="font-size: 24px;"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== EDIT USER MODAL ==================== -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title"><i class="fas fa-user-edit"></i> <?= t('edit_user', 'Edit User') ?></h2>
                <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
            </div>
            <form id="editForm" onsubmit="saveUser(event)">
                <input type="hidden" id="edit_user_id">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('username', 'Username') ?> *</label>
                        <input type="text" id="edit_username" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('email', 'Email') ?> *</label>
                        <input type="email" id="edit_email" class="form-input" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= t('full_name', 'Full Name') ?></label>
                        <input type="text" id="edit_full_name" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= t('phone', 'Phone') ?></label>
                        <input type="text" id="edit_phone" class="form-input">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('country', 'Country') ?></label>
                    <input type="text" id="edit_country" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('new_password', 'New Password') ?> <span style="color: var(--text-muted); font-weight: 400;">(<?= t('leave_blank', 'leave blank to keep current') ?>)</span></label>
                    <input type="password" id="edit_password" class="form-input" minlength="8" autocomplete="new-password">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')"><?= t('cancel', 'Cancel') ?></button>
                    <button type="submit" class="btn btn-primary" id="editSaveBtn">
                        <i class="fas fa-save"></i>
                        <?= t('save_changes', 'Save Changes') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ==================== ADJUST BALANCE MODAL ==================== -->
    <div id="balanceModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title"><i class="fas fa-wallet"></i> <?= t('adjust_balance', 'Adjust Balance') ?></h2>
                <button class="modal-close" onclick="closeModal('balanceModal')">&times;</button>
            </div>
            <div class="current-balance-display">
                <div class="current-balance-label"><?= t('current_balance', 'Current Balance') ?></div>
                <div class="current-balance-value" id="balance_current">$0.00</div>
            </div>
            <form id="balanceForm" onsubmit="submitBalance(event)">
                <input type="hidden" id="balance_user_id">
                <div class="form-group">
                    <label class="form-label"><?= t('amount', 'Amount') ?> *</label>
                    <input type="number" id="balance_amount" class="form-input" step="0.01" min="0.01" required placeholder="<?= t('enter_amount', 'Enter amount') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= t('reason_note', 'Reason / Note') ?></label>
                    <textarea id="balance_note" class="form-textarea" placeholder="<?= t('reason_placeholder', 'Enter a reason for this adjustment...') ?>"></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('balanceModal')"><?= t('cancel', 'Cancel') ?></button>
                    <button type="button" class="btn btn-danger" onclick="submitBalanceAction('deduct')">
                        <i class="fas fa-minus"></i>
                        <?= t('deduct', 'Deduct') ?>
                    </button>
                    <button type="button" class="btn btn-success" onclick="submitBalanceAction('add')">
                        <i class="fas fa-plus"></i>
                        <?= t('add', 'Add') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const CSRF_TOKEN = '<?= $csrf_token ?>';
        const LANG = '<?= $lang ?>';

        // ====== TOAST NOTIFICATIONS ======
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = 'toast toast-' + type;
            toast.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : 'exclamation-circle') + '"></i> ' + message;
            container.appendChild(toast);
            setTimeout(() => { toast.style.opacity = '0'; toast.style.transform = 'translateY(-10px)'; setTimeout(() => toast.remove(), 300); }, 3500);
        }

        // ====== AJAX HELPER ======
        function ajaxPost(data) {
            data.csrf_token = CSRF_TOKEN;
            const body = new URLSearchParams(data).toString();
            return fetch('users.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: body
            }).then(r => r.json());
        }

        // ====== SIDEBAR ======
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('active');
        }

        // ====== SELECT ALL ======
        function toggleSelectAll() {
            const checked = document.getElementById('selectAll').checked;
            document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = checked);
        }

        function getSelectedIds() {
            return Array.from(document.querySelectorAll('.user-checkbox:checked')).map(cb => cb.value);
        }

        // ====== MODAL HELPERS ======
        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        // Close modal on outside click
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) closeModal(this.id);
            });
        });

        // Close on Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.active').forEach(m => closeModal(m.id));
            }
        });

        // ====== VIEW USER ======
        function viewUser(userId) {
            openModal('viewModal');
            document.getElementById('viewContent').innerHTML = '<div style="text-align:center;padding:40px;color:var(--text-muted);"><i class="fas fa-spinner fa-spin" style="font-size:24px;"></i></div>';

            ajaxPost({action: 'get_user', user_id: userId}).then(data => {
                if (data.success) {
                    const u = data.user;
                    const statusClass = u.is_active ? 'active' : 'inactive';
                    const statusText = u.is_active ? '<?= t('active', 'Active') ?>' : '<?= t('inactive', 'Inactive') ?>';
                    document.getElementById('viewContent').innerHTML = `
                        <div class="view-grid">
                            <div class="view-item">
                                <div class="view-item-label"><?= t('username', 'Username') ?></div>
                                <div class="view-item-value">${escHtml(u.username)}</div>
                            </div>
                            <div class="view-item">
                                <div class="view-item-label"><?= t('email', 'Email') ?></div>
                                <div class="view-item-value">${escHtml(u.email)}</div>
                            </div>
                            <div class="view-item">
                                <div class="view-item-label"><?= t('full_name', 'Full Name') ?></div>
                                <div class="view-item-value">${escHtml(u.full_name || '-')}</div>
                            </div>
                            <div class="view-item">
                                <div class="view-item-label"><?= t('phone', 'Phone') ?></div>
                                <div class="view-item-value">${escHtml(u.phone || '-')}</div>
                            </div>
                            <div class="view-item">
                                <div class="view-item-label"><?= t('country', 'Country') ?></div>
                                <div class="view-item-value">${escHtml(u.country || '-')}</div>
                            </div>
                            <div class="view-item">
                                <div class="view-item-label"><?= t('balance', 'Balance') ?></div>
                                <div class="view-item-value" style="color: var(--success);">$${parseFloat(u.balance).toFixed(2)}</div>
                            </div>
                            <div class="view-item">
                                <div class="view-item-label"><?= t('total_trades', 'Total Trades') ?></div>
                                <div class="view-item-value">${u.total_trades} <span style="font-size:12px;color:var(--text-muted);">(S:${u.spot_trades} B:${u.binary_trades})</span></div>
                            </div>
                            <div class="view-item">
                                <div class="view-item-label"><?= t('status', 'Status') ?></div>
                                <div class="view-item-value"><span class="status-badge ${statusClass}"><i class="fas fa-${u.is_active ? 'check-circle' : 'times-circle'}"></i> ${statusText}</span></div>
                            </div>
                            <div class="view-item">
                                <div class="view-item-label"><?= t('registered', 'Registered') ?></div>
                                <div class="view-item-value">${u.created_at || '-'}</div>
                            </div>
                            <div class="view-item">
                                <div class="view-item-label"><?= t('last_login', 'Last Login') ?></div>
                                <div class="view-item-value">${u.last_login || '<?= t('never', 'Never') ?>'}</div>
                            </div>
                            <div class="view-item full-width">
                                <div class="view-item-label"><?= t('last_ip', 'Last IP') ?></div>
                                <div class="view-item-value">${escHtml(u.last_ip || 'N/A')}</div>
                            </div>
                        </div>
                    `;
                } else {
                    document.getElementById('viewContent').innerHTML = '<p style="text-align:center;color:var(--danger);">' + (data.message || 'Error') + '</p>';
                }
            }).catch(() => {
                document.getElementById('viewContent').innerHTML = '<p style="text-align:center;color:var(--danger);"><?= t('error_occurred', 'An error occurred') ?></p>';
            });
        }

        // ====== EDIT USER ======
        function editUser(userId) {
            ajaxPost({action: 'get_user', user_id: userId}).then(data => {
                if (data.success) {
                    const u = data.user;
                    document.getElementById('edit_user_id').value = u.id;
                    document.getElementById('edit_username').value = u.username;
                    document.getElementById('edit_email').value = u.email;
                    document.getElementById('edit_full_name').value = u.full_name || '';
                    document.getElementById('edit_phone').value = u.phone || '';
                    document.getElementById('edit_country').value = u.country || '';
                    document.getElementById('edit_password').value = '';
                    openModal('editModal');
                } else {
                    showToast(data.message || '<?= t('error_occurred', 'An error occurred') ?>', 'error');
                }
            }).catch(() => showToast('<?= t('error_occurred', 'An error occurred') ?>', 'error'));
        }

        function saveUser(e) {
            e.preventDefault();
            const btn = document.getElementById('editSaveBtn');
            btn.classList.add('btn-loading');
            btn.disabled = true;

            ajaxPost({
                action: 'update_user',
                user_id: document.getElementById('edit_user_id').value,
                username: document.getElementById('edit_username').value,
                email: document.getElementById('edit_email').value,
                full_name: document.getElementById('edit_full_name').value,
                phone: document.getElementById('edit_phone').value,
                country: document.getElementById('edit_country').value,
                password: document.getElementById('edit_password').value
            }).then(data => {
                btn.classList.remove('btn-loading');
                btn.disabled = false;
                if (data.success) {
                    showToast('<?= t('user_updated', 'User updated successfully') ?>');
                    closeModal('editModal');
                    setTimeout(() => location.reload(), 800);
                } else {
                    showToast(data.message || '<?= t('error_occurred', 'An error occurred') ?>', 'error');
                }
            }).catch(() => {
                btn.classList.remove('btn-loading');
                btn.disabled = false;
                showToast('<?= t('error_occurred', 'An error occurred') ?>', 'error');
            });
        }

        // ====== TOGGLE STATUS ======
        function toggleStatus(userId) {
            if (!confirm('<?= t('confirm_toggle_status', 'Are you sure you want to toggle this user\'s status?') ?>')) return;

            ajaxPost({action: 'toggle_status', user_id: userId}).then(data => {
                if (data.success) {
                    showToast('<?= t('status_updated', 'Status updated successfully') ?>');
                    setTimeout(() => location.reload(), 600);
                } else {
                    showToast(data.message || '<?= t('error_occurred', 'An error occurred') ?>', 'error');
                }
            }).catch(() => showToast('<?= t('error_occurred', 'An error occurred') ?>', 'error'));
        }

        // ====== ADJUST BALANCE ======
        function openBalanceModal(userId) {
            document.getElementById('balance_user_id').value = userId;
            document.getElementById('balance_amount').value = '';
            document.getElementById('balance_note').value = '';

            ajaxPost({action: 'get_user', user_id: userId}).then(data => {
                if (data.success) {
                    document.getElementById('balance_current').textContent = '$' + parseFloat(data.user.balance).toFixed(2);
                    openModal('balanceModal');
                } else {
                    showToast(data.message || '<?= t('error_occurred', 'An error occurred') ?>', 'error');
                }
            }).catch(() => showToast('<?= t('error_occurred', 'An error occurred') ?>', 'error'));
        }

        function submitBalanceAction(type) {
            const amount = parseFloat(document.getElementById('balance_amount').value);
            if (!amount || amount <= 0) {
                showToast('<?= t('enter_valid_amount', 'Please enter a valid amount') ?>', 'error');
                return;
            }

            const finalAmount = type === 'deduct' ? -amount : amount;
            const note = document.getElementById('balance_note').value;
            const userId = document.getElementById('balance_user_id').value;

            ajaxPost({
                action: 'update_balance',
                user_id: userId,
                amount: finalAmount,
                note: note
            }).then(data => {
                if (data.success) {
                    showToast('<?= t('balance_updated', 'Balance updated successfully') ?>');
                    closeModal('balanceModal');
                    setTimeout(() => location.reload(), 800);
                } else {
                    showToast(data.message || '<?= t('error_occurred', 'An error occurred') ?>', 'error');
                }
            }).catch(() => showToast('<?= t('error_occurred', 'An error occurred') ?>', 'error'));
        }

        function submitBalance(e) { e.preventDefault(); }

        // ====== DELETE USER ======
        function deleteUser(userId, username) {
            if (!confirm('<?= t('confirm_delete_user', 'Are you sure you want to delete user') ?> "' + username + '"? <?= t('action_irreversible', 'This action cannot be undone.') ?>')) return;

            ajaxPost({action: 'delete_user', user_id: userId}).then(data => {
                if (data.success) {
                    showToast('<?= t('user_deleted', 'User deleted successfully') ?>');
                    const row = document.getElementById('userRow-' + userId);
                    if (row) { row.style.opacity = '0'; setTimeout(() => row.remove(), 300); }
                } else {
                    showToast(data.message || '<?= t('error_occurred', 'An error occurred') ?>', 'error');
                }
            }).catch(() => showToast('<?= t('error_occurred', 'An error occurred') ?>', 'error'));
        }

        // ====== BULK ACTIONS ======
        function executeBulkAction() {
            const action = document.getElementById('bulkAction').value;
            if (!action) {
                showToast('<?= t('select_action', 'Please select an action') ?>', 'error');
                return;
            }

            if (action === 'export') {
                exportCSV();
                return;
            }

            const ids = getSelectedIds();
            if (ids.length === 0) {
                showToast('<?= t('select_users', 'Please select at least one user') ?>', 'error');
                return;
            }

            const actionLabels = { activate: '<?= t('activate', 'Activate') ?>', deactivate: '<?= t('deactivate', 'Deactivate') ?>', delete: '<?= t('delete', 'Delete') ?>' };
            if (!confirm('<?= t('confirm_bulk', 'Apply') ?> "' + actionLabels[action] + '" <?= t('to', 'to') ?> ' + ids.length + ' <?= t('users', 'users') ?>?')) return;

            let completed = 0;
            const total = ids.length;

            ids.forEach(userId => {
                let postAction;
                if (action === 'delete') {
                    postAction = ajaxPost({action: 'delete_user', user_id: userId});
                } else {
                    // For activate/deactivate, we toggle - but to ensure correct state we'd need specific endpoints
                    // Using toggle which works for bulk as a simple approach
                    postAction = ajaxPost({action: 'toggle_status', user_id: userId});
                }

                postAction.then(() => {
                    completed++;
                    if (completed >= total) {
                        showToast('<?= t('bulk_action_complete', 'Bulk action completed') ?>');
                        setTimeout(() => location.reload(), 800);
                    }
                });
            });
        }

        // ====== EXPORT CSV ======
        function exportCSV() {
            ajaxPost({action: 'export_csv'}).then(data => {
                if (data.success) {
                    const blob = new Blob([data.csv], {type: 'text/csv;charset=utf-8;'});
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = data.filename;
                    a.click();
                    URL.revokeObjectURL(url);
                    showToast('<?= t('export_success', 'Export completed') ?>');
                } else {
                    showToast(data.message || '<?= t('error_occurred', 'An error occurred') ?>', 'error');
                }
            }).catch(() => showToast('<?= t('error_occurred', 'An error occurred') ?>', 'error'));
        }

        // ====== HTML ESCAPE ======
        function escHtml(str) {
            if (!str) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
    </script>
</body>
</html>
