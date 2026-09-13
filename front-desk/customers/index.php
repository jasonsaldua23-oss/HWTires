<?php
/**
 * Front-Desk Customer Records
 */

require_once '../../includes/config.php';
require_once '../../includes/record-filters.php';
require_once '../../includes/customer-vehicle-records.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$page_title = 'Customer and Vehicle';
$user = app_get_session_user();
$user_branch_id = intval($user['branch_id'] ?? 0);
$can_manage_records = can_modify_records();

if (isset($_GET['action']) && in_array($_GET['action'], ['delete', 'archive'], true) && isset($_GET['id'])) {
    if (!verify_csrf_token($_GET['csrf_token'] ?? '')) {
        set_flash_message('Invalid security token', 'danger');
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM customers WHERE id = ? AND status = 'active'");
            $stmt->execute([intval($_GET['id'])]);
            $record = $stmt->fetch();

            if (!$record) {
                throw new Exception('Customer not found');
            }

            enforce_modify_permission();

            app_deactivate_customer_branch_record($record['id'], $user_branch_id);
            set_flash_message('Customer branch record archived successfully', 'success');
            log_audit('customer_branch_records', 'archive', $record['id'], null, [
                'branch_id' => $user_branch_id,
                'status' => 'inactive',
                'records_preserved' => true,
            ]);
        } catch (Exception $e) {
            set_flash_message($e->getMessage(), 'danger');
        }
    }

    redirect($_SERVER['HTTP_REFERER'] ?? '/hwtires/front-desk/customers/');
}

$search = trim($_GET['search'] ?? '');
$raw_branch_filter = array_key_exists('branch', $_GET)
    ? trim((string) $_GET['branch'])
    : ($user_branch_id > 0 ? (string) $user_branch_id : '');
$branch_filter = ($raw_branch_filter === 'all' || $raw_branch_filter === '')
    ? (array_key_exists('branch', $_GET) ? '' : ($user_branch_id > 0 ? $user_branch_id : ''))
    : intval($raw_branch_filter);
if ($branch_filter !== '' && $branch_filter <= 0) {
    $branch_filter = $user_branch_id > 0 ? $user_branch_id : '';
}
$branch_query_value = $branch_filter === '' ? 'all' : (string) $branch_filter;
$status_filter = cv_records_status_filter_current();
$operation_status_filter = cv_records_operation_filter_current();
$record_filter = record_archive_filter_current();

$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * RECORDS_PER_PAGE;
$current_url = $_SERVER['REQUEST_URI'] ?? '/hwtires/front-desk/customers/';
$date_filter = record_date_filter_current();

if (!function_exists('front_customer_branch_label')) {
    function front_customer_branch_label($name) {
        return app_branch_label($name, '-');
    }
}

if (!function_exists('front_customer_vehicle_name')) {
    function front_customer_vehicle_name($vehicle) {
        $name = trim(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? ''));
        return $name !== '' ? $name : 'Vehicle';
    }
}

$branches = $pdo->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY id ASC")->fetchAll();
$branch_names_by_id = [];
foreach ($branches as $branch) {
    $branch_names_by_id[(int) $branch['id']] = front_customer_branch_label($branch['name'] ?? '');
}
$add_vehicle_customer_options = $pdo->query("
    SELECT id,
           name,
           COALESCE(NULLIF(phone_mobile, ''), NULLIF(contact, ''), '') AS contact_number
    FROM customers
    WHERE status = 'active'
    ORDER BY name ASC
")->fetchAll();

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
                SELECT sh.vehicle_id, sh.branch_id, CAST(CONCAT(sh.service_date, ' ', COALESCE(TIME(sh.created_at), '00:00:00')) AS DATETIME) as act_date, 1 as rk, sh.id as sid
                FROM service_history sh WHERE sh.branch_id IS NOT NULL
                UNION ALL
                SELECT jo.vehicle_id, jo.branch_id, CAST(CONCAT(jo.job_date, ' ', COALESCE(TIME(jo.updated_at), TIME(jo.created_at), '00:00:00')) AS DATETIME) as act_date, 2 as rk, jo.id as sid
                FROM job_orders jo WHERE jo.branch_id IS NOT NULL AND jo.status NOT IN ('archived', 'cancelled')
                UNION ALL
                SELECT q.vehicle_id, q.branch_id, CAST(CONCAT(q.quotation_date, ' ', COALESCE(TIME(q.updated_at), TIME(q.created_at), '00:00:00')) AS DATETIME) as act_date, 3 as rk, q.id as sid
                FROM quotations q WHERE q.branch_id IS NOT NULL AND q.status <> 'archived'
            ) sh_last
            WHERE sh_last.vehicle_id = v.id
            ORDER BY sh_last.act_date DESC, sh_last.rk ASC, sh_last.sid DESC
            LIMIT 1
        ),
        v.branch_id,
        c.branch_id,
        1
    ) = ?";
    $params[] = $branch_filter;
}

if ($search !== '') {
    foreach (app_search_terms($search) as $term) {
        $term_variants = app_search_term_variants($term);
        $term_group_conditions = [];
        foreach ($term_variants as $v_term) {
            $search_param = "%" . strtolower($v_term) . "%";
            $search_conditions = [
                "LOWER(c.name) LIKE ?",
                "LOWER(COALESCE(c.email, '')) LIKE ?",
                "LOWER(COALESCE(c.contact, '')) LIKE ?",
                "LOWER(COALESCE(c.phone_mobile, '')) LIKE ?",
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
                 c.address,
                 c.customer_type,
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
foreach ($service_status_summary_by_vehicle as $summary_vehicle_id => $service_summary) {
    $service_status_by_vehicle[(int) $summary_vehicle_id] = $service_summary['status'] ?? 'no-service';
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
        'address' => $record['address'] ?? '',
        'customer_type' => $record['customer_type'] ?? 'individual',
    ];
}
[$vehicles_by_customer, $service_history_by_vehicle, $ownership_history_by_vehicle, $availed_items_by_vehicle] = cv_records_load($pdo, array_keys($modal_customers), $record_filter !== 'active');

$last_visit_branch_by_vehicle = [];
$last_visit_activity_by_vehicle = [];
$vehicle_ids = array_map('intval', array_column($vehicle_records, 'vehicle_id'));
if (!empty($vehicle_ids)) {
    $last_visit_activity_by_vehicle = cv_records_load_latest_branch_activity($pdo, $vehicle_ids, $record_filter !== 'active');
    foreach ($last_visit_activity_by_vehicle as $activity_vehicle_id => $activity) {
        $last_visit_branch_by_vehicle[$activity_vehicle_id] = (int) ($activity['branch_id'] ?? 0);
    }
}

$pagination_params = '';
if ($search !== '') {
    $pagination_params .= '&search=' . urlencode($search);
}
if (array_key_exists('branch', $_GET) || $branch_filter !== '') {
    $pagination_params .= '&branch=' . urlencode($branch_query_value);
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
    <section class="customer-records-topbar">
        <div class="customer-records-hero">
            <h1>Customer and Vehicle Records</h1>
            <p>Manage customer and vehicle information across all branches</p>
        </div>
        <button class="customer-add-btn" type="button" data-bs-toggle="modal" data-bs-target="#addVehicleChoiceModal">
            <i class="fas fa-plus"></i>
            <span>Add Vehicle</span>
        </button>
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

    <section class="customer-records-filter-card">
        <form method="GET" action="./#customer-records" class="customer-records-filter" data-record-date-filter>
            <label class="customer-filter-field customer-branch-field">
                <span>Branch</span>
                <select name="branch" class="customer-branch-select">
                    <option value="all" <?php echo $branch_filter === '' ? 'selected' : ''; ?>>All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <?php $branch_label = front_customer_branch_label($branch['name']); ?>
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
                <input id="customerSearchInput" type="text" name="search" maxlength="100" data-text-format="first-letter" placeholder="Search by vehicle, plate number, customer, or branch..." value="<?php echo esc_attr($search); ?>">
            </label>
            <button type="submit" class="customer-records-search-btn btn btn-primary">Search</button>
            <?php if ($search !== '' || (array_key_exists('branch', $_GET) && $branch_filter !== $user_branch_id) || $status_filter !== 'all' || $operation_status_filter !== 'all' || $record_filter !== 'active' || ($date_filter['scope'] ?? 'all') !== 'all'): ?>
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
                        $customer_id = (int) ($record['customer_id'] ?? 0);
                        $vehicle_name = front_customer_vehicle_name($record);
                        $last_branch_id = $last_visit_branch_by_vehicle[$vehicle_id] ?? intval($record['vehicle_branch_id'] ?? 0);
                        $last_branch_label = $branch_names_by_id[$last_branch_id] ?? '-';
                        $operation_summary = $service_operation_summary_by_vehicle[$vehicle_id] ?? [];
                        $operation_status = $operation_summary['status'] ?? 'no-service';
                        $operation_id = (int) ($operation_summary['quotation_id'] ?? 0);
                        $operation_branch_id = (int) ($operation_summary['quotation_branch_id'] ?? 0);
                        $service_summary = $service_status_summary_by_vehicle[$vehicle_id] ?? [];
                        $service_status = $service_summary['status'] ?? 'no-service';
                        $service_status_job_id = (int) ($service_summary['job_id'] ?? 0);
                        $service_status_job_branch_id = (int) ($service_summary['job_branch_id'] ?? 0);
                        $is_archived_record = strtolower((string) ($record['vehicle_record_status'] ?? 'active')) === 'inactive'
                            || strtolower((string) ($record['customer_record_status'] ?? 'active')) === 'inactive';
                        $vehicle_profile_url = '/hwtires/front-desk/vehicles/profile.php?id=' . $vehicle_id;
                        $operation_status_class = $is_archived_record ? 'archived' : cv_records_operation_status_class($operation_status);
                        $operation_status_label = $is_archived_record ? 'Archived' : ($operation_id > 0 ? cv_records_operation_status_label($operation_status) : 'No Operation');
                        $service_status_class = $is_archived_record ? 'archived' : cv_records_service_status_class($service_status);
                        $service_status_label = $is_archived_record ? 'Archived' : cv_records_service_status_label($service_status);
                        $can_open_service_operation = !$is_archived_record && $operation_id > 0 && $operation_branch_id === $user_branch_id;
                        $can_open_service_status = !$is_archived_record && $service_status_job_id > 0 && $service_status_job_branch_id === $user_branch_id;
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
                                <?php if ($can_open_service_operation): ?>
                                    <a class="customer-vehicle-status-pill customer-status-action status-<?php echo esc_attr($operation_status_class); ?>"
                                       href="/hwtires/front-desk/quotations/view.php?id=<?php echo $operation_id; ?>">
                                        <?php echo esc_html($operation_status_label); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="customer-vehicle-status-pill status-<?php echo esc_attr($operation_status_class); ?>">
                                        <?php echo esc_html($operation_status_label); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($can_open_service_status): ?>
                                    <a class="customer-vehicle-status-pill customer-status-action status-<?php echo esc_attr($service_status_class); ?>"
                                       href="/hwtires/front-desk/service-status/?job_id=<?php echo $service_status_job_id; ?>#service-records">
                                        <?php echo esc_html($service_status_label); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="customer-vehicle-status-pill status-<?php echo esc_attr($service_status_class); ?>">
                                        <?php echo esc_html($service_status_label); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="customer-row-actions">
                                    <button type="button"
                                            class="customer-icon-action view customer-history-open"
                                            title="View service history"
                                            data-bs-toggle="modal"
                                            data-bs-target="#customerVehicleModal<?php echo $customer_id; ?>"
                                            data-history-vehicle-id="<?php echo $vehicle_id; ?>">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if (!$is_archived_record): ?>
                                        <button type="button"
                                                class="customer-icon-action edit"
                                                title="Edit customer"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editCustomerModal"
                                                data-id="<?php echo $customer_id; ?>"
                                                data-name="<?php echo esc_attr($record['customer_name'] ?? ''); ?>"
                                                data-phone-mobile="<?php echo esc_attr($record['phone_mobile'] ?? ''); ?>"
                                                data-contact="<?php echo esc_attr($record['contact'] ?? ''); ?>"
                                                data-address="<?php echo esc_attr($record['address'] ?? ''); ?>"
                                                data-customer-type="<?php echo esc_attr($record['customer_type'] ?? 'individual'); ?>">
                                            <i class="fas fa-pen-to-square"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($can_manage_records): ?>
                                        <?php if ($is_archived_record): ?>
                                            <form method="POST" action="/hwtires/api/vehicles-api.php" class="customer-inline-action-form" onsubmit="return confirm('Restore this vehicle record?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                                <input type="hidden" name="action" value="restore">
                                                <input type="hidden" name="id" value="<?php echo $vehicle_id; ?>">
                                                <input type="hidden" name="redirect" value="<?php echo esc_attr($current_url); ?>">
                                                <button type="submit" class="customer-icon-action restore" title="Restore vehicle">
                                                    <i class="fas fa-rotate-left"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" action="/hwtires/api/vehicles-api.php" class="customer-inline-action-form" onsubmit="return confirm('Archive this vehicle record? The record will be hidden but kept in the database.');">
                                                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                                <input type="hidden" name="action" value="archive">
                                                <input type="hidden" name="id" value="<?php echo $vehicle_id; ?>">
                                                <input type="hidden" name="redirect" value="<?php echo esc_attr($current_url); ?>">
                                                <button type="submit" class="customer-icon-action delete" title="Archive vehicle">
                                                    <i class="fas fa-box-archive"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
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
            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                <a class="page-link" href="?page=<?php echo $i; ?><?php echo $pagination_params; ?>"><?php echo $i; ?></a>
            </li>
            <?php endfor; ?>
        </ul>
    </nav>
    <?php endif; ?>

    <?php foreach ($modal_customers as $modal_customer): ?>
        <?php cv_records_render_history_modal($modal_customer, $vehicles_by_customer, $service_history_by_vehicle, [
            'allow_add_vehicle' => true,
            'allow_delete_vehicle' => $record_filter === 'active',
            'delete_vehicle_branch_id' => $user_branch_id,
            'delete_vehicle_requires_branch_match' => false,
            'delete_vehicle_action' => '/hwtires/api/vehicles-api.php',
            'redirect' => $current_url,
            'allow_start_service_operation' => true,
            'start_service_base_url' => '/hwtires/front-desk/quotations/create.php',
            'branches' => $branches,
            'default_branch_id' => $branch_filter !== '' ? (int) $branch_filter : 0,
            'ownership_history_by_vehicle' => $ownership_history_by_vehicle,
            'availed_items_by_vehicle' => $availed_items_by_vehicle,
        ]); ?>
    <?php endforeach; ?>

</div>

<?php cv_records_render_history_script(); ?>

<div class="modal fade" id="addVehicleChoiceModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content customer-form-modal customer-choice-modal">
            <div class="modal-header">
                <h5 class="modal-title">Add Vehicle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="customer-flow-options">
                    <button type="button" class="customer-flow-option" data-customer-flow-target="addVehicleModal">
                        <span class="customer-flow-icon"><i class="fas fa-user-check"></i></span>
                        <span>
                            <strong>Select Existing Customer</strong>
                            <small>Link a new vehicle to a customer already in the records.</small>
                        </span>
                    </button>
                    <button type="button" class="customer-flow-option" data-customer-flow-target="addCustomerModal">
                        <span class="customer-flow-icon"><i class="fas fa-user-plus"></i></span>
                        <span>
                            <strong>Create New Customer</strong>
                            <small>Add the customer and their first vehicle in one entry.</small>
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable customer-add-dialog">
        <div class="modal-content customer-form-modal">
            <div class="modal-header">
                <h5 class="modal-title">Create New Customer and Vehicle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="/hwtires/api/customers-api.php">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="redirect" value="<?php echo esc_attr($current_url); ?>">
                <input type="hidden" name="customer_type" value="individual">
                <div class="modal-body customer-add-body">
                    <section class="customer-modal-section">
                        <h3>Customer Information</h3>
                        <div class="form-group">
                            <label class="form-label required">Customer Name</label>
                            <input type="text" class="form-control" name="name" maxlength="100" data-text-format="person-name" placeholder="Enter customer name" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label required">Contact Number</label>
                            <input type="tel" class="form-control" name="phone_mobile" inputmode="numeric" minlength="11" maxlength="11" pattern="09[0-9]{9}" placeholder="e.g., 09171234567" autocomplete="tel" data-phone-input required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Address</label>
                            <textarea class="form-control" name="address" rows="2" maxlength="255" data-text-format="first-letter" placeholder="e.g., 852 Rosario Street, Bacolod City"></textarea>
                        </div>
                    </section>

                    <section class="customer-modal-section vehicle-section">
                        <h3>Vehicle Information</h3>
                        <div class="customer-form-grid">
                            <div class="form-group">
                                <label class="form-label required">Vehicle Make</label>
                                <select class="form-select vehicle-make-select" name="vehicle_make" id="add_cust_vehicle_make" required>
                                    <option value="" selected disabled>-- Select Car Brand --</option>
                                </select>
                                <input type="text" class="form-control vehicle-make-custom mt-2" name="vehicle_make_custom" id="add_cust_vehicle_make_custom" maxlength="50" data-text-format="first-letter" placeholder="Type custom car brand..." style="display: none;">
                            </div>
                            <div class="form-group">
                                <label class="form-label required">Vehicle Model</label>
                                <select class="form-select vehicle-model-select" name="vehicle_model" id="add_cust_vehicle_model" required disabled>
                                    <option value="" selected disabled>-- Select Brand First --</option>
                                </select>
                                <input type="text" class="form-control vehicle-model-custom mt-2" name="vehicle_model_custom" id="add_cust_vehicle_model_custom" maxlength="50" data-text-format="first-letter" placeholder="Type custom model..." style="display: none;">
                            </div>
                            <div class="form-group">
                                <label class="form-label required">Plate Number</label>
                                <input type="text" class="form-control" name="vehicle_plate_number" maxlength="8" pattern="[A-Z]{3}-[0-9]{4}" placeholder="ABC-1234" title="Use the ABC-1234 format" autocomplete="off" autocapitalize="characters" data-plate-input required>
                            </div>
                            <div class="form-group">
                                <label class="form-label required">Last Mileage (km)</label>
                                <input type="number" class="form-control" name="vehicle_last_mileage" min="0" step="1" placeholder="45000" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Year</label>
                                <input type="number" class="form-control" name="vehicle_year" min="1900" max="<?php echo date('Y') + 1; ?>" value="<?php echo date('Y'); ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Color</label>
                                <input type="text" class="form-control" name="vehicle_color" maxlength="50" data-text-format="first-letter" placeholder="White, Black, etc.">
                            </div>
                        </div>
                    </section>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn customer-add-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn customer-add-submit">Add Customer and Vehicle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content customer-form-modal">
            <div class="modal-header">
                <h5 class="modal-title">Edit Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="/hwtires/api/customers-api.php">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="redirect" value="<?php echo esc_attr($current_url); ?>">
                <input type="hidden" name="id" id="edit_customer_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label required">Full Name</label>
                        <input type="text" class="form-control" name="name" id="edit_customer_name" maxlength="100" data-text-format="person-name" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label required">Contact Number</label>
                        <input type="tel" class="form-control" name="contact" id="edit_customer_contact" inputmode="numeric" minlength="11" maxlength="11" pattern="09[0-9]{9}" placeholder="e.g., 09171234567" autocomplete="tel" data-phone-input required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Address</label>
                        <textarea class="form-control" name="address" id="edit_customer_address" rows="2" maxlength="255" data-text-format="first-letter" placeholder="e.g., 852 Rosario Street, Bacolod City"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label required">Customer Type</label>
                        <select class="form-select" name="customer_type" id="edit_customer_type" required>
                            <option value="individual">Individual</option>
                            <option value="corporate">Corporate</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="addVehicleModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered customer-add-dialog">
        <div class="modal-content customer-form-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="addVehicleModalTitle">Add Vehicle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="/hwtires/api/customers-api.php">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="add_vehicle">
                <input type="hidden" name="redirect" value="<?php echo esc_attr($current_url); ?>">
                <div class="modal-body customer-add-body">
                    <section class="customer-modal-section">
                        <h3>Customer</h3>
                        <div class="form-group" style="position: relative;">
                            <label class="form-label required">Select Existing Customer</label>
                            <input type="hidden" name="customer_id" id="add_vehicle_customer_id" required>
                            <div style="position: relative;">
                                <input type="text" id="add_vehicle_customer_input" class="form-control" maxlength="100" placeholder="🔍 Type customer name or phone..." autocomplete="off" required style="padding-right: 32px; font-size: 0.95rem;">
                                <button type="button" id="add_vehicle_customer_clear_btn" title="Clear selection" style="display: none; position: absolute; right: 10px; top: 50%; transform: translateY(-50%); border: none; background: transparent; color: #94a3b8; font-size: 16px; cursor: pointer; line-height: 1;">&times;</button>
                            </div>
                            <div id="add_vehicle_customer_dropdown" class="tag-autocomplete-dropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; box-shadow: 0 12px 28px rgba(0,0,0,0.15); max-height: 240px; overflow-y: auto; z-index: 1065; margin-top: 4px;"></div>
                        </div>
                    </section>
                    <section class="customer-modal-section vehicle-section">
                        <h3>Vehicle Information</h3>
                        <div class="customer-form-grid">
                            <div class="form-group">
                                <label class="form-label required">Vehicle Make</label>
                                <select class="form-select vehicle-make-select" name="make" id="add_veh_make" required>
                                    <option value="" selected disabled>-- Select Car Brand --</option>
                                </select>
                                <input type="text" class="form-control vehicle-make-custom mt-2" name="make_custom" id="add_veh_make_custom" maxlength="50" data-text-format="first-letter" placeholder="Type custom car brand..." style="display: none;">
                            </div>
                            <div class="form-group">
                                <label class="form-label required">Vehicle Model</label>
                                <select class="form-select vehicle-model-select" name="model" id="add_veh_model" required disabled>
                                    <option value="" selected disabled>-- Select Brand First --</option>
                                </select>
                                <input type="text" class="form-control vehicle-model-custom mt-2" name="model_custom" id="add_veh_model_custom" maxlength="50" data-text-format="first-letter" placeholder="Type custom model..." style="display: none;">
                            </div>
                            <div class="form-group">
                                <label class="form-label required">Plate Number</label>
                                <input type="text" class="form-control" name="plate_number" maxlength="8" pattern="[A-Z]{3}-[0-9]{4}" placeholder="ABC-1234" title="Use the ABC-1234 format" autocomplete="off" autocapitalize="characters" data-plate-input required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Last Mileage (km)</label>
                                <input type="number" class="form-control" name="last_mileage" min="0" step="1" placeholder="45000">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Year</label>
                                <input type="number" class="form-control" name="year" min="1900" max="<?php echo date('Y') + 1; ?>" value="<?php echo date('Y'); ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Color</label>
                                <input type="text" class="form-control" name="color" maxlength="50" data-text-format="first-letter" placeholder="White, Black, etc.">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Condition</label>
                                <select class="form-select" name="condition">
                                    <option value="excellent">Excellent</option>
                                    <option value="good" selected>Good</option>
                                    <option value="fair">Fair</option>
                                    <option value="poor">Poor</option>
                                </select>
                            </div>
                        </div>
                    </section>
                    <div class="customer-add-vehicle-note">
                        This vehicle will be linked to the selected customer and recorded under your current branch for audit history.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn customer-add-cancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn customer-add-submit">Add Vehicle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    function normalizePhone(value) {
        return String(value || '').replace(/\D/g, '').slice(0, 11);
    }

    document.querySelectorAll('[data-phone-input]').forEach(function(input) {
        input.addEventListener('input', function() {
            input.value = normalizePhone(input.value);
        });
    });

    const editModal = document.getElementById('editCustomerModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            if (!button) return;

            document.getElementById('edit_customer_id').value = button.dataset.id || '';
            document.getElementById('edit_customer_name').value = button.dataset.name || '';
            const phoneVal = button.dataset.contact || button.dataset.phoneMobile || '';
            document.getElementById('edit_customer_contact').value = normalizePhone(phoneVal);
            document.getElementById('edit_customer_address').value = button.dataset.address || '';
            document.getElementById('edit_customer_type').value = button.dataset.customerType || 'individual';
        });
    }

    const customerOptions = <?php echo json_encode(array_map(function($c) {
        return [
            'id' => (int) $c['id'],
            'name' => trim((string) ($c['name'] ?? '')),
            'phone' => trim((string) ($c['contact_number'] ?? '')),
        ];
    }, $add_vehicle_customer_options)); ?>;

    const customerHidden = document.getElementById('add_vehicle_customer_id');
    const customerInput = document.getElementById('add_vehicle_customer_input');
    const customerDropdown = document.getElementById('add_vehicle_customer_dropdown');
    const customerClearBtn = document.getElementById('add_vehicle_customer_clear_btn');

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function renderCustomerSuggestions(query = '') {
        if (!customerDropdown) return;
        const q = query.toLowerCase().trim();
        let matches = [];

        if (q === '') {
            matches = customerOptions.slice(0, 15);
        } else {
            matches = customerOptions.filter(function(c) {
                const name = (c.name || '').toLowerCase();
                const phone = (c.phone || '').toLowerCase();
                return name.includes(q) || phone.includes(q);
            }).slice(0, 15);
        }

        let html = '';
        if (matches.length === 0) {
            html = '<div style="padding: 10px 12px; color: #94a3b8; font-size: 0.85rem; text-align: center;">No matching customer found</div>';
        } else {
            matches.forEach(function(c) {
                const isSelected = customerHidden && customerHidden.value === String(c.id);
                html += '<div class="tag-autocomplete-item ' + (isSelected ? 'active' : '') + '" data-id="' + c.id + '" data-name="' + escapeHtml(c.name) + '" data-phone="' + escapeHtml(c.phone || '') + '">';
                html += '<div style="font-weight: 600; font-size: 0.88rem; color: #1e293b;">' + escapeHtml(c.name) + '</div>';
                if (c.phone) {
                    html += '<div style="font-size: 0.76rem; color: #64748b;"><i class="fas fa-phone" style="font-size: 10px; margin-right: 4px;"></i>' + escapeHtml(c.phone) + '</div>';
                }
                html += '</div>';
            });
        }

        customerDropdown.innerHTML = html;
        customerDropdown.style.display = 'block';
    }

    function selectCustomer(id, name, phone) {
        if (!id) {
            if (customerHidden) customerHidden.value = '';
            if (customerInput) customerInput.value = '';
            if (customerClearBtn) customerClearBtn.style.display = 'none';
        } else {
            if (customerHidden) customerHidden.value = id;
            if (customerInput) customerInput.value = name + (phone ? ' (' + phone + ')' : '');
            if (customerClearBtn) customerClearBtn.style.display = 'block';
        }
        if (customerDropdown) customerDropdown.style.display = 'none';
    }

    if (customerInput) {
        customerInput.addEventListener('focus', function() {
            renderCustomerSuggestions(this.value);
        });
        customerInput.addEventListener('input', function() {
            renderCustomerSuggestions(this.value);
            if (customerHidden) customerHidden.value = '';
            if (customerClearBtn) customerClearBtn.style.display = this.value ? 'block' : 'none';
        });
    }

    if (customerDropdown) {
        customerDropdown.addEventListener('click', function(e) {
            const item = e.target.closest('.tag-autocomplete-item');
            if (!item) return;
            const id = item.dataset.id || '';
            const name = item.dataset.name || '';
            const phone = item.dataset.phone || '';
            selectCustomer(id, name, phone);
        });
    }

    if (customerClearBtn) {
        customerClearBtn.addEventListener('click', function() {
            selectCustomer('', '', '');
            if (customerInput) customerInput.focus();
        });
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#add_vehicle_customer_input') && !e.target.closest('#add_vehicle_customer_dropdown')) {
            if (customerDropdown) customerDropdown.style.display = 'none';
        }
    });

    const addVehicleModal = document.getElementById('addVehicleModal');
    function fillAddVehicleModal(button) {
        const title = document.getElementById('addVehicleModalTitle');

        if (!button) {
            return;
        }

        const customerId = button.dataset.customerId || '';
        const customerName = button.dataset.customerName || '';

        if (customerId) {
            const found = customerOptions.find(function(c) { return String(c.id) === String(customerId); });
            selectCustomer(customerId, customerName || (found ? found.name : ''), found ? found.phone : '');
        } else {
            selectCustomer('', '', '');
        }

        if (title) {
            title.textContent = customerName ? 'Add Vehicle for ' + customerName : 'Add Vehicle';
        }
    }

    if (addVehicleModal) {
        addVehicleModal.addEventListener('show.bs.modal', function(event) {
            if (event.relatedTarget) {
                fillAddVehicleModal(event.relatedTarget);
            }
        });

        document.querySelectorAll('.customer-add-vehicle-btn').forEach(function(button) {
            button.addEventListener('click', function() {
                fillAddVehicleModal(button);

                const activeModal = button.closest('.modal');
                if (activeModal && window.bootstrap) {
                    const instance = bootstrap.Modal.getInstance(activeModal);
                    if (instance) {
                        instance.hide();
                    }
                }

                if (window.bootstrap) {
                    setTimeout(function() {
                        bootstrap.Modal.getOrCreateInstance(addVehicleModal).show();
                    }, 180);
                }
            });
        });
    }

    document.querySelectorAll('[data-customer-flow-target]').forEach(function(button) {
        button.addEventListener('click', function() {
            const targetId = button.dataset.customerFlowTarget || '';
            const targetModal = targetId ? document.getElementById(targetId) : null;
            const choiceModal = document.getElementById('addVehicleChoiceModal');

            if (!targetModal || !window.bootstrap) {
                return;
            }

            if (targetId === 'addVehicleModal') {
                fillAddVehicleModal(button);
            }

            const choiceInstance = choiceModal ? bootstrap.Modal.getInstance(choiceModal) : null;
            if (choiceInstance) {
                choiceInstance.hide();
            }

            setTimeout(function() {
                bootstrap.Modal.getOrCreateInstance(targetModal).show();
            }, 180);
        });
    });

    <?php if (($_GET['open'] ?? '') === 'add'): ?>
    const addVehicleChoiceModal = document.getElementById('addVehicleChoiceModal');
    if (addVehicleChoiceModal && window.bootstrap) {
        bootstrap.Modal.getOrCreateInstance(addVehicleChoiceModal).show();
    }
    <?php endif; ?>

    <?php if (($_GET['focus'] ?? '') === 'search'): ?>
    const searchInput = document.getElementById('customerSearchInput');
    if (searchInput) {
        searchInput.focus();
    }
    <?php endif; ?>
});
</script>

<?php record_date_filter_script(); ?>
<?php require_once '../../includes/footer.php'; ?>
