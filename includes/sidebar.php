<?php
/**
 * Sidebar Navigation Include
 */

$user = app_get_session_user();
$current_path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');

// Determine base URL based on user role
$is_admin_sidebar = ($user['role'] ?? '') === 'admin';
$base_url = $is_admin_sidebar ? '/hwtires/admin' : '/hwtires/front-desk';
$base_path = trim($base_url, '/');
$sidebar_company_name = 'HW Tires';
$sidebar_logo_path = APP_URL . '/assets/images/logo.svg';
$sidebar_role_label = ($user['role'] ?? '') === 'admin' ? 'Admin/Owner' : 'Front Desk';
$sidebar_user_name = trim((string) ($user['name'] ?? 'User'));
$sidebar_user_initial = strtoupper(substr($sidebar_user_name !== '' ? $sidebar_user_name : 'U', 0, 1));

try {
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('company_name', 'company_logo')");
    $stmt->execute();

    foreach ($stmt->fetchAll() as $setting) {
        if ($setting['setting_key'] === 'company_name' && trim((string) $setting['setting_value']) !== '') {
            $sidebar_company_name = trim((string) $setting['setting_value']);
        }

        if ($setting['setting_key'] === 'company_logo' && trim((string) $setting['setting_value']) !== '') {
            $candidate_logo = ltrim((string) $setting['setting_value'], '/');
            if ($candidate_logo !== '' && is_file(dirname(__DIR__) . '/' . $candidate_logo)) {
                $sidebar_logo_path = APP_URL . '/' . $candidate_logo;
            } else {
                $sidebar_logo_path = APP_URL . '/assets/images/logo.svg';
            }
        }
    }
} catch (Exception $e) {
    $sidebar_company_name = 'HW Tires';
    $sidebar_logo_path = APP_URL . '/assets/images/logo.svg';
}

if (!function_exists('sidebar_is_active')) {
    function sidebar_is_active($section) {
        global $current_path, $base_path;

        if ($section === 'dashboard') {
            return $current_path === $base_path || $current_path === $base_path . '/index.php';
        }

        return strpos($current_path, $base_path . '/' . $section) === 0;
    }
}

if (!function_exists('sidebar_short_date')) {
    function sidebar_short_date($date) {
        return !empty($date) ? date('Y-m-d', strtotime($date)) : '-';
    }
}

$header_notifications = [];
$header_notification_count = 0;
$sidebar_branch_shortcuts = [];

try {
    $is_admin_header = ($user['role'] ?? '') === 'admin';
    $user_branch_id = (int) ($user['branch_id'] ?? 0);

    $low_stock_where = "i.status = 'active' AND i.quantity <= i.reorder_level";
    $low_stock_params = [];

    if (!$is_admin_header) {
        $low_stock_where .= " AND i.branch_id = ?";
        $low_stock_params[] = $user_branch_id;
    }

    if ($is_admin_header || can_access_inventory($user_branch_id)) {
        $low_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_items i WHERE $low_stock_where");
        $low_count_stmt->execute($low_stock_params);
        $low_stock_count = (int) $low_count_stmt->fetchColumn();
        $header_notification_count += $low_stock_count;

        if ($low_stock_count > 0) {
            $low_items_stmt = $pdo->prepare("
                SELECT i.item_name, i.quantity, i.reorder_level, b.name AS branch_name
                FROM inventory_items i
                LEFT JOIN branches b ON b.id = i.branch_id
                WHERE $low_stock_where
                ORDER BY i.quantity ASC, i.item_name ASC
                LIMIT 3
            ");
            $low_items_stmt->execute($low_stock_params);
            $low_items = $low_items_stmt->fetchAll();
            $low_title = $low_stock_count . ' low stock ' . ($low_stock_count === 1 ? 'item' : 'items');
            $low_detail = empty($low_items)
                ? 'Review inventory records'
                : implode(', ', array_map(static function ($item) {
                    return ($item['item_name'] ?? 'Item') . ' (' . (int) ($item['quantity'] ?? 0) . ' left)';
                }, $low_items));
            $header_notifications[] = [
                'id' => 'low-stock-' . ($is_admin_header ? 'admin' : 'branch-' . $user_branch_id),
                'count' => $low_stock_count,
                'type' => 'danger',
                'icon' => 'fas fa-exclamation',
                'title' => $low_title,
                'detail' => $low_detail,
                'href' => $base_url . '/tire-inventory/?view=low_stock#inventory-records',
            ];
        }
    }

    try {
        $transfer_notification_count = 0;
        $transfer_latest = null;
        $transfer_notification_ids = '';

        if ($is_admin_header) {
            $transfer_count_stmt = $pdo->prepare("
                SELECT COUNT(*) AS total, GROUP_CONCAT(tn.id ORDER BY tn.created_at DESC) AS ids
                FROM transfer_notifications tn
                LEFT JOIN inter_branch_transfer_requests tr ON tr.id = tn.transfer_request_id
                WHERE tn.is_read = 0
                  AND tn.user_id = ?
                  AND (
                      tn.transfer_request_id IS NULL
                      OR tr.status IN ('pending', 'approved', 'shipped', 'received')
                  )
            ");
            $transfer_count_stmt->execute([(int) ($user['id'] ?? 0)]);
            $transfer_count_row = $transfer_count_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $transfer_notification_count = (int) ($transfer_count_row['total'] ?? 0);
            $transfer_notification_ids = (string) ($transfer_count_row['ids'] ?? '');

            if ($transfer_notification_count > 0) {
                $transfer_latest_stmt = $pdo->prepare("
                    SELECT
                        tn.id,
                        tn.title,
                        tn.message,
                        tn.type,
                        tn.action_url,
                        tr.status,
                        tr.item_name,
                        tr.requested_quantity,
                        rb.name AS requesting_branch_name
                    FROM transfer_notifications tn
                    LEFT JOIN inter_branch_transfer_requests tr ON tr.id = tn.transfer_request_id
                    LEFT JOIN branches rb ON rb.id = tr.requesting_branch_id
                    WHERE tn.is_read = 0
                      AND tn.user_id = ?
                      AND (
                          tn.transfer_request_id IS NULL
                          OR tr.status IN ('pending', 'approved', 'shipped', 'received')
                      )
                    ORDER BY tn.created_at DESC
                    LIMIT 1
                ");
                $transfer_latest_stmt->execute([(int) ($user['id'] ?? 0)]);
                $transfer_latest = $transfer_latest_stmt->fetch();
            }
        } elseif ($user_branch_id > 0) {
            $transfer_count_stmt = $pdo->prepare("
                SELECT COUNT(*) AS total, GROUP_CONCAT(tn.id ORDER BY tn.created_at DESC) AS ids
                FROM transfer_notifications tn
                LEFT JOIN inter_branch_transfer_requests tr ON tr.id = tn.transfer_request_id
                WHERE tn.is_read = 0
                  AND (tn.user_id = ? OR (tn.user_id IS NULL AND tn.branch_id = ?))
                  AND (
                      tn.transfer_request_id IS NULL
                      OR tr.status IN ('pending', 'approved', 'shipped', 'received')
                  )
            ");
            $transfer_count_stmt->execute([(int) ($user['id'] ?? 0), $user_branch_id]);
            $transfer_count_row = $transfer_count_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $transfer_notification_count = (int) ($transfer_count_row['total'] ?? 0);
            $transfer_notification_ids = (string) ($transfer_count_row['ids'] ?? '');

            if ($transfer_notification_count > 0) {
                $transfer_latest_stmt = $pdo->prepare("
                    SELECT
                        tn.id,
                        tn.title,
                        tn.message,
                        tn.type,
                        tn.action_url,
                        tr.status,
                        tr.item_name,
                        tr.requested_quantity,
                        rb.name AS requesting_branch_name
                    FROM transfer_notifications tn
                    LEFT JOIN inter_branch_transfer_requests tr ON tr.id = tn.transfer_request_id
                    LEFT JOIN branches rb ON rb.id = tr.requesting_branch_id
                    WHERE tn.is_read = 0
                      AND (tn.user_id = ? OR (tn.user_id IS NULL AND tn.branch_id = ?))
                      AND (
                          tn.transfer_request_id IS NULL
                          OR tr.status IN ('pending', 'approved', 'shipped', 'received')
                      )
                    ORDER BY tn.created_at DESC
                    LIMIT 1
                ");
                $transfer_latest_stmt->execute([(int) ($user['id'] ?? 0), $user_branch_id]);
                $transfer_latest = $transfer_latest_stmt->fetch();
            }
        }

        if ($transfer_notification_count > 0) {
            $header_notification_count += $transfer_notification_count;
            $header_notifications[] = [
                'id' => $is_admin_header ? 'admin-transfer-notifications' : ('incoming-transfer-requests-branch-' . $user_branch_id),
                'count' => $transfer_notification_count,
                'type' => $transfer_latest['type'] ?? 'warning',
                'icon' => ($transfer_latest['type'] ?? '') === 'warning' ? 'fas fa-triangle-exclamation' : 'fas fa-right-left',
                'title' => $transfer_notification_count === 1
                    ? ($transfer_latest['title'] ?? ($is_admin_header ? 'Stock notification' : 'Transfer notification'))
                    : ($is_admin_header ? $transfer_notification_count . ' stock notifications' : $transfer_notification_count . ' transfer notifications'),
                'detail' => $transfer_latest['message'] ?? 'Review branch stock updates',
                'href' => !empty($transfer_latest['action_url']) ? $transfer_latest['action_url'] : ($base_url . ($is_admin_header ? '/tire-inventory/transactions.php' : '/tire-inventory/#requested-items')),
                'db_ids' => $transfer_notification_ids,
            ];
        }
    } catch (Exception $transfer_notification_error) {
        error_log('Sidebar transfer notification error: ' . $transfer_notification_error->getMessage());
    }

    if ($is_admin_header) {
        $movement_count_stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM audit_logs al
            INNER JOIN users u ON u.id = al.user_id
            WHERE al.table_name = 'inventory_items'
              AND al.action IN ('stock_in', 'inventory_transfer')
              AND u.role = 'front-desk'
              AND al.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $movement_count_stmt->execute();
        $frontdesk_inventory_movements = (int) $movement_count_stmt->fetchColumn();
        $header_notification_count += $frontdesk_inventory_movements;

        if ($frontdesk_inventory_movements > 0) {
            $movement_latest_stmt = $pdo->prepare("
                SELECT
                    al.action,
                    al.created_at,
                    u.name AS user_name,
                    i.item_name,
                    b.name AS branch_name
                FROM audit_logs al
                INNER JOIN users u ON u.id = al.user_id
                LEFT JOIN inventory_items i ON i.id = al.record_id
                LEFT JOIN branches b ON b.id = i.branch_id
                WHERE al.table_name = 'inventory_items'
                  AND al.action IN ('stock_in', 'inventory_transfer')
                  AND u.role = 'front-desk'
                  AND al.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                ORDER BY al.created_at DESC
                LIMIT 1
            ");
            $movement_latest_stmt->execute();
            $latest_movement = $movement_latest_stmt->fetch() ?: [];
            $movement_action = ($latest_movement['action'] ?? '') === 'inventory_transfer'
                ? 'transferred stock'
                : 'added stock';
            $movement_detail = trim(($latest_movement['user_name'] ?? 'Front desk') . ' ' . $movement_action);

            if (!empty($latest_movement['item_name'])) {
                $movement_detail .= ' for ' . $latest_movement['item_name'];
            }

            if (!empty($latest_movement['branch_name'])) {
                $movement_detail .= ' (' . sidebar_short_date($latest_movement['created_at'] ?? null) . ')';
            }

            $header_notifications[] = [
                'id' => 'frontdesk-inventory-movement-admin',
                'count' => $frontdesk_inventory_movements,
                'type' => 'info',
                'icon' => 'fas fa-right-left',
                'title' => $frontdesk_inventory_movements . ' front desk inventory ' . ($frontdesk_inventory_movements === 1 ? 'update' : 'updates'),
                'detail' => $movement_detail,
                'href' => $base_url . '/tire-inventory/transactions.php',
            ];
        }
    }

    $quote_where = "status = 'pending'";
    $quote_params = [];

    if (!$is_admin_header) {
        $quote_where .= " AND branch_id = ?";
        $quote_params[] = $user_branch_id;
    }

    $quote_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM quotations WHERE $quote_where");
    $quote_count_stmt->execute($quote_params);
    $pending_quotes = (int) $quote_count_stmt->fetchColumn();
    $header_notification_count += $pending_quotes;

    if ($pending_quotes > 0) {
            $header_notifications[] = [
                'id' => 'pending-quotations-' . ($is_admin_header ? 'admin' : 'branch-' . $user_branch_id),
                'count' => $pending_quotes,
                'type' => 'warning',
                'icon' => 'far fa-file-lines',
                'title' => $pending_quotes . ' pending ' . ($pending_quotes === 1 ? 'service operation' : 'service operations'),
                'detail' => 'Review and update customer quotation requests',
                'href' => $base_url . '/quotations/?status=pending&date_scope=all#quotation-records',
            ];
        }

    $job_where = "status IN ('waiting', 'in-progress')";
    $job_params = [];

    if (!$is_admin_header) {
        $job_where .= " AND branch_id = ?";
        $job_params[] = $user_branch_id;
    }

    $job_count_stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'waiting' THEN 1 ELSE 0 END) AS waiting,
            SUM(CASE WHEN status = 'in-progress' THEN 1 ELSE 0 END) AS in_progress
        FROM job_orders
        WHERE $job_where
    ");
    $job_count_stmt->execute($job_params);
    $job_counts = $job_count_stmt->fetch() ?: ['total' => 0, 'waiting' => 0, 'in_progress' => 0];
    $active_jobs = (int) ($job_counts['total'] ?? 0);
    $header_notification_count += $active_jobs;

    if ($active_jobs > 0) {
        $header_notifications[] = [
            'id' => 'active-job-orders-' . ($is_admin_header ? 'admin' : 'branch-' . $user_branch_id),
            'count' => $active_jobs,
            'type' => 'info',
            'icon' => 'fas fa-clipboard-list',
            'title' => $active_jobs . ' active ' . ($active_jobs === 1 ? 'job order' : 'job orders'),
            'detail' => (int) ($job_counts['waiting'] ?? 0) . ' waiting, ' . (int) ($job_counts['in_progress'] ?? 0) . ' in progress',
            'href' => $is_admin_header
                ? $base_url . '/job-orders/?date_scope=all'
                : $base_url . '/service-status/?status=active&date_scope=all#service-records',
        ];
    }
} catch (Exception $e) {
    $header_notifications = [];
    $header_notification_count = 0;
}

if (($user['role'] ?? '') === 'admin') {
    try {
        $sidebar_branch_stmt = $pdo->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY id ASC");
        $sidebar_branch_shortcuts = $sidebar_branch_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        $sidebar_branch_shortcuts = [];
    }
}
?>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <img src="<?php echo esc_attr($sidebar_logo_path); ?>" alt="<?php echo esc_attr($sidebar_company_name); ?>">
        <div>
            <h6><?php echo esc_html($sidebar_company_name); ?></h6>
            <span>Management</span>
        </div>
    </div>

    <a class="sidebar-user" href="<?php echo APP_URL; ?>/profile.php">
        <span class="sidebar-avatar"><?php echo esc_html($sidebar_user_initial); ?></span>
        <div>
            <strong><?php echo esc_html($sidebar_user_name); ?></strong>
            <span><?php echo esc_html($sidebar_role_label); ?></span>
        </div>
    </a>

    <nav class="sidebar-nav" aria-label="Main navigation">
        <ul class="sidebar-menu">
            <!-- Dashboard -->
            <li class="sidebar-menu-item <?php echo sidebar_is_active('dashboard') ? 'active' : ''; ?>">
                <a href="<?php echo $base_url; ?>/">
                    <i class="fas fa-table-cells-large"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <!-- Common Menu Items (All Users) -->
            <li class="sidebar-menu-item <?php echo (sidebar_is_active('customers') || sidebar_is_active('vehicles')) ? 'active' : ''; ?>">
                <a href="<?php echo $base_url; ?>/customers/">
                    <i class="fas fa-car-side"></i>
                    <span>Customer and Vehicle</span>
                </a>
            </li>

            <li class="sidebar-menu-item <?php echo sidebar_is_active('quotations') ? 'active' : ''; ?>">
                <a href="<?php echo $base_url; ?>/quotations/">
                    <i class="far fa-file-lines"></i>
                    <span><?php echo $is_admin_sidebar ? 'Historical Transactions' : 'Service Operations'; ?></span>
                </a>
            </li>

            <?php if ($is_admin_sidebar): ?>
                <li class="sidebar-menu-item <?php echo (sidebar_is_active('job-orders') || sidebar_is_active('service-status')) ? 'active' : ''; ?>">
                    <a href="<?php echo $base_url; ?>/job-orders/">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Job Order</span>
                    </a>
                </li>
            <?php else: ?>
                <li class="sidebar-menu-item <?php echo sidebar_is_active('job-orders') ? 'active' : ''; ?>">
                    <a href="<?php echo $base_url; ?>/job-orders/">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Job Orders</span>
                    </a>
                </li>

                <li class="sidebar-menu-item <?php echo sidebar_is_active('service-status') ? 'active' : ''; ?>">
                    <a href="<?php echo $base_url; ?>/service-status/">
                        <i class="fas fa-wave-square"></i>
                        <span>Service Status</span>
                    </a>
                </li>
            <?php endif; ?>

            <!-- Inventory -->
            <?php if ($user['role'] === 'admin' || ($user['role'] === 'front-desk' && can_access_inventory($user['branch_id']))): ?>
            <li class="sidebar-menu-item <?php echo sidebar_is_active('tire-inventory') ? 'active' : ''; ?>">
                <a href="<?php echo $base_url; ?>/tire-inventory/">
                    <i class="fas fa-cube"></i>
                    <span>Inventory</span>
                </a>
            </li>
            <?php if ($user['role'] === 'admin'): ?>
            <li class="sidebar-menu-item <?php echo sidebar_is_active('transfers') ? 'active' : ''; ?>">
                <a href="<?php echo $base_url; ?>/transfers/">
                    <i class="fas fa-arrow-right-arrow-left"></i>
                    <span>Branch Transfers</span>
                </a>
            </li>
            <?php endif; ?>
            <?php if ($user['role'] === 'front-desk'): ?>
            <li class="sidebar-menu-item <?php echo sidebar_is_active('forecasting') ? 'active' : ''; ?>">
                <a href="<?php echo $base_url; ?>/forecasting/">
                    <i class="fas fa-arrow-trend-up"></i>
                    <span>Forecasting</span>
                </a>
            </li>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ($user['role'] === 'front-desk'): ?>
            <li class="sidebar-menu-item <?php echo sidebar_is_active('reports') ? 'active' : ''; ?>">
                <a href="<?php echo $base_url; ?>/reports/">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reports</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- Admin Only Menu Items -->
            <?php if ($user['role'] === 'admin'): ?>
                <li class="sidebar-menu-item <?php echo sidebar_is_active('forecasting') ? 'active' : ''; ?>">
                    <a href="<?php echo $base_url; ?>/forecasting/">
                        <i class="fas fa-arrow-trend-up"></i>
                        <span>Forecasting</span>
                    </a>
                </li>

                <li class="sidebar-menu-item <?php echo sidebar_is_active('reports') ? 'active' : ''; ?>">
                    <a href="<?php echo $base_url; ?>/reports/">
                        <i class="fas fa-chart-bar"></i>
                        <span>Reports</span>
                    </a>
                </li>

                <li class="sidebar-menu-item <?php echo sidebar_is_active('services') ? 'active' : ''; ?>">
                    <a href="<?php echo $base_url; ?>/services/">
                        <i class="fas fa-screwdriver-wrench"></i>
                        <span>Services</span>
                    </a>
                </li>

                <li class="sidebar-menu-item <?php echo sidebar_is_active('users') ? 'active' : ''; ?>">
                    <a href="<?php echo $base_url; ?>/users/">
                        <i class="fas fa-user-gear"></i>
                        <span>User Management</span>
                    </a>
                </li>

                <li class="sidebar-menu-item <?php echo sidebar_is_active('branch-management') ? 'active' : ''; ?>">
                    <a href="<?php echo $base_url; ?>/branch-management/">
                        <i class="fas fa-location-dot"></i>
                        <span>Branch Management</span>
                    </a>
                </li>

                <li class="sidebar-menu-item <?php echo sidebar_is_active('audit-trail') ? 'active' : ''; ?>">
                    <a href="<?php echo $base_url; ?>/audit-trail/">
                        <i class="fas fa-shield-halved"></i>
                        <span>Audit Trail</span>
                    </a>
                </li>

                <li class="sidebar-menu-item <?php echo sidebar_is_active('settings') ? 'active' : ''; ?>">
                    <a href="<?php echo $base_url; ?>/settings/">
                        <i class="fas fa-gear"></i>
                        <span>Settings</span>
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </nav>

    <div class="sidebar-logout">
        <a href="<?php echo APP_URL; ?>/logout.php" onclick="return confirm('Are you sure you want to logout?')">
            <i class="fas fa-arrow-right-from-bracket"></i>
            <span>Logout</span>
        </a>
    </div>
</aside>

<!-- Top Navigation Header -->
<div class="main-content">
    <header class="header">
        <div class="header-left">
            <button class="btn btn-link" onclick="toggleSidebar()" id="sidebarToggle" style="display: none; color: var(--text-dark);">
                <i class="fas fa-bars fa-lg"></i>
            </button>
            <div class="header-date">
                <span id="current-date"><?php echo date('l, F j, Y'); ?></span>
            </div>
        </div>

        <div class="header-right">
            <div class="dropdown notification-dropdown">
                <button class="notification-bell dropdown-toggle" type="button" id="notificationDropdown" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
                    <i class="fas fa-bell"></i>
                    <?php if ($header_notification_count > 0): ?>
                        <span class="notification-badge" data-notification-badge><?php echo $header_notification_count > 99 ? '99+' : (int) $header_notification_count; ?></span>
                    <?php endif; ?>
                </button>
                <div class="dropdown-menu dropdown-menu-end notification-menu" aria-labelledby="notificationDropdown">
                    <div class="notification-menu-header">
                        <strong>Notifications</strong>
                        <span data-notification-active-count><?php echo (int) $header_notification_count; ?> unread</span>
                    </div>
                    <?php if (empty($header_notifications)): ?>
                        <div class="notification-empty">
                            <i class="far fa-circle-check"></i>
                            <span>No notifications</span>
                        </div>
                    <?php else: ?>
                        <div class="notification-list">
                            <?php foreach ($header_notifications as $notification): ?>
                                <div class="notification-row notification-unread"
                                     data-notification-id="<?php echo esc_attr($notification['id'] ?? md5($notification['title'] . $notification['href'])); ?>"
                                     data-notification-count="<?php echo (int) ($notification['count'] ?? 1); ?>"
                                     <?php if (!empty($notification['db_ids'])): ?>
                                         data-notification-db-ids="<?php echo esc_attr($notification['db_ids']); ?>"
                                     <?php endif; ?>>
                                    <a href="<?php echo esc_attr($notification['href']); ?>" class="notification-item notification-<?php echo esc_attr($notification['type']); ?>">
                                        <span class="notification-item-icon">
                                            <i class="<?php echo esc_attr($notification['icon']); ?>"></i>
                                        </span>
                                        <span class="notification-item-body">
                                            <strong><?php echo esc_html($notification['title']); ?></strong>
                                            <em><?php echo esc_html($notification['detail']); ?></em>
                                        </span>
                                    </a>
                                    <button type="button" class="notification-dismiss" aria-label="Dismiss notification">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <button type="button" class="notification-read-toggle">Mark as read</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="notification-empty notification-empty-js" hidden>
                            <i class="far fa-circle-check"></i>
                            <span>No notifications</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- User Profile Dropdown -->
            <div class="dropdown">
                <button class="btn btn-link dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false" style="color: var(--text-dark); text-decoration: none;">
                    <i class="fas fa-user-circle"></i>
                    <span><?php echo esc_html($user['name']); ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="<?php echo APP_URL; ?>/profile.php"><i class="fas fa-user"></i> Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="<?php echo APP_URL; ?>/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </header>

    <!-- Main Content Area -->
    <div class="content">
