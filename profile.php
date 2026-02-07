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

$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Update Personal Info
    if (isset($_POST['update_profile'])) {
        $full_name = sanitize($_POST['full_name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');

        if (empty($email)) {
            $error_message = t('email_required');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_message = t('invalid_email');
        } else {
            // Check if email already exists for other users
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $user_id]);

            if ($stmt->fetch()) {
                $error_message = t('email_already_exists');
            } else {
                try {
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET email = ?, full_name = ?, phone = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$email, $full_name, $phone, $user_id]);

                    $success_message = t('profile_updated_successfully');
                    $user = get_user_data($pdo, $user_id); // Refresh user data

                    // Log activity
                    log_activity($pdo, $user_id, 'profile_update', t('profile_update_activity'));

                } catch (PDOException $e) {
                    $error_message = t('error_updating_profile');
                    error_log("Profile update error: " . $e->getMessage());
                }
            }
        }
    }

    // Change Password
    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error_message = t('all_password_fields_required');
        } elseif ($new_password !== $confirm_password) {
            $error_message = t('passwords_do_not_match');
        } elseif (strlen($new_password) < 8) {
            $error_message = t('password_min_length');
        } elseif (!password_verify($current_password, $user['password'])) {
            $error_message = t('current_password_incorrect');
        } else {
            try {
                $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$hashed_password, $user_id]);

                $success_message = t('password_changed_successfully');

                // Log activity
                log_activity($pdo, $user_id, 'password_change', t('password_change_activity'));

            } catch (PDOException $e) {
                $error_message = t('error_changing_password');
                error_log("Password change error: " . $e->getMessage());
            }
        }
    }

    // Toggle 2FA
    if (isset($_POST['toggle_2fa'])) {
        $enable_2fa = isset($_POST['enable_2fa']) ? 1 : 0;

        try {
            $stmt = $pdo->prepare("UPDATE users SET two_factor_enabled = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$enable_2fa, $user_id]);

            $success_message = $enable_2fa ? t('2fa_enabled') : t('2fa_disabled');
            $user = get_user_data($pdo, $user_id);

            // Log activity
            log_activity($pdo, $user_id, '2fa_toggle', $enable_2fa ? t('2fa_enabled_activity') : t('2fa_disabled_activity'));

        } catch (PDOException $e) {
            $error_message = t('error_updating_2fa');
            error_log("2FA toggle error: " . $e->getMessage());
        }
    }

    // Upload KYC Document
    if (isset($_POST['upload_kyc'])) {
        if (isset($_FILES['kyc_document']) && $_FILES['kyc_document']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
            $max_size = 5 * 1024 * 1024; // 5MB

            $file_type = $_FILES['kyc_document']['type'];
            $file_size = $_FILES['kyc_document']['size'];

            if (!in_array($file_type, $allowed_types)) {
                $error_message = t('invalid_file_type');
            } elseif ($file_size > $max_size) {
                $error_message = t('file_too_large');
            } else {
                $upload_dir = 'uploads/kyc/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                $file_extension = pathinfo($_FILES['kyc_document']['name'], PATHINFO_EXTENSION);
                $new_filename = 'kyc_' . $user_id . '_' . time() . '.' . $file_extension;
                $upload_path = $upload_dir . $new_filename;

                if (move_uploaded_file($_FILES['kyc_document']['tmp_name'], $upload_path)) {
                    try {
                        $stmt = $pdo->prepare("
                            UPDATE users
                            SET kyc_document = ?, kyc_status = 'pending', kyc_submitted_at = NOW(), updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([$upload_path, $user_id]);

                        $success_message = t('kyc_submitted_successfully');
                        $user = get_user_data($pdo, $user_id);

                        // Log activity
                        log_activity($pdo, $user_id, 'kyc_submit', t('kyc_submit_activity'));

                    } catch (PDOException $e) {
                        $error_message = t('error_submitting_kyc');
                        error_log("KYC submit error: " . $e->getMessage());
                        unlink($upload_path);
                    }
                } else {
                    $error_message = t('error_uploading_file');
                }
            }
        } else {
            $error_message = t('no_file_selected');
        }
    }
}

// Get user data
$kyc_status = $user['kyc_status'] ?? 'not_submitted';
$kyc_document = $user['kyc_document'] ?? '';
$two_factor_enabled = $user['two_factor_enabled'] ?? 0;

$notifications = get_user_notifications($pdo, $user_id, false, 5);
$unread_count = count(get_user_notifications($pdo, $user_id, true));

// Get user initials for avatar
$initials = '';
if (!empty($user['full_name'])) {
    $name_parts = explode(' ', trim($user['full_name']));
    $initials = strtoupper(substr($name_parts[0], 0, 1));
    if (isset($name_parts[1])) {
        $initials .= strtoupper(substr($name_parts[1], 0, 1));
    }
} elseif (!empty($user['username'])) {
    $initials = strtoupper(substr($user['username'], 0, 2));
} else {
    $initials = 'U';
}
?>
<!DOCTYPE html>
<html lang="<?= $_SESSION['language'] ?? 'ar' ?>" dir="<?= ($_SESSION['language'] ?? 'ar') === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
    <?php render_head(t('profile')); ?>
    <?php render_base_css(); ?>
    <style>
        .main {
            <?= ($_SESSION['language'] ?? 'ar') === 'ar' ? 'margin-right' : 'margin-left' ?>: 280px;
            padding: 30px;
            min-height: 100vh;
        }

        .page-header {
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

        /* Toast Notifications */
        .toast {
            position: fixed;
            top: 20px;
            <?= ($_SESSION['language'] ?? 'ar') === 'ar' ? 'right' : 'left' ?>: 50%;
            transform: translateX(<?= ($_SESSION['language'] ?? 'ar') === 'ar' ? '' : '-' ?>50%);
            padding: 16px 24px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            z-index: 10000;
            animation: slideDown 0.3s ease-out;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            min-width: 300px;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateX(<?= ($_SESSION['language'] ?? 'ar') === 'ar' ? '' : '-' ?>50%) translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(<?= ($_SESSION['language'] ?? 'ar') === 'ar' ? '' : '-' ?>50%) translateY(0);
            }
        }

        .toast-success {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid var(--success);
            color: var(--success);
        }

        .toast-error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid var(--danger);
            color: var(--danger);
        }

        /* Profile Grid */
        .profile-grid {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 24px;
            margin-bottom: 32px;
        }

        /* Profile Card */
        .profile-card {
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 32px;
            text-align: center;
            height: fit-content;
        }

        .profile-avatar-large {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, var(--accent), #DC2626);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            font-weight: 900;
            color: white;
            margin: 0 auto 24px;
            box-shadow: 0 8px 24px rgba(249, 158, 11, 0.3);
        }

        .profile-name {
            font-size: 24px;
            font-weight: 900;
            margin-bottom: 4px;
            color: var(--text);
        }

        .profile-email {
            color: var(--text-muted);
            margin-bottom: 8px;
            font-size: 14px;
        }

        .profile-join-date {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 24px;
        }

        .profile-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            padding-top: 24px;
            border-top: 1px solid var(--border);
        }

        .profile-stat {
            text-align: center;
        }

        .profile-stat-value {
            font-size: 24px;
            font-weight: 900;
            color: var(--accent);
        }

        .profile-stat-label {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
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
            padding: 12px 24px;
            background: none;
            border: none;
            color: var(--text-muted);
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            font-family: inherit;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tab-btn:hover {
            color: var(--text);
        }

        .tab-btn.active {
            color: var(--accent);
            border-bottom-color: var(--accent);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Form Sections */
        .form-section {
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
        }

        .form-section-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: var(--text);
        }

        .form-section-title i {
            color: var(--accent);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
        }

        .form-input,
        .form-select {
            padding: 12px 16px;
            background: var(--primary);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            font-size: 14px;
            font-family: inherit;
            transition: all 0.3s;
        }

        .form-input:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(249, 158, 11, 0.1);
        }

        .form-checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            background: var(--primary);
            border: 1px solid var(--border);
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .form-checkbox-wrapper:hover {
            border-color: var(--accent);
        }

        .form-checkbox-wrapper input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
            accent-color: var(--accent);
        }

        .form-checkbox-label {
            flex: 1;
            font-size: 14px;
            color: var(--text);
        }

        .form-checkbox-description {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s;
            font-family: inherit;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent), #DC2626);
            color: white;
            box-shadow: 0 4px 16px rgba(249, 158, 11, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 24px rgba(249, 158, 11, 0.4);
        }

        .btn-secondary {
            background: var(--secondary);
            color: var(--text);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        /* KYC Status Badge */
        .kyc-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 16px;
        }

        .kyc-status.pending {
            background: rgba(249, 158, 11, 0.1);
            color: var(--accent);
        }

        .kyc-status.approved {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .kyc-status.rejected {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .kyc-status.not_submitted {
            background: rgba(148, 163, 184, 0.1);
            color: var(--text-muted);
        }

        .file-upload-area {
            border: 2px dashed var(--border);
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            transition: all 0.3s;
            background: var(--primary);
        }

        .file-upload-area:hover {
            border-color: var(--accent);
            background: rgba(249, 158, 11, 0.02);
        }

        .file-upload-icon {
            font-size: 48px;
            color: var(--accent);
            margin-bottom: 16px;
        }

        .file-upload-text {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .file-upload-hint {
            font-size: 12px;
            color: var(--text-muted);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main {
                margin-<?= ($_SESSION['language'] ?? 'ar') === 'ar' ? 'right' : 'left' ?>: 0;
                padding: 20px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .page-title {
                font-size: 24px;
            }

            .tabs {
                gap: 4px;
            }

            .tab-btn {
                padding: 10px 16px;
                font-size: 13px;
            }
        }
    </style>
</head>
<body>
    <?php render_sidebar('profile', $user, $unread_count); ?>

    <!-- Main Content -->
    <main class="main">
        <div class="page-header">
            <h1 class="page-title"><?= t('profile') ?></h1>
            <div class="breadcrumb">
                <a href="dashboard.php"><?= t('home') ?></a>
                <i class="fas fa-chevron-<?= ($_SESSION['language'] ?? 'ar') === 'ar' ? 'left' : 'right' ?>" style="font-size: 10px;"></i>
                <span><?= t('profile') ?></span>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="toast toast-success" id="successToast">
                <i class="fas fa-check-circle"></i>
                <span><?= htmlspecialchars($success_message) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="toast toast-error" id="errorToast">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($error_message) ?></span>
            </div>
        <?php endif; ?>

        <div class="profile-grid">
            <!-- Left - Profile Card -->
            <div class="profile-card">
                <div class="profile-avatar-large">
                    <?= htmlspecialchars($initials) ?>
                </div>
                <h2 class="profile-name"><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></h2>
                <p class="profile-email"><?= htmlspecialchars($user['email']) ?></p>
                <p class="profile-join-date">
                    <i class="fas fa-calendar-alt"></i>
                    <?= t('member_since') ?>: <?= date('d M Y', strtotime($user['created_at'])) ?>
                </p>

                <div class="profile-stats">
                    <div class="profile-stat">
                        <div class="profile-stat-value">$<?= number_format($user['balance'], 2) ?></div>
                        <div class="profile-stat-label"><?= t('balance') ?></div>
                    </div>
                    <div class="profile-stat">
                        <div class="profile-stat-value"><?= $kyc_status === 'approved' ? '✓' : '✗' ?></div>
                        <div class="profile-stat-label"><?= t('kyc_verified') ?></div>
                    </div>
                </div>
            </div>

            <!-- Right - Settings Tabs -->
            <div>
                <div class="tabs">
                    <button class="tab-btn active" onclick="switchTab('personal')">
                        <i class="fas fa-user"></i>
                        <span><?= t('personal_info') ?></span>
                    </button>
                    <button class="tab-btn" onclick="switchTab('security')">
                        <i class="fas fa-lock"></i>
                        <span><?= t('security') ?></span>
                    </button>
                    <button class="tab-btn" onclick="switchTab('kyc')">
                        <i class="fas fa-id-card"></i>
                        <span><?= t('kyc_verification') ?></span>
                    </button>
                </div>

                <!-- Personal Info Tab -->
                <div id="tab-personal" class="tab-content active">
                    <form method="POST" class="form-section">
                        <h3 class="form-section-title">
                            <i class="fas fa-user-edit"></i>
                            <?= t('edit_personal_info') ?>
                        </h3>

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label"><?= t('full_name') ?></label>
                                <input type="text" name="full_name" class="form-input"
                                    value="<?= htmlspecialchars($user['full_name'] ?? '') ?>"
                                    placeholder="<?= t('enter_full_name') ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label"><?= t('email') ?> *</label>
                                <input type="email" name="email" class="form-input"
                                    value="<?= htmlspecialchars($user['email']) ?>"
                                    required
                                    placeholder="<?= t('enter_email') ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label"><?= t('phone') ?></label>
                                <input type="tel" name="phone" class="form-input"
                                    value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
                                    placeholder="<?= t('enter_phone') ?>">
                            </div>
                        </div>

                        <div style="margin-top: 24px;">
                            <button type="submit" name="update_profile" class="btn btn-primary">
                                <i class="fas fa-save"></i>
                                <?= t('save_changes') ?>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Security Tab -->
                <div id="tab-security" class="tab-content">
                    <form method="POST" class="form-section">
                        <h3 class="form-section-title">
                            <i class="fas fa-key"></i>
                            <?= t('change_password') ?>
                        </h3>

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label"><?= t('current_password') ?> *</label>
                                <input type="password" name="current_password" class="form-input"
                                    required
                                    placeholder="<?= t('enter_current_password') ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label"><?= t('new_password') ?> *</label>
                                <input type="password" name="new_password" class="form-input"
                                    minlength="8"
                                    required
                                    placeholder="<?= t('enter_new_password') ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label"><?= t('confirm_password') ?> *</label>
                                <input type="password" name="confirm_password" class="form-input"
                                    minlength="8"
                                    required
                                    placeholder="<?= t('confirm_new_password') ?>">
                            </div>
                        </div>

                        <div style="margin-top: 24px;">
                            <button type="submit" name="change_password" class="btn btn-primary">
                                <i class="fas fa-shield-alt"></i>
                                <?= t('update_password') ?>
                            </button>
                        </div>
                    </form>

                    <form method="POST" class="form-section">
                        <h3 class="form-section-title">
                            <i class="fas fa-mobile-alt"></i>
                            <?= t('two_factor_authentication') ?>
                        </h3>

                        <p style="color: var(--text-muted); margin-bottom: 20px; font-size: 14px;">
                            <?= t('2fa_description') ?>
                        </p>

                        <div class="form-checkbox-wrapper">
                            <input type="checkbox" name="enable_2fa" id="enable_2fa"
                                <?= $two_factor_enabled ? 'checked' : '' ?>>
                            <label for="enable_2fa" class="form-checkbox-label">
                                <?= t('enable_2fa') ?>
                                <div class="form-checkbox-description">
                                    <?= t('2fa_security_note') ?>
                                </div>
                            </label>
                        </div>

                        <div style="margin-top: 24px;">
                            <button type="submit" name="toggle_2fa" class="btn btn-primary">
                                <i class="fas fa-save"></i>
                                <?= t('save_changes') ?>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- KYC Tab -->
                <div id="tab-kyc" class="tab-content">
                    <div class="form-section">
                        <h3 class="form-section-title">
                            <i class="fas fa-id-card"></i>
                            <?= t('kyc_verification') ?>
                        </h3>

                        <div style="margin-bottom: 24px;">
                            <div class="kyc-status <?= $kyc_status ?>">
                                <i class="fas fa-<?=
                                    $kyc_status === 'approved' ? 'check-circle' :
                                    ($kyc_status === 'pending' ? 'clock' :
                                    ($kyc_status === 'rejected' ? 'times-circle' : 'info-circle'))
                                ?>"></i>
                                <span>
                                    <?= t('kyc_status') ?>:
                                    <?php
                                    switch($kyc_status) {
                                        case 'approved':
                                            echo t('kyc_approved');
                                            break;
                                        case 'pending':
                                            echo t('kyc_pending');
                                            break;
                                        case 'rejected':
                                            echo t('kyc_rejected');
                                            break;
                                        default:
                                            echo t('kyc_not_submitted');
                                    }
                                    ?>
                                </span>
                            </div>
                        </div>

                        <?php if ($kyc_status === 'approved'): ?>
                            <div style="padding: 20px; background: rgba(16, 185, 129, 0.05); border: 1px solid rgba(16, 185, 129, 0.1); border-radius: 12px;">
                                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                                    <i class="fas fa-check-circle" style="font-size: 24px; color: var(--success);"></i>
                                    <div>
                                        <div style="font-weight: 700; color: var(--success);"><?= t('kyc_verified_title') ?></div>
                                        <div style="font-size: 13px; color: var(--text-muted);"><?= t('kyc_verified_message') ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php elseif ($kyc_status === 'pending'): ?>
                            <div style="padding: 20px; background: rgba(249, 158, 11, 0.05); border: 1px solid rgba(249, 158, 11, 0.1); border-radius: 12px;">
                                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                                    <i class="fas fa-clock" style="font-size: 24px; color: var(--accent);"></i>
                                    <div>
                                        <div style="font-weight: 700; color: var(--accent);"><?= t('kyc_under_review') ?></div>
                                        <div style="font-size: 13px; color: var(--text-muted);"><?= t('kyc_review_message') ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php elseif ($kyc_status === 'rejected'): ?>
                            <div style="padding: 20px; background: rgba(239, 68, 68, 0.05); border: 1px solid rgba(239, 68, 68, 0.1); border-radius: 12px; margin-bottom: 24px;">
                                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                                    <i class="fas fa-times-circle" style="font-size: 24px; color: var(--danger);"></i>
                                    <div>
                                        <div style="font-weight: 700; color: var(--danger);"><?= t('kyc_rejected_title') ?></div>
                                        <div style="font-size: 13px; color: var(--text-muted);"><?= t('kyc_rejected_message') ?></div>
                                    </div>
                                </div>
                            </div>

                            <form method="POST" enctype="multipart/form-data">
                                <div class="form-group">
                                    <label class="form-label"><?= t('upload_new_document') ?></label>
                                    <div class="file-upload-area" onclick="document.getElementById('kyc_document').click()">
                                        <i class="fas fa-cloud-upload-alt file-upload-icon"></i>
                                        <div class="file-upload-text"><?= t('click_to_upload') ?></div>
                                        <div class="file-upload-hint"><?= t('supported_formats') ?>: JPG, PNG, PDF (<?= t('max_size') ?>: 5MB)</div>
                                        <input type="file" name="kyc_document" id="kyc_document"
                                            accept="image/jpeg,image/png,image/jpg,application/pdf"
                                            style="display: none;"
                                            onchange="updateFileName(this)">
                                    </div>
                                    <div id="file_name" style="margin-top: 8px; font-size: 13px; color: var(--text-muted);"></div>
                                </div>

                                <div style="margin-top: 24px;">
                                    <button type="submit" name="upload_kyc" class="btn btn-primary">
                                        <i class="fas fa-upload"></i>
                                        <?= t('resubmit_document') ?>
                                    </button>
                                </div>
                            </form>
                        <?php else: ?>
                            <p style="color: var(--text-muted); margin-bottom: 24px; font-size: 14px;">
                                <?= t('kyc_upload_instructions') ?>
                            </p>

                            <form method="POST" enctype="multipart/form-data">
                                <div class="form-group">
                                    <label class="form-label"><?= t('upload_id_document') ?> *</label>
                                    <div class="file-upload-area" onclick="document.getElementById('kyc_document').click()">
                                        <i class="fas fa-cloud-upload-alt file-upload-icon"></i>
                                        <div class="file-upload-text"><?= t('click_to_upload') ?></div>
                                        <div class="file-upload-hint"><?= t('supported_formats') ?>: JPG, PNG, PDF (<?= t('max_size') ?>: 5MB)</div>
                                        <input type="file" name="kyc_document" id="kyc_document"
                                            accept="image/jpeg,image/png,image/jpg,application/pdf"
                                            style="display: none;"
                                            onchange="updateFileName(this)"
                                            required>
                                    </div>
                                    <div id="file_name" style="margin-top: 8px; font-size: 13px; color: var(--text-muted);"></div>
                                </div>

                                <div style="margin-top: 24px;">
                                    <button type="submit" name="upload_kyc" class="btn btn-primary">
                                        <i class="fas fa-upload"></i>
                                        <?= t('submit_for_verification') ?>
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php render_base_js(); ?>
    <script>
        function switchTab(tabName) {
            // Remove active class from all tabs
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });

            // Add active class to selected tab
            event.target.closest('.tab-btn').classList.add('active');
            document.getElementById('tab-' + tabName).classList.add('active');
        }

        function updateFileName(input) {
            const fileNameDiv = document.getElementById('file_name');
            if (input.files && input.files[0]) {
                const fileName = input.files[0].name;
                const fileSize = (input.files[0].size / 1024 / 1024).toFixed(2);
                fileNameDiv.innerHTML = `<i class="fas fa-file"></i> ${fileName} (${fileSize} MB)`;
                fileNameDiv.style.color = 'var(--accent)';
            }
        }

        // Auto-hide toast notifications after 5 seconds
        setTimeout(() => {
            const toasts = document.querySelectorAll('.toast');
            toasts.forEach(toast => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(<?= ($_SESSION['language'] ?? 'ar') === 'ar' ? '' : '-' ?>50%) translateY(-20px)';
                setTimeout(() => toast.remove(), 300);
            });
        }, 5000);
    </script>
</body>
</html>
