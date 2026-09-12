<?php
/**
 * Customer List / Records
 */

require_once '../../includes/config.php';
require_once '../../includes/record-filters.php';
require_once '../../includes/customer-vehicle-records.php';
session_name(SESSION_NAME);
session_start();

// Check authentication
if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$page_title = 'Customer and Vehicle';
$user = app_get_session_user();
$is_read_only = true;

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    set_flash_message('Admin is view-only for customer records.', 'warning');
    redirect($_SERVER['HTTP_REFERER']);
}

// Get search and filter parameters
$search = trim($_GET['search'] ?? '');
$branch_filter = $_GET['branch'] ?? '';
$branch_filter = $branch_filter !== '' ? intval($branch_filter) : '';
if ($branch_filter !== '' && $branch_filter <= 0) {
    $branch_filter = '';
}
$status_filter = cv_records_status_filter_current();
$operation_status_filter = cv_records_operation_filter_current();
$record_filter = record_archive_filter_current();
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * RECORDS_PER_PAGE;
$date_filter = record_date_filter_current();

// Branch options for admin filter
$branches = $pdo->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name ASC")->fetchAll();

if (!function_exists('admin_customers_column_exists')) {
    function admin_customers_column_exists($table, $column) {
        return app_column_exists($table, $column);
    }
}

if (!function_exists('admin_customer_branch_label')) {
    function admin_customer_branch_label($name) {
        return app_branch_label($name, '-');
    }
}

if (!function_exists('admin_customer_vehicle_name')) {
    function admin_customer_vehicle_name($vehicle) {
        $name = trim(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? ''));
        return $name !== '' ? $name : 'Vehicle';
    }
}

$customers_has_phone_mobile = admin_customers_column_exists('customers', 'phone_mobile');

$branch_names_by_id = [];
foreach ($branches as $branch) {
    $branch_names_by_id[(int) $branch['id']] = admin_customer_branch_label($branch['name'] ?? '');
}

$vehicle_main_activity_expr = record_activity_datetime_expr('v.last_service_date', 'v.created_at', 'v.updated_at');
$vehicle_main_record_date_expr = record_business_datetime_expr('v.last_service_date', 'v.created_at');
$vehicle_job_record_date_expr = record_business_datetime_expr('jo_date.job_date', 'jo_date.created_at');
$vehicle_quote_record_date_expr = record_business_datetime_expr('q_date.quotation_date', 'q_date.created_at');
$vehicle_sort_expr = "GREATEST(
    COALESCE((
        SELECT MAX(sh.service_date)
        FROM service_history sh
        WHERE sh.vehicle_id = v.id
    ), '1000-01-01'),
    COALESCE((
        SELECT MAX(jo.job_date)
        FROM job_orders jo
        WHERE jo.vehicle_id = v.id
          AND jo.status <> 'cancelled'
    ), '1000-01-01'),
    COALESCE((
        SELECT MAX(q.quotation_date)
        FROM quotations q
        WHERE q.vehicle_id = v.id
          AND q.status <> 'archived'
    ), '1000-01-01'),
    COALESCE(v.last_service_date, '1000-01-01'),
    COALESCE(DATE(v.created_at), '1000-01-01')
)";

$where = [
    "TRIM(CONCAT(COALESCE(v.make, ''), ' ', COALESCE(v.model, ''))) <> ''",
    "COALESCE(NULLIF(TRIM(v.plate_number), ''), '-') <> '-'"
];
$params = [];

if ($record_filter === 'active') {
    $where[] = "v.status = 'active'";
    $where[] = "c.status = 'active'";
} elseif ($record_filter === 'archived') {
    $where[] = "(v.status = 'inactive' OR c.status = 'inactive')";
}

if ($branch_filter !== '') {
    $where[] = "COALESCE(
        (
            SELECT sh_last.branch_id
            FROM (
                SELECT sh.branch_id, CAST(CONCAT(sh.service_date, ' ', COALESCE(TIME(sh.created_at), '00:00:00')) AS DATETIME) as act_date, 1 as rk, sh.id as sid
                FROM service_history sh WHERE sh.vehicle_id = v.id AND sh.branch_id IS NOT NULL
                UNION ALL
                SELECT jo.branch_id, CAST(CONCAT(jo.job_date, ' ', COALESCE(TIME(jo.updated_at), TIME(jo.created_at), '00:00:00')) AS DATETIME) as act_date, 2 as rk, jo.id as sid
                FROM job_orders jo WHERE jo.vehicle_id = v.id AND jo.branch_id IS NOT NULL AND jo.status NOT IN ('archived', 'cancelled')
                UNION ALL
                SELECT q.branch_id, CAST(CONCAT(q.quotation_date, ' ', COALESCE(TIME(q.updated_at), TIME(q.created_at), '00:00:00')) AS DATETIME) as act_date, 3 as rk, q.id as sid
                FROM quotations q WHERE q.vehicle_id = v.id AND q.branch_id IS NOT NULL AND q.status <> 'archived'
            ) sh_last
            ORDER BY sh_last.act_date DESC, sh_last.rk ASC, sh_last.sid DESC
            LIMIT 1
        ),
        v.branch_id,
        c.branch_id,
        1
    ) = ?";
    $params[] = $branch_filter;
}

if (!empty($search)) {
    foreach (app_search_terms($search) as $term) {
        $term_variants = app_search_term_variants($term);
        $term_group_conditions = [];
        foreach ($term_variants as $v_term) {
            $search_param = "%" . strtolower($v_term) . "%";
            $search_conditions = [
                "LOWER(c.name) LIKE ?",
                "LOWER(COALESCE(c.email, '')) LIKE ?",
                "LOWER(COALESCE(c.contact, '')) LIKE ?",
                "LOWER(COALESCE(v.make, '')) LIKE ?",
                "LOWER(COALESCE(v.model, '')) LIKE ?",
                "LOWER(COALESCE(v.plate_number, '')) LIKE ?",
                "EXISTS (
                    SELECT 1
                    FROM branches b_search
                    WHERE b_search.id = v.branch_id
                      AND LOWER(b_search.name) LIKE ?
                )",
                "EXISTS (
                    SELECT 1
                    FROM service_history sh_search
                    INNER JOIN branches b_sh_search ON b_sh_search.id = sh_search.branch_id
                    WHERE sh_search.vehicle_id = v.id
                      AND LOWER(b_sh_search.name) LIKE ?
                )",
                "EXISTS (
                    SELECT 1
                    FROM job_orders jo_search
                    INNER JOIN branches b_jo_search ON b_jo_search.id = jo_search.branch_id
                    WHERE jo_search.vehicle_id = v.id
                      AND jo_search.status NOT IN ('archived', 'cancelled')
                      AND LOWER(b_jo_search.name) LIKE ?
                )",
                "EXISTS (
                    SELECT 1
                    FROM quotations q_search
                    INNER JOIN branches b_q_search ON b_q_search.id = q_search.branch_id
                    WHERE q_search.vehicle_id = v.id
                      AND q_search.status <> 'archived'
                      AND LOWER(b_q_search.name) LIKE ?
                )"
            ];

            if ($customers_has_phone_mobile) {
                $search_conditions[] = "LOWER(COALESCE(c.phone_mobile, '')) LIKE ?";
            }

            $term_group_conditions[] = '(' . implode(' OR ', $search_conditions) . ')';
            $params = array_merge($params, array_fill(0, count($search_conditions), $search_param));
        }
        $where[] = '(' . implode(' OR ', $term_group_conditions) . ')';
    }
}

$vehicle_date_params = [];
$vehicle_date_conditions = [];
$vehicle_main_date = record_date_filter_condition($vehicle_main_record_date_expr, $date_filter, $vehicle_date_params);
if ($vehicle_main_date !== '') {
    $vehicle_date_conditions[] = $vehicle_main_date;

    $service_date_params = [];
    $service_date_condition = record_date_filter_condition($vehicle_history_record_date_expr, $date_filter, $service_date_params);
    $vehicle_date_conditions[] = "EXISTS (
        SELECT 1 FROM service_history sh_date
        WHERE sh_date.vehicle_id = v.id
          AND $service_date_condition
    )";
    $vehicle_date_params = array_merge($vehicle_date_params, $service_date_params);

    $job_date_params = [];
    $job_date_condition = record_date_filter_condition($vehicle_job_record_date_expr, $date_filter, $job_date_params);
    $vehicle_date_conditions[] = "EXISTS (
        SELECT 1 FROM job_orders jo_date
        WHERE jo_date.vehicle_id = v.id
          AND jo_date.status NOT IN ('archived', 'cancelled')
          AND $job_date_condition
    )";
    $vehicle_date_params = array_merge($vehicle_date_params, $job_date_params);

    $quote_date_params = [];
    $quote_date_condition = record_date_filter_condition($vehicle_quote_record_date_expr, $date_filter, $quote_date_params);
    $vehicle_date_conditions[] = "EXISTS (
        SELECT 1 FROM quotations q_date
        WHERE q_date.vehicle_id = v.id
          AND q_date.status <> 'archived'
          AND $quote_date_condition
    )";
    $vehicle_date_params = array_merge($vehicle_date_params, $quote_date_params);

    $where[] = '(' . implode(' OR ', $vehicle_date_conditions) . ')';
    $params = array_merge($params, $vehicle_date_params);
}

$where_sql = implode(' AND ', $where);

$query = "SELECT v.id AS vehicle_id,
                 v.customer_id,
                 v.make,
                 v.model,
                 v.plate_number,
                 v.branch_id AS vehicle_branch_id,
                 v.status AS vehicle_record_status,
                 c.id AS customer_id,
                 c.name AS customer_name,
                 c.status AS customer_record_status,
                 c.contact,
                 c.phone_mobile,
                 c.email,
                 GREATEST(
                     COALESCE((SELECT MAX(sh.service_date) FROM service_history sh WHERE sh.vehicle_id = v.id), '1000-01-01'),
                     COALESCE((SELECT MAX(jo.job_date) FROM job_orders jo WHERE jo.vehicle_id = v.id AND jo.status <> 'cancelled'), '1000-01-01'),
                     COALESCE((SELECT MAX(q.quotation_date) FROM quotations q WHERE q.vehicle_id = v.id AND q.status <> 'archived'), '1000-01-01'),
                     COALESCE(v.last_service_date, '1000-01-01'),
                     COALESCE(DATE(v.created_at), '1000-01-01')
                 ) AS latest_activity_date
          FROM vehicles v
          INNER JOIN customers c ON c.id = v.customer_id
          WHERE $where_sql
          ORDER BY latest_activity_date DESC, v.id DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$all_vehicle_records = $stmt->fetchAll();

$all_vehicle_ids = array_map('intval', array_column($all_vehicle_records, 'vehicle_id'));
$service_operation_summary_by_vehicle = cv_records_load_latest_service_operation_summary($pdo, $all_vehicle_ids);
$raw_service_status_summary_by_vehicle = cv_records_load_latest_service_status_summary($pdo, $all_vehicle_ids);
$service_status_summary_by_vehicle = cv_records_align_service_status_with_operation($pdo, $service_operation_summary_by_vehicle, $raw_service_status_summary_by_vehicle);
$operation_status_by_vehicle = [];
foreach ($service_operation_summary_by_vehicle as $summary_vehicle_id => $operation_summary) {
    $operation_status_by_vehicle[(int) $summary_vehicle_id] = $operation_summary['status'] ?? 'no-service';
}
$service_status_by_vehicle = [];
foreach ($service_status_summary_by_vehicle as $summary_vehicle_id => $summary) {
    $service_status_by_vehicle[(int) $summary_vehicle_id] = $summary['status'] ?? 'no-service';
}

if ($operation_status_filter !== 'all') {
    $all_vehicle_records = array_values(array_filter($all_vehicle_records, static function ($record) use ($operation_status_by_vehicle, $operation_status_filter) {
        $vehicle_id = (int) ($record['vehicle_id'] ?? 0);
        $operation_status = $operation_status_by_vehicle[$vehicle_id] ?? 'no-service';
        return cv_records_operation_status_class($operation_status) === $operation_status_filter;
    }));
}

if ($status_filter !== 'all') {
    $all_vehicle_records = array_values(array_filter($all_vehicle_records, static function ($record) use ($service_status_by_vehicle, $status_filter) {
        $vehicle_id = (int) ($record['vehicle_id'] ?? 0);
        $service_status = $service_status_by_vehicle[$vehicle_id] ?? 'no-service';
        return cv_records_service_status_class($service_status) === $status_filter;
    }));
}

$total_records = count($all_vehicle_records);
$total_pages = max(1, (int) ceil($total_records / RECORDS_PER_PAGE));
$page = min($page, $total_pages);
$offset = ($page - 1) * RECORDS_PER_PAGE;
$vehicle_records = array_slice($all_vehicle_records, $offset, RECORDS_PER_PAGE);

$modal_customers = [];
foreach ($vehicle_records as $record) {
    $customer_id = (int) ($record['customer_id'] ?? 0);
    if ($customer_id <= 0 || isset($modal_customers[$customer_id])) {
        continue;
    }

    $modal_customers[$customer_id] = [
        'id' => $customer_id,
        'name' => $record['customer_name'] ?? '',
        'contact' => $record['contact'] ?? '',
        'phone_mobile' => $record['phone_mobile'] ?? '',
        'email' => $record['email'] ?? '',
    ];
}
[$vehicles_by_customer, $service_history_by_vehicle, $ownership_history_by_vehicle, $availed_items_by_vehicle] = cv_records_load($pdo, array_keys($modal_customers), $record_filter !== 'active');

$last_visit_branch_by_vehicle = [];
$last_visit_activity_by_vehicle = [];
$vehicle_ids = array_map('intval', array_column($vehicle_records, 'vehicle_id'));
$job_modal_id_by_vehicle = [];
$job_ids_for_modals = [];
if (!empty($vehicle_ids)) {
    $last_visit_activity_by_vehicle = cv_records_load_latest_branch_activity($pdo, $vehicle_ids, $record_filter !== 'active');
    foreach ($last_visit_activity_by_vehicle as $activity_vehicle_id => $activity) {
        $last_visit_branch_by_vehicle[$activity_vehicle_id] = (int) ($activity['branch_id'] ?? 0);
    }

    foreach ($vehicle_ids as $vehicle_id) {
        $job_id = (int) ($service_status_summary_by_vehicle[$vehicle_id]['job_id'] ?? 0);
        if ($job_id <= 0) {
            continue;
        }

        $job_modal_id_by_vehicle[$vehicle_id] = 'customerJobDetailsModal' . $job_id;
        $job_ids_for_modals[] = $job_id;
    }
}

[$job_details_by_id, $job_services_by_quotation, $job_progress_by_id] = cv_records_load_job_order_details($pdo, $job_ids_for_modals);

$pagination_params = '';
if ($search !== '') {
    $pagination_params .= '&search=' . urlencode($search);
}
if ($branch_filter !== '') {
    $pagination_params .= '&branch=' . urlencode((string) $branch_filter);
}
if ($status_filter !== 'all') {
    $pagination_params .= '&status=' . urlencode((string) $status_filter);
}
if ($operation_status_filter !== 'all') {
    $pagination_params .= '&operation_status=' . urlencode((string) $operation_status_filter);
}
if ($record_filter !== 'active') {
    $pagination_params .= '&records=' . urlencode((string) $record_filter);
}
$pagination_params .= record_date_filter_query_string($date_filter);

?>

<?php require_once '../../includes/header.php'; ?>
<?php require_once '../../includes/sidebar.php'; ?>

<div class="customer-records-page">
    <section class="customer-records-hero">
        <h1>Customer and Vehicle Records</h1>
        <p>View customer and vehicle information across all branches</p>
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

    <section class="customer-records-filter-card">
        <form method="GET" action="./#customer-records" class="customer-records-filter" data-record-date-filter>
            <label class="customer-filter-field customer-branch-field">
                <span>Branch</span>
                <select name="branch" class="customer-branch-select">
                    <option value="">All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <?php $branch_label = preg_replace('/\s*-\s*.*/', '', $branch['name']); ?>
                        <option value="<?php echo (int) $branch['id']; ?>" <?php echo $branch_filter === (int) $branch['id'] ? 'selected' : ''; ?>>
                            <?php echo esc_html($branch_label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="customer-filter-field customer-operation-field">
                <span>Service Operation</span>
                <select name="operation_status" class="customer-operation-select">
                    <?php foreach (cv_records_operation_filter_options() as $operation_value => $operation_label): ?>
                        <option value="<?php echo esc_attr($operation_value); ?>" <?php echo $operation_status_filter === $operation_value ? 'selected' : ''; ?>>
                            <?php echo esc_html($operation_label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="customer-filter-field customer-status-field">
                <span>Service Status</span>
                <select name="status" class="customer-status-select">
                    <?php foreach (cv_records_status_filter_options() as $status_value => $status_label): ?>
                        <option value="<?php echo esc_attr($status_value); ?>" <?php echo $status_filter === $status_value ? 'selected' : ''; ?>>
                            <?php echo esc_html($status_label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="customer-filter-field customer-record-field">
                <span>Status</span>
                <select name="records" class="customer-record-select">
                    <?php foreach (record_archive_filter_options() as $record_value => $record_label): ?>
                        <option value="<?php echo esc_attr($record_value); ?>" <?php echo $record_filter === $record_value ? 'selected' : ''; ?>>
                            <?php echo esc_html($record_label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="customer-filter-field customer-date-scope-field">
                <span>Period</span>
                <select name="date_scope" class="records-date-scope customer-date-select" aria-label="Select record period">
                    <option value="all" <?php echo ($date_filter['scope'] ?? 'all') === 'all' ? 'selected' : ''; ?>>All Records</option>
                    <option value="recent" <?php echo ($date_filter['scope'] ?? '') === 'recent' ? 'selected' : ''; ?>>Current Week</option>
                    <option value="day" <?php echo ($date_filter['scope'] ?? '') === 'day' ? 'selected' : ''; ?>>Day</option>
                    <option value="week" <?php echo ($date_filter['scope'] ?? '') === 'week' ? 'selected' : ''; ?>>Week</option>
                    <option value="month" <?php echo ($date_filter['scope'] ?? '') === 'month' ? 'selected' : ''; ?>>Month</option>
                    <option value="year" <?php echo ($date_filter['scope'] ?? '') === 'year' ? 'selected' : ''; ?>>Year</option>
                    <option value="range" <?php echo ($date_filter['scope'] ?? '') === 'range' ? 'selected' : ''; ?>>Date Range</option>
                </select>
            </label>
            <label class="customer-filter-field" data-date-input="day">
                <span>Day</span>
                <input type="date" name="date_day" value="<?php echo esc_attr($date_filter['day']); ?>">
            </label>
            <label class="customer-filter-field" data-date-input="week">
                <span>Week</span>
                <input type="week" name="date_week" value="<?php echo esc_attr($date_filter['week']); ?>">
            </label>
            <label class="customer-filter-field" data-date-input="month">
                <span>Month</span>
                <input type="month" name="date_month" value="<?php echo esc_attr($date_filter['month']); ?>">
            </label>
            <label class="customer-filter-field" data-date-input="year">
                <span>Year</span>
                <input type="number" name="date_year" min="2020" max="2100" value="<?php echo (int) $date_filter['year']; ?>">
            </label>
            <label class="customer-filter-field" data-date-input="range">
                <span>From</span>
                <input type="date" name="date_from" value="<?php echo esc_attr($date_filter['from']); ?>">
            </label>
            <label class="customer-filter-field" data-date-input="range">
                <span>To</span>
                <input type="date" name="date_to" value="<?php echo esc_attr($date_filter['to']); ?>">
            </label>
            <button type="submit" class="customer-records-submit customer-records-apply-btn">Apply</button>
            <label class="customer-search-field">
                <i class="fas fa-search"></i>
                <input type="text" name="search" maxlength="100" data-text-format="first-letter" placeholder="Search by vehicle, plate number, customer, or branch..." value="<?php echo esc_attr($search); ?>">
            </label>
            <button type="submit" class="customer-records-search-btn btn btn-primary">Search</button>
            <?php if ($search !== '' || $branch_filter !== '' || $status_filter !== 'all' || $operation_status_filter !== 'all' || $record_filter !== 'active' || ($date_filter['scope'] ?? 'all') !== 'all'): ?>
                <a href="./#customer-records" class="btn btn-outline-secondary customer-records-clear">Clear</a>
            <?php endif; ?>
        </form>
    </section>

    <section class="customer-records-table-card" id="customer-records">
        <div class="table-responsive">
            <table class="customer-records-table">
                <thead>
                    <tr>
                        <th>Vehicle Name</th>
                        <th>Plate Number</th>
                        <th>Customer Name</th>
                        <th>Contact Number</th>
                        <th>Last Visited Branch</th>
                        <th>Service Operation</th>
                        <th>Service Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($vehicle_records)): ?>
                    <tr>
                        <td colspan="8" class="customer-records-empty">No vehicle records found</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($vehicle_records as $record): ?>
                        <?php
                        $vehicle_id = (int) ($record['vehicle_id'] ?? 0);
                        $vehicle_name = admin_customer_vehicle_name($record);
                        $last_branch_id = $last_visit_branch_by_vehicle[$vehicle_id] ?? intval($record['vehicle_branch_id'] ?? 0);
                        $last_branch_label = $branch_names_by_id[$last_branch_id] ?? '-';
                        $operation_summary = $service_operation_summary_by_vehicle[$vehicle_id] ?? [];
                        $operation_status = $operation_summary['status'] ?? 'no-service';
                        $operation_id = (int) ($operation_summary['quotation_id'] ?? 0);
                        $service_summary = $service_status_summary_by_vehicle[$vehicle_id] ?? [];
                        $service_status = $service_summary['status'] ?? 'no-service';
                        $is_archived_record = strtolower((string) ($record['vehicle_record_status'] ?? 'active')) === 'inactive'
                            || strtolower((string) ($record['customer_record_status'] ?? 'active')) === 'inactive';
                        $vehicle_profile_url = '/hwtires/admin/vehicles/profile.php?id=' . $vehicle_id;
                        $operation_status_class = $is_archived_record ? 'archived' : cv_records_operation_status_class($operation_status);
                        $operation_status_label = $is_archived_record ? 'Archived' : ($operation_id > 0 ? cv_records_operation_status_label($operation_status) : 'No Operation');
                        $service_status_class = $is_archived_record ? 'archived' : cv_records_service_status_class($service_status);
                        $service_status_label = $is_archived_record ? 'Archived' : cv_records_service_status_label($service_status);
                        $status_has_job_modal = isset($job_modal_id_by_vehicle[$vehicle_id]);
                        $status_modal_target = $status_has_job_modal ? $job_modal_id_by_vehicle[$vehicle_id] : '';
                        ?>
                        <tr>
                            <td>
                                <a class="vehicle-name-link" href="<?php echo esc_attr($vehicle_profile_url); ?>">
                                    <strong><?php echo esc_html($vehicle_name); ?></strong>
                                </a>
                            </td>
                            <td>
                                <?php if (!empty($record['plate_number'])): ?>
                                    <span class="customer-plate-pill"><?php echo esc_html($record['plate_number']); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="customer-name-link">
                                    <strong><?php echo esc_html($record['customer_name'] ?? '-'); ?></strong>
                                </span>
                            </td>
                            <td><?php echo esc_html(($record['phone_mobile'] ?? '') ?: (($record['contact'] ?? '') ?: '-')); ?></td>
                            <td>
                                <div class="customer-branch-pill-list">
                                    <?php if ($last_branch_label === '-'): ?>
                                        <span class="customer-branch-pill customer-branch-empty">-</span>
                                    <?php else: ?>
                                        <span class="customer-branch-pill customer-branch-plain"><?php echo esc_html($last_branch_label); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if (!$is_archived_record && $operation_id > 0): ?>
                                    <a class="customer-vehicle-status-pill customer-status-action status-<?php echo esc_attr($operation_status_class); ?>"
                                       href="/hwtires/admin/quotations/view.php?id=<?php echo $operation_id; ?>">
                                        <?php echo esc_html($operation_status_label); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="customer-vehicle-status-pill status-<?php echo esc_attr($operation_status_class); ?>">
                                        <?php echo esc_html($operation_status_label); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($status_has_job_modal): ?>
                                    <button type="button"
                                            class="customer-vehicle-status-pill customer-status-action status-<?php echo esc_attr($service_status_class); ?>"
                                            data-bs-toggle="modal"
                                            data-bs-target="#<?php echo esc_attr($status_modal_target); ?>">
                                        <?php echo esc_html($service_status_label); ?>
                                    </button>
                                <?php else: ?>
                                    <span class="customer-vehicle-status-pill status-<?php echo esc_attr($service_status_class); ?>">
                                        <?php echo esc_html($service_status_label); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button"
                                        class="customer-view-action customer-history-open"
                                        title="View service history"
                                        data-bs-toggle="modal"
                                        data-bs-target="#customerVehicleModal<?php echo (int) ($record['customer_id'] ?? 0); ?>"
                                        data-history-vehicle-id="<?php echo $vehicle_id; ?>">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="customer-records-count">
        Showing <?php echo count($vehicle_records); ?> of <?php echo (int) $total_records; ?> vehicles
    </div>

    <?php if ($total_pages > 1): ?>
    <nav class="customer-records-pagination" aria-label="Customer records pages">
        <ul class="pagination justify-content-center">
            <?php if ($page > 1): ?>
            <li class="page-item">
                <a class="page-link" href="?page=1<?php echo $pagination_params; ?>">First</a>
            </li>
            <li class="page-item">
                <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo $pagination_params; ?>">Previous</a>
            </li>
            <?php endif; ?>

            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                <a class="page-link" href="?page=<?php echo $i; ?><?php echo $pagination_params; ?>">
                    <?php echo $i; ?>
                </a>
            </li>
            <?php endfor; ?>

            <?php if ($page < $total_pages): ?>
            <li class="page-item">
                <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo $pagination_params; ?>">Next</a>
            </li>
            <li class="page-item">
                <a class="page-link" href="?page=<?php echo $total_pages; ?><?php echo $pagination_params; ?>">Last</a>
            </li>
            <?php endif; ?>
        </ul>
    </nav>
    <?php endif; ?>

    <?php foreach ($modal_customers as $modal_customer): ?>
        <?php cv_records_render_history_modal($modal_customer, $vehicles_by_customer, $service_history_by_vehicle, [
            'allow_add_vehicle' => false,
            'branches' => $branches,
            'default_branch_id' => $branch_filter !== '' ? (int) $branch_filter : 0,
            'ownership_history_by_vehicle' => $ownership_history_by_vehicle,
            'availed_items_by_vehicle' => $availed_items_by_vehicle,
        ]); ?>
    <?php endforeach; ?>

    <?php foreach ($job_details_by_id as $job_id => $job_detail): ?>
        <?php
        $quotation_id = (int) ($job_detail['quotation_id'] ?? 0);
        cv_records_render_job_order_details_modal(
            $job_detail,
            $job_services_by_quotation[$quotation_id] ?? [],
            $job_progress_by_id[(int) $job_id] ?? [],
            'customerJobDetailsModal'
        );
        ?>
    <?php endforeach; ?>

</div>

<?php cv_records_render_history_script(); ?>
<?php record_date_filter_script(); ?>

<?php require_once '../../includes/footer.php'; ?>
