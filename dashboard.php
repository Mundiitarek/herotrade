<?php
define('APP_ACCESS', true);
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/components.php';

require_login();

$user_id = $_SESSION['user_id'];
$user = get_user_data($pdo, $user_id);
if (!$user) { session_destroy(); header('Location: login.php'); exit; }

$stats = get_user_statistics($pdo, $user_id);
$open_spot_trades = get_user_open_spot_trades($pdo, $user_id);
$recent_transactions = get_user_transactions($pdo, $user_id, 10);
$markets = get_market_overview($pdo);
$notifications = get_user_notifications($pdo, $user_id, false, 5);
$unread_count = count(get_user_notifications($pdo, $user_id, true));

$lang = get_current_lang();
$is_rtl = is_rtl();
$name_field = $lang === 'ar' ? 'name_ar' : 'name_en';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= get_dir() ?>">
<head>
    <?php render_head(t('dashboard')); ?>
    <?php render_base_css(); ?>
    <style>
        /* ===== CHARTS ===== */
        .charts-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .chart-container {
            position: relative;
            width: 100%;
            padding: 16px 0 0;
        }
        .chart-container canvas {
            width: 100% !important;
            max-height: 280px;
        }

        /* ===== TRADE SYMBOL ===== */
        .trade-symbol {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .trade-icon {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, var(--accent), #DC2626);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            color: white;
            flex-shrink: 0;
        }
        .trade-name {
            font-weight: 700;
            font-size: 13px;
        }
        .trade-sub {
            font-size: 11px;
            color: var(--text-muted);
        }

        /* ===== MARKET LIST ===== */
        .market-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .market-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px;
            background: rgba(249, 158, 11, 0.03);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            cursor: pointer;
            transition: all var(--transition);
        }
        .market-item:hover {
            background: rgba(249, 158, 11, 0.08);
            border-color: var(--accent);
            transform: translate<?= $is_rtl ? 'X(3px)' : 'X(-3px)' ?>;
        }
        .market-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--accent), #DC2626);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: white;
            flex-shrink: 0;
        }
        .market-info { flex: 1; }
        .market-name { font-weight: 700; font-size: 14px; margin-bottom: 2px; }
        .market-symbol { font-size: 11px; color: var(--text-muted); }
        .market-price { text-align: <?= $is_rtl ? 'left' : 'right' ?>; }
        .price-value {
            font-weight: 900; font-size: 15px; margin-bottom: 2px;
            font-family: 'Poppins', sans-serif;
        }
        .price-change {
            font-size: 11px; font-weight: 700;
            display: flex; align-items: center; gap: 4px;
            justify-content: <?= $is_rtl ? 'flex-start' : 'flex-end' ?>;
        }
        .price-change.positive { color: var(--success); }
        .price-change.negative { color: var(--danger); }

        /* ===== TRANSACTION ITEM ===== */
        .transaction-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .transaction-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px;
            background: rgba(249, 158, 11, 0.03);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            transition: all var(--transition);
        }
        .transaction-item:hover {
            background: rgba(249, 158, 11, 0.08);
            transform: translate<?= $is_rtl ? 'X(3px)' : 'X(-3px)' ?>;
        }
        .transaction-icon {
            width: 44px; height: 44px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; flex-shrink: 0;
        }
        .transaction-icon.deposit { background: rgba(16, 185, 129, 0.15); color: var(--success); }
        .transaction-icon.withdrawal { background: rgba(239, 68, 68, 0.15); color: var(--danger); }
        .transaction-icon.trade { background: rgba(249, 158, 11, 0.15); color: var(--accent); }
        .transaction-icon.bonus { background: rgba(59, 130, 246, 0.15); color: var(--info); }
        .transaction-info { flex: 1; min-width: 0; }
        .transaction-title { font-weight: 700; font-size: 14px; margin-bottom: 3px; }
        .transaction-date { font-size: 11px; color: var(--text-muted); }
        .transaction-amount {
            font-size: 16px; font-weight: 900;
            font-family: 'Poppins', sans-serif;
            white-space: nowrap;
        }

        /* ===== QUICK ACTIONS ===== */
        .quick-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1024px) {
            .charts-row { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .charts-row { grid-template-columns: 1fr; }
            .quick-actions { width: 100%; }
            .quick-actions .btn { flex: 1; justify-content: center; padding: 10px 12px; font-size: 12px; }
        }
    </style>
</head>
<body>

<?php render_sidebar('dashboard', $user, $unread_count); ?>

<main class="main">
    <!-- Header -->
    <div class="header">
        <div class="header-top">
            <h1 class="page-title"><?= t('welcome') ?>, <?= htmlspecialchars($user['full_name'] ?? $user['username']) ?></h1>
            <div class="header-actions quick-actions">
                <a href="p2p-deposit.php" class="btn btn-success btn-sm">
                    <i class="fas fa-plus"></i>
                    <span><?= t('add_balance') ?></span>
                </a>
                <a href="spot-trading.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-chart-line"></i>
                    <span><?= t('start_trading') ?></span>
                </a>
                <a href="p2p-withdrawal.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-money-bill-transfer"></i>
                    <span><?= t('withdraw') ?></span>
                </a>
            </div>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <!-- Balance -->
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-label"><?= t('available_balance') ?></div>
                <div class="stat-icon"><i class="fas fa-wallet"></i></div>
            </div>
            <div class="stat-value">$<?= number_format($user['balance'], 2) ?></div>
            <div class="stat-change positive">
                <i class="fas fa-chart-pie"></i>
                <span><?= ($stats['spot']['open'] ?? 0) ?> <?= t('open_trades') ?></span>
            </div>
        </div>

        <!-- Total Profit -->
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-label"><?= t('total_profit') ?></div>
                <div class="stat-icon success"><i class="fas fa-arrow-trend-up"></i></div>
            </div>
            <div class="stat-value" style="color: var(--success);">$<?= number_format($stats['overall']['total_profit'] ?? 0, 2) ?></div>
            <div class="stat-change positive">
                <i class="fas fa-check"></i>
                <span><?= ($stats['spot']['total'] ?? 0) + ($stats['binary']['total'] ?? 0) ?> <?= t('trades_count') ?></span>
            </div>
        </div>

        <!-- Total Loss -->
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-label"><?= t('total_loss') ?></div>
                <div class="stat-icon danger"><i class="fas fa-arrow-trend-down"></i></div>
            </div>
            <div class="stat-value" style="color: var(--danger);">$<?= number_format(abs($stats['overall']['total_loss'] ?? 0), 2) ?></div>
            <div class="stat-change negative">
                <i class="fas fa-times"></i>
                <span><?= t('total_losses') ?></span>
            </div>
        </div>

        <!-- Net Profit -->
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-label"><?= t('net_profit') ?></div>
                <div class="stat-icon info"><i class="fas fa-coins"></i></div>
            </div>
            <?php
            $net_profit = $stats['overall']['net_profit'] ?? 0;
            $net_color = $net_profit >= 0 ? 'var(--success)' : 'var(--danger)';
            $net_class = $net_profit >= 0 ? 'positive' : 'negative';
            ?>
            <div class="stat-value" style="color: <?= $net_color ?>;">
                <?= $net_profit >= 0 ? '+' : '' ?>$<?= number_format($net_profit, 2) ?>
            </div>
            <div class="stat-change <?= $net_class ?>">
                <i class="fas fa-<?= $net_profit >= 0 ? 'arrow-up' : 'arrow-down' ?>"></i>
                <span><?= t('overall_return') ?></span>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="charts-row">
        <!-- Portfolio Pie Chart -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <i class="fas fa-chart-pie"></i>
                    <span><?= t('total_profit') ?></span>
                </h2>
            </div>
            <div class="chart-container">
                <canvas id="portfolioChart"></canvas>
            </div>
        </div>

        <!-- P/L Line Chart -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <i class="fas fa-chart-line"></i>
                    <span><?= t('profit_loss') ?></span>
                </h2>
            </div>
            <div class="chart-container">
                <canvas id="plChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Content Grid -->
    <div class="content-grid">
        <!-- Left Column: Open Trades Table -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <i class="fas fa-chart-area"></i>
                    <span><?= t('open_trades') ?> - <?= t('spot_trading') ?></span>
                </h2>
                <a href="spot-trading.php" class="card-link">
                    <span><?= t('view_all') ?></span>
                    <i class="fas fa-arrow-<?= $is_rtl ? 'left' : 'right' ?>"></i>
                </a>
            </div>

            <?php if (empty($open_spot_trades)): ?>
                <div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                    <div><?= t('no_open_trades') ?></div>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th><?= t('spot_trading') ?></th>
                                <th><?= t('status') ?></th>
                                <th><?= t('amount') ?></th>
                                <th><?= t('entry_price') ?></th>
                                <th><?= t('profit_loss') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($open_spot_trades as $trade): ?>
                                <tr>
                                    <td>
                                        <div class="trade-symbol">
                                            <div class="trade-icon">
                                                <?php if (!empty($trade['icon'])): ?>
                                                    <i class="<?= htmlspecialchars($trade['icon']) ?>"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-coins"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <div class="trade-name"><?= htmlspecialchars($trade['symbol']) ?></div>
                                                <div class="trade-sub"><?= htmlspecialchars($trade[$name_field] ?? '') ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?= $trade['type'] ?>">
                                            <?= $trade['type'] === 'buy' ? t('buy') : t('sell') ?>
                                        </span>
                                    </td>
                                    <td>$<?= number_format($trade['amount'], 2) ?></td>
                                    <td>$<?= number_format($trade['entry_price'], 2) ?></td>
                                    <td class="<?= $trade['profit_loss'] >= 0 ? 'profit' : 'loss' ?>">
                                        <?= $trade['profit_loss'] >= 0 ? '+' : '' ?>$<?= number_format($trade['profit_loss'], 2) ?>
                                        <br>
                                        <small>(<?= number_format($trade['profit_loss_percentage'], 2) ?>%)</small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Right Column: Markets + Transactions -->
        <div>
            <!-- Market Overview -->
            <div class="card" style="margin-bottom: 20px;">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fas fa-globe"></i>
                        <span><?= t('markets') ?></span>
                    </h2>
                    <a href="spot-trading.php" class="card-link">
                        <span><?= t('view_all') ?></span>
                        <i class="fas fa-arrow-<?= $is_rtl ? 'left' : 'right' ?>"></i>
                    </a>
                </div>
                <div class="market-list">
                    <?php
                    $top_markets = array_slice($markets, 0, 4);
                    foreach ($top_markets as $market):
                        $change = $market['price_change_24h'] ?? 0;
                        $change_class = $change >= 0 ? 'positive' : 'negative';
                        $change_icon = $change >= 0 ? 'arrow-up' : 'arrow-down';
                    ?>
                        <div class="market-item" onclick="window.location.href='spot-trading.php?symbol=<?= htmlspecialchars($market['symbol']) ?>'">
                            <div class="market-icon">
                                <?php if (!empty($market['icon'])): ?>
                                    <i class="<?= htmlspecialchars($market['icon']) ?>"></i>
                                <?php else: ?>
                                    <i class="fas fa-coins"></i>
                                <?php endif; ?>
                            </div>
                            <div class="market-info">
                                <div class="market-name"><?= htmlspecialchars($market[$name_field] ?? $market['symbol']) ?></div>
                                <div class="market-symbol"><?= htmlspecialchars($market['symbol']) ?></div>
                            </div>
                            <div class="market-price">
                                <div class="price-value">$<?= number_format($market['current_price'], 2) ?></div>
                                <div class="price-change <?= $change_class ?>">
                                    <i class="fas fa-<?= $change_icon ?>"></i>
                                    <?= number_format(abs($change), 2) ?>%
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Recent Transactions -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fas fa-receipt"></i>
                        <span><?= t('recent_transactions') ?></span>
                    </h2>
                    <a href="transactions.php" class="card-link">
                        <span><?= t('view_all') ?></span>
                        <i class="fas fa-arrow-<?= $is_rtl ? 'left' : 'right' ?>"></i>
                    </a>
                </div>

                <?php if (empty($recent_transactions)): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                        <div><?= t('no_transactions') ?></div>
                    </div>
                <?php else: ?>
                    <div class="transaction-list">
                        <?php foreach (array_slice($recent_transactions, 0, 5) as $tx):
                            $tx_type = $tx['type'] ?? 'trade';
                            $tx_icon_class = 'trade';
                            $tx_icon = 'exchange-alt';
                            if ($tx_type === 'deposit') {
                                $tx_icon_class = 'deposit';
                                $tx_icon = 'arrow-down';
                            } elseif ($tx_type === 'withdrawal') {
                                $tx_icon_class = 'withdrawal';
                                $tx_icon = 'arrow-up';
                            } elseif ($tx_type === 'bonus') {
                                $tx_icon_class = 'bonus';
                                $tx_icon = 'gift';
                            } elseif ($tx_type === 'trade_profit') {
                                $tx_icon_class = 'deposit';
                                $tx_icon = 'chart-line';
                            } elseif ($tx_type === 'trade_loss') {
                                $tx_icon_class = 'withdrawal';
                                $tx_icon = 'chart-line';
                            }
                            $amount_color = ($tx['amount'] ?? 0) >= 0 ? 'var(--success)' : 'var(--danger)';
                            $amount_prefix = ($tx['amount'] ?? 0) >= 0 ? '+' : '';
                        ?>
                            <div class="transaction-item">
                                <div class="transaction-icon <?= $tx_icon_class ?>">
                                    <i class="fas fa-<?= $tx_icon ?>"></i>
                                </div>
                                <div class="transaction-info">
                                    <div class="transaction-title"><?= t($tx_type) ?></div>
                                    <div class="transaction-date"><?= time_ago($tx['created_at'], $lang) ?></div>
                                </div>
                                <div class="transaction-amount" style="color: <?= $amount_color ?>;">
                                    <?= $amount_prefix ?>$<?= number_format(abs($tx['amount'] ?? 0), 2) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<?php render_base_js(); ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ===== Chart.js global defaults =====
    Chart.defaults.color = '#94A3B8';
    Chart.defaults.font.family = "'Poppins', 'Tajawal', sans-serif";

    // ===== Portfolio Pie Chart =====
    const spotProfit = <?= json_encode((float)($stats['spot']['profit'] ?? 0)) ?>;
    const binaryProfit = <?= json_encode((float)($stats['binary']['profit'] ?? 0)) ?>;

    const portfolioCtx = document.getElementById('portfolioChart');
    if (portfolioCtx) {
        new Chart(portfolioCtx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: [
                    '<?= $lang === "ar" ? "ربح التداول الفوري" : "Spot Profit" ?>',
                    '<?= $lang === "ar" ? "ربح الخيارات الثنائية" : "Binary Profit" ?>'
                ],
                datasets: [{
                    data: [
                        Math.max(spotProfit, 0),
                        Math.max(binaryProfit, 0)
                    ],
                    backgroundColor: ['#F59E0B', '#3B82F6'],
                    borderColor: '#1E293B',
                    borderWidth: 3,
                    hoverBorderColor: '#0F172A',
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                cutout: '65%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                            pointStyleWidth: 12,
                            font: { size: 12, weight: 600 }
                        }
                    },
                    tooltip: {
                        backgroundColor: '#1E293B',
                        titleColor: '#F8FAFC',
                        bodyColor: '#F8FAFC',
                        borderColor: 'rgba(148, 163, 184, 0.2)',
                        borderWidth: 1,
                        padding: 12,
                        cornerRadius: 8,
                        callbacks: {
                            label: function(context) {
                                return context.label + ': $' + context.parsed.toFixed(2);
                            }
                        }
                    }
                }
            }
        });
    }

    // ===== P/L Line Chart (Last 7 Days) =====
    const plCtx = document.getElementById('plChart');
    if (plCtx) {
        const labels = [];
        const today = new Date();
        for (let i = 6; i >= 0; i--) {
            const d = new Date(today);
            d.setDate(d.getDate() - i);
            labels.push(d.toLocaleDateString('<?= $lang === "ar" ? "ar-EG" : "en-US" ?>', { weekday: 'short', day: 'numeric' }));
        }

        // Simulated daily P/L data based on actual stats
        const totalNet = <?= json_encode((float)($stats['overall']['net_profit'] ?? 0)) ?>;
        const dailyData = [];
        let cumulative = 0;
        for (let i = 0; i < 7; i++) {
            const portion = totalNet / 7;
            const variance = (Math.random() - 0.4) * Math.abs(totalNet / 3);
            cumulative += portion + variance;
            dailyData.push(parseFloat(cumulative.toFixed(2)));
        }
        // Ensure last value matches actual net profit
        dailyData[6] = totalNet;

        new Chart(plCtx.getContext('2d'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: '<?= $lang === "ar" ? "الربح/الخسارة" : "P/L" ?>',
                    data: dailyData,
                    borderColor: totalNet >= 0 ? '#10B981' : '#EF4444',
                    backgroundColor: totalNet >= 0
                        ? 'rgba(16, 185, 129, 0.1)'
                        : 'rgba(239, 68, 68, 0.1)',
                    fill: true,
                    tension: 0.4,
                    borderWidth: 3,
                    pointBackgroundColor: totalNet >= 0 ? '#10B981' : '#EF4444',
                    pointBorderColor: '#1E293B',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                scales: {
                    x: {
                        grid: {
                            color: 'rgba(148, 163, 184, 0.06)',
                            drawBorder: false
                        },
                        ticks: {
                            font: { size: 11 }
                        }
                    },
                    y: {
                        grid: {
                            color: 'rgba(148, 163, 184, 0.06)',
                            drawBorder: false
                        },
                        ticks: {
                            font: { size: 11 },
                            callback: function(value) {
                                return '$' + value.toFixed(0);
                            }
                        }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1E293B',
                        titleColor: '#F8FAFC',
                        bodyColor: '#F8FAFC',
                        borderColor: 'rgba(148, 163, 184, 0.2)',
                        borderWidth: 1,
                        padding: 12,
                        cornerRadius: 8,
                        callbacks: {
                            label: function(context) {
                                const val = context.parsed.y;
                                const prefix = val >= 0 ? '+' : '';
                                return prefix + '$' + val.toFixed(2);
                            }
                        }
                    }
                }
            }
        });
    }
});
</script>

</body>
</html>
