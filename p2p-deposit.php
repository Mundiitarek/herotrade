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

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['payment_proof'])) {
    $upload_dir = __DIR__ . '/uploads/payment_proofs/';

    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $file = $_FILES['payment_proof'];
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $max_size = 5 * 1024 * 1024; // 5MB

    if ($file['error'] === UPLOAD_ERR_OK) {
        if (!in_array($file['type'], $allowed_types)) {
            json_response(['success' => false, 'message' => t('invalid_file_type', 'Invalid file type. Only images allowed.')], 400);
        }

        if ($file['size'] > $max_size) {
            json_response(['success' => false, 'message' => t('file_too_large', 'File too large. Maximum 5MB.')], 400);
        }

        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'proof_' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $filepath = $upload_dir . $filename;

        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            json_response(['success' => true, 'filename' => $filename, 'path' => 'uploads/payment_proofs/' . $filename]);
        } else {
            json_response(['success' => false, 'message' => t('upload_failed', 'File upload failed')], 500);
        }
    } else {
        json_response(['success' => false, 'message' => t('upload_error', 'Upload error')], 400);
    }
    exit;
}

// Handle deposit submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_deposit') {
    header('Content-Type: application/json');

    $gateway_id = intval($_POST['gateway_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $payment_proof = sanitize($_POST['payment_proof'] ?? '');
    $reference_number = sanitize($_POST['reference_number'] ?? '');
    $payment_notes = sanitize($_POST['payment_notes'] ?? '');

    // Get gateway
    try {
        $stmt = $pdo->prepare("SELECT * FROM payment_gateways WHERE id = ? AND is_active = 1");
        $stmt->execute([$gateway_id]);
        $gateway = $stmt->fetch();

        if (!$gateway) {
            json_response(['success' => false, 'message' => t('invalid_gateway', 'Invalid payment gateway')], 400);
        }

        // Validation
        if ($amount < $gateway['min_deposit']) {
            json_response(['success' => false, 'message' => t('min_deposit_error', 'Minimum deposit') . ': $' . number_format($gateway['min_deposit'], 2)], 400);
        }

        if ($amount > $gateway['max_deposit']) {
            json_response(['success' => false, 'message' => t('max_deposit_error', 'Maximum deposit') . ': $' . number_format($gateway['max_deposit'], 2)], 400);
        }

        if (empty($payment_proof) && empty($reference_number)) {
            json_response(['success' => false, 'message' => t('proof_required', 'Payment proof or reference number is required')], 400);
        }

        // Calculate fee
        $fee = ($amount * $gateway['fee_percentage'] / 100) + $gateway['fee_fixed'];
        $net_amount = $amount - $fee;

        // Create transaction
        $pdo->beginTransaction();

        $reference_id = 'DEP_' . time() . '_' . $user_id . '_' . bin2hex(random_bytes(4));

        $payment_details = json_encode([
            'proof' => $payment_proof,
            'reference_number' => $reference_number,
            'notes' => $payment_notes,
            'fee' => $fee,
            'gateway_name' => $gateway['name'],
            'gateway_type' => $gateway['type']
        ], JSON_UNESCAPED_UNICODE);

        $stmt = $pdo->prepare("
            INSERT INTO transactions (
                user_id, type, method, amount, fee, net_amount,
                status, reference_id, payment_gateway, payment_details, ip_address
            ) VALUES (?, 'deposit', 'p2p', ?, ?, ?, 'pending', ?, ?, ?, ?)
        ");

        $stmt->execute([
            $user_id,
            $amount,
            $fee,
            $net_amount,
            $reference_id,
            $gateway['name'],
            $payment_details,
            get_client_ip()
        ]);

        $transaction_id = $pdo->lastInsertId();

        log_activity($pdo, 'deposit_request', "Deposit request: $$amount via {$gateway['name']}", $user_id);

        // Create notification
        create_notification(
            $pdo,
            $user_id,
            'deposit',
            'Deposit Request Submitted',
            'تم إرسال طلب الإيداع',
            "Your deposit request of $$amount has been submitted and is pending review.",
            "تم إرسال طلب إيداع بقيمة $$amount وهو قيد المراجعة.",
            'transactions.php'
        );

        $pdo->commit();

        json_response([
            'success' => true,
            'message' => t('deposit_submitted', 'Deposit request submitted successfully. It will be reviewed within 24 hours.'),
            'transaction_id' => $transaction_id,
            'reference_id' => $reference_id
        ]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Deposit request error: " . $e->getMessage());
        json_response(['success' => false, 'message' => t('error_occurred', 'An error occurred')], 500);
    }

    exit;
}

// Get payment gateways
try {
    $stmt = $pdo->query("
        SELECT * FROM payment_gateways
        WHERE is_active = 1
        ORDER BY sort_order ASC, id ASC
    ");
    $payment_gateways = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Get payment gateways error: " . $e->getMessage());
    $payment_gateways = [];
}

// Get user's deposit history
try {
    $stmt = $pdo->prepare("
        SELECT * FROM transactions
        WHERE user_id = ? AND type = 'deposit'
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$user_id]);
    $deposit_history = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Get deposit history error: " . $e->getMessage());
    $deposit_history = [];
}

// Get unread notifications count
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    $unread_count = $stmt->fetch()['count'] ?? 0;
} catch (PDOException $e) {
    $unread_count = 0;
}

$lang = get_current_lang();
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" dir="<?php echo get_dir(); ?>">
<head>
    <?php render_head(t('add_balance')); ?>
    <?php render_base_css(); ?>
    <style>
        /* P2P Deposit specific styles */
        .deposit-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }

        .step-indicator {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            overflow-x: auto;
            padding-bottom: 8px;
        }

        .step {
            flex: 1;
            min-width: 120px;
            padding: 12px;
            background: var(--secondary);
            border: 2px solid var(--border);
            border-radius: var(--radius-sm);
            text-align: center;
            transition: all var(--transition);
            cursor: pointer;
            position: relative;
        }

        .step.active {
            border-color: var(--accent);
            background: rgba(249, 158, 11, 0.1);
        }

        .step.completed {
            border-color: var(--success);
            background: rgba(16, 185, 129, 0.1);
        }

        .step-number {
            width: 32px;
            height: 32px;
            background: var(--primary);
            border: 2px solid var(--border);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 8px;
            font-weight: 700;
            transition: all var(--transition);
        }

        .step.active .step-number {
            background: var(--accent);
            border-color: var(--accent);
            color: #000;
        }

        .step.completed .step-number {
            background: var(--success);
            border-color: var(--success);
            color: white;
        }

        .step-title {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
        }

        .step.active .step-title,
        .step.completed .step-title {
            color: var(--text);
        }

        .step-content {
            display: none;
        }

        .step-content.active {
            display: block;
            animation: fadeIn 0.3s;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .gateway-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
        }

        .gateway-card {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px;
            background: var(--secondary);
            border: 2px solid var(--border);
            border-radius: var(--radius);
            cursor: pointer;
            transition: all var(--transition);
        }

        .gateway-card:hover {
            background: rgba(249, 158, 11, 0.05);
            transform: translateY(-2px);
        }

        .gateway-card.selected {
            border-color: var(--accent);
            background: rgba(249, 158, 11, 0.1);
        }

        .gateway-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--accent), #DC2626);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
        }

        .gateway-info {
            flex: 1;
        }

        .gateway-name {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .gateway-limits {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 4px;
        }

        .gateway-fee {
            font-size: 11px;
            color: var(--accent);
            font-weight: 600;
        }

        .gateway-radio {
            width: 24px;
            height: 24px;
            border: 2px solid var(--border);
            border-radius: 50%;
            position: relative;
            flex-shrink: 0;
        }

        .gateway-card.selected .gateway-radio {
            border-color: var(--accent);
        }

        .gateway-card.selected .gateway-radio::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 12px;
            height: 12px;
            background: var(--accent);
            border-radius: 50%;
        }

        .amount-presets {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-top: 12px;
        }

        .amount-preset {
            padding: 12px;
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            text-align: center;
            font-weight: 700;
            cursor: pointer;
            transition: all var(--transition);
            font-family: 'Poppins', sans-serif;
        }

        .amount-preset:hover {
            background: var(--accent);
            color: #000;
            border-color: var(--accent);
        }

        .info-box {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 20px;
        }

        .info-box-title {
            font-weight: 700;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-box-content {
            font-size: 14px;
            line-height: 1.6;
            color: var(--text-muted);
        }

        .summary-box {
            background: rgba(249, 158, 11, 0.05);
            border: 1px solid rgba(249, 158, 11, 0.2);
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 20px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 14px;
        }

        .summary-row:not(:last-child) {
            border-bottom: 1px solid var(--border);
        }

        .summary-row:last-child {
            font-size: 18px;
            font-weight: 900;
            padding-top: 12px;
        }

        .summary-label {
            color: var(--text-muted);
        }

        .summary-value {
            font-weight: 700;
            font-family: 'Poppins', sans-serif;
        }

        .summary-value.success {
            color: var(--success);
        }

        .upload-area {
            border: 2px dashed var(--border);
            border-radius: var(--radius);
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all var(--transition);
            margin-bottom: 16px;
        }

        .upload-area:hover {
            border-color: var(--accent);
            background: rgba(249, 158, 11, 0.05);
        }

        .upload-area.dragover {
            border-color: var(--accent);
            background: rgba(249, 158, 11, 0.1);
        }

        .upload-icon {
            font-size: 48px;
            color: var(--text-muted);
            margin-bottom: 12px;
        }

        .upload-text {
            color: var(--text-muted);
            font-size: 14px;
        }

        .uploaded-preview {
            display: none;
            margin-top: 12px;
            text-align: center;
        }

        .uploaded-preview img {
            max-width: 100%;
            max-height: 200px;
            border-radius: var(--radius);
            border: 1px solid var(--border);
        }

        .confirmation-box {
            background: rgba(16, 185, 129, 0.1);
            border: 2px solid var(--success);
            border-radius: var(--radius);
            padding: 30px;
            text-align: center;
        }

        .confirmation-icon {
            font-size: 64px;
            color: var(--success);
            margin-bottom: 16px;
        }

        .confirmation-title {
            font-size: 24px;
            font-weight: 900;
            margin-bottom: 12px;
        }

        .confirmation-text {
            color: var(--text-muted);
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .confirmation-details {
            background: rgba(0,0,0,0.2);
            border-radius: var(--radius-sm);
            padding: 16px;
            margin: 20px 0;
        }

        .confirmation-details-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 14px;
        }

        @media (max-width: 1024px) {
            .deposit-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .amount-presets {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <?php render_sidebar('p2p-deposit', $user, $unread_count); ?>

    <main class="main">
        <div class="header">
            <div class="header-top">
                <h1 class="page-title"><?php echo t('add_balance'); ?></h1>
            </div>
        </div>

        <!-- Step Indicator -->
        <div class="step-indicator">
            <div class="step active" data-step="1">
                <div class="step-number">1</div>
                <div class="step-title"><?php echo t('select_gateway', 'Select Gateway'); ?></div>
            </div>
            <div class="step" data-step="2">
                <div class="step-number">2</div>
                <div class="step-title"><?php echo t('enter_amount', 'Enter Amount'); ?></div>
            </div>
            <div class="step" data-step="3">
                <div class="step-number">3</div>
                <div class="step-title"><?php echo t('upload_proof', 'Upload Proof'); ?></div>
            </div>
            <div class="step" data-step="4">
                <div class="step-number">4</div>
                <div class="step-title"><?php echo t('confirm', 'Confirm'); ?></div>
            </div>
        </div>

        <div class="deposit-grid">
            <div>
                <!-- Step 1: Select Gateway -->
                <div class="step-content active" id="step1">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-credit-card"></i>
                                <?php echo t('select_payment_method', 'Select Payment Method'); ?>
                            </h3>
                        </div>

                        <?php if (empty($payment_gateways)): ?>
                            <div class="empty-state">
                                <div class="empty-icon"><i class="fas fa-credit-card"></i></div>
                                <p><?php echo t('no_gateways', 'No payment gateways available'); ?></p>
                            </div>
                        <?php else: ?>
                            <div class="gateway-grid">
                                <?php foreach ($payment_gateways as $index => $gateway): ?>
                                    <div class="gateway-card <?php echo $index === 0 ? 'selected' : ''; ?>"
                                         data-gateway-id="<?php echo $gateway['id']; ?>"
                                         data-gateway-name="<?php echo $lang === 'ar' ? ($gateway['display_name_ar'] ?? $gateway['name']) : ($gateway['display_name_en'] ?? $gateway['name']); ?>"
                                         data-min="<?php echo $gateway['min_deposit']; ?>"
                                         data-max="<?php echo $gateway['max_deposit']; ?>"
                                         data-fee-percent="<?php echo $gateway['fee_percentage']; ?>"
                                         data-fee-fixed="<?php echo $gateway['fee_fixed']; ?>"
                                         data-instructions="<?php echo htmlspecialchars($lang === 'ar' ? ($gateway['instructions_ar'] ?? '') : ($gateway['instructions_en'] ?? '')); ?>"
                                         onclick="selectGateway(this)">
                                        <div class="gateway-icon">
                                            <i class="fas fa-<?php
                                            echo match($gateway['type']) {
                                                'bank_transfer' => 'university',
                                                'crypto' => 'bitcoin',
                                                'e_wallet' => 'wallet',
                                                'card' => 'credit-card',
                                                default => 'money-bill'
                                            };
                                            ?>"></i>
                                        </div>
                                        <div class="gateway-info">
                                            <div class="gateway-name">
                                                <?php echo htmlspecialchars($lang === 'ar' ? ($gateway['display_name_ar'] ?? $gateway['name']) : ($gateway['display_name_en'] ?? $gateway['name'])); ?>
                                            </div>
                                            <div class="gateway-limits">
                                                <?php echo t('min', 'Min'); ?>: $<?php echo number_format($gateway['min_deposit'], 2); ?> -
                                                <?php echo t('max', 'Max'); ?>: $<?php echo number_format($gateway['max_deposit'], 2); ?>
                                            </div>
                                            <?php if ($gateway['fee_percentage'] > 0 || $gateway['fee_fixed'] > 0): ?>
                                                <div class="gateway-fee">
                                                    <?php echo t('fee', 'Fee'); ?>:
                                                    <?php if ($gateway['fee_percentage'] > 0): ?>
                                                        <?php echo $gateway['fee_percentage']; ?>%
                                                    <?php endif; ?>
                                                    <?php if ($gateway['fee_fixed'] > 0): ?>
                                                        <?php echo $gateway['fee_percentage'] > 0 ? '+' : ''; ?> $<?php echo number_format($gateway['fee_fixed'], 2); ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="gateway-fee"><?php echo t('no_fees', 'No Fees'); ?> ✓</div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="gateway-radio"></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <button class="btn btn-primary" style="width: 100%; margin-top: 20px;" onclick="goToStep(2)">
                                <?php echo t('next'); ?>
                                <i class="fas fa-arrow-<?php echo is_rtl() ? 'left' : 'right'; ?>"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Step 2: Enter Amount -->
                <div class="step-content" id="step2">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-dollar-sign"></i>
                                <?php echo t('enter_amount', 'Enter Amount'); ?>
                            </h3>
                        </div>

                        <div id="gatewayInstructions" class="info-box" style="display: none;">
                            <div class="info-box-title">
                                <i class="fas fa-info-circle"></i>
                                <?php echo t('payment_instructions', 'Payment Instructions'); ?>
                            </div>
                            <div class="info-box-content" id="instructionsText"></div>
                        </div>

                        <div class="form-group">
                            <label><?php echo t('amount'); ?> (USD)</label>
                            <input type="number" id="depositAmount" class="form-control"
                                   placeholder="0.00" min="10" step="0.01" onchange="updateSummary()">
                        </div>

                        <div class="amount-presets">
                            <div class="amount-preset" onclick="setAmount(50)">$50</div>
                            <div class="amount-preset" onclick="setAmount(100)">$100</div>
                            <div class="amount-preset" onclick="setAmount(500)">$500</div>
                            <div class="amount-preset" onclick="setAmount(1000)">$1000</div>
                        </div>

                        <div class="summary-box" id="summaryBox" style="margin-top: 20px; display: none;">
                            <div class="summary-row">
                                <span class="summary-label"><?php echo t('amount'); ?>:</span>
                                <span class="summary-value" id="summaryAmount">$0.00</span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label"><?php echo t('fee'); ?>:</span>
                                <span class="summary-value" id="summaryFee">$0.00</span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label"><?php echo t('you_will_receive', 'You Will Receive'); ?>:</span>
                                <span class="summary-value success" id="summaryNet">$0.00</span>
                            </div>
                        </div>

                        <div style="display: flex; gap: 12px; margin-top: 20px;">
                            <button class="btn btn-secondary" style="flex: 1;" onclick="goToStep(1)">
                                <i class="fas fa-arrow-<?php echo is_rtl() ? 'right' : 'left'; ?>"></i>
                                <?php echo t('back'); ?>
                            </button>
                            <button class="btn btn-primary" style="flex: 1;" onclick="goToStep(3)">
                                <?php echo t('next'); ?>
                                <i class="fas fa-arrow-<?php echo is_rtl() ? 'left' : 'right'; ?>"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Upload Proof -->
                <div class="step-content" id="step3">
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-upload"></i>
                                <?php echo t('upload_payment_proof', 'Upload Payment Proof'); ?>
                            </h3>
                        </div>

                        <div class="info-box">
                            <div class="info-box-title">
                                <i class="fas fa-info-circle"></i>
                                <?php echo t('important', 'Important'); ?>
                            </div>
                            <div class="info-box-content">
                                <?php echo t('upload_proof_instructions', 'Please upload a screenshot or photo of your payment proof, or enter the transaction reference number below.'); ?>
                            </div>
                        </div>

                        <div class="upload-area" id="uploadArea">
                            <input type="file" id="fileInput" accept="image/*" style="display: none;" onchange="handleFileSelect(event)">
                            <div class="upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                            <div class="upload-text">
                                <strong><?php echo t('click_to_upload', 'Click to upload'); ?></strong>
                                <?php echo t('or_drag_drop', 'or drag and drop'); ?><br>
                                <small><?php echo t('image_formats', 'PNG, JPG, GIF up to 5MB'); ?></small>
                            </div>
                        </div>

                        <div class="uploaded-preview" id="uploadedPreview">
                            <img id="previewImage" src="" alt="Preview">
                            <button class="btn btn-danger btn-sm" style="margin-top: 12px;" onclick="removeUpload()">
                                <i class="fas fa-times"></i> <?php echo t('remove'); ?>
                            </button>
                        </div>

                        <div style="text-align: center; margin: 20px 0; color: var(--text-muted);">
                            <?php echo t('or', 'OR'); ?>
                        </div>

                        <div class="form-group">
                            <label><?php echo t('reference_number', 'Transaction Reference Number'); ?></label>
                            <input type="text" id="referenceNumber" class="form-control"
                                   placeholder="<?php echo t('enter_reference', 'Enter transaction reference number'); ?>">
                        </div>

                        <div class="form-group">
                            <label><?php echo t('additional_notes', 'Additional Notes'); ?> (<?php echo t('optional', 'Optional'); ?>)</label>
                            <textarea id="paymentNotes" class="form-control" rows="3"
                                      placeholder="<?php echo t('notes_placeholder', 'Any additional information...'); ?>"></textarea>
                        </div>

                        <div style="display: flex; gap: 12px; margin-top: 20px;">
                            <button class="btn btn-secondary" style="flex: 1;" onclick="goToStep(2)">
                                <i class="fas fa-arrow-<?php echo is_rtl() ? 'right' : 'left'; ?>"></i>
                                <?php echo t('back'); ?>
                            </button>
                            <button class="btn btn-primary" style="flex: 1;" onclick="submitDeposit()">
                                <?php echo t('submit'); ?>
                                <i class="fas fa-check"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Step 4: Confirmation -->
                <div class="step-content" id="step4">
                    <div class="card">
                        <div class="confirmation-box">
                            <div class="confirmation-icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <h2 class="confirmation-title"><?php echo t('deposit_submitted', 'Deposit Submitted'); ?></h2>
                            <p class="confirmation-text">
                                <?php echo t('deposit_confirmation_text', 'Your deposit request has been submitted successfully and is pending review. You will be notified once it is processed.'); ?>
                            </p>

                            <div class="confirmation-details">
                                <div class="confirmation-details-row">
                                    <span><?php echo t('reference_id', 'Reference ID'); ?>:</span>
                                    <strong id="confirmReferenceId">-</strong>
                                </div>
                                <div class="confirmation-details-row">
                                    <span><?php echo t('amount'); ?>:</span>
                                    <strong id="confirmAmount">-</strong>
                                </div>
                                <div class="confirmation-details-row">
                                    <span><?php echo t('status'); ?>:</span>
                                    <span class="badge pending"><?php echo t('pending'); ?></span>
                                </div>
                            </div>

                            <div style="display: flex; gap: 12px; justify-content: center;">
                                <a href="transactions.php" class="btn btn-primary">
                                    <i class="fas fa-receipt"></i>
                                    <?php echo t('view_transactions', 'View Transactions'); ?>
                                </a>
                                <button class="btn btn-secondary" onclick="location.reload()">
                                    <i class="fas fa-plus"></i>
                                    <?php echo t('new_deposit', 'New Deposit'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sidebar Info -->
            <div>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-info-circle"></i>
                            <?php echo t('deposit_info', 'Deposit Information'); ?>
                        </h3>
                    </div>

                    <div style="font-size: 14px; line-height: 1.8; color: var(--text-muted);">
                        <p><i class="fas fa-check" style="color: var(--success);"></i> <?php echo t('deposit_info_1', 'Deposits are processed within 24 hours'); ?></p>
                        <p><i class="fas fa-check" style="color: var(--success);"></i> <?php echo t('deposit_info_2', 'Upload clear payment proof'); ?></p>
                        <p><i class="fas fa-check" style="color: var(--success);"></i> <?php echo t('deposit_info_3', 'Include transaction reference'); ?></p>
                        <p><i class="fas fa-check" style="color: var(--success);"></i> <?php echo t('deposit_info_4', 'You will be notified once approved'); ?></p>
                    </div>

                    <div class="info-box" style="margin-top: 20px;">
                        <div class="info-box-title">
                            <i class="fas fa-shield-alt"></i>
                            <?php echo t('secure_payment', 'Secure Payment'); ?>
                        </div>
                        <div class="info-box-content">
                            <?php echo t('secure_payment_text', 'All payments are encrypted and secure. Your financial information is protected.'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Deposit History -->
        <?php if (!empty($deposit_history)): ?>
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-history"></i>
                        <?php echo t('deposit_history', 'Deposit History'); ?>
                    </h3>
                    <a href="transactions.php?type=deposit" class="card-link">
                        <?php echo t('view_all'); ?>
                        <i class="fas fa-arrow-<?php echo is_rtl() ? 'left' : 'right'; ?>"></i>
                    </a>
                </div>

                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th><?php echo t('reference_id', 'Reference ID'); ?></th>
                                <th><?php echo t('method', 'Method'); ?></th>
                                <th><?php echo t('amount'); ?></th>
                                <th><?php echo t('fee'); ?></th>
                                <th><?php echo t('status'); ?></th>
                                <th><?php echo t('date'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deposit_history as $deposit): ?>
                                <tr>
                                    <td><code style="font-size: 11px;"><?php echo htmlspecialchars($deposit['reference_id']); ?></code></td>
                                    <td><?php echo htmlspecialchars($deposit['payment_gateway']); ?></td>
                                    <td style="font-weight: 700; color: var(--success); font-family: 'Poppins', sans-serif;">
                                        $<?php echo number_format($deposit['amount'], 2); ?>
                                    </td>
                                    <td style="font-family: 'Poppins', sans-serif;">
                                        $<?php echo number_format($deposit['fee'] ?? 0, 2); ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $deposit['status']; ?>">
                                            <?php echo t($deposit['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($deposit['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <?php render_base_js(); ?>
    <script>
        let currentStep = 1;
        let selectedGateway = {
            id: <?php echo !empty($payment_gateways) ? $payment_gateways[0]['id'] : 0; ?>,
            name: '<?php echo !empty($payment_gateways) ? htmlspecialchars($payment_gateways[0]['name']) : ''; ?>',
            min: <?php echo !empty($payment_gateways) ? $payment_gateways[0]['min_deposit'] : 10; ?>,
            max: <?php echo !empty($payment_gateways) ? $payment_gateways[0]['max_deposit'] : 10000; ?>,
            feePercent: <?php echo !empty($payment_gateways) ? $payment_gateways[0]['fee_percentage'] : 0; ?>,
            feeFixed: <?php echo !empty($payment_gateways) ? $payment_gateways[0]['fee_fixed'] : 0; ?>
        };
        let uploadedFile = null;

        function selectGateway(element) {
            document.querySelectorAll('.gateway-card').forEach(card => card.classList.remove('selected'));
            element.classList.add('selected');

            selectedGateway = {
                id: parseInt(element.dataset.gatewayId),
                name: element.dataset.gatewayName,
                min: parseFloat(element.dataset.min),
                max: parseFloat(element.dataset.max),
                feePercent: parseFloat(element.dataset.feePercent),
                feeFixed: parseFloat(element.dataset.feeFixed)
            };

            const instructions = element.dataset.instructions;
            if (instructions && instructions.trim()) {
                document.getElementById('gatewayInstructions').style.display = 'block';
                document.getElementById('instructionsText').innerHTML = instructions.replace(/\n/g, '<br>');
            } else {
                document.getElementById('gatewayInstructions').style.display = 'none';
            }

            updateSummary();
        }

        function goToStep(step) {
            if (step === 2 && !selectedGateway.id) {
                showToast('<?php echo t('select_gateway_first', 'Please select a payment gateway'); ?>', 'warning');
                return;
            }

            if (step === 3) {
                const amount = parseFloat(document.getElementById('depositAmount').value);
                if (!amount || amount < selectedGateway.min || amount > selectedGateway.max) {
                    showToast('<?php echo t('enter_valid_amount', 'Please enter a valid amount'); ?>', 'warning');
                    return;
                }
            }

            document.querySelectorAll('.step-content').forEach(content => content.classList.remove('active'));
            document.getElementById('step' + step).classList.add('active');

            document.querySelectorAll('.step').forEach((stepEl, index) => {
                stepEl.classList.remove('active', 'completed');
                if (index + 1 < step) {
                    stepEl.classList.add('completed');
                } else if (index + 1 === step) {
                    stepEl.classList.add('active');
                }
            });

            currentStep = step;
        }

        function setAmount(amount) {
            document.getElementById('depositAmount').value = amount;
            updateSummary();
        }

        function updateSummary() {
            const amount = parseFloat(document.getElementById('depositAmount').value) || 0;

            if (amount > 0) {
                const fee = (amount * selectedGateway.feePercent / 100) + selectedGateway.feeFixed;
                const net = amount - fee;

                document.getElementById('summaryAmount').textContent = '$' + amount.toFixed(2);
                document.getElementById('summaryFee').textContent = '$' + fee.toFixed(2);
                document.getElementById('summaryNet').textContent = '$' + net.toFixed(2);
                document.getElementById('summaryBox').style.display = 'block';
            } else {
                document.getElementById('summaryBox').style.display = 'none';
            }
        }

        // Upload handling
        const uploadArea = document.getElementById('uploadArea');
        const fileInput = document.getElementById('fileInput');

        uploadArea.addEventListener('click', () => fileInput.click());

        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.classList.add('dragover');
        });

        uploadArea.addEventListener('dragleave', () => {
            uploadArea.classList.remove('dragover');
        });

        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('dragover');

            const file = e.dataTransfer.files[0];
            if (file && file.type.startsWith('image/')) {
                uploadFile(file);
            }
        });

        function handleFileSelect(event) {
            const file = event.target.files[0];
            if (file) {
                uploadFile(file);
            }
        }

        async function uploadFile(file) {
            const formData = new FormData();
            formData.append('payment_proof', file);

            try {
                const response = await fetch('p2p-deposit.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    uploadedFile = result.filename;
                    document.getElementById('previewImage').src = result.path;
                    document.getElementById('uploadedPreview').style.display = 'block';
                    uploadArea.style.display = 'none';
                    showToast('<?php echo t('file_uploaded', 'File uploaded successfully'); ?>', 'success');
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                showToast('<?php echo t('upload_failed', 'Upload failed'); ?>', 'error');
            }
        }

        function removeUpload() {
            uploadedFile = null;
            document.getElementById('uploadedPreview').style.display = 'none';
            uploadArea.style.display = 'block';
            fileInput.value = '';
        }

        async function submitDeposit() {
            const amount = parseFloat(document.getElementById('depositAmount').value);
            const reference = document.getElementById('referenceNumber').value.trim();
            const notes = document.getElementById('paymentNotes').value.trim();

            if (!amount || amount < selectedGateway.min || amount > selectedGateway.max) {
                showToast('<?php echo t('enter_valid_amount', 'Please enter a valid amount'); ?>', 'warning');
                return;
            }

            if (!uploadedFile && !reference) {
                showToast('<?php echo t('proof_or_reference_required', 'Please upload payment proof or enter reference number'); ?>', 'warning');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'submit_deposit');
            formData.append('gateway_id', selectedGateway.id);
            formData.append('amount', amount);
            formData.append('payment_proof', uploadedFile || '');
            formData.append('reference_number', reference);
            formData.append('payment_notes', notes);

            try {
                const response = await fetch('p2p-deposit.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    document.getElementById('confirmReferenceId').textContent = result.reference_id;
                    document.getElementById('confirmAmount').textContent = '$' + amount.toFixed(2);
                    goToStep(4);
                    showToast(result.message, 'success');
                } else {
                    showToast(result.message, 'error');
                }
            } catch (error) {
                showToast('<?php echo t('error_occurred', 'An error occurred'); ?>', 'error');
            }
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            const firstGateway = document.querySelector('.gateway-card');
            if (firstGateway) {
                selectGateway(firstGateway);
            }
            updateSummary();
        });
    </script>
</body>
</html>
