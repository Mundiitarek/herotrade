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

// Handle AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'mark_read') {
        $notification_id = intval($_POST['notification_id'] ?? 0);

        try {
            $stmt = $pdo->prepare("
                UPDATE notifications
                SET is_read = 1, read_at = NOW()
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$notification_id, $user_id]);

            json_response(['success' => true]);
        } catch (PDOException $e) {
            json_response(['success' => false, 'message' => t('error_occurred', 'An error occurred')], 500);
        }
    }

    if ($_POST['action'] === 'mark_unread') {
        $notification_id = intval($_POST['notification_id'] ?? 0);

        try {
            $stmt = $pdo->prepare("
                UPDATE notifications
                SET is_read = 0, read_at = NULL
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$notification_id, $user_id]);

            json_response(['success' => true]);
        } catch (PDOException $e) {
            json_response(['success' => false, 'message' => t('error_occurred', 'An error occurred')], 500);
        }
    }

    if ($_POST['action'] === 'mark_all_read') {
        try {
            $stmt = $pdo->prepare("
                UPDATE notifications
                SET is_read = 1, read_at = NOW()
                WHERE user_id = ? AND is_read = 0
            ");
            $stmt->execute([$user_id]);

            json_response(['success' => true]);
        } catch (PDOException $e) {
            json_response(['success' => false, 'message' => t('error_occurred', 'An error occurred')], 500);
        }
    }

    if ($_POST['action'] === 'delete') {
        $notification_id = intval($_POST['notification_id'] ?? 0);

        try {
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
            $stmt->execute([$notification_id, $user_id]);

            json_response(['success' => true]);
        } catch (PDOException $e) {
            json_response(['success' => false, 'message' => t('error_occurred', 'An error occurred')], 500);
        }
    }

    if ($_POST['action'] === 'delete_all_read') {
        try {
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ? AND is_read = 1");
            $stmt->execute([$user_id]);

            json_response(['success' => true]);
        } catch (PDOException $e) {
            json_response(['success' => false, 'message' => t('error_occurred', 'An error occurred')], 500);
        }
    }

    exit;
}

// Pagination
$page = intval($_GET['page'] ?? 1);
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Get filter
$filter = sanitize($_GET['filter'] ?? 'all');

// Build query
$where = "user_id = ?";
$params = [$user_id];

if ($filter === 'unread') {
    $where .= " AND is_read = 0";
} elseif ($filter === 'read') {
    $where .= " AND is_read = 1";
}

// Get notifications
try {
    $stmt = $pdo->prepare("
        SELECT * FROM notifications
        WHERE $where
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$per_page, $offset]));
    $notifications = $stmt->fetchAll();

    // Get total count for pagination
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM notifications WHERE $where");
    $stmt->execute($params);
    $total_notifications = $stmt->fetch()['total'];
} catch (PDOException $e) {
    error_log("Get notifications error: " . $e->getMessage());
    $notifications = [];
    $total_notifications = 0;
}

// Get counts
try {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) as total,
            COUNT(CASE WHEN is_read = 0 THEN 1 END) as unread,
            COUNT(CASE WHEN is_read = 1 THEN 1 END) as read_count
        FROM notifications
        WHERE user_id = ?
    ");
    $stmt->execute([$user_id]);
    $counts = $stmt->fetch();
} catch (PDOException $e) {
    $counts = ['total' => 0, 'unread' => 0, 'read_count' => 0];
}

$total_pages = ceil($total_notifications / $per_page);

$lang = get_current_lang();
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" dir="<?php echo get_dir(); ?>">
<head>
    <?php render_head(t('notifications')); ?>
    <?php render_base_css(); ?>
    <style>
        /* Notification specific styles */
        .filter-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 10px 20px;
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
            transition: all var(--transition);
            min-height: 44px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .filter-tab:hover,
        .filter-tab.active {
            background: var(--accent);
            color: #000;
            border-color: var(--accent);
        }

        .notification-item {
            display: flex;
            gap: 16px;
            padding: 20px;
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            margin-bottom: 12px;
            transition: all var(--transition);
            align-items: flex-start;
        }

        .notification-item:hover {
            background: rgba(249, 158, 11, 0.03);
            transform: translateX(<?php echo is_rtl() ? '5px' : '-5px'; ?>);
        }

        .notification-item.unread {
            background: rgba(249, 158, 11, 0.05);
            border-color: rgba(249, 158, 11, 0.2);
        }

        .notification-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .notification-icon.trade {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info);
        }

        .notification-icon.deposit {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .notification-icon.withdrawal {
            background: rgba(249, 158, 11, 0.1);
            color: var(--accent);
        }

        .notification-icon.system {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .notification-icon.success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .notification-icon.warning {
            background: rgba(249, 158, 11, 0.1);
            color: var(--accent);
        }

        .notification-content {
            flex: 1;
            min-width: 0;
        }

        .notification-title {
            font-weight: 700;
            font-size: 15px;
            margin-bottom: 4px;
        }

        .notification-message {
            color: var(--text-muted);
            font-size: 14px;
            margin-bottom: 8px;
            line-height: 1.5;
        }

        .notification-time {
            font-size: 12px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .notification-actions {
            display: flex;
            gap: 6px;
            flex-shrink: 0;
        }

        .notification-action-btn {
            width: 36px;
            height: 36px;
            background: rgba(0,0,0,0.2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--text-muted);
            cursor: pointer;
            transition: all var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .notification-action-btn:hover {
            background: var(--accent);
            color: #000;
            border-color: var(--accent);
        }

        .notification-action-btn.danger:hover {
            background: var(--danger);
            color: white;
            border-color: var(--danger);
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 24px;
            flex-wrap: wrap;
        }

        .pagination a,
        .pagination span {
            padding: 8px 16px;
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
            min-height: 40px;
            display: flex;
            align-items: center;
            transition: all var(--transition);
        }

        .pagination a:hover {
            background: var(--accent);
            color: #000;
            border-color: var(--accent);
        }

        .pagination .active {
            background: var(--accent);
            color: #000;
            border-color: var(--accent);
        }

        @media (max-width: 768px) {
            .notification-item {
                flex-direction: column;
            }

            .notification-actions {
                width: 100%;
                justify-content: flex-end;
            }
        }
    </style>
</head>
<body>
    <?php render_sidebar('notifications', $user, $counts['unread']); ?>

    <main class="main">
        <div class="header">
            <div class="header-top">
                <h1 class="page-title"><?php echo t('notifications'); ?></h1>
                <div class="header-actions">
                    <?php if ($counts['unread'] > 0): ?>
                        <button class="btn btn-primary" onclick="markAllRead()">
                            <i class="fas fa-check-double"></i>
                            <?php echo t('mark_all_read', 'Mark All Read'); ?>
                        </button>
                    <?php endif; ?>

                    <?php if ($counts['read_count'] > 0): ?>
                        <button class="btn btn-danger" onclick="deleteAllRead()">
                            <i class="fas fa-trash"></i>
                            <?php echo t('delete_all_read', 'Delete Read'); ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Filter Tabs -->
        <div class="filter-tabs">
            <a href="?filter=all" class="filter-tab <?php echo $filter === 'all' ? 'active' : ''; ?>">
                <i class="fas fa-list"></i>
                <?php echo t('all'); ?> (<?php echo $counts['total']; ?>)
            </a>
            <a href="?filter=unread" class="filter-tab <?php echo $filter === 'unread' ? 'active' : ''; ?>">
                <i class="fas fa-envelope"></i>
                <?php echo t('unread', 'Unread'); ?> (<?php echo $counts['unread']; ?>)
            </a>
            <a href="?filter=read" class="filter-tab <?php echo $filter === 'read' ? 'active' : ''; ?>">
                <i class="fas fa-envelope-open"></i>
                <?php echo t('read', 'Read'); ?> (<?php echo $counts['read_count']; ?>)
            </a>
        </div>

        <!-- Notifications List -->
        <?php if (empty($notifications)): ?>
            <div class="card">
                <div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-bell-slash"></i></div>
                    <h3><?php echo t('no_notifications', 'No Notifications'); ?></h3>
                    <p><?php echo $filter === 'unread' ? t('all_notifications_read', 'All notifications are read') : t('no_notifications_yet', 'No notifications yet'); ?></p>
                </div>
            </div>
        <?php else: ?>
            <div>
                <?php foreach ($notifications as $notification): ?>
                    <?php
                    // Get notification type for icon
                    $type = $notification['type'] ?? 'system';
                    $icon_map = [
                        'trade' => 'fa-chart-line',
                        'deposit' => 'fa-arrow-down',
                        'withdrawal' => 'fa-arrow-up',
                        'system' => 'fa-bell',
                        'success' => 'fa-check-circle',
                        'warning' => 'fa-exclamation-triangle',
                        'info' => 'fa-info-circle'
                    ];
                    $icon = $icon_map[$type] ?? 'fa-bell';

                    // Get title and message based on language
                    $title = $lang === 'ar' ? ($notification['title_ar'] ?? $notification['title_en'] ?? '') : ($notification['title_en'] ?? $notification['title_ar'] ?? '');
                    $message = $lang === 'ar' ? ($notification['message_ar'] ?? $notification['message_en'] ?? '') : ($notification['message_en'] ?? $notification['message_ar'] ?? '');
                    ?>
                    <div class="notification-item <?php echo $notification['is_read'] ? '' : 'unread'; ?>">
                        <div class="notification-icon <?php echo htmlspecialchars($type); ?>">
                            <i class="fas <?php echo $icon; ?>"></i>
                        </div>

                        <div class="notification-content">
                            <div class="notification-title"><?php echo htmlspecialchars($title); ?></div>
                            <div class="notification-message"><?php echo htmlspecialchars($message); ?></div>
                            <div class="notification-time">
                                <i class="fas fa-clock"></i>
                                <?php echo time_ago($notification['created_at'], $lang); ?>
                            </div>
                        </div>

                        <div class="notification-actions">
                            <?php if (!$notification['is_read']): ?>
                                <button class="notification-action-btn"
                                        onclick="markAsRead(<?php echo $notification['id']; ?>)"
                                        title="<?php echo t('mark_as_read', 'Mark as read'); ?>">
                                    <i class="fas fa-check"></i>
                                </button>
                            <?php else: ?>
                                <button class="notification-action-btn"
                                        onclick="markAsUnread(<?php echo $notification['id']; ?>)"
                                        title="<?php echo t('mark_as_unread', 'Mark as unread'); ?>">
                                    <i class="fas fa-envelope"></i>
                                </button>
                            <?php endif; ?>
                            <button class="notification-action-btn danger"
                                    onclick="deleteNotification(<?php echo $notification['id']; ?>)"
                                    title="<?php echo t('delete'); ?>">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?filter=<?php echo $filter; ?>&page=<?php echo $page - 1; ?>">
                            <i class="fas fa-chevron-<?php echo is_rtl() ? 'right' : 'left'; ?>"></i>
                            <?php echo t('previous'); ?>
                        </a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="active"><?php echo $i; ?></span>
                        <?php elseif ($i == 1 || $i == $total_pages || abs($i - $page) <= 2): ?>
                            <a href="?filter=<?php echo $filter; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        <?php elseif (abs($i - $page) == 3): ?>
                            <span>...</span>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?filter=<?php echo $filter; ?>&page=<?php echo $page + 1; ?>">
                            <?php echo t('next'); ?>
                            <i class="fas fa-chevron-<?php echo is_rtl() ? 'left' : 'right'; ?>"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <?php render_base_js(); ?>
    <script>
        function markAsRead(id) {
            fetch('notifications.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=mark_read&notification_id=' + id
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    showToast(data.message || '<?php echo t('error_occurred', 'An error occurred'); ?>', 'error');
                }
            })
            .catch(() => showToast('<?php echo t('error_occurred', 'An error occurred'); ?>', 'error'));
        }

        function markAsUnread(id) {
            fetch('notifications.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=mark_unread&notification_id=' + id
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    showToast(data.message || '<?php echo t('error_occurred', 'An error occurred'); ?>', 'error');
                }
            })
            .catch(() => showToast('<?php echo t('error_occurred', 'An error occurred'); ?>', 'error'));
        }

        function markAllRead() {
            if (!confirm('<?php echo t('confirm_mark_all_read', 'Mark all notifications as read?'); ?>')) return;

            fetch('notifications.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=mark_all_read'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    showToast(data.message || '<?php echo t('error_occurred', 'An error occurred'); ?>', 'error');
                }
            })
            .catch(() => showToast('<?php echo t('error_occurred', 'An error occurred'); ?>', 'error'));
        }

        function deleteNotification(id) {
            if (!confirm('<?php echo t('confirm_delete', 'Are you sure you want to delete this?'); ?>')) return;

            fetch('notifications.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=delete&notification_id=' + id
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    showToast(data.message || '<?php echo t('error_occurred', 'An error occurred'); ?>', 'error');
                }
            })
            .catch(() => showToast('<?php echo t('error_occurred', 'An error occurred'); ?>', 'error'));
        }

        function deleteAllRead() {
            if (!confirm('<?php echo t('confirm_delete_all_read', 'Delete all read notifications? This cannot be undone.'); ?>')) return;

            fetch('notifications.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=delete_all_read'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    showToast(data.message || '<?php echo t('error_occurred', 'An error occurred'); ?>', 'error');
                }
            })
            .catch(() => showToast('<?php echo t('error_occurred', 'An error occurred'); ?>', 'error'));
        }
    </script>
</body>
</html>
