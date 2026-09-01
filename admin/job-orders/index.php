<?php
/**
 * Admin Job Order Monitor
 */

require_once '../../includes/config.php';
require_once '../../includes/record-filters.php';
require_once '../../includes/job-order-progress.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$user = app_get_session_user();
if (($user['role'] ?? '') !== 'admin') {
    redirect('/hwtires/' . ($user['role'] ?? 'front-desk') . '/');
}

$page_title = 'Job Order';

if (!function_exists('job_branch_label')) {
    function job_branch_label($branch_name) {
        return app_branch_label($branch_name, 'Branch');
    }
}

if (!function_exists('job_status_label')) {
    function job_status_label($status) {
        $status = (string) $status;
        if (in_array($status, ['waiting', 'pending'], true)) {
            return 'Waiting';
        }

        return ucwords(str_replace('-', ' ', $status));
    }
}

if (!function_exists('job_order_number')) {
    function job_order_number($job) {
        if (!empty($job['job_number'])) {
            return $job['job_number'];
        }

        return 'JO' . str_pad((string) ($job['id'] ?? 0), 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('job_vehicle_label')) {
    function job_vehicle_label($job, $include_plate = true) {
        $vehicle = trim(($job['vehicle_make'] ?? '') . ' ' . ($job['vehicle_model'] ?? ''));
        if ($vehicle === '') {
            $vehicle = 'Vehicle';
        }

        if ($include_plate && !empty($job['plate_number'])) {
            $vehicle .= ' (' . $job['plate_number'] . ')';
        }

        return $vehicle;
    }
}

if (!function_exists('job_display_date')) {
    function job_display_date($date) {
        return !empty($date) ? date('Y-m-d', strtotime($date)) : '-';
    }
}

if (!function_exists('job_order_filter_url')) {
    function job_order_filter_url($status, $branch_id = 0, $search = '', $date_filter = null, $page = 1, $record_filter = 'active') {
        $query = [];

        if ($status !== 'all') {
            $query['status'] = $status;
        }

        $branch_id = (int) $branch_id;
        if ($branch_id > 0) {
            $query['branch_id'] = $branch_id;
        }

        $record_filter = record_archive_filter_current($record_filter);
        if ($record_filter !== 'active') {
            $query['records'] = $record_filter;
        }

        $search = trim((string) $search);
        if ($search !== '') {
            $query['search'] = $search;
        }

        if (is_array($date_filter)) {
            $query = array_merge($query, record_date_filter_query_params($date_filter));
        }

        $page = max(1, (int) $page);
        if ($page > 1) {
            $query['page'] = $page;
        }

        return empty($query) ? './' : '?' . http_build_query($query);
    }
}

$tracked_statuses = [
    'all' => 'Total',
    'waiting' => 'Waiting',
    'in-progress' => 'In Progress',
    'completed' => 'Completed',
];

$status_filter = $_GET['status'] ?? 'all';
if (!array_key_exists($status_filter, $tracked_statuses)) {
    $status_filter = 'all';
}
$record_filter = record_archive_filter_current();
if ($record_filter === 'archived') {
    $status_filter = 'all';
}

$branch_filter = max(0, (int) ($_GET['branch_id'] ?? 0));
$search_filter = trim((string) ($_GET['search'] ?? ''));
if (function_exists('mb_substr')) {
    $search_filter = mb_substr($search_filter, 0, 140);
} else {
    $search_filter = substr($search_filter, 0, 140);
}

$date_filter = record_date_filter_current();
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 12;
$offset = ($page - 1) * $per_page;

$branches = $pdo->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$valid_branch_ids = array_map(static function ($branch) {
    return (int) $branch['id'];
}, $branches);

if ($branch_filter > 0 && !in_array($branch_filter, $valid_branch_ids, true)) {
    $branch_filter = 0;
}

$joins_sql = "
    FROM job_orders jo
    LEFT JOIN quotations q ON q.id = jo.quotation_id
    LEFT JOIN customers c ON c.id = jo.customer_id
    LEFT JOIN vehicles v ON v.id = jo.vehicle_id
    LEFT JOIN branches b ON b.id = jo.branch_id
    LEFT JOIN users u ON u.id = jo.created_by
";

$base_where = [];
$base_params = [];
$job_activity_expr = record_activity_datetime_expr('jo.job_date', 'jo.created_at', 'jo.updated_at');
$job_record_date_expr = record_business_datetime_expr('jo.job_date', 'jo.created_at');

if ($record_filter === 'active') {
    $base_where[] = "jo.status IN ('waiting', 'pending', 'in-progress', 'completed')";
} elseif ($record_filter === 'archived') {
    $base_where[] = "jo.status = 'archived'";
} else {
    $base_where[] = "jo.status IN ('waiting', 'pending', 'in-progress', 'completed', 'archived')";
}

if ($branch_filter > 0) {
    $base_where[] = 'jo.branch_id = ?';
    $base_params[] = $branch_filter;
}

$date_params = [];
$date_condition = record_date_filter_condition($job_record_date_expr, $date_filter, $date_params);
if ($date_condition !== '') {
    $base_where[] = $date_condition;
    $base_params = array_merge($base_params, $date_params);
}

if ($search_filter !== '') {
    foreach (app_search_terms($search_filter) as $term) {
        $base_where[] = "(
            jo.job_number LIKE ?
            OR q.quotation_number LIKE ?
            OR c.name LIKE ?
            OR c.phone_mobile LIKE ?
            OR c.contact LIKE ?
            OR c.email LIKE ?
            OR v.make LIKE ?
            OR v.model LIKE ?
            OR v.plate_number LIKE ?
            OR b.name LIKE ?
            OR jo.assigned_technician_name LIKE ?
            OR jo.notes LIKE ?
            OR q.inspection_complaint LIKE ?
            OR q.inspection_findings LIKE ?
            OR q.inspection_recommendations LIKE ?
            OR EXISTS (
                SELECT 1
                FROM quotation_items qi_search
                WHERE qi_search.quotation_id = jo.quotation_id
                  AND qi_search.item_name LIKE ?
            )
        )";
        $base_params = array_merge($base_params, array_fill(0, 16, '%' . $term . '%'));
    }
}

$base_where_sql = implode(' AND ', $base_where);

$summary_counts = [
    'all' => 0,
    'waiting' => 0,
    'in-progress' => 0,
    'completed' => 0,
];

$summary_total_stmt = $pdo->prepare("SELECT COUNT(DISTINCT jo.id) $joins_sql WHERE $base_where_sql");
$summary_total_stmt->execute($base_params);
$summary_counts['all'] = (int) $summary_total_stmt->fetchColumn();

$summary_stmt = $pdo->prepare("
    SELECT CASE WHEN jo.status = 'pending' THEN 'waiting' ELSE jo.status END AS status,
           COUNT(DISTINCT jo.id) AS total
    $joins_sql
    WHERE $base_where_sql
    GROUP BY CASE WHEN jo.status = 'pending' THEN 'waiting' ELSE jo.status END
");
$summary_stmt->execute($base_params);
foreach ($summary_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $status_key = (string) ($row['status'] ?? '');
    if (array_key_exists($status_key, $summary_counts)) {
        $summary_counts[$status_key] = (int) ($row['total'] ?? 0);
    }
}

$record_where = $base_where;
$record_params = $base_params;
if ($status_filter === 'waiting') {
    $record_where[] = "jo.status IN ('waiting', 'pending')";
} elseif ($status_filter !== 'all') {
    $record_where[] = 'jo.status = ?';
    $record_params[] = $status_filter;
}
$record_where_sql = implode(' AND ', $record_where);

$total_stmt = $pdo->prepare("SELECT COUNT(DISTINCT jo.id) $joins_sql WHERE $record_where_sql");
$total_stmt->execute($record_params);
$total_records = (int) $total_stmt->fetchColumn();
$total_pages = max(1, (int) ceil($total_records / $per_page));
if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $per_page;
}

$job_stmt = $pdo->prepare("
    SELECT jo.*,
           c.name AS customer_name,
           c.phone_mobile AS customer_phone,
           c.contact AS customer_contact,
           v.make AS vehicle_make,
           v.model AS vehicle_model,
           v.year AS vehicle_year,
           v.plate_number,
           b.name AS branch_name,
           u.name AS created_by_name,
           q.quotation_number,
           q.inspection_complaint,
           q.inspection_findings,
           q.inspection_recommendations,
           q.inspection_mileage
    $joins_sql
    WHERE $record_where_sql
    ORDER BY $job_record_date_expr DESC, jo.id DESC
    LIMIT $per_page OFFSET $offset
");
$job_stmt->execute($record_params);
$job_orders = $job_stmt->fetchAll(PDO::FETCH_ASSOC);
$progress_by_job = job_progress_get_for_jobs($pdo, $job_orders);
$displayed_from = $total_records > 0 ? $offset + 1 : 0;
$displayed_to = min($offset + count($job_orders), $total_records);
$pagination_pages = [];
if ($total_pages > 1) {
    $pagination_pages = array_filter(array_unique(array_merge(
        [1, $total_pages],
        range(max(1, $page - 2), min($total_pages, $page + 2))
    )), static function ($page_number) use ($total_pages) {
        return $page_number >= 1 && $page_number <= $total_pages;
    });
    sort($pagination_pages);
}

$quotation_ids = [];
foreach ($job_orders as $job) {
    if (!empty($job['quotation_id'])) {
        $quotation_ids[] = (int) $job['quotation_id'];
    }
}
$quotation_ids = array_values(array_unique($quotation_ids));

$services_by_quotation = [];
if (!empty($quotation_ids)) {
    $placeholders = implode(',', array_fill(0, count($quotation_ids), '?'));
    $items_stmt = $pdo->prepare("
        SELECT quotation_id, item_name
        FROM quotation_items
        WHERE quotation_id IN ($placeholders)
        ORDER BY quotation_id ASC, id ASC
    ");
    $items_stmt->execute($quotation_ids);

    foreach ($items_stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $services_by_quotation[(int) $item['quotation_id']][] = $item['item_name'];
    }
}

$hidden_for_search = [
    'status' => $status_filter !== 'all' ? $status_filter : '',
    'branch_id' => $branch_filter ?: '',
    'records' => $record_filter !== 'active' ? $record_filter : '',
];
$hidden_for_date = [
    'status' => $status_filter !== 'all' ? $status_filter : '',
    'branch_id' => $branch_filter ?: '',
    'search' => $search_filter,
    'records' => $record_filter !== 'active' ? $record_filter : '',
];
?>

<?php require_once '../../includes/header.php'; ?>
<?php require_once '../../includes/sidebar.php'; ?>

<main class="service-status-page admin-job-order-page" id="job-order-records">
    <header class="service-status-hero">
        <h1>Job Order</h1>
        <p>Monitor all vehicles in service</p>
    </header>

    <?php
    $flash_message = get_flash_message();
    if ($flash_message):
        $flash_type = $flash_message['type'] === 'error' ? 'danger' : $flash_message['type'];
    ?>
        <div class="alert alert-<?php echo esc_attr($flash_type); ?> alert-dismissible fade show job-orders-flash" role="alert">
            <?php echo esc_html($flash_message['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <section class="service-status-summary-grid" aria-label="Job order status summary">
        <?php foreach ($tracked_statuses as $status => $label): ?>
            <?php
            $summary_class = $status === 'all' ? 'summary-total' : ($status === 'in-progress' ? 'summary-progress' : 'summary-' . $status);
            ?>
            <a class="service-summary-card <?php echo esc_attr($summary_class); ?> <?php echo $status_filter === $status ? 'active' : ''; ?>"
               href="<?php echo esc_attr(job_order_filter_url($status, $branch_filter, $search_filter, $date_filter, 1, $record_filter)); ?>#job-order-records">
                <span><?php echo esc_html($label); ?></span>
                <strong><?php echo (int) $summary_counts[$status]; ?></strong>
            </a>
        <?php endforeach; ?>
    </section>

    <section class="service-branch-filter-card admin-job-order-filter-card">
        <div class="service-filter-grid">
            <div class="service-filter-left">
                <form method="GET" action="./#job-order-records" class="service-branch-filter">
                    <?php if ($status_filter !== 'all'): ?>
                        <input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>">
                    <?php endif; ?>
                    <?php if ($record_filter !== 'active'): ?>
                        <input type="hidden" name="records" value="<?php echo esc_attr($record_filter); ?>">
                    <?php endif; ?>
                    <?php if ($search_filter !== ''): ?>
                        <input type="hidden" name="search" value="<?php echo esc_attr($search_filter); ?>">
                    <?php endif; ?>
                    <?php record_date_filter_hidden_inputs(record_date_filter_query_params($date_filter)); ?>
                    <label for="branchFilter">Branch</label>
                    <select id="branchFilter" name="branch_id" onchange="this.form.submit()">
                        <option value="0">All Branches</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?php echo (int) $branch['id']; ?>" <?php echo $branch_filter === (int) $branch['id'] ? 'selected' : ''; ?>>
                                <?php echo esc_html($branch['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">Apply</button>
                </form>

                <form method="GET" action="./#job-order-records" class="service-branch-filter">
                    <?php if ($branch_filter > 0): ?>
                        <input type="hidden" name="branch_id" value="<?php echo (int) $branch_filter; ?>">
                    <?php endif; ?>
                    <?php if ($record_filter !== 'active'): ?>
                        <input type="hidden" name="records" value="<?php echo esc_attr($record_filter); ?>">
                    <?php endif; ?>
                    <?php if ($search_filter !== ''): ?>
                        <input type="hidden" name="search" value="<?php echo esc_attr($search_filter); ?>">
                    <?php endif; ?>
                    <?php record_date_filter_hidden_inputs(record_date_filter_query_params($date_filter)); ?>
                    <label for="jobStatusFilter">Status</label>
                    <select id="jobStatusFilter" name="status" onchange="this.form.submit()">
                        <?php foreach ($tracked_statuses as $status_value => $status_label): ?>
                            <option value="<?php echo esc_attr($status_value); ?>" <?php echo $status_filter === $status_value ? 'selected' : ''; ?>>
                                <?php echo esc_html($status_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">Apply</button>
                </form>

                <form method="GET" action="./#job-order-records" class="service-branch-filter">
                    <?php if ($status_filter !== 'all'): ?>
                        <input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>">
                    <?php endif; ?>
                    <?php if ($branch_filter > 0): ?>
                        <input type="hidden" name="branch_id" value="<?php echo (int) $branch_filter; ?>">
                    <?php endif; ?>
                    <?php if ($search_filter !== ''): ?>
                        <input type="hidden" name="search" value="<?php echo esc_attr($search_filter); ?>">
                    <?php endif; ?>
                    <?php record_date_filter_hidden_inputs(record_date_filter_query_params($date_filter)); ?>
                    <label for="jobRecordFilter">Archive Status</label>
                    <select id="jobRecordFilter" name="records" onchange="this.form.submit()">
                        <?php foreach (record_archive_filter_options() as $record_value => $record_label): ?>
                            <option value="<?php echo esc_attr($record_value); ?>" <?php echo $record_filter === $record_value ? 'selected' : ''; ?>>
                                <?php echo esc_html($record_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">Apply</button>
                </form>

                <?php record_date_filter_controls($date_filter, $hidden_for_date, 'job-order-records'); ?>
            </div>

            <form method="GET" action="./#job-order-records" class="records-search-form service-search-form">
                <?php record_date_filter_hidden_inputs($hidden_for_search); ?>
                <?php record_date_filter_hidden_inputs(record_date_filter_query_params($date_filter)); ?>
                <label class="records-search-field">
                    <i class="fas fa-search"></i>
                    <input type="search"
                           name="search"
                           value="<?php echo esc_attr($search_filter); ?>"
                           placeholder="Search plate, customer, job order, technician, service...">
                </label>
                <button type="submit" class="records-search-btn">Search</button>
                <?php if ($search_filter !== ''): ?>
                    <a class="records-search-clear"
                       href="<?php echo esc_attr(job_order_filter_url($status_filter, $branch_filter, '', $date_filter, 1, $record_filter)); ?>#job-order-records">
                        Clear
                    </a>
                <?php endif; ?>
            </form>
        </div>
    </section>

    <?php if (empty($job_orders)): ?>
        <div class="service-status-empty">No job orders found for this filter.</div>
    <?php else: ?>
        <section class="admin-job-order-panel">
            <div class="admin-job-order-panel-header">
                <div>
                    <h2><?php echo esc_html(record_date_filter_heading('Job Orders', $date_filter)); ?></h2>
                    <p>Showing <?php echo number_format($displayed_from); ?>-<?php echo number_format($displayed_to); ?> of <?php echo number_format($total_records); ?> job orders</p>
                </div>
            </div>

            <div class="job-order-list job-order-records-table admin-job-order-table" role="table" aria-label="Job order records">
                <div class="job-order-records-head" role="row">
                    <span>Customer / Vehicle</span>
                    <span>Job #</span>
                    <span>Date / Branch</span>
                    <span>Technician</span>
                    <span>Services</span>
                    <span>Progress</span>
                    <span>Status</span>
                    <span>Action</span>
                </div>
            <?php foreach ($job_orders as $job): ?>
                <?php
                $job_id = (int) ($job['id'] ?? 0);
                $job_number = job_order_number($job);
                $status = $job['status'] ?? 'waiting';
                $services = $services_by_quotation[(int) ($job['quotation_id'] ?? 0)] ?? [];
                $visible_services = array_slice($services, 0, 2);
                $hidden_service_count = max(0, count($services) - count($visible_services));
                $note = app_format_record_notes($job['notes'] ?? '');
                $technician = trim($job['assigned_technician_name'] ?? '') ?: 'Unassigned';
                $display_date = job_display_date($job['job_date'] ?? $job['created_at'] ?? '');
                $phone = $job['customer_phone'] ?: ($job['customer_contact'] ?? '');
                $progress = $progress_by_job[$job_id] ?? ['tasks' => [], 'total' => 0, 'done' => 0, 'percent' => 0];
                $progress_percent = (int) ($progress['percent'] ?? 0);
                ?>
                <article class="job-order-card job-order-row admin-job-order-row" role="row">
                    <div class="job-order-cell job-order-primary" role="cell">
                        <strong><?php echo esc_html($job['customer_name'] ?? 'Customer'); ?></strong>
                        <span><?php echo esc_html(job_vehicle_label($job)); ?></span>
                        <?php if ($phone): ?>
                            <small><?php echo esc_html($phone); ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="job-order-cell" role="cell">
                        <strong><?php echo esc_html($job_number); ?></strong>
                        <span>Job Order</span>
                    </div>
                    <div class="job-order-cell" role="cell">
                        <strong><?php echo esc_html($display_date); ?></strong>
                        <span><?php echo esc_html(job_branch_label($job['branch_name'] ?? '')); ?></span>
                    </div>
                    <div class="job-order-cell" role="cell">
                        <strong><?php echo esc_html($technician); ?></strong>
                    </div>
                    <div class="job-order-cell job-order-services" role="cell">
                        <?php if (empty($visible_services)): ?>
                            <span class="job-order-muted">No services listed</span>
                        <?php else: ?>
                            <div class="job-service-chip-list">
                                <?php foreach ($visible_services as $service): ?>
                                    <span><?php echo esc_html($service); ?></span>
                                <?php endforeach; ?>
                                <?php if ($hidden_service_count > 0): ?>
                                    <span><?php echo '+' . (int) $hidden_service_count . ' more'; ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="job-order-cell service-status-progress-cell service-progress-readonly" role="cell">
                        <div class="service-progress-track" aria-label="Job order progress">
                            <span style="width: <?php echo $progress_percent; ?>%;"></span>
                        </div>
                        <small class="service-progress-copy">
                            <strong><?php echo $progress_percent; ?>%</strong> completed
                            <span>(<?php echo (int) ($progress['done'] ?? 0); ?>/<?php echo (int) ($progress['total'] ?? 0); ?> done)</span>
                        </small>
                    </div>
                    <div class="job-order-cell" role="cell">
                        <span class="job-status-pill status-<?php echo esc_attr($status); ?>">
                            <?php echo esc_html(job_status_label($status)); ?>
                        </span>
                    </div>
                    <div class="job-order-cell job-order-action" role="cell" style="display: flex; gap: 6px; align-items: center; justify-content: flex-end;">
                        <button type="button" class="job-details-button admin-job-detail-trigger" data-bs-toggle="modal" data-bs-target="#jobDetailsModal<?php echo $job_id; ?>" title="View Job Order" aria-label="View Job Order">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </article>

                <div class="modal fade job-details-modal" id="jobDetailsModal<?php echo $job_id; ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <div>
                                    <h2>Job Order Details</h2>
                                    <p>Job Order ID: <?php echo esc_html($job_number); ?></p>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>

                            <div class="modal-body">
                                <section class="job-detail-summary">
                                    <div>
                                        <span>Customer</span>
                                        <strong><?php echo esc_html($job['customer_name'] ?? 'Customer'); ?></strong>
                                        <p><?php echo esc_html($phone ?: '-'); ?></p>
                                    </div>
                                    <div>
                                        <span>Vehicle</span>
                                        <strong><?php echo esc_html(job_vehicle_label($job, false)); ?></strong>
                                        <p><?php echo esc_html($job['plate_number'] ?: '-'); ?></p>
                                    </div>
                                </section>

                                <section class="job-detail-grid">
                                    <div>
                                        <span>Branch</span>
                                        <strong><?php echo esc_html(job_branch_label($job['branch_name'] ?? '')); ?></strong>
                                    </div>
                                    <div>
                                        <span>Date Created</span>
                                        <strong><?php echo esc_html($display_date); ?></strong>
                                    </div>
                                    <div>
                                        <span>Status</span>
                                        <strong>
                                            <span class="job-status-pill status-<?php echo esc_attr($status); ?>">
                                                <?php echo esc_html(job_status_label($status)); ?>
                                            </span>
                                        </strong>
                                    </div>
                                    <div>
                                        <span>Assigned To</span>
                                        <strong><?php echo esc_html($technician); ?></strong>
                                    </div>
                                    <?php if (!empty($job['estimated_duration'])): ?>
                                        <div>
                                            <span>Estimated Duration</span>
                                            <strong><?php echo esc_html($job['estimated_duration']); ?></strong>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <span>Service Operation</span>
                                        <strong><?php echo esc_html($job['quotation_number'] ?: '-'); ?></strong>
                                    </div>
                                    <div>
                                        <span>Sales in Charge</span>
                                        <strong><?php echo esc_html($job['created_by_name'] ?: '-'); ?></strong>
                                    </div>
                                </section>

                                <section class="job-detail-section service-progress-readonly">
                                    <h3>Service Progress</h3>
                                    <div class="service-progress-track" aria-label="Job order progress">
                                        <span style="width: <?php echo $progress_percent; ?>%;"></span>
                                    </div>
                                    <p class="service-progress-copy">
                                        <strong><?php echo $progress_percent; ?>%</strong> completed
                                        <span>(<?php echo (int) ($progress['done'] ?? 0); ?>/<?php echo (int) ($progress['total'] ?? 0); ?> done)</span>
                                    </p>
                                    <div class="service-task-list service-task-list-modal" aria-label="Read only job order checklist">
                                        <?php foreach (($progress['tasks'] ?? []) as $task): ?>
                                            <?php
                                            $task_done = !empty($task['is_done']);
                                            $task_quantity = max(1, (int) ($task['quantity'] ?? 1));
                                            ?>
                                            <label class="service-task-check <?php echo $task_done ? 'is-done' : ''; ?>">
                                                <input type="checkbox" <?php echo $task_done ? 'checked' : ''; ?> disabled>
                                                <span>
                                                    <?php echo esc_html($task['task_name']); ?>
                                                    <?php if ($task_quantity > 1): ?>
                                                        <small>x<?php echo $task_quantity; ?></small>
                                                    <?php endif; ?>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </section>

                                <?php
                                $has_inspection = trim((string) ($job['inspection_complaint'] ?? '')) !== ''
                                    || trim((string) ($job['inspection_findings'] ?? '')) !== ''
                                    || trim((string) ($job['inspection_recommendations'] ?? '')) !== ''
                                    || !empty($job['inspection_mileage']);
                                ?>
                                <?php if ($has_inspection): ?>
                                    <section class="job-detail-section job-inspection-section">
                                        <h3>Service Inspection</h3>
                                        <div class="quotation-inspection-record">
                                            <?php if (!empty($job['inspection_mileage'])): ?>
                                                <div>
                                                    <span>Current Mileage</span>
                                                    <strong><?php echo number_format((int) $job['inspection_mileage']); ?> km</strong>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (trim((string) ($job['inspection_complaint'] ?? '')) !== ''): ?>
                                                <div>
                                                    <span>Customer Concern</span>
                                                    <p><?php echo nl2br(esc_html($job['inspection_complaint'])); ?></p>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (trim((string) ($job['inspection_findings'] ?? '')) !== ''): ?>
                                                <div>
                                                    <span>Inspection Findings</span>
                                                    <p><?php echo nl2br(esc_html($job['inspection_findings'])); ?></p>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (trim((string) ($job['inspection_recommendations'] ?? '')) !== ''): ?>
                                                <div>
                                                    <span>Recommended Action</span>
                                                    <p><?php echo nl2br(esc_html($job['inspection_recommendations'])); ?></p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </section>
                                <?php endif; ?>

                                <section class="job-detail-section">
                                    <h3>Services Requested</h3>
                                    <?php if (empty($services)): ?>
                                        <p class="job-detail-muted">No services listed</p>
                                    <?php else: ?>
                                        <div class="job-service-chip-list">
                                            <?php foreach ($services as $service): ?>
                                                <span><?php echo esc_html($service); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </section>

                                <section class="job-detail-section">
                                    <h3>Notes</h3>
                                    <p><?php echo esc_html($note !== '' ? $note : 'No notes added'); ?></p>
                                </section>
                            </div>

                            <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <?php if ($status === 'archived'): ?>
                                        <form method="POST" action="/hwtires/api/job-orders-api.php" style="display:inline;" onsubmit="return confirm('Restore / Unarchive this job order?');">
                                            <input type="hidden" name="action" value="unarchive">
                                            <input type="hidden" name="id" value="<?php echo (int) $job_id; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo esc_attr(get_csrf_token()); ?>">
                                            <input type="hidden" name="redirect" value="<?php echo esc_attr($_SERVER['REQUEST_URI'] ?? './'); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-success">
                                                <i class="fas fa-rotate-left"></i> Restore / Unarchive
                                            </button>
                                        </form>
                                    <?php elseif (in_array($status, ['completed', 'waiting', 'cancelled', 'rejected'], true)): ?>
                                        <form method="POST" action="/hwtires/api/job-orders-api.php" style="display:inline;" onsubmit="return confirm('Archive this job order?');">
                                            <input type="hidden" name="action" value="archive">
                                            <input type="hidden" name="id" value="<?php echo (int) $job_id; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo esc_attr(get_csrf_token()); ?>">
                                            <input type="hidden" name="redirect" value="<?php echo esc_attr($_SERVER['REQUEST_URI'] ?? './'); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                <i class="fas fa-box-archive"></i> Archive Job Order
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <button type="button" class="job-modal-close-btn" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </section>

        <?php if ($total_pages > 1): ?>
            <nav class="service-pagination" aria-label="Job order pages">
                <ul class="pagination-list">
                    <?php if ($page > 1): ?>
                        <li>
                            <a class="pagination-link pagination-text-link"
                               href="<?php echo esc_attr(job_order_filter_url($status_filter, $branch_filter, $search_filter, $date_filter, 1, $record_filter)); ?>#job-order-records">
                                First
                            </a>
                        </li>
                        <li>
                            <a class="pagination-link pagination-text-link"
                               href="<?php echo esc_attr(job_order_filter_url($status_filter, $branch_filter, $search_filter, $date_filter, $page - 1, $record_filter)); ?>#job-order-records">
                                Previous
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php $last_page_link = 0; ?>
                    <?php foreach ($pagination_pages as $i): ?>
                        <?php if ($last_page_link > 0 && $i > $last_page_link + 1): ?>
                            <li><span class="pagination-link pagination-ellipsis">...</span></li>
                        <?php endif; ?>
                        <li>
                            <a class="pagination-link <?php echo $i === $page ? 'active' : ''; ?>"
                               href="<?php echo esc_attr(job_order_filter_url($status_filter, $branch_filter, $search_filter, $date_filter, $i, $record_filter)); ?>#job-order-records">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php $last_page_link = $i; ?>
                    <?php endforeach; ?>
                    <?php if ($page < $total_pages): ?>
                        <li>
                            <a class="pagination-link pagination-text-link"
                               href="<?php echo esc_attr(job_order_filter_url($status_filter, $branch_filter, $search_filter, $date_filter, $page + 1, $record_filter)); ?>#job-order-records">
                                Next
                            </a>
                        </li>
                        <li>
                            <a class="pagination-link pagination-text-link"
                               href="<?php echo esc_attr(job_order_filter_url($status_filter, $branch_filter, $search_filter, $date_filter, $total_pages, $record_filter)); ?>#job-order-records">
                                Last
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</main>

<?php require_once '../../includes/footer.php'; ?>
