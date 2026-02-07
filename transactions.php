<?php
define('APP_ACCESS', true);
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/components.php';

// Check authentication
require_login();

$user_id = $_SESSION['user_id'];
$user = get_user_data($pdo, $user_id);

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Get unread notifications count
$unread_count = count(get_user_notifications($pdo, $user_id, true));

// Filters
$type_filter = sanitize($_GET['type'] ?? 'all');
$status_filter = sanitize($_GET['status'] ?? 'all');
$search = sanitize($_GET['search'] ?? '');
$date_from = sanitize($_GET['date_from'] ?? '');
$date_to = sanitize($_GET['date_to'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build query
$where = ["user_id = ?"];
$params = [$user_id];

if ($type_filter !== 'all') {
    if ($type_filter === 'trades') {
        $where[] = "(type = 'trade_profit' OR type = 'trade_loss')";
    } else {
        $where[] = "type = ?";
        $params[] = $type_filter;
    }
}

if ($status_filter !== 'all') {
    $where[] = "status = ?";
    $params[] = $status_filter;
}

if (!empty($search)) {
    $where[] = "(transaction_id LIKE ? OR reference LIKE ? OR description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($date_from)) {
    $where[] = "DATE(created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where[] = "DATE(created_at) <= ?";
    $params[] = $date_to;
}

$where_clause = implode(' AND ', $where);

// Get total count
try {
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE $where_clause");
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $per_page);
} catch (PDOException $e) {
    error_log("Count transactions error: " . $e->getMessage());
    $total_records = 0;
    $total_pages = 1;
}

// Get transactions
try {
    $stmt = $pdo->prepare("
        SELECT * FROM transactions
        WHERE $where_clause
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");

    $params[] = $per_page;
    $params[] = $offset;
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Get transactions error: " . $e->getMessage());
    $transactions = [];
}

// Get statistics
$stats = [
    'total_deposits' => 0,
    'total_withdrawals' => 0,
    'pending_count' => 0,
    'net_balance' => 0
];

try {
    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN type = 'deposit' AND status = 'completed' THEN amount ELSE 0 END) as total_deposits,
            SUM(CASE WHEN type = 'withdrawal' AND status = 'completed' THEN amount ELSE 0 END) as total_withdrawals,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count
        FROM transactions
        WHERE user_id = ?
    ");
    $stmt->execute([$user_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['net_balance'] = ($stats['total_deposits'] ?? 0) - ($stats['total_withdrawals'] ?? 0);
} catch (PDOException $e) {
    error_log("Get transaction stats error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="<?= current_language() ?>" dir="<?= is_rtl() ? 'rtl' : 'ltr' ?>">
<head>
    <?php render_head(t('transactions')); ?>
    <?php render_base_css(); ?>
    <style>
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
        }

        body {
            background: var(--primary);
            color: var(--text);
        }

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
            background: linear-gradient(135deg, var(--text), var(--accent));
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

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            <?= is_rtl() ? 'right' : 'left' ?>: 0;
            width: 100px;
            height: 100px;
            background: radial-gradient(circle, rgba(249, 158, 11, 0.1), transparent);
            border-radius: 50%;
            transform: translate(-30%, -30%);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 12px;
        }

        .stat-icon.deposit { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .stat-icon.withdrawal { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
        .stat-icon.pending { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .stat-icon.balance { background: rgba(59, 130, 246, 0.1); color: var(--info); }

        .stat-label {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 4px;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 900;
            color: var(--text);
        }

        /* Filter Tabs */
        .filter-section {
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
        }

        .filter-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 10px 20px;
            background: transparent;
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text-muted);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .tab-btn:hover {
            background: rgba(249, 158, 11, 0.05);
            color: var(--text);
        }

        .tab-btn.active {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
        }

        .filter-controls {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
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
            transition: all 0.3s;
        }

        .filter-input:focus,
        .filter-select:focus {
            outline: none;
            border-color: var(--accent);
        }

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
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent), #DC2626);
            color: white;
            box-shadow: 0 8px 24px rgba(249, 158, 11, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(249, 158, 11, 0.4);
        }

        .btn-secondary {
            background: var(--secondary);
            color: var(--text);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: #2D3748;
        }

        /* Transactions Table */
        .transactions-card {
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
        }

        .transactions-header {
            padding: 24px;
            border-bottom: 1px solid var(--border);
        }

        .transactions-title {
            font-size: 20px;
            font-weight: 700;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: rgba(249, 158, 11, 0.05);
        }

        th {
            padding: 16px 24px;
            text-align: <?= is_rtl() ? 'right' : 'left' ?>;
            font-weight: 700;
            font-size: 13px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 16px 24px;
            border-top: 1px solid var(--border);
        }

        tbody tr {
            transition: background 0.2s;
            cursor: pointer;
        }

        tbody tr:hover {
            background: rgba(249, 158, 11, 0.05);
        }

        .transaction-id {
            font-family: monospace;
            color: var(--accent);
            font-weight: 600;
        }

        .transaction-type {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
        }

        .transaction-type.deposit {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .transaction-type.withdrawal {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .transaction-type.trade_profit,
        .transaction-type.trade_loss {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .amount {
            font-weight: 700;
            font-size: 16px;
        }

        .amount.positive { color: var(--success); }
        .amount.negative { color: var(--danger); }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
        }

        .status-badge.completed,
        .status-badge.approved {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .status-badge.pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .status-badge.rejected {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .status-badge i.fa-spinner {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .transaction-date {
            font-size: 13px;
            color: var(--text-muted);
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
            padding: 8px 16px;
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
            background: var(--accent);
            color: white;
            border-color: var(--accent);
        }

        .pagination-btn.active {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
        }

        .pagination-btn.disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }

        .pagination-info {
            padding: 8px 16px;
            color: var(--text-muted);
            font-size: 14px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 64px 24px;
        }

        .empty-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 24px;
            background: rgba(249, 158, 11, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: var(--accent);
        }

        .empty-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .empty-text {
            color: var(--text-muted);
            margin-bottom: 24px;
        }

        /* Transaction Detail Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
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
            border: 1px solid var(--border);
            border-radius: 16px;
            max-width: 600px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 20px;
            font-weight: 700;
        }

        .modal-close {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            cursor: pointer;
            transition: all 0.3s;
            font-size: 16px;
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
            padding: 16px 0;
            border-bottom: 1px solid var(--border);
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-size: 14px;
            color: var(--text-muted);
            font-weight: 600;
        }

        .detail-value {
            font-size: 14px;
            color: var(--text);
            font-weight: 600;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .main {
                margin-<?= is_rtl() ? 'right' : 'left' ?>: 0;
                padding: 20px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .filter-controls {
                grid-template-columns: 1fr;
            }

            .filter-tabs {
                flex-direction: column;
            }

            .tab-btn {
                width: 100%;
                justify-content: center;
            }

            .table-wrapper {
                overflow-x: scroll;
            }

            table {
                min-width: 800px;
            }

            .page-title {
                font-size: 24px;
            }

            .modal-content {
                margin: 0 10px;
            }
        }
    </style>
</head>
<body>
    <?php render_sidebar('transactions', $user, $unread_count); ?>

    <!-- Main Content -->
    <main class="main">
        <div class="header">
            <h1 class="page-title"><?= t('transactions') ?></h1>
            <div class="breadcrumb">
                <a href="dashboard.php"><?= t('home') ?></a>
                <i class="fas fa-chevron-<?= is_rtl() ? 'left' : 'right' ?>" style="font-size: 10px;"></i>
                <span><?= t('transactions') ?></span>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon deposit">
                    <i class="fas fa-arrow-down"></i>
                </div>
                <div class="stat-label"><?= t('total_deposits') ?></div>
                <div class="stat-value amount positive">$<?= number_format($stats['total_deposits'] ?? 0, 2) ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon withdrawal">
                    <i class="fas fa-arrow-up"></i>
                </div>
                <div class="stat-label"><?= t('total_withdrawals') ?></div>
                <div class="stat-value amount negative">$<?= number_format($stats['total_withdrawals'] ?? 0, 2) ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon pending">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-label"><?= t('pending_transactions') ?></div>
                <div class="stat-value"><?= $stats['pending_count'] ?? 0 ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon balance">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-label"><?= t('net_balance') ?></div>
                <div class="stat-value amount <?= ($stats['net_balance'] ?? 0) >= 0 ? 'positive' : 'negative' ?>">$<?= number_format($stats['net_balance'] ?? 0, 2) ?></div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-section">
            <!-- Filter Tabs -->
            <div class="filter-tabs">
                <a href="?type=all&status=<?= $status_filter ?>&search=<?= urlencode($search) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>"
                   class="tab-btn <?= $type_filter === 'all' ? 'active' : '' ?>">
                    <i class="fas fa-list"></i>
                    <?= t('all') ?>
                </a>
                <a href="?type=deposit&status=<?= $status_filter ?>&search=<?= urlencode($search) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>"
                   class="tab-btn <?= $type_filter === 'deposit' ? 'active' : '' ?>">
                    <i class="fas fa-arrow-down"></i>
                    <?= t('deposits') ?>
                </a>
                <a href="?type=withdrawal&status=<?= $status_filter ?>&search=<?= urlencode($search) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>"
                   class="tab-btn <?= $type_filter === 'withdrawal' ? 'active' : '' ?>">
                    <i class="fas fa-arrow-up"></i>
                    <?= t('withdrawals') ?>
                </a>
                <a href="?type=trades&status=<?= $status_filter ?>&search=<?= urlencode($search) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>"
                   class="tab-btn <?= $type_filter === 'trades' ? 'active' : '' ?>">
                    <i class="fas fa-exchange-alt"></i>
                    <?= t('trades') ?>
                </a>
            </div>

            <!-- Filter Controls -->
            <form method="GET" action="transactions.php">
                <input type="hidden" name="type" value="<?= htmlspecialchars($type_filter) ?>">
                <div class="filter-controls">
                    <div class="filter-group">
                        <label class="filter-label"><?= t('status') ?></label>
                        <select name="status" class="filter-select">
                            <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>><?= t('all') ?></option>
                            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>><?= t('pending') ?></option>
                            <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>><?= t('approved') ?></option>
                            <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>><?= t('completed') ?></option>
                            <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>><?= t('rejected') ?></option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label"><?= t('search') ?></label>
                        <input type="text" name="search" class="filter-input"
                               placeholder="<?= t('transaction_id_or_reference') ?>"
                               value="<?= htmlspecialchars($search) ?>">
                    </div>

                    <div class="filter-group">
                        <label class="filter-label"><?= t('date_from') ?></label>
                        <input type="date" name="date_from" class="filter-input" value="<?= htmlspecialchars($date_from) ?>">
                    </div>

                    <div class="filter-group">
                        <label class="filter-label"><?= t('date_to') ?></label>
                        <input type="date" name="date_to" class="filter-input" value="<?= htmlspecialchars($date_to) ?>">
                    </div>

                    <div class="filter-group">
                        <label class="filter-label" style="opacity: 0;"><?= t('search') ?></label>
                        <button type="submit" class="btn btn-primary" style="width: 100%;">
                            <i class="fas fa-search"></i>
                            <?= t('search') ?>
                        </button>
                    </div>

                    <?php if ($type_filter !== 'all' || $status_filter !== 'all' || !empty($search) || !empty($date_from) || !empty($date_to)): ?>
                    <div class="filter-group">
                        <label class="filter-label" style="opacity: 0;"><?= t('reset') ?></label>
                        <a href="transactions.php" class="btn btn-secondary" style="width: 100%; justify-content: center;">
                            <i class="fas fa-times"></i>
                            <?= t('reset') ?>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Transactions Table -->
        <div class="transactions-card">
            <div class="transactions-header">
                <h3 class="transactions-title"><?= t('transaction_history') ?> (<?= $total_records ?> <?= t('transactions') ?>)</h3>
            </div>

            <?php if (empty($transactions)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-receipt"></i>
                    </div>
                    <h3 class="empty-title"><?= t('no_transactions') ?></h3>
                    <p class="empty-text"><?= t('no_transactions_yet') ?></p>
                    <a href="p2p-deposit.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i>
                        <?= t('add_funds_now') ?>
                    </a>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th><?= t('transaction_id') ?></th>
                                <th><?= t('type') ?></th>
                                <th><?= t('amount') ?></th>
                                <th><?= t('status') ?></th>
                                <th><?= t('gateway') ?></th>
                                <th><?= t('date') ?></th>
                                <th><?= t('details') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $transaction): ?>
                                <tr onclick="showTransactionDetail(<?= htmlspecialchars(json_encode($transaction)) ?>)">
                                    <td>
                                        <span class="transaction-id">#<?= htmlspecialchars($transaction['transaction_id']) ?></span>
                                    </td>
                                    <td>
                                        <?php
                                        $type = $transaction['type'];
                                        $type_icons = [
                                            'deposit' => 'arrow-down',
                                            'withdrawal' => 'arrow-up',
                                            'trade_profit' => 'chart-line',
                                            'trade_loss' => 'chart-line'
                                        ];
                                        $icon = $type_icons[$type] ?? 'circle';
                                        ?>
                                        <span class="transaction-type <?= htmlspecialchars($type) ?>">
                                            <i class="fas fa-<?= $icon ?>"></i>
                                            <?= t($type) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $is_positive = in_array($type, ['deposit', 'trade_profit']);
                                        $is_negative = in_array($type, ['withdrawal', 'trade_loss']);
                                        ?>
                                        <span class="amount <?= $is_positive ? 'positive' : ($is_negative ? 'negative' : '') ?>">
                                            <?= $is_positive ? '+' : ($is_negative ? '-' : '') ?>
                                            $<?= number_format($transaction['amount'], 2) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $status = $transaction['status'];
                                        $status_icons = [
                                            'completed' => 'check-circle',
                                            'approved' => 'check-circle',
                                            'pending' => 'clock',
                                            'rejected' => 'times-circle'
                                        ];
                                        $status_icon = $status_icons[$status] ?? 'spinner';
                                        ?>
                                        <span class="status-badge <?= htmlspecialchars($status) ?>">
                                            <i class="fas fa-<?= $status_icon ?>"></i>
                                            <?= t($status) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($transaction['gateway'] ?? t('n/a')) ?></td>
                                    <td>
                                        <div class="transaction-date">
                                            <?= date('Y/m/d', strtotime($transaction['created_at'])) ?>
                                            <br>
                                            <small style="color: var(--text-muted);"><?= date('h:i A', strtotime($transaction['created_at'])) ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <button class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px;"
                                                onclick="event.stopPropagation(); showTransactionDetail(<?= htmlspecialchars(json_encode($transaction)) ?>)">
                                            <i class="fas fa-eye"></i>
                                            <?= t('view') ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php
                        $prev_icon = is_rtl() ? 'right' : 'left';
                        $next_icon = is_rtl() ? 'left' : 'right';
                        $query_params = http_build_query([
                            'type' => $type_filter,
                            'status' => $status_filter,
                            'search' => $search,
                            'date_from' => $date_from,
                            'date_to' => $date_to
                        ]);
                        ?>

                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page - 1 ?>&<?= $query_params ?>" class="pagination-btn">
                                <i class="fas fa-chevron-<?= $prev_icon ?>"></i>
                                <?= t('previous') ?>
                            </a>
                        <?php else: ?>
                            <span class="pagination-btn disabled">
                                <i class="fas fa-chevron-<?= $prev_icon ?>"></i>
                                <?= t('previous') ?>
                            </span>
                        <?php endif; ?>

                        <span class="pagination-info">
                            <?= t('page') ?> <?= $page ?> <?= t('of') ?> <?= $total_pages ?>
                        </span>

                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?= $page + 1 ?>&<?= $query_params ?>" class="pagination-btn">
                                <?= t('next') ?>
                                <i class="fas fa-chevron-<?= $next_icon ?>"></i>
                            </a>
                        <?php else: ?>
                            <span class="pagination-btn disabled">
                                <?= t('next') ?>
                                <i class="fas fa-chevron-<?= $next_icon ?>"></i>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>

    <!-- Transaction Detail Modal -->
    <div id="transactionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><?= t('transaction_details') ?></h3>
                <button class="modal-close" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="modalBody">
                <!-- Content will be populated by JavaScript -->
            </div>
        </div>
    </div>

    <?php render_base_js(); ?>
    <script>
        function showTransactionDetail(transaction) {
            const modal = document.getElementById('transactionModal');
            const modalBody = document.getElementById('modalBody');

            // Format type
            const typeIcons = {
                'deposit': 'arrow-down',
                'withdrawal': 'arrow-up',
                'trade_profit': 'chart-line',
                'trade_loss': 'chart-line'
            };
            const typeIcon = typeIcons[transaction.type] || 'circle';
            const typeClass = transaction.type;

            // Format status
            const statusIcons = {
                'completed': 'check-circle',
                'approved': 'check-circle',
                'pending': 'clock',
                'rejected': 'times-circle'
            };
            const statusIcon = statusIcons[transaction.status] || 'spinner';

            // Format amount
            const isPositive = ['deposit', 'trade_profit'].includes(transaction.type);
            const isNegative = ['withdrawal', 'trade_loss'].includes(transaction.type);
            const amountClass = isPositive ? 'positive' : (isNegative ? 'negative' : '');
            const amountSign = isPositive ? '+' : (isNegative ? '-' : '');

            modalBody.innerHTML = `
                <div class="detail-row">
                    <span class="detail-label"><?= t('transaction_id') ?></span>
                    <span class="detail-value transaction-id">#${transaction.transaction_id}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label"><?= t('type') ?></span>
                    <span class="detail-value">
                        <span class="transaction-type ${typeClass}">
                            <i class="fas fa-${typeIcon}"></i>
                            <?= is_rtl() ? '${getArabicType(transaction.type)}' : '${transaction.type}' ?>
                        </span>
                    </span>
                </div>
                <div class="detail-row">
                    <span class="detail-label"><?= t('amount') ?></span>
                    <span class="detail-value amount ${amountClass}">${amountSign}$${parseFloat(transaction.amount).toFixed(2)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label"><?= t('status') ?></span>
                    <span class="detail-value">
                        <span class="status-badge ${transaction.status}">
                            <i class="fas fa-${statusIcon}"></i>
                            <?= is_rtl() ? '${getArabicStatus(transaction.status)}' : '${transaction.status}' ?>
                        </span>
                    </span>
                </div>
                ${transaction.gateway ? `
                <div class="detail-row">
                    <span class="detail-label"><?= t('gateway') ?></span>
                    <span class="detail-value">${transaction.gateway}</span>
                </div>
                ` : ''}
                ${transaction.reference ? `
                <div class="detail-row">
                    <span class="detail-label"><?= t('reference') ?></span>
                    <span class="detail-value">${transaction.reference}</span>
                </div>
                ` : ''}
                ${transaction.description ? `
                <div class="detail-row">
                    <span class="detail-label"><?= t('description') ?></span>
                    <span class="detail-value">${transaction.description}</span>
                </div>
                ` : ''}
                <div class="detail-row">
                    <span class="detail-label"><?= t('date') ?></span>
                    <span class="detail-value">${new Date(transaction.created_at).toLocaleString('<?= current_language() === 'ar' ? 'ar-SA' : 'en-US' ?>')}</span>
                </div>
                ${transaction.updated_at && transaction.updated_at !== transaction.created_at ? `
                <div class="detail-row">
                    <span class="detail-label"><?= t('updated_at') ?></span>
                    <span class="detail-value">${new Date(transaction.updated_at).toLocaleString('<?= current_language() === 'ar' ? 'ar-SA' : 'en-US' ?>')}</span>
                </div>
                ` : ''}
            `;

            modal.classList.add('active');
        }

        function closeModal() {
            document.getElementById('transactionModal').classList.remove('active');
        }

        // Close modal on outside click
        document.getElementById('transactionModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        <?php if (current_language() === 'ar'): ?>
        function getArabicType(type) {
            const types = {
                'deposit': 'إيداع',
                'withdrawal': 'سحب',
                'trade_profit': 'ربح تداول',
                'trade_loss': 'خسارة تداول'
            };
            return types[type] || type;
        }

        function getArabicStatus(status) {
            const statuses = {
                'completed': 'مكتمل',
                'approved': 'موافق عليه',
                'pending': 'قيد الانتظار',
                'rejected': 'مرفوض'
            };
            return statuses[status] || status;
        }
        <?php endif; ?>

        // Auto-refresh pending transactions every 30 seconds
        <?php if (!empty(array_filter($transactions, fn($t) => $t['status'] === 'pending'))): ?>
        setTimeout(() => {
            location.reload();
        }, 30000);
        <?php endif; ?>
    </script>
</body>
</html>
