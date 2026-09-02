<?php
/**
 * Front Desk Dashboard
 */

require_once '../includes/config.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$user = app_get_session_user();
if (!is_array($user)) {
    redirect('/hwtires/index.php');
}

$page_title = 'Front Desk Dashboard';
$branch_id = intval($user['branch_id'] ?? 0);
$branch = ['name' => $branch_id > 0 ? 'Branch ' . $branch_id : 'Front Desk'];
$todays_job_orders = [];
$todays_jobs_count = 0;
$recent_quotations = [];
$recent_customer_visits = [];
$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end = date('Y-m-d', strtotime('sunday this week'));

if (!function_exists('front_dashboard_status_label')) {
    function front_dashboard_status_label($status) {
        $status = trim((string) $status);
        return $status === '' ? 'Pending' : ucwords(str_replace('-', ' ', $status));
    }
}

if (!function_exists('front_dashboard_status_class')) {
    function front_dashboard_status_class($status) {
        $status = strtolower(trim((string) $status));
        return in_array($status, ['approved', 'completed'], true) ? 'status-success'
            : (in_array($status, ['pending', 'waiting'], true) ? 'status-warning'
            : ($status === 'rejected' || $status === 'cancelled' ? 'status-danger' : 'status-info'));
    }
}

if (!function_exists('front_dashboard_services')) {
    function front_dashboard_services($services, $fallback = 'No services listed') {
        $services = trim((string) $services);
        return $services !== '' ? $services : $fallback;
    }
}

if (!function_exists('front_dashboard_short_date')) {
    function front_dashboard_short_date($date) {
        return empty($date) ? '-' : date('Y-m-d', strtotime($date));
    }
}

if (!function_exists('front_dashboard_currency')) {
    function front_dashboard_currency($amount) {
        $amount = (float) $amount;
        $decimals = floor($amount) == $amount ? 0 : 2;
        return '&#8369;' . number_format($amount, $decimals);
    }
}

if (!function_exists('front_dashboard_branch_class')) {
    function front_dashboard_branch_class($branch_id) {
        $branch_id = (int) $branch_id;
        if ($branch_id <= 0) {
            return 'customer-branch-empty';
        }

        return 'customer-branch-' . ((($branch_id - 1) % 3) + 1);
    }
}

try {
    if ($branch_id <= 0) {
        throw new Exception('No branch is assigned to this front desk user.');
    }

    $branch_stmt = $pdo->prepare("SELECT id, name FROM branches WHERE id = ? LIMIT 1");
    $branch_stmt->execute([$branch_id]);
    $branch_row = $branch_stmt->fetch();
    if ($branch_row) {
        $branch = $branch_row;
    }

    $count_stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM job_orders
        WHERE branch_id = ?
          AND status IN ('waiting', 'pending', 'in-progress')
            AND DATE(COALESCE(job_date, created_at)) BETWEEN ? AND ?
    ");
        $count_stmt->execute([$branch_id, $week_start, $week_end]);
    $todays_jobs_count = intval($count_stmt->fetchColumn());

    $jobs_stmt = $pdo->prepare("
        SELECT jo.id, jo.job_number, jo.quotation_id, jo.status, jo.assigned_technician_name, jo.notes,
               c.name AS customer_name,
               v.plate_number, v.make, v.model
        FROM job_orders jo
        INNER JOIN customers c ON c.id = jo.customer_id
        LEFT JOIN vehicles v ON v.id = jo.vehicle_id
        WHERE jo.branch_id = ?
          AND jo.status IN ('waiting', 'pending', 'in-progress')
          AND DATE(COALESCE(jo.job_date, jo.created_at)) BETWEEN ? AND ?
        ORDER BY jo.scheduled_start_time IS NULL, jo.scheduled_start_time ASC, jo.id DESC
        LIMIT 5
    ");
    $jobs_stmt->execute([$branch_id, $week_start, $week_end]);
    $todays_job_orders = $jobs_stmt->fetchAll();

    $job_quotation_ids = array_filter(array_unique(array_column($todays_job_orders, 'quotation_id')));
    $service_items_by_quote = [];
    if (!empty($job_quotation_ids)) {
        $q_placeholders = implode(',', array_fill(0, count($job_quotation_ids), '?'));
        $qi_stmt = $pdo->prepare("
            SELECT quotation_id, item_name, item_type, quantity
            FROM quotation_items
            WHERE quotation_id IN ($q_placeholders)
            ORDER BY quotation_id ASC, id ASC
        ");
        $qi_stmt->execute(array_values($job_quotation_ids));
        foreach ($qi_stmt->fetchAll() as $qi_row) {
            $formatted_name = app_display_item_name($qi_row['item_name'], $qi_row['item_type'] ?? null);
            if ((int) $qi_row['quantity'] > 1) {
                $formatted_name .= ' (' . (int) $qi_row['quantity'] . 'x)';
            }
            $service_items_by_quote[(int) $qi_row['quotation_id']][] = $formatted_name;
        }
    }

    $quotes_stmt = $pdo->prepare("
        SELECT q.id, q.branch_id, q.quotation_number, q.quotation_date, q.created_at, q.status, q.total_amount,
               c.name AS customer_name
        FROM quotations q
        INNER JOIN customers c ON c.id = q.customer_id
        WHERE q.branch_id = ?
          AND q.status <> 'archived'
        ORDER BY COALESCE(q.quotation_date, DATE(q.created_at)) DESC, q.id DESC
        LIMIT 3
    ");
    $quotes_stmt->execute([$branch_id]);
    $recent_quotations = $quotes_stmt->fetchAll();

    $visits_stmt = $pdo->prepare("
        SELECT cv.id, cv.branch_id, cv.visit_date, c.name AS customer_name, c.phone_mobile, c.contact
        FROM customer_visits cv
        INNER JOIN (
            SELECT MAX(id) AS id
            FROM customer_visits
            WHERE branch_id = ?
            GROUP BY DATE(visit_date)
            ORDER BY DATE(visit_date) DESC
            LIMIT 3
        ) recent ON recent.id = cv.id
        INNER JOIN customers c ON c.id = cv.customer_id
        ORDER BY cv.visit_date DESC, cv.id DESC
    ");
    $visits_stmt->execute([$branch_id]);
    $recent_customer_visits = $visits_stmt->fetchAll();
} catch (Exception $e) {
    error_log('Front Desk Dashboard error: ' . $e->getMessage());
}
?>

<?php require_once '../includes/header.php'; ?>
<?php require_once '../includes/sidebar.php'; ?>

<div class="front-desk-dashboard">
    <section class="front-desk-hero">
        <h1>Front Desk Dashboard</h1>
        <p><?php echo esc_html(app_branch_label($branch['name'] ?? '', 'Front Desk')); ?> - Quick access to daily operations</p>
    </section>

    <?php
    $flash_message = get_flash_message();
    if ($flash_message):
    ?>
        <div class="alert alert-<?php echo esc_attr($flash_message['type']); ?> alert-dismissible fade show" role="alert">
            <?php echo esc_html($flash_message['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <section class="front-panel front-quick-panel">
        <div class="front-panel-header">
            <h2>Quick Actions</h2>
            <span><?php echo esc_html(app_branch_label($branch['name'] ?? '', 'Front Desk')); ?></span>
        </div>
        <div class="front-quick-grid">
            <a class="front-quick-action" href="/hwtires/front-desk/customers/?focus=search">
                <span class="front-quick-icon action-search"><i class="fas fa-magnifying-glass"></i></span>
                <strong>Search Customer</strong>
            </a>
            <a class="front-quick-action" href="/hwtires/front-desk/customers/?open=add">
                <span class="front-quick-icon action-add"><i class="fas fa-user-plus"></i></span>
                <strong>Add Customer</strong>
            </a>
            <a class="front-quick-action" href="/hwtires/front-desk/quotations/create.php">
                <span class="front-quick-icon action-quote"><i class="far fa-file-lines"></i></span>
                <strong>Service Operation</strong>
            </a>
            <a class="front-quick-action" href="/hwtires/front-desk/job-orders/create.php">
                <span class="front-quick-icon action-job"><i class="fas fa-clipboard-list"></i></span>
                <strong>Create Job Order</strong>
            </a>
        </div>
    </section>

    <section class="front-panel front-work-panel">
        <div class="front-panel-header">
            <div class="front-section-heading">
                <i class="far fa-calendar-check"></i>
                <h2>This Week's Active Jobs</h2>
                <span><?php echo $todays_jobs_count; ?></span>
            </div>
            <a class="front-panel-link" href="/hwtires/front-desk/job-orders/">View Job Orders</a>
        </div>

        <div class="front-list front-work-list">
            <?php if (empty($todays_job_orders)): ?>
                <div class="front-empty-state compact">No active job orders scheduled this week.</div>
            <?php else: ?>
                <?php foreach ($todays_job_orders as $job): ?>
                    <article class="front-job-card front-record-row">
                        <div class="front-record-main">
                            <h3><?php echo esc_html($job['customer_name']); ?></h3>
                            <p>
                                <?php echo esc_html(trim(($job['plate_number'] ?? '') . ' ' . (($job['make'] ?? '') ?: '') . ' ' . (($job['model'] ?? '') ?: '')) ?: 'Vehicle not specified'); ?>
                            </p>
                            <?php
                            $job_quote_id = (int) ($job['quotation_id'] ?? 0);
                            $job_services_str = !empty($service_items_by_quote[$job_quote_id]) ? implode(', ', $service_items_by_quote[$job_quote_id]) : '';
                            ?>
                            <small><?php echo esc_html(front_dashboard_services($job_services_str, $job['notes'] ?? 'No services listed')); ?></small>
                        </div>
                        <div class="front-record-meta">
                            <span class="front-status-pill <?php echo esc_attr(front_dashboard_status_class($job['status'])); ?>">
                                <?php echo esc_html(front_dashboard_status_label($job['status'])); ?>
                            </span>
                            <small><?php echo esc_html($job['assigned_technician_name'] ?: 'Unassigned'); ?></small>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <div class="front-dashboard-grid">
        <section class="front-panel">
            <div class="front-panel-header">
                <h2>Recent Service Operations</h2>
                <a class="front-panel-link" href="/hwtires/front-desk/quotations/">View All</a>
            </div>
            <div class="front-list">
                <?php if (empty($recent_quotations)): ?>
                    <div class="front-empty-state">No recent service operations found</div>
                <?php else: ?>
                    <?php foreach ($recent_quotations as $quotation): ?>
                        <article class="front-quotation-card front-record-row">
                            <div class="front-record-main">
                                <div class="front-inline-title">
                                    <h3><?php echo esc_html($quotation['customer_name']); ?></h3>
                                    <span class="front-status-pill <?php echo esc_attr(front_dashboard_status_class($quotation['status'])); ?>">
                                        <?php echo esc_html(front_dashboard_status_label($quotation['status'])); ?>
                                    </span>
                                </div>
                                <p><?php echo esc_html(front_dashboard_short_date($quotation['quotation_date'] ?? $quotation['created_at'] ?? '')); ?></p>
                            </div>
                            <strong><?php echo front_dashboard_currency($quotation['total_amount']); ?></strong>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="front-panel">
            <div class="front-panel-header">
                <h2>Recent Customer Visits</h2>
                <a class="front-panel-link" href="/hwtires/front-desk/customers/">View Customers</a>
            </div>
            <div class="front-list">
                <?php if (empty($recent_customer_visits)): ?>
                    <div class="front-empty-state">No recent customer visits found</div>
                <?php else: ?>
                    <?php foreach ($recent_customer_visits as $visit): ?>
                        <article class="front-visit-card front-record-row">
                            <div class="front-record-main">
                                <h3><?php echo esc_html($visit['customer_name']); ?></h3>
                                <p><?php echo esc_html(($visit['phone_mobile'] ?? '') ?: (($visit['contact'] ?? '') ?: '-')); ?></p>
                            </div>
                            <time><?php echo esc_html(front_dashboard_short_date($visit['visit_date'])); ?></time>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
