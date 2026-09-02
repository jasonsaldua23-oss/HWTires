<?php
/**
 * Admin Dashboard
 */

require_once '../includes/config.php';
session_name(SESSION_NAME);
session_start();

// Check authentication and role
if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$user = app_get_session_user();

// Ensure user data is valid array
if (!is_array($user)) {
    redirect('/hwtires/index.php');
}

// Check if user is admin
if (($user['role'] ?? null) !== 'admin') {
    redirect('/hwtires/front-desk/');
}

$page_title = 'Admin Dashboard';

if (!function_exists('admin_dashboard_branch_class')) {
    function admin_dashboard_branch_class($branch_id) {
        $branch_id = (int) $branch_id;
        if ($branch_id <= 0) {
            return 'customer-branch-empty';
        }

        return 'customer-branch-' . ((($branch_id - 1) % 3) + 1);
    }
}

try {
    // Get KPI data
    $total_customers = $pdo->query("SELECT COUNT(*) as count FROM customers WHERE status = 'active'")->fetch()['count'];
    $total_vehicles = $pdo->query("SELECT COUNT(*) as count FROM vehicles WHERE status = 'active'")->fetch()['count'];
    $total_quotations = $pdo->query("SELECT COUNT(*) as count FROM quotations WHERE status <> 'archived'")->fetch()['count'];
    $total_job_orders = $pdo->query("SELECT COUNT(*) as count FROM job_orders WHERE status NOT IN ('archived', 'cancelled')")->fetch()['count'];

    // Get inventory alert data for dashboard display
    $critical_items = (int) $pdo->query("SELECT COUNT(*) as count FROM inventory_items WHERE status = 'active' AND quantity <= reorder_level")->fetch()['count'];
    $low_stock_items_count = (int) $pdo->query("SELECT COUNT(*) as count FROM inventory_items WHERE status = 'active' AND quantity > reorder_level AND quantity <= (reorder_level * 2)")->fetch()['count'];
    $inventory_alert_items = $pdo->query("SELECT item_name, category, brand, size, description, quantity, reorder_level
                                         FROM inventory_items
                                         WHERE status = 'active' AND quantity <= reorder_level
                                         ORDER BY quantity ASC, item_name ASC
                                         LIMIT 6")->fetchAll();

    // Get recent service operations
    $recent_quotations = $pdo->query("SELECT q.*, c.name as customer_name, b.name as branch_name
                                      FROM quotations q
                                      JOIN customers c ON q.customer_id = c.id
                                      LEFT JOIN branches b ON q.branch_id = b.id
                                      WHERE q.status <> 'archived'
                                      ORDER BY COALESCE(q.quotation_date, DATE(q.created_at)) DESC, q.id DESC
                                      LIMIT 3")->fetchAll();

    // Get recent job orders
    $recent_job_orders = $pdo->query("SELECT j.*, c.name as customer_name, b.name as branch_name, v.plate_number
                                      FROM job_orders j
                                      JOIN customers c ON j.customer_id = c.id
                                      LEFT JOIN branches b ON j.branch_id = b.id
                                      LEFT JOIN vehicles v ON j.vehicle_id = v.id
                                      WHERE j.status NOT IN ('archived', 'cancelled')
                                      ORDER BY COALESCE(j.job_date, DATE(j.created_at)) DESC, j.id DESC
                                      LIMIT 3")->fetchAll();

    // Get branch overview
    $branches = $pdo->query("SELECT b.*, COUNT(DISTINCT jo.id) as job_count
                             FROM branches b
                             LEFT JOIN job_orders jo ON b.id = jo.branch_id AND jo.status NOT IN ('archived', 'cancelled')
                             WHERE b.status = 'active'
                             GROUP BY b.id
                             ORDER BY b.id ASC")->fetchAll();

} catch (Exception $e) {
    error_log('Dashboard error: ' . $e->getMessage());
    $total_customers = 0;
    $total_vehicles = 0;
    $total_quotations = 0;
    $total_job_orders = 0;
    $critical_items = 0;
    $low_stock_items_count = 0;
    $inventory_alert_items = [];
    $recent_quotations = [];
    $recent_job_orders = [];
    $branches = [];
}

?>

<?php require_once '../includes/header.php'; ?>
<?php require_once '../includes/sidebar.php'; ?>

<div class="admin-dashboard">
    <section class="admin-dashboard-hero">
        <div>
            <h1>Admin Dashboard</h1>
            <p>Welcome back! Here's an overview of all branches.</p>
        </div>
        <a href="/hwtires/admin/tire-inventory/" class="dashboard-notification" title="Inventory alerts">
            <i class="far fa-bell"></i>
            <?php if ($critical_items > 0): ?>
                <span><?php echo $critical_items; ?></span>
            <?php endif; ?>
        </a>
    </section>

    <!-- Flash Messages -->
    <?php
    $flash_message = get_flash_message();
    if ($flash_message):
    ?>
        <div class="alert alert-<?php echo esc_attr($flash_message['type']); ?> alert-dismissible fade show" role="alert">
            <?php echo esc_html($flash_message['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <section class="dashboard-alert-summary dashboard-alert-compact">
        <div class="dashboard-alert-copy">
            <div class="dashboard-alert-icon">
                <i class="fas fa-exclamation"></i>
            </div>
            <div>
                <h2>Inventory Alerts</h2>
                <?php if ($critical_items > 0 || $low_stock_items_count > 0): ?>
                    <p>
                        <strong><?php echo $critical_items; ?> critical items need immediate attention</strong>
                        <?php if ($low_stock_items_count > 0): ?>
                            <span> &bull; <?php echo $low_stock_items_count; ?> items running low</span>
                        <?php endif; ?>
                    </p>
                <?php else: ?>
                    <p><strong>No critical inventory alerts right now</strong></p>
                <?php endif; ?>
            </div>
        </div>
        <a href="/hwtires/admin/forecasting/" class="dashboard-alert-action">View Forecasting</a>
    </section>

    <section class="dashboard-kpi-grid">
        <article class="dashboard-kpi-card">
            <div>
                <div class="kpi-label">Total Customers</div>
                <div class="kpi-value"><?php echo $total_customers; ?></div>
            </div>
            <div class="dashboard-kpi-icon icon-cyan">
                <i class="fas fa-users"></i>
            </div>
        </article>
        <article class="dashboard-kpi-card">
            <div>
                <div class="kpi-label">Total Vehicles</div>
                <div class="kpi-value"><?php echo $total_vehicles; ?></div>
            </div>
            <div class="dashboard-kpi-icon icon-green">
                <i class="fas fa-car-side"></i>
            </div>
        </article>
        <article class="dashboard-kpi-card">
            <div>
                <div class="kpi-label">Total Service Operations</div>
                <div class="kpi-value"><?php echo $total_quotations; ?></div>
            </div>
            <div class="dashboard-kpi-icon icon-purple">
                <i class="fas fa-file-invoice"></i>
            </div>
        </article>
        <article class="dashboard-kpi-card">
            <div>
                <div class="kpi-label">Total Job Orders</div>
                <div class="kpi-value"><?php echo $total_job_orders; ?></div>
            </div>
            <div class="dashboard-kpi-icon icon-orange">
                <i class="fas fa-clipboard-list"></i>
            </div>
        </article>
    </section>

    <section class="dashboard-panel branch-overview-panel dashboard-table-panel">
        <div class="dashboard-panel-header">
            <h2>Branch Overview</h2>
            <span><?php echo count($branches); ?> active branches</span>
        </div>
        <div class="dashboard-branch-table-wrap">
            <?php if (empty($branches)): ?>
                <div class="dashboard-empty-state">No active branches found.</div>
            <?php else: ?>
                <table class="dashboard-branch-table">
                    <thead>
                        <tr>
                            <th>Branch</th>
                            <th>Inventory</th>
                            <th>Job Orders</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($branches as $branch): ?>
                            <?php $branch_label = app_branch_label($branch['name'] ?? '', 'Branch'); ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($branch_label); ?></strong>
                                </td>
                                <td>Enabled</td>
                                <td>
                                    <strong><?php echo (int) ($branch['job_count'] ?? 0); ?></strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>

    <details class="dashboard-inventory-panel dashboard-alert-details">
        <summary>
            <div class="dashboard-panel-title">
                <span class="dashboard-alert-icon">
                    <i class="fas fa-exclamation"></i>
                </span>
                <div>
                    <h2>Critical Inventory Items</h2>
                    <small><?php echo count($inventory_alert_items); ?> items shown from current alerts</small>
                </div>
            </div>
            <span class="dashboard-details-toggle">Show Items</span>
        </summary>
        <div class="dashboard-inventory-list">
            <?php if (empty($inventory_alert_items)): ?>
                <div class="dashboard-empty-state">No low stock items found.</div>
            <?php else: ?>
                <?php foreach ($inventory_alert_items as $item): ?>
                    <?php
                    $category = strtolower($item['category'] ?? 'part');
                    $descriptor = $item['size'] ?: ($item['description'] ?? '');
                    ?>
                    <article class="dashboard-inventory-item">
                        <div>
                            <h3><?php echo esc_html(app_display_item_name($item['item_name'], $category)); ?></h3>
                            <p>
                                <?php echo esc_html($item['brand'] ?: 'Unbranded'); ?>
                                <?php if (!empty($descriptor)): ?>
                                    &bull; <?php echo esc_html($descriptor); ?>
                                <?php endif; ?>
                                <span class="inventory-category category-<?php echo esc_attr($category); ?>">
                                    <?php echo esc_html(ucfirst($category)); ?>
                                </span>
                            </p>
                        </div>
                        <div class="dashboard-inventory-status">
                            <strong>Low Stock</strong>
                            <span>Qty: <?php echo (int) $item['quantity']; ?> (Reorder: <?php echo (int) $item['reorder_level']; ?>)</span>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </details>

    <section class="dashboard-activity-grid">
        <article class="dashboard-panel dashboard-activity-panel">
            <h2>Recent Service Operations</h2>
            <div class="dashboard-activity-list">
                <?php if (empty($recent_quotations)): ?>
                    <div class="dashboard-empty-state">No service operations found.</div>
                <?php else: ?>
                    <?php foreach ($recent_quotations as $q): ?>
                        <?php
                        $quotation_status = strtolower(str_replace(' ', '-', $q['status'] ?? 'pending'));
                        $quotation_branch = app_branch_label($q['branch_name'] ?? '', 'Branch');
                        ?>
                        <a href="/hwtires/admin/quotations/view.php?id=<?php echo (int) $q['id']; ?>" class="dashboard-activity-item">
                            <div>
                                <h3><?php echo esc_html($q['customer_name']); ?></h3>
                                <span class="customer-branch-pill <?php echo esc_attr(admin_dashboard_branch_class($q['branch_id'] ?? 0)); ?>">
                                    <?php echo esc_html($quotation_branch); ?>
                                </span>
                            </div>
                            <div class="dashboard-activity-meta">
                                <span class="status-pill status-<?php echo esc_attr($quotation_status); ?>">
                                    <?php echo esc_html(ucfirst($q['status'] ?? 'Pending')); ?>
                                </span>
                                <strong>&#8369;<?php echo number_format((float) $q['total_amount'], 0); ?></strong>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>

        <article class="dashboard-panel dashboard-activity-panel">
            <h2>Recent Job Orders</h2>
            <div class="dashboard-activity-list">
                <?php if (empty($recent_job_orders)): ?>
                    <div class="dashboard-empty-state">No job orders found.</div>
                <?php else: ?>
                    <?php foreach ($recent_job_orders as $j): ?>
                        <?php
                        $job_status = strtolower(str_replace(' ', '-', $j['status'] ?? 'waiting'));
                        $job_status_label = $job_status === 'waiting' ? 'Pending' : ucwords(str_replace('-', ' ', $job_status));
                        $job_branch = app_branch_label($j['branch_name'] ?? '', 'Branch');
                        ?>
                        <a href="/hwtires/admin/job-orders/view.php?id=<?php echo (int) $j['id']; ?>" class="dashboard-activity-item">
                            <div>
                                <h3><?php echo esc_html($j['customer_name']); ?></h3>
                                <span class="customer-branch-pill <?php echo esc_attr(admin_dashboard_branch_class($j['branch_id'] ?? 0)); ?>">
                                    <?php echo esc_html($job_branch); ?>
                                </span>
                            </div>
                            <div class="dashboard-activity-meta">
                                <span class="status-pill status-<?php echo esc_attr($job_status); ?>">
                                    <?php echo esc_html($job_status_label); ?>
                                </span>
                                <span><?php echo esc_html(($j['assigned_technician_name'] ?? '') ?: 'Unassigned'); ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>
    </section>
</div>

<?php require_once '../includes/footer.php'; ?>
