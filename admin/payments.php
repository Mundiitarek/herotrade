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

// Sidebar position helpers
$sidebar_side = $rtl ? 'right' : 'left';
$sidebar_opposite = $rtl ? 'left' : 'right';
$chevron_next = $rtl ? 'fa-chevron-left' : 'fa-chevron-right';

// ==========================================
// AJAX HANDLERS (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // CSRF check
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        json_response(['success' => false, 'message' => t('invalid_token', 'Invalid security token')], 403);
    }

    // ------- approve -------
    if ($_POST['action'] === 'approve') {
        $transaction_id = intval($_POST['transaction_id'] ?? 0);
        $admin_notes = sanitize($_POST['admin_notes'] ?? '');

        if ($transaction_id <= 0) {
            json_response(['success' => false, 'message' => t('invalid_transaction', 'Invalid transaction')], 400);
        }

        try {
            $pdo->beginTransaction();

            // Get transaction
            $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ? AND status = 'pending'");
            $stmt->execute([$transaction_id]);
            $transaction = $stmt->fetch();

            if (!$transaction) {
                $pdo->rollBack();
                json_response(['success' => false, 'message' => t('transaction_not_found', 'Transaction not found or already processed')], 404);
            }

            // Update transaction status
            $stmt = $pdo->prepare("
                UPDATE transactions
                SET status = 'completed', completed_at = NOW(), processed_by = ?, admin_notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$admin_id, $admin_notes, $transaction_id]);

            // For deposits: add amount to user balance
            if ($transaction['type'] === 'deposit') {
                $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$transaction['amount'], $transaction['user_id']]);
            }

            // Create notification
            create_notification(
                $pdo,
                $transaction['user_id'],
                'success',
                'Transaction Approved',
                'تمت الموافقة على المعاملة',
                'Your ' . $transaction['type'] . ' of $' . number_format($transaction['amount'], 2) . ' has been approved.',
                'تمت الموافقة على ' . ($transaction['type'] === 'deposit' ? 'الإيداع' : 'السحب') . ' بقيمة $' . number_format($transaction['amount'], 2)
            );

            // Log activity
            log_admin_activity($pdo, $admin_id, 'payment_approve', "Approved transaction #{$transaction_id} ({$transaction['type']}) \${$transaction['amount']}");

            $pdo->commit();
            json_response(['success' => true, 'message' => t('transaction_approved', 'Transaction approved successfully')]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Approve transaction error: " . $e->getMessage());
            json_response(['success' => false, 'message' => t('error_occurred', 'An error occurred')], 500);
        }
    }

    // ------- approve_bonus -------
    if ($_POST['action'] === 'approve_bonus') {
        $transaction_id = intval($_POST['transaction_id'] ?? 0);
        $bonus_amount = floatval($_POST['bonus_amount'] ?? 0);
        $admin_notes = sanitize($_POST['admin_notes'] ?? '');

        if ($transaction_id <= 0) {
            json_response(['success' => false, 'message' => t('invalid_transaction', 'Invalid transaction')], 400);
        }

        try {
            $pdo->beginTransaction();

            // Get transaction
            $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ? AND status = 'pending'");
            $stmt->execute([$transaction_id]);
            $transaction = $stmt->fetch();

            if (!$transaction) {
                $pdo->rollBack();
                json_response(['success' => false, 'message' => t('transaction_not_found', 'Transaction not found or already processed')], 404);
            }

            // Update transaction status
            $stmt = $pdo->prepare("
                UPDATE transactions
                SET status = 'completed', completed_at = NOW(), processed_by = ?, admin_notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$admin_id, $admin_notes . ($bonus_amount > 0 ? " [Bonus: \${$bonus_amount}]" : ''), $transaction_id]);

            // Add original amount + bonus to user balance
            $total_credit = $transaction['amount'] + $bonus_amount;
            $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$total_credit, $transaction['user_id']]);

            // Create bonus transaction record if bonus > 0
            if ($bonus_amount > 0) {
                $bonus_tx_id = 'BONUS' . time() . rand(1000, 9999);
                $stmt = $pdo->prepare("
                    INSERT INTO transactions (transaction_id, user_id, type, amount, status, description, completed_at, processed_by)
                    VALUES (?, ?, 'bonus', ?, 'completed', ?, NOW(), ?)
                ");
                $stmt->execute([
                    $bonus_tx_id,
                    $transaction['user_id'],
                    $bonus_amount,
                    'Deposit bonus for transaction #' . $transaction_id,
                    $admin_id
                ]);
            }

            // Create notification
            $bonus_text = $bonus_amount > 0 ? ' + $' . number_format($bonus_amount, 2) . ' bonus' : '';
            $bonus_text_ar = $bonus_amount > 0 ? ' + $' . number_format($bonus_amount, 2) . ' مكافأة' : '';
            create_notification(
                $pdo,
                $transaction['user_id'],
                'success',
                'Deposit Approved' . ($bonus_amount > 0 ? ' with Bonus' : ''),
                'تمت الموافقة على الإيداع' . ($bonus_amount > 0 ? ' مع مكافأة' : ''),
                'Your deposit of $' . number_format($transaction['amount'], 2) . ' has been approved' . $bonus_text . '.',
                'تمت الموافقة على إيداعك بقيمة $' . number_format($transaction['amount'], 2) . $bonus_text_ar . '.'
            );

            // Log activity
            log_admin_activity($pdo, $admin_id, 'payment_approve_bonus', "Approved transaction #{$transaction_id} with bonus \${$bonus_amount}");

            $pdo->commit();
            json_response(['success' => true, 'message' => t('transaction_approved_bonus', 'Transaction approved with bonus')]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Approve bonus error: " . $e->getMessage());
            json_response(['success' => false, 'message' => t('error_occurred', 'An error occurred')], 500);
        }
    }

    // ------- reject -------
    if ($_POST['action'] === 'reject') {
        $transaction_id = intval($_POST['transaction_id'] ?? 0);
        $reject_reason = sanitize($_POST['reject_reason'] ?? '');

        if ($transaction_id <= 0) {
            json_response(['success' => false, 'message' => t('invalid_transaction', 'Invalid transaction')], 400);
        }

        try {
            $pdo->beginTransaction();

            // Get transaction
            $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ? AND status = 'pending'");
            $stmt->execute([$transaction_id]);
            $transaction = $stmt->fetch();

            if (!$transaction) {
                $pdo->rollBack();
                json_response(['success' => false, 'message' => t('transaction_not_found', 'Transaction not found or already processed')], 404);
            }

            // Update transaction
            $stmt = $pdo->prepare("
                UPDATE transactions
                SET status = 'failed', reject_reason = ?, processed_by = ?, completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$reject_reason, $admin_id, $transaction_id]);

            // For withdrawals: refund the balance back to user
            if ($transaction['type'] === 'withdrawal') {
                $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$transaction['amount'], $transaction['user_id']]);
            }

            // Create notification
            $reason_en = $reject_reason ?: 'No reason specified';
            $reason_ar = $reject_reason ?: 'لم يتم تحديد السبب';
            create_notification(
                $pdo,
                $transaction['user_id'],
                'danger',
                'Transaction Rejected',
                'تم رفض المعاملة',
                'Your ' . $transaction['type'] . ' of $' . number_format($transaction['amount'], 2) . ' was rejected. Reason: ' . $reason_en,
                'تم رفض ' . ($transaction['type'] === 'deposit' ? 'الإيداع' : 'السحب') . ' بقيمة $' . number_format($transaction['amount'], 2) . '. السبب: ' . $reason_ar
            );

            // Log activity
            log_admin_activity($pdo, $admin_id, 'payment_reject', "Rejected transaction #{$transaction_id}. Reason: {$reject_reason}");

            $pdo->commit();
            json_response(['success' => true, 'message' => t('transaction_rejected', 'Transaction rejected')]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Reject transaction error: " . $e->getMessage());
            json_response(['success' => false, 'message' => t('error_occurred', 'An error occurred')], 500);
        }
    }

    // ------- get_transaction -------
    if ($_POST['action'] === 'get_transaction') {
        $transaction_id = intval($_POST['transaction_id'] ?? 0);
        if ($transaction_id <= 0) {
            json_response(['success' => false, 'message' => t('invalid_transaction', 'Invalid transaction')], 400);
        }
        try {
            $stmt = $pdo->prepare("
                SELECT t.*, u.username, u.email, u.balance as user_balance, u.full_name,
                       pg.name as gateway_name
                FROM transactions t
                LEFT JOIN users u ON t.user_id = u.id
                LEFT JOIN payment_gateways pg ON t.gateway_id = pg.id
                WHERE t.id = ?
            ");
            $stmt->execute([$transaction_id]);
            $tx = $stmt->fetch();
            if (!$tx) {
                json_response(['success' => false, 'message' => t('transaction_not_found', 'Transaction not found')], 404);
            }
            json_response(['success' => true, 'transaction' => $tx]);
        } catch (PDOException $e) {
            error_log("Get transaction error: " . $e->getMessage());
            json_response(['success' => false, 'message' => t('error_occurred', 'An error occurred')], 500);
        }
    }

    exit;
}

// ==========================================
// MAIN PAGE DATA
// ==========================================

// Filters
$status_filter = sanitize($_GET['status'] ?? 'all');
$type_filter = sanitize($_GET['type'] ?? '');
$search = sanitize($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build where clause
$where_parts = ["1=1"];
$params = [];

if ($status_filter && $status_filter !== 'all') {
    if ($status_filter === 'rejected') {
        $where_parts[] = "(t.status = 'failed' OR t.status = 'rejected')";
    } else {
        $where_parts[] = "t.status = ?";
        $params[] = $status_filter;
    }
}

if ($type_filter) {
    $where_parts[] = "t.type = ?";
    $params[] = $type_filter;
}

if ($search) {
    $where_parts[] = "(u.username LIKE ? OR u.email LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$where = implode(' AND ', $where_parts);

// Get total count for pagination
try {
    $count_sql = "SELECT COUNT(*) FROM transactions t LEFT JOIN users u ON t.user_id = u.id WHERE {$where}";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = max(1, ceil($total_records / $per_page));
} catch (PDOException $e) {
    error_log("Count transactions error: " . $e->getMessage());
    $total_records = 0;
    $total_pages = 1;
}

// Get transactions
try {
    $query_params = $params;
    $query_params[] = $per_page;
    $query_params[] = $offset;

    $stmt = $pdo->prepare("
        SELECT t.*, u.username, u.email, u.full_name, u.balance as user_balance,
               pg.name as gateway_name,
               COALESCE(pg.display_name_en, pg.name) as gateway_display_en,
               COALESCE(pg.display_name_ar, pg.name) as gateway_display_ar
        FROM transactions t
        LEFT JOIN users u ON t.user_id = u.id
        LEFT JOIN payment_gateways pg ON t.gateway_id = pg.id
        WHERE {$where}
        ORDER BY t.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($query_params);
    $transactions = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Get transactions error: " . $e->getMessage());
    $transactions = [];
}

// ==========================================
// STATISTICS
// ==========================================
try {
    $stmt = $pdo->query("
        SELECT
            COALESCE(SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END), 0) as total_volume,
            COALESCE(SUM(CASE WHEN status = 'completed' AND DATE(created_at) = CURDATE() THEN amount ELSE 0 END), 0) as today_volume,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
            COUNT(*) as total_count,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_count,
            COUNT(CASE WHEN status = 'failed' OR status = 'rejected' THEN 1 END) as rejected_count
        FROM transactions
    ");
    $stats = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Stats error: " . $e->getMessage());
    $stats = ['total_volume' => 0, 'today_volume' => 0, 'pending_count' => 0, 'total_count' => 0, 'completed_count' => 0, 'rejected_count' => 0];
}

$success_rate = ($stats['total_count'] > 0) ? round(($stats['completed_count'] / $stats['total_count']) * 100, 1) : 0;
$pending_count = (int)($stats['pending_count'] ?? 0);
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('payments_management', 'Payments Management') ?> - HeroTrade Admin</title>
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
            --purple: #8B5CF6;
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
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            z-index: 999;
            backdrop-filter: blur(4px);
        }

        .sidebar-overlay.active { display: block; }

        .hamburger {
            display: none;
            position: fixed;
            top: 16px;
            <?= $sidebar_side ?>: 16px;
            z-index: 1001;
            background: var(--secondary);
            border: 1px solid var(--border);
            color: var(--text);
            width: 48px; height: 48px;
            border-radius: 12px;
            font-size: 20px;
            cursor: pointer;
            align-items: center;
            justify-content: center;
        }

        .logo {
            padding: 24px 20px;
            border-bottom: 1px solid var(--border);
        }

        .logo-content {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            width: 44px; height: 44px;
            background: linear-gradient(135deg, #DC2626, var(--accent));
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; color: white; flex-shrink: 0;
        }

        .logo-text {
            font-size: 20px; font-weight: 900;
            background: linear-gradient(135deg, #DC2626, var(--accent));
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .logo-subtitle {
            font-size: 10px; text-transform: uppercase;
            letter-spacing: 2px; color: var(--text-muted); font-weight: 600;
        }

        .lang-switcher {
            display: flex; gap: 6px;
            padding: 12px 20px;
            border-bottom: 1px solid var(--border);
        }

        .lang-btn {
            flex: 1; padding: 8px 12px;
            border: 1px solid var(--border); border-radius: 8px;
            background: transparent; color: var(--text-muted);
            font-size: 13px; font-weight: 600; cursor: pointer;
            transition: all 0.3s; font-family: inherit; text-align: center;
        }

        .lang-btn.active {
            background: linear-gradient(135deg, #DC2626, var(--accent));
            color: white; border-color: transparent;
        }

        .lang-btn:hover:not(.active) { border-color: var(--accent); color: var(--text); }

        .admin-card {
            margin: 16px 20px; padding: 16px;
            background: rgba(220, 38, 38, 0.05);
            border: 1px solid rgba(220, 38, 38, 0.1);
            border-radius: 14px;
        }

        .admin-info { display: flex; align-items: center; gap: 12px; }

        .admin-avatar {
            width: 44px; height: 44px;
            background: linear-gradient(135deg, #DC2626, var(--accent));
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; font-weight: 700; color: white; flex-shrink: 0;
        }

        .admin-name { font-weight: 700; font-size: 15px; }
        .admin-role {
            font-size: 11px; color: var(--accent); font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.5px;
        }

        .nav-menu { padding: 12px 0 24px; }

        .nav-section-title {
            padding: 16px 20px 8px; font-size: 10px; font-weight: 700;
            color: var(--text-muted); text-transform: uppercase; letter-spacing: 1.5px;
        }

        .nav-item {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 20px; color: var(--text-muted);
            text-decoration: none; transition: all 0.3s;
            position: relative; font-size: 14px; font-weight: 500;
        }

        .nav-item:hover, .nav-item.active {
            color: var(--text);
            background: rgba(220, 38, 38, 0.06);
        }

        .nav-item.active::before {
            content: ''; position: absolute;
            <?= $sidebar_side ?>: 0; top: 0;
            height: 100%; width: 3px;
            background: linear-gradient(180deg, #DC2626, var(--accent));
            border-radius: 0 3px 3px 0;
        }

        .nav-item i { width: 20px; text-align: center; font-size: 16px; }

        .nav-badge {
            margin-<?= $sidebar_opposite ?>: auto;
            background: var(--danger); color: white;
            font-size: 10px; padding: 2px 8px;
            border-radius: 10px; font-weight: 700;
            min-width: 20px; text-align: center;
        }

        .nav-item.logout-link { color: var(--danger); margin-top: 8px; }
        .nav-item.logout-link:hover { background: rgba(239, 68, 68, 0.08); }

        /* ====== MAIN ====== */
        .main {
            margin-<?= $sidebar_side ?>: var(--sidebar-width);
            padding: 32px;
            min-height: 100vh;
        }

        .header {
            display: flex; justify-content: space-between; align-items: flex-start;
            margin-bottom: 32px; flex-wrap: wrap; gap: 16px;
        }

        .page-title {
            font-size: 30px; font-weight: 900;
            background: linear-gradient(135deg, var(--text), var(--accent));
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text; line-height: 1.2;
        }

        .page-subtitle { color: var(--text-muted); font-size: 14px; margin-top: 4px; }

        .breadcrumb {
            display: flex; align-items: center; gap: 8px;
            font-size: 13px; color: var(--text-muted); margin-top: 8px;
        }
        .breadcrumb a { color: var(--text-muted); text-decoration: none; }
        .breadcrumb a:hover { color: var(--accent); }

        .header-date {
            font-size: 13px; color: var(--text-muted);
            display: flex; align-items: center; gap: 8px;
            background: var(--secondary); padding: 10px 16px;
            border-radius: 10px; border: 1px solid var(--border);
        }

        /* ====== STATS CARDS ====== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px; margin-bottom: 32px;
        }

        .stat-card {
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: 16px; padding: 24px;
            position: relative; overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.3);
        }

        .stat-card::after {
            content: ''; position: absolute;
            top: 0; <?= $sidebar_opposite ?>: 0;
            width: 120px; height: 120px;
            border-radius: 50%; transform: translate(40%, -40%);
            opacity: 0.08;
        }

        .stat-card.card-volume::after { background: var(--info); }
        .stat-card.card-today::after { background: var(--success); }
        .stat-card.card-pending::after { background: var(--warning); }
        .stat-card.card-rate::after { background: var(--purple); }

        .stat-header {
            display: flex; justify-content: space-between;
            align-items: flex-start; margin-bottom: 16px;
        }

        .stat-icon {
            width: 52px; height: 52px; border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px;
        }

        .stat-icon.icon-volume { background: rgba(59, 130, 246, 0.12); color: var(--info); }
        .stat-icon.icon-today { background: rgba(16, 185, 129, 0.12); color: var(--success); }
        .stat-icon.icon-pending { background: rgba(245, 158, 11, 0.12); color: var(--warning); }
        .stat-icon.icon-rate { background: rgba(139, 92, 246, 0.12); color: var(--purple); }

        .stat-value {
            font-size: 28px; font-weight: 900;
            color: var(--text); margin-bottom: 4px; line-height: 1.1;
        }

        .stat-label {
            font-size: 13px; color: var(--text-muted); font-weight: 500;
        }

        .stat-trend {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: 11px; font-weight: 700;
            padding: 4px 10px; border-radius: 20px;
        }

        .stat-trend.alert {
            background: rgba(239, 68, 68, 0.1); color: var(--danger);
        }

        /* ====== TABS ====== */
        .tabs {
            display: flex; gap: 4px; margin-bottom: 24px;
            background: var(--secondary); border-radius: 12px;
            padding: 6px; border: 1px solid var(--border);
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 10px 20px; background: none; border: none;
            color: var(--text-muted); font-weight: 600; cursor: pointer;
            transition: all 0.3s; border-radius: 8px;
            font-family: inherit; text-decoration: none; font-size: 14px;
            display: inline-flex; align-items: center; gap: 8px;
        }

        .tab-btn:hover { color: var(--text); background: rgba(255, 255, 255, 0.05); }

        .tab-btn.active {
            background: linear-gradient(135deg, #DC2626, var(--accent));
            color: white;
        }

        .tab-count {
            font-size: 11px; font-weight: 700;
            padding: 2px 8px; border-radius: 10px;
            background: rgba(255, 255, 255, 0.15);
        }

        .tab-btn:not(.active) .tab-count {
            background: rgba(148, 163, 184, 0.15);
        }

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

        .search-wrapper { position: relative; }

        .search-wrapper i {
            position: absolute; top: 50%; transform: translateY(-50%);
            <?= $sidebar_side ?>: 14px; color: var(--text-muted); font-size: 14px;
        }

        .search-wrapper input { padding-<?= $sidebar_side ?>: 40px; }

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
        .transactions-card {
            background: var(--secondary); border: 1px solid var(--border);
            border-radius: 16px; overflow: hidden;
        }

        .transactions-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 12px;
        }

        .transactions-header h3 { font-size: 18px; font-weight: 700; }

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

        tr.pending-row { border-<?= $sidebar_side ?>: 3px solid var(--warning); }

        .user-cell { display: flex; align-items: center; gap: 12px; }

        .user-avatar-sm {
            width: 36px; height: 36px;
            background: linear-gradient(135deg, #DC2626, var(--accent));
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; color: white; font-size: 13px; flex-shrink: 0;
        }

        .user-name { font-weight: 600; font-size: 14px; }
        .user-email { font-size: 12px; color: var(--text-muted); }

        /* ====== BADGES ====== */
        .type-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 12px; border-radius: 8px;
            font-size: 12px; font-weight: 700;
        }

        .type-badge.deposit {
            background: rgba(16, 185, 129, 0.1); color: var(--success);
        }

        .type-badge.withdrawal {
            background: rgba(239, 68, 68, 0.1); color: var(--danger);
        }

        .type-badge.bonus {
            background: rgba(139, 92, 246, 0.1); color: var(--purple);
        }

        .status-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 12px; border-radius: 8px;
            font-size: 12px; font-weight: 700;
        }

        .status-badge.pending { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .status-badge.completed { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .status-badge.failed, .status-badge.rejected { background: rgba(239, 68, 68, 0.1); color: var(--danger); }

        .amount-cell { font-weight: 700; font-size: 14px; }
        .fee-cell { font-size: 13px; color: var(--text-muted); }
        .date-cell { font-size: 13px; color: var(--text-muted); }

        .actions-cell { display: flex; gap: 6px; }

        /* ====== BUTTONS ====== */
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 8px 16px; border: none; border-radius: 8px;
            font-weight: 600; font-size: 13px; cursor: pointer;
            transition: all 0.3s; text-decoration: none;
            font-family: inherit; white-space: nowrap;
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

        .btn-sm { padding: 6px 12px; font-size: 12px; }

        .btn-success { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .btn-success:hover { background: var(--success); color: white; }

        .btn-danger { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
        .btn-danger:hover { background: var(--danger); color: white; }

        .btn-info { background: rgba(59, 130, 246, 0.1); color: var(--info); }
        .btn-info:hover { background: var(--info); color: white; }

        .btn-icon { padding: 8px; width: 34px; height: 34px; justify-content: center; }

        /* ====== PAGINATION ====== */
        .pagination-wrapper {
            padding: 20px 24px;
            border-top: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 12px;
        }

        .pagination-info { font-size: 13px; color: var(--text-muted); }

        .pagination { display: flex; align-items: center; gap: 6px; }

        .pagination-btn {
            padding: 8px 14px; background: var(--primary);
            border: 1px solid var(--border); border-radius: 8px;
            color: var(--text); text-decoration: none;
            font-weight: 600; font-size: 13px; transition: all 0.3s;
        }

        .pagination-btn:hover:not(.disabled):not(.active) {
            background: linear-gradient(135deg, #DC2626, var(--accent));
            color: white; border-color: transparent;
        }

        .pagination-btn.active {
            background: linear-gradient(135deg, #DC2626, var(--accent));
            color: white; border-color: transparent;
        }

        .pagination-btn.disabled { opacity: 0.3; cursor: not-allowed; pointer-events: none; }

        /* ====== MODAL ====== */
        .modal {
            display: none; position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.8);
            z-index: 2000;
            align-items: center; justify-content: center;
        }

        .modal.active { display: flex; }

        .modal-content {
            background: var(--secondary); border: 1px solid var(--border);
            border-radius: 16px; padding: 32px;
            max-width: 600px; width: 92%;
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

        .modal-title { font-size: 20px; font-weight: 900; }

        .modal-close {
            background: none; border: none; color: var(--text-muted);
            font-size: 24px; cursor: pointer; padding: 4px;
            line-height: 1;
        }
        .modal-close:hover { color: var(--text); }

        .form-group { margin-bottom: 18px; }

        .form-label {
            display: block; font-size: 14px; font-weight: 600;
            color: var(--text-muted); margin-bottom: 8px;
        }

        .form-input, .form-textarea, .form-select {
            width: 100%; padding: 12px 16px;
            background: var(--primary); border: 1px solid var(--border);
            border-radius: 10px; color: var(--text);
            font-size: 14px; font-family: inherit; transition: border-color 0.3s;
        }

        .form-input:focus, .form-textarea:focus, .form-select:focus {
            outline: none; border-color: var(--accent);
        }

        .form-textarea { resize: vertical; min-height: 80px; }
        .form-select option { background: var(--secondary); color: var(--text); }

        .modal-actions {
            display: flex; gap: 12px; justify-content: flex-end;
            margin-top: 24px; padding-top: 20px;
            border-top: 1px solid var(--border);
        }

        /* ====== APPROVE MODAL EXTRAS ====== */
        .tx-detail-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
            margin-bottom: 20px;
        }

        .tx-detail-item {
            padding: 14px; background: var(--primary);
            border-radius: 10px; border: 1px solid var(--border);
        }

        .tx-detail-item.full-width { grid-column: 1 / -1; }

        .tx-detail-label { font-size: 12px; color: var(--text-muted); margin-bottom: 4px; }
        .tx-detail-value { font-size: 15px; font-weight: 600; word-break: break-all; }

        .bonus-section {
            background: rgba(139, 92, 246, 0.05);
            border: 1px solid rgba(139, 92, 246, 0.15);
            border-radius: 12px; padding: 16px; margin-bottom: 18px;
        }

        .bonus-section-title {
            font-size: 14px; font-weight: 700; color: var(--purple);
            margin-bottom: 12px; display: flex; align-items: center; gap: 8px;
        }

        /* ====== REJECT MODAL EXTRAS ====== */
        .reason-chips {
            display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px;
        }

        .reason-chip {
            padding: 8px 14px; background: var(--primary);
            border: 1px solid var(--border); border-radius: 8px;
            color: var(--text-muted); font-size: 13px; cursor: pointer;
            transition: all 0.3s; font-family: inherit;
        }

        .reason-chip:hover, .reason-chip.active {
            border-color: var(--danger); color: var(--danger);
            background: rgba(239, 68, 68, 0.05);
        }

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
            animation: toastIn 0.3s ease; min-width: 280px;
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
        .empty-state i { font-size: 48px; margin-bottom: 16px; opacity: 0.3; display: block; }
        .empty-state p { font-size: 16px; }

        /* ====== LOADING ====== */
        .btn-loading { pointer-events: none; opacity: 0.7; }
        .btn-loading::after {
            content: ''; width: 14px; height: 14px;
            border: 2px solid transparent; border-top-color: currentColor;
            border-radius: 50%; animation: spin 0.6s linear infinite;
            display: inline-block; margin-<?= $sidebar_side ?>: 6px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ====== RESPONSIVE ====== */
        @media (max-width: 1400px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 768px) {
            .hamburger { display: flex; }

            .sidebar {
                transform: translateX(<?= $rtl ? '100%' : '-100%' ?>);
            }

            .sidebar.open { transform: translateX(0); }

            .main {
                margin-<?= $sidebar_side ?>: 0;
                padding: 80px 16px 24px;
            }

            .stats-grid { grid-template-columns: 1fr; }
            .header { flex-direction: column; }
            .page-title { font-size: 24px; }
            .tabs { flex-direction: column; }

            table { min-width: 1000px; }

            .modal-content { width: 96%; padding: 20px; max-height: 95vh; }
            .tx-detail-grid { grid-template-columns: 1fr; }

            .filters-row { flex-direction: column; }
            .filter-group { min-width: 100%; }

            .pagination-wrapper { justify-content: center; text-align: center; }
        }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
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
                <div class="logo-text">HeroTrade Admin</div>
                <div class="logo-subtitle"><?= t('admin_panel', 'Admin Panel') ?></div>
            </div>
        </div>
    </div>

    <div class="lang-switcher">
        <button class="lang-btn <?= $lang === 'ar' ? 'active' : '' ?>" onclick="changeLanguage('ar')">العربية</button>
        <button class="lang-btn <?= $lang === 'en' ? 'active' : '' ?>" onclick="changeLanguage('en')">English</button>
    </div>

    <div class="admin-card">
        <div class="admin-info">
            <div class="admin-avatar"><i class="fas fa-user-shield"></i></div>
            <div>
                <div class="admin-name"><?= htmlspecialchars($admin['username'] ?? 'Admin') ?></div>
                <div class="admin-role">Super Admin</div>
            </div>
        </div>
    </div>

    <nav class="nav-menu">
        <div class="nav-section-title"><?= $rtl ? 'الإدارة' : 'Management' ?></div>

        <a href="index.php" class="nav-item">
            <i class="fas fa-chart-pie"></i>
            <span><?= t('dashboard', 'Dashboard') ?></span>
        </a>
        <a href="users.php" class="nav-item">
            <i class="fas fa-users"></i>
            <span><?= t('users_management', 'Users') ?></span>
        </a>
        <a href="payments.php" class="nav-item active">
            <i class="fas fa-credit-card"></i>
            <span><?= t('payments_management', 'Payments') ?></span>
            <?php if ($pending_count > 0): ?>
                <span class="nav-badge"><?= $pending_count ?></span>
            <?php endif; ?>
        </a>
        <a href="trades.php" class="nav-item">
            <i class="fas fa-exchange-alt"></i>
            <span><?= t('trades_management', 'Trades') ?></span>
        </a>
        <a href="payment-gateways.php" class="nav-item">
            <i class="fas fa-wallet"></i>
            <span><?= t('payment_gateways', 'Gateways') ?></span>
        </a>
        <a href="reports.php" class="nav-item">
            <i class="fas fa-chart-bar"></i>
            <span><?= t('reports', 'Reports') ?></span>
        </a>
        <a href="settings.php" class="nav-item">
            <i class="fas fa-cog"></i>
            <span><?= t('settings', 'Settings') ?></span>
        </a>

        <div class="nav-section-title"><?= $rtl ? 'أخرى' : 'Other' ?></div>

        <a href="../logout.php" class="nav-item logout-link">
            <i class="fas fa-sign-out-alt"></i>
            <span><?= t('logout', 'Logout') ?></span>
        </a>
    </nav>
</aside>

<!-- Main Content -->
<main class="main">
    <!-- Header -->
    <div class="header">
        <div>
            <h1 class="page-title"><?= t('payments_management', 'Payments Management') ?></h1>
            <p class="page-subtitle"><?= $rtl ? 'إدارة ومراجعة جميع المعاملات المالية' : 'Manage and review all financial transactions' ?></p>
            <div class="breadcrumb">
                <a href="index.php"><?= t('dashboard', 'Dashboard') ?></a>
                <i class="fas <?= $chevron_next ?>" style="font-size: 10px;"></i>
                <span><?= t('payments', 'Payments') ?></span>
            </div>
        </div>
        <div class="header-date">
            <i class="fas fa-calendar-alt"></i>
            <?= date('Y-m-d / h:i A') ?>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card card-volume">
            <div class="stat-header">
                <div class="stat-icon icon-volume"><i class="fas fa-chart-line"></i></div>
            </div>
            <div class="stat-value">$<?= number_format($stats['total_volume'] ?? 0, 2) ?></div>
            <div class="stat-label"><?= $rtl ? 'إجمالي الحجم' : 'Total Volume' ?></div>
        </div>

        <div class="stat-card card-today">
            <div class="stat-header">
                <div class="stat-icon icon-today"><i class="fas fa-calendar-day"></i></div>
            </div>
            <div class="stat-value">$<?= number_format($stats['today_volume'] ?? 0, 2) ?></div>
            <div class="stat-label"><?= $rtl ? 'حجم اليوم' : "Today's Volume" ?></div>
        </div>

        <div class="stat-card card-pending">
            <div class="stat-header">
                <div class="stat-icon icon-pending"><i class="fas fa-clock"></i></div>
                <?php if ($pending_count > 0): ?>
                    <div class="stat-trend alert">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= $rtl ? 'يتطلب إجراء' : 'Action Required' ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="stat-value"><?= number_format($pending_count) ?></div>
            <div class="stat-label"><?= $rtl ? 'معاملات معلقة' : 'Pending Count' ?></div>
        </div>

        <div class="stat-card card-rate">
            <div class="stat-header">
                <div class="stat-icon icon-rate"><i class="fas fa-percentage"></i></div>
            </div>
            <div class="stat-value"><?= $success_rate ?>%</div>
            <div class="stat-label"><?= $rtl ? 'نسبة النجاح' : 'Success Rate' ?></div>
        </div>
    </div>

    <!-- Status Tabs -->
    <div class="tabs">
        <?php
            $tab_items = [
                'all' => [$rtl ? 'الكل' : 'All', $stats['total_count'] ?? 0, 'fa-list'],
                'pending' => [$rtl ? 'معلقة' : 'Pending', $stats['pending_count'] ?? 0, 'fa-clock'],
                'completed' => [$rtl ? 'مكتملة' : 'Completed', $stats['completed_count'] ?? 0, 'fa-check-circle'],
                'rejected' => [$rtl ? 'مرفوضة' : 'Rejected', $stats['rejected_count'] ?? 0, 'fa-times-circle'],
            ];
            $current_query = [];
            if ($type_filter) $current_query['type'] = $type_filter;
            if ($search) $current_query['search'] = $search;
        ?>
        <?php foreach ($tab_items as $tab_key => $tab_data): ?>
            <?php
                $tab_query = $current_query;
                $tab_query['status'] = $tab_key;
                $tab_url = '?' . http_build_query($tab_query);
            ?>
            <a href="<?= $tab_url ?>" class="tab-btn <?= $status_filter === $tab_key ? 'active' : '' ?>">
                <i class="fas <?= $tab_data[2] ?>"></i>
                <?= $tab_data[0] ?>
                <span class="tab-count"><?= number_format($tab_data[1]) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <div class="filters-section">
        <form method="GET" action="payments.php" id="filterForm">
            <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
            <div class="filters-row">
                <div class="filter-group" style="flex: 2;">
                    <label class="filter-label"><?= $rtl ? 'بحث' : 'Search' ?></label>
                    <div class="search-wrapper">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" class="filter-input" placeholder="<?= $rtl ? 'اسم المستخدم أو البريد الإلكتروني...' : 'Username or email...' ?>" value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>

                <div class="filter-group">
                    <label class="filter-label"><?= $rtl ? 'النوع' : 'Type' ?></label>
                    <select name="type" class="filter-select" onchange="this.form.submit()">
                        <option value="" <?= $type_filter === '' ? 'selected' : '' ?>><?= $rtl ? 'الكل' : 'All Types' ?></option>
                        <option value="deposit" <?= $type_filter === 'deposit' ? 'selected' : '' ?>><?= $rtl ? 'إيداع' : 'Deposit' ?></option>
                        <option value="withdrawal" <?= $type_filter === 'withdrawal' ? 'selected' : '' ?>><?= $rtl ? 'سحب' : 'Withdrawal' ?></option>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-label" style="opacity: 0;">&nbsp;</label>
                    <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;">
                        <i class="fas fa-search"></i>
                        <?= $rtl ? 'بحث' : 'Search' ?>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Transactions Table -->
    <div class="transactions-card">
        <div class="transactions-header">
            <h3>
                <i class="fas fa-credit-card" style="color: var(--accent); margin-<?= $sidebar_opposite ?>: 8px;"></i>
                <?= $rtl ? 'المعاملات' : 'Transactions' ?> (<?= number_format($total_records) ?>)
            </h3>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th><?= $rtl ? 'المستخدم' : 'User' ?></th>
                        <th><?= $rtl ? 'النوع' : 'Type' ?></th>
                        <th><?= $rtl ? 'البوابة' : 'Gateway' ?></th>
                        <th><?= $rtl ? 'المبلغ' : 'Amount' ?></th>
                        <th><?= $rtl ? 'الرسوم' : 'Fee' ?></th>
                        <th><?= $rtl ? 'الحالة' : 'Status' ?></th>
                        <th><?= $rtl ? 'التاريخ' : 'Date' ?></th>
                        <th><?= $rtl ? 'الإجراءات' : 'Actions' ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="9">
                                <div class="empty-state">
                                    <i class="fas fa-receipt"></i>
                                    <p><?= $rtl ? 'لا توجد معاملات' : 'No transactions found' ?></p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $tx): ?>
                            <?php
                                $is_pending = ($tx['status'] === 'pending');
                                $type_class = $tx['type'] === 'deposit' ? 'deposit' : ($tx['type'] === 'withdrawal' ? 'withdrawal' : 'bonus');
                                $type_label = $rtl
                                    ? ($tx['type'] === 'deposit' ? 'إيداع' : ($tx['type'] === 'withdrawal' ? 'سحب' : 'مكافأة'))
                                    : ucfirst($tx['type']);
                                $type_icon = $tx['type'] === 'deposit' ? 'fa-arrow-down' : ($tx['type'] === 'withdrawal' ? 'fa-arrow-up' : 'fa-gift');

                                $status_class = $tx['status'];
                                if ($tx['status'] === 'rejected') $status_class = 'rejected';
                                $status_label = $rtl
                                    ? ($tx['status'] === 'pending' ? 'معلق' : ($tx['status'] === 'completed' ? 'مكتمل' : 'مرفوض'))
                                    : ucfirst($tx['status'] === 'failed' ? 'Rejected' : $tx['status']);
                                $status_icon = $tx['status'] === 'pending' ? 'fa-clock' : ($tx['status'] === 'completed' ? 'fa-check-circle' : 'fa-times-circle');

                                $avatar_letters = mb_strtoupper(mb_substr($tx['username'] ?? 'U', 0, 2));
                                $gateway_display = $rtl
                                    ? htmlspecialchars($tx['gateway_display_ar'] ?? $tx['gateway_name'] ?? 'N/A')
                                    : htmlspecialchars($tx['gateway_display_en'] ?? $tx['gateway_name'] ?? 'N/A');
                            ?>
                            <tr id="txRow-<?= $tx['id'] ?>" class="<?= $is_pending ? 'pending-row' : '' ?>">
                                <td style="font-weight: 600; color: var(--text-muted);">#<?= $tx['id'] ?></td>
                                <td>
                                    <div class="user-cell">
                                        <div class="user-avatar-sm"><?= $avatar_letters ?></div>
                                        <div>
                                            <div class="user-name"><?= htmlspecialchars($tx['username'] ?? 'N/A') ?></div>
                                            <div class="user-email"><?= htmlspecialchars($tx['email'] ?? '') ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="type-badge <?= $type_class ?>">
                                        <i class="fas <?= $type_icon ?>"></i>
                                        <?= $type_label ?>
                                    </span>
                                </td>
                                <td style="font-size: 13px;"><?= $gateway_display ?></td>
                                <td class="amount-cell">$<?= number_format($tx['amount'] ?? 0, 2) ?></td>
                                <td class="fee-cell">$<?= number_format($tx['fee'] ?? 0, 2) ?></td>
                                <td>
                                    <span class="status-badge <?= $status_class ?>">
                                        <i class="fas <?= $status_icon ?>"></i>
                                        <?= $status_label ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="date-cell">
                                        <?= date('Y-m-d', strtotime($tx['created_at'])) ?><br>
                                        <span style="font-size: 11px;"><?= date('h:i A', strtotime($tx['created_at'])) ?></span>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($is_pending): ?>
                                        <div class="actions-cell">
                                            <button class="btn btn-success btn-sm" onclick="openApproveModal(<?= $tx['id'] ?>)" title="<?= $rtl ? 'موافقة' : 'Approve' ?>">
                                                <i class="fas fa-check"></i>
                                                <?= $rtl ? 'موافقة' : 'Approve' ?>
                                            </button>
                                            <button class="btn btn-danger btn-sm" onclick="openRejectModal(<?= $tx['id'] ?>)" title="<?= $rtl ? 'رفض' : 'Reject' ?>">
                                                <i class="fas fa-times"></i>
                                                <?= $rtl ? 'رفض' : 'Reject' ?>
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span style="font-size: 12px; color: var(--text-muted);">
                                            <i class="fas fa-check-double"></i>
                                            <?= $rtl ? 'تمت المعالجة' : 'Processed' ?>
                                        </span>
                                    <?php endif; ?>
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
                    <?= $rtl ? 'عرض' : 'Showing' ?> <?= $offset + 1 ?>-<?= min($offset + $per_page, $total_records) ?>
                    <?= $rtl ? 'من' : 'of' ?> <?= number_format($total_records) ?> <?= $rtl ? 'معاملة' : 'transactions' ?>
                </div>
                <div class="pagination">
                    <?php
                        $query_base = http_build_query(array_filter([
                            'status' => $status_filter !== 'all' ? $status_filter : null,
                            'type' => $type_filter ?: null,
                            'search' => $search ?: null,
                        ]));
                        $q_sep = $query_base ? "&{$query_base}" : '';
                    ?>

                    <a href="?page=1<?= $q_sep ?>" class="pagination-btn <?= $page <= 1 ? 'disabled' : '' ?>">
                        <i class="fas fa-angle-double-<?= $rtl ? 'right' : 'left' ?>"></i>
                    </a>
                    <a href="?page=<?= max(1, $page - 1) ?><?= $q_sep ?>" class="pagination-btn <?= $page <= 1 ? 'disabled' : '' ?>">
                        <i class="fas fa-chevron-<?= $rtl ? 'right' : 'left' ?>"></i>
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
                        <i class="fas fa-chevron-<?= $rtl ? 'left' : 'right' ?>"></i>
                    </a>
                    <a href="?page=<?= $total_pages ?><?= $q_sep ?>" class="pagination-btn <?= $page >= $total_pages ? 'disabled' : '' ?>">
                        <i class="fas fa-angle-double-<?= $rtl ? 'left' : 'right' ?>"></i>
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<!-- ==================== APPROVE MODAL ==================== -->
<div id="approveModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">
                <i class="fas fa-check-circle" style="color: var(--success);"></i>
                <?= $rtl ? 'الموافقة على المعاملة' : 'Approve Transaction' ?>
            </h2>
            <button class="modal-close" onclick="closeModal('approveModal')">&times;</button>
        </div>

        <!-- Transaction Details -->
        <div id="approveDetails">
            <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                <i class="fas fa-spinner fa-spin" style="font-size: 24px;"></i>
            </div>
        </div>

        <!-- Bonus Section -->
        <div class="bonus-section">
            <div class="bonus-section-title">
                <i class="fas fa-gift"></i>
                <?= $rtl ? 'إضافة مكافأة (اختياري)' : 'Add Bonus (Optional)' ?>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <input type="number" id="approve_bonus" class="form-input" step="0.01" min="0" value="0" placeholder="<?= $rtl ? 'مبلغ المكافأة' : 'Bonus amount' ?>">
            </div>
        </div>

        <!-- Admin Note -->
        <div class="form-group">
            <label class="form-label"><?= $rtl ? 'ملاحظة الأدمن (اختياري)' : 'Admin Note (Optional)' ?></label>
            <textarea id="approve_notes" class="form-textarea" placeholder="<?= $rtl ? 'أضف ملاحظة...' : 'Add a note...' ?>"></textarea>
        </div>

        <input type="hidden" id="approve_transaction_id">

        <div class="modal-actions">
            <button type="button" class="btn btn-secondary" onclick="closeModal('approveModal')">
                <?= $rtl ? 'إلغاء' : 'Cancel' ?>
            </button>
            <button type="button" class="btn btn-success" id="approveBtn" onclick="submitApprove()">
                <i class="fas fa-check"></i>
                <?= $rtl ? 'موافقة' : 'Approve' ?>
            </button>
        </div>
    </div>
</div>

<!-- ==================== REJECT MODAL ==================== -->
<div id="rejectModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">
                <i class="fas fa-times-circle" style="color: var(--danger);"></i>
                <?= $rtl ? 'رفض المعاملة' : 'Reject Transaction' ?>
            </h2>
            <button class="modal-close" onclick="closeModal('rejectModal')">&times;</button>
        </div>

        <!-- Predefined Reasons -->
        <div class="form-group">
            <label class="form-label"><?= $rtl ? 'سبب سريع' : 'Quick Reason' ?></label>
            <div class="reason-chips">
                <?php
                    $reasons = $rtl ? [
                        'معلومات الدفع غير صحيحة',
                        'مبلغ غير متطابق',
                        'إثبات الدفع غير واضح',
                        'حساب مشبوه',
                        'تجاوز الحد المسموح',
                        'بوابة الدفع معطلة',
                    ] : [
                        'Invalid payment details',
                        'Amount mismatch',
                        'Unclear proof of payment',
                        'Suspicious account activity',
                        'Exceeds allowed limit',
                        'Payment gateway disabled',
                    ];
                ?>
                <?php foreach ($reasons as $reason): ?>
                    <button type="button" class="reason-chip" onclick="selectReason(this, '<?= htmlspecialchars($reason, ENT_QUOTES) ?>')">
                        <?= htmlspecialchars($reason) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Custom Reason -->
        <div class="form-group">
            <label class="form-label"><?= $rtl ? 'سبب الرفض' : 'Rejection Reason' ?></label>
            <textarea id="reject_reason" class="form-textarea" placeholder="<?= $rtl ? 'أدخل سبب الرفض...' : 'Enter rejection reason...' ?>" required></textarea>
        </div>

        <input type="hidden" id="reject_transaction_id">

        <div class="modal-actions">
            <button type="button" class="btn btn-secondary" onclick="closeModal('rejectModal')">
                <?= $rtl ? 'إلغاء' : 'Cancel' ?>
            </button>
            <button type="button" class="btn btn-danger" id="rejectBtn" onclick="submitReject()">
                <i class="fas fa-times"></i>
                <?= $rtl ? 'تأكيد الرفض' : 'Confirm Reject' ?>
            </button>
        </div>
    </div>
</div>

<script>
    const CSRF_TOKEN = '<?= $csrf_token ?>';
    const IS_RTL = <?= $rtl ? 'true' : 'false' ?>;

    // ====== TOAST ======
    function showToast(message, type) {
        type = type || 'success';
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = 'toast toast-' + type;
        toast.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : 'exclamation-circle') + '"></i> ' + escHtml(message);
        container.appendChild(toast);
        setTimeout(function() {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-10px)';
            setTimeout(function() { toast.remove(); }, 300);
        }, 3500);
    }

    // ====== AJAX HELPER ======
    function ajaxPost(data) {
        data.csrf_token = CSRF_TOKEN;
        var body = new URLSearchParams(data).toString();
        return fetch('payments.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body
        }).then(function(r) { return r.json(); });
    }

    // ====== SIDEBAR ======
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('sidebarOverlay').classList.toggle('active');
        var icon = document.querySelector('#hamburgerBtn i');
        if (document.getElementById('sidebar').classList.contains('open')) {
            icon.classList.remove('fa-bars');
            icon.classList.add('fa-times');
        } else {
            icon.classList.remove('fa-times');
            icon.classList.add('fa-bars');
        }
    }

    // ====== LANGUAGE SWITCHER ======
    function changeLanguage(lang) {
        document.cookie = 'lang=' + lang + ';path=/;max-age=' + (86400 * 365);
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '../api/set_language.php?lang=' + lang, true);
        xhr.onload = function() { window.location.reload(); };
        xhr.onerror = function() { window.location.reload(); };
        xhr.send();
    }

    // ====== MODAL HELPERS ======
    function openModal(id) { document.getElementById(id).classList.add('active'); }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }

    // Close modal on outside click
    document.querySelectorAll('.modal').forEach(function(modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) closeModal(this.id);
        });
    });

    // Close on Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal.active').forEach(function(m) { closeModal(m.id); });
        }
    });

    // ====== HTML ESCAPE ======
    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ====== APPROVE MODAL ======
    function openApproveModal(txId) {
        document.getElementById('approve_transaction_id').value = txId;
        document.getElementById('approve_bonus').value = '0';
        document.getElementById('approve_notes').value = '';
        document.getElementById('approveDetails').innerHTML = '<div style="text-align:center;padding:40px;color:var(--text-muted);"><i class="fas fa-spinner fa-spin" style="font-size:24px;"></i></div>';
        openModal('approveModal');

        // Fetch transaction details
        ajaxPost({action: 'get_transaction', transaction_id: txId}).then(function(data) {
            if (data.success) {
                var tx = data.transaction;
                var typeLabel = IS_RTL
                    ? (tx.type === 'deposit' ? 'إيداع' : 'سحب')
                    : (tx.type.charAt(0).toUpperCase() + tx.type.slice(1));
                var typeColor = tx.type === 'deposit' ? 'var(--success)' : 'var(--danger)';

                document.getElementById('approveDetails').innerHTML =
                    '<div class="tx-detail-grid">' +
                        '<div class="tx-detail-item">' +
                            '<div class="tx-detail-label">' + (IS_RTL ? 'المستخدم' : 'User') + '</div>' +
                            '<div class="tx-detail-value">' + escHtml(tx.username) + '</div>' +
                        '</div>' +
                        '<div class="tx-detail-item">' +
                            '<div class="tx-detail-label">' + (IS_RTL ? 'البريد' : 'Email') + '</div>' +
                            '<div class="tx-detail-value">' + escHtml(tx.email) + '</div>' +
                        '</div>' +
                        '<div class="tx-detail-item">' +
                            '<div class="tx-detail-label">' + (IS_RTL ? 'النوع' : 'Type') + '</div>' +
                            '<div class="tx-detail-value" style="color:' + typeColor + ';">' + typeLabel + '</div>' +
                        '</div>' +
                        '<div class="tx-detail-item">' +
                            '<div class="tx-detail-label">' + (IS_RTL ? 'المبلغ' : 'Amount') + '</div>' +
                            '<div class="tx-detail-value" style="font-size:20px;color:var(--success);">$' + parseFloat(tx.amount).toFixed(2) + '</div>' +
                        '</div>' +
                        '<div class="tx-detail-item">' +
                            '<div class="tx-detail-label">' + (IS_RTL ? 'البوابة' : 'Gateway') + '</div>' +
                            '<div class="tx-detail-value">' + escHtml(tx.gateway_name || 'N/A') + '</div>' +
                        '</div>' +
                        '<div class="tx-detail-item">' +
                            '<div class="tx-detail-label">' + (IS_RTL ? 'رصيد المستخدم الحالي' : 'Current User Balance') + '</div>' +
                            '<div class="tx-detail-value">$' + parseFloat(tx.user_balance || 0).toFixed(2) + '</div>' +
                        '</div>' +
                    '</div>';
            } else {
                document.getElementById('approveDetails').innerHTML = '<p style="text-align:center;color:var(--danger);">' + (data.message || 'Error') + '</p>';
            }
        }).catch(function() {
            document.getElementById('approveDetails').innerHTML = '<p style="text-align:center;color:var(--danger);">' + (IS_RTL ? 'حدث خطأ' : 'An error occurred') + '</p>';
        });
    }

    function submitApprove() {
        var txId = document.getElementById('approve_transaction_id').value;
        var bonus = parseFloat(document.getElementById('approve_bonus').value) || 0;
        var notes = document.getElementById('approve_notes').value;
        var btn = document.getElementById('approveBtn');

        btn.classList.add('btn-loading');
        btn.disabled = true;

        var action = bonus > 0 ? 'approve_bonus' : 'approve';
        var postData = {
            action: action,
            transaction_id: txId,
            admin_notes: notes
        };
        if (bonus > 0) {
            postData.bonus_amount = bonus;
        }

        ajaxPost(postData).then(function(data) {
            btn.classList.remove('btn-loading');
            btn.disabled = false;
            if (data.success) {
                showToast(data.message || (IS_RTL ? 'تمت الموافقة بنجاح' : 'Approved successfully'));
                closeModal('approveModal');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showToast(data.message || (IS_RTL ? 'حدث خطأ' : 'An error occurred'), 'error');
            }
        }).catch(function() {
            btn.classList.remove('btn-loading');
            btn.disabled = false;
            showToast(IS_RTL ? 'حدث خطأ' : 'An error occurred', 'error');
        });
    }

    // ====== REJECT MODAL ======
    function openRejectModal(txId) {
        document.getElementById('reject_transaction_id').value = txId;
        document.getElementById('reject_reason').value = '';
        // Clear active reason chips
        document.querySelectorAll('.reason-chip').forEach(function(c) { c.classList.remove('active'); });
        openModal('rejectModal');
    }

    function selectReason(chip, reason) {
        document.querySelectorAll('.reason-chip').forEach(function(c) { c.classList.remove('active'); });
        chip.classList.add('active');
        document.getElementById('reject_reason').value = reason;
    }

    function submitReject() {
        var txId = document.getElementById('reject_transaction_id').value;
        var reason = document.getElementById('reject_reason').value.trim();
        var btn = document.getElementById('rejectBtn');

        if (!reason) {
            showToast(IS_RTL ? 'يرجى إدخال سبب الرفض' : 'Please enter a rejection reason', 'error');
            return;
        }

        btn.classList.add('btn-loading');
        btn.disabled = true;

        ajaxPost({
            action: 'reject',
            transaction_id: txId,
            reject_reason: reason
        }).then(function(data) {
            btn.classList.remove('btn-loading');
            btn.disabled = false;
            if (data.success) {
                showToast(data.message || (IS_RTL ? 'تم الرفض بنجاح' : 'Rejected successfully'));
                closeModal('rejectModal');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showToast(data.message || (IS_RTL ? 'حدث خطأ' : 'An error occurred'), 'error');
            }
        }).catch(function() {
            btn.classList.remove('btn-loading');
            btn.disabled = false;
            showToast(IS_RTL ? 'حدث خطأ' : 'An error occurred', 'error');
        });
    }
</script>
</body>
</html>
