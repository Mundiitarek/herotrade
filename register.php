<?php
define('APP_ACCESS', true);
require_once 'config.php';
require_once 'includes/components.php';

// Redirect if already logged in
if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

// Handle registration request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    try {
        // Verify CSRF token
        if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
            json_response(['success' => false, 'message' => t('invalid_request', 'Invalid request')], 403);
        }

        $username = sanitize($_POST['username'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $full_name = sanitize($_POST['full_name'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        $country = sanitize($_POST['country'] ?? '');
        $language = get_current_lang();
        $terms = isset($_POST['terms']);

        // Validation
        if (empty($username) || empty($email) || empty($password) || empty($full_name)) {
            json_response(['success' => false, 'message' => t('fill_all_fields')], 400);
        }

        if (!$terms) {
            json_response(['success' => false, 'message' => t('accept_terms')], 400);
        }

        if (strlen($password) < PASSWORD_MIN_LENGTH) {
            json_response(['success' => false, 'message' => t('password_min_length', 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters')], 400);
        }

        if ($password !== $confirm_password) {
            json_response(['success' => false, 'message' => t('passwords_not_match')], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(['success' => false, 'message' => t('invalid_email', 'Invalid email address')], 400);
        }

        // Check if username exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            json_response(['success' => false, 'message' => t('username_exists', 'Username already exists')], 409);
        }

        // Check if email exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            json_response(['success' => false, 'message' => t('email_exists')], 409);
        }

        // Create user
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, password, full_name, phone, country, language, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $username,
            $email,
            $hashed_password,
            $full_name,
            $phone,
            $country,
            $language,
            get_client_ip()
        ]);

        $user_id = $pdo->lastInsertId();

        log_activity($pdo, 'registration', 'New user registered', $user_id);

        // Redirect to login with success message
        json_response([
            'success' => true,
            'message' => t('register_success'),
            'redirect' => 'login.php'
        ]);

    } catch (Exception $e) {
        error_log("Registration error: " . $e->getMessage());
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
    <?php render_head(t('register')); ?>
    <?php render_base_css(); ?>
    <style>
        /* Override base styles for auth page */
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow-x: hidden;
            padding: 40px 20px;
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
            max-width: 580px;
            position: relative;
            z-index: 2;
            animation: fadeInUp 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
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
            margin-bottom: 36px;
        }

        .logo {
            width: 60px;
            height: 60px;
            margin: 0 auto 16px;
            background: linear-gradient(135deg, var(--accent), #DC2626);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 900;
            color: white;
            box-shadow: 0 10px 30px rgba(245, 158, 11, 0.3);
        }

        .auth-title {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 6px;
            background: linear-gradient(135deg, var(--text), var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .auth-subtitle {
            color: var(--text-muted);
            font-size: 14px;
        }

        /* Form Elements */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-muted);
        }

        label .required {
            color: var(--danger);
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            <?php echo $is_rtl ? 'right: 16px;' : 'left: 16px;'; ?>
            top: 50%;
            transform: translateY(-50%);
            font-size: 18px;
            color: var(--text-muted);
            transition: color var(--transition);
        }

        input[type="email"],
        input[type="password"],
        input[type="text"],
        select {
            width: 100%;
            padding: 14px 16px;
            <?php echo $is_rtl ? 'padding-right: 48px;' : 'padding-left: 48px;'; ?>
            background: var(--glass);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--text);
            font-size: 14px;
            font-family: var(--font);
            transition: all var(--transition);
            outline: none;
        }

        select {
            padding: 14px 16px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2394A3B8' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: <?php echo $is_rtl ? 'left 16px center' : 'right 16px center'; ?>;
            cursor: pointer;
        }

        input:focus,
        select:focus {
            background: rgba(255, 255, 255, 0.05);
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.1);
        }

        input:focus + .input-icon {
            color: var(--accent);
        }

        /* Password Strength */
        .password-strength {
            height: 4px;
            border-radius: 2px;
            margin-top: 8px;
            background: rgba(255, 255, 255, 0.1);
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            width: 0%;
            transition: all var(--transition);
            border-radius: 2px;
        }

        .strength-weak {
            width: 33%;
            background: var(--danger);
        }

        .strength-medium {
            width: 66%;
            background: var(--warning);
        }

        .strength-strong {
            width: 100%;
            background: var(--success);
        }

        /* Terms Checkbox */
        .terms-checkbox {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin: 24px 0;
        }

        .terms-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            min-width: 18px;
            accent-color: var(--accent);
            margin-top: 2px;
            cursor: pointer;
        }

        .terms-checkbox label {
            margin: 0;
            font-size: 13px;
            line-height: 1.5;
            cursor: pointer;
        }

        .terms-checkbox a {
            color: var(--accent);
            text-decoration: none;
            transition: color var(--transition);
        }

        .terms-checkbox a:hover {
            color: var(--accent-hover);
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
            margin: 28px 0;
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

        /* Login Link */
        .login-link {
            text-align: center;
            font-size: 14px;
            color: var(--text-muted);
        }

        .login-link a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
            transition: color var(--transition);
        }

        .login-link a:hover {
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
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }

            .auth-card {
                padding: 32px 24px;
            }

            .auth-title {
                font-size: 24px;
            }
        }

        @media (max-width: 600px) {
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
                <h1 class="auth-title"><?php echo t('create_account'); ?></h1>
                <p class="auth-subtitle"><?php echo t('hero_subtitle'); ?></p>
            </div>

            <form id="registerForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label for="full_name"><?php echo t('full_name'); ?> <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <input type="text" id="full_name" name="full_name" required autocomplete="name">
                            <span class="input-icon">📝</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="username"><?php echo t('username'); ?> <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <input type="text" id="username" name="username" required autocomplete="username">
                            <span class="input-icon">👤</span>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="email"><?php echo t('email'); ?> <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input type="email" id="email" name="email" required autocomplete="email">
                        <span class="input-icon">✉️</span>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="phone"><?php echo t('phone'); ?></label>
                        <div class="input-wrapper">
                            <input type="text" id="phone" name="phone" autocomplete="tel">
                            <span class="input-icon">📱</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="country"><?php echo t('country'); ?></label>
                        <select id="country" name="country">
                            <option value=""><?php echo t('select_country', 'Select Country'); ?></option>
                            <option value="EG"><?php echo $lang === 'ar' ? 'مصر' : 'Egypt'; ?></option>
                            <option value="SA"><?php echo $lang === 'ar' ? 'السعودية' : 'Saudi Arabia'; ?></option>
                            <option value="AE"><?php echo $lang === 'ar' ? 'الإمارات' : 'UAE'; ?></option>
                            <option value="KW"><?php echo $lang === 'ar' ? 'الكويت' : 'Kuwait'; ?></option>
                            <option value="QA"><?php echo $lang === 'ar' ? 'قطر' : 'Qatar'; ?></option>
                            <option value="BH"><?php echo $lang === 'ar' ? 'البحرين' : 'Bahrain'; ?></option>
                            <option value="OM"><?php echo $lang === 'ar' ? 'عمان' : 'Oman'; ?></option>
                            <option value="JO"><?php echo $lang === 'ar' ? 'الأردن' : 'Jordan'; ?></option>
                            <option value="LB"><?php echo $lang === 'ar' ? 'لبنان' : 'Lebanon'; ?></option>
                            <option value="IQ"><?php echo $lang === 'ar' ? 'العراق' : 'Iraq'; ?></option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password"><?php echo t('password'); ?> <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" required autocomplete="new-password">
                        <span class="input-icon">🔒</span>
                    </div>
                    <div class="password-strength">
                        <div class="password-strength-bar" id="strengthBar"></div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password"><?php echo t('confirm_password'); ?> <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
                        <span class="input-icon">🔒</span>
                    </div>
                </div>

                <div class="terms-checkbox">
                    <input type="checkbox" id="terms" name="terms" required>
                    <label for="terms">
                        <?php echo t('i_agree_to'); ?> <a href="#" target="_blank"><?php echo t('terms_conditions'); ?></a> <?php echo t('and', '&'); ?> <a href="#" target="_blank"><?php echo t('privacy_policy'); ?></a>
                    </label>
                </div>

                <button type="submit" class="btn-auth" id="registerBtn">
                    <span id="btnText"><?php echo t('create_account'); ?></span>
                    <div class="spinner" id="spinner"></div>
                </button>
            </form>

            <div class="divider">
                <span><?php echo t('already_have_account'); ?></span>
            </div>

            <div class="login-link">
                <a href="login.php"><?php echo t('login'); ?></a>
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

        // Password strength checker
        const passwordInput = document.getElementById('password');
        const strengthBar = document.getElementById('strengthBar');

        passwordInput.addEventListener('input', function() {
            const password = this.value;
            let strength = 0;

            if (password.length >= 8) strength++;
            if (password.match(/[a-z]/) && password.match(/[A-Z]/)) strength++;
            if (password.match(/[0-9]/)) strength++;
            if (password.match(/[^a-zA-Z0-9]/)) strength++;

            strengthBar.className = 'password-strength-bar';
            if (strength >= 3) {
                strengthBar.classList.add('strength-strong');
            } else if (strength >= 2) {
                strengthBar.classList.add('strength-medium');
            } else if (strength >= 1) {
                strengthBar.classList.add('strength-weak');
            } else {
                strengthBar.style.width = '0%';
            }
        });

        // Register form handler
        const registerForm = document.getElementById('registerForm');
        const registerBtn = document.getElementById('registerBtn');
        const btnText = document.getElementById('btnText');
        const spinner = document.getElementById('spinner');

        registerForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;

            if (password !== confirmPassword) {
                showToast('<?php echo t('passwords_not_match'); ?>', 'error');
                return;
            }

            // Disable button and show loading
            registerBtn.disabled = true;
            btnText.style.display = 'none';
            spinner.style.display = 'block';

            const formData = new FormData(registerForm);

            try {
                const response = await fetch('register.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    showToast(result.message, 'success');

                    setTimeout(() => {
                        window.location.href = result.redirect;
                    }, 1500);
                } else {
                    showToast(result.message, 'error');

                    // Re-enable button
                    registerBtn.disabled = false;
                    btnText.style.display = 'inline';
                    spinner.style.display = 'none';
                }
            } catch (error) {
                showToast('<?php echo t('connection_error'); ?>', 'error');

                // Re-enable button
                registerBtn.disabled = false;
                btnText.style.display = 'inline';
                spinner.style.display = 'none';
            }
        });
    </script>
</body>
</html>
