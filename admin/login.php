<?php
define('APP_ACCESS', true);
require_once '../config.php';
require_once '../functions.php';
require_once 'functions.php';

// Handle language switching
if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'])) {
    $_SESSION['language'] = $_GET['lang'];
    setcookie('lang', $_GET['lang'], time() + 86400 * 365, '/');
    header('Location: login.php');
    exit;
}

// Redirect if already logged in
if (isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error_message = t('all_fields_required', get_current_lang() === 'ar' ? 'جميع الحقول مطلوبة' : 'All fields are required');
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ? AND is_active = 1");
            $stmt->execute([$username]);
            $admin = $stmt->fetch();

            if ($admin && password_verify($password, $admin['password'])) {
                // Successful login
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['admin_role'] = $admin['role'];

                // Update last login
                $stmt = $pdo->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?");
                $stmt->execute([$admin['id']]);

                // Log activity
                log_admin_activity($pdo, $admin['id'], 'login', 'تسجيل دخول الأدمن');

                header('Location: index.php');
                exit;
            } else {
                $error_message = t('invalid_credentials', get_current_lang() === 'ar' ? 'اسم المستخدم أو كلمة المرور غير صحيحة' : 'Invalid username or password');

                // Log failed attempt
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent)
                        VALUES (NULL, 'admin_login_failed', ?, ?, ?)
                    ");
                    $stmt->execute([
                        "محاولة تسجيل دخول فاشلة: $username",
                        $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                        $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                    ]);
                } catch (PDOException $e) {
                    error_log("Failed login log error: " . $e->getMessage());
                }
            }
        } catch (PDOException $e) {
            error_log("Admin login error: " . $e->getMessage());
            $error_message = t('general_error', get_current_lang() === 'ar' ? 'حدث خطأ، يرجى المحاولة مرة أخرى' : 'An error occurred, please try again');
        }
    }
}

$lang = get_current_lang();
$dir = get_dir();
$is_rtl = is_rtl();
$other_lang = $lang === 'ar' ? 'en' : 'ar';
$other_lang_label = $lang === 'ar' ? 'EN' : 'AR';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title><?= t('login', 'Login') ?> - HeroTrade <?= t('admin_panel', 'Admin Panel') ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * {
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
            --text: #F8FAFC;
            --text-muted: #94A3B8;
            --border: rgba(148, 163, 184, 0.1);
        }

        body {
            font-family: <?= $is_rtl ? "'Tajawal'" : "'Poppins', 'Tajawal'" ?>, sans-serif;
            background: var(--primary);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            direction: <?= $dir ?>;
            position: relative;
            overflow: hidden;
        }

        /* Subtle gradient background */
        .bg-gradient {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            background:
                radial-gradient(ellipse at 20% 20%, rgba(245, 158, 11, 0.08) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 80%, rgba(220, 38, 38, 0.06) 0%, transparent 50%),
                radial-gradient(ellipse at 50% 50%, rgba(30, 41, 59, 0.5) 0%, transparent 70%);
            pointer-events: none;
        }

        .bg-gradient::before,
        .bg-gradient::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            opacity: 0.07;
            animation: floatBlob 20s infinite ease-in-out;
        }

        .bg-gradient::before {
            width: 600px;
            height: 600px;
            background: linear-gradient(135deg, #DC2626, var(--accent));
            top: -200px;
            <?= $is_rtl ? 'left' : 'right' ?>: -200px;
        }

        .bg-gradient::after {
            width: 500px;
            height: 500px;
            background: linear-gradient(135deg, var(--accent), #DC2626);
            bottom: -200px;
            <?= $is_rtl ? 'right' : 'left' ?>: -200px;
            animation-delay: 10s;
        }

        @keyframes floatBlob {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(60px, -60px) scale(1.1); }
            66% { transform: translate(-40px, 40px) scale(0.95); }
        }

        /* Language Switcher */
        .lang-switcher {
            position: fixed;
            top: 20px;
            <?= $is_rtl ? 'left' : 'right' ?>: 20px;
            z-index: 100;
        }

        .lang-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            font-family: inherit;
            transition: all 0.3s;
            letter-spacing: 0.5px;
        }

        .lang-btn:hover {
            color: var(--accent);
            border-color: rgba(245, 158, 11, 0.3);
            background: rgba(245, 158, 11, 0.05);
        }

        .lang-btn i {
            font-size: 14px;
        }

        /* Login Container */
        .login-container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 440px;
            padding: 20px;
        }

        /* Login Card */
        .login-card {
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 40px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
        }

        /* Logo */
        .logo {
            text-align: center;
            margin-bottom: 36px;
        }

        .logo-icon {
            width: 72px;
            height: 72px;
            background: linear-gradient(135deg, #DC2626, var(--accent));
            border-radius: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: white;
            margin-bottom: 16px;
            box-shadow: 0 12px 32px rgba(220, 38, 38, 0.35);
        }

        .logo-text {
            font-size: 26px;
            font-weight: 900;
            background: linear-gradient(135deg, #DC2626, var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 6px;
        }

        .logo-subtitle {
            font-size: 13px;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        /* Welcome */
        .welcome-text {
            text-align: center;
            margin-bottom: 28px;
        }

        .welcome-title {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .welcome-subtitle {
            font-size: 14px;
            color: var(--text-muted);
        }

        /* Toast Alert */
        .toast-alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.25);
            color: var(--danger);
            font-size: 14px;
            font-weight: 500;
            animation: slideIn 0.4s ease-out, shake 0.5s ease-in-out;
            transition: opacity 0.3s, transform 0.3s;
        }

        .toast-alert i {
            font-size: 18px;
            flex-shrink: 0;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20% { transform: translateX(-6px); }
            40% { transform: translateX(6px); }
            60% { transform: translateX(-4px); }
            80% { transform: translateX(4px); }
        }

        /* Form */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            <?= $is_rtl ? 'right' : 'left' ?>: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 16px;
            pointer-events: none;
            transition: color 0.3s;
        }

        .form-input {
            width: 100%;
            min-height: 48px;
            padding: 12px 16px;
            padding-<?= $is_rtl ? 'right' : 'left' ?>: 46px;
            background: var(--primary);
            border: 2px solid var(--border);
            border-radius: 8px;
            color: var(--text);
            font-size: 16px;
            font-family: inherit;
            transition: all 0.3s;
            -webkit-appearance: none;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.1);
        }

        .form-input:focus ~ .input-icon {
            color: var(--accent);
        }

        .form-input::placeholder {
            color: var(--text-muted);
            opacity: 0.7;
        }

        /* Password Toggle */
        .password-toggle {
            position: absolute;
            <?= $is_rtl ? 'left' : 'right' ?>: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 16px;
            padding: 4px;
            transition: color 0.3s;
            line-height: 1;
        }

        .password-toggle:hover {
            color: var(--accent);
        }

        /* Remember Me */
        .form-options {
            display: flex;
            align-items: center;
            margin-bottom: 24px;
        }

        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .checkbox-wrapper input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--accent);
        }

        .checkbox-wrapper label {
            font-size: 14px;
            color: var(--text-muted);
            cursor: pointer;
            user-select: none;
        }

        /* Submit Button */
        .btn-submit {
            width: 100%;
            min-height: 48px;
            padding: 14px 24px;
            background: linear-gradient(135deg, #DC2626, var(--accent));
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 8px 24px rgba(220, 38, 38, 0.3);
            font-family: inherit;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(220, 38, 38, 0.4);
        }

        .btn-submit:active {
            transform: translateY(0);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }

        /* Loading State */
        .btn-submit.loading {
            position: relative;
            color: transparent;
            pointer-events: none;
        }

        .btn-submit.loading::after {
            content: '';
            position: absolute;
            width: 22px;
            height: 22px;
            top: 50%;
            left: 50%;
            margin-left: -11px;
            margin-top: -11px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Security Badge */
        .security-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px;
            background: rgba(16, 185, 129, 0.08);
            border: 1px solid rgba(16, 185, 129, 0.15);
            border-radius: 8px;
            margin-top: 24px;
        }

        .security-badge i {
            color: var(--success);
            font-size: 14px;
        }

        .security-badge span {
            font-size: 12px;
            color: var(--success);
            font-weight: 600;
        }

        /* Footer */
        .login-footer {
            text-align: center;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
        }

        .footer-link {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 14px;
            transition: color 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .footer-link:hover {
            color: var(--accent);
        }

        /* Responsive */
        @media (max-width: 480px) {
            .login-card {
                padding: 28px 20px;
                border-radius: 12px;
            }

            .logo-icon {
                width: 60px;
                height: 60px;
                font-size: 26px;
                border-radius: 14px;
            }

            .logo-text {
                font-size: 22px;
            }

            .logo-subtitle {
                font-size: 11px;
                letter-spacing: 1.5px;
            }

            .welcome-title {
                font-size: 18px;
            }

            .welcome-subtitle {
                font-size: 13px;
            }

            .login-container {
                padding: 12px;
            }

            .lang-switcher {
                top: 12px;
                <?= $is_rtl ? 'left' : 'right' ?>: 12px;
            }

            .lang-btn {
                padding: 6px 12px;
                font-size: 12px;
            }
        }

        @media (max-height: 640px) {
            body {
                align-items: flex-start;
                padding-top: 20px;
            }

            .logo {
                margin-bottom: 20px;
            }

            .welcome-text {
                margin-bottom: 18px;
            }

            .login-card {
                padding: 24px 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Background gradient -->
    <div class="bg-gradient"></div>

    <!-- Language Switcher -->
    <div class="lang-switcher">
        <a href="?lang=<?= $other_lang ?>" class="lang-btn">
            <i class="fas fa-globe"></i>
            <?= $other_lang_label ?>
        </a>
    </div>

    <!-- Login Container -->
    <div class="login-container">
        <div class="login-card">
            <!-- Logo -->
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <h1 class="logo-text">HeroTrade Admin</h1>
                <p class="logo-subtitle"><?= t('admin_panel', 'Admin Panel') ?></p>
            </div>

            <!-- Welcome -->
            <div class="welcome-text">
                <h2 class="welcome-title"><?= t('welcome', 'Welcome') ?></h2>
                <p class="welcome-subtitle"><?= $is_rtl ? 'قم بتسجيل الدخول للوصول إلى لوحة التحكم' : 'Sign in to access the control panel' ?></p>
            </div>

            <!-- Error Alert -->
            <?php if ($error_message): ?>
                <div class="toast-alert" id="toastAlert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= htmlspecialchars($error_message) ?></span>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST" id="loginForm" novalidate>
                <!-- Username -->
                <div class="form-group">
                    <label class="form-label" for="username"><?= t('username', 'Username') ?></label>
                    <div class="input-wrapper">
                        <input
                            type="text"
                            name="username"
                            id="username"
                            class="form-input"
                            placeholder="<?= $is_rtl ? 'أدخل اسم المستخدم' : 'Enter your username' ?>"
                            required
                            autofocus
                            autocomplete="username"
                            value="<?= htmlspecialchars($username ?? '') ?>"
                        >
                        <i class="fas fa-user input-icon"></i>
                    </div>
                </div>

                <!-- Password -->
                <div class="form-group">
                    <label class="form-label" for="password"><?= t('password', 'Password') ?></label>
                    <div class="input-wrapper">
                        <input
                            type="password"
                            name="password"
                            id="password"
                            class="form-input"
                            placeholder="<?= $is_rtl ? 'أدخل كلمة المرور' : 'Enter your password' ?>"
                            required
                            autocomplete="current-password"
                        >
                        <i class="fas fa-lock input-icon"></i>
                        <button type="button" class="password-toggle" onclick="togglePassword()" aria-label="<?= $is_rtl ? 'إظهار كلمة المرور' : 'Toggle password visibility' ?>">
                            <i class="fas fa-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>

                <!-- Remember Me -->
                <div class="form-options">
                    <div class="checkbox-wrapper">
                        <input type="checkbox" id="remember" name="remember">
                        <label for="remember"><?= t('remember_me', 'Remember Me') ?></label>
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn-submit" id="submitBtn">
                    <i class="fas fa-sign-in-alt"></i>
                    <?= t('login', 'Login') ?>
                </button>
            </form>

            <!-- Security Badge -->
            <div class="security-badge">
                <i class="fas fa-lock"></i>
                <span><?= $is_rtl ? 'اتصال آمن ومشفر' : 'Secure encrypted connection' ?></span>
            </div>

            <!-- Footer -->
            <div class="login-footer">
                <a href="../index.php" class="footer-link">
                    <i class="fas fa-arrow-<?= $is_rtl ? 'right' : 'left' ?>"></i>
                    <?= $is_rtl ? 'العودة للصفحة الرئيسية' : 'Back to homepage' ?>
                </a>
            </div>
        </div>
    </div>

    <script>
        // Password Toggle
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        // Form Submit Loading State
        document.getElementById('loginForm').addEventListener('submit', function() {
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.classList.add('loading');
        });

        // Auto-hide toast alert after 5 seconds
        const toastAlert = document.getElementById('toastAlert');
        if (toastAlert) {
            setTimeout(function() {
                toastAlert.style.opacity = '0';
                toastAlert.style.transform = 'translateY(-10px)';
                setTimeout(function() { toastAlert.remove(); }, 300);
            }, 5000);
        }

        // Prevent double submission
        let isSubmitting = false;
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            if (isSubmitting) {
                e.preventDefault();
                return false;
            }
            isSubmitting = true;
        });
    </script>
</body>
</html>
