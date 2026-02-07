<?php
define('APP_ACCESS', true);
require_once '../config.php';
require_once '../functions.php';
require_once 'functions.php';
require_once 'components.php';

// Check admin authentication
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

// Handle AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'close_spot_trade') {
        $trade_id = intval($_POST['trade_id'] ?? 0);

        try {
            $stmt = $pdo->prepare("SELECT * FROM spot_trades WHERE id = ? AND status = 'open'");
            $stmt->execute([$trade_id]);
            $trade = $stmt->fetch();

            if (!$trade) {
                json_response(['success' => false, 'message' => t('trade_not_found', 'Trade not found')], 404);
            }

            // Get current price
            $current_price = get_realtime_price($pdo, $trade['symbol']);

            // Calculate profit/loss
            if ($trade['type'] === 'buy') {
                $profit_loss = ($current_price - $trade['entry_price']) * $trade['quantity'];
            } else {
                $profit_loss = ($trade['entry_price'] - $current_price) * $trade['quantity'];
            }

            $pdo->beginTransaction();

            // Close trade
            $stmt = $pdo->prepare("
                UPDATE spot_trades
                SET status = 'closed', exit_price = ?, profit_loss = ?, closed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$current_price, $profit_loss, $trade_id]);

            // Update user balance
            $final_amount = $trade['amount'] + $profit_loss;
            $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$final_amount, $trade['user_id']]);

            log_admin_activity($pdo, $admin_id, 'trade_close', "Closed spot trade #$trade_id");

            $pdo->commit();
            json_response(['success' => true]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Close trade error: " . $e->getMessage());
            json_response(['success' => false, 'message' => t('error_occurred', 'An error occurred')], 500);
        }
    }

    if ($_POST['action'] === 'settle_binary_trade') {
        $trade_id = intval($_POST['trade_id'] ?? 0);
        $result = sanitize($_POST['result'] ?? '');

        if (!in_array($result, ['won', 'lost'])) {
            json_response(['success' => false, 'message' => 'Invalid result'], 400);
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM binary_trades WHERE id = ? AND status = 'pending'");
            $stmt->execute([$trade_id]);
            $trade = $stmt->fetch();

            if (!$trade) {
                json_response(['success' => false, 'message' => t('trade_not_found', 'Trade not found')], 404);
            }

            $pdo->beginTransaction();

            // Update trade result
            $stmt = $pdo->prepare("
                UPDATE binary_trades
                SET status = 'completed', result = ?, settled_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$result, $trade_id]);

            // If won, update user balance with payout
            if ($result === 'won') {
                $stmt = $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$trade['payout'], $trade['user_id']]);
            }

            log_admin_activity($pdo, $admin_id, 'trade_settle', "Settled binary trade #$trade_id as $result");

            $pdo->commit();
            json_response(['success' => true]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Settle trade error: " . $e->getMessage());
            json_response(['success' => false, 'message' => t('error_occurred', 'An error occurred')], 500);
        }
    }

    if ($_POST['action'] === 'delete_trade') {
        $trade_id = intval($_POST['trade_id'] ?? 0);
        $trade_type = sanitize($_POST['trade_type'] ?? 'spot');

        try {
            if ($trade_type === 'spot') {
                $stmt = $pdo->prepare("DELETE FROM spot_trades WHERE id = ?");
            } else {
                $stmt = $pdo->prepare("DELETE FROM binary_trades WHERE id = ?");
            }
            $stmt->execute([$trade_id]);

            log_admin_activity($pdo, $admin_id, 'trade_delete', "Deleted $trade_type trade #$trade_id");

            json_response(['success' => true]);
        } catch (PDOException $e) {
            error_log("Delete trade error: " . $e->getMessage());
            json_response(['success' => false, 'message' => t('error_occurred', 'An error occurred')], 500);
        }
    }

    if ($_POST['action'] === 'get_trade_details') {
        $trade_id = intval($_POST['trade_id'] ?? 0);
        $trade_type = sanitize($_POST['trade_type'] ?? 'spot');

        try {
            if ($trade_type === 'spot') {
                $stmt = $pdo->prepare("
                    SELECT
                        st.*,
                        u.username,
                        u.email,
                        tp.name_ar as pair_name_ar,
                        tp.name_en as pair_name_en,
                        tp.current_price as market_price
                    FROM spot_trades st
                    JOIN users u ON st.user_id = u.id
                    LEFT JOIN trading_pairs tp ON st.pair_id = tp.id
                    WHERE st.id = ?
                ");
            } else {
                $stmt = $pdo->prepare("
                    SELECT
                        bt.*,
                        u.username,
                        u.email,
                        tp.name_ar as pair_name_ar,
                        tp.name_en as pair_name_en
                    FROM binary_trades bt
                    JOIN users u ON bt.user_id = u.id
                    LEFT JOIN trading_pairs tp ON bt.pair_id = tp.id
                    WHERE bt.id = ?
                ");
            }
            $stmt->execute([$trade_id]);
            $trade = $stmt->fetch();

            if (!$trade) {
                json_response(['success' => false, 'message' => t('trade_not_found', 'Trade not found')], 404);
            }

            json_response(['success' => true, 'trade' => $trade]);
        } catch (PDOException $e) {
            error_log("Get trade details error: " . $e->getMessage());
            json_response(['success' => false, 'message' => t('error_occurred', 'An error occurred')], 500);
        }
    }

    exit;
}

// Get filters
$trade_type = sanitize($_GET['type'] ?? 'spot');
$status_filter = sanitize($_GET['status'] ?? 'all');
$user_filter = sanitize($_GET['user'] ?? '');
$date_from = sanitize($_GET['date_from'] ?? '');
$date_to = sanitize($_GET['date_to'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Get trades based on type
if ($trade_type === 'spot') {
    // Build query for spot trades
    $where = ["1=1"];
    $params = [];

    if ($status_filter !== 'all') {
        $where[] = "st.status = ?";
        $params[] = $status_filter;
    }

    if (!empty($user_filter)) {
        $where[] = "(u.username LIKE ? OR u.email LIKE ?)";
        $params[] = "%$user_filter%";
        $params[] = "%$user_filter%";
    }

    if (!empty($date_from)) {
        $where[] = "DATE(st.created_at) >= ?";
        $params[] = $date_from;
    }

    if (!empty($date_to)) {
        $where[] = "DATE(st.created_at) <= ?";
        $params[] = $date_to;
    }

    $where_clause = implode(' AND ', $where);

    try {
        // Get total count
        $count_stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM spot_trades st
            JOIN users u ON st.user_id = u.id
            WHERE $where_clause
        ");
        $count_stmt->execute($params);
        $total_trades = $count_stmt->fetchColumn();
        $total_pages = ceil($total_trades / $per_page);

        // Get trades
        $stmt = $pdo->prepare("
            SELECT
                st.*,
                u.username,
                u.email,
                tp.name_ar as pair_name_ar,
                tp.name_en as pair_name_en,
                tp.current_price as market_price
            FROM spot_trades st
            JOIN users u ON st.user_id = u.id
            LEFT JOIN trading_pairs tp ON st.pair_id = tp.id
            WHERE $where_clause
            ORDER BY st.created_at DESC
            LIMIT ? OFFSET ?
        ");

        $params[] = $per_page;
        $params[] = $offset;
        $stmt->execute($params);
        $trades = $stmt->fetchAll();

        // Calculate current P/L for open trades
        foreach ($trades as &$trade) {
            if ($trade['status'] === 'open') {
                $current_price = $trade['market_price'];
                if ($trade['type'] === 'buy') {
                    $trade['current_profit_loss'] = ($current_price - $trade['entry_price']) * $trade['quantity'];
                } else {
                    $trade['current_profit_loss'] = ($trade['entry_price'] - $current_price) * $trade['quantity'];
                }
            }
        }

        // Get statistics
        $stmt = $pdo->query("
            SELECT
                COUNT(*) as total,
                COUNT(CASE WHEN status = 'open' THEN 1 END) as open_trades,
                SUM(amount) as total_volume,
                SUM(CASE WHEN status = 'closed' AND profit_loss < 0 THEN ABS(profit_loss) ELSE 0 END) as platform_profit
            FROM spot_trades
        ");
        $stats = $stmt->fetch();

    } catch (PDOException $e) {
        error_log("Get spot trades error: " . $e->getMessage());
        $trades = [];
        $stats = [];
        $total_trades = 0;
        $total_pages = 1;
    }

} else {
    // Binary trades
    $where = ["1=1"];
    $params = [];

    if ($status_filter !== 'all') {
        if ($status_filter === 'pending') {
            $where[] = "bt.status = 'pending'";
        } else {
            $where[] = "bt.result = ?";
            $params[] = $status_filter;
        }
    }

    if (!empty($user_filter)) {
        $where[] = "(u.username LIKE ? OR u.email LIKE ?)";
        $params[] = "%$user_filter%";
        $params[] = "%$user_filter%";
    }

    if (!empty($date_from)) {
        $where[] = "DATE(bt.created_at) >= ?";
        $params[] = $date_from;
    }

    if (!empty($date_to)) {
        $where[] = "DATE(bt.created_at) <= ?";
        $params[] = $date_to;
    }

    $where_clause = implode(' AND ', $where);

    try {
        // Get total count
        $count_stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM binary_trades bt
            JOIN users u ON bt.user_id = u.id
            WHERE $where_clause
        ");
        $count_stmt->execute($params);
        $total_trades = $count_stmt->fetchColumn();
        $total_pages = ceil($total_trades / $per_page);

        // Get trades
        $stmt = $pdo->prepare("
            SELECT
                bt.*,
                u.username,
                u.email,
                tp.name_ar as pair_name_ar,
                tp.name_en as pair_name_en
            FROM binary_trades bt
            JOIN users u ON bt.user_id = u.id
            LEFT JOIN trading_pairs tp ON bt.pair_id = tp.id
            WHERE $where_clause
            ORDER BY bt.created_at DESC
            LIMIT ? OFFSET ?
        ");

        $params[] = $per_page;
        $params[] = $offset;
        $stmt->execute($params);
        $trades = $stmt->fetchAll();

        // Get statistics
        $stmt = $pdo->query("
            SELECT
                COUNT(*) as total,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as open_trades,
                SUM(amount) as total_volume,
                SUM(CASE WHEN result = 'lost' THEN amount WHEN result = 'won' THEN (amount - payout) END) as platform_profit
            FROM binary_trades
        ");
        $stats = $stmt->fetch();

    } catch (PDOException $e) {
        error_log("Get binary trades error: " . $e->getMessage());
        $trades = [];
        $stats = [];
        $total_trades = 0;
        $total_pages = 1;
    }
}

$page_title = t('trades_management', 'Trades Management');
?>
<!DOCTYPE html>
<html lang="<?php echo get_current_lang(); ?>" dir="<?php echo get_dir(); ?>">
<head>
    <?php render_admin_head($page_title); ?>
    <?php render_admin_base_css(); ?>
    <style>
        .main {
            <?php echo is_rtl() ? 'margin-right' : 'margin-left'; ?>: 280px;
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
            transition: color 0.3s;
        }

        .breadcrumb a:hover {
            color: var(--accent);
        }

        /* Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
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
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.2);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            <?php echo is_rtl() ? 'right' : 'left'; ?>: 0;
            width: 4px;
            height: 100%;
            background: var(--accent);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 12px;
        }

        .stat-label {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 8px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 900;
            line-height: 1;
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            border-bottom: 2px solid var(--border);
            overflow-x: auto;
        }

        .tab-btn {
            padding: 14px 24px;
            background: none;
            border: none;
            color: var(--text-muted);
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            font-family: <?php echo get_font(); ?>;
            text-decoration: none;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }

        .tab-btn:hover,
        .tab-btn.active {
            color: var(--accent);
            border-bottom-color: var(--accent);
        }

        /* Filters */
        .filters-card {
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
        }

        .filters-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filter-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
        }

        .filter-input,
        .filter-select {
            padding: 12px 16px;
            background: var(--primary);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            font-size: 14px;
            font-family: <?php echo get_font(); ?>;
            transition: border-color 0.3s;
        }

        .filter-input:focus,
        .filter-select:focus {
            outline: none;
            border-color: var(--accent);
        }

        .filter-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }

        /* Table */
        .trades-card {
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        thead {
            background: rgba(220, 38, 38, 0.05);
        }

        th {
            padding: 16px 20px;
            text-align: <?php echo is_rtl() ? 'right' : 'left'; ?>;
            font-weight: 700;
            font-size: 12px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        td {
            padding: 16px 20px;
            border-top: 1px solid var(--border);
            vertical-align: middle;
        }

        tbody tr {
            transition: background 0.2s;
        }

        tbody tr:hover {
            background: rgba(220, 38, 38, 0.03);
        }

        .user-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--accent), #DC2626);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
            color: white;
        }

        .user-info .user-name {
            font-weight: 600;
            margin-bottom: 2px;
        }

        .user-info .user-email {
            font-size: 12px;
            color: var(--text-muted);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .badge.open {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .badge.closed {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
        }

        .badge.pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--accent);
        }

        .badge.won {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .badge.lost {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .badge.buy {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .badge.sell {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .badge.up {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .badge.down {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .btn-group {
            display: flex;
            gap: 8px;
        }

        .btn-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
            font-size: 14px;
        }

        .btn-icon.view {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .btn-icon.view:hover {
            background: var(--info);
            color: white;
        }

        .btn-icon.close {
            background: rgba(139, 92, 246, 0.1);
            color: var(--purple);
        }

        .btn-icon.close:hover {
            background: var(--purple);
            color: white;
        }

        .btn-icon.delete {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .btn-icon.delete:hover {
            background: var(--danger);
            color: white;
        }

        .profit-positive {
            color: var(--success);
            font-weight: 700;
        }

        .profit-negative {
            color: var(--danger);
            font-weight: 700;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding: 24px;
            border-top: 1px solid var(--border);
        }

        .pagination-btn {
            padding: 10px 18px;
            background: var(--primary);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .pagination-btn:hover:not(.disabled) {
            background: linear-gradient(135deg, #DC2626, var(--accent));
            color: white;
            border-color: transparent;
        }

        .pagination-btn.disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }

        .pagination-info {
            padding: 0 16px;
            color: var(--text-muted);
            font-size: 14px;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--secondary);
            border-radius: 16px;
            max-width: 600px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            border: 1px solid var(--border);
        }

        .modal-header {
            padding: 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-title {
            font-size: 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .modal-close {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: none;
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
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

        .modal-body {
            padding: 24px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-size: 13px;
            color: var(--text-muted);
            font-weight: 600;
        }

        .detail-value {
            font-weight: 600;
            text-align: <?php echo is_rtl() ? 'left' : 'right'; ?>;
        }

        .modal-actions {
            padding: 24px;
            border-top: 1px solid var(--border);
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }

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

        /* Responsive */
        @media (max-width: 768px) {
            .main {
                margin-<?php echo is_rtl() ? 'right' : 'left'; ?>: 0;
                padding: 20px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .page-title {
                font-size: 24px;
            }

            .modal-content {
                margin: 0;
            }
        }
    </style>
</head>
<body>
    <?php render_admin_sidebar('trades', $admin); ?>

    <main class="main">
        <div class="header">
            <h1 class="page-title"><?php echo t('trades_management', 'Trades Management'); ?></h1>
            <div class="breadcrumb">
                <a href="index.php"><?php echo t('dashboard', 'Dashboard'); ?></a>
                <i class="fas fa-chevron-<?php echo is_rtl() ? 'left' : 'right'; ?>" style="font-size: 10px;"></i>
                <span><?php echo t('trades_management', 'Trades Management'); ?></span>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(139, 92, 246, 0.1); color: var(--purple);">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <div class="stat-label"><?php echo t('total_trades', 'Total Trades'); ?></div>
                <div class="stat-value" style="color: var(--purple);"><?php echo number_format($stats['total'] ?? 0); ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(59, 130, 246, 0.1); color: var(--info);">
                    <i class="fas fa-folder-open"></i>
                </div>
                <div class="stat-label"><?php echo t('open_trades', 'Open Trades'); ?></div>
                <div class="stat-value" style="color: var(--info);"><?php echo number_format($stats['open_trades'] ?? 0); ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); color: var(--accent);">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-label"><?php echo t('total_volume', 'Total Volume'); ?></div>
                <div class="stat-value" style="color: var(--accent);">$<?php echo number_format($stats['total_volume'] ?? 0, 2); ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--success);">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="stat-label"><?php echo t('platform_profit', 'Platform Profit'); ?></div>
                <div class="stat-value" style="color: var(--success);">$<?php echo number_format($stats['platform_profit'] ?? 0, 2); ?></div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <a href="?type=spot" class="tab-btn <?php echo $trade_type === 'spot' ? 'active' : ''; ?>">
                <i class="fas fa-chart-line"></i>
                <span><?php echo t('spot_trading', 'Spot Trading'); ?></span>
            </a>
            <a href="?type=binary" class="tab-btn <?php echo $trade_type === 'binary' ? 'active' : ''; ?>">
                <i class="fas fa-chart-bar"></i>
                <span><?php echo t('binary_trading', 'Binary Trading'); ?></span>
            </a>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <div class="filters-title">
                <i class="fas fa-filter"></i>
                <span><?php echo t('filters', 'Filters'); ?></span>
            </div>
            <form method="GET">
                <input type="hidden" name="type" value="<?php echo htmlspecialchars($trade_type); ?>">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label class="filter-label"><?php echo t('status', 'Status'); ?></label>
                        <select name="status" class="filter-select">
                            <option value="all"><?php echo t('all', 'All'); ?></option>
                            <?php if ($trade_type === 'spot'): ?>
                                <option value="open" <?php echo $status_filter === 'open' ? 'selected' : ''; ?>><?php echo t('open', 'Open'); ?></option>
                                <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>><?php echo t('closed', 'Closed'); ?></option>
                            <?php else: ?>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>><?php echo t('pending', 'Pending'); ?></option>
                                <option value="won" <?php echo $status_filter === 'won' ? 'selected' : ''; ?>><?php echo t('won', 'Won'); ?></option>
                                <option value="lost" <?php echo $status_filter === 'lost' ? 'selected' : ''; ?>><?php echo t('lost', 'Lost'); ?></option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label"><?php echo t('user', 'User'); ?></label>
                        <input type="text" name="user" class="filter-input" placeholder="<?php echo t('search_user', 'Search user...'); ?>" value="<?php echo htmlspecialchars($user_filter); ?>">
                    </div>

                    <div class="filter-group">
                        <label class="filter-label"><?php echo t('date_from', 'Date From'); ?></label>
                        <input type="date" name="date_from" class="filter-input" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>

                    <div class="filter-group">
                        <label class="filter-label"><?php echo t('date_to', 'Date To'); ?></label>
                        <input type="date" name="date_to" class="filter-input" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                </div>

                <div class="filter-actions">
                    <a href="?type=<?php echo $trade_type; ?>" class="btn btn-secondary">
                        <i class="fas fa-redo"></i>
                        <?php echo t('reset', 'Reset'); ?>
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i>
                        <?php echo t('search', 'Search'); ?>
                    </button>
                </div>
            </form>
        </div>

        <!-- Trades Table -->
        <div class="trades-card">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo t('id', 'ID'); ?></th>
                            <th><?php echo t('user', 'User'); ?></th>
                            <th><?php echo t('pair', 'Pair'); ?></th>
                            <?php if ($trade_type === 'spot'): ?>
                                <th><?php echo t('type', 'Type'); ?></th>
                                <th><?php echo t('amount', 'Amount'); ?></th>
                                <th><?php echo t('entry_price', 'Entry Price'); ?></th>
                                <th><?php echo t('current_price', 'Current Price'); ?></th>
                                <th>P/L</th>
                            <?php else: ?>
                                <th><?php echo t('direction', 'Direction'); ?></th>
                                <th><?php echo t('amount', 'Amount'); ?></th>
                                <th><?php echo t('duration', 'Duration'); ?></th>
                                <th><?php echo t('result', 'Result'); ?></th>
                            <?php endif; ?>
                            <th><?php echo t('status', 'Status'); ?></th>
                            <th><?php echo t('created_at', 'Created'); ?></th>
                            <th><?php echo t('actions', 'Actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($trades)): ?>
                            <tr>
                                <td colspan="12">
                                    <div class="empty-state">
                                        <i class="fas fa-inbox"></i>
                                        <p><?php echo t('no_trades_found', 'No trades found'); ?></p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($trades as $trade): ?>
                                <tr>
                                    <td><strong>#<?php echo $trade['id']; ?></strong></td>
                                    <td>
                                        <div class="user-cell">
                                            <div class="user-avatar">
                                                <?php echo strtoupper(substr($trade['username'], 0, 2)); ?>
                                            </div>
                                            <div class="user-info">
                                                <div class="user-name"><?php echo htmlspecialchars($trade['username']); ?></div>
                                                <div class="user-email"><?php echo htmlspecialchars($trade['email']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars(is_rtl() ? ($trade['pair_name_ar'] ?? $trade['symbol']) : ($trade['pair_name_en'] ?? $trade['symbol'])); ?></strong>
                                    </td>
                                    <?php if ($trade_type === 'spot'): ?>
                                        <td>
                                            <span class="badge <?php echo $trade['type']; ?>">
                                                <i class="fas fa-arrow-<?php echo $trade['type'] === 'buy' ? 'up' : 'down'; ?>"></i>
                                                <?php echo $trade['type'] === 'buy' ? t('buy', 'Buy') : t('sell', 'Sell'); ?>
                                            </span>
                                        </td>
                                        <td><strong>$<?php echo number_format($trade['amount'], 2); ?></strong></td>
                                        <td>$<?php echo number_format($trade['entry_price'], 2); ?></td>
                                        <td>
                                            <?php if ($trade['status'] === 'open'): ?>
                                                $<?php echo number_format($trade['market_price'], 2); ?>
                                            <?php else: ?>
                                                $<?php echo number_format($trade['exit_price'], 2); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $pl = $trade['status'] === 'open' ? $trade['current_profit_loss'] : $trade['profit_loss'];
                                            $pl_class = $pl >= 0 ? 'profit-positive' : 'profit-negative';
                                            ?>
                                            <span class="<?php echo $pl_class; ?>">
                                                <?php echo $pl >= 0 ? '+' : ''; ?>$<?php echo number_format($pl, 2); ?>
                                            </span>
                                        </td>
                                    <?php else: ?>
                                        <td>
                                            <span class="badge <?php echo $trade['direction']; ?>">
                                                <i class="fas fa-arrow-<?php echo $trade['direction'] === 'up' ? 'up' : 'down'; ?>"></i>
                                                <?php echo $trade['direction'] === 'up' ? t('up', 'Up') : t('down', 'Down'); ?>
                                            </span>
                                        </td>
                                        <td><strong>$<?php echo number_format($trade['amount'], 2); ?></strong></td>
                                        <td><?php echo $trade['duration']; ?>s</td>
                                        <td>
                                            <?php if ($trade['result']): ?>
                                                <span class="<?php echo $trade['result'] === 'won' ? 'profit-positive' : 'profit-negative'; ?>">
                                                    <?php echo $trade['result'] === 'won' ? '+$' . number_format($trade['payout'], 2) : '-$' . number_format($trade['amount'], 2); ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted);">-</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td>
                                        <span class="badge <?php echo $trade_type === 'spot' ? $trade['status'] : ($trade['status'] === 'pending' ? 'pending' : $trade['result']); ?>">
                                            <?php
                                            if ($trade_type === 'spot') {
                                                echo $trade['status'] === 'open' ? t('open', 'Open') : t('closed', 'Closed');
                                            } else {
                                                if ($trade['status'] === 'pending') {
                                                    echo t('pending', 'Pending');
                                                } else {
                                                    echo $trade['result'] === 'won' ? t('won', 'Won') : t('lost', 'Lost');
                                                }
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    <td style="font-size: 13px; color: var(--text-muted);">
                                        <?php echo date('Y-m-d H:i', strtotime($trade['created_at'])); ?>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button class="btn-icon view" onclick="viewTradeDetails(<?php echo $trade['id']; ?>, '<?php echo $trade_type; ?>')" title="<?php echo t('view_details', 'View Details'); ?>">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if ($trade_type === 'spot' && $trade['status'] === 'open'): ?>
                                                <button class="btn-icon close" onclick="closeSpotTrade(<?php echo $trade['id']; ?>)" title="<?php echo t('close_trade', 'Close Trade'); ?>">
                                                    <i class="fas fa-times-circle"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($trade_type === 'binary' && $trade['status'] === 'pending'): ?>
                                                <button class="btn-icon close" onclick="settleBinaryTrade(<?php echo $trade['id']; ?>)" title="<?php echo t('settle_trade', 'Settle Trade'); ?>">
                                                    <i class="fas fa-gavel"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button class="btn-icon delete" onclick="deleteTrade(<?php echo $trade['id']; ?>, '<?php echo $trade_type; ?>')" title="<?php echo t('delete', 'Delete'); ?>">
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
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?type=<?php echo $trade_type; ?>&status=<?php echo $status_filter; ?>&user=<?php echo urlencode($user_filter); ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&page=<?php echo $page - 1; ?>" class="pagination-btn">
                            <i class="fas fa-chevron-<?php echo is_rtl() ? 'right' : 'left'; ?>"></i>
                            <?php echo t('previous', 'Previous'); ?>
                        </a>
                    <?php else: ?>
                        <span class="pagination-btn disabled">
                            <i class="fas fa-chevron-<?php echo is_rtl() ? 'right' : 'left'; ?>"></i>
                            <?php echo t('previous', 'Previous'); ?>
                        </span>
                    <?php endif; ?>

                    <span class="pagination-info">
                        <?php echo t('page', 'Page'); ?> <?php echo $page; ?> <?php echo t('of', 'of'); ?> <?php echo $total_pages; ?>
                    </span>

                    <?php if ($page < $total_pages): ?>
                        <a href="?type=<?php echo $trade_type; ?>&status=<?php echo $status_filter; ?>&user=<?php echo urlencode($user_filter); ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&page=<?php echo $page + 1; ?>" class="pagination-btn">
                            <?php echo t('next', 'Next'); ?>
                            <i class="fas fa-chevron-<?php echo is_rtl() ? 'left' : 'right'; ?>"></i>
                        </a>
                    <?php else: ?>
                        <span class="pagination-btn disabled">
                            <?php echo t('next', 'Next'); ?>
                            <i class="fas fa-chevron-<?php echo is_rtl() ? 'left' : 'right'; ?>"></i>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Trade Details Modal -->
    <div class="modal" id="tradeModal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">
                    <i class="fas fa-file-invoice"></i>
                    <span><?php echo t('trade_details', 'Trade Details'); ?></span>
                </div>
                <button class="modal-close" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="tradeDetailsContent">
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 32px; color: var(--accent);"></i>
                </div>
            </div>
        </div>
    </div>

    <?php render_admin_base_js(); ?>
    <script>
        function viewTradeDetails(tradeId, tradeType) {
            const modal = document.getElementById('tradeModal');
            const content = document.getElementById('tradeDetailsContent');

            modal.classList.add('active');
            content.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-spin" style="font-size: 32px; color: var(--accent);"></i></div>';

            fetch('trades.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=get_trade_details&trade_id=${tradeId}&trade_type=${tradeType}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const trade = data.trade;
                    const isRtl = <?php echo is_rtl() ? 'true' : 'false'; ?>;
                    const pairName = isRtl ? (trade.pair_name_ar || trade.symbol) : (trade.pair_name_en || trade.symbol);

                    let html = '';

                    if (tradeType === 'spot') {
                        const pl = trade.status === 'open'
                            ? (trade.type === 'buy'
                                ? (trade.market_price - trade.entry_price) * trade.quantity
                                : (trade.entry_price - trade.market_price) * trade.quantity)
                            : trade.profit_loss;
                        const plClass = pl >= 0 ? 'profit-positive' : 'profit-negative';

                        html = `
                            <div class="detail-row">
                                <span class="detail-label"><?php echo t('trade_id', 'Trade ID'); ?></span>
                                <span class="detail-value"><strong>#${trade.id}</strong></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label"><?php echo t('user', 'User'); ?></span>
                                <span class="detail-value">${trade.username} (${trade.email})</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label"><?php echo t('pair', 'Pair'); ?></span>
                                <span class="detail-value"><strong>${pairName}</strong></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label"><?php echo t('type', 'Type'); ?></span>
                                <span class="detail-value"><span class="badge ${trade.type}">${trade.type === 'buy' ? '<?php echo t('buy', 'Buy'); ?>' : '<?php echo t('sell', 'Sell'); ?>'}</span></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label"><?php echo t('amount', 'Amount'); ?></span>
                                <span class="detail-value"><strong>$${parseFloat(trade.amount).toFixed(2)}</strong></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label"><?php echo t('quantity', 'Quantity'); ?></span>
                                <span class="detail-value">${parseFloat(trade.quantity).toFixed(8)}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label"><?php echo t('entry_price', 'Entry Price'); ?></span>
                                <span class="detail-value">$${parseFloat(trade.entry_price).toFixed(2)}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">${trade.status === 'open' ? '<?php echo t('current_price', 'Current Price'); ?>' : '<?php echo t('exit_price', 'Exit Price'); ?>'}</span>
                                <span class="detail-value">$${parseFloat(trade.status === 'open' ? trade.market_price : trade.exit_price).toFixed(2)}</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">P/L</span>
                                <span class="detail-value ${plClass}"><strong>${pl >= 0 ? '+' : ''}$${pl.toFixed(2)}</strong></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label"><?php echo t('status', 'Status'); ?></span>
                                <span class="detail-value"><span class="badge ${trade.status}">${trade.status === 'open' ? '<?php echo t('open', 'Open'); ?>' : '<?php echo t('closed', 'Closed'); ?>'}</span></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label"><?php echo t('created_at', 'Created'); ?></span>
                                <span class="detail-value">${new Date(trade.created_at).toLocaleString()}</span>
                            </div>
                            ${trade.closed_at ? `
                            <div class="detail-row">
                                <span class="detail-label"><?php echo t('closed_at', 'Closed'); ?></span>
                                <span class="detail-value">${new Date(trade.closed_at).toLocaleString()}</span>
                            </div>` : ''}
                        `;
                    } else {
                        html = `
                            <div class="detail-row">
                                <span class="detail-label"><?php echo t('trade_id', 'Trade ID'); ?></span>
                                <span class="detail-value"><strong>#${trade.id}</strong></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label"><?php echo t('user', 'User'); ?></span>
                                <span class="detail-value">${trade.username} (${trade.email})</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label"><?php echo t('pair', 'Pair'); ?></span>
                                <span class="detail-value"><strong>${pairName}</strong></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label"><?php echo t('direction', 'Direction'); ?></span>
                                <span class="detail-value"><span class="badge ${trade.direction}">${trade.direction === 'up' ? '<?php echo t('up', 'Up'); ?>' : '<?php echo t('down', 'Down'); ?>'}</span></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label"><?php echo t('amount', 'Amount'); ?></span>
                                <span class="detail-value"><strong>$${parseFloat(trade.amount).toFixed(2)}</strong></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label"><?php echo t('payout', 'Payout'); ?></span>
                                <span class="detail-value" style="color: var(--success);"><strong>$${parseFloat(trade.payout).toFixed(2)}</strong></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label"><?php echo t('duration', 'Duration'); ?></span>
                                <span class="detail-value">${trade.duration}s</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label"><?php echo t('entry_price', 'Entry Price'); ?></span>
                                <span class="detail-value">$${parseFloat(trade.entry_price).toFixed(2)}</span>
                            </div>
                            ${trade.exit_price ? `
                            <div class="detail-row">
                                <span class="detail-label"><?php echo t('exit_price', 'Exit Price'); ?></span>
                                <span class="detail-value">$${parseFloat(trade.exit_price).toFixed(2)}</span>
                            </div>` : ''}
                            <div class="detail-row">
                                <span class="detail-label"><?php echo t('result', 'Result'); ?></span>
                                <span class="detail-value">
                                    ${trade.result ? `<span class="badge ${trade.result}">${trade.result === 'won' ? '<?php echo t('won', 'Won'); ?>' : '<?php echo t('lost', 'Lost'); ?>'}</span>` : '<span style="color: var(--text-muted);">-</span>'}
                                </span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label"><?php echo t('status', 'Status'); ?></span>
                                <span class="detail-value"><span class="badge ${trade.status === 'pending' ? 'pending' : trade.result}">${trade.status === 'pending' ? '<?php echo t('pending', 'Pending'); ?>' : (trade.result === 'won' ? '<?php echo t('won', 'Won'); ?>' : '<?php echo t('lost', 'Lost'); ?>')}</span></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label"><?php echo t('created_at', 'Created'); ?></span>
                                <span class="detail-value">${new Date(trade.created_at).toLocaleString()}</span>
                            </div>
                            ${trade.settled_at ? `
                            <div class="detail-row">
                                <span class="detail-label"><?php echo t('settled_at', 'Settled'); ?></span>
                                <span class="detail-value">${new Date(trade.settled_at).toLocaleString()}</span>
                            </div>` : ''}
                        `;
                    }

                    content.innerHTML = html;
                } else {
                    showToast(data.message || '<?php echo t('error_occurred', 'An error occurred'); ?>', 'error');
                    closeModal();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('<?php echo t('error_occurred', 'An error occurred'); ?>', 'error');
                closeModal();
            });
        }

        function closeModal() {
            document.getElementById('tradeModal').classList.remove('active');
        }

        function closeSpotTrade(tradeId) {
            if (!confirm('<?php echo t('confirm_close_trade', 'Are you sure you want to close this trade?'); ?>')) return;

            fetch('trades.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=close_spot_trade&trade_id=${tradeId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('<?php echo t('trade_closed_successfully', 'Trade closed successfully'); ?>', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.message || '<?php echo t('error_occurred', 'An error occurred'); ?>', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('<?php echo t('error_occurred', 'An error occurred'); ?>', 'error');
            });
        }

        function settleBinaryTrade(tradeId) {
            const result = prompt('<?php echo t('enter_result', 'Enter result (won/lost)'); ?>:');
            if (!result || !['won', 'lost'].includes(result.toLowerCase())) {
                showToast('<?php echo t('invalid_result', 'Invalid result'); ?>', 'error');
                return;
            }

            fetch('trades.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=settle_binary_trade&trade_id=${tradeId}&result=${result.toLowerCase()}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('<?php echo t('trade_settled_successfully', 'Trade settled successfully'); ?>', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.message || '<?php echo t('error_occurred', 'An error occurred'); ?>', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('<?php echo t('error_occurred', 'An error occurred'); ?>', 'error');
            });
        }

        function deleteTrade(tradeId, tradeType) {
            if (!confirm('<?php echo t('confirm_delete_trade', 'Are you sure you want to delete this trade? This action cannot be undone.'); ?>')) return;

            fetch('trades.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=delete_trade&trade_id=${tradeId}&trade_type=${tradeType}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('<?php echo t('trade_deleted_successfully', 'Trade deleted successfully'); ?>', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.message || '<?php echo t('error_occurred', 'An error occurred'); ?>', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('<?php echo t('error_occurred', 'An error occurred'); ?>', 'error');
            });
        }

        // Close modal when clicking outside
        document.getElementById('tradeModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
    </script>
</body>
</html>
