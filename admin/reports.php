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

// Date range filter
$start_date = isset($_GET['start_date']) ? sanitize($_GET['start_date']) : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? sanitize($_GET['end_date']) : date('Y-m-d');

// ==========================================
// CSV EXPORT
// ==========================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $export_type = sanitize($_GET['type'] ?? 'revenue');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report_' . $export_type . '_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    // UTF-8 BOM for Excel
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    try {
        if ($export_type === 'revenue') {
            fputcsv($output, ['Date', 'Fee Revenue', 'Trade Loss Revenue', 'Total']);
            $stmt = $pdo->prepare("
                SELECT DATE(created_at) as date,
                    SUM(CASE WHEN type = 'fee' THEN amount ELSE 0 END) as fee_revenue,
                    SUM(CASE WHEN type = 'trade_loss' THEN ABS(amount) ELSE 0 END) as trade_loss_revenue
                FROM transactions WHERE status = 'completed'
                AND DATE(created_at) BETWEEN ? AND ?
                GROUP BY DATE(created_at) ORDER BY date
            ");
            $stmt->execute([$start_date, $end_date]);
            while ($row = $stmt->fetch()) {
                $total = $row['fee_revenue'] + $row['trade_loss_revenue'];
                fputcsv($output, [$row['date'], $row['fee_revenue'], $row['trade_loss_revenue'], $total]);
            }
        } elseif ($export_type === 'traders') {
            fputcsv($output, ['Username', 'Full Name', 'Total Trades', 'Total Profit/Loss', 'Win Rate %']);
            $stmt = $pdo->query("
                SELECT u.username, u.full_name,
                    (SELECT COUNT(*) FROM spot_trades WHERE user_id = u.id AND status = 'closed') +
                    (SELECT COUNT(*) FROM binary_trades WHERE user_id = u.id AND status IN ('won','lost')) as total_trades,
                    COALESCE((SELECT SUM(profit_loss) FROM spot_trades WHERE user_id = u.id AND status = 'closed'), 0) +
                    COALESCE((SELECT SUM(profit_loss) FROM binary_trades WHERE user_id = u.id AND status IN ('won','lost')), 0) as total_profit,
                    CASE WHEN (SELECT COUNT(*) FROM binary_trades WHERE user_id = u.id AND status IN ('won','lost')) > 0
                        THEN ROUND((SELECT COUNT(*) FROM binary_trades WHERE user_id = u.id AND result = 'won') * 100.0 /
                            (SELECT COUNT(*) FROM binary_trades WHERE user_id = u.id AND status IN ('won','lost')), 1)
                        ELSE 0 END as win_rate
                FROM users u ORDER BY total_profit DESC LIMIT 50
            ");
            while ($row = $stmt->fetch()) {
                fputcsv($output, [$row['username'], $row['full_name'], $row['total_trades'], $row['total_profit'], $row['win_rate']]);
            }
        } elseif ($export_type === 'transactions') {
            fputcsv($output, ['Type', 'Count', 'Total Amount', 'Average Amount']);
            $stmt = $pdo->prepare("
                SELECT type, COUNT(*) as count, SUM(amount) as total, AVG(amount) as avg_amount
                FROM transactions WHERE status = 'completed'
                AND DATE(created_at) BETWEEN ? AND ?
                GROUP BY type ORDER BY total DESC
            ");
            $stmt->execute([$start_date, $end_date]);
            while ($row = $stmt->fetch()) {
                fputcsv($output, [$row['type'], $row['count'], number_format($row['total'], 2), number_format($row['avg_amount'], 2)]);
            }
        } elseif ($export_type === 'gateways') {
            fputcsv($output, ['Gateway', 'Transaction Count', 'Volume', 'Success Rate %']);
            $stmt = $pdo->query("
                SELECT pg.name as gateway_name,
                    COUNT(t.id) as tx_count,
                    SUM(CASE WHEN t.status = 'completed' THEN t.amount ELSE 0 END) as volume,
                    ROUND(COUNT(CASE WHEN t.status = 'completed' THEN 1 END) * 100.0 / NULLIF(COUNT(t.id), 0), 1) as success_rate
                FROM payment_gateways pg
                LEFT JOIN transactions t ON pg.id = t.gateway_id
                GROUP BY pg.id ORDER BY volume DESC
            ");
            while ($row = $stmt->fetch()) {
                fputcsv($output, [$row['gateway_name'], $row['tx_count'], number_format($row['volume'], 2), $row['success_rate']]);
            }
        }
    } catch (PDOException $e) {
        fputcsv($output, ['Error generating report']);
    }

    fclose($output);
    exit;
}

// ==========================================
// DATA QUERIES
// ==========================================
try {
    // Revenue breakdown (current period)
    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN type = 'fee' THEN amount ELSE 0 END) as fee_revenue,
            SUM(CASE WHEN type = 'trade_loss' THEN ABS(amount) ELSE 0 END) as trade_loss_revenue,
            SUM(CASE WHEN type = 'withdrawal' AND status = 'completed' THEN ABS(amount) * 0.01 ELSE 0 END) as withdrawal_fees,
            SUM(CASE WHEN type = 'p2p_fee' THEN amount ELSE 0 END) as p2p_fees
        FROM transactions
        WHERE status = 'completed'
        AND DATE(created_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$start_date, $end_date]);
    $revenue = $stmt->fetch();

    // Revenue for previous period (for % change calculation)
    $period_days = (strtotime($end_date) - strtotime($start_date)) / 86400;
    $prev_start = date('Y-m-d', strtotime($start_date . " -$period_days days"));
    $prev_end = date('Y-m-d', strtotime($start_date . ' -1 day'));

    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN type = 'fee' THEN amount ELSE 0 END) as fee_revenue,
            SUM(CASE WHEN type = 'trade_loss' THEN ABS(amount) ELSE 0 END) as trade_loss_revenue,
            SUM(CASE WHEN type = 'withdrawal' AND status = 'completed' THEN ABS(amount) * 0.01 ELSE 0 END) as withdrawal_fees,
            SUM(CASE WHEN type = 'p2p_fee' THEN amount ELSE 0 END) as p2p_fees
        FROM transactions
        WHERE status = 'completed'
        AND DATE(created_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$prev_start, $prev_end]);
    $prev_revenue = $stmt->fetch();

    // User registration stats (last 30 days)
    $user_stats = $pdo->query("
        SELECT DATE(created_at) as date, COUNT(*) as count
        FROM users
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date
    ")->fetchAll();

    // Transaction stats by type
    $stmt = $pdo->prepare("
        SELECT
            type,
            COUNT(*) as count,
            SUM(amount) as total,
            AVG(amount) as avg_amount
        FROM transactions
        WHERE status = 'completed'
        AND DATE(created_at) BETWEEN ? AND ?
        GROUP BY type
        ORDER BY total DESC
    ");
    $stmt->execute([$start_date, $end_date]);
    $tx_stats = $stmt->fetchAll();

    // Top 10 traders
    $top_traders = $pdo->query("
        SELECT u.id, u.username, u.full_name,
            (SELECT COUNT(*) FROM spot_trades WHERE user_id = u.id AND status = 'closed') as spot_trades_count,
            (SELECT COUNT(*) FROM binary_trades WHERE user_id = u.id AND status IN ('won','lost')) as binary_trades_count,
            COALESCE((SELECT SUM(profit_loss) FROM spot_trades WHERE user_id = u.id AND status = 'closed'), 0) as spot_profit,
            COALESCE((SELECT SUM(profit_loss) FROM binary_trades WHERE user_id = u.id AND status IN ('won','lost')), 0) as binary_profit,
            COALESCE((SELECT COUNT(*) FROM binary_trades WHERE user_id = u.id AND result = 'won'), 0) as wins,
            COALESCE((SELECT COUNT(*) FROM binary_trades WHERE user_id = u.id AND status IN ('won','lost')), 0) as total_binary
        FROM users u
        ORDER BY (
            COALESCE((SELECT SUM(profit_loss) FROM spot_trades WHERE user_id = u.id AND status = 'closed'), 0) +
            COALESCE((SELECT SUM(profit_loss) FROM binary_trades WHERE user_id = u.id AND status IN ('won','lost')), 0)
        ) DESC
        LIMIT 10
    ")->fetchAll();

    // Trading volume by day (last 7 days)
    $volume_data = $pdo->query("
        SELECT dates.date,
            COALESCE((SELECT SUM(amount) FROM spot_trades WHERE DATE(created_at) = dates.date), 0) as spot_volume,
            COALESCE((SELECT SUM(amount) FROM binary_trades WHERE DATE(created_at) = dates.date), 0) as binary_volume
        FROM (
            SELECT DATE(DATE_SUB(NOW(), INTERVAL n DAY)) as date
            FROM (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6) nums
        ) dates
        ORDER BY dates.date ASC
    ")->fetchAll();

    // Binary win/loss stats
    $binary_stats = $pdo->query("
        SELECT
            COUNT(CASE WHEN result = 'won' THEN 1 END) as won,
            COUNT(CASE WHEN result = 'lost' THEN 1 END) as lost,
            SUM(CASE WHEN result = 'won' THEN payout ELSE 0 END) as total_payout,
            SUM(CASE WHEN result = 'lost' THEN amount ELSE 0 END) as total_lost_amount,
            COUNT(*) as total
        FROM binary_trades
        WHERE status IN ('won', 'lost')
    ")->fetch();

    // Gateway performance
    $gateway_stats = $pdo->query("
        SELECT pg.id, pg.name as gateway_name,
            COUNT(t.id) as tx_count,
            SUM(CASE WHEN t.status = 'completed' THEN t.amount ELSE 0 END) as volume,
            COUNT(CASE WHEN t.status = 'completed' THEN 1 END) as success_count,
            ROUND(COUNT(CASE WHEN t.status = 'completed' THEN 1 END) * 100.0 / NULLIF(COUNT(t.id), 0), 1) as success_rate
        FROM payment_gateways pg
        LEFT JOIN transactions t ON pg.id = t.gateway_id
        GROUP BY pg.id
        ORDER BY volume DESC
    ")->fetchAll();

    // Revenue trend (daily for chart)
    $stmt = $pdo->prepare("
        SELECT DATE(created_at) as date,
            SUM(CASE WHEN type = 'fee' THEN amount ELSE 0 END) as fees,
            SUM(CASE WHEN type = 'trade_loss' THEN ABS(amount) ELSE 0 END) as losses,
            SUM(CASE WHEN type = 'p2p_fee' THEN amount ELSE 0 END) as p2p,
            SUM(CASE WHEN type IN ('fee','trade_loss','p2p_fee') THEN ABS(amount) ELSE 0 END) as total
        FROM transactions
        WHERE status = 'completed'
        AND DATE(created_at) BETWEEN ? AND ?
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ");
    $stmt->execute([$start_date, $end_date]);
    $revenue_trend = $stmt->fetchAll();

    // Counts for sidebar
    $total_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $pending_count = $pdo->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending'")->fetchColumn();

} catch (PDOException $e) {
    error_log("Admin reports error: " . $e->getMessage());
    $revenue = ['fee_revenue' => 0, 'trade_loss_revenue' => 0, 'withdrawal_fees' => 0, 'p2p_fees' => 0];
    $prev_revenue = ['fee_revenue' => 0, 'trade_loss_revenue' => 0, 'withdrawal_fees' => 0, 'p2p_fees' => 0];
    $user_stats = $tx_stats = $top_traders = $volume_data = $gateway_stats = $revenue_trend = [];
    $binary_stats = ['won' => 0, 'lost' => 0, 'total_payout' => 0, 'total_lost_amount' => 0, 'total' => 0];
    $total_users = 0;
    $pending_count = 0;
}

// Helper: calculate percentage change
function calc_change($current, $previous) {
    $current = floatval($current);
    $previous = floatval($previous);
    if ($previous == 0) return $current > 0 ? 100 : 0;
    return round((($current - $previous) / abs($previous)) * 100, 1);
}

$fee_change = calc_change($revenue['fee_revenue'] ?? 0, $prev_revenue['fee_revenue'] ?? 0);
$loss_change = calc_change($revenue['trade_loss_revenue'] ?? 0, $prev_revenue['trade_loss_revenue'] ?? 0);
$p2p_change = calc_change($revenue['p2p_fees'] ?? 0, $prev_revenue['p2p_fees'] ?? 0);
$wd_change = calc_change($revenue['withdrawal_fees'] ?? 0, $prev_revenue['withdrawal_fees'] ?? 0);
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $lang === 'ar' ? 'التقارير والتحليلات' : 'Reports & Analytics' ?> - Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
            --cyan: #06B6D4;
            --text: #F8FAFC;
            --text-muted: #94A3B8;
            --border: rgba(148, 163, 184, 0.1);
            --card-bg: #1E293B;
        }

        body {
            font-family: <?= $rtl ? "'Tajawal', sans-serif" : "'Poppins', 'Tajawal', sans-serif" ?>;
            background: var(--primary);
            color: var(--text);
            line-height: 1.6;
        }

        /* ===== SIDEBAR ===== */
        .sidebar {
            position: fixed;
            <?= $rtl ? 'right: 0;' : 'left: 0;' ?>
            top: 0;
            width: 280px;
            height: 100vh;
            background: var(--secondary);
            border-<?= $rtl ? 'left' : 'right' ?>: 1px solid var(--border);
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

        .nav-menu { padding: 20px 0; }

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
            <?= $rtl ? 'right: 0;' : 'left: 0;' ?>
            top: 0;
            height: 100%;
            width: 3px;
            background: linear-gradient(135deg, #DC2626, var(--accent));
        }

        .nav-item i { width: 20px; text-align: center; font-size: 18px; }

        .nav-badge {
            <?= $rtl ? 'margin-right: auto;' : 'margin-left: auto;' ?>
            background: var(--danger);
            color: white;
            font-size: 10px;
            padding: 3px 8px;
            border-radius: 10px;
            font-weight: 700;
        }

        /* ===== MAIN CONTENT ===== */
        .main {
            <?= $rtl ? 'margin-right: 280px;' : 'margin-left: 280px;' ?>
            padding: 30px;
            min-height: 100vh;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 32px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .page-title {
            font-size: 32px;
            font-weight: 900;
            background: linear-gradient(135deg, var(--text), #DC2626);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
        }

        .page-subtitle {
            color: var(--text-muted);
            font-size: 14px;
        }

        /* ===== DATE FILTER ===== */
        .filter-bar {
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px 24px;
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 28px;
            flex-wrap: wrap;
        }

        .filter-bar label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
        }

        .filter-bar input[type="date"] {
            background: var(--primary);
            border: 1px solid var(--border);
            color: var(--text);
            padding: 10px 14px;
            border-radius: 10px;
            font-family: inherit;
            font-size: 13px;
            outline: none;
            transition: border-color 0.3s;
        }

        .filter-bar input[type="date"]:focus {
            border-color: var(--accent);
        }

        .filter-bar input[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(1);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            font-family: inherit;
            color: white;
        }

        .btn-primary {
            background: linear-gradient(135deg, #DC2626, var(--accent));
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(220, 38, 38, 0.3);
        }

        .btn-secondary {
            background: rgba(148, 163, 184, 0.1);
            border: 1px solid var(--border);
            color: var(--text-muted);
        }

        .btn-secondary:hover {
            background: rgba(148, 163, 184, 0.15);
            color: var(--text);
        }

        .btn-success {
            background: linear-gradient(135deg, #059669, var(--success));
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        .export-group {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* ===== REVENUE CARDS ===== */
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
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.3);
        }

        .stat-card .gradient-bar {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
        }

        .stat-card.card-blue .gradient-bar { background: linear-gradient(90deg, #3B82F6, #06B6D4); }
        .stat-card.card-red .gradient-bar { background: linear-gradient(90deg, #EF4444, #F59E0B); }
        .stat-card.card-green .gradient-bar { background: linear-gradient(90deg, #10B981, #34D399); }
        .stat-card.card-purple .gradient-bar { background: linear-gradient(90deg, #8B5CF6, #EC4899); }

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

        .stat-icon.blue { background: rgba(59, 130, 246, 0.1); color: var(--info); }
        .stat-icon.red { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
        .stat-icon.green { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .stat-icon.purple { background: rgba(139, 92, 246, 0.1); color: var(--purple); }

        .stat-trend {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
        }

        .stat-trend.up { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .stat-trend.down { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
        .stat-trend.neutral { background: rgba(148, 163, 184, 0.1); color: var(--text-muted); }

        .stat-value {
            font-size: 28px;
            font-weight: 900;
            color: var(--text);
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 13px;
            color: var(--text-muted);
        }

        /* ===== CHARTS ===== */
        .charts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 32px;
        }

        .chart-card {
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
        }

        .chart-card.full-width {
            grid-column: 1 / -1;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .chart-title {
            font-size: 18px;
            font-weight: 700;
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        /* ===== DATA TABLES ===== */
        .tables-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 32px;
        }

        .table-card {
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
        }

        .table-card.full-width {
            grid-column: 1 / -1;
        }

        .table-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-title {
            font-size: 18px;
            font-weight: 700;
        }

        .table-body {
            overflow-x: auto;
        }

        .table-body::-webkit-scrollbar { height: 4px; }
        .table-body::-webkit-scrollbar-thumb { background: var(--accent); border-radius: 2px; }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        table th {
            padding: 14px 20px;
            text-align: <?= $rtl ? 'right' : 'left' ?>;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: rgba(0, 0, 0, 0.15);
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        table td {
            padding: 14px 20px;
            font-size: 14px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        table tbody tr {
            transition: background 0.2s;
        }

        table tbody tr:hover {
            background: rgba(245, 158, 11, 0.02);
        }

        table tbody tr:last-child td {
            border-bottom: none;
        }

        .rank-badge {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
            color: white;
        }

        .rank-1 { background: linear-gradient(135deg, #F59E0B, #DC2626); }
        .rank-2 { background: linear-gradient(135deg, #94A3B8, #64748B); }
        .rank-3 { background: linear-gradient(135deg, #B45309, #92400E); }
        .rank-default { background: rgba(139, 92, 246, 0.15); color: var(--purple); }

        .text-success { color: var(--success); }
        .text-danger { color: var(--danger); }
        .text-muted { color: var(--text-muted); }
        .text-info { color: var(--info); }
        .fw-bold { font-weight: 700; }

        .progress-bar-container {
            width: 100px;
            height: 6px;
            background: rgba(148, 163, 184, 0.1);
            border-radius: 3px;
            overflow: hidden;
            display: inline-block;
            vertical-align: middle;
            margin-<?= $rtl ? 'left' : 'right' ?>: 8px;
        }

        .progress-bar-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.6s ease;
        }

        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.3;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1200px) {
            .charts-grid,
            .tables-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(<?= $rtl ? '100%' : '-100%' ?>);
                transition: transform 0.3s;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .main {
                <?= $rtl ? 'margin-right: 0;' : 'margin-left: 0;' ?>
                padding: 20px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .header {
                flex-direction: column;
            }

            .page-title {
                font-size: 24px;
            }

            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .mobile-toggle {
                display: block;
            }
        }

        .mobile-toggle {
            display: none;
            position: fixed;
            top: 20px;
            <?= $rtl ? 'right: 20px;' : 'left: 20px;' ?>
            z-index: 1100;
            width: 44px;
            height: 44px;
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: 12px;
            color: var(--text);
            font-size: 20px;
            cursor: pointer;
            align-items: center;
            justify-content: center;
        }

        @media (max-width: 768px) {
            .mobile-toggle { display: flex; }
        }
    </style>
</head>
<body>
    <!-- Mobile Toggle -->
    <button class="mobile-toggle" onclick="document.querySelector('.sidebar').classList.toggle('open')">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar -->
    <aside class="sidebar">
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
                <span class="nav-badge"><?= $total_users ?></span>
            </a>
            <a href="payments.php" class="nav-item">
                <i class="fas fa-credit-card"></i>
                <span><?= $lang === 'ar' ? 'المدفوعات' : 'Payments' ?></span>
                <?php if ($pending_count > 0): ?>
                    <span class="nav-badge"><?= $pending_count ?></span>
                <?php endif; ?>
            </a>
            <a href="trades.php" class="nav-item">
                <i class="fas fa-exchange-alt"></i>
                <span><?= $lang === 'ar' ? 'الصفقات' : 'Trades' ?></span>
            </a>
            <a href="reports.php" class="nav-item active">
                <i class="fas fa-file-chart-line fa-chart-bar"></i>
                <span><?= $lang === 'ar' ? 'التقارير' : 'Reports' ?></span>
            </a>
            <a href="settings.php" class="nav-item">
                <i class="fas fa-cog"></i>
                <span><?= $lang === 'ar' ? 'الإعدادات' : 'Settings' ?></span>
            </a>

            <div class="nav-section-title"><?= $lang === 'ar' ? 'أخرى' : 'Other' ?></div>
            <a href="../dashboard.php" class="nav-item">
                <i class="fas fa-arrow-<?= $rtl ? 'right' : 'left' ?>"></i>
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
        <!-- Header -->
        <div class="header">
            <div>
                <h1 class="page-title"><?= $lang === 'ar' ? 'التقارير والتحليلات' : 'Reports & Analytics' ?></h1>
                <p class="page-subtitle"><?= $lang === 'ar' ? 'تحليل شامل لأداء المنصة والإيرادات' : 'Comprehensive platform performance and revenue analysis' ?></p>
            </div>
            <div class="export-group">
                <a href="?export=csv&type=revenue&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="btn btn-success btn-sm">
                    <i class="fas fa-file-csv"></i>
                    <?= $lang === 'ar' ? 'تصدير الإيرادات' : 'Export Revenue' ?>
                </a>
                <a href="?export=csv&type=traders" class="btn btn-success btn-sm">
                    <i class="fas fa-file-csv"></i>
                    <?= $lang === 'ar' ? 'تصدير المتداولين' : 'Export Traders' ?>
                </a>
                <a href="?export=csv&type=transactions&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="btn btn-success btn-sm">
                    <i class="fas fa-file-csv"></i>
                    <?= $lang === 'ar' ? 'تصدير المعاملات' : 'Export Transactions' ?>
                </a>
                <a href="?export=csv&type=gateways" class="btn btn-success btn-sm">
                    <i class="fas fa-file-csv"></i>
                    <?= $lang === 'ar' ? 'تصدير البوابات' : 'Export Gateways' ?>
                </a>
            </div>
        </div>

        <!-- Date Range Filter -->
        <form class="filter-bar" method="GET">
            <label><i class="fas fa-calendar-alt"></i> <?= $lang === 'ar' ? 'من تاريخ' : 'Start Date' ?></label>
            <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
            <label><i class="fas fa-calendar-alt"></i> <?= $lang === 'ar' ? 'إلى تاريخ' : 'End Date' ?></label>
            <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-search"></i>
                <?= $lang === 'ar' ? 'تطبيق' : 'Generate' ?>
            </button>
            <a href="reports.php" class="btn btn-secondary">
                <i class="fas fa-redo"></i>
                <?= $lang === 'ar' ? 'إعادة تعيين' : 'Reset' ?>
            </a>
        </form>

        <!-- Revenue Summary Cards -->
        <div class="stats-grid">
            <!-- Spot Trading Fees -->
            <div class="stat-card card-blue">
                <div class="gradient-bar"></div>
                <div class="stat-header">
                    <div class="stat-icon blue">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-trend <?= $fee_change >= 0 ? 'up' : 'down' ?>">
                        <i class="fas fa-arrow-<?= $fee_change >= 0 ? 'up' : 'down' ?>"></i>
                        <?= abs($fee_change) ?>%
                    </div>
                </div>
                <div class="stat-value">$<?= number_format($revenue['fee_revenue'] ?? 0, 2) ?></div>
                <div class="stat-label"><?= $lang === 'ar' ? 'رسوم التداول الفوري' : 'Spot Trading Fees' ?></div>
            </div>

            <!-- Binary Losses (Platform Revenue) -->
            <div class="stat-card card-red">
                <div class="gradient-bar"></div>
                <div class="stat-header">
                    <div class="stat-icon red">
                        <i class="fas fa-dice"></i>
                    </div>
                    <div class="stat-trend <?= $loss_change >= 0 ? 'up' : 'down' ?>">
                        <i class="fas fa-arrow-<?= $loss_change >= 0 ? 'up' : 'down' ?>"></i>
                        <?= abs($loss_change) ?>%
                    </div>
                </div>
                <div class="stat-value">$<?= number_format($revenue['trade_loss_revenue'] ?? 0, 2) ?></div>
                <div class="stat-label"><?= $lang === 'ar' ? 'خسائر التداول الثنائي' : 'Binary Trade Losses' ?></div>
            </div>

            <!-- P2P Fees -->
            <div class="stat-card card-green">
                <div class="gradient-bar"></div>
                <div class="stat-header">
                    <div class="stat-icon green">
                        <i class="fas fa-people-arrows"></i>
                    </div>
                    <div class="stat-trend <?= $p2p_change >= 0 ? 'up' : ($p2p_change < 0 ? 'down' : 'neutral') ?>">
                        <i class="fas fa-arrow-<?= $p2p_change >= 0 ? 'up' : 'down' ?>"></i>
                        <?= abs($p2p_change) ?>%
                    </div>
                </div>
                <div class="stat-value">$<?= number_format($revenue['p2p_fees'] ?? 0, 2) ?></div>
                <div class="stat-label"><?= $lang === 'ar' ? 'رسوم P2P' : 'P2P Fees' ?></div>
            </div>

            <!-- Withdrawal Fees -->
            <div class="stat-card card-purple">
                <div class="gradient-bar"></div>
                <div class="stat-header">
                    <div class="stat-icon purple">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-trend <?= $wd_change >= 0 ? 'up' : ($wd_change < 0 ? 'down' : 'neutral') ?>">
                        <i class="fas fa-arrow-<?= $wd_change >= 0 ? 'up' : 'down' ?>"></i>
                        <?= abs($wd_change) ?>%
                    </div>
                </div>
                <div class="stat-value">$<?= number_format($revenue['withdrawal_fees'] ?? 0, 2) ?></div>
                <div class="stat-label"><?= $lang === 'ar' ? 'رسوم السحب' : 'Withdrawal Fees' ?></div>
            </div>
        </div>

        <!-- Charts Row 1: Revenue Trend + User Registrations -->
        <div class="charts-grid">
            <!-- Revenue Trend Line Chart -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">
                        <i class="fas fa-chart-line" style="color: var(--accent); margin-<?= $rtl ? 'left' : 'right' ?>: 8px;"></i>
                        <?= $lang === 'ar' ? 'اتجاه الإيرادات' : 'Revenue Trend' ?>
                    </h3>
                </div>
                <div class="chart-container">
                    <canvas id="revenueTrendChart"></canvas>
                </div>
            </div>

            <!-- User Registrations Bar Chart -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">
                        <i class="fas fa-user-plus" style="color: var(--info); margin-<?= $rtl ? 'left' : 'right' ?>: 8px;"></i>
                        <?= $lang === 'ar' ? 'تسجيلات المستخدمين' : 'User Registrations' ?>
                    </h3>
                    <span class="text-muted" style="font-size: 12px;"><?= $lang === 'ar' ? 'آخر 30 يوم' : 'Last 30 days' ?></span>
                </div>
                <div class="chart-container">
                    <canvas id="userRegistrationsChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Charts Row 2: Trading Volume + Win/Loss Pie -->
        <div class="charts-grid">
            <!-- Trading Volume Area Chart -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">
                        <i class="fas fa-chart-area" style="color: var(--success); margin-<?= $rtl ? 'left' : 'right' ?>: 8px;"></i>
                        <?= $lang === 'ar' ? 'حجم التداول' : 'Trading Volume' ?>
                    </h3>
                    <span class="text-muted" style="font-size: 12px;"><?= $lang === 'ar' ? 'آخر 7 أيام' : 'Last 7 days' ?></span>
                </div>
                <div class="chart-container">
                    <canvas id="tradingVolumeChart"></canvas>
                </div>
            </div>

            <!-- Binary Win/Loss Pie Chart -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">
                        <i class="fas fa-pie-chart fa-chart-pie" style="color: var(--purple); margin-<?= $rtl ? 'left' : 'right' ?>: 8px;"></i>
                        <?= $lang === 'ar' ? 'نتائج التداول الثنائي' : 'Binary Win/Loss' ?>
                    </h3>
                </div>
                <div class="chart-container">
                    <canvas id="binaryWinLossChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Tables Row 1: Top Traders + Gateway Performance -->
        <div class="tables-grid">
            <!-- Top 10 Traders -->
            <div class="table-card">
                <div class="table-header">
                    <h3 class="table-title">
                        <i class="fas fa-trophy" style="color: var(--accent); margin-<?= $rtl ? 'left' : 'right' ?>: 8px;"></i>
                        <?= $lang === 'ar' ? 'أفضل 10 متداولين' : 'Top 10 Traders' ?>
                    </h3>
                    <a href="?export=csv&type=traders" class="btn btn-secondary btn-sm">
                        <i class="fas fa-download"></i> CSV
                    </a>
                </div>
                <div class="table-body">
                    <?php if (empty($top_traders)): ?>
                        <div class="empty-state">
                            <i class="fas fa-users"></i>
                            <p><?= $lang === 'ar' ? 'لا يوجد متداولون بعد' : 'No traders yet' ?></p>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th><?= $lang === 'ar' ? 'المتداول' : 'Trader' ?></th>
                                    <th><?= $lang === 'ar' ? 'الصفقات' : 'Trades' ?></th>
                                    <th><?= $lang === 'ar' ? 'الربح/الخسارة' : 'Profit/Loss' ?></th>
                                    <th><?= $lang === 'ar' ? 'نسبة الفوز' : 'Win Rate' ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rank = 1; foreach ($top_traders as $trader):
                                    $total_trades = ($trader['spot_trades_count'] ?? 0) + ($trader['binary_trades_count'] ?? 0);
                                    $total_profit = ($trader['spot_profit'] ?? 0) + ($trader['binary_profit'] ?? 0);
                                    $win_rate = ($trader['total_binary'] ?? 0) > 0
                                        ? round(($trader['wins'] / $trader['total_binary']) * 100, 1)
                                        : 0;
                                ?>
                                    <tr>
                                        <td>
                                            <span class="rank-badge <?= $rank <= 3 ? 'rank-' . $rank : 'rank-default' ?>">
                                                <?= $rank ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="fw-bold"><?= htmlspecialchars($trader['username']) ?></div>
                                            <div class="text-muted" style="font-size: 11px;">
                                                <?= htmlspecialchars($trader['full_name'] ?? '') ?>
                                            </div>
                                        </td>
                                        <td><?= number_format($total_trades) ?></td>
                                        <td class="<?= $total_profit >= 0 ? 'text-success' : 'text-danger' ?> fw-bold">
                                            <?= $total_profit >= 0 ? '+' : '' ?>$<?= number_format($total_profit, 2) ?>
                                        </td>
                                        <td>
                                            <div class="progress-bar-container">
                                                <div class="progress-bar-fill" style="width: <?= $win_rate ?>%; background: <?= $win_rate >= 50 ? 'var(--success)' : 'var(--danger)' ?>;"></div>
                                            </div>
                                            <span class="<?= $win_rate >= 50 ? 'text-success' : 'text-danger' ?>"><?= $win_rate ?>%</span>
                                        </td>
                                    </tr>
                                <?php $rank++; endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Gateway Performance -->
            <div class="table-card">
                <div class="table-header">
                    <h3 class="table-title">
                        <i class="fas fa-network-wired" style="color: var(--cyan); margin-<?= $rtl ? 'left' : 'right' ?>: 8px;"></i>
                        <?= $lang === 'ar' ? 'أداء بوابات الدفع' : 'Gateway Performance' ?>
                    </h3>
                    <a href="?export=csv&type=gateways" class="btn btn-secondary btn-sm">
                        <i class="fas fa-download"></i> CSV
                    </a>
                </div>
                <div class="table-body">
                    <?php if (empty($gateway_stats)): ?>
                        <div class="empty-state">
                            <i class="fas fa-credit-card"></i>
                            <p><?= $lang === 'ar' ? 'لا توجد بوابات دفع' : 'No gateways found' ?></p>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th><?= $lang === 'ar' ? 'البوابة' : 'Gateway' ?></th>
                                    <th><?= $lang === 'ar' ? 'المعاملات' : 'Transactions' ?></th>
                                    <th><?= $lang === 'ar' ? 'الحجم' : 'Volume' ?></th>
                                    <th><?= $lang === 'ar' ? 'نسبة النجاح' : 'Success Rate' ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($gateway_stats as $gw): ?>
                                    <tr>
                                        <td class="fw-bold"><?= htmlspecialchars($gw['gateway_name']) ?></td>
                                        <td><?= number_format($gw['tx_count'] ?? 0) ?></td>
                                        <td class="text-success fw-bold">$<?= number_format($gw['volume'] ?? 0, 2) ?></td>
                                        <td>
                                            <?php $sr = floatval($gw['success_rate'] ?? 0); ?>
                                            <div class="progress-bar-container">
                                                <div class="progress-bar-fill" style="width: <?= $sr ?>%; background: <?= $sr >= 80 ? 'var(--success)' : ($sr >= 50 ? 'var(--warning)' : 'var(--danger)') ?>;"></div>
                                            </div>
                                            <span class="<?= $sr >= 80 ? 'text-success' : ($sr >= 50 ? '' : 'text-danger') ?>"><?= $sr ?>%</span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Transaction Summary by Type (full width) -->
        <div class="tables-grid">
            <div class="table-card full-width">
                <div class="table-header">
                    <h3 class="table-title">
                        <i class="fas fa-receipt" style="color: var(--info); margin-<?= $rtl ? 'left' : 'right' ?>: 8px;"></i>
                        <?= $lang === 'ar' ? 'ملخص المعاملات حسب النوع' : 'Transaction Summary by Type' ?>
                    </h3>
                    <a href="?export=csv&type=transactions&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="btn btn-secondary btn-sm">
                        <i class="fas fa-download"></i> CSV
                    </a>
                </div>
                <div class="table-body">
                    <?php if (empty($tx_stats)): ?>
                        <div class="empty-state">
                            <i class="fas fa-receipt"></i>
                            <p><?= $lang === 'ar' ? 'لا توجد معاملات في هذه الفترة' : 'No transactions in this period' ?></p>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th><?= $lang === 'ar' ? 'النوع' : 'Type' ?></th>
                                    <th><?= $lang === 'ar' ? 'العدد' : 'Count' ?></th>
                                    <th><?= $lang === 'ar' ? 'الإجمالي' : 'Total' ?></th>
                                    <th><?= $lang === 'ar' ? 'المتوسط' : 'Average' ?></th>
                                    <th><?= $lang === 'ar' ? 'النسبة' : 'Share' ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                    $grand_total = array_sum(array_column($tx_stats, 'total'));
                                    $type_labels = [
                                        'deposit' => $lang === 'ar' ? 'إيداع' : 'Deposit',
                                        'withdrawal' => $lang === 'ar' ? 'سحب' : 'Withdrawal',
                                        'fee' => $lang === 'ar' ? 'رسوم' : 'Fee',
                                        'trade_loss' => $lang === 'ar' ? 'خسارة تداول' : 'Trade Loss',
                                        'trade_win' => $lang === 'ar' ? 'ربح تداول' : 'Trade Win',
                                        'adjustment' => $lang === 'ar' ? 'تعديل' : 'Adjustment',
                                        'p2p_fee' => $lang === 'ar' ? 'رسوم P2P' : 'P2P Fee',
                                        'bonus' => $lang === 'ar' ? 'مكافأة' : 'Bonus',
                                        'referral' => $lang === 'ar' ? 'إحالة' : 'Referral',
                                    ];
                                    $type_icons = [
                                        'deposit' => 'arrow-down',
                                        'withdrawal' => 'arrow-up',
                                        'fee' => 'percentage',
                                        'trade_loss' => 'chart-line',
                                        'trade_win' => 'trophy',
                                        'adjustment' => 'sliders-h',
                                        'p2p_fee' => 'people-arrows',
                                        'bonus' => 'gift',
                                        'referral' => 'user-plus',
                                    ];
                                    $type_colors = [
                                        'deposit' => 'var(--success)',
                                        'withdrawal' => 'var(--danger)',
                                        'fee' => 'var(--info)',
                                        'trade_loss' => 'var(--danger)',
                                        'trade_win' => 'var(--success)',
                                        'adjustment' => 'var(--warning)',
                                        'p2p_fee' => 'var(--purple)',
                                        'bonus' => 'var(--accent)',
                                        'referral' => 'var(--cyan)',
                                    ];
                                ?>
                                <?php foreach ($tx_stats as $tx): ?>
                                    <?php
                                        $share = $grand_total > 0 ? round((abs($tx['total']) / abs($grand_total)) * 100, 1) : 0;
                                        $icon = $type_icons[$tx['type']] ?? 'circle';
                                        $color = $type_colors[$tx['type']] ?? 'var(--text-muted)';
                                        $label = $type_labels[$tx['type']] ?? ucfirst($tx['type']);
                                    ?>
                                    <tr>
                                        <td>
                                            <i class="fas fa-<?= $icon ?>" style="color: <?= $color ?>; margin-<?= $rtl ? 'left' : 'right' ?>: 8px;"></i>
                                            <span class="fw-bold"><?= htmlspecialchars($label) ?></span>
                                        </td>
                                        <td><?= number_format($tx['count']) ?></td>
                                        <td class="fw-bold">$<?= number_format(abs($tx['total']), 2) ?></td>
                                        <td>$<?= number_format(abs($tx['avg_amount']), 2) ?></td>
                                        <td>
                                            <div class="progress-bar-container" style="width: 80px;">
                                                <div class="progress-bar-fill" style="width: <?= $share ?>%; background: <?= $color ?>;"></div>
                                            </div>
                                            <span style="font-size: 12px;"><?= $share ?>%</span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Chart.js Scripts -->
    <script>
        const chartDefaults = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: {
                        color: '#F8FAFC',
                        font: { family: <?= json_encode($rtl ? 'Tajawal' : 'Poppins') ?>, size: 12 },
                        padding: 16
                    }
                }
            },
            scales: {
                y: {
                    ticks: { color: '#94A3B8', font: { size: 11 } },
                    grid: { color: 'rgba(148, 163, 184, 0.08)' },
                    border: { color: 'rgba(148, 163, 184, 0.1)' }
                },
                x: {
                    ticks: { color: '#94A3B8', font: { size: 11 } },
                    grid: { color: 'rgba(148, 163, 184, 0.08)' },
                    border: { color: 'rgba(148, 163, 184, 0.1)' }
                }
            }
        };

        // ===== Revenue Trend Line Chart =====
        const revCtx = document.getElementById('revenueTrendChart').getContext('2d');
        const revGradient1 = revCtx.createLinearGradient(0, 0, 0, 300);
        revGradient1.addColorStop(0, 'rgba(59, 130, 246, 0.3)');
        revGradient1.addColorStop(1, 'rgba(59, 130, 246, 0)');
        const revGradient2 = revCtx.createLinearGradient(0, 0, 0, 300);
        revGradient2.addColorStop(0, 'rgba(239, 68, 68, 0.3)');
        revGradient2.addColorStop(1, 'rgba(239, 68, 68, 0)');

        new Chart(revCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_map(function($d) { return date('m/d', strtotime($d['date'])); }, $revenue_trend)) ?>,
                datasets: [
                    {
                        label: <?= json_encode($lang === 'ar' ? 'الرسوم' : 'Fees') ?>,
                        data: <?= json_encode(array_map(function($d) { return floatval($d['fees']); }, $revenue_trend)) ?>,
                        borderColor: '#3B82F6',
                        backgroundColor: revGradient1,
                        tension: 0.4,
                        fill: true,
                        borderWidth: 2,
                        pointRadius: 3,
                        pointBackgroundColor: '#3B82F6'
                    },
                    {
                        label: <?= json_encode($lang === 'ar' ? 'خسائر التداول' : 'Trade Losses') ?>,
                        data: <?= json_encode(array_map(function($d) { return floatval($d['losses']); }, $revenue_trend)) ?>,
                        borderColor: '#EF4444',
                        backgroundColor: revGradient2,
                        tension: 0.4,
                        fill: true,
                        borderWidth: 2,
                        pointRadius: 3,
                        pointBackgroundColor: '#EF4444'
                    }
                ]
            },
            options: {
                ...chartDefaults,
                interaction: { mode: 'index', intersect: false }
            }
        });

        // ===== User Registrations Bar Chart =====
        const userCtx = document.getElementById('userRegistrationsChart').getContext('2d');
        const userGradient = userCtx.createLinearGradient(0, 0, 0, 300);
        userGradient.addColorStop(0, 'rgba(139, 92, 246, 0.8)');
        userGradient.addColorStop(1, 'rgba(139, 92, 246, 0.2)');

        new Chart(userCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_map(function($d) { return date('m/d', strtotime($d['date'])); }, $user_stats)) ?>,
                datasets: [{
                    label: <?= json_encode($lang === 'ar' ? 'تسجيلات جديدة' : 'New Registrations') ?>,
                    data: <?= json_encode(array_map(function($d) { return intval($d['count']); }, $user_stats)) ?>,
                    backgroundColor: userGradient,
                    borderColor: '#8B5CF6',
                    borderWidth: 1,
                    borderRadius: 6,
                    maxBarThickness: 24
                }]
            },
            options: {
                ...chartDefaults,
                plugins: {
                    ...chartDefaults.plugins,
                    legend: { display: false }
                }
            }
        });

        // ===== Trading Volume Area Chart =====
        const volCtx = document.getElementById('tradingVolumeChart').getContext('2d');
        const volGradient1 = volCtx.createLinearGradient(0, 0, 0, 300);
        volGradient1.addColorStop(0, 'rgba(16, 185, 129, 0.4)');
        volGradient1.addColorStop(1, 'rgba(16, 185, 129, 0)');
        const volGradient2 = volCtx.createLinearGradient(0, 0, 0, 300);
        volGradient2.addColorStop(0, 'rgba(245, 158, 11, 0.4)');
        volGradient2.addColorStop(1, 'rgba(245, 158, 11, 0)');

        new Chart(volCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_map(function($d) { return date('D m/d', strtotime($d['date'])); }, $volume_data)) ?>,
                datasets: [
                    {
                        label: <?= json_encode($lang === 'ar' ? 'تداول فوري' : 'Spot Volume') ?>,
                        data: <?= json_encode(array_map(function($d) { return floatval($d['spot_volume']); }, $volume_data)) ?>,
                        borderColor: '#10B981',
                        backgroundColor: volGradient1,
                        tension: 0.4,
                        fill: true,
                        borderWidth: 2,
                        pointRadius: 4,
                        pointBackgroundColor: '#10B981'
                    },
                    {
                        label: <?= json_encode($lang === 'ar' ? 'تداول ثنائي' : 'Binary Volume') ?>,
                        data: <?= json_encode(array_map(function($d) { return floatval($d['binary_volume']); }, $volume_data)) ?>,
                        borderColor: '#F59E0B',
                        backgroundColor: volGradient2,
                        tension: 0.4,
                        fill: true,
                        borderWidth: 2,
                        pointRadius: 4,
                        pointBackgroundColor: '#F59E0B'
                    }
                ]
            },
            options: {
                ...chartDefaults,
                interaction: { mode: 'index', intersect: false }
            }
        });

        // ===== Binary Win/Loss Pie Chart =====
        const binaryCtx = document.getElementById('binaryWinLossChart').getContext('2d');
        new Chart(binaryCtx, {
            type: 'doughnut',
            data: {
                labels: [
                    <?= json_encode($lang === 'ar' ? 'فوز' : 'Won') ?>,
                    <?= json_encode($lang === 'ar' ? 'خسارة' : 'Lost') ?>
                ],
                datasets: [{
                    data: [
                        <?= intval($binary_stats['won'] ?? 0) ?>,
                        <?= intval($binary_stats['lost'] ?? 0) ?>
                    ],
                    backgroundColor: [
                        'rgba(16, 185, 129, 0.85)',
                        'rgba(239, 68, 68, 0.85)'
                    ],
                    borderColor: [
                        '#10B981',
                        '#EF4444'
                    ],
                    borderWidth: 2,
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
                            font: { family: <?= json_encode($rtl ? 'Tajawal' : 'Poppins') ?>, size: 13 },
                            padding: 20,
                            usePointStyle: true,
                            pointStyleWidth: 16
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? ((context.parsed / total) * 100).toFixed(1) : 0;
                                return context.label + ': ' + context.parsed + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            },
            plugins: [{
                id: 'centerText',
                beforeDraw: function(chart) {
                    const { width, height, ctx } = chart;
                    ctx.restore();
                    const total = <?= intval(($binary_stats['won'] ?? 0) + ($binary_stats['lost'] ?? 0)) ?>;
                    const fontSize = (height / 8).toFixed(2);
                    ctx.font = '900 ' + fontSize + 'px <?= $rtl ? "Tajawal" : "Poppins" ?>';
                    ctx.textBaseline = 'middle';
                    ctx.textAlign = 'center';
                    ctx.fillStyle = '#F8FAFC';
                    ctx.fillText(total, width / 2, height / 2 - 8);
                    ctx.font = '400 ' + (fontSize * 0.5) + 'px <?= $rtl ? "Tajawal" : "Poppins" ?>';
                    ctx.fillStyle = '#94A3B8';
                    ctx.fillText(<?= json_encode($lang === 'ar' ? 'إجمالي الصفقات' : 'Total Trades') ?>, width / 2, height / 2 + 18);
                    ctx.save();
                }
            }]
        });
    </script>
</body>
</html>
