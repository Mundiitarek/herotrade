<?php
/**
 * Common UI Components for all pages
 * Include this file after config.php and functions.php
 */

if (!defined('APP_ACCESS')) {
    die('Direct access not permitted');
}

/**
 * Render the common <head> section
 */
function render_head($page_title = '') {
    $lang = get_current_lang();
    $dir = get_dir();
    $font = get_font();
    $site_name = $lang === 'ar' ? 'هيرو تريد' : 'HeroTrade';
    $title = $page_title ? "$page_title - $site_name" : $site_name;
    ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($title); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <?php
}

/**
 * Render common CSS variables and base styles
 */
function render_base_css() {
    $dir = get_dir();
    $font = get_font();
    $is_rtl = is_rtl();
    ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --primary: #0F172A;
            --secondary: #1E293B;
            --accent: #F59E0B;
            --accent-hover: #D97706;
            --success: #10B981;
            --danger: #EF4444;
            --info: #3B82F6;
            --warning: #F59E0B;
            --text: #F8FAFC;
            --text-muted: #94A3B8;
            --border: rgba(148, 163, 184, 0.1);
            --card-bg: #1E293B;
            --glass: rgba(255, 255, 255, 0.03);
            --shadow: 0 4px 24px rgba(0, 0, 0, 0.3);
            --radius: 12px;
            --radius-sm: 8px;
            --transition: 0.3s ease;
            --font: <?php echo $font; ?>;
            --sidebar-width: 280px;
        }

        body {
            font-family: var(--font);
            background: var(--primary);
            color: var(--text);
            line-height: 1.6;
            direction: <?php echo $dir; ?>;
            text-align: <?php echo $is_rtl ? 'right' : 'left'; ?>;
            overflow-x: hidden;
        }

        /* ===== SIDEBAR ===== */
        .sidebar {
            position: fixed;
            <?php echo $is_rtl ? 'right: 0;' : 'left: 0;'; ?>
            top: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--secondary);
            <?php echo $is_rtl ? 'border-left: 1px solid var(--border);' : 'border-right: 1px solid var(--border);'; ?>
            z-index: 1000;
            overflow-y: auto;
            transition: transform var(--transition);
        }

        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-thumb { background: var(--accent); border-radius: 2px; }

        .logo { padding: 24px 20px; border-bottom: 1px solid var(--border); }
        .logo-content { display: flex; align-items: center; gap: 12px; }
        .logo-icon {
            width: 44px; height: 44px;
            background: linear-gradient(135deg, var(--accent), #DC2626);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; color: white;
        }
        .logo-text {
            font-size: 20px; font-weight: 900;
            background: linear-gradient(135deg, var(--accent), #DC2626);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .user-card {
            padding: 16px; margin: 16px;
            background: rgba(249, 158, 11, 0.05);
            border: 1px solid rgba(249, 158, 11, 0.1);
            border-radius: var(--radius);
        }
        .user-info { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
        .user-avatar {
            width: 44px; height: 44px;
            background: linear-gradient(135deg, var(--accent), #DC2626);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; font-weight: 700; color: white; flex-shrink: 0;
        }
        .user-name { font-weight: 700; font-size: 15px; }
        .user-balance {
            display: flex; justify-content: space-between; align-items: center;
            padding: 10px 12px; background: rgba(0,0,0,0.2); border-radius: 10px;
        }
        .balance-label { font-size: 12px; color: var(--text-muted); }
        .balance-value { font-size: 18px; font-weight: 900; color: var(--success); font-family: 'Poppins', sans-serif; }

        /* Nav Menu */
        .nav-menu { padding: 16px 0; }
        .nav-section-title {
            padding: 0 20px 8px; font-size: 11px; font-weight: 700;
            color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px;
        }
        .nav-item {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 20px; color: var(--text-muted);
            text-decoration: none; transition: all var(--transition);
            position: relative; font-size: 14px; min-height: 44px;
        }
        .nav-item:hover, .nav-item.active { color: var(--text); background: rgba(249, 158, 11, 0.05); }
        .nav-item.active::before {
            content: ''; position: absolute;
            <?php echo $is_rtl ? 'right: 0;' : 'left: 0;'; ?>
            top: 0; height: 100%; width: 3px; background: var(--accent);
        }
        .nav-item i { width: 20px; text-align: center; font-size: 16px; }
        .nav-badge {
            <?php echo $is_rtl ? 'margin-right: auto;' : 'margin-left: auto;'; ?>
            background: var(--danger); color: white; font-size: 10px;
            padding: 2px 8px; border-radius: 10px; font-weight: 700;
        }

        /* Language Switcher in sidebar */
        .lang-switcher {
            display: flex; gap: 4px; margin: 8px 16px; padding: 4px;
            background: rgba(0,0,0,0.2); border-radius: var(--radius-sm);
        }
        .lang-btn {
            flex: 1; padding: 8px; border: none; border-radius: 6px;
            background: transparent; color: var(--text-muted);
            font-size: 12px; font-weight: 600; cursor: pointer;
            transition: all var(--transition); font-family: var(--font);
            display: flex; align-items: center; justify-content: center; gap: 6px;
        }
        .lang-btn.active { background: var(--accent); color: #000; }
        .lang-btn:hover:not(.active) { background: rgba(255,255,255,0.05); color: var(--text); }

        /* ===== MAIN CONTENT ===== */
        .main {
            <?php echo $is_rtl ? 'margin-right: var(--sidebar-width);' : 'margin-left: var(--sidebar-width);'; ?>
            padding: 24px 30px;
            min-height: 100vh;
        }

        /* Mobile hamburger */
        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 16px;
            <?php echo $is_rtl ? 'right: 16px;' : 'left: 16px;'; ?>
            z-index: 9998;
            width: 44px; height: 44px;
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--text);
            font-size: 20px;
            cursor: pointer;
            align-items: center; justify-content: center;
            transition: all var(--transition);
        }
        .mobile-menu-btn:hover { background: var(--accent); color: #000; }
        .sidebar-overlay {
            display: none; position: fixed; top: 0; left: 0;
            width: 100%; height: 100%; background: rgba(0,0,0,0.6);
            z-index: 999; opacity: 0; transition: opacity var(--transition);
        }
        .sidebar-overlay.active { display: block; opacity: 1; }

        /* ===== HEADER ===== */
        .header { margin-bottom: 24px; }
        .header-top { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .page-title {
            font-size: 28px; font-weight: 900;
            background: linear-gradient(135deg, var(--text), var(--accent));
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
        }
        .header-actions { display: flex; gap: 10px; flex-wrap: wrap; }

        /* ===== BUTTONS ===== */
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 24px; border: none; border-radius: var(--radius-sm);
            font-weight: 700; font-size: 14px; cursor: pointer;
            transition: all var(--transition); text-decoration: none;
            font-family: var(--font); min-height: 44px; white-space: nowrap;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--accent), #DC2626);
            color: white; box-shadow: 0 8px 24px rgba(249, 158, 11, 0.3);
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 12px 32px rgba(249, 158, 11, 0.4); }
        .btn-secondary { background: var(--secondary); color: var(--text); border: 1px solid var(--border); }
        .btn-secondary:hover { background: #2D3748; }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #059669; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #DC2626; }
        .btn-sm { padding: 8px 16px; font-size: 12px; min-height: 36px; }

        /* ===== CARDS ===== */
        .card {
            background: var(--secondary); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 24px;
        }
        .card-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid var(--border);
            flex-wrap: wrap; gap: 10px;
        }
        .card-title {
            font-size: 17px; font-weight: 700;
            display: flex; align-items: center; gap: 10px;
        }
        .card-title i { color: var(--accent); }
        .card-link {
            color: var(--accent); text-decoration: none; font-size: 13px;
            font-weight: 600; display: flex; align-items: center; gap: 6px;
            transition: all var(--transition);
        }
        .card-link:hover { gap: 10px; }

        /* ===== STATS GRID ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px; margin-bottom: 24px;
        }
        .stat-card {
            background: var(--secondary); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 20px;
            position: relative; overflow: hidden; transition: all var(--transition);
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow); }
        .stat-card::before {
            content: ''; position: absolute; top: 0; left: 0;
            width: 100%; height: 3px;
            background: linear-gradient(90deg, var(--accent), #DC2626);
        }
        .stat-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .stat-label { font-size: 13px; color: var(--text-muted); font-weight: 500; }
        .stat-icon {
            width: 42px; height: 42px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; background: rgba(249, 158, 11, 0.1); color: var(--accent);
        }
        .stat-icon.success { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .stat-icon.danger { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
        .stat-icon.info { background: rgba(59, 130, 246, 0.1); color: var(--info); }
        .stat-value {
            font-size: 28px; font-weight: 900; margin-bottom: 6px;
            font-family: 'Poppins', sans-serif;
        }
        .stat-change {
            font-size: 12px; font-weight: 600;
            display: flex; align-items: center; gap: 4px;
        }
        .stat-change.positive { color: var(--success); }
        .stat-change.negative { color: var(--danger); }

        /* ===== TABLE ===== */
        .table-wrapper { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .table { width: 100%; border-collapse: collapse; min-width: 600px; }
        .table thead th {
            text-align: <?php echo $is_rtl ? 'right' : 'left'; ?>;
            padding: 12px; font-size: 11px; font-weight: 700;
            color: var(--text-muted); text-transform: uppercase;
            letter-spacing: 0.5px; border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        .table tbody td {
            padding: 14px 12px; border-bottom: 1px solid var(--border);
            font-size: 13px; white-space: nowrap;
        }
        .table tbody tr:hover { background: rgba(249, 158, 11, 0.03); }

        /* ===== BADGES ===== */
        .badge {
            display: inline-block; padding: 4px 12px; border-radius: var(--radius-sm);
            font-size: 11px; font-weight: 700; text-transform: uppercase;
        }
        .badge.buy, .badge.success, .badge.won, .badge.completed, .badge.approved, .badge.active { background: rgba(16, 185, 129, 0.15); color: var(--success); }
        .badge.sell, .badge.danger, .badge.lost, .badge.failed, .badge.rejected { background: rgba(239, 68, 68, 0.15); color: var(--danger); }
        .badge.open, .badge.info, .badge.pending, .badge.processing { background: rgba(59, 130, 246, 0.15); color: var(--info); }
        .badge.warning, .badge.cancelled { background: rgba(249, 158, 11, 0.15); color: var(--accent); }

        .profit { color: var(--success); font-weight: 700; }
        .loss { color: var(--danger); font-weight: 700; }

        /* ===== FORM ELEMENTS ===== */
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block; margin-bottom: 6px; font-size: 13px;
            font-weight: 600; color: var(--text-muted);
        }
        .form-control {
            width: 100%; padding: 12px 16px; background: rgba(0,0,0,0.2);
            border: 1px solid var(--border); border-radius: var(--radius-sm);
            color: var(--text); font-size: 14px; font-family: var(--font);
            transition: all var(--transition); min-height: 48px;
        }
        .form-control:focus {
            outline: none; border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(249, 158, 11, 0.1);
        }
        .form-control::placeholder { color: var(--text-muted); }
        select.form-control { cursor: pointer; }
        textarea.form-control { min-height: 100px; resize: vertical; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center; padding: 40px 20px; color: var(--text-muted);
        }
        .empty-icon { font-size: 48px; margin-bottom: 12px; opacity: 0.3; }

        /* ===== TOAST NOTIFICATIONS ===== */
        .toast-container {
            position: fixed; top: 20px; <?php echo $is_rtl ? 'left: 20px;' : 'right: 20px;'; ?>
            z-index: 10000; display: flex; flex-direction: column; gap: 10px;
        }
        .toast {
            padding: 14px 20px; border-radius: var(--radius-sm);
            color: white; font-weight: 600; font-size: 14px;
            display: flex; align-items: center; gap: 10px;
            animation: slideIn 0.3s ease, fadeOut 0.3s ease 3.7s forwards;
            min-width: 280px; max-width: 420px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.4);
            font-family: var(--font);
        }
        .toast.success { background: var(--success); }
        .toast.error { background: var(--danger); }
        .toast.warning { background: var(--accent); color: #000; }
        .toast.info { background: var(--info); }
        @keyframes slideIn {
            from { transform: translateX(<?php echo $is_rtl ? '-100%' : '100%'; ?>); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes fadeOut { to { opacity: 0; transform: translateY(-10px); } }

        /* ===== LOADING SPINNER ===== */
        .spinner {
            width: 40px; height: 40px; border: 3px solid var(--border);
            border-top-color: var(--accent); border-radius: 50%;
            animation: spin 0.8s linear infinite; margin: 20px auto;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ===== TABS ===== */
        .tabs { display: flex; gap: 4px; margin-bottom: 20px; overflow-x: auto; padding-bottom: 4px; }
        .tab-btn {
            padding: 10px 20px; border: 1px solid var(--border);
            background: transparent; color: var(--text-muted);
            border-radius: var(--radius-sm); cursor: pointer;
            font-weight: 600; font-size: 13px; transition: all var(--transition);
            white-space: nowrap; font-family: var(--font); min-height: 44px;
        }
        .tab-btn.active, .tab-btn:hover {
            background: var(--accent); color: #000; border-color: var(--accent);
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* ===== MODAL ===== */
        .modal-overlay {
            display: none; position: fixed; top: 0; left: 0;
            width: 100%; height: 100%; background: rgba(0,0,0,0.7);
            z-index: 10001; align-items: center; justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: var(--secondary); border-radius: var(--radius);
            padding: 30px; max-width: 500px; width: 90%;
            max-height: 90vh; overflow-y: auto;
            border: 1px solid var(--border);
        }
        .modal-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid var(--border);
        }
        .modal-title { font-size: 18px; font-weight: 700; }
        .modal-close {
            width: 36px; height: 36px; border: none; background: rgba(239,68,68,0.1);
            color: var(--danger); border-radius: var(--radius-sm); cursor: pointer;
            font-size: 16px; display: flex; align-items: center; justify-content: center;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1024px) {
            .sidebar {
                transform: <?php echo $is_rtl ? 'translateX(100%)' : 'translateX(-100%)'; ?>;
            }
            .sidebar.active { transform: translateX(0); }
            .main {
                <?php echo $is_rtl ? 'margin-right: 0;' : 'margin-left: 0;'; ?>
                padding: 70px 20px 24px;
            }
            .mobile-menu-btn { display: flex; }
        }

        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .stat-value { font-size: 22px; }
            .page-title { font-size: 22px; }
            .card { padding: 16px; }
            .form-row { grid-template-columns: 1fr; }
            .header-actions { width: 100%; }
            .header-actions .btn { flex: 1; justify-content: center; padding: 10px 12px; font-size: 12px; }
        }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .stat-card { padding: 14px; }
            .stat-value { font-size: 20px; }
            .main { padding: 64px 12px 20px; }
        }

        /* ===== CONTENT GRID ===== */
        .content-grid {
            display: grid; grid-template-columns: 2fr 1fr;
            gap: 20px; margin-bottom: 24px;
        }
        @media (max-width: 1024px) {
            .content-grid { grid-template-columns: 1fr; }
        }

        /* ===== ASSET TABS ===== */
        .asset-tabs {
            display: flex; gap: 6px; margin-bottom: 16px;
            overflow-x: auto; padding-bottom: 4px;
        }
        .asset-tab {
            padding: 10px 18px; border: 1px solid var(--border);
            background: transparent; color: var(--text-muted);
            border-radius: var(--radius-sm); cursor: pointer;
            font-weight: 600; font-size: 13px; transition: all var(--transition);
            white-space: nowrap; font-family: var(--font);
            display: flex; align-items: center; gap: 8px; min-height: 44px;
        }
        .asset-tab.active, .asset-tab:hover {
            background: var(--accent); color: #000; border-color: var(--accent);
        }
    </style>
    <?php
}

/**
 * Render sidebar for user pages
 */
function render_sidebar($active_page, $user, $unread_count = 0) {
    $lang = get_current_lang();
    $is_rtl = is_rtl();
    ?>
    <!-- Mobile Menu Button -->
    <button class="mobile-menu-btn" onclick="toggleSidebar()" aria-label="Menu">
        <i class="fas fa-bars"></i>
    </button>
    <div class="sidebar-overlay" onclick="toggleSidebar()"></div>

    <aside class="sidebar" id="sidebar">
        <div class="logo">
            <div class="logo-content">
                <div class="logo-icon"><i class="fas fa-chart-line"></i></div>
                <span class="logo-text">HeroTrade</span>
            </div>
        </div>

        <!-- Language Switcher -->
        <div class="lang-switcher">
            <button class="lang-btn <?php echo $lang === 'ar' ? 'active' : ''; ?>" onclick="changeLanguage('ar')">
                العربية
            </button>
            <button class="lang-btn <?php echo $lang === 'en' ? 'active' : ''; ?>" onclick="changeLanguage('en')">
                English
            </button>
        </div>

        <div class="user-card">
            <div class="user-info">
                <div class="user-avatar"><?php echo strtoupper(substr($user['username'], 0, 2)); ?></div>
                <div>
                    <div class="user-name"><?php echo htmlspecialchars($user['username']); ?></div>
                </div>
            </div>
            <div class="user-balance">
                <span class="balance-label"><?php echo t('available_balance'); ?></span>
                <span class="balance-value">$<?php echo number_format($user['balance'], 2); ?></span>
            </div>
        </div>

        <nav class="nav-menu">
            <div class="nav-section-title"><?php echo t('main_menu'); ?></div>
            <a href="dashboard.php" class="nav-item <?php echo $active_page === 'dashboard' ? 'active' : ''; ?>">
                <i class="fas fa-home"></i><span><?php echo t('dashboard'); ?></span>
            </a>
            <a href="spot-trading.php" class="nav-item <?php echo $active_page === 'spot-trading' ? 'active' : ''; ?>">
                <i class="fas fa-chart-area"></i><span><?php echo t('spot_trading'); ?></span>
            </a>
            <a href="binary-trading.php" class="nav-item <?php echo $active_page === 'binary-trading' ? 'active' : ''; ?>">
                <i class="fas fa-bullseye"></i><span><?php echo t('binary_trading'); ?></span>
            </a>
            <a href="p2p-deposit.php" class="nav-item <?php echo $active_page === 'p2p-deposit' ? 'active' : ''; ?>">
                <i class="fas fa-wallet"></i><span><?php echo t('add_balance'); ?></span>
            </a>
            <a href="p2p-withdrawal.php" class="nav-item <?php echo $active_page === 'p2p-withdrawal' ? 'active' : ''; ?>">
                <i class="fas fa-money-bill-transfer"></i><span><?php echo t('withdraw'); ?></span>
            </a>

            <div class="nav-section-title" style="margin-top: 16px;"><?php echo t('account'); ?></div>
            <a href="transactions.php" class="nav-item <?php echo $active_page === 'transactions' ? 'active' : ''; ?>">
                <i class="fas fa-receipt"></i><span><?php echo t('transactions'); ?></span>
            </a>
            <a href="profile.php" class="nav-item <?php echo $active_page === 'profile' ? 'active' : ''; ?>">
                <i class="fas fa-user-circle"></i><span><?php echo t('profile'); ?></span>
            </a>
            <a href="notifications.php" class="nav-item <?php echo $active_page === 'notifications' ? 'active' : ''; ?>">
                <i class="fas fa-bell"></i><span><?php echo t('notifications'); ?></span>
                <?php if ($unread_count > 0): ?>
                    <span class="nav-badge"><?php echo $unread_count; ?></span>
                <?php endif; ?>
            </a>
            <a href="logout.php" class="nav-item" style="color: var(--danger);">
                <i class="fas fa-sign-out-alt"></i><span><?php echo t('logout'); ?></span>
            </a>
        </nav>
    </aside>
    <?php
}

/**
 * Render common JavaScript
 */
function render_base_js() {
    ?>
    <script>
    // Sidebar Toggle
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.querySelector('.sidebar-overlay');
        sidebar.classList.toggle('active');
        overlay.classList.toggle('active');
        document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
    }

    // Language Switcher
    function changeLanguage(lang) {
        fetch('change_language.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'lang=' + lang
        }).then(() => location.reload());
    }

    // Toast Notification
    function showToast(message, type = 'success') {
        let container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container';
            document.body.appendChild(container);
        }
        const icons = { success: 'check-circle', error: 'exclamation-circle', warning: 'exclamation-triangle', info: 'info-circle' };
        const toast = document.createElement('div');
        toast.className = 'toast ' + type;
        toast.innerHTML = '<i class="fas fa-' + (icons[type] || 'info-circle') + '"></i> ' + message;
        container.appendChild(toast);
        setTimeout(() => toast.remove(), 4000);
    }

    // Close sidebar on outside click (mobile)
    document.addEventListener('click', function(e) {
        const sidebar = document.getElementById('sidebar');
        const btn = document.querySelector('.mobile-menu-btn');
        if (sidebar && sidebar.classList.contains('active') && !sidebar.contains(e.target) && !btn.contains(e.target)) {
            toggleSidebar();
        }
    });
    </script>
    <?php
}
