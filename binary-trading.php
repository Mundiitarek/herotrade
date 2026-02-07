<?php
define('APP_ACCESS', true);
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/components.php';

require_login();

$user_id = $_SESSION['user_id'];
$user = get_user_data($pdo, $user_id);

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'open_binary') {
        $pair_id = intval($_POST['pair_id'] ?? 0);
        $symbol = sanitize($_POST['symbol'] ?? '');
        $direction = sanitize($_POST['direction'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $duration = intval($_POST['duration'] ?? 60);

        if (empty($pair_id) || empty($symbol) || !in_array($direction, ['up', 'down'])) {
            json_response(['success' => false, 'message' => t('error') . ': ' . t('no_data')], 400);
        }

        if ($amount < MIN_TRADE_AMOUNT) {
            json_response(['success' => false, 'message' => t('min_trade') . ': $' . MIN_TRADE_AMOUNT], 400);
        }

        // Re-fetch user balance
        $user = get_user_data($pdo, $user_id);
        if ($amount > $user['balance']) {
            json_response(['success' => false, 'message' => t('insufficient_balance')], 400);
        }

        $min_duration = get_setting($pdo, 'binary_min_duration', 60);
        $max_duration = get_setting($pdo, 'binary_max_duration', 3600);

        if ($duration < $min_duration || $duration > $max_duration) {
            json_response(['success' => false, 'message' => t('error')], 400);
        }

        $result = open_binary_trade($pdo, $user_id, $pair_id, $symbol, $direction, $amount, $duration);
        json_response($result);

    } elseif ($_POST['action'] === 'get_price') {
        $symbol = sanitize($_POST['symbol'] ?? '');
        if (empty($symbol)) {
            json_response(['success' => false], 400);
        }
        $price = get_realtime_price($pdo, $symbol);
        json_response(['success' => true, 'price' => $price]);
    }
    exit;
}

// Get selected pair
$selected_symbol = sanitize($_GET['symbol'] ?? 'BTCUSDT');

$stmt = $pdo->prepare("SELECT * FROM trading_pairs WHERE symbol = ? AND is_active = 1 AND binary_enabled = 1");
$stmt->execute([$selected_symbol]);
$selected_pair = $stmt->fetch();

if (!$selected_pair) {
    $stmt = $pdo->prepare("SELECT * FROM trading_pairs WHERE symbol = 'BTCUSDT' AND is_active = 1");
    $stmt->execute();
    $selected_pair = $stmt->fetch();
    $selected_symbol = 'BTCUSDT';
}

// Get all pairs grouped by asset type
$stmt = $pdo->query("SELECT * FROM trading_pairs WHERE is_active = 1 AND binary_enabled = 1 ORDER BY asset_type, volume_24h DESC");
$all_pairs = $stmt->fetchAll();

$pairs_by_type = [];
foreach ($all_pairs as $pair) {
    $type = $pair['asset_type'] ?? 'crypto';
    $pairs_by_type[$type][] = $pair;
}

// Get user's pending trades
$stmt = $pdo->prepare("
    SELECT bt.*, tp.name_ar, tp.name_en
    FROM binary_trades bt
    JOIN trading_pairs tp ON bt.pair_id = tp.id
    WHERE bt.user_id = ? AND bt.status = 'pending'
    ORDER BY bt.expiry_time ASC
");
$stmt->execute([$user_id]);
$pending_trades = $stmt->fetchAll();

// Get completed trades
$stmt = $pdo->prepare("
    SELECT bt.*, tp.name_ar, tp.name_en
    FROM binary_trades bt
    JOIN trading_pairs tp ON bt.pair_id = tp.id
    WHERE bt.user_id = ? AND bt.status IN ('won', 'lost')
    ORDER BY bt.closed_at DESC LIMIT 20
");
$stmt->execute([$user_id]);
$completed_trades = $stmt->fetchAll();

// Stats
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_trades,
        SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) as won_trades,
        SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) as lost_trades,
        SUM(CASE WHEN profit_loss > 0 THEN profit_loss ELSE 0 END) as total_profit,
        SUM(CASE WHEN profit_loss < 0 THEN profit_loss ELSE 0 END) as total_loss
    FROM binary_trades WHERE user_id = ? AND status IN ('won', 'lost')
");
$stmt->execute([$user_id]);
$binary_stats = $stmt->fetch();

$win_rate = $binary_stats['total_trades'] > 0
    ? ($binary_stats['won_trades'] / $binary_stats['total_trades']) * 100 : 0;

$payout_percentage = get_setting($pdo, 'binary_payout_percentage', 85);
$unread_count = count(get_user_notifications($pdo, $user_id, true));

// Map symbol to TradingView format
function get_tv_symbol($symbol) {
    $map = [
        'BTCUSDT' => 'BINANCE:BTCUSDT', 'ETHUSDT' => 'BINANCE:ETHUSDT',
        'BNBUSDT' => 'BINANCE:BNBUSDT', 'XRPUSDT' => 'BINANCE:XRPUSDT',
        'ADAUSDT' => 'BINANCE:ADAUSDT', 'SOLUSDT' => 'BINANCE:SOLUSDT',
        'DOGEUSDT' => 'BINANCE:DOGEUSDT', 'MATICUSDT' => 'BINANCE:MATICUSDT',
        'EURUSD' => 'FX:EURUSD', 'GBPUSD' => 'FX:GBPUSD',
        'USDJPY' => 'FX:USDJPY', 'AUDUSD' => 'FX:AUDUSD', 'USDCAD' => 'FX:USDCAD',
        'US30' => 'FOREXCOM:DJI', 'NAS100' => 'NASDAQ:NDX',
        'SPX500' => 'FOREXCOM:SPX500', 'UK100' => 'FOREXCOM:UKXGBP',
        'GER40' => 'XETR:DAX',
        'AAPL' => 'NASDAQ:AAPL', 'GOOGL' => 'NASDAQ:GOOGL',
        'MSFT' => 'NASDAQ:MSFT', 'TSLA' => 'NASDAQ:TSLA', 'AMZN' => 'NASDAQ:AMZN',
    ];
    return $map[$symbol] ?? 'BINANCE:BTCUSDT';
}

$tv_symbol = get_tv_symbol($selected_symbol);
$lang = get_current_lang();
$is_rtl = is_rtl();
$name_field = $lang === 'ar' ? 'name_ar' : 'name_en';
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" dir="<?php echo get_dir(); ?>">
<head>
    <?php render_head(t('binary_trading')); ?>
    <?php render_base_css(); ?>
    <style>
        /* Binary Trading specific styles */
        .trading-layout {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 0;
            min-height: calc(100vh - 20px);
        }

        .chart-and-form {
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            padding: 20px;
        }

        /* TradingView Chart Container */
        .chart-container {
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            margin-bottom: 20px;
            position: relative;
        }
        .chart-container .tradingview-widget-container { height: 500px; }

        /* Stats Bar */
        .binary-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        .binary-stat {
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px;
            text-align: center;
        }
        .binary-stat-value {
            font-size: 24px;
            font-weight: 900;
            font-family: 'Poppins', sans-serif;
        }
        .binary-stat-label {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        /* Trading Form Card */
        .trade-form-card {
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px;
        }
        .form-title {
            font-size: 18px;
            font-weight: 900;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .form-title i { color: var(--accent); }

        /* Asset Type Tabs */
        .asset-tabs { margin-bottom: 16px; }

        /* Pair Grid */
        .pair-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
            gap: 8px;
            margin-bottom: 20px;
            max-height: 200px;
            overflow-y: auto;
        }
        .pair-btn {
            padding: 10px 6px;
            background: var(--glass);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all var(--transition);
            text-align: center;
            font-family: var(--font);
            color: var(--text);
        }
        .pair-btn:hover, .pair-btn.active {
            background: rgba(245, 158, 11, 0.1);
            border-color: var(--accent);
        }
        .pair-btn-name { font-size: 11px; font-weight: 700; }
        .pair-btn-price { font-size: 10px; color: var(--text-muted); margin-top: 2px; }

        /* Amount Input */
        .amount-input-wrapper {
            position: relative;
            margin-bottom: 12px;
        }
        .amount-input {
            width: 100%;
            padding: 16px 20px;
            background: rgba(0,0,0,0.2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--text);
            font-size: 20px;
            font-weight: 900;
            font-family: 'Poppins', sans-serif;
            text-align: center;
            min-height: 48px;
        }
        .amount-input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(245,158,11,0.1); }
        .quick-amounts {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 6px;
            margin-bottom: 16px;
        }
        .quick-btn {
            padding: 10px 4px;
            background: var(--glass);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--text-muted);
            font-weight: 700;
            font-size: 12px;
            cursor: pointer;
            transition: all var(--transition);
            font-family: var(--font);
            min-height: 44px;
        }
        .quick-btn:hover { border-color: var(--accent); color: var(--accent); }

        /* Duration Selector */
        .duration-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-bottom: 20px;
        }
        .duration-btn {
            padding: 12px 8px;
            background: var(--glass);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all var(--transition);
            text-align: center;
            font-family: var(--font);
            color: var(--text);
            min-height: 44px;
        }
        .duration-btn:hover, .duration-btn.active {
            background: rgba(59, 130, 246, 0.1);
            border-color: var(--info);
        }
        .duration-time { font-size: 18px; font-weight: 900; }
        .duration-label { font-size: 10px; color: var(--text-muted); }

        /* Trade Buttons */
        .trade-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .trade-btn {
            padding: 20px;
            border: none;
            border-radius: var(--radius);
            color: white;
            font-size: 18px;
            font-weight: 900;
            cursor: pointer;
            transition: all var(--transition);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            font-family: var(--font);
            min-height: 44px;
        }
        .trade-btn.up {
            background: linear-gradient(135deg, var(--success), #059669);
            box-shadow: 0 8px 32px rgba(16, 185, 129, 0.3);
        }
        .trade-btn.up:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 12px 40px rgba(16, 185, 129, 0.5);
        }
        .trade-btn.down {
            background: linear-gradient(135deg, var(--danger), #DC2626);
            box-shadow: 0 8px 32px rgba(239, 68, 68, 0.3);
        }
        .trade-btn.down:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 12px 40px rgba(239, 68, 68, 0.5);
        }
        .trade-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .trade-btn-icon { font-size: 28px; }
        .trade-btn-payout { font-size: 12px; opacity: 0.9; }

        /* ===== TRADES SIDEBAR ===== */
        .trades-panel {
            background: var(--secondary);
            <?php echo $is_rtl ? 'border-right: 1px solid var(--border);' : 'border-left: 1px solid var(--border);'; ?>
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            padding: 20px;
        }
        .trades-panel::-webkit-scrollbar { width: 4px; }
        .trades-panel::-webkit-scrollbar-thumb { background: var(--accent); border-radius: 2px; }

        .panel-title {
            font-size: 16px; font-weight: 700;
            margin-bottom: 16px;
            display: flex; align-items: center; justify-content: space-between;
        }
        .trades-count-badge {
            background: var(--accent); color: #000;
            font-size: 11px; padding: 3px 10px;
            border-radius: 10px; font-weight: 900;
        }

        /* Trade Card */
        .trade-card {
            background: var(--glass);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 12px;
            position: relative;
            overflow: hidden;
        }
        .trade-card::before {
            content: ''; position: absolute; top: 0; left: 0;
            width: 100%; height: 3px;
        }
        .trade-card.up::before { background: var(--success); }
        .trade-card.down::before { background: var(--danger); }
        .trade-card-header {
            display: flex; justify-content: space-between;
            align-items: center; margin-bottom: 12px;
        }
        .trade-card-symbol { font-weight: 700; font-size: 14px; }
        .trade-card-direction {
            padding: 4px 12px; border-radius: 6px;
            font-size: 11px; font-weight: 900; text-transform: uppercase;
        }
        .trade-card-direction.up { background: rgba(16,185,129,0.2); color: var(--success); }
        .trade-card-direction.down { background: rgba(239,68,68,0.2); color: var(--danger); }
        .trade-card-info {
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 8px; margin-bottom: 12px; font-size: 12px;
        }
        .info-label { color: var(--text-muted); margin-bottom: 2px; }
        .info-value { font-weight: 700; }

        /* Countdown */
        .countdown-timer {
            background: rgba(0,0,0,0.2); border-radius: var(--radius-sm);
            padding: 12px; text-align: center;
        }
        .countdown-label { font-size: 10px; color: var(--text-muted); margin-bottom: 4px; }
        .countdown-time {
            font-size: 28px; font-weight: 900;
            font-family: 'Poppins', monospace; color: var(--accent);
        }
        .countdown-progress {
            width: 100%; height: 4px; background: rgba(255,255,255,0.1);
            border-radius: 2px; margin-top: 8px; overflow: hidden;
        }
        .countdown-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--accent), var(--success));
            transition: width 1s linear;
        }

        /* Result Badge */
        .result-badge {
            padding: 8px 14px; border-radius: var(--radius-sm);
            text-align: center; font-weight: 900; font-size: 13px; margin-top: 10px;
        }
        .result-badge.won { background: rgba(16,185,129,0.2); color: var(--success); }
        .result-badge.lost { background: rgba(239,68,68,0.2); color: var(--danger); }

        .section-label {
            font-size: 12px; font-weight: 700; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .trading-layout { grid-template-columns: 1fr; }
            .trades-panel {
                <?php echo $is_rtl ? 'border-right: none;' : 'border-left: none;'; ?>
                border-top: 1px solid var(--border);
            }
        }

        @media (max-width: 768px) {
            .binary-stats { grid-template-columns: repeat(2, 1fr); gap: 8px; }
            .binary-stat { padding: 12px; }
            .binary-stat-value { font-size: 18px; }
            .chart-container .tradingview-widget-container { height: 350px; }
            .pair-grid { grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); }
            .quick-amounts { grid-template-columns: repeat(3, 1fr); }
            .duration-grid { grid-template-columns: repeat(2, 1fr); }
            .chart-and-form { padding: 12px; }
            .trades-panel { padding: 12px; }
            .trade-form-card { padding: 16px; }
        }

        @media (max-width: 480px) {
            .trade-buttons { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php render_sidebar('binary-trading', $user, $unread_count); ?>

    <main class="main" style="padding: 0;">
        <!-- Mobile top padding spacer -->
        <div style="height: 10px;" class="mobile-spacer"></div>

        <!-- Stats Bar -->
        <div style="padding: 16px 20px;">
            <div class="binary-stats">
                <div class="binary-stat">
                    <div class="binary-stat-value"><?php echo $binary_stats['total_trades'] ?? 0; ?></div>
                    <div class="binary-stat-label"><?php echo t('total_trades'); ?></div>
                </div>
                <div class="binary-stat">
                    <div class="binary-stat-value" style="color: var(--success);"><?php echo $binary_stats['won_trades'] ?? 0; ?></div>
                    <div class="binary-stat-label"><?php echo t('won'); ?></div>
                </div>
                <div class="binary-stat">
                    <div class="binary-stat-value" style="color: var(--danger);"><?php echo $binary_stats['lost_trades'] ?? 0; ?></div>
                    <div class="binary-stat-label"><?php echo t('lost'); ?></div>
                </div>
                <div class="binary-stat">
                    <div class="binary-stat-value" style="color: var(--accent);"><?php echo number_format($win_rate, 1); ?>%</div>
                    <div class="binary-stat-label"><?php echo t('win_rate'); ?></div>
                </div>
            </div>
        </div>

        <!-- Trading Layout -->
        <div class="trading-layout">
            <!-- Left: Chart + Form -->
            <div class="chart-and-form">
                <!-- TradingView Chart -->
                <div class="chart-container">
                    <div class="tradingview-widget-container">
                        <div id="tradingview_chart"></div>
                    </div>
                </div>

                <!-- Trading Form -->
                <div class="trade-form-card">
                    <div class="form-title">
                        <i class="fas fa-bullseye"></i>
                        <?php echo t('open_new_binary'); ?>
                    </div>

                    <div id="tradeAlert"></div>

                    <form id="binaryForm">
                        <input type="hidden" name="action" value="open_binary">
                        <input type="hidden" name="pair_id" id="pairId" value="<?php echo $selected_pair['id']; ?>">
                        <input type="hidden" name="symbol" id="symbol" value="<?php echo $selected_pair['symbol']; ?>">
                        <input type="hidden" name="direction" id="direction" value="">
                        <input type="hidden" name="duration" id="duration" value="60">

                        <!-- Asset Type Tabs -->
                        <div class="asset-tabs">
                            <?php
                            $asset_types = [
                                'crypto' => ['icon' => 'fab fa-bitcoin', 'label' => t('crypto')],
                                'forex' => ['icon' => 'fas fa-dollar-sign', 'label' => t('forex')],
                                'indices' => ['icon' => 'fas fa-chart-line', 'label' => t('indices')],
                                'stocks' => ['icon' => 'fas fa-building', 'label' => t('stocks')],
                            ];
                            $current_asset_type = $selected_pair['asset_type'] ?? 'crypto';
                            foreach ($asset_types as $type => $info):
                                if (empty($pairs_by_type[$type])) continue;
                            ?>
                                <button type="button" class="asset-tab <?php echo $type === $current_asset_type ? 'active' : ''; ?>"
                                        onclick="switchAssetType('<?php echo $type; ?>')">
                                    <i class="<?php echo $info['icon']; ?>"></i>
                                    <?php echo $info['label']; ?>
                                </button>
                            <?php endforeach; ?>
                        </div>

                        <!-- Pair Selector -->
                        <?php foreach ($pairs_by_type as $type => $pairs): ?>
                        <div class="pair-grid" id="pairs-<?php echo $type; ?>" style="<?php echo $type !== $current_asset_type ? 'display:none;' : ''; ?>">
                            <?php foreach ($pairs as $pair): ?>
                                <div class="pair-btn <?php echo $pair['symbol'] === $selected_symbol ? 'active' : ''; ?>"
                                     data-pair-id="<?php echo $pair['id']; ?>"
                                     data-symbol="<?php echo $pair['symbol']; ?>"
                                     onclick="selectPair(this)">
                                    <div class="pair-btn-name"><?php echo $pair[$name_field]; ?></div>
                                    <div class="pair-btn-price"><?php echo $pair['symbol']; ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>

                        <!-- Amount -->
                        <div class="section-label"><?php echo t('amount'); ?> ($)</div>
                        <div class="amount-input-wrapper">
                            <input type="number" name="amount" id="tradeAmount" class="amount-input"
                                   placeholder="0.00" min="<?php echo MIN_TRADE_AMOUNT; ?>"
                                   max="<?php echo $user['balance']; ?>" step="0.01" value="10" required>
                        </div>
                        <div class="quick-amounts">
                            <button type="button" class="quick-btn" onclick="setAmount(10)">$10</button>
                            <button type="button" class="quick-btn" onclick="setAmount(25)">$25</button>
                            <button type="button" class="quick-btn" onclick="setAmount(50)">$50</button>
                            <button type="button" class="quick-btn" onclick="setAmount(100)">$100</button>
                            <button type="button" class="quick-btn" onclick="setAmount(<?php echo floor($user['balance']); ?>)"><?php echo t('all'); ?></button>
                        </div>

                        <!-- Duration -->
                        <div class="section-label"><?php echo t('duration'); ?></div>
                        <div class="duration-grid">
                            <div class="duration-btn active" onclick="selectDuration(this, 60)">
                                <div class="duration-time">60</div>
                                <div class="duration-label"><?php echo t('seconds'); ?></div>
                            </div>
                            <div class="duration-btn" onclick="selectDuration(this, 300)">
                                <div class="duration-time">5</div>
                                <div class="duration-label"><?php echo t('minutes'); ?></div>
                            </div>
                            <div class="duration-btn" onclick="selectDuration(this, 900)">
                                <div class="duration-time">15</div>
                                <div class="duration-label"><?php echo t('minute'); ?></div>
                            </div>
                            <div class="duration-btn" onclick="selectDuration(this, 3600)">
                                <div class="duration-time">1</div>
                                <div class="duration-label"><?php echo t('hour'); ?></div>
                            </div>
                        </div>

                        <!-- Payout Info -->
                        <div style="display: flex; justify-content: space-between; padding: 12px 16px; background: rgba(0,0,0,0.2); border-radius: var(--radius-sm); margin-bottom: 16px; font-size: 13px;">
                            <span style="color: var(--text-muted);"><?php echo t('payout_rate'); ?>:</span>
                            <span style="color: var(--success); font-weight: 700;"><?php echo $payout_percentage; ?>%</span>
                        </div>

                        <!-- Trade Buttons -->
                        <div class="trade-buttons">
                            <button type="button" class="trade-btn up" id="upBtn" onclick="submitTrade('up')">
                                <span class="trade-btn-icon"><i class="fas fa-arrow-up"></i></span>
                                <span><?php echo t('up'); ?> (UP)</span>
                                <span class="trade-btn-payout"><?php echo t('payout'); ?>: <?php echo $payout_percentage; ?>%</span>
                            </button>
                            <button type="button" class="trade-btn down" id="downBtn" onclick="submitTrade('down')">
                                <span class="trade-btn-icon"><i class="fas fa-arrow-down"></i></span>
                                <span><?php echo t('down'); ?> (DOWN)</span>
                                <span class="trade-btn-payout"><?php echo t('payout'); ?>: <?php echo $payout_percentage; ?>%</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Right: Active/Completed Trades -->
            <div class="trades-panel">
                <div class="tabs" style="border-bottom: 1px solid var(--border); margin-bottom: 16px;">
                    <button class="tab-btn active" onclick="switchTradeTab('pending', this)">
                        <?php echo t('active_trades'); ?> (<?php echo count($pending_trades); ?>)
                    </button>
                    <button class="tab-btn" onclick="switchTradeTab('completed', this)">
                        <?php echo t('completed_trades'); ?>
                    </button>
                </div>

                <div id="pendingContent" class="tab-content active">
                    <?php if (empty($pending_trades)): ?>
                        <div class="empty-state">
                            <div class="empty-icon"><i class="fas fa-bullseye"></i></div>
                            <div><?php echo t('no_active_trades'); ?></div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($pending_trades as $trade): ?>
                            <div class="trade-card <?php echo $trade['direction']; ?>">
                                <div class="trade-card-header">
                                    <span class="trade-card-symbol"><?php echo $trade['symbol']; ?></span>
                                    <span class="trade-card-direction <?php echo $trade['direction']; ?>">
                                        <?php echo $trade['direction'] === 'up' ? t('up') : t('down'); ?>
                                    </span>
                                </div>
                                <div class="trade-card-info">
                                    <div>
                                        <div class="info-label"><?php echo t('amount'); ?></div>
                                        <div class="info-value">$<?php echo number_format($trade['amount'], 2); ?></div>
                                    </div>
                                    <div>
                                        <div class="info-label"><?php echo t('entry_price'); ?></div>
                                        <div class="info-value">$<?php echo number_format($trade['entry_price'], 2); ?></div>
                                    </div>
                                    <div>
                                        <div class="info-label"><?php echo t('potential_profit'); ?></div>
                                        <div class="info-value" style="color: var(--success);">
                                            $<?php echo number_format($trade['amount'] * ($trade['payout_percentage'] / 100), 2); ?>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="info-label"><?php echo t('payout_rate'); ?></div>
                                        <div class="info-value"><?php echo $trade['payout_percentage']; ?>%</div>
                                    </div>
                                </div>
                                <div class="countdown-timer">
                                    <div class="countdown-label"><?php echo t('time_remaining'); ?></div>
                                    <div class="countdown-time" data-expiry="<?php echo strtotime($trade['expiry_time']); ?>" data-duration="<?php echo $trade['duration_seconds']; ?>">--:--</div>
                                    <div class="countdown-progress">
                                        <div class="countdown-progress-bar" style="width: 100%;"></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div id="completedContent" class="tab-content">
                    <?php if (empty($completed_trades)): ?>
                        <div class="empty-state">
                            <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                            <div><?php echo t('no_completed_trades'); ?></div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($completed_trades as $trade): ?>
                            <div class="trade-card <?php echo $trade['direction']; ?>" style="opacity: 0.85;">
                                <div class="trade-card-header">
                                    <span class="trade-card-symbol"><?php echo $trade['symbol']; ?></span>
                                    <span class="trade-card-direction <?php echo $trade['direction']; ?>">
                                        <?php echo $trade['direction'] === 'up' ? t('up') : t('down'); ?>
                                    </span>
                                </div>
                                <div class="trade-card-info">
                                    <div>
                                        <div class="info-label"><?php echo t('amount'); ?></div>
                                        <div class="info-value">$<?php echo number_format($trade['amount'], 2); ?></div>
                                    </div>
                                    <div>
                                        <div class="info-label"><?php echo t('profit_loss'); ?></div>
                                        <div class="info-value" style="color: <?php echo $trade['profit_loss'] >= 0 ? 'var(--success)' : 'var(--danger)'; ?>">
                                            <?php echo $trade['profit_loss'] >= 0 ? '+' : ''; ?>$<?php echo number_format($trade['profit_loss'], 2); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="result-badge <?php echo $trade['status']; ?>">
                                    <?php echo $trade['status'] === 'won' ? t('won') : t('lost'); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- TradingView Widget Script -->
    <script type="text/javascript" src="https://s3.tradingview.com/tv.js"></script>
    <script type="text/javascript">
    // Initialize TradingView Chart
    let tvWidget;
    function initChart(symbol) {
        const container = document.getElementById('tradingview_chart');
        container.innerHTML = '';
        tvWidget = new TradingView.widget({
            "width": "100%",
            "height": "100%",
            "symbol": symbol,
            "interval": "5",
            "timezone": "Etc/UTC",
            "theme": "dark",
            "style": "1",
            "locale": "<?php echo $lang === 'ar' ? 'ar_AE' : 'en'; ?>",
            "toolbar_bg": "#1E293B",
            "enable_publishing": false,
            "hide_side_toolbar": false,
            "allow_symbol_change": false,
            "save_image": false,
            "studies": ["STD;RSI"],
            "container_id": "tradingview_chart",
            "backgroundColor": "#0F172A",
            "gridColor": "rgba(148, 163, 184, 0.06)",
            "hide_volume": false
        });
    }
    initChart('<?php echo $tv_symbol; ?>');

    // TradingView symbol map for dynamic switching
    const tvSymbolMap = {
        <?php foreach ($all_pairs as $pair): ?>
        '<?php echo $pair['symbol']; ?>': '<?php echo get_tv_symbol($pair['symbol']); ?>',
        <?php endforeach; ?>
    };
    </script>

    <?php render_base_js(); ?>

    <script>
    // Asset type switching
    function switchAssetType(type) {
        document.querySelectorAll('.asset-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.pair-grid').forEach(g => g.style.display = 'none');
        event.target.closest('.asset-tab').classList.add('active');
        const grid = document.getElementById('pairs-' + type);
        if (grid) grid.style.display = 'grid';
    }

    // Select Pair
    function selectPair(el) {
        document.querySelectorAll('.pair-btn').forEach(b => b.classList.remove('active'));
        el.classList.add('active');
        document.getElementById('pairId').value = el.dataset.pairId;
        document.getElementById('symbol').value = el.dataset.symbol;

        // Update TradingView chart
        const tvSym = tvSymbolMap[el.dataset.symbol];
        if (tvSym) initChart(tvSym);

        // Update price via AJAX
        fetch('binary-trading.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=get_price&symbol=' + el.dataset.symbol
        }).then(r => r.json()).then(d => {
            if (d.success) {
                // Price updated in background
            }
        });
    }

    // Set Amount
    function setAmount(a) { document.getElementById('tradeAmount').value = a; }

    // Select Duration
    function selectDuration(el, sec) {
        document.querySelectorAll('.duration-btn').forEach(b => b.classList.remove('active'));
        el.classList.add('active');
        document.getElementById('duration').value = sec;
    }

    // Submit Trade
    async function submitTrade(direction) {
        const amount = parseFloat(document.getElementById('tradeAmount').value);
        if (!amount || amount < <?php echo MIN_TRADE_AMOUNT; ?>) {
            showToast('<?php echo t('min_trade'); ?>: $<?php echo MIN_TRADE_AMOUNT; ?>', 'error');
            return;
        }
        if (amount > <?php echo $user['balance']; ?>) {
            showToast('<?php echo t('insufficient_balance'); ?>', 'error');
            return;
        }

        document.getElementById('direction').value = direction;
        const upBtn = document.getElementById('upBtn');
        const downBtn = document.getElementById('downBtn');
        upBtn.disabled = true;
        downBtn.disabled = true;

        const formData = new FormData(document.getElementById('binaryForm'));
        try {
            const res = await fetch('binary-trading.php', { method: 'POST', body: formData });
            const result = await res.json();
            if (result.success) {
                showToast(result.message, 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast(result.message, 'error');
                upBtn.disabled = false;
                downBtn.disabled = false;
            }
        } catch (e) {
            showToast('<?php echo t('error'); ?>', 'error');
            upBtn.disabled = false;
            downBtn.disabled = false;
        }
    }

    // Trade Tabs
    function switchTradeTab(tab, btn) {
        document.querySelectorAll('.trades-panel .tab-btn').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.trades-panel .tab-content').forEach(c => c.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(tab + 'Content').classList.add('active');
    }

    // Countdown Timers
    function updateCountdowns() {
        document.querySelectorAll('.countdown-time').forEach(timer => {
            const expiry = parseInt(timer.dataset.expiry);
            const duration = parseInt(timer.dataset.duration) || 60;
            const now = Math.floor(Date.now() / 1000);
            const remaining = expiry - now;

            if (remaining <= 0) {
                timer.textContent = '00:00';
                const bar = timer.parentElement.querySelector('.countdown-progress-bar');
                if (bar) bar.style.width = '0%';
                setTimeout(() => window.location.reload(), 2000);
            } else {
                const m = Math.floor(remaining / 60);
                const s = remaining % 60;
                timer.textContent = m.toString().padStart(2, '0') + ':' + s.toString().padStart(2, '0');
                const bar = timer.parentElement.querySelector('.countdown-progress-bar');
                if (bar) bar.style.width = ((remaining / duration) * 100) + '%';
            }
        });
    }
    setInterval(updateCountdowns, 1000);
    updateCountdowns();

    // Price auto-update
    setInterval(() => {
        fetch('binary-trading.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=get_price&symbol=' + document.getElementById('symbol').value
        }).then(r => r.json()).catch(() => {});
    }, <?php echo (get_setting($pdo, 'price_update_interval', 5) * 1000); ?>);
    </script>
</body>
</html>
