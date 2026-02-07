<?php
define('APP_ACCESS', true);
require_once 'config.php';
require_once 'includes/components.php';

// Redirect if already logged in
if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

// Handle login request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    try {
        // Verify CSRF token
        if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
            json_response(['success' => false, 'message' => t('invalid_request', 'Invalid request')], 403);
        }

        $email = sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']);

        if (empty($email) || empty($password)) {
            json_response(['success' => false, 'message' => t('fill_all_fields')], 400);
        }

        // Get user
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? OR username = ?");
        $stmt->execute([$email, $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            log_activity($pdo, 'failed_login', "Failed login attempt for: $email");
            json_response(['success' => false, 'message' => t('login_failed')], 401);
        }

        if (!$user['is_active']) {
            json_response(['success' => false, 'message' => t('account_disabled', 'Account is disabled')], 403);
        }

        // Create session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['language'] = $user['language'];

        // Update last login
        $stmt = $pdo->prepare("UPDATE users SET last_login = NOW(), ip_address = ? WHERE id = ?");
        $stmt->execute([get_client_ip(), $user['id']]);

        log_activity($pdo, 'login', 'User logged in successfully', $user['id']);

        json_response([
            'success' => true,
            'message' => t('login_success'),
            'redirect' => 'dashboard.php'
        ]);

    } catch (Exception $e) {
        error_log("Login error: " . $e->getMessage());
        json_response(['success' => false, 'message' => t('connection_error')], 500);
    }
}

$csrf_token = generate_csrf_token();
$lang = get_current_lang();
$dir = get_dir();
$is_rtl = is_rtl();
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" dir="<?php echo $dir; ?>">
<head>
    <?php render_head(t('login')); ?>
    <?php render_base_css(); ?>
    <style>
        /* Override base styles for auth page */
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            padding: 20px;
        }

        /* Animated Background */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background:
                radial-gradient(circle at 20% 30%, rgba(245, 158, 11, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(16, 185, 129, 0.05) 0%, transparent 50%);
            animation: gradientShift 20s ease infinite;
            z-index: 0;
        }

        @keyframes gradientShift {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        /* Language Switcher */
        .language-switcher {
            position: fixed;
            top: 20px;
            <?php echo $is_rtl ? 'left: 20px;' : 'right: 20px;'; ?>
            display: flex;
            gap: 8px;
            background: var(--glass);
            backdrop-filter: blur(10px);
            padding: 6px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            z-index: 10;
        }

        .lang-btn {
            padding: 8px 16px;
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            border-radius: 6px;
            transition: all var(--transition);
            font-family: var(--font);
        }

        .lang-btn.active {
            background: var(--accent);
            color: white;
        }

        .lang-btn:hover:not(.active) {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text);
        }

        /* Auth Container */
        .auth-container {
            width: 100%;
            max-width: 480px;
            position: relative;
            z-index: 2;
            animation: slideInUp 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .auth-card {
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 48px 40px;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .auth-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, var(--accent), var(--success));
        }

        /* Logo */
        .logo-container {
            text-align: center;
            margin-bottom: 40px;
        }

        .logo {
            width: 60px;
            height: 60px;
            margin: 0 auto 20px;
            background: linear-gradient(135deg, var(--accent), #DC2626);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 900;
            color: white;
            box-shadow: 0 10px 30px rgba(245, 158, 11, 0.3);
            animation: logoFloat 3s ease-in-out infinite;
        }

        @keyframes logoFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        .auth-title {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
            background: linear-gradient(135deg, var(--text), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .auth-subtitle {
            color: var(--text-muted);
            font-size: 15px;
            font-weight: 400;
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 24px;
        }

        label {
            display: block;
            margin-bottom: 10px;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-muted);
            transition: color var(--transition);
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            <?php echo $is_rtl ? 'right: 18px;' : 'left: 18px;'; ?>
            top: 50%;
            transform: translateY(-50%);
            font-size: 20px;
            color: var(--text-muted);
            transition: color var(--transition);
            z-index: 1;
        }

        input[type="email"],
        input[type="password"],
        input[type="text"] {
            width: 100%;
            padding: 16px 20px;
            <?php echo $is_rtl ? 'padding-right: 52px;' : 'padding-left: 52px;'; ?>
            background: var(--glass);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--text);
            font-size: 15px;
            font-family: var(--font);
            transition: all var(--transition);
            outline: none;
        }

        input:focus {
            background: rgba(255, 255, 255, 0.05);
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.1);
        }

        input:focus + .input-icon {
            color: var(--accent);
        }

        /* Remember & Forgot */
        .remember-forgot {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
            font-size: 14px;
        }

        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--accent);
            cursor: pointer;
        }

        .checkbox-wrapper label {
            margin: 0;
            cursor: pointer;
            color: var(--text-muted);
        }

        .forgot-link {
            color: var(--accent);
            text-decoration: none;
            transition: all var(--transition);
            position: relative;
        }

        .forgot-link::after {
            content: '';
            position: absolute;
            bottom: -2px;
            <?php echo $is_rtl ? 'right: 0;' : 'left: 0;'; ?>
            width: 0;
            height: 1px;
            background: var(--accent);
            transition: width var(--transition);
        }

        .forgot-link:hover::after {
            width: 100%;
        }

        /* Button */
        .btn-auth {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--accent), #DC2626);
            border: none;
            border-radius: var(--radius-sm);
            color: white;
            font-size: 16px;
            font-weight: 600;
            font-family: var(--font);
            cursor: pointer;
            transition: all var(--transition);
            box-shadow: 0 10px 30px rgba(245, 158, 11, 0.3);
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-auth::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }

        .btn-auth:hover::before {
            left: 100%;
        }

        .btn-auth:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 40px rgba(245, 158, 11, 0.4);
        }

        .btn-auth:active {
            transform: translateY(0);
        }

        .btn-auth:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* Loading Spinner */
        .spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Divider */
        .divider {
            display: flex;
            align-items: center;
            gap: 16px;
            margin: 32px 0;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        .divider span {
            color: var(--text-muted);
            font-size: 13px;
        }

        /* Register Link */
        .register-link {
            text-align: center;
            font-size: 14px;
            color: var(--text-muted);
        }

        .register-link a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
            transition: color var(--transition);
        }

        .register-link a:hover {
            color: var(--accent-hover);
        }

        /* Toast Notification */
        .toast {
            position: fixed;
            top: 20px;
            <?php echo $is_rtl ? 'left: 50%;' : 'right: 50%;'; ?>
            transform: translateX(<?php echo $is_rtl ? '50%' : '-50%'; ?>);
            min-width: 300px;
            padding: 16px 20px;
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow);
            display: none;
            z-index: 9999;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateX(<?php echo $is_rtl ? '50%' : '-50%'; ?>) translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(<?php echo $is_rtl ? '50%' : '-50%'; ?>) translateY(0);
            }
        }

        .toast.success {
            border-color: var(--success);
            background: rgba(16, 185, 129, 0.1);
        }

        .toast.error {
            border-color: var(--danger);
            background: rgba(239, 68, 68, 0.1);
        }

        .toast-content {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .toast-icon {
            font-size: 20px;
        }

        .toast.success .toast-icon {
            color: var(--success);
        }

        .toast.error .toast-icon {
            color: var(--danger);
        }

        .toast-message {
            flex: 1;
            font-size: 14px;
            color: var(--text);
        }

        /* Responsive */
        @media (max-width: 600px) {
            .auth-container {
                padding: 0;
            }

            .auth-card {
                padding: 36px 24px;
            }

            .auth-title {
                font-size: 26px;
            }

            .language-switcher {
                top: 10px;
                <?php echo $is_rtl ? 'left: 10px;' : 'right: 10px;'; ?>
            }
        }

        @media (max-width: 320px) {
            .auth-card {
                padding: 24px 16px;
            }

            .auth-title {
                font-size: 22px;
            }
        }
    </style>
</head>
<body>
    <!-- Language Switcher -->
    <div class="language-switcher">
        <button class="lang-btn <?php echo $lang === 'ar' ? 'active' : ''; ?>" onclick="changeLanguage('ar')">
            عربي
        </button>
        <button class="lang-btn <?php echo $lang === 'en' ? 'active' : ''; ?>" onclick="changeLanguage('en')">
            English
        </button>
    </div>

    <!-- Toast Notification -->
    <div class="toast" id="toast">
        <div class="toast-content">
            <span class="toast-icon" id="toastIcon"></span>
            <span class="toast-message" id="toastMessage"></span>
        </div>
    </div>

    <!-- Auth Container -->
    <div class="auth-container">
        <div class="auth-card">
            <div class="logo-container">
                <div class="logo">₿</div>
                <h1 class="auth-title"><?php echo t('login'); ?></h1>
                <p class="auth-subtitle"><?php echo t('hero_subtitle'); ?></p>
            </div>

            <form id="loginForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                <div class="form-group">
                    <label for="email"><?php echo t('email'); ?></label>
                    <div class="input-wrapper">
                        <input type="text" id="email" name="email" required autocomplete="username">
                        <span class="input-icon">👤</span>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password"><?php echo t('password'); ?></label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" required autocomplete="current-password">
                        <span class="input-icon">🔒</span>
                    </div>
                </div>

                <div class="remember-forgot">
                    <div class="checkbox-wrapper">
                        <input type="checkbox" id="remember" name="remember">
                        <label for="remember"><?php echo t('remember_me'); ?></label>
                    </div>
                    <a href="#" class="forgot-link"><?php echo t('forgot_password'); ?></a>
                </div>

                <button type="submit" class="btn-auth" id="loginBtn">
                    <span id="btnText"><?php echo t('login'); ?></span>
                    <div class="spinner" id="spinner"></div>
                </button>
            </form>

            <div class="divider">
                <span><?php echo t('or', 'or'); ?></span>
            </div>

            <div class="register-link">
                <?php echo t('dont_have_account'); ?> <a href="register.php"><?php echo t('register'); ?></a>
            </div>
        </div>
    </div>

    <script>
        // Toast notification function
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            const icon = document.getElementById('toastIcon');
            const msg = document.getElementById('toastMessage');

            toast.className = 'toast ' + type;
            icon.textContent = type === 'success' ? '✓' : '✕';
            msg.textContent = message;
            toast.style.display = 'block';

            setTimeout(() => {
                toast.style.display = 'none';
            }, 5000);
        }

        // Language switcher
        function changeLanguage(lang) {
            fetch('change_language.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'lang=' + lang
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }

        // Login form handler
        const loginForm = document.getElementById('loginForm');
        const loginBtn = document.getElementById('loginBtn');
        const btnText = document.getElementById('btnText');
        const spinner = document.getElementById('spinner');

        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            // Disable button and show loading
            loginBtn.disabled = true;
            btnText.style.display = 'none';
            spinner.style.display = 'block';

            const formData = new FormData(loginForm);

            try {
                const response = await fetch('login.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    showToast(result.message, 'success');

                    setTimeout(() => {
                        window.location.href = result.redirect;
                    }, 1000);
                } else {
                    showToast(result.message, 'error');

                    // Re-enable button
                    loginBtn.disabled = false;
                    btnText.style.display = 'inline';
                    spinner.style.display = 'none';
                }
            } catch (error) {
                showToast('<?php echo t('connection_error'); ?>', 'error');

                // Re-enable button
                loginBtn.disabled = false;
                btnText.style.display = 'inline';
                spinner.style.display = 'none';
            }
        });
    </script>
</body>
</html>
