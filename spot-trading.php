<?php
define('APP_ACCESS', true);
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/components.php';

// Require login
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

    if ($_POST['action'] === 'open_trade') {
        $pair_id = intval($_POST['pair_id'] ?? 0);
        $symbol = sanitize($_POST['symbol'] ?? '');
        $type = sanitize($_POST['type'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $leverage = floatval($_POST['leverage'] ?? 1);

        // Validation
        if (empty($pair_id) || empty($symbol) || !in_array($type, ['buy', 'sell'])) {
            json_response(['success' => false, 'message' => t('invalid_data')], 400);
        }

        if ($amount < MIN_TRADE_AMOUNT) {
            json_response(['success' => false, 'message' => t('min_trade') . ': $' . MIN_TRADE_AMOUNT], 400);
        }

        // Re-fetch user balance
        $user = get_user_data($pdo, $user_id);
        if ($amount > $user['balance']) {
            json_response(['success' => false, 'message' => t('insufficient_balance')], 400);
        }

        // Open trade
        $result = open_spot_trade($pdo, $user_id, $pair_id, $symbol, $type, $amount, $leverage);
        json_response($result);

    } elseif ($_POST['action'] === 'close_trade') {
        $trade_id = intval($_POST['trade_id'] ?? 0);

        if (empty($trade_id)) {
            json_response(['success' => false, 'message' => t('trade_id_required')], 400);
        }

        $result = close_spot_trade($pdo, $trade_id, $user_id);
        json_response($result);

    } elseif ($_POST['action'] === 'get_price') {
        $symbol = sanitize($_POST['symbol'] ?? '');

        if (empty($symbol)) {
            json_response(['success' => false, 'message' => t('symbol_required')], 400);
        }

        $price = get_realtime_price($pdo, $symbol);

        if ($price === false) {
            json_response(['success' => false, 'message' => t('price_fetch_failed')], 500);
        }

        json_response(['success' => true, 'price' => $price]);
    }

    exit;
}

// Get selected pair (default BTC)
$selected_symbol = sanitize($_GET['symbol'] ?? 'BTCUSDT');

// Get trading pairs
$stmt = $pdo->prepare("SELECT * FROM trading_pairs WHERE symbol = ? AND is_active = 1 AND spot_enabled = 1");
$stmt->execute([$selected_symbol]);
$selected_pair = $stmt->fetch();

if (!$selected_pair) {
    // Fallback to BTC
    $stmt = $pdo->prepare("SELECT * FROM trading_pairs WHERE symbol = 'BTCUSDT' AND is_active = 1");
    $stmt->execute();
    $selected_pair = $stmt->fetch();
    $selected_symbol = 'BTCUSDT';
}

// Get pairs grouped by asset_type
$stmt = $pdo->query("SELECT * FROM trading_pairs WHERE is_active = 1 AND spot_enabled = 1 ORDER BY asset_type, volume_24h DESC");
$all_pairs = $stmt->fetchAll();
$pairs_by_type = [];
foreach ($all_pairs as $pair) {
    $type = $pair['asset_type'] ?? 'crypto';
    $pairs_by_type[$type][] = $pair;
}

// Get user's open trades
$stmt = $pdo->prepare("
    SELECT st.*, tp.name_ar, tp.name_en
    FROM spot_trades st
    JOIN trading_pairs tp ON st.pair_id = tp.id
    WHERE st.user_id = ? AND st.status = 'open'
    ORDER BY st.created_at DESC
");
$stmt->execute([$user_id]);
$open_trades = $stmt->fetchAll();

// Get user's closed trades
$stmt = $pdo->prepare("
    SELECT st.*, tp.name_ar, tp.name_en
    FROM spot_trades st
    JOIN trading_pairs tp ON st.pair_id = tp.id
    WHERE st.user_id = ? AND st.status = 'closed'
    ORDER BY st.closed_at DESC
    LIMIT 20
");
$stmt->execute([$user_id]);
$closed_trades = $stmt->fetchAll();

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
    <?php render_head(t('spot_trading')); ?>
    <?php render_base_css(); ?>
    <style>
        /* Spot Trading specific styles */
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

        /* Trading Form Card */
        .trade-form-card {
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 20px;
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

        /* Leverage Slider */
        .leverage-section { margin-bottom: 20px; }
        .leverage-slider {
            width: 100%;
            -webkit-appearance: none;
            height: 6px;
            border-radius: 3px;
            background: rgba(255,255,255,0.1);
            outline: none;
            margin-top: 8px;
        }
        .leverage-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: var(--accent);
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(245,158,11,0.4);
        }
        .leverage-slider::-moz-range-thumb {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: var(--accent);
            cursor: pointer;
            border: none;
        }
        .leverage-value {
            text-align: center;
            margin-top: 10px;
            font-size: 22px;
            font-weight: 900;
            color: var(--accent);
            font-family: 'Poppins', sans-serif;
        }

        /* Trade Summary */
        .trade-summary {
            background: rgba(0,0,0,0.2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 16px;
            margin-bottom: 20px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 13px;
        }
        .summary-row:last-child { margin-bottom: 0; }
        .summary-label { color: var(--text-muted); }
        .summary-value { font-weight: 700; font-family: 'Poppins', sans-serif; }

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
        .trade-btn.buy-btn {
            background: linear-gradient(135deg, var(--success), #059669);
            box-shadow: 0 8px 32px rgba(16, 185, 129, 0.3);
        }
        .trade-btn.buy-btn:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 12px 40px rgba(16, 185, 129, 0.5);
        }
        .trade-btn.sell-btn {
            background: linear-gradient(135deg, var(--danger), #DC2626);
            box-shadow: 0 8px 32px rgba(239, 68, 68, 0.3);
        }
        .trade-btn.sell-btn:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 12px 40px rgba(239, 68, 68, 0.5);
        }
        .trade-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .trade-btn-icon { font-size: 28px; }

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
        .trade-card.buy-card::before { background: var(--success); }
        .trade-card.sell-card::before { background: var(--danger); }
        .trade-card-header {
            display: flex; justify-content: space-between;
            align-items: center; margin-bottom: 12px;
        }
        .trade-card-symbol { font-weight: 700; font-size: 14px; }
        .trade-card-type {
            padding: 4px 12px; border-radius: 6px;
            font-size: 11px; font-weight: 900; text-transform: uppercase;
        }
        .trade-card-type.buy { background: rgba(16,185,129,0.2); color: var(--success); }
        .trade-card-type.sell { background: rgba(239,68,68,0.2); color: var(--danger); }
        .trade-card-info {
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 8px; margin-bottom: 12px; font-size: 12px;
        }
        .info-label { color: var(--text-muted); margin-bottom: 2px; }
        .info-value { font-weight: 700; }

        /* PnL Display */
        .pnl-display {
            background: rgba(0,0,0,0.2); border-radius: var(--radius-sm);
            padding: 12px; text-align: center; margin-bottom: 12px;
        }
        .pnl-label { font-size: 10px; color: var(--text-muted); margin-bottom: 4px; }
        .pnl-value {
            font-size: 20px; font-weight: 900;
            font-family: 'Poppins', sans-serif;
        }
        .pnl-value.profit { color: var(--success); }
        .pnl-value.loss { color: var(--danger); }

        /* Close Trade Button */
        .btn-close-trade {
            width: 100%;
            padding: 10px;
            background: var(--danger);
            border: none;
            border-radius: var(--radius-sm);
            color: white;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            transition: all var(--transition);
            font-family: var(--font);
            min-height: 44px;
        }
        .btn-close-trade:hover {
            background: #DC2626;
            transform: translateY(-2px);
        }

        /* Result Badge for closed */
        .result-badge {
            padding: 8px 14px; border-radius: var(--radius-sm);
            text-align: center; font-weight: 900; font-size: 13px; margin-top: 10px;
        }
        .result-badge.profit { background: rgba(16,185,129,0.2); color: var(--success); }
        .result-badge.loss-badge { background: rgba(239,68,68,0.2); color: var(--danger); }

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
            .chart-container .tradingview-widget-container { height: 350px; }
            .pair-grid { grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); }
            .quick-amounts { grid-template-columns: repeat(3, 1fr); }
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
    <?php render_sidebar('spot-trading', $user, $unread_count); ?>

    <main class="main" style="padding: 0;">
        <!-- Mobile top padding spacer -->
        <div style="height: 10px;" class="mobile-spacer"></div>

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
                        <i class="fas fa-chart-area"></i>
                        <?php echo t('open_new_spot'); ?>
                    </div>

                    <div id="tradeAlert"></div>

                    <form id="spotForm">
                        <input type="hidden" name="action" value="open_trade">
                        <input type="hidden" name="pair_id" id="pairId" value="<?php echo $selected_pair['id']; ?>">
                        <input type="hidden" name="symbol" id="symbol" value="<?php echo $selected_pair['symbol']; ?>">
                        <input type="hidden" name="type" id="tradeType" value="buy">

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
                            foreach ($asset_types as $atype => $info):
                                if (empty($pairs_by_type[$atype])) continue;
                            ?>
                                <button type="button" class="asset-tab <?php echo $atype === $current_asset_type ? 'active' : ''; ?>"
                                        onclick="switchAssetType('<?php echo $atype; ?>')">
                                    <i class="<?php echo $info['icon']; ?>"></i>
                                    <?php echo $info['label']; ?>
                                </button>
                            <?php endforeach; ?>
                        </div>

                        <!-- Pair Selector -->
                        <?php foreach ($pairs_by_type as $ptype => $pairs): ?>
                        <div class="pair-grid" id="pairs-<?php echo $ptype; ?>" style="<?php echo $ptype !== $current_asset_type ? 'display:none;' : ''; ?>">
                            <?php foreach ($pairs as $pair): ?>
                                <div class="pair-btn <?php echo $pair['symbol'] === $selected_symbol ? 'active' : ''; ?>"
                                     data-pair-id="<?php echo $pair['id']; ?>"
                                     data-symbol="<?php echo $pair['symbol']; ?>"
                                     data-price="<?php echo $pair['current_price']; ?>"
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

                        <!-- Leverage -->
                        <div class="leverage-section">
                            <div class="section-label"><?php echo t('leverage'); ?> (1x - 10x)</div>
                            <input type="range" name="leverage" id="leverageSlider" class="leverage-slider"
                                   min="1" max="10" value="1" step="0.5">
                            <div class="leverage-value" id="leverageValue">1.0x</div>
                        </div>

                        <!-- Trade Summary -->
                        <div class="trade-summary">
                            <div class="summary-row">
                                <span class="summary-label"><?php echo t('current_price'); ?>:</span>
                                <span class="summary-value" id="summaryPrice">$<?php echo number_format($selected_pair['current_price'], 2); ?></span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label"><?php echo t('expected_quantity'); ?>:</span>
                                <span class="summary-value" id="summaryQuantity">0.00</span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label"><?php echo t('fee'); ?> (0.1%):</span>
                                <span class="summary-value" id="summaryFee">$0.00</span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label"><?php echo t('total'); ?>:</span>
                                <span class="summary-value" id="summaryTotal">$0.00</span>
                            </div>
                        </div>

                        <!-- Trade Buttons -->
                        <div class="trade-buttons">
                            <button type="button" class="trade-btn buy-btn" id="buyBtn" onclick="submitTrade('buy')">
                                <span class="trade-btn-icon"><i class="fas fa-arrow-up"></i></span>
                                <span><?php echo t('buy_now'); ?></span>
                            </button>
                            <button type="button" class="trade-btn sell-btn" id="sellBtn" onclick="submitTrade('sell')">
                                <span class="trade-btn-icon"><i class="fas fa-arrow-down"></i></span>
                                <span><?php echo t('sell_now'); ?></span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Open Trades Table (Desktop) -->
                <div class="card" style="margin-bottom: 20px;">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="fas fa-chart-line"></i>
                            <?php echo t('open_trades'); ?> (<?php echo count($open_trades); ?>)
                        </div>
                    </div>
                    <?php if (empty($open_trades)): ?>
                        <div class="empty-state">
                            <div class="empty-icon"><i class="fas fa-chart-area"></i></div>
                            <div><?php echo t('no_open_trades'); ?></div>
                        </div>
                    <?php else: ?>
                        <div class="table-wrapper">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th><?php echo t('select_pair'); ?></th>
                                        <th><?php echo t('trade_type'); ?></th>
                                        <th><?php echo t('amount'); ?></th>
                                        <th><?php echo t('entry_price'); ?></th>
                                        <th><?php echo t('leverage'); ?></th>
                                        <th><?php echo t('profit_loss'); ?></th>
                                        <th><?php echo t('actions'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($open_trades as $trade):
                                        $current_price = $selected_pair['current_price'];
                                        if ($trade['type'] === 'buy') {
                                            $pnl = ($current_price - $trade['entry_price']) * $trade['quantity'];
                                        } else {
                                            $pnl = ($trade['entry_price'] - $current_price) * $trade['quantity'];
                                        }
                                        $pnl_percentage = $trade['amount'] > 0 ? ($pnl / $trade['amount']) * 100 : 0;
                                    ?>
                                        <tr>
                                            <td><strong><?php echo $trade['symbol']; ?></strong></td>
                                            <td>
                                                <span class="badge <?php echo $trade['type']; ?>">
                                                    <?php echo $trade['type'] === 'buy' ? t('buy') : t('sell'); ?>
                                                </span>
                                            </td>
                                            <td>$<?php echo number_format($trade['amount'], 2); ?></td>
                                            <td>$<?php echo number_format($trade['entry_price'], 2); ?></td>
                                            <td><?php echo number_format($trade['leverage'] ?? 1, 1); ?>x</td>
                                            <td class="<?php echo $pnl >= 0 ? 'profit' : 'loss'; ?>">
                                                <?php echo $pnl >= 0 ? '+' : ''; ?>$<?php echo number_format($pnl, 2); ?>
                                                (<?php echo number_format($pnl_percentage, 2); ?>%)
                                            </td>
                                            <td>
                                                <button class="btn btn-danger btn-sm" onclick="closeTrade(<?php echo $trade['id']; ?>)">
                                                    <i class="fas fa-times"></i> <?php echo t('close_trade'); ?>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Closed Trades Table (Desktop) -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="fas fa-history"></i>
                            <?php echo t('closed_trades'); ?>
                        </div>
                    </div>
                    <?php if (empty($closed_trades)): ?>
                        <div class="empty-state">
                            <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                            <div><?php echo t('no_closed_trades'); ?></div>
                        </div>
                    <?php else: ?>
                        <div class="table-wrapper">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th><?php echo t('select_pair'); ?></th>
                                        <th><?php echo t('trade_type'); ?></th>
                                        <th><?php echo t('amount'); ?></th>
                                        <th><?php echo t('entry_price'); ?></th>
                                        <th><?php echo t('exit_price'); ?></th>
                                        <th><?php echo t('profit_loss'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($closed_trades as $trade): ?>
                                        <tr>
                                            <td><strong><?php echo $trade['symbol']; ?></strong></td>
                                            <td>
                                                <span class="badge <?php echo $trade['type']; ?>">
                                                    <?php echo $trade['type'] === 'buy' ? t('buy') : t('sell'); ?>
                                                </span>
                                            </td>
                                            <td>$<?php echo number_format($trade['amount'], 2); ?></td>
                                            <td>$<?php echo number_format($trade['entry_price'], 2); ?></td>
                                            <td>$<?php echo number_format($trade['exit_price'], 2); ?></td>
                                            <td class="<?php echo $trade['profit_loss'] >= 0 ? 'profit' : 'loss'; ?>">
                                                <?php echo $trade['profit_loss'] >= 0 ? '+' : ''; ?>$<?php echo number_format($trade['profit_loss'], 2); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right: Active Trades Panel -->
            <div class="trades-panel">
                <div class="tabs" style="border-bottom: 1px solid var(--border); margin-bottom: 16px;">
                    <button class="tab-btn active" onclick="switchTradeTab('open', this)">
                        <?php echo t('open_trades'); ?> (<?php echo count($open_trades); ?>)
                    </button>
                    <button class="tab-btn" onclick="switchTradeTab('closed', this)">
                        <?php echo t('closed_trades'); ?>
                    </button>
                </div>

                <div id="openContent" class="tab-content active">
                    <?php if (empty($open_trades)): ?>
                        <div class="empty-state">
                            <div class="empty-icon"><i class="fas fa-chart-area"></i></div>
                            <div><?php echo t('no_open_trades'); ?></div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($open_trades as $trade):
                            $current_price = $selected_pair['current_price'];
                            if ($trade['type'] === 'buy') {
                                $pnl = ($current_price - $trade['entry_price']) * $trade['quantity'];
                            } else {
                                $pnl = ($trade['entry_price'] - $current_price) * $trade['quantity'];
                            }
                            $pnl_percentage = $trade['amount'] > 0 ? ($pnl / $trade['amount']) * 100 : 0;
                        ?>
                            <div class="trade-card <?php echo $trade['type'] === 'buy' ? 'buy-card' : 'sell-card'; ?>">
                                <div class="trade-card-header">
                                    <span class="trade-card-symbol"><?php echo $trade['symbol']; ?></span>
                                    <span class="trade-card-type <?php echo $trade['type']; ?>">
                                        <?php echo $trade['type'] === 'buy' ? t('buy') : t('sell'); ?>
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
                                        <div class="info-label"><?php echo t('leverage'); ?></div>
                                        <div class="info-value"><?php echo number_format($trade['leverage'] ?? 1, 1); ?>x</div>
                                    </div>
                                    <div>
                                        <div class="info-label"><?php echo t('quantity'); ?></div>
                                        <div class="info-value"><?php echo number_format($trade['quantity'], 6); ?></div>
                                    </div>
                                </div>
                                <div class="pnl-display">
                                    <div class="pnl-label"><?php echo t('profit_loss'); ?></div>
                                    <div class="pnl-value <?php echo $pnl >= 0 ? 'profit' : 'loss'; ?>">
                                        <?php echo $pnl >= 0 ? '+' : ''; ?>$<?php echo number_format($pnl, 2); ?>
                                        (<?php echo number_format($pnl_percentage, 2); ?>%)
                                    </div>
                                </div>
                                <button class="btn-close-trade" onclick="closeTrade(<?php echo $trade['id']; ?>)">
                                    <i class="fas fa-times"></i> <?php echo t('close_trade'); ?>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div id="closedContent" class="tab-content">
                    <?php if (empty($closed_trades)): ?>
                        <div class="empty-state">
                            <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                            <div><?php echo t('no_closed_trades'); ?></div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($closed_trades as $trade): ?>
                            <div class="trade-card <?php echo $trade['type'] === 'buy' ? 'buy-card' : 'sell-card'; ?>" style="opacity: 0.85;">
                                <div class="trade-card-header">
                                    <span class="trade-card-symbol"><?php echo $trade['symbol']; ?></span>
                                    <span class="trade-card-type <?php echo $trade['type']; ?>">
                                        <?php echo $trade['type'] === 'buy' ? t('buy') : t('sell'); ?>
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
                                        <div class="info-label"><?php echo t('exit_price'); ?></div>
                                        <div class="info-value">$<?php echo number_format($trade['exit_price'], 2); ?></div>
                                    </div>
                                    <div>
                                        <div class="info-label"><?php echo t('profit_loss'); ?></div>
                                        <div class="info-value" style="color: <?php echo $trade['profit_loss'] >= 0 ? 'var(--success)' : 'var(--danger)'; ?>">
                                            <?php echo $trade['profit_loss'] >= 0 ? '+' : ''; ?>$<?php echo number_format($trade['profit_loss'], 2); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="result-badge <?php echo $trade['profit_loss'] >= 0 ? 'profit' : 'loss-badge'; ?>">
                                    <?php echo $trade['profit_loss'] >= 0 ? t('profit') : t('loss'); ?>:
                                    $<?php echo number_format(abs($trade['profit_loss']), 2); ?>
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
    let currentPrice = <?php echo $selected_pair['current_price']; ?>;

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
        fetch('spot-trading.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=get_price&symbol=' + el.dataset.symbol
        }).then(r => r.json()).then(d => {
            if (d.success) {
                currentPrice = parseFloat(d.price);
                document.getElementById('summaryPrice').textContent = '$' + currentPrice.toFixed(2);
                updateTradeSummary();
            }
        });
    }

    // Set Amount
    function setAmount(a) {
        document.getElementById('tradeAmount').value = a;
        updateTradeSummary();
    }

    // Leverage Slider
    const leverageSlider = document.getElementById('leverageSlider');
    const leverageValue = document.getElementById('leverageValue');
    leverageSlider.addEventListener('input', () => {
        leverageValue.textContent = parseFloat(leverageSlider.value).toFixed(1) + 'x';
    });

    // Update Trade Summary
    const tradeAmount = document.getElementById('tradeAmount');
    tradeAmount.addEventListener('input', updateTradeSummary);

    function updateTradeSummary() {
        const amount = parseFloat(tradeAmount.value) || 0;
        const quantity = amount / currentPrice;
        const fee = amount * 0.001;
        const total = amount + fee;

        document.getElementById('summaryQuantity').textContent = quantity.toFixed(8);
        document.getElementById('summaryFee').textContent = '$' + fee.toFixed(2);
        document.getElementById('summaryTotal').textContent = '$' + total.toFixed(2);
    }
    updateTradeSummary();

    // Submit Trade
    async function submitTrade(type) {
        const amount = parseFloat(document.getElementById('tradeAmount').value);
        if (!amount || amount < <?php echo MIN_TRADE_AMOUNT; ?>) {
            showToast('<?php echo t('min_trade'); ?>: $<?php echo MIN_TRADE_AMOUNT; ?>', 'error');
            return;
        }
        if (amount > <?php echo $user['balance']; ?>) {
            showToast('<?php echo t('insufficient_balance'); ?>', 'error');
            return;
        }

        document.getElementById('tradeType').value = type;
        const buyBtn = document.getElementById('buyBtn');
        const sellBtn = document.getElementById('sellBtn');
        buyBtn.disabled = true;
        sellBtn.disabled = true;

        const formData = new FormData(document.getElementById('spotForm'));
        try {
            const res = await fetch('spot-trading.php', { method: 'POST', body: formData });
            const result = await res.json();
            if (result.success) {
                showToast(result.message, 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast(result.message, 'error');
                buyBtn.disabled = false;
                sellBtn.disabled = false;
            }
        } catch (e) {
            showToast('<?php echo t('connection_error'); ?>', 'error');
            buyBtn.disabled = false;
            sellBtn.disabled = false;
        }
    }

    // Close Trade
    async function closeTrade(tradeId) {
        if (!confirm('<?php echo t('confirm_close_trade'); ?>')) {
            return;
        }

        const formData = new FormData();
        formData.append('action', 'close_trade');
        formData.append('trade_id', tradeId);

        try {
            const res = await fetch('spot-trading.php', { method: 'POST', body: formData });
            const result = await res.json();
            if (result.success) {
                showToast(result.message, 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast(result.message, 'error');
            }
        } catch (e) {
            showToast('<?php echo t('connection_error'); ?>', 'error');
        }
    }

    // Trade Tabs (sidebar panel)
    function switchTradeTab(tab, btn) {
        document.querySelectorAll('.trades-panel .tab-btn').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.trades-panel .tab-content').forEach(c => c.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(tab + 'Content').classList.add('active');
    }

    // Price auto-update
    setInterval(() => {
        fetch('spot-trading.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=get_price&symbol=' + document.getElementById('symbol').value
        }).then(r => r.json()).then(d => {
            if (d.success) {
                currentPrice = parseFloat(d.price);
                document.getElementById('summaryPrice').textContent = '$' + currentPrice.toFixed(2);
                updateTradeSummary();
            }
        }).catch(() => {});
    }, 30000);
    </script>
</body>
</html>
