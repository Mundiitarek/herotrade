<?php
define('APP_ACCESS', true);
require_once '../config.php';
require_once '../functions.php';
require_once 'functions.php';

// Check admin login
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

$lang = get_current_lang();
$dir = get_dir();
$is_rtl = is_rtl();

// ==========================================
// FETCH ALL DASHBOARD DATA
// ==========================================

// Users stats
try {
    $stmt = $pdo->query("
        SELECT
            COUNT(*) as total_users,
            COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_users,
            COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as new_users_today,
            COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as new_users_week
        FROM users
    ");
    $users_stats = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Admin users stats error: " . $e->getMessage());
    $users_stats = ['total_users' => 0, 'active_users' => 0, 'new_users_today' => 0, 'new_users_week' => 0];
}

// Total balance
try {
    $stmt = $pdo->query("SELECT COALESCE(SUM(balance), 0) as total_balance FROM users");
    $balance_data = $stmt->fetch();
    $total_balance = $balance_data['total_balance'] ?? 0;
} catch (PDOException $e) {
    error_log("Admin balance error: " . $e->getMessage());
    $total_balance = 0;
}

// Transactions stats
try {
    $stmt = $pdo->query("
        SELECT
            COUNT(*) as total_transactions,
            COUNT(CASE WHEN type = 'deposit' AND status = 'pending' THEN 1 END) as pending_deposits_count,
            COUNT(CASE WHEN type = 'withdrawal' AND status = 'pending' THEN 1 END) as pending_withdrawals_count,
            SUM(CASE WHEN type = 'deposit' AND status = 'completed' AND created_at >= CURDATE() THEN amount ELSE 0 END) as today_deposits,
            SUM(CASE WHEN type = 'withdrawal' AND status = 'completed' AND created_at >= CURDATE() THEN amount ELSE 0 END) as today_withdrawals,
            SUM(CASE WHEN type = 'deposit' AND status = 'completed' THEN amount ELSE 0 END) as total_deposits,
            SUM(CASE WHEN type = 'withdrawal' AND status = 'completed' THEN amount ELSE 0 END) as total_withdrawals
        FROM transactions
    ");
    $transactions_stats = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Admin transactions stats error: " . $e->getMessage());
    $transactions_stats = [
        'total_transactions' => 0, 'pending_deposits_count' => 0, 'pending_withdrawals_count' => 0,
        'today_deposits' => 0, 'today_withdrawals' => 0, 'total_deposits' => 0, 'total_withdrawals' => 0
    ];
}

$pending_total = ($transactions_stats['pending_deposits_count'] ?? 0) + ($transactions_stats['pending_withdrawals_count'] ?? 0);

// Spot trades stats
try {
    $stmt = $pdo->query("
        SELECT
            COUNT(*) as total_spot_trades,
            COUNT(CASE WHEN status = 'open' THEN 1 END) as open_spot_trades,
            SUM(CASE WHEN status = 'closed' THEN profit_loss ELSE 0 END) as total_pl,
            SUM(COALESCE(fee, 0)) as total_spot_fees
        FROM spot_trades
    ");
    $spot_stats = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Admin spot stats error: " . $e->getMessage());
    $spot_stats = ['total_spot_trades' => 0, 'open_spot_trades' => 0, 'total_pl' => 0, 'total_spot_fees' => 0];
}

// Binary trades stats
try {
    $stmt = $pdo->query("
        SELECT
            COUNT(*) as total_binary_trades,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_binary_trades,
            COUNT(CASE WHEN result = 'won' THEN 1 END) as won_binary_trades,
            COUNT(CASE WHEN result = 'lost' THEN 1 END) as lost_binary_trades,
            SUM(CASE WHEN result = 'won' THEN payout ELSE 0 END) as total_binary_payout,
            SUM(CASE WHEN result = 'lost' THEN amount ELSE 0 END) as total_binary_loss
        FROM binary_trades
    ");
    $binary_stats = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Admin binary stats error: " . $e->getMessage());
    $binary_stats = [
        'total_binary_trades' => 0, 'pending_binary_trades' => 0,
        'won_binary_trades' => 0, 'lost_binary_trades' => 0,
        'total_binary_payout' => 0, 'total_binary_loss' => 0
    ];
}

// Platform revenue
$spot_fees = (float)($spot_stats['total_spot_fees'] ?? 0);
$binary_losses = (float)($binary_stats['total_binary_loss'] ?? 0);
$binary_payouts = (float)($binary_stats['total_binary_payout'] ?? 0);
$binary_net = $binary_losses - $binary_payouts;
$platform_revenue = $spot_fees + $binary_net;

// Recent activity (last 15)
try {
    $stmt = $pdo->query("
        SELECT al.*, u.username
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.id
        ORDER BY al.created_at DESC
        LIMIT 15
    ");
    $recent_activity = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Admin activity error: " . $e->getMessage());
    $recent_activity = [];
}

// Top 5 traders by profit
try {
    $stmt = $pdo->query("
        SELECT u.id, u.username, u.email, u.balance,
            COUNT(st.id) as total_trades,
            SUM(CASE WHEN st.profit_loss > 0 THEN st.profit_loss ELSE 0 END) as total_profit
        FROM users u
        LEFT JOIN spot_trades st ON u.id = st.user_id AND st.status = 'closed'
        GROUP BY u.id, u.username, u.email, u.balance
        ORDER BY total_profit DESC
        LIMIT 5
    ");
    $top_traders = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Admin top traders error: " . $e->getMessage());
    $top_traders = [];
}

// Pending deposits (last 10)
try {
    $stmt = $pdo->query("
        SELECT t.*, u.username, u.email, pg.name as gateway_name
        FROM transactions t
        JOIN users u ON t.user_id = u.id
        LEFT JOIN payment_gateways pg ON t.gateway_id = pg.id
        WHERE t.type = 'deposit' AND t.status = 'pending'
        ORDER BY t.created_at DESC
        LIMIT 10
    ");
    $pending_deposits = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Admin pending deposits error: " . $e->getMessage());
    $pending_deposits = [];
}

// 7-day deposit/withdrawal chart data
try {
    $stmt = $pdo->query("
        SELECT
            DATE(created_at) as date,
            SUM(CASE WHEN type = 'deposit' AND status = 'completed' THEN amount ELSE 0 END) as deposits,
            SUM(CASE WHEN type = 'withdrawal' AND status = 'completed' THEN amount ELSE 0 END) as withdrawals
        FROM transactions
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ");
    $chart_data = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Admin chart data error: " . $e->getMessage());
    $chart_data = [];
}

// Active trades count
$active_trades = ($spot_stats['open_spot_trades'] ?? 0) + ($binary_stats['pending_binary_trades'] ?? 0);

// Chart arrays for JS
$chart_labels = json_encode(array_map(function($d) { return date('m/d', strtotime($d['date'])); }, $chart_data));
$chart_deposits = json_encode(array_map(function($d) { return (float)$d['deposits']; }, $chart_data));
$chart_withdrawals = json_encode(array_map(function($d) { return (float)$d['withdrawals']; }, $chart_data));

// Revenue doughnut data
$p2p_fees = 0; // placeholder for P2P fees
$revenue_labels_en = json_encode(['Spot Fees', 'Binary Net', 'P2P Fees']);
$revenue_labels_ar = json_encode(['رسوم Spot', 'صافي Binary', 'رسوم P2P']);
$revenue_data = json_encode([$spot_fees, max(0, $binary_net), $p2p_fees]);
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('admin_panel') ?> - <?= t('dashboard') ?> | HeroTrade</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <style>
        *, *::before, *::after {
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
            --glass: rgba(30, 41, 59, 0.8);
            --sidebar-width: 280px;
        }

        body {
            font-family: <?= $is_rtl ? "'Tajawal'" : "'Poppins', 'Tajawal'" ?>, sans-serif;
            background: var(--primary);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
        }

        /* ==========================================
           SIDEBAR
           ========================================== */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            z-index: 999;
            backdrop-filter: blur(4px);
        }

        .sidebar-overlay.active {
            display: block;
        }

        aside.sidebar {
            position: fixed;
            top: 0;
            <?= $is_rtl ? 'right: 0' : 'left: 0' ?>;
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--secondary);
            border-<?= $is_rtl ? 'left' : 'right' ?>: 1px solid var(--border);
            z-index: 1000;
            overflow-y: auto;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        aside.sidebar::-webkit-scrollbar { width: 4px; }
        aside.sidebar::-webkit-scrollbar-thumb { background: var(--accent); border-radius: 2px; }

        .sidebar .logo {
            padding: 24px 20px;
            border-bottom: 1px solid var(--border);
        }

        .sidebar .logo-content {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar .logo-icon {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, #DC2626, var(--accent));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            flex-shrink: 0;
        }

        .sidebar .logo-text {
            font-size: 20px;
            font-weight: 900;
            background: linear-gradient(135deg, #DC2626, var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .sidebar .logo-subtitle {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--text-muted);
            font-weight: 600;
        }

        .sidebar .lang-switcher {
            display: flex;
            gap: 6px;
            padding: 12px 20px;
            border-bottom: 1px solid var(--border);
        }

        .sidebar .lang-switcher .lang-btn {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: transparent;
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-family: inherit;
            text-align: center;
        }

        .sidebar .lang-switcher .lang-btn.active {
            background: linear-gradient(135deg, #DC2626, var(--accent));
            color: white;
            border-color: transparent;
        }

        .sidebar .lang-switcher .lang-btn:hover:not(.active) {
            border-color: var(--accent);
            color: var(--text);
        }

        .sidebar .admin-card {
            margin: 16px;
            padding: 16px;
            background: rgba(220, 38, 38, 0.05);
            border: 1px solid rgba(220, 38, 38, 0.1);
            border-radius: 14px;
        }

        .sidebar .admin-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar .admin-avatar {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, #DC2626, var(--accent));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 700;
            color: white;
            flex-shrink: 0;
        }

        .sidebar .admin-name {
            font-weight: 700;
            font-size: 15px;
        }

        .sidebar .admin-role {
            font-size: 11px;
            color: var(--accent);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .sidebar nav {
            padding: 12px 0 24px;
        }

        .sidebar .nav-section {
            padding: 16px 20px 8px;
            font-size: 10px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        .sidebar .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            color: var(--text-muted);
            text-decoration: none;
            transition: all 0.3s;
            position: relative;
            font-size: 14px;
            font-weight: 500;
        }

        .sidebar .nav-item:hover,
        .sidebar .nav-item.active {
            color: var(--text);
            background: rgba(220, 38, 38, 0.06);
        }

        .sidebar .nav-item.active::before {
            content: '';
            position: absolute;
            <?= $is_rtl ? 'right' : 'left' ?>: 0;
            top: 0;
            height: 100%;
            width: 3px;
            background: linear-gradient(180deg, #DC2626, var(--accent));
            border-radius: 0 3px 3px 0;
        }

        .sidebar .nav-item i {
            width: 20px;
            text-align: center;
            font-size: 16px;
        }

        .sidebar .nav-item .nav-badge {
            margin-<?= $is_rtl ? 'right' : 'left' ?>: auto;
            background: var(--danger);
            color: white;
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 10px;
            font-weight: 700;
            min-width: 20px;
            text-align: center;
        }

        .sidebar .nav-item.logout-link {
            color: var(--danger);
            margin-top: 8px;
        }

        .sidebar .nav-item.logout-link:hover {
            background: rgba(239, 68, 68, 0.08);
        }

        /* ==========================================
           MAIN CONTENT
           ========================================== */
        .main {
            <?= $is_rtl ? 'margin-right' : 'margin-left' ?>: var(--sidebar-width);
            padding: 32px;
            min-height: 100vh;
        }

        /* Mobile menu button */
        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 16px;
            <?= $is_rtl ? 'right: 16px' : 'left: 16px' ?>;
            z-index: 998;
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: var(--secondary);
            border: 1px solid var(--border);
            color: var(--text);
            font-size: 20px;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
            backdrop-filter: blur(10px);
        }

        .mobile-menu-btn:hover {
            background: var(--accent);
            color: var(--primary);
        }

        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 32px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .page-title {
            font-size: 30px;
            font-weight: 900;
            background: linear-gradient(135deg, var(--text), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1.2;
        }

        .page-subtitle {
            color: var(--text-muted);
            font-size: 14px;
            margin-top: 4px;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-date {
            font-size: 13px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--secondary);
            padding: 10px 16px;
            border-radius: 10px;
            border: 1px solid var(--border);
        }

        /* ==========================================
           KPI STATS CARDS
           ========================================== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            position: relative;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.3);
        }

        .stat-card::after {
            content: '';
            position: absolute;
            top: 0;
            <?= $is_rtl ? 'left' : 'right' ?>: 0;
            width: 120px;
            height: 120px;
            border-radius: 50%;
            transform: translate(40%, -40%);
            opacity: 0.08;
        }

        .stat-card.card-users::after { background: var(--info); }
        .stat-card.card-revenue::after { background: var(--success); }
        .stat-card.card-trades::after { background: var(--purple); }
        .stat-card.card-pending::after { background: var(--warning); }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }

        .stat-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .stat-icon.icon-users { background: rgba(59, 130, 246, 0.12); color: var(--info); }
        .stat-icon.icon-revenue { background: rgba(16, 185, 129, 0.12); color: var(--success); }
        .stat-icon.icon-trades { background: rgba(139, 92, 246, 0.12); color: var(--purple); }
        .stat-icon.icon-pending { background: rgba(245, 158, 11, 0.12); color: var(--warning); }

        .stat-trend {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
        }

        .stat-trend.up {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .stat-trend.alert {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .stat-value {
            font-size: 30px;
            font-weight: 900;
            color: var(--text);
            margin-bottom: 4px;
            line-height: 1.1;
        }

        .stat-label {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 16px;
            font-weight: 500;
        }

        .stat-details {
            display: flex;
            gap: 16px;
            padding-top: 14px;
            border-top: 1px solid var(--border);
        }

        .stat-detail {
            flex: 1;
        }

        .stat-detail-value {
            font-size: 15px;
            font-weight: 700;
            color: var(--text);
        }

        .stat-detail-label {
            font-size: 11px;
            color: var(--text-muted);
            font-weight: 500;
        }

        /* ==========================================
           CHARTS
           ========================================== */
        .charts-grid {
            display: grid;
            grid-template-columns: 3fr 2fr;
            gap: 24px;
            margin-bottom: 32px;
        }

        .chart-card {
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            backdrop-filter: blur(10px);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .chart-title {
            font-size: 17px;
            font-weight: 700;
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        /* ==========================================
           CONTENT GRID
           ========================================== */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 32px;
        }

        .content-card {
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            backdrop-filter: blur(10px);
        }

        .content-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .content-title {
            font-size: 17px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .content-title i {
            color: var(--accent);
            font-size: 16px;
        }

        .content-body {
            max-height: 460px;
            overflow-y: auto;
        }

        .content-body::-webkit-scrollbar { width: 4px; }
        .content-body::-webkit-scrollbar-track { background: transparent; }
        .content-body::-webkit-scrollbar-thumb { background: var(--accent); border-radius: 2px; }

        /* Activity feed items */
        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 14px 24px;
            border-bottom: 1px solid var(--border);
            transition: background 0.2s;
        }

        .activity-item:hover {
            background: rgba(245, 158, 11, 0.02);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .activity-icon.login { background: rgba(59, 130, 246, 0.12); color: var(--info); }
        .activity-icon.trade { background: rgba(139, 92, 246, 0.12); color: var(--purple); }
        .activity-icon.deposit { background: rgba(16, 185, 129, 0.12); color: var(--success); }
        .activity-icon.withdrawal { background: rgba(239, 68, 68, 0.12); color: var(--danger); }
        .activity-icon.default { background: rgba(148, 163, 184, 0.12); color: var(--text-muted); }

        .activity-content {
            flex: 1;
            min-width: 0;
        }

        .activity-text {
            font-size: 13px;
            font-weight: 500;
            line-height: 1.4;
        }

        .activity-text strong {
            color: var(--accent);
        }

        .activity-meta {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 3px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .activity-time {
            font-size: 11px;
            color: var(--text-muted);
            white-space: nowrap;
            margin-top: 4px;
        }

        /* Top traders */
        .trader-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 24px;
            border-bottom: 1px solid var(--border);
            transition: background 0.2s;
        }

        .trader-item:hover {
            background: rgba(245, 158, 11, 0.02);
        }

        .trader-item:last-child {
            border-bottom: none;
        }

        .trader-rank {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 900;
            flex-shrink: 0;
        }

        .trader-rank.gold {
            background: linear-gradient(135deg, #F59E0B, #DC2626);
            color: white;
        }

        .trader-rank.silver {
            background: linear-gradient(135deg, #94A3B8, #64748B);
            color: white;
        }

        .trader-rank.bronze {
            background: linear-gradient(135deg, #D97706, #92400E);
            color: white;
        }

        .trader-rank.normal {
            background: rgba(139, 92, 246, 0.12);
            color: var(--purple);
        }

        .trader-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--info), var(--purple));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 700;
            color: white;
            flex-shrink: 0;
            text-transform: uppercase;
        }

        .trader-info {
            flex: 1;
            min-width: 0;
        }

        .trader-name {
            font-weight: 600;
            font-size: 14px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .trader-trades {
            font-size: 11px;
            color: var(--text-muted);
        }

        .trader-profit {
            font-weight: 700;
            font-size: 14px;
            color: var(--success);
            white-space: nowrap;
        }

        /* Quick actions panel */
        .quick-actions {
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            margin-top: 24px;
        }

        .quick-actions-title {
            font-size: 17px;
            font-weight: 700;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .quick-actions-title i {
            color: var(--accent);
        }

        .quick-actions-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .quick-action-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            border-radius: 10px;
            background: rgba(245, 158, 11, 0.06);
            border: 1px solid var(--border);
            color: var(--text);
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.3s;
            cursor: pointer;
            font-family: inherit;
        }

        .quick-action-btn:hover {
            background: rgba(245, 158, 11, 0.12);
            border-color: var(--accent);
            transform: translateY(-2px);
        }

        .quick-action-btn i {
            color: var(--accent);
            font-size: 16px;
            width: 20px;
            text-align: center;
        }

        /* Pending deposits list */
        .deposit-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 24px;
            border-bottom: 1px solid var(--border);
            transition: background 0.2s;
        }

        .deposit-item:hover {
            background: rgba(245, 158, 11, 0.02);
        }

        .deposit-item:last-child {
            border-bottom: none;
        }

        .deposit-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: rgba(245, 158, 11, 0.12);
            color: var(--warning);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }

        .deposit-info {
            flex: 1;
            min-width: 0;
        }

        .deposit-user {
            font-weight: 600;
            font-size: 14px;
        }

        .deposit-meta {
            font-size: 11px;
            color: var(--text-muted);
        }

        .deposit-amount {
            font-weight: 700;
            font-size: 15px;
            color: var(--success);
            white-space: nowrap;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            font-family: inherit;
        }

        .btn-primary {
            background: linear-gradient(135deg, #DC2626, var(--accent));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(220, 38, 38, 0.3);
        }

        .btn-sm {
            padding: 6px 14px;
            font-size: 12px;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text-muted);
        }

        .btn-outline:hover {
            border-color: var(--accent);
            color: var(--accent);
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.3;
            display: block;
        }

        .empty-state p {
            font-size: 14px;
        }

        /* ==========================================
           RESPONSIVE
           ========================================== */
        @media (max-width: 1400px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 1200px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }

            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .mobile-menu-btn {
                display: flex;
            }

            aside.sidebar {
                transform: translate<?= $is_rtl ? 'X(100%)' : 'X(-100%)' ?>;
            }

            aside.sidebar.open {
                transform: translateX(0);
            }

            .main {
                <?= $is_rtl ? 'margin-right' : 'margin-left' ?>: 0;
                padding: 80px 16px 24px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .page-title {
                font-size: 24px;
            }

            .stat-value {
                font-size: 26px;
            }

            .header {
                flex-direction: column;
            }

            .quick-actions-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<!-- Mobile Menu Button -->
<button class="mobile-menu-btn" onclick="toggleSidebar()" aria-label="Menu">
    <i class="fas fa-bars"></i>
</button>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" onclick="toggleSidebar()"></div>

<!-- Sidebar -->
<aside class="sidebar">
    <div class="logo">
        <div class="logo-content">
            <div class="logo-icon">
                <i class="fas fa-shield-alt"></i>
            </div>
            <div>
                <div class="logo-text">HeroTrade Admin</div>
                <div class="logo-subtitle"><?= t('admin_panel') ?></div>
            </div>
        </div>
    </div>

    <div class="lang-switcher">
        <button class="lang-btn <?= $lang === 'ar' ? 'active' : '' ?>" onclick="changeLanguage('ar')">العربية</button>
        <button class="lang-btn <?= $lang === 'en' ? 'active' : '' ?>" onclick="changeLanguage('en')">English</button>
    </div>

    <div class="admin-card">
        <div class="admin-info">
            <div class="admin-avatar">
                <i class="fas fa-user-shield"></i>
            </div>
            <div>
                <div class="admin-name"><?= htmlspecialchars($admin['username'] ?? 'Admin') ?></div>
                <div class="admin-role">Super Admin</div>
            </div>
        </div>
    </div>

    <nav>
        <div class="nav-section"><?= $is_rtl ? 'الإدارة' : 'Management' ?></div>

        <a href="index.php" class="nav-item active">
            <i class="fas fa-chart-pie"></i>
            <span><?= t('dashboard') ?></span>
        </a>
        <a href="users.php" class="nav-item">
            <i class="fas fa-users"></i>
            <span><?= t('users_management') ?></span>
            <?php if (($users_stats['total_users'] ?? 0) > 0): ?>
                <span class="nav-badge"><?= $users_stats['total_users'] ?></span>
            <?php endif; ?>
        </a>
        <a href="payments.php" class="nav-item">
            <i class="fas fa-credit-card"></i>
            <span><?= t('payments_management') ?></span>
            <?php if ($pending_total > 0): ?>
                <span class="nav-badge"><?= $pending_total ?></span>
            <?php endif; ?>
        </a>
        <a href="trades.php" class="nav-item">
            <i class="fas fa-exchange-alt"></i>
            <span><?= t('trades_management') ?></span>
        </a>
        <a href="payment-gateways.php" class="nav-item">
            <i class="fas fa-wallet"></i>
            <span><?= t('payment_gateways') ?></span>
        </a>
        <a href="reports.php" class="nav-item">
            <i class="fas fa-chart-bar"></i>
            <span><?= t('reports') ?></span>
        </a>
        <a href="settings.php" class="nav-item">
            <i class="fas fa-cog"></i>
            <span><?= t('settings') ?></span>
        </a>

        <div class="nav-section"><?= $is_rtl ? 'أخرى' : 'Other' ?></div>

        <a href="../logout.php" class="nav-item logout-link">
            <i class="fas fa-sign-out-alt"></i>
            <span><?= t('logout') ?></span>
        </a>
    </nav>
</aside>

<!-- Main Content -->
<main class="main">
    <!-- Header -->
    <div class="header">
        <div>
            <h1 class="page-title"><?= t('dashboard') ?></h1>
            <p class="page-subtitle"><?= $is_rtl ? 'نظرة شاملة على أداء المنصة' : 'Complete overview of platform performance' ?></p>
        </div>
        <div class="header-right">
            <div class="header-date">
                <i class="fas fa-calendar-alt"></i>
                <?= date('Y-m-d / h:i A') ?>
            </div>
        </div>
    </div>

    <!-- KPI Stats Cards -->
    <div class="stats-grid">
        <!-- Total Users -->
        <div class="stat-card card-users">
            <div class="stat-header">
                <div class="stat-icon icon-users">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-trend up">
                    <i class="fas fa-arrow-up"></i>
                    +<?= $users_stats['new_users_week'] ?? 0 ?> <?= t('this_week') ?>
                </div>
            </div>
            <div class="stat-value"><?= number_format($users_stats['total_users'] ?? 0) ?></div>
            <div class="stat-label"><?= t('total') ?> <?= $is_rtl ? 'المستخدمين' : 'Users' ?></div>
            <div class="stat-details">
                <div class="stat-detail">
                    <div class="stat-detail-value"><?= number_format($users_stats['active_users'] ?? 0) ?></div>
                    <div class="stat-detail-label"><?= t('active') ?></div>
                </div>
                <div class="stat-detail">
                    <div class="stat-detail-value"><?= number_format($users_stats['new_users_today'] ?? 0) ?></div>
                    <div class="stat-detail-label"><?= t('today') ?></div>
                </div>
                <div class="stat-detail">
                    <div class="stat-detail-value">$<?= number_format($total_balance, 0) ?></div>
                    <div class="stat-detail-label"><?= t('balance') ?></div>
                </div>
            </div>
        </div>

        <!-- Revenue -->
        <div class="stat-card card-revenue">
            <div class="stat-header">
                <div class="stat-icon icon-revenue">
                    <i class="fas fa-dollar-sign"></i>
                </div>
            </div>
            <div class="stat-value">$<?= number_format($platform_revenue, 2) ?></div>
            <div class="stat-label"><?= $is_rtl ? 'إيرادات المنصة' : 'Platform Revenue' ?></div>
            <div class="stat-details">
                <div class="stat-detail">
                    <div class="stat-detail-value">$<?= number_format($transactions_stats['today_deposits'] ?? 0, 2) ?></div>
                    <div class="stat-detail-label"><?= t('deposit') ?> <?= t('today') ?></div>
                </div>
                <div class="stat-detail">
                    <div class="stat-detail-value">$<?= number_format($transactions_stats['today_withdrawals'] ?? 0, 2) ?></div>
                    <div class="stat-detail-label"><?= t('withdrawal') ?> <?= t('today') ?></div>
                </div>
            </div>
        </div>

        <!-- Active Trades -->
        <div class="stat-card card-trades">
            <div class="stat-header">
                <div class="stat-icon icon-trades">
                    <i class="fas fa-chart-line"></i>
                </div>
            </div>
            <div class="stat-value"><?= number_format($active_trades) ?></div>
            <div class="stat-label"><?= $is_rtl ? 'صفقات نشطة' : 'Active Trades' ?></div>
            <div class="stat-details">
                <div class="stat-detail">
                    <div class="stat-detail-value"><?= number_format($spot_stats['total_spot_trades'] ?? 0) ?></div>
                    <div class="stat-detail-label">Spot <?= t('total') ?></div>
                </div>
                <div class="stat-detail">
                    <div class="stat-detail-value"><?= number_format($binary_stats['total_binary_trades'] ?? 0) ?></div>
                    <div class="stat-detail-label">Binary <?= t('total') ?></div>
                </div>
                <div class="stat-detail">
                    <div class="stat-detail-value" style="color: <?= ($spot_stats['total_pl'] ?? 0) >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
                        $<?= number_format(abs($spot_stats['total_pl'] ?? 0), 2) ?>
                    </div>
                    <div class="stat-detail-label">P/L</div>
                </div>
            </div>
        </div>

        <!-- Pending Transactions -->
        <div class="stat-card card-pending">
            <div class="stat-header">
                <div class="stat-icon icon-pending">
                    <i class="fas fa-clock"></i>
                </div>
                <?php if ($pending_total > 0): ?>
                    <div class="stat-trend alert">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= $is_rtl ? 'يتطلب إجراء' : 'Action Required' ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="stat-value"><?= number_format($pending_total) ?></div>
            <div class="stat-label"><?= t('pending') ?> <?= $is_rtl ? 'المعاملات' : 'Transactions' ?></div>
            <div class="stat-details">
                <div class="stat-detail">
                    <div class="stat-detail-value"><?= number_format($transactions_stats['pending_deposits_count'] ?? 0) ?></div>
                    <div class="stat-detail-label"><?= t('deposit') ?></div>
                </div>
                <div class="stat-detail">
                    <div class="stat-detail-value"><?= number_format($transactions_stats['pending_withdrawals_count'] ?? 0) ?></div>
                    <div class="stat-detail-label"><?= t('withdrawal') ?></div>
                </div>
                <div class="stat-detail">
                    <a href="payments.php" class="btn btn-primary btn-sm" style="width: 100%; justify-content: center;">
                        <?= $is_rtl ? 'مراجعة' : 'Review' ?>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="charts-grid">
        <!-- Deposits vs Withdrawals Line Chart -->
        <div class="chart-card">
            <div class="chart-header">
                <h3 class="chart-title">
                    <i class="fas fa-chart-area" style="color: var(--info); margin-<?= $is_rtl ? 'left' : 'right' ?>: 8px;"></i>
                    <?= $is_rtl ? 'الإيداعات والسحوبات - آخر 7 أيام' : 'Deposits vs Withdrawals - Last 7 Days' ?>
                </h3>
            </div>
            <div class="chart-container">
                <canvas id="transactionsChart"></canvas>
            </div>
        </div>

        <!-- Revenue Doughnut Chart -->
        <div class="chart-card">
            <div class="chart-header">
                <h3 class="chart-title">
                    <i class="fas fa-chart-pie" style="color: var(--accent); margin-<?= $is_rtl ? 'left' : 'right' ?>: 8px;"></i>
                    <?= $is_rtl ? 'توزيع الإيرادات' : 'Revenue Breakdown' ?>
                </h3>
            </div>
            <div class="chart-container">
                <canvas id="revenueChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Content Grid -->
    <div class="content-grid">
        <!-- Left: Recent Activity Feed -->
        <div class="content-card">
            <div class="content-header">
                <h3 class="content-title">
                    <i class="fas fa-stream"></i>
                    <?= $is_rtl ? 'النشاط الأخير' : 'Recent Activity' ?>
                </h3>
            </div>
            <div class="content-body">
                <?php if (empty($recent_activity)): ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <p><?= $is_rtl ? 'لا يوجد نشاط حتى الآن' : 'No activity yet' ?></p>
                    </div>
                <?php else: ?>
                    <?php foreach ($recent_activity as $activity):
                        $action = $activity['action'] ?? '';
                        if (strpos($action, 'login') !== false) {
                            $icon_class = 'login';
                            $fa_icon = 'fa-sign-in-alt';
                        } elseif (strpos($action, 'trade') !== false) {
                            $icon_class = 'trade';
                            $fa_icon = 'fa-exchange-alt';
                        } elseif (strpos($action, 'deposit') !== false) {
                            $icon_class = 'deposit';
                            $fa_icon = 'fa-arrow-down';
                        } elseif (strpos($action, 'withdraw') !== false) {
                            $icon_class = 'withdrawal';
                            $fa_icon = 'fa-arrow-up';
                        } else {
                            $icon_class = 'default';
                            $fa_icon = 'fa-circle';
                        }
                    ?>
                        <div class="activity-item">
                            <div class="activity-icon <?= $icon_class ?>">
                                <i class="fas <?= $fa_icon ?>"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-text">
                                    <strong><?= htmlspecialchars($activity['username'] ?? 'System') ?></strong>
                                    &mdash; <?= htmlspecialchars($activity['description'] ?? $action) ?>
                                </div>
                                <div class="activity-meta">
                                    <span><i class="fas fa-globe" style="margin-<?= $is_rtl ? 'left' : 'right' ?>: 4px;"></i><?= htmlspecialchars($activity['ip_address'] ?? 'N/A') ?></span>
                                </div>
                            </div>
                            <div class="activity-time">
                                <?= time_ago($activity['created_at'], $lang) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right Column: Top Traders + Quick Actions -->
        <div>
            <!-- Top Traders -->
            <div class="content-card">
                <div class="content-header">
                    <h3 class="content-title">
                        <i class="fas fa-trophy"></i>
                        <?= $is_rtl ? 'أفضل المتداولين' : 'Top Traders' ?>
                    </h3>
                    <a href="users.php" class="btn btn-sm btn-outline"><?= t('view_all') ?></a>
                </div>
                <div class="content-body">
                    <?php if (empty($top_traders)): ?>
                        <div class="empty-state">
                            <i class="fas fa-medal"></i>
                            <p><?= $is_rtl ? 'لا يوجد متداولون بعد' : 'No traders yet' ?></p>
                        </div>
                    <?php else: ?>
                        <?php $rank = 1; foreach ($top_traders as $trader):
                            $rank_class = $rank === 1 ? 'gold' : ($rank === 2 ? 'silver' : ($rank === 3 ? 'bronze' : 'normal'));
                            $initials = strtoupper(substr($trader['username'] ?? 'U', 0, 1));
                        ?>
                            <div class="trader-item">
                                <div class="trader-rank <?= $rank_class ?>"><?= $rank ?></div>
                                <div class="trader-avatar"><?= $initials ?></div>
                                <div class="trader-info">
                                    <div class="trader-name"><?= htmlspecialchars($trader['username']) ?></div>
                                    <div class="trader-trades">
                                        <?= (int)($trader['total_trades'] ?? 0) ?> <?= t('trades_count') ?>
                                        &bull; <?= t('balance') ?>: $<?= number_format($trader['balance'] ?? 0, 2) ?>
                                    </div>
                                </div>
                                <div class="trader-profit">+$<?= number_format($trader['total_profit'] ?? 0, 2) ?></div>
                            </div>
                        <?php $rank++; endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Actions Panel -->
            <div class="quick-actions">
                <div class="quick-actions-title">
                    <i class="fas fa-bolt"></i>
                    <?= t('quick_actions') ?>
                </div>
                <div class="quick-actions-grid">
                    <a href="users.php" class="quick-action-btn">
                        <i class="fas fa-user-plus"></i>
                        <?= $is_rtl ? 'إدارة المستخدمين' : 'Manage Users' ?>
                    </a>
                    <a href="payments.php" class="quick-action-btn">
                        <i class="fas fa-money-check-alt"></i>
                        <?= $is_rtl ? 'مراجعة المدفوعات' : 'Review Payments' ?>
                    </a>
                    <a href="trades.php" class="quick-action-btn">
                        <i class="fas fa-chart-line"></i>
                        <?= $is_rtl ? 'عرض الصفقات' : 'View Trades' ?>
                    </a>
                    <a href="settings.php" class="quick-action-btn">
                        <i class="fas fa-sliders-h"></i>
                        <?= t('settings') ?>
                    </a>
                    <a href="payment-gateways.php" class="quick-action-btn">
                        <i class="fas fa-wallet"></i>
                        <?= t('payment_gateways') ?>
                    </a>
                    <a href="reports.php" class="quick-action-btn">
                        <i class="fas fa-file-alt"></i>
                        <?= t('reports') ?>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Pending Deposits Row -->
    <?php if (!empty($pending_deposits)): ?>
    <div class="content-card" style="margin-bottom: 32px;">
        <div class="content-header">
            <h3 class="content-title">
                <i class="fas fa-hourglass-half"></i>
                <?= $is_rtl ? 'طلبات إيداع معلقة' : 'Pending Deposit Requests' ?>
            </h3>
            <a href="payments.php" class="btn btn-primary btn-sm">
                <?= t('view_all') ?>
            </a>
        </div>
        <div class="content-body">
            <?php foreach ($pending_deposits as $deposit): ?>
                <div class="deposit-item">
                    <div class="deposit-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="deposit-info">
                        <div class="deposit-user"><?= htmlspecialchars($deposit['username']) ?></div>
                        <div class="deposit-meta">
                            <?= htmlspecialchars($deposit['gateway_name'] ?? 'N/A') ?>
                            &bull; <?= date('Y/m/d h:i A', strtotime($deposit['created_at'])) ?>
                        </div>
                    </div>
                    <div class="deposit-amount">$<?= number_format($deposit['amount'], 2) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</main>

<script>
// ==========================================
// SIDEBAR TOGGLE (Mobile)
// ==========================================
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    const menuBtn = document.querySelector('.mobile-menu-btn i');

    sidebar.classList.toggle('open');
    overlay.classList.toggle('active');

    if (sidebar.classList.contains('open')) {
        menuBtn.classList.remove('fa-bars');
        menuBtn.classList.add('fa-times');
    } else {
        menuBtn.classList.remove('fa-times');
        menuBtn.classList.add('fa-bars');
    }
}

// ==========================================
// LANGUAGE SWITCHER
// ==========================================
function changeLanguage(lang) {
    const url = new URL(window.location.href);
    // Set via cookie and session
    document.cookie = 'lang=' + lang + ';path=/;max-age=' + (86400 * 365);

    // Send AJAX to update session, then reload
    const xhr = new XMLHttpRequest();
    xhr.open('GET', '../api/set_language.php?lang=' + lang, true);
    xhr.onload = function() {
        window.location.reload();
    };
    xhr.onerror = function() {
        window.location.reload();
    };
    xhr.send();
}

// ==========================================
// CHART.JS - DEPOSITS VS WITHDRAWALS (LINE)
// ==========================================
const transactionsCtx = document.getElementById('transactionsChart').getContext('2d');

const gradientDeposits = transactionsCtx.createLinearGradient(0, 0, 0, 300);
gradientDeposits.addColorStop(0, 'rgba(16, 185, 129, 0.3)');
gradientDeposits.addColorStop(1, 'rgba(16, 185, 129, 0.0)');

const gradientWithdrawals = transactionsCtx.createLinearGradient(0, 0, 0, 300);
gradientWithdrawals.addColorStop(0, 'rgba(239, 68, 68, 0.3)');
gradientWithdrawals.addColorStop(1, 'rgba(239, 68, 68, 0.0)');

new Chart(transactionsCtx, {
    type: 'line',
    data: {
        labels: <?= $chart_labels ?>,
        datasets: [{
            label: '<?= $is_rtl ? 'إيداعات' : 'Deposits' ?>',
            data: <?= $chart_deposits ?>,
            borderColor: '#10B981',
            backgroundColor: gradientDeposits,
            tension: 0.4,
            fill: true,
            borderWidth: 2,
            pointBackgroundColor: '#10B981',
            pointBorderColor: '#10B981',
            pointRadius: 4,
            pointHoverRadius: 6
        }, {
            label: '<?= $is_rtl ? 'سحوبات' : 'Withdrawals' ?>',
            data: <?= $chart_withdrawals ?>,
            borderColor: '#EF4444',
            backgroundColor: gradientWithdrawals,
            tension: 0.4,
            fill: true,
            borderWidth: 2,
            pointBackgroundColor: '#EF4444',
            pointBorderColor: '#EF4444',
            pointRadius: 4,
            pointHoverRadius: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
            intersect: false,
            mode: 'index'
        },
        plugins: {
            legend: {
                labels: {
                    color: '#F8FAFC',
                    font: { family: <?= $is_rtl ? "'Tajawal'" : "'Poppins'" ?>, size: 12 },
                    usePointStyle: true,
                    pointStyle: 'circle',
                    padding: 20
                }
            },
            tooltip: {
                backgroundColor: '#1E293B',
                titleColor: '#F8FAFC',
                bodyColor: '#94A3B8',
                borderColor: 'rgba(148, 163, 184, 0.2)',
                borderWidth: 1,
                cornerRadius: 10,
                padding: 12,
                titleFont: { family: <?= $is_rtl ? "'Tajawal'" : "'Poppins'" ?>, weight: '600' },
                bodyFont: { family: <?= $is_rtl ? "'Tajawal'" : "'Poppins'" ?> },
                callbacks: {
                    label: function(ctx) {
                        return ctx.dataset.label + ': $' + ctx.parsed.y.toLocaleString();
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    color: '#94A3B8',
                    font: { size: 11 },
                    callback: function(value) { return '$' + value.toLocaleString(); }
                },
                grid: { color: 'rgba(148, 163, 184, 0.08)', drawBorder: false }
            },
            x: {
                ticks: { color: '#94A3B8', font: { size: 11 } },
                grid: { display: false }
            }
        }
    }
});

// ==========================================
// CHART.JS - REVENUE BREAKDOWN (DOUGHNUT)
// ==========================================
const revenueCtx = document.getElementById('revenueChart').getContext('2d');

new Chart(revenueCtx, {
    type: 'doughnut',
    data: {
        labels: <?= $is_rtl ? $revenue_labels_ar : $revenue_labels_en ?>,
        datasets: [{
            data: <?= $revenue_data ?>,
            backgroundColor: ['#8B5CF6', '#10B981', '#3B82F6'],
            borderColor: '#1E293B',
            borderWidth: 3,
            hoverOffset: 8
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '65%',
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    color: '#F8FAFC',
                    font: { family: <?= $is_rtl ? "'Tajawal'" : "'Poppins'" ?>, size: 12 },
                    usePointStyle: true,
                    pointStyle: 'circle',
                    padding: 20
                }
            },
            tooltip: {
                backgroundColor: '#1E293B',
                titleColor: '#F8FAFC',
                bodyColor: '#94A3B8',
                borderColor: 'rgba(148, 163, 184, 0.2)',
                borderWidth: 1,
                cornerRadius: 10,
                padding: 12,
                titleFont: { family: <?= $is_rtl ? "'Tajawal'" : "'Poppins'" ?>, weight: '600' },
                bodyFont: { family: <?= $is_rtl ? "'Tajawal'" : "'Poppins'" ?> },
                callbacks: {
                    label: function(ctx) {
                        return ctx.label + ': $' + ctx.parsed.toLocaleString();
                    }
                }
            }
        }
    }
});

// Auto-refresh every 60 seconds
setTimeout(function() { location.reload(); }, 60000);
</script>
</body>
</html>
