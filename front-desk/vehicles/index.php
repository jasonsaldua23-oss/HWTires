<?php
/**
 * Front-Desk Vehicle Records
 */

require_once '../../includes/config.php';
require_once '../../includes/record-filters.php';
require_once '../../includes/customer-vehicle-records.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$page_title = 'Vehicle Records';
$user = app_get_session_user();
$user_branch_id = intval($user['branch_id'] ?? 0);
$can_manage_records = can_modify_records();
$current_url = $_SERVER['REQUEST_URI'] ?? '/hwtires/front-desk/vehicles/';

if (!function_exists('vehicle_records_branch_label')) {
    function vehicle_records_branch_label($name) {
        return app_branch_label($name, '-');
    }
}

if (!function_exists('vehicle_records_name')) {
    function vehicle_records_name($vehicle, $include_plate = false) {
        $name = trim(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? ''));
        if ($name === '') {
            $name = 'Vehicle';
        }

        if ($include_plate && !empty($vehicle['plate_number'])) {
            $name .= ' (' . $vehicle['plate_number'] . ')';
        }

        return $name;
    }
}

if (!function_exists('vehicle_records_plate_branch_class')) {
    function vehicle_records_plate_branch_class($branch_id) {
        $branch_id = intval($branch_id);
        return $branch_id > 0 ? 'vehicle-plate-branch-' . ((($branch_id - 1) % 3) + 1) : 'vehicle-plate-branch-empty';
    }
}

if (!function_exists('vehicle_records_short_date')) {
    function vehicle_records_short_date($date) {
        return !empty($date) ? date('Y-m-d', strtotime($date)) : '-';
    }
}

if (!function_exists('vehicle_records_money')) {
    function vehicle_records_money($amount) {
        return '&#8369;' . number_format((float) $amount, 0);
    }
}

if (!function_exists('vehicle_records_services')) {
    function vehicle_records_services($value) {
        if (empty($value)) {
            return [];
        }

        $parts = preg_split('/[,;\r\n]+/', $value);
        $parts = array_map('trim', $parts);
        return array_values(array_filter($parts, function ($part) {
            return $part !== '';
        }));
    }
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
$record_filter = record_archive_filter_current();
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * RECORDS_PER_PAGE;
$date_filter = record_date_filter_current();
$vehicle_main_activity_expr = record_activity_datetime_expr('v.last_service_date', 'v.created_at', 'v.updated_at');
$vehicle_job_activity_expr = record_activity_datetime_expr('jo_date.job_date', 'jo_date.created_at', 'jo_date.updated_at');
$vehicle_quote_activity_expr = record_activity_datetime_expr('q_date.quotation_date', 'q_date.created_at', 'q_date.updated_at');
$vehicle_history_activity_expr = record_activity_datetime_expr('sh_date.service_date', 'sh_date.created_at');
$vehicle_main_record_date_expr = record_business_datetime_expr('v.last_service_date', 'v.created_at');
$vehicle_job_record_date_expr = record_business_datetime_expr('jo_date.job_date', 'jo_date.created_at');
$vehicle_quote_record_date_expr = record_business_datetime_expr('q_date.quotation_date', 'q_date.created_at');
$vehicle_history_record_date_expr = record_business_datetime_expr('sh_date.service_date', 'sh_date.created_at');
$vehicle_sort_expr = "COALESCE(v.updated_at, v.created_at, v.last_service_date, '1970-01-01 00:00:00')";

$branches = $pdo->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY id ASC")->fetchAll();
$branch_names_by_id = [];
foreach ($branches as $branch) {
    $branch_names_by_id[(int) $branch['id']] = vehicle_records_branch_label($branch['name'] ?? '');
}
$customer_options = [];
if ($can_manage_records) {
    $customer_options = $pdo->query("
        SELECT id, name, phone_mobile, contact
        FROM customers
        WHERE status = 'active'
        ORDER BY name ASC, id ASC
    ")->fetchAll();
}

$where = [
    "TRIM(CONCAT(COALESCE(v.make, ''), ' ', COALESCE(v.model, ''))) <> ''",
    "COALESCE(NULLIF(TRIM(v.plate_number), ''), '-') <> '-'"
];
$params = [];

if ($record_filter === 'active') {
    $where[] = "v.status = 'active'";
} elseif ($record_filter === 'archived') {
    $where[] = "v.status = 'inactive'";
}

if ($branch_filter !== '') {
    $where[] = "(v.branch_id = ? OR EXISTS (
        SELECT 1 FROM service_history sh_branch
        WHERE sh_branch.vehicle_id = v.id AND sh_branch.branch_id = ?
    ) OR EXISTS (
        SELECT 1 FROM job_orders jo_branch
        WHERE jo_branch.vehicle_id = v.id AND jo_branch.branch_id = ?
          AND jo_branch.status <> 'archived'
    ) OR EXISTS (
        SELECT 1 FROM quotations q_branch
        WHERE q_branch.vehicle_id = v.id AND q_branch.branch_id = ?
          AND q_branch.status <> 'archived'
    ))";
    $params = array_merge($params, [$branch_filter, $branch_filter, $branch_filter, $branch_filter]);
}

if ($search !== '') {
    foreach (app_search_terms($search) as $term) {
        $where[] = "(
            c.name LIKE ?
            OR c.contact LIKE ?
            OR c.phone_mobile LIKE ?
            OR v.make LIKE ?
            OR v.model LIKE ?
            OR v.plate_number LIKE ?
            OR EXISTS (
                SELECT 1
                FROM branches b_search
                WHERE b_search.id = v.branch_id
                  AND b_search.name LIKE ?
            )
        )";
        $search_param = "%$term%";
        $params = array_merge($params, array_fill(0, 7, $search_param));
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
          AND jo_date.status <> 'archived'
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

$query = "SELECT v.*, c.name AS customer_name
          FROM vehicles v
          LEFT JOIN customers c ON v.customer_id = c.id
          WHERE $where_sql
          ORDER BY $vehicle_sort_expr DESC, v.id DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$all_vehicles = $stmt->fetchAll();

$all_vehicle_ids = array_map('intval', array_column($all_vehicles, 'id'));
$service_operation_summary_by_vehicle = cv_records_load_latest_operation_summary($pdo, $all_vehicle_ids);
$service_operation_status_by_vehicle = [];
foreach ($service_operation_summary_by_vehicle as $summary_vehicle_id => $operation_summary) {
    $service_operation_status_by_vehicle[$summary_vehicle_id] = $operation_summary['status'] ?? 'no-service';
}

if ($status_filter !== 'all') {
    $all_vehicles = array_values(array_filter($all_vehicles, static function ($vehicle) use ($service_operation_status_by_vehicle, $status_filter) {
        $vehicle_id = (int) ($vehicle['id'] ?? 0);
        $operation_status = $service_operation_status_by_vehicle[$vehicle_id] ?? 'no-service';
        return cv_records_operation_status_class($operation_status) === $status_filter;
    }));
}

$total_records = count($all_vehicles);
$total_pages = max(1, (int) ceil($total_records / RECORDS_PER_PAGE));
$page = min($page, $total_pages);
$offset = ($page - 1) * RECORDS_PER_PAGE;
$vehicles = array_slice($all_vehicles, $offset, RECORDS_PER_PAGE);

$service_history_by_vehicle = [];
$last_visit_branch_by_vehicle = [];
$last_visit_activity_by_vehicle = [];
$vehicle_ids = array_map('intval', array_column($vehicles, 'id'));

if (!empty($vehicle_ids)) {
    $placeholders = implode(',', array_fill(0, count($vehicle_ids), '?'));
    $history_where = ["sh.vehicle_id IN ($placeholders)"];
    $history_params = $vehicle_ids;
    if ($branch_filter !== '') {
        $history_where[] = 'sh.branch_id = ?';
        $history_params[] = $branch_filter;
    }
    $history_where_sql = implode(' AND ', $history_where);

    $history_stmt = $pdo->prepare("
        SELECT sh.*, b.name AS branch_name
        FROM service_history sh
        LEFT JOIN branches b ON b.id = sh.branch_id
        WHERE $history_where_sql
        ORDER BY sh.service_date DESC, sh.id DESC
    ");
    $history_stmt->execute($history_params);

    foreach ($history_stmt->fetchAll() as $entry) {
        $service_history_by_vehicle[intval($entry['vehicle_id'])][] = $entry;
    }

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
if ($record_filter !== 'active') {
    $pagination_params .= '&records=' . urlencode((string) $record_filter);
}
$pagination_params .= record_date_filter_query_string($date_filter);
?>

<?php require_once '../../includes/header.php'; ?>
<?php require_once '../../includes/sidebar.php'; ?>

<div class="vehicle-records-page">
    <section class="vehicle-records-topbar">
        <div class="vehicle-records-hero">
            <h1>Vehicle Records</h1>
            <p>Browse, edit, and add vehicle profiles across all branches</p>
        </div>
        <?php if ($can_manage_records): ?>
            <button class="customer-add-btn vehicle-add-btn" type="button" data-bs-toggle="modal" data-bs-target="#addVehicleRecordModal">
                <i class="fas fa-plus"></i>
                <span>Add Vehicle</span>
            </button>
        <?php endif; ?>
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

    <section class="vehicle-records-filter-card">
        <form method="GET" class="vehicle-records-filter">
            <?php record_date_filter_hidden_inputs(record_date_filter_query_params($date_filter)); ?>
            <label class="vehicle-search-field">
                <i class="fas fa-search"></i>
                <input type="text" name="search" maxlength="100" data-text-format="first-letter" placeholder="Search by plate number, vehicle make/model, or customer name..." value="<?php echo esc_attr($search); ?>">
            </label>
            <select name="branch" class="vehicle-branch-select" onchange="this.form.submit()">
                <option value="all" <?php echo $branch_filter === '' ? 'selected' : ''; ?>>All Branches</option>
                <?php foreach ($branches as $branch): ?>
                    <?php $branch_label = vehicle_records_branch_label($branch['name']); ?>
                    <option value="<?php echo (int) $branch['id']; ?>" <?php echo $branch_filter === (int) $branch['id'] ? 'selected' : ''; ?>>
                        <?php echo esc_html($branch_label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="vehicle-status-select" onchange="this.form.submit()">
                <?php foreach (cv_records_status_filter_options() as $status_value => $status_label): ?>
                    <option value="<?php echo esc_attr($status_value); ?>" <?php echo $status_filter === $status_value ? 'selected' : ''; ?>>
                        <?php echo esc_html($status_label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="records" class="vehicle-record-select" onchange="this.form.submit()">
                <?php foreach (record_archive_filter_options() as $record_value => $record_label): ?>
                    <option value="<?php echo esc_attr($record_value); ?>" <?php echo $record_filter === $record_value ? 'selected' : ''; ?>>
                        <?php echo esc_html($record_label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="vehicle-records-submit">Search</button>
        </form>
        <?php
        record_date_filter_controls($date_filter, [
            'search' => $search,
            'branch' => $branch_query_value,
            'status' => $status_filter,
            'records' => $record_filter !== 'active' ? $record_filter : '',
        ], 'vehicle-records-list');
        ?>
    </section>

    <?php if (empty($vehicles)): ?>
        <section class="vehicle-records-empty">No vehicles found.</section>
    <?php else: ?>
        <section class="vehicle-records-grid" id="vehicle-records-list">
            <?php foreach ($vehicles as $vehicle): ?>
                <?php
                $vehicle_id = (int) $vehicle['id'];
                $modal_id = 'serviceHistoryModal' . $vehicle_id;
                $histories = $service_history_by_vehicle[$vehicle_id] ?? [];
                $plate_branch_id = $last_visit_branch_by_vehicle[$vehicle_id] ?? intval($vehicle['branch_id'] ?? 0);
                $last_branch_id = $plate_branch_id;
                $last_branch_label = $branch_names_by_id[$last_branch_id] ?? '-';
                $operation_status = $service_operation_status_by_vehicle[$vehicle_id] ?? 'no-service';
                $service_status_job_id = (int) ($service_operation_summary_by_vehicle[$vehicle_id]['job_id'] ?? 0);
                $service_status_job_branch_id = (int) ($service_operation_summary_by_vehicle[$vehicle_id]['job_branch_id'] ?? 0);
                $is_archived_record = strtolower((string) ($vehicle['status'] ?? 'active')) === 'inactive';
                $status_class = $is_archived_record ? 'archived' : cv_records_operation_status_class($operation_status);
                $status_label = $is_archived_record ? 'Archived' : cv_records_operation_status_label($operation_status);
                $can_open_service_status = !$is_archived_record && $service_status_job_id > 0 && $service_status_job_branch_id === $user_branch_id;
                ?>
                <article class="vehicle-record-card">
                    <div class="vehicle-record-head">
                        <div>
                            <h2><?php echo esc_html(vehicle_records_name($vehicle)); ?></h2>
                            <p>
                                <?php echo !empty($vehicle['year']) ? esc_html($vehicle['year']) : '-'; ?>
                                &bull;
                                <?php echo esc_html($vehicle['color'] ?? '-'); ?>
                            </p>
                        </div>
                        <?php if (!empty($vehicle['plate_number'])): ?>
                            <span class="customer-plate-pill <?php echo esc_attr(vehicle_records_plate_branch_class($plate_branch_id)); ?>"><?php echo esc_html($vehicle['plate_number']); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="vehicle-record-facts">
                        <div>
                            <span>Owner</span>
                            <strong><?php echo esc_html($vehicle['customer_name'] ?? '-'); ?></strong>
                        </div>
                        <div>
                            <span>Last Service</span>
                            <strong><?php echo esc_html(vehicle_records_short_date($vehicle['last_service_date'] ?? '')); ?></strong>
                        </div>
                        <div>
                            <span>Last Mileage</span>
                            <strong>
                                <?php echo !empty($vehicle['last_mileage']) ? number_format((float) $vehicle['last_mileage']) . ' km' : '-'; ?>
                            </strong>
                        </div>
                        <div>
                            <span>Last Visited Branch</span>
                            <strong>
                                <?php echo esc_html($last_branch_label); ?>
                            </strong>
                        </div>
                        <div>
                            <span>Status</span>
                            <strong>
                                <?php if ($can_open_service_status): ?>
                                    <a class="customer-vehicle-status-pill customer-status-action status-<?php echo esc_attr($status_class); ?>"
                                       href="/hwtires/front-desk/service-status/?job_id=<?php echo $service_status_job_id; ?>#service-records">
                                        <?php echo esc_html($status_label); ?>
                                    </a>
                                <?php else: ?>
                                    <button type="button"
                                            class="customer-vehicle-status-pill customer-status-action status-<?php echo esc_attr($status_class); ?>"
                                            data-bs-toggle="modal"
                                            data-bs-target="#<?php echo esc_attr($modal_id); ?>">
                                        <?php echo esc_html($status_label); ?>
                                    </button>
                                <?php endif; ?>
                            </strong>
                        </div>
                    </div>

                    <div class="vehicle-record-actions <?php echo $can_manage_records ? '' : 'single'; ?>">
                        <button type="button" class="vehicle-history-button" data-bs-toggle="modal" data-bs-target="#<?php echo esc_attr($modal_id); ?>">
                            <i class="fas fa-eye"></i>
                            <span>View History</span>
                        </button>
                        <?php if ($can_manage_records): ?>
                            <?php if (!$is_archived_record): ?>
                                <button type="button" class="vehicle-edit-button" data-bs-toggle="modal" data-bs-target="#editVehicleRecordModal<?php echo $vehicle_id; ?>">
                                    <i class="fas fa-pen-to-square"></i>
                                    <span>Edit Vehicle</span>
                                </button>
                            <?php endif; ?>
                            <?php if ($is_archived_record): ?>
                                <form method="POST" action="/hwtires/api/vehicles-api.php" class="vehicle-archive-form" onsubmit="return confirm('Restore this vehicle record?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                    <input type="hidden" name="action" value="restore">
                                    <input type="hidden" name="id" value="<?php echo $vehicle_id; ?>">
                                    <input type="hidden" name="redirect" value="<?php echo esc_attr($current_url); ?>">
                                    <button type="submit" class="vehicle-archive-button restore">
                                        <i class="fas fa-rotate-left"></i>
                                        <span>Restore</span>
                                    </button>
                                </form>
                            <?php else: ?>
                                <form method="POST" action="/hwtires/api/vehicles-api.php" class="vehicle-archive-form" onsubmit="return confirm('Archive this vehicle record? The record will be hidden but kept in the database.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                    <input type="hidden" name="action" value="archive">
                                    <input type="hidden" name="id" value="<?php echo $vehicle_id; ?>">
                                    <input type="hidden" name="redirect" value="<?php echo esc_attr($current_url); ?>">
                                    <button type="submit" class="vehicle-archive-button">
                                        <i class="fas fa-box-archive"></i>
                                        <span>Archive</span>
                                    </button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </article>

                <?php if ($can_manage_records && !$is_archived_record): ?>
                    <div class="modal fade vehicle-record-form-modal" id="editVehicleRecordModal<?php echo $vehicle_id; ?>" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                            <div class="modal-content">
                                <form method="POST" action="/hwtires/api/vehicles-api.php">
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="redirect" value="<?php echo esc_attr($current_url); ?>">
                                    <input type="hidden" name="id" value="<?php echo $vehicle_id; ?>">
                                    <div class="modal-header">
                                        <div>
                                            <h5 class="modal-title">Edit Vehicle</h5>
                                            <p><?php echo esc_html(vehicle_records_name($vehicle, true)); ?></p>
                                        </div>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="vehicle-record-form-grid">
                                            <div class="form-group">
                                                <label class="form-label required">Vehicle Make</label>
                                                <input type="text" class="form-control" name="make" value="<?php echo esc_attr($vehicle['make'] ?? ''); ?>" maxlength="50" data-text-format="first-letter" required>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label required">Vehicle Model</label>
                                                <input type="text" class="form-control" name="model" value="<?php echo esc_attr($vehicle['model'] ?? ''); ?>" maxlength="50" data-text-format="first-letter" required>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Plate Number</label>
                                                <input type="text" class="form-control" name="plate_number" value="<?php echo esc_attr($vehicle['plate_number'] ?? ''); ?>" maxlength="8" pattern="[A-Z]{3}-[0-9]{4}" title="Use the ABC-1234 format" autocomplete="off" autocapitalize="characters" data-plate-input>
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Year</label>
                                                <input type="number" class="form-control" name="year" min="1900" max="<?php echo date('Y') + 1; ?>" value="<?php echo esc_attr($vehicle['year'] ?? ''); ?>">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Color</label>
                                                <input type="text" class="form-control" name="color" value="<?php echo esc_attr($vehicle['color'] ?? ''); ?>" maxlength="50" data-text-format="first-letter">
                                            </div>
                                            <div class="form-group">
                                                <label class="form-label">Condition</label>
                                                <select class="form-select" name="condition">
                                                    <?php foreach (['excellent' => 'Excellent', 'good' => 'Good', 'fair' => 'Fair', 'poor' => 'Poor'] as $value => $label): ?>
                                                        <option value="<?php echo esc_attr($value); ?>" <?php echo ($vehicle['condition'] ?? 'good') === $value ? 'selected' : ''; ?>>
                                                            <?php echo esc_html($label); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn vehicle-form-cancel" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn vehicle-form-submit">Save Changes</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="modal fade vehicle-history-modal" id="<?php echo esc_attr($modal_id); ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <div>
                                    <h2>Service History</h2>
                                    <p><?php echo esc_html(vehicle_records_name($vehicle, true)); ?></p>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>

                            <div class="modal-body">
                                <?php if (empty($histories)): ?>
                                    <div class="vehicle-history-empty">No service history found for this vehicle.</div>
                                <?php else: ?>
                                    <div class="vehicle-history-list">
                                        <?php foreach ($histories as $entry): ?>
                                            <?php $services = vehicle_records_services($entry['services_description'] ?? 'Service'); ?>
                                            <article class="vehicle-history-entry">
                                                <div class="vehicle-history-top">
                                                    <div>
                                                        <h3><?php echo esc_html(vehicle_records_short_date($entry['service_date'] ?? '')); ?></h3>
                                                        <p><?php echo esc_html(vehicle_records_branch_label($entry['branch_name'] ?? '')); ?></p>
                                                    </div>
                                                    <div class="vehicle-history-cost">
                                                        <strong><?php echo vehicle_records_money($entry['total_cost']); ?></strong>
                                                        <span>
                                                            <?php echo !empty($entry['mileage_at_service']) ? number_format((float) $entry['mileage_at_service']) . ' km' : '-'; ?>
                                                        </span>
                                                    </div>
                                                </div>

                                                <h4>Services Performed:</h4>
                                                <div class="vehicle-history-chips">
                                                    <?php if (empty($services)): ?>
                                                        <span>Service</span>
                                                    <?php else: ?>
                                                        <?php foreach ($services as $service): ?>
                                                            <span><?php echo esc_html($service); ?></span>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </div>

                                                <?php if (!empty($entry['notes'])): ?>
                                                    <div class="vehicle-history-notes">
                                                        <strong>Notes:</strong>
                                                        <span><?php echo esc_html(app_format_record_notes($entry['notes'])); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="modal-footer">
                                <button type="button" class="vehicle-history-close" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <div class="vehicle-records-count">
        Showing <?php echo count($vehicles); ?> of <?php echo (int) $total_records; ?> vehicles
    </div>

    <?php if ($total_pages > 1): ?>
    <nav class="vehicle-records-pagination" aria-label="Vehicle records pages">
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
                <a class="page-link" href="?page=<?php echo $i; ?><?php echo $pagination_params; ?>"><?php echo $i; ?></a>
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

    <?php if ($can_manage_records): ?>
        <div class="modal fade vehicle-record-form-modal" id="addVehicleRecordModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <form method="POST" action="/hwtires/api/vehicles-api.php">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="redirect" value="<?php echo esc_attr($current_url); ?>">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title">Add Vehicle</h5>
                                <p>Create a vehicle profile under an existing customer</p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="vehicle-record-form-grid">
                                <div class="form-group vehicle-form-wide" style="position: relative;">
                                    <label class="form-label required">Customer</label>
                                    <input type="hidden" name="customer_id" id="addVehicleCustomerId" required>
                                    <div style="position: relative;">
                                        <input type="text" id="addVehicleCustomerInput" class="form-control" maxlength="100" placeholder="🔍 Type customer name or phone..." autocomplete="off" required style="padding-right: 32px; font-size: 0.95rem;">
                                        <button type="button" id="addVehicleCustomerClearBtn" title="Clear selection" style="display: none; position: absolute; right: 10px; top: 50%; transform: translateY(-50%); border: none; background: transparent; color: #94a3b8; font-size: 16px; cursor: pointer; line-height: 1;">&times;</button>
                                    </div>
                                    <div id="addVehicleCustomerDropdown" class="tag-autocomplete-dropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; box-shadow: 0 12px 28px rgba(0,0,0,0.15); max-height: 240px; overflow-y: auto; z-index: 1065; margin-top: 4px;"></div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label required">Vehicle Make</label>
                                    <select class="form-select vehicle-make-select" name="make" id="veh_idx_add_make" required>
                                        <option value="" selected disabled>-- Select Car Brand --</option>
                                    </select>
                                    <input type="text" class="form-control vehicle-make-custom mt-2" name="make_custom" id="veh_idx_add_make_custom" maxlength="50" data-text-format="first-letter" placeholder="New Vehicle Make * (e.g., Jetour)" style="display: none;">
                                </div>
                                <div class="form-group">
                                    <label class="form-label required">Vehicle Model</label>
                                    <select class="form-select vehicle-model-select" name="model" id="veh_idx_add_model" required disabled>
                                        <option value="" selected disabled>-- Select Brand First --</option>
                                    </select>
                                    <input type="text" class="form-control vehicle-model-custom mt-2" name="model_custom" id="veh_idx_add_model_custom" maxlength="50" data-text-format="first-letter" placeholder="New Vehicle Model * (e.g., X70)" style="display: none;">
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
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn vehicle-form-cancel" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn vehicle-form-submit">Add Vehicle</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.tag-autocomplete-dropdown {
    scrollbar-width: thin;
}
.tag-autocomplete-dropdown::-webkit-scrollbar {
    width: 6px;
}
.tag-autocomplete-dropdown::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 3px;
}
.tag-autocomplete-item {
    padding: 9px 12px;
    cursor: pointer;
    border-bottom: 1px solid #f1f5f9;
    transition: background 0.15s ease;
}
.tag-autocomplete-item:hover, .tag-autocomplete-item.active {
    background: #e6fcff;
}
.tag-autocomplete-item:last-child {
    border-bottom: none;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const customerOptions = <?php echo json_encode(array_map(function($c) {
        $phone = ($c['phone_mobile'] ?? '') ?: ($c['contact'] ?? '');
        return [
            'id' => (int) $c['id'],
            'name' => trim((string) ($c['name'] ?? '')),
            'phone' => trim((string) $phone),
        ];
    }, $customer_options)); ?>;

    const customerHidden = document.getElementById('addVehicleCustomerId');
    const customerInput = document.getElementById('addVehicleCustomerInput');
    const customerDropdown = document.getElementById('addVehicleCustomerDropdown');
    const customerClearBtn = document.getElementById('addVehicleCustomerClearBtn');

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
        if (!e.target.closest('#addVehicleCustomerInput') && !e.target.closest('#addVehicleCustomerDropdown')) {
            if (customerDropdown) customerDropdown.style.display = 'none';
        }
    });

    const addVehicleModalEl = document.getElementById('addVehicleRecordModal');
    if (addVehicleModalEl) {
        addVehicleModalEl.addEventListener('show.bs.modal', function() {
            selectCustomer('', '', '');
        });
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>
