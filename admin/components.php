<?php
/**
 * Admin UI Components
 * Include after config.php and functions.php in admin pages
 */

if (!defined('APP_ACCESS')) {
    die('Direct access not permitted');
}

/**
 * Render admin sidebar
 */
function render_admin_sidebar($active_page, $admin) {
    $lang = get_current_lang();
    $is_rtl = is_rtl();
    ?>
    <button class="mobile-menu-btn" onclick="toggleSidebar()" aria-label="Menu">
        <i class="fas fa-bars"></i>
    </button>
    <div class="sidebar-overlay" onclick="toggleSidebar()"></div>

    <aside class="sidebar" id="sidebar">
        <div class="logo">
            <div class="logo-content">
                <div class="logo-icon"><i class="fas fa-shield-halved"></i></div>
                <span class="logo-text">HeroTrade Admin</span>
            </div>
        </div>

        <div class="lang-switcher">
            <button class="lang-btn <?php echo $lang === 'ar' ? 'active' : ''; ?>" onclick="changeLanguage('ar')">العربية</button>
            <button class="lang-btn <?php echo $lang === 'en' ? 'active' : ''; ?>" onclick="changeLanguage('en')">English</button>
        </div>

        <div class="user-card">
            <div class="user-info">
                <div class="user-avatar"><i class="fas fa-user-shield"></i></div>
                <div>
                    <div class="user-name"><?php echo htmlspecialchars($admin['username'] ?? 'Admin'); ?></div>
                    <div style="font-size: 11px; color: var(--text-muted);"><?php echo htmlspecialchars($admin['role'] ?? 'admin'); ?></div>
                </div>
            </div>
        </div>

        <nav class="nav-menu">
            <div class="nav-section-title"><?php echo t('admin_panel'); ?></div>
            <a href="index.php" class="nav-item <?php echo $active_page === 'dashboard' ? 'active' : ''; ?>">
                <i class="fas fa-home"></i><span><?php echo t('dashboard'); ?></span>
            </a>
            <a href="users.php" class="nav-item <?php echo $active_page === 'users' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i><span><?php echo t('users_management'); ?></span>
            </a>
            <a href="payments.php" class="nav-item <?php echo $active_page === 'payments' ? 'active' : ''; ?>">
                <i class="fas fa-credit-card"></i><span><?php echo t('payments_management'); ?></span>
            </a>
            <a href="trades.php" class="nav-item <?php echo $active_page === 'trades' ? 'active' : ''; ?>">
                <i class="fas fa-chart-bar"></i><span><?php echo t('trades_management'); ?></span>
            </a>
            <a href="payment-gateways.php" class="nav-item <?php echo $active_page === 'gateways' ? 'active' : ''; ?>">
                <i class="fas fa-money-check-alt"></i><span><?php echo t('payment_gateways'); ?></span>
            </a>
            <a href="reports.php" class="nav-item <?php echo $active_page === 'reports' ? 'active' : ''; ?>">
                <i class="fas fa-chart-pie"></i><span><?php echo t('reports'); ?></span>
            </a>
            <a href="settings.php" class="nav-item <?php echo $active_page === 'settings' ? 'active' : ''; ?>">
                <i class="fas fa-cog"></i><span><?php echo t('settings'); ?></span>
            </a>
            <a href="../logout.php" class="nav-item" style="color: var(--danger);">
                <i class="fas fa-sign-out-alt"></i><span><?php echo t('logout'); ?></span>
            </a>
        </nav>
    </aside>
    <?php
}

/**
 * Render admin head section
 */
function render_admin_head($page_title = '') {
    $lang = get_current_lang();
    $site_name = 'HeroTrade Admin';
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
 * Render admin base CSS (same design system as user pages)
 */
function render_admin_base_css() {
    $is_rtl = is_rtl();
    $font = get_font();
    // Include the same base CSS from the user components
    require_once __DIR__ . '/../includes/components.php';
    render_base_css();
}

/**
 * Render admin base JS
 */
function render_admin_base_js() {
    ?>
    <script>
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.querySelector('.sidebar-overlay');
        sidebar.classList.toggle('active');
        overlay.classList.toggle('active');
        document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
    }

    function changeLanguage(lang) {
        fetch('../change_language.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'lang=' + lang
        }).then(() => location.reload());
    }

    function showToast(message, type) {
        type = type || 'success';
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
        setTimeout(function() { toast.remove(); }, 4000);
    }
    </script>
    <?php
}
