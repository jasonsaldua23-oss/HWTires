<?php
/**
 * Vehicles List / Records
 */

require_once '../../includes/config.php';
require_once '../../includes/record-filters.php';
require_once '../../includes/customer-vehicle-records.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$page_title = 'Vehicles';
$user = app_get_session_user();

if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    set_flash_message('Admin is view-only for vehicle records.', 'warning');
    redirect($_SERVER['HTTP_REFERER']);
}

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
$branch_filter = $_GET['branch'] ?? '';
$branch_filter = $branch_filter !== '' ? intval($branch_filter) : '';
if ($branch_filter !== '' && $branch_filter <= 0) {
    $branch_filter = '';
}
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

$branches = $pdo->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name ASC")->fetchAll();
$branch_names_by_id = [];
foreach ($branches as $branch) {
    $branch_names_by_id[(int) $branch['id']] = vehicle_records_branch_label($branch['name'] ?? '');
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
          AND jo_branch.status NOT IN ('archived', 'cancelled')
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
foreach ($service_operation_summary_by_vehicle as $summary_vehicle_id => $summary) {
    $service_operation_status_by_vehicle[(int) $summary_vehicle_id] = $summary['status'] ?? 'no-service';
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
$job_modal_id_by_vehicle = [];
$job_ids_for_modals = [];

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

    foreach ($vehicle_ids as $vehicle_id) {
        $job_id = (int) ($service_operation_summary_by_vehicle[$vehicle_id]['job_id'] ?? 0);
        if ($job_id <= 0) {
            continue;
        }

        $job_modal_id_by_vehicle[$vehicle_id] = 'vehicleJobDetailsModal' . $job_id;
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
if ($record_filter !== 'active') {
    $pagination_params .= '&records=' . urlencode((string) $record_filter);
}
$pagination_params .= record_date_filter_query_string($date_filter);
?>

<?php require_once '../../includes/header.php'; ?>
<?php require_once '../../includes/sidebar.php'; ?>

<div class="vehicle-records-page">
    <section class="vehicle-records-hero">
        <h1>Vehicle Records</h1>
        <p>Browse all registered vehicles</p>
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
                <option value="">All Branches</option>
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
            'branch' => $branch_filter,
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
                $is_archived_record = strtolower((string) ($vehicle['status'] ?? 'active')) === 'inactive';
                $status_class = $is_archived_record ? 'archived' : cv_records_operation_status_class($operation_status);
                $status_label = $is_archived_record ? 'Archived' : cv_records_operation_status_label($operation_status);
                $status_modal_target = $job_modal_id_by_vehicle[$vehicle_id] ?? $modal_id;
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
                                <button type="button"
                                        class="customer-vehicle-status-pill customer-status-action status-<?php echo esc_attr($status_class); ?>"
                                        data-bs-toggle="modal"
                                        data-bs-target="#<?php echo esc_attr($status_modal_target); ?>">
                                    <?php echo esc_html($status_label); ?>
                                </button>
                            </strong>
                        </div>
                    </div>

                    <button type="button" class="vehicle-history-button" data-bs-toggle="modal" data-bs-target="#<?php echo esc_attr($modal_id); ?>">
                        <i class="fas fa-eye"></i>
                        <span>View Service History</span>
                    </button>
                </article>

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

    <?php foreach ($job_details_by_id as $job_id => $job_detail): ?>
        <?php
        $quotation_id = (int) ($job_detail['quotation_id'] ?? 0);
        cv_records_render_job_order_details_modal(
            $job_detail,
            $job_services_by_quotation[$quotation_id] ?? [],
            $job_progress_by_id[(int) $job_id] ?? [],
            'vehicleJobDetailsModal'
        );
        ?>
    <?php endforeach; ?>
</div>

<?php require_once '../../includes/footer.php'; ?>
