<?php
/**
 * Front-desk vehicle service status
 */

require_once '../../includes/config.php';
require_once '../../includes/record-filters.php';
require_once '../../includes/job-order-progress.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$page_title = 'Service Status';
$user = app_get_session_user();

if (($user['role'] ?? '') !== 'front-desk') {
    redirect('/hwtires/' . ($user['role'] ?? 'admin') . '/service-status/');
}

$branch_id = (int) ($user['branch_id'] ?? 0);
if ($branch_id <= 0) {
    set_flash_message('Your account is not assigned to a branch.', 'error');
    redirect('/hwtires/front-desk/');
}

if (!function_exists('service_status_branch_label')) {
    function service_status_branch_label($name) {
        return app_branch_label($name, '-');
    }
}

if (!function_exists('service_status_branch_class')) {
    function service_status_branch_class($branch_id) {
        $branch_id = intval($branch_id);
        if ($branch_id <= 0) {
            return 'customer-branch-empty';
        }

        return 'customer-branch-' . ((($branch_id - 1) % 3) + 1);
    }
}

if (!function_exists('service_status_vehicle_name')) {
    function service_status_vehicle_name($job) {
        $name = trim(($job['make'] ?? '') . ' ' . ($job['model'] ?? ''));
        if ($name === '') {
            $name = 'Vehicle';
        }

        if (!empty($job['year'])) {
            $name .= ' ' . $job['year'];
        }

        return $name;
    }
}

if (!function_exists('service_status_time_range')) {
    function service_status_time_range($start, $end) {
        if (empty($start) && empty($end)) {
            return '-';
        }

        $start_text = !empty($start) ? date('h:i A', strtotime($start)) : '-';
        $end_text = !empty($end) ? date('h:i A', strtotime($end)) : '-';
        return $start_text . ' - ' . $end_text;
    }
}

if (!function_exists('service_status_label')) {
    function service_status_label($status) {
        $labels = [
            'waiting' => 'Waiting',
            'in-progress' => 'In Progress',
            'completed' => 'Completed',
        ];

        return $labels[$status] ?? ucfirst(str_replace('-', ' ', (string) $status));
    }
}

if (!function_exists('front_service_status_filter_url')) {
    function front_service_status_filter_url($status, $search = '', $date_filter = null) {
        $query = [];

        if ($status !== 'all') {
            $query['status'] = $status;
        }

        $search = trim((string) $search);
        if ($search !== '') {
            $query['search'] = $search;
        }

        if (is_array($date_filter)) {
            $query = array_merge($query, record_date_filter_query_params($date_filter));
        }

        return empty($query) ? './' : '?' . http_build_query($query);
    }
}

$allowed_statuses = ['all', 'active', 'waiting', 'in-progress', 'completed'];
$status_filter = $_GET['status'] ?? 'all';
if (!in_array($status_filter, $allowed_statuses, true)) {
    $status_filter = 'all';
}

$search_filter = trim($_GET['search'] ?? '');
if (function_exists('mb_substr')) {
    $search_filter = mb_substr($search_filter, 0, 100);
} else {
    $search_filter = substr($search_filter, 0, 100);
}
$focus_job_id = max(0, (int) ($_GET['job_id'] ?? 0));
$date_filter = record_date_filter_current();
$job_activity_expr = record_activity_datetime_expr('jo.job_date', 'jo.created_at', 'jo.updated_at');
$job_record_date_expr = record_business_datetime_expr('jo.job_date', 'jo.created_at');

$base_where = [
    "jo.status IN ('waiting', 'pending', 'in-progress', 'completed')",
    'jo.branch_id = ?',
];
$base_params = [$branch_id];

if ($focus_job_id > 0) {
    $base_where[] = 'jo.id = ?';
    $base_params[] = $focus_job_id;
} else {
    $date_params = [];
    $date_condition = record_date_filter_condition($job_record_date_expr, $date_filter, $date_params);
    if ($date_condition !== '') {
        $base_where[] = $date_condition;
        $base_params = array_merge($base_params, $date_params);
    }
}

if ($search_filter !== '') {
    foreach (app_search_terms($search_filter) as $term) {
        $base_where[] = "(
            jo.job_number LIKE ?
            OR c.name LIKE ?
            OR c.phone_mobile LIKE ?
            OR c.contact LIKE ?
            OR v.make LIKE ?
            OR v.model LIKE ?
            OR v.plate_number LIKE ?
            OR b.name LIKE ?
            OR jo.assigned_technician_name LIKE ?
            OR jo.notes LIKE ?
            OR EXISTS (
                SELECT 1
                FROM quotation_items qi
                WHERE qi.quotation_id = jo.quotation_id
                  AND qi.item_name LIKE ?
            )
        )";
        $base_params = array_merge($base_params, array_fill(0, 11, '%' . $term . '%'));
    }
}

$summary_where = $base_where;
$summary_params = $base_params;
$summary_where_sql = implode(' AND ', $summary_where);

$service_status_from_sql = "
    FROM job_orders jo
    LEFT JOIN customers c ON jo.customer_id = c.id
    LEFT JOIN vehicles v ON jo.vehicle_id = v.id
    LEFT JOIN branches b ON jo.branch_id = b.id
";

$summary = [
    'waiting' => 0,
    'in-progress' => 0,
    'completed' => 0,
];

$summary_stmt = $pdo->prepare("
    SELECT CASE WHEN jo.status = 'pending' THEN 'waiting' ELSE jo.status END AS status, COUNT(*) AS count
    $service_status_from_sql
    WHERE $summary_where_sql
    GROUP BY CASE WHEN jo.status = 'pending' THEN 'waiting' ELSE jo.status END
");
$summary_stmt->execute($summary_params);
foreach ($summary_stmt->fetchAll() as $row) {
    if (array_key_exists($row['status'], $summary)) {
        $summary[$row['status']] = (int) $row['count'];
    }
}

$total_jobs = array_sum($summary);

$jobs_where = $base_where;
$jobs_params = $base_params;
if ($status_filter !== 'all') {
    if ($status_filter === 'active') {
        $jobs_where[] = "jo.status IN ('waiting', 'pending', 'in-progress')";
    } elseif ($status_filter === 'waiting') {
        $jobs_where[] = "jo.status IN ('waiting', 'pending')";
    } else {
        $jobs_where[] = 'jo.status = ?';
        $jobs_params[] = $status_filter;
    }
}
$jobs_where_sql = implode(' AND ', $jobs_where);
$jobs_order_sql = "$job_record_date_expr DESC, jo.id DESC";

// ADD PAGINATION
$page = max(1, (int) ($_GET['page'] ?? 1));
$records_per_page = 15;  // Limit to 15 jobs per page
$offset = ($page - 1) * $records_per_page;

// Count total records first
$count_stmt = $pdo->prepare("SELECT COUNT(DISTINCT jo.id) as total $service_status_from_sql WHERE $jobs_where_sql");
$count_stmt->execute($jobs_params);
$total_records = $count_stmt->fetch()['total'];
$total_pages = ceil($total_records / $records_per_page);

$jobs_stmt = $pdo->prepare("
    SELECT jo.*,
           CASE WHEN jo.status = 'pending' THEN 'waiting' ELSE jo.status END AS display_status,
           c.name AS customer_name,
           COALESCE(NULLIF(TRIM(c.phone_mobile), ''), NULLIF(TRIM(c.contact), ''), NULLIF(TRIM(c.phone_work), '')) AS customer_contact_number,
           v.make,
           v.model,
           v.year,
           v.plate_number,
           b.name AS branch_name
    $service_status_from_sql
    WHERE $jobs_where_sql
    ORDER BY $jobs_order_sql
    LIMIT $records_per_page OFFSET $offset
");
$jobs_stmt->execute($jobs_params);
$jobs = $jobs_stmt->fetchAll();
$progress_by_job = job_progress_get_for_jobs($pdo, $jobs);
$transfer_states_by_job = [];
foreach ($jobs as $job) {
    $job_id = (int) ($job['id'] ?? 0);
    if ($job_id > 0) {
        $transfer_states_by_job[$job_id] = job_progress_get_transfer_states_for_job($pdo, $job_id);
    }
}

$services_by_quotation = [];
$quotation_ids = array_values(array_unique(array_filter(array_map('intval', array_column($jobs, 'quotation_id')))));

if (!empty($quotation_ids)) {
    $placeholders = implode(',', array_fill(0, count($quotation_ids), '?'));
    $items_stmt = $pdo->prepare("SELECT quotation_id, item_name, item_type FROM quotation_items WHERE quotation_id IN ($placeholders) ORDER BY id ASC");
    $items_stmt->execute($quotation_ids);

    foreach ($items_stmt->fetchAll() as $item) {
        $services_by_quotation[(int) $item['quotation_id']][] = app_display_item_name($item['item_name'], $item['item_type'] ?? null);
    }
}

$current_path = '/hwtires/front-desk/service-status/';
$progress_csrf_token = generate_csrf_token();
?>

<?php require_once '../../includes/header.php'; ?>
<?php require_once '../../includes/sidebar.php'; ?>

<div class="service-status-page">
    <style>
    .service-status-page .service-task-list-modal {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .service-status-page .service-task-check {
        display: flex !important;
        flex-direction: column !important;
        align-items: stretch !important;
        justify-content: center !important;
        gap: 4px !important;
        min-height: 42px !important;
        padding: 9px 12px !important;
        box-sizing: border-box !important;
        width: 100% !important;
        background: #f8fafc !important;
        border: 1px solid #e2e8f0 !important;
        border-radius: 8px !important;
        cursor: pointer !important;
        transition: all 0.15s ease-in-out !important;
    }
    .service-status-page .service-task-check.is-done {
        color: #008d43 !important;
        background: #ecfdf3 !important;
        border-color: #bbf7d0 !important;
    }
    .service-status-page .service-task-check.is-transfer-pending {
        background: #fffbeb !important;
        border-color: #fde68a !important;
        cursor: not-allowed !important;
    }
    .service-status-page .service-task-check .service-task-main {
        display: flex !important;
        flex-direction: row !important;
        align-items: center !important;
        justify-content: flex-start !important;
        gap: 10px !important;
        width: 100% !important;
    }
    .service-status-page .service-task-check input[type="checkbox"],
    .service-status-page .service-task-check input.service-progress-checkbox {
        display: inline-block !important;
        flex: 0 0 18px !important;
        width: 18px !important;
        height: 18px !important;
        margin: 0 !important;
        vertical-align: middle !important;
        flex-shrink: 0 !important;
        accent-color: #0096b6 !important;
    }
    .service-status-page .service-task-check.is-transfer-pending input[type="checkbox"],
    .service-status-page .service-task-check.is-transfer-pending input.service-progress-checkbox {
        accent-color: #b45309 !important;
    }
    .service-status-page .service-task-check .service-task-name {
        display: inline-flex !important;
        align-items: center !important;
        gap: 6px !important;
        flex: 1 1 auto !important;
        line-height: 1.35 !important;
        font-size: 13.5px !important;
        font-weight: 650 !important;
        color: #16223c !important;
        white-space: normal !important;
        word-break: break-word !important;
    }
    .service-status-page .service-task-check.is-done .service-task-name {
        color: #008d43 !important;
    }
    .service-status-page .service-task-check.is-transfer-pending .service-task-name {
        color: #92400e !important;
    }
    .service-status-page .service-task-check .service-task-quantity {
        display: inline-block !important;
        font-size: 12px !important;
        font-weight: 700 !important;
        color: #64748b !important;
        margin-left: 2px !important;
    }
    .service-status-page .service-task-check .service-task-transfer-status {
        margin-left: 28px !important;
        font-size: 12.5px !important;
        font-weight: 500 !important;
        line-height: 1.35 !important;
        word-break: break-word !important;
    }
    .service-status-page .service-task-check .service-task-transfer-status.is-pending {
        color: #b45309 !important;
    }
    .service-status-page .service-task-check .service-task-transfer-status.is-shipped {
        color: #c2410c !important;
    }
    .service-status-page .service-task-check .service-task-transfer-status.is-no-source {
        color: #92400e !important;
    }
    .service-status-page .service-task-check .service-task-transfer-status.is-ready {
        color: #0f766e !important;
    }
    </style>

    <section class="service-status-hero">
        <h1>Vehicle Service Status</h1>
        <p>Track and update service status</p>
    </section>

    <?php
    $flash_message = get_flash_message();
    if ($flash_message):
        $flash_type = $flash_message['type'] === 'error' ? 'danger' : $flash_message['type'];
    ?>
        <div class="alert alert-<?php echo esc_attr($flash_type); ?> alert-dismissible fade show" role="alert">
            <?php echo esc_html($flash_message['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <section class="service-status-summary-grid">
        <a href="<?php echo esc_attr(front_service_status_filter_url('all', $search_filter, $date_filter)); ?>#service-records" class="service-summary-card summary-total <?php echo $status_filter === 'all' ? 'active' : ''; ?>">
            <span>Total</span>
            <strong><?php echo (int) $total_jobs; ?></strong>
        </a>
        <a href="<?php echo esc_attr(front_service_status_filter_url('waiting', $search_filter, $date_filter)); ?>#service-records" class="service-summary-card summary-waiting <?php echo $status_filter === 'waiting' ? 'active' : ''; ?>">
            <span>Waiting</span>
            <strong><?php echo (int) $summary['waiting']; ?></strong>
        </a>
        <a href="<?php echo esc_attr(front_service_status_filter_url('in-progress', $search_filter, $date_filter)); ?>#service-records" class="service-summary-card summary-progress <?php echo $status_filter === 'in-progress' ? 'active' : ''; ?>">
            <span>In Progress</span>
            <strong><?php echo (int) $summary['in-progress']; ?></strong>
        </a>
        <a href="<?php echo esc_attr(front_service_status_filter_url('completed', $search_filter, $date_filter)); ?>#service-records" class="service-summary-card summary-completed <?php echo $status_filter === 'completed' ? 'active' : ''; ?>">
            <span>Completed</span>
            <strong><?php echo (int) $summary['completed']; ?></strong>
        </a>
    </section>

    <section class="service-branch-filter-card service-search-filter-card">
        <form method="GET" action="./#service-records" class="records-select-filter service-status-select-filter">
            <?php if ($focus_job_id > 0): ?>
                <input type="hidden" name="job_id" value="<?php echo (int) $focus_job_id; ?>">
            <?php endif; ?>
            <?php if ($search_filter !== ''): ?>
                <input type="hidden" name="search" value="<?php echo esc_attr($search_filter); ?>">
            <?php endif; ?>
            <?php record_date_filter_hidden_inputs(record_date_filter_query_params($date_filter)); ?>
            <label>
                <span>Service Status</span>
                <select name="status" onchange="this.form.submit()" aria-label="Filter service status records">
                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Service Statuses</option>
                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active Records</option>
                    <option value="waiting" <?php echo $status_filter === 'waiting' ? 'selected' : ''; ?>>Waiting</option>
                    <option value="in-progress" <?php echo $status_filter === 'in-progress' ? 'selected' : ''; ?>>In Progress</option>
                    <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                </select>
            </label>
            <button type="submit">Apply</button>
        </form>
        <form method="GET" action="./#service-records" class="records-search-form service-search-form">
            <?php if ($focus_job_id > 0): ?>
                <input type="hidden" name="job_id" value="<?php echo (int) $focus_job_id; ?>">
            <?php endif; ?>
            <input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>">
            <?php record_date_filter_hidden_inputs(record_date_filter_query_params($date_filter)); ?>
            <label class="records-search-field">
                <i class="fas fa-search"></i>
                <input type="search"
                       name="search"
                       maxlength="100"
                       data-text-format="first-letter"
                       value="<?php echo esc_attr($search_filter); ?>"
                       placeholder="Search plate, customer, job order...">
            </label>
            <button type="submit" class="records-search-btn">Search</button>
            <?php if ($search_filter !== ''): ?>
                <a class="records-search-clear"
                   href="<?php echo esc_attr(front_service_status_filter_url($status_filter, '', $date_filter)); ?>#service-records">
                    Clear
                </a>
            <?php endif; ?>
        </form>
        <?php
        record_date_filter_controls($date_filter, [
            'status' => $status_filter !== 'all' ? $status_filter : '',
            'search' => $search_filter,
            'job_id' => $focus_job_id > 0 ? $focus_job_id : '',
        ], 'service-records');
        ?>
    </section>

    <div id="service-records">
        <?php if (empty($jobs)): ?>
            <section class="service-status-empty">No vehicles found for this status.</section>
        <?php else: ?>
            <section class="service-status-queue-card">
                <div class="service-status-queue-header">
                    <div>
                        <h2>Service Queue</h2>
                        <p>Showing <?php echo (int) $offset + 1; ?>-<?php echo (int) min($offset + count($jobs), $total_records); ?> of <?php echo (int) $total_records; ?> job orders</p>
                    </div>
                </div>
                <div class="service-status-queue-table" role="table" aria-label="Vehicle service status records">
                    <div class="service-status-queue-columns" role="row">
                        <span>Plate / Vehicle</span>
                        <span>Customer</span>
                        <span>Job Order</span>
                        <span>Technician</span>
                        <span>Schedule</span>
                        <span>Progress</span>
                        <span>Status</span>
                        <span>Action</span>
                    </div>
            <?php foreach ($jobs as $job): ?>
                <?php
                $job_status = $job['display_status'] ?? ($job['status'] ?? 'waiting');
                $services = !empty($job['quotation_id']) ? ($services_by_quotation[(int) $job['quotation_id']] ?? []) : [];
                if (empty($services)) {
                    $services = ['Service Request'];
                }
                $job_id = (int) $job['id'];
                $progress = $progress_by_job[$job_id] ?? ['tasks' => [], 'total' => 0, 'done' => 0, 'percent' => 0];
                $progress_percent = (int) ($progress['percent'] ?? 0);
                $customer_contact_number = trim((string) ($job['customer_contact_number'] ?? ''));
                $visible_services = array_slice($services, 0, 2);
                $hidden_service_count = max(0, count($services) - count($visible_services));
                $display_note = app_format_record_notes($job['notes'] ?? '');
                $technician_name = ($job['assigned_technician_name'] ?? '') ?: 'Unassigned';
                $schedule_range = service_status_time_range($job['scheduled_start_time'] ?? '', $job['scheduled_end_time'] ?? '');
                $status_action_label = $job_status === 'completed' ? 'View Status' : 'Update Status';
                ?>
                    <article class="service-status-row" data-progress-card data-job-id="<?php echo $job_id; ?>" role="row">
                        <div class="service-status-cell service-status-vehicle-cell" role="cell">
                            <strong><?php echo esc_html($job['plate_number'] ?? '-'); ?></strong>
                            <span><?php echo esc_html(service_status_vehicle_name($job)); ?></span>
                            <small><?php echo esc_html(service_status_branch_label($job['branch_name'] ?? '')); ?></small>
                        </div>
                        <div class="service-status-cell" role="cell">
                            <strong><?php echo esc_html($job['customer_name'] ?? '-'); ?></strong>
                            <?php if ($customer_contact_number !== ''): ?>
                                <span><?php echo esc_html($customer_contact_number); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="service-status-cell" role="cell">
                            <strong><?php echo esc_html($job['job_number'] ?? '-'); ?></strong>
                            <span>
                                <?php foreach ($visible_services as $index => $service): ?>
                                    <?php echo $index > 0 ? '; ' : ''; ?><?php echo esc_html($service); ?>
                                <?php endforeach; ?>
                                <?php if ($hidden_service_count > 0): ?>
                                    <?php echo esc_html(' +' . $hidden_service_count . ' more'); ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="service-status-cell" role="cell">
                            <strong><?php echo esc_html($technician_name); ?></strong>
                        </div>
                        <div class="service-status-cell" role="cell">
                            <strong><?php echo esc_html($schedule_range); ?></strong>
                            <span><?php echo esc_html(!empty($job['job_date']) ? date('M d, Y', strtotime($job['job_date'])) : '-'); ?></span>
                        </div>
                        <div class="service-status-cell service-status-progress-cell" role="cell">
                            <div class="service-progress-track" aria-label="Job order progress">
                                <span data-progress-bar style="width: <?php echo $progress_percent; ?>%;"></span>
                            </div>
                            <p class="service-progress-copy">
                                <strong data-progress-percent><?php echo $progress_percent; ?>%</strong>
                                <span data-progress-count>(<?php echo (int) ($progress['done'] ?? 0); ?>/<?php echo (int) ($progress['total'] ?? 0); ?> done)</span>
                            </p>
                        </div>
                        <div class="service-status-cell" role="cell">
                            <strong class="service-progress-status status-<?php echo esc_attr($job_status); ?>" data-progress-status>
                                <?php echo esc_html(service_status_label($job_status)); ?>
                            </strong>
                        </div>
                        <div class="service-status-cell service-status-action-cell" role="cell">
                            <button type="button" class="service-status-update-btn" data-bs-toggle="modal" data-bs-target="#serviceStatusModal<?php echo $job_id; ?>">
                                <i class="fas fa-eye"></i>
                                <span data-status-action-label><?php echo esc_html($status_action_label); ?></span>
                            </button>
                        </div>
                    </article>

                    <div class="modal fade service-status-detail-modal" id="serviceStatusModal<?php echo $job_id; ?>" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered modal-lg">
                            <div class="modal-content" data-progress-card data-job-id="<?php echo $job_id; ?>">
                                <div class="modal-header">
                                    <div>
                                        <h2>Service Status</h2>
                                        <p><?php echo esc_html(($job['job_number'] ?? '-') . ' - ' . ($job['plate_number'] ?? '-')); ?></p>
                                    </div>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>

                                <div class="modal-body">
                                    <section class="service-status-detail-summary">
                                        <div>
                                            <span>Vehicle</span>
                                            <strong><?php echo esc_html(($job['plate_number'] ?? '-') . ' - ' . service_status_vehicle_name($job)); ?></strong>
                                        </div>
                                        <div>
                                            <span>Customer</span>
                                            <strong><?php echo esc_html($job['customer_name'] ?? '-'); ?></strong>
                                            <?php if ($customer_contact_number !== ''): ?>
                                                <p><?php echo esc_html($customer_contact_number); ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <span>Technician</span>
                                            <strong><?php echo esc_html($technician_name); ?></strong>
                                        </div>
                                        <div>
                                            <span>Schedule</span>
                                            <strong><?php echo esc_html($schedule_range); ?></strong>
                                            <p><?php echo esc_html(!empty($job['job_date']) ? date('M d, Y', strtotime($job['job_date'])) : '-'); ?></p>
                                        </div>
                                    </section>

                                    <section class="service-status-detail-section">
                                        <div class="service-progress-head">
                                            <span>Progress</span>
                                            <strong class="service-progress-status status-<?php echo esc_attr($job_status); ?>" data-progress-status>
                                                <?php echo esc_html(service_status_label($job_status)); ?>
                                            </strong>
                                        </div>
                                        <div class="service-progress-track" aria-label="Job order progress">
                                            <span data-progress-bar style="width: <?php echo $progress_percent; ?>%;"></span>
                                        </div>
                                        <p class="service-progress-copy">
                                            <strong data-progress-percent><?php echo $progress_percent; ?>%</strong> completed
                                            <span data-progress-count>(<?php echo (int) ($progress['done'] ?? 0); ?>/<?php echo (int) ($progress['total'] ?? 0); ?> done)</span>
                                        </p>
                                    </section>

                                    <section class="service-status-detail-section">
                                        <h3>Services</h3>
                                        <div class="service-job-services">
                                            <div>
                                                <?php foreach ($services as $service): ?>
                                                    <span><?php echo esc_html($service); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </section>

                                    <section class="service-status-detail-section">
                                        <h3>Checklist</h3>
                                        <?php if (empty($progress['tasks'])): ?>
                                            <p class="service-status-empty-line">No checklist tasks are available for this job order.</p>
                                        <?php else: ?>
                                            <div class="service-task-list service-task-list-modal" aria-label="Job order checklist">
                                                <?php foreach (($progress['tasks'] ?? []) as $task): ?>
                                                    <?php
                                                    $transfer_state = $transfer_states_by_job[$job_id][$task['task_key']] ?? ['requires_transfer' => false, 'is_ready' => true, 'status' => 'ready', 'label' => 'Ready', 'message' => '', 'branch_name' => ''];
                                                    $transfer_blocked = !empty($transfer_state['requires_transfer']) && empty($transfer_state['is_ready']);
                                                    $task_done = !empty($task['is_done']) && !$transfer_blocked;
                                                    $task_quantity = max(1, (int) ($task['quantity'] ?? 1));
                                                    ?>
                                                    <label class="service-task-check <?php echo $task_done ? 'is-done' : ''; ?> <?php echo $transfer_blocked ? 'is-transfer-pending' : ''; ?> <?php echo !empty($transfer_state['requires_transfer']) ? 'has-transfer-status' : ''; ?>"
                                                           <?php if (!empty($transfer_state['message'])): ?>data-transfer-message="<?php echo esc_attr($transfer_state['message']); ?>"<?php endif; ?>>
                                                        <div class="service-task-main">
                                                            <input type="checkbox"
                                                                   class="service-progress-checkbox"
                                                                   data-job-id="<?php echo $job_id; ?>"
                                                                   data-task-key="<?php echo esc_attr($task['task_key']); ?>"
                                                                   <?php echo $transfer_blocked ? 'disabled' : ''; ?>
                                                                   <?php echo $task_done ? 'checked' : ''; ?>>
                                                            <span class="service-task-name">
                                                                <?php echo esc_html($task['task_name']); ?>
                                                                <?php if ($task_quantity > 1): ?>
                                                                    <small class="service-task-quantity">x<?php echo $task_quantity; ?></small>
                                                                <?php endif; ?>
                                                            </span>
                                                        </div>
                                                        <?php if (!empty($transfer_state['requires_transfer'])): ?>
                                                            <?php
                                                            $transfer_status_class = $transfer_blocked ? 'is-pending' : 'is-ready';
                                                            if ($transfer_state['status'] === 'shipped') {
                                                                $transfer_status_class = 'is-shipped';
                                                            } elseif ($transfer_state['status'] === 'not-sent' || empty($transfer_state['branch_name'])) {
                                                                $transfer_status_class = 'is-no-source';
                                                            }
                                                            ?>
                                                            <div class="service-task-transfer-status <?php echo $transfer_status_class; ?>">
                                                                <?php echo esc_html($transfer_state['label'] ?? ($transfer_blocked ? 'Waiting for transfer' : 'Transferred — Ready to Service')); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </section>

                                    <section class="service-status-detail-section">
                                        <h3>Notes</h3>
                                        <p class="service-status-note-copy"><?php echo esc_html($display_note !== '' ? $display_note : 'No notes added'); ?></p>
                                    </section>
                                </div>

                                <div class="modal-footer">
                                    <?php if (!empty($job['quotation_id']) && (int) ($job['branch_id'] ?? 0) === $branch_id): ?>
                                        <a href="/hwtires/front-desk/quotations/view.php?id=<?php echo (int) $job['quotation_id']; ?>" class="service-status-secondary-link">
                                            <i class="fas fa-external-link-alt"></i>
                                            <span>Open Service Operation</span>
                                        </a>
                                    <?php endif; ?>
                                    <button type="button" class="service-status-close-btn" data-bs-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>
            <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- PAGINATION -->
        <?php if ($total_pages > 1): ?>
            <nav class="service-pagination" aria-label="Pagination">
                <ul class="pagination-list">
                    <?php if ($page > 1): ?>
                        <li>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>#service-records" class="pagination-link">« First</a>
                        </li>
                        <li>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>#service-records" class="pagination-link">‹ Previous</a>
                        </li>
                    <?php endif; ?>

                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    for ($i = $start_page; $i <= $end_page; $i++):
                    ?>
                        <li>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>#service-records"
                               class="pagination-link <?php echo $i === $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <li>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>#service-records" class="pagination-link">Next ›</a>
                        </li>
                        <li>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>#service-records" class="pagination-link">Last »</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>

<script>
const serviceProgressCsrf = <?php echo json_encode($progress_csrf_token); ?>;

function serviceProgressCardsFor(card) {
    const jobId = card ? String(card.dataset.jobId || '') : '';
    if (jobId === '') {
        return card ? [card] : [];
    }

    return Array.from(document.querySelectorAll('[data-progress-card]')).filter((progressCard) => {
        return String(progressCard.dataset.jobId || '') === jobId;
    });
}

function setServiceProgressStatus(card, status, label) {
    serviceProgressCardsFor(card).forEach((progressCard) => {
        progressCard.querySelectorAll('[data-progress-status]').forEach((statusEl) => {
            statusEl.classList.remove('status-waiting', 'status-in-progress', 'status-completed', 'status-cancelled');
            statusEl.classList.add('status-' + status);
            statusEl.textContent = label;
        });

        progressCard.querySelectorAll('[data-status-action-label]').forEach((actionLabel) => {
            actionLabel.textContent = status === 'completed' ? 'View Status' : 'Update Status';
        });
    });
}

function refreshServiceProgressCard(card, data) {
    const percent = parseInt(data.percent || 0, 10);

    if (data.status) {
        setServiceProgressStatus(card, data.status, data.status_label || data.status);
    }

    serviceProgressCardsFor(card).forEach((progressCard) => {
        progressCard.querySelectorAll('[data-progress-bar]').forEach((bar) => {
            bar.style.width = Math.max(0, Math.min(100, percent)) + '%';
        });

        progressCard.querySelectorAll('[data-progress-percent]').forEach((percentText) => {
            percentText.textContent = percent + '%';
        });

        progressCard.querySelectorAll('[data-progress-count]').forEach((countText) => {
            countText.textContent = '(' + (data.done || 0) + '/' + (data.total || 0) + ' done)';
        });

        if (Array.isArray(data.tasks)) {
            const taskStates = new Map(data.tasks.map((task) => [
                String(task.task_key || ''),
                parseInt(task.is_done || 0, 10) === 1
            ]));

            progressCard.querySelectorAll('.service-progress-checkbox').forEach((checkbox) => {
                const taskKey = String(checkbox.dataset.taskKey || '');
                if (!taskStates.has(taskKey)) return;

                const isDone = taskStates.get(taskKey);
                checkbox.checked = isDone;
                checkbox.closest('.service-task-check')?.classList.toggle('is-done', isDone);
            });
        } else if (data.status === 'completed') {
            progressCard.querySelectorAll('.service-progress-checkbox').forEach((checkbox) => {
                checkbox.checked = true;
                checkbox.closest('.service-task-check')?.classList.add('is-done');
            });
        }
    });
}

function showInventoryProgressResult(result) {
    const inventory = result && result.inventory ? result.inventory : null;
    if (!inventory) return;

    const message = String(inventory.message || '').trim();
    if (message === '' || inventory.applied) return;

    const quietMessages = new Set([
        'Task does not use inventory',
        'No quotation item to deduct',
        'Inventory already deducted for this task'
    ]);
    if (quietMessages.has(message)) return;

    alert(message);
}

document.addEventListener('change', async (event) => {
    const checkbox = event.target.closest('.service-progress-checkbox');
    if (!checkbox) return;

    const card = checkbox.closest('[data-progress-card]');
    if (!card) return;

    const originalChecked = !checkbox.checked;
    checkbox.disabled = true;

    const payload = new FormData();
    payload.append('action', 'update_job_progress');
    payload.append('csrf_token', serviceProgressCsrf);
    payload.append('job_order_id', checkbox.dataset.jobId || card.dataset.jobId);
    payload.append('task_key', checkbox.dataset.taskKey || '');
    payload.append('is_done', checkbox.checked ? '1' : '0');

    try {
        const response = await fetch('/hwtires/api/service-status-api.php', {
            method: 'POST',
            body: payload,
            headers: { 'Accept': 'application/json' }
        });
        const result = await response.json();

        if (!result.success) {
            throw new Error(result.message || 'Unable to update job progress.');
        }

        refreshServiceProgressCard(card, result.data || {});
        showInventoryProgressResult(result);
        checkbox.disabled = false;
    } catch (error) {
        checkbox.checked = originalChecked;
        checkbox.closest('.service-task-check')?.classList.toggle('is-done', originalChecked);
        checkbox.disabled = false;
        alert(error.message || 'Unable to update job progress.');
    }
});

document.addEventListener('click', (event) => {
    const task = event.target.closest('.service-task-check.is-transfer-pending');
    if (!task) return;

    event.preventDefault();
    alert(task.dataset.transferMessage || 'This item has not been sent from the branch yet.');
});

document.addEventListener('DOMContentLoaded', () => {
    const focusJobId = <?php echo (int) $focus_job_id; ?>;
    if (!focusJobId || !window.bootstrap) return;

    const modal = document.getElementById('serviceStatusModal' + focusJobId);
    if (modal) {
        bootstrap.Modal.getOrCreateInstance(modal).show();
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>
