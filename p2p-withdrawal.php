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

$lang = get_current_lang();
$unread_count = count(get_user_notifications($pdo, $user_id, true));

// Handle withdrawal request (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'submit_withdrawal') {
        $gateway_id = intval($_POST['gateway_id'] ?? 0);
        $amount = floatval($_POST['amount'] ?? 0);
        $account_details = sanitize($_POST['account_details'] ?? '');

        // Refresh user data to get accurate balance
        $user = get_user_data($pdo, $user_id);

        // Get gateway
        $stmt = $pdo->prepare("SELECT * FROM payment_gateways WHERE id = ? AND is_active = 1");
        $stmt->execute([$gateway_id]);
        $gateway = $stmt->fetch();

        if (!$gateway) {
            json_response(['success' => false, 'message' => t('select_gateway')], 400);
        }

        // Validate amount
        if ($amount <= 0) {
            json_response(['success' => false, 'message' => t('amount_out_of_range')], 400);
        }

        $min = floatval($gateway['min_withdrawal'] ?? $gateway['min_deposit'] ?? 10);
        $max = floatval($gateway['max_withdrawal'] ?? $gateway['max_deposit'] ?? 100000);

        if ($amount < $min) {
            json_response(['success' => false, 'message' => t('min_amount') . ': $' . number_format($min, 2)], 400);
        }

        if ($amount > $max) {
            json_response(['success' => false, 'message' => t('max_amount') . ': $' . number_format($max, 2)], 400);
        }

        if ($amount > $user['balance']) {
            json_response(['success' => false, 'message' => t('insufficient_balance')], 400);
        }

        if (empty($account_details)) {
            json_response(['success' => false, 'message' => t('account_details')], 400);
        }

        // Calculate fee
        $fee = ($amount * $gateway['fee_percentage']) / 100;
        $net_amount = $amount - $fee;

        try {
            $pdo->beginTransaction();

            // Deduct balance immediately
            $stmt = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
            $stmt->execute([$amount, $user_id]);

            // Insert transaction
            $stmt = $pdo->prepare("
                INSERT INTO transactions (
                    user_id, type, amount, currency, status,
                    payment_gateway, gateway_id, user_account_details,
                    fee_amount, net_amount, ip_address
                ) VALUES (?, 'withdrawal', ?, 'USD', 'pending', ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $user_id,
                $amount,
                $gateway['name'],
                $gateway_id,
                $account_details,
                $fee,
                $net_amount,
                get_client_ip()
            ]);

            $transaction_id = $pdo->lastInsertId();

            $pdo->commit();

            // Create notification for user
            create_notification(
                $pdo,
                $user_id,
                'transaction',
                'Withdrawal Request',
                'طلب سحب',
                "Your withdrawal request of \$$amount has been submitted successfully. You will receive \$$net_amount after fees.",
                "تم إرسال طلب سحب بقيمة \$$amount بنجاح. ستستلم \$$net_amount بعد خصم الرسوم.",
                'p2p-withdrawal.php'
            );

            // Log activity
            log_activity($pdo, 'withdrawal_request', "Withdrawal request: \$$amount via {$gateway['name']}", $user_id);

            json_response([
                'success' => true,
                'message' => t('request_submitted'),
                'transaction_id' => $transaction_id
            ]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Withdrawal request error: " . $e->getMessage());
            json_response(['success' => false, 'message' => t('error')], 500);
        }
    }

    exit;
}

// Get active payment gateways
$stmt = $pdo->query("
    SELECT * FROM payment_gateways
    WHERE is_active = 1
    ORDER BY sort_order ASC, id ASC
");
$payment_gateways = $stmt->fetchAll();

// Get user's withdrawal history
$stmt = $pdo->prepare("
    SELECT t.*, pg.display_name_ar, pg.display_name_en
    FROM transactions t
    LEFT JOIN payment_gateways pg ON t.gateway_id = pg.id
    WHERE t.user_id = ? AND t.type = 'withdrawal'
    ORDER BY t.created_at DESC
    LIMIT 20
");
$stmt->execute([$user_id]);
$withdrawal_history = $stmt->fetchAll();

// Get pending count
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM transactions
    WHERE user_id = ? AND type = 'withdrawal' AND status = 'pending'
");
$stmt->execute([$user_id]);
$pending_count = $stmt->fetchColumn();

// Prepare gateway data for JS
$gateways_js = [];
foreach ($payment_gateways as $gw) {
    $gateways_js[] = [
        'id'          => (int)$gw['id'],
        'name'        => $lang === 'ar' ? ($gw['display_name_ar'] ?? $gw['name']) : ($gw['display_name_en'] ?? $gw['name']),
        'type'        => $gw['type'] ?? 'bank_transfer',
        'min'         => floatval($gw['min_withdrawal'] ?? $gw['min_deposit'] ?? 10),
        'max'         => floatval($gw['max_withdrawal'] ?? $gw['max_deposit'] ?? 100000),
        'feePercent'  => floatval($gw['fee_percentage'] ?? 0),
    ];
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= get_dir() ?>">
<head>
<?php render_head(t('p2p_withdrawal')); ?>
<?php render_base_css(); ?>
<style>
    /* ===== Withdrawal Grid ===== */
    .withdrawal-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
        margin-bottom: 24px;
    }

    /* ===== Gateway Cards ===== */
    .gateway-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .gateway-card {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 18px 20px;
        background: var(--glass);
        border: 2px solid var(--border);
        border-radius: var(--radius);
        cursor: pointer;
        transition: all var(--transition);
        position: relative;
    }

    .gateway-card:hover {
        background: rgba(245, 158, 11, 0.04);
        border-color: rgba(245, 158, 11, 0.3);
    }

    .gateway-card.active {
        border-color: var(--accent);
        background: rgba(245, 158, 11, 0.08);
        box-shadow: 0 0 24px rgba(245, 158, 11, 0.15);
    }

    .gateway-card-icon {
        width: 52px;
        height: 52px;
        background: linear-gradient(135deg, var(--accent), #DC2626);
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
        color: white;
        flex-shrink: 0;
    }

    .gateway-card-info {
        flex: 1;
        min-width: 0;
    }

    .gateway-card-name {
        font-size: 16px;
        font-weight: 700;
        margin-bottom: 4px;
    }

    .gateway-card-limits {
        font-size: 12px;
        color: var(--text-muted);
        margin-bottom: 2px;
    }

    .gateway-card-fee {
        font-size: 12px;
        color: var(--accent);
        font-weight: 600;
    }

    .gateway-card-check {
        width: 26px;
        height: 26px;
        border: 2px solid var(--border);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 13px;
        color: transparent;
        transition: all var(--transition);
        flex-shrink: 0;
    }

    .gateway-card.active .gateway-card-check {
        background: var(--accent);
        border-color: var(--accent);
        color: #000;
    }

    /* ===== Form Styles ===== */
    .quick-amounts {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 10px;
        margin-top: 10px;
    }

    .quick-amount-btn {
        padding: 12px 8px;
        background: var(--glass);
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        color: var(--text);
        font-weight: 700;
        font-size: 14px;
        cursor: pointer;
        transition: all var(--transition);
        text-align: center;
        font-family: var(--font);
    }

    .quick-amount-btn:hover {
        background: rgba(245, 158, 11, 0.1);
        border-color: var(--accent);
        color: var(--accent);
    }

    /* ===== Summary Box ===== */
    .summary-box {
        background: var(--glass);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 20px;
        margin-bottom: 20px;
    }

    .summary-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 0;
        font-size: 14px;
    }

    .summary-row:not(:last-child) {
        border-bottom: 1px solid var(--border);
    }

    .summary-row:last-child {
        padding-top: 14px;
        font-size: 17px;
        font-weight: 900;
    }

    .summary-label {
        color: var(--text-muted);
    }

    .summary-value {
        font-weight: 700;
        font-family: 'Poppins', sans-serif;
    }

    .summary-value.green {
        color: var(--success);
    }

    .summary-value.red {
        color: var(--danger);
    }

    /* ===== Submit Button ===== */
    .btn-withdraw {
        width: 100%;
        padding: 16px;
        background: linear-gradient(135deg, var(--accent), #DC2626);
        border: none;
        border-radius: var(--radius);
        color: white;
        font-size: 16px;
        font-weight: 900;
        cursor: pointer;
        transition: all var(--transition);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        box-shadow: 0 8px 32px rgba(245, 158, 11, 0.3);
        font-family: var(--font);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        min-height: 56px;
    }

    .btn-withdraw:hover:not(:disabled) {
        transform: translateY(-2px);
        box-shadow: 0 12px 40px rgba(245, 158, 11, 0.45);
    }

    .btn-withdraw:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        transform: none;
    }

    /* ===== Balance Info ===== */
    .balance-info {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 16px 20px;
        background: rgba(16, 185, 129, 0.06);
        border: 1px solid rgba(16, 185, 129, 0.15);
        border-radius: var(--radius);
        margin-bottom: 20px;
    }

    .balance-info-label {
        font-size: 14px;
        color: var(--text-muted);
        font-weight: 600;
    }

    .balance-info-value {
        font-size: 22px;
        font-weight: 900;
        color: var(--success);
        font-family: 'Poppins', sans-serif;
    }

    /* ===== Pending Alert ===== */
    .pending-alert {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 14px 20px;
        background: rgba(245, 158, 11, 0.08);
        border: 1px solid rgba(245, 158, 11, 0.2);
        border-radius: var(--radius);
        margin-bottom: 20px;
        font-size: 14px;
        color: var(--accent);
        font-weight: 600;
    }

    .pending-alert i {
        font-size: 18px;
    }

    /* ===== Responsive ===== */
    @media (max-width: 1024px) {
        .withdrawal-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .quick-amounts {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 480px) {
        .balance-info {
            flex-direction: column;
            text-align: center;
            gap: 6px;
        }
    }
</style>
</head>
<body>
<?php render_sidebar('p2p-withdrawal', $user, $unread_count); ?>

<main class="main">
    <!-- Header -->
    <div class="header">
        <div class="header-top">
            <h1 class="page-title"><i class="fas fa-money-bill-transfer" style="color: var(--accent);"></i> <?= t('p2p_withdrawal') ?></h1>
            <div class="header-actions">
                <a href="dashboard.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-<?= is_rtl() ? 'right' : 'left' ?>"></i>
                    <?= t('back_to_dashboard') ?>
                </a>
            </div>
        </div>
    </div>

    <?php if ($pending_count > 0): ?>
        <div class="pending-alert">
            <i class="fas fa-clock"></i>
            <?= t('pending_requests') ?>: <?= $pending_count ?>
        </div>
    <?php endif; ?>

    <!-- Withdrawal Grid -->
    <div class="withdrawal-grid">
        <!-- Left: Gateway Selection -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-university"></i>
                    <?= t('select_gateway') ?>
                </div>
            </div>

            <div class="gateway-list">
                <?php if (empty($payment_gateways)): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fas fa-ban"></i></div>
                        <p><?= t('no_data') ?></p>
                    </div>
                <?php else: ?>
                    <?php foreach ($payment_gateways as $index => $gateway):
                        $gw_name = $lang === 'ar'
                            ? ($gateway['display_name_ar'] ?? $gateway['name'])
                            : ($gateway['display_name_en'] ?? $gateway['name']);
                        $gw_min = floatval($gateway['min_withdrawal'] ?? $gateway['min_deposit'] ?? 10);
                        $gw_max = floatval($gateway['max_withdrawal'] ?? $gateway['max_deposit'] ?? 100000);
                        $gw_fee = floatval($gateway['fee_percentage'] ?? 0);
                        $gw_type = $gateway['type'] ?? 'bank_transfer';

                        $type_icons = [
                            'bank_transfer' => 'fa-university',
                            'crypto'        => 'fa-bitcoin',
                            'e_wallet'      => 'fa-wallet',
                            'card'          => 'fa-credit-card',
                            'mobile'        => 'fa-mobile-alt',
                        ];
                        $icon_class = $type_icons[$gw_type] ?? 'fa-money-bill-wave';
                    ?>
                        <div class="gateway-card <?= $index === 0 ? 'active' : '' ?>"
                             data-id="<?= $gateway['id'] ?>"
                             onclick="selectGateway(this, <?= $index ?>)">
                            <div class="gateway-card-icon">
                                <i class="fas <?= $icon_class ?>"></i>
                            </div>
                            <div class="gateway-card-info">
                                <div class="gateway-card-name"><?= htmlspecialchars($gw_name) ?></div>
                                <div class="gateway-card-limits">
                                    <?= t('min_amount') ?>: $<?= number_format($gw_min, 2) ?>
                                    &mdash;
                                    <?= t('max_amount') ?>: $<?= number_format($gw_max, 2) ?>
                                </div>
                                <?php if ($gw_fee > 0): ?>
                                    <div class="gateway-card-fee">
                                        <?= t('processing_fee') ?>: <?= $gw_fee ?>%
                                    </div>
                                <?php else: ?>
                                    <div class="gateway-card-fee" style="color: var(--success);">
                                        <?= t('processing_fee') ?>: 0%
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="gateway-card-check">
                                <i class="fas fa-check"></i>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right: Withdrawal Form -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <?= t('withdrawal_amount') ?>
                </div>
            </div>

            <!-- Balance display -->
            <div class="balance-info">
                <span class="balance-info-label"><?= t('available_balance') ?></span>
                <span class="balance-info-value" id="currentBalance">$<?= number_format($user['balance'], 2) ?></span>
            </div>

            <form id="withdrawalForm" autocomplete="off">
                <input type="hidden" name="action" value="submit_withdrawal">
                <input type="hidden" name="gateway_id" id="gatewayId" value="<?= $payment_gateways[0]['id'] ?? '' ?>">

                <!-- Amount -->
                <div class="form-group">
                    <label><?= t('amount') ?> (USD)</label>
                    <input type="number" name="amount" id="withdrawAmount" class="form-control"
                           placeholder="0.00" min="1" step="0.01" required>
                    <div class="quick-amounts">
                        <button type="button" class="quick-amount-btn" onclick="setAmount(50)">$50</button>
                        <button type="button" class="quick-amount-btn" onclick="setAmount(100)">$100</button>
                        <button type="button" class="quick-amount-btn" onclick="setAmount(500)">$500</button>
                        <button type="button" class="quick-amount-btn" onclick="setAmount(1000)">$1,000</button>
                    </div>
                </div>

                <!-- Account Details -->
                <div class="form-group">
                    <label><?= t('account_details') ?></label>
                    <textarea name="account_details" id="accountDetails" class="form-control"
                              placeholder="<?= $lang === 'ar' ? 'ادخل تفاصيل حسابك (رقم الحساب البنكي، عنوان المحفظة، رقم الهاتف...)' : 'Enter your account details (bank account number, wallet address, phone number...)' ?>"
                              required></textarea>
                </div>

                <!-- Summary -->
                <div class="summary-box">
                    <div class="summary-row">
                        <span class="summary-label"><?= t('amount') ?>:</span>
                        <span class="summary-value" id="summaryAmount">$0.00</span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label"><?= t('processing_fee') ?>:</span>
                        <span class="summary-value red" id="summaryFee">$0.00</span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label"><?= t('you_will_receive') ?>:</span>
                        <span class="summary-value green" id="summaryNet">$0.00</span>
                    </div>
                </div>

                <!-- Submit -->
                <button type="submit" class="btn-withdraw" id="submitBtn">
                    <i class="fas fa-paper-plane"></i>
                    <span id="btnText"><?= t('submit_request') ?></span>
                    <span id="btnLoading" style="display:none;"><?= t('loading') ?></span>
                </button>
            </form>
        </div>
    </div>

    <!-- Withdrawal History -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-history"></i>
                <?= t('withdrawal_history') ?>
            </div>
        </div>

        <?php if (empty($withdrawal_history)): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-receipt"></i></div>
                <p><?= t('no_data') ?></p>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?= t('payment_gateway') ?></th>
                            <th><?= t('amount') ?></th>
                            <th><?= t('fee') ?></th>
                            <th><?= t('you_will_receive') ?></th>
                            <th><?= t('status') ?></th>
                            <th><?= t('date') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($withdrawal_history as $row):
                            $gw_display = $lang === 'ar'
                                ? ($row['display_name_ar'] ?? $row['payment_gateway'] ?? '-')
                                : ($row['display_name_en'] ?? $row['payment_gateway'] ?? '-');

                            $status_class = $row['status'];
                            if ($row['status'] === 'rejected' || $row['status'] === 'failed') {
                                $status_class = 'danger';
                            } elseif ($row['status'] === 'completed' || $row['status'] === 'approved') {
                                $status_class = 'success';
                            } elseif ($row['status'] === 'pending') {
                                $status_class = 'pending';
                            }

                            $row_fee = floatval($row['fee_amount'] ?? 0);
                            $row_net = floatval($row['net_amount'] ?? ($row['amount'] - $row_fee));
                        ?>
                            <tr>
                                <td style="color: var(--text-muted); font-family: monospace;"><?= $row['id'] ?></td>
                                <td><?= htmlspecialchars($gw_display) ?></td>
                                <td style="font-weight: 700;">$<?= number_format($row['amount'], 2) ?></td>
                                <td style="color: var(--danger);">$<?= number_format($row_fee, 2) ?></td>
                                <td style="font-weight: 700; color: var(--success);">$<?= number_format($row_net, 2) ?></td>
                                <td>
                                    <span class="badge <?= $status_class ?>">
                                        <?= t($row['status']) ?>
                                    </span>
                                </td>
                                <td style="color: var(--text-muted); font-size: 13px; white-space: nowrap;">
                                    <?= date('Y-m-d H:i', strtotime($row['created_at'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php render_base_js(); ?>

<script>
// Gateway data from PHP
const gateways = <?= json_encode($gateways_js, JSON_UNESCAPED_UNICODE) ?>;
const userBalance = <?= floatval($user['balance']) ?>;
let selectedIndex = 0;

// Translations from PHP
const LANG = {
    insufficient_balance: <?= json_encode(t('insufficient_balance')) ?>,
    amount_out_of_range: <?= json_encode(t('amount_out_of_range')) ?>,
    request_submitted: <?= json_encode(t('request_submitted')) ?>,
    select_gateway: <?= json_encode(t('select_gateway')) ?>,
    account_details: <?= json_encode(t('account_details')) ?>,
    error: <?= json_encode(t('error')) ?>
};

function selectGateway(el, index) {
    document.querySelectorAll('.gateway-card').forEach(c => c.classList.remove('active'));
    el.classList.add('active');
    selectedIndex = index;
    document.getElementById('gatewayId').value = gateways[index].id;
    updateSummary();
}

function setAmount(val) {
    document.getElementById('withdrawAmount').value = val;
    updateSummary();
}

function updateSummary() {
    const gw = gateways[selectedIndex];
    if (!gw) return;
    const amount = parseFloat(document.getElementById('withdrawAmount').value) || 0;
    const fee = (amount * gw.feePercent) / 100;
    const net = amount - fee;

    document.getElementById('summaryAmount').textContent = '$' + amount.toFixed(2);
    document.getElementById('summaryFee').textContent = '$' + fee.toFixed(2);
    document.getElementById('summaryNet').textContent = '$' + (net > 0 ? net.toFixed(2) : '0.00');
}

// Listen for amount input
document.getElementById('withdrawAmount').addEventListener('input', updateSummary);

// Form submission
document.getElementById('withdrawalForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const gw = gateways[selectedIndex];
    if (!gw) {
        showToast(LANG.select_gateway, 'error');
        return;
    }

    const amount = parseFloat(document.getElementById('withdrawAmount').value) || 0;
    const details = document.getElementById('accountDetails').value.trim();

    if (amount <= 0 || amount < gw.min || amount > gw.max) {
        showToast(LANG.amount_out_of_range, 'error');
        return;
    }

    if (amount > userBalance) {
        showToast(LANG.insufficient_balance, 'error');
        return;
    }

    if (!details) {
        showToast(LANG.account_details, 'error');
        return;
    }

    const btn = document.getElementById('submitBtn');
    const btnText = document.getElementById('btnText');
    const btnLoading = document.getElementById('btnLoading');

    btn.disabled = true;
    btnText.style.display = 'none';
    btnLoading.style.display = 'inline';

    try {
        const formData = new FormData(this);
        const response = await fetch('p2p-withdrawal.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.success) {
            showToast(result.message, 'success');
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showToast(result.message || LANG.error, 'error');
            btn.disabled = false;
            btnText.style.display = 'inline';
            btnLoading.style.display = 'none';
        }
    } catch (err) {
        showToast(LANG.error, 'error');
        btn.disabled = false;
        btnText.style.display = 'inline';
        btnLoading.style.display = 'none';
    }
});

// Init
updateSummary();
</script>
</body>
</html>
