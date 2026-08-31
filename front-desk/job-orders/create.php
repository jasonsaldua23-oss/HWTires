<?php
/**
 * Front-desk job order creation and recent records
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/record-filters.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$user = app_get_session_user();
if (($user['role'] ?? '') !== 'front-desk') {
    set_flash_message('You do not have access to this page', 'danger');
    redirect('/hwtires/' . ($user['role'] ?? 'front-desk') . '/');
}

$page_title = 'Create Job Order';
$user_branch_id = (int) ($user['branch_id'] ?? 0);
$selected_quotation_id = (int) ($_GET['quotation_id'] ?? 0);

if (!function_exists('front_job_branch_label')) {
    function front_job_branch_label($branch_name) {
        return app_branch_label($branch_name, 'Branch');
    }
}

if (!function_exists('front_job_status_label')) {
    function front_job_status_label($status) {
        $status = (string) $status;
        if (in_array($status, ['waiting', 'pending'], true)) {
            return 'Waiting';
        }

        return ucwords(str_replace('-', ' ', $status));
    }
}

if (!function_exists('front_job_order_number')) {
    function front_job_order_number($job) {
        if (!empty($job['job_number'])) {
            return $job['job_number'];
        }

        return 'JO' . str_pad((string) ($job['id'] ?? 0), 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('front_job_vehicle_label')) {
    function front_job_vehicle_label($record, $include_plate = true) {
        $vehicle = trim(($record['vehicle_make'] ?? $record['make'] ?? '') . ' ' . ($record['vehicle_model'] ?? $record['model'] ?? ''));

        if ($vehicle === '') {
            $vehicle = 'Vehicle';
        }

        $plate = $record['plate_number'] ?? '';
        if ($include_plate && $plate !== '') {
            $vehicle .= ' (' . $plate . ')';
        }

        return $vehicle;
    }
}

if (!function_exists('front_job_date')) {
    function front_job_date($date) {
        return !empty($date) ? date('Y-m-d', strtotime($date)) : '-';
    }
}

if (!function_exists('front_job_money')) {
    function front_job_money($amount, $decimals = 0) {
        return '&#8369;' . number_format((float) $amount, $decimals);
    }
}

if (!function_exists('front_job_estimated_duration')) {
    function front_job_estimated_duration(array $service_names, array $duration_map) {
        $durations = [];

        foreach ($service_names as $service_name) {
            $key = strtolower(trim((string) $service_name));
            if ($key !== '' && !empty($duration_map[$key])) {
                $durations[] = trim((string) $duration_map[$key]);
            }
        }

        $durations = array_values(array_unique(array_filter($durations)));
        return implode('; ', $durations);
    }
}

if (!function_exists('front_job_order_filter_url')) {
    function front_job_order_filter_url($status, $search = '', $date_filter = null) {
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

$allowed_job_statuses = [
    'all' => 'All',
    'waiting' => 'Waiting',
    'in-progress' => 'In Progress',
    'completed' => 'Completed',
    'archived' => 'Archived',
];
$job_status_filter = $_GET['status'] ?? 'all';
if (!array_key_exists($job_status_filter, $allowed_job_statuses)) {
    $job_status_filter = 'all';
}

$job_search_filter = trim($_GET['search'] ?? '');
if (function_exists('mb_substr')) {
    $job_search_filter = mb_substr($job_search_filter, 0, 100);
} else {
    $job_search_filter = substr($job_search_filter, 0, 100);
}
$date_filter = record_date_filter_current();

$branch_stmt = $pdo->prepare("SELECT id, name FROM branches WHERE id = ?");
$branch_stmt->execute([$user_branch_id]);
$user_branch = $branch_stmt->fetch();
$user_branch_name = $user_branch['name'] ?? ('Branch ' . $user_branch_id);
$front_desk_label = trim((string) ($user['name'] ?? 'Front Desk')) . ' - Front Desk B' . $user_branch_id . ' (' . front_job_branch_label($user_branch_name) . ')';
$approved_quote_year = (int) date('Y');

$technician_stmt = $pdo->prepare("
    SELECT id, name
    FROM technicians
    WHERE branch_id = ?
    ORDER BY name ASC
");
$technician_stmt->execute([$user_branch_id]);
$branch_technicians = $technician_stmt->fetchAll();

$service_duration_map = [];
try {
    $duration_stmt = $pdo->query("
        SELECT name, estimated_duration
        FROM service_catalog
        WHERE status = 'active'
          AND estimated_duration IS NOT NULL
          AND estimated_duration <> ''
    ");
    foreach ($duration_stmt->fetchAll() as $service_duration) {
        $service_duration_map[strtolower(trim((string) $service_duration['name']))] = (string) $service_duration['estimated_duration'];
    }
} catch (Exception $duration_error) {
    error_log('Front job order service duration load error: ' . $duration_error->getMessage());
}

$approved_stmt = $pdo->prepare("
    SELECT q.*,
           c.name AS customer_name,
           c.phone_mobile AS customer_phone,
           c.contact AS customer_contact,
           v.make AS vehicle_make,
           v.model AS vehicle_model,
           v.year AS vehicle_year,
           v.plate_number,
           b.name AS branch_name
    FROM quotations q
    LEFT JOIN customers c ON c.id = q.customer_id
    LEFT JOIN vehicles v ON v.id = q.vehicle_id
    LEFT JOIN branches b ON b.id = q.branch_id
    WHERE q.status = 'approved'
      AND q.branch_id = ?
      AND YEAR(COALESCE(q.quotation_date, q.created_at)) = ?
    ORDER BY q.created_at DESC, q.id DESC
");
$approved_stmt->execute([$user_branch_id, $approved_quote_year]);
$approved_quotations = $approved_stmt->fetchAll();

$approved_ids = array_map(static function ($quote) {
    return (int) $quote['id'];
}, $approved_quotations);

if ($selected_quotation_id > 0 && !in_array($selected_quotation_id, $approved_ids, true)) {
    set_flash_message('Only approved service operations from your branch can be converted to job orders.', 'warning');
    $selected_quotation_id = 0;
}

$quotation_items_by_id = [];
if (!empty($approved_ids)) {
    $placeholders = implode(',', array_fill(0, count($approved_ids), '?'));
    $items_stmt = $pdo->prepare("
        SELECT quotation_id, item_name, item_type, quantity, unit_price
        FROM quotation_items
        WHERE quotation_id IN ($placeholders)
        ORDER BY quotation_id ASC, id ASC
    ");
    $items_stmt->execute($approved_ids);

    foreach ($items_stmt->fetchAll() as $item) {
        $quotation_items_by_id[(int) $item['quotation_id']][] = $item;
    }
}

$quotation_payload = [];
foreach ($approved_quotations as &$quote) {
    $quote_id = (int) $quote['id'];
    $items = $quotation_items_by_id[$quote_id] ?? [];
    $items_total = 0;
    $service_names = [];

    foreach ($items as $item) {
        if (($item['item_type'] ?? '') !== 'service') {
            $items_total += max(1, (int) ($item['quantity'] ?? 1)) * (float) ($item['unit_price'] ?? 0);
        }
        $service_names[] = (string) ($item['item_name'] ?? 'Service');
    }

    $calculated_total = (float) ($quote['labor_cost'] ?? 0) + $items_total;
    $quote['display_total'] = $calculated_total > 0 ? $calculated_total : (float) ($quote['total_amount'] ?? 0);
    $quote['service_names'] = $service_names;
    $quote['estimated_duration'] = front_job_estimated_duration($service_names, $service_duration_map);

    $quotation_payload[$quote_id] = [
        'id' => $quote_id,
        'customer_id' => (int) ($quote['customer_id'] ?? 0),
        'vehicle_id' => (int) ($quote['vehicle_id'] ?? 0),
        'branch_id' => (int) ($quote['branch_id'] ?? 0),
        'customer_name' => $quote['customer_name'] ?: 'Customer',
        'customer_phone' => $quote['customer_phone'] ?: ($quote['customer_contact'] ?? ''),
        'vehicle_name' => front_job_vehicle_label($quote, false),
        'plate_number' => $quote['plate_number'] ?: '-',
        'vehicle_year' => $quote['vehicle_year'] ?: '',
        'branch_name' => front_job_branch_label($quote['branch_name'] ?? ''),
        'quotation_number' => $quote['quotation_number'] ?? '',
        'display_total' => (float) ($quote['display_total'] ?? 0),
        'notes' => $quote['notes'] ?? '',
        'services' => $service_names,
        'estimated_duration' => $quote['estimated_duration'],
    ];
}
unset($quote);

$job_where = [
    'jo.branch_id = ?',
];
$job_params = [$user_branch_id];
$job_activity_expr = record_activity_datetime_expr('jo.job_date', 'jo.created_at', 'jo.updated_at');
$job_record_date_expr = record_business_datetime_expr('jo.job_date', 'jo.created_at');

if ($job_status_filter === 'archived') {
    $job_where[] = "jo.status = 'archived'";
} else {
    $job_where[] = "jo.status <> 'archived'";
    if ($job_status_filter === 'waiting') {
        $job_where[] = "jo.status IN ('waiting', 'pending')";
    } elseif ($job_status_filter !== 'all') {
        $job_where[] = 'jo.status = ?';
        $job_params[] = $job_status_filter;
    }
}

$job_date_params = [];
$job_date_condition = record_date_filter_condition($job_record_date_expr, $date_filter, $job_date_params);
if ($job_date_condition !== '') {
    $job_where[] = $job_date_condition;
    $job_params = array_merge($job_params, $job_date_params);
}

if ($job_search_filter !== '') {
    foreach (app_search_terms($job_search_filter) as $term) {
        $job_where[] = "(
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
            OR u.name LIKE ?
            OR q.inspection_complaint LIKE ?
            OR q.inspection_findings LIKE ?
            OR q.inspection_recommendations LIKE ?
            OR EXISTS (
                SELECT 1
                FROM quotation_items qi
                WHERE qi.quotation_id = jo.quotation_id
                  AND qi.item_name LIKE ?
            )
        )";
        $job_params = array_merge($job_params, array_fill(0, 15, '%' . $term . '%'));
    }
}

$job_where_sql = implode(' AND ', $job_where);

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
           q.inspection_complaint,
           q.inspection_findings,
           q.inspection_recommendations,
           q.inspection_mileage
    FROM job_orders jo
    LEFT JOIN quotations q ON q.id = jo.quotation_id
    LEFT JOIN customers c ON c.id = jo.customer_id
    LEFT JOIN vehicles v ON v.id = jo.vehicle_id
    LEFT JOIN branches b ON b.id = jo.branch_id
    LEFT JOIN users u ON u.id = jo.created_by
    WHERE $job_where_sql
    ORDER BY $job_record_date_expr DESC, jo.id DESC
    LIMIT 20
");
$job_stmt->execute($job_params);
$job_orders = $job_stmt->fetchAll();

$job_quotation_ids = [];
foreach ($job_orders as $job) {
    if (!empty($job['quotation_id'])) {
        $job_quotation_ids[] = (int) $job['quotation_id'];
    }
}
$job_quotation_ids = array_values(array_unique($job_quotation_ids));

$services_by_quotation = [];
if (!empty($job_quotation_ids)) {
    $placeholders = implode(',', array_fill(0, count($job_quotation_ids), '?'));
    $job_items_stmt = $pdo->prepare("
        SELECT quotation_id, item_name
        FROM quotation_items
        WHERE quotation_id IN ($placeholders)
        ORDER BY quotation_id ASC, id ASC
    ");
    $job_items_stmt->execute($job_quotation_ids);

    foreach ($job_items_stmt->fetchAll() as $item) {
        $services_by_quotation[(int) $item['quotation_id']][] = $item['item_name'];
    }
}
?>

<?php require_once __DIR__ . '/../../includes/header.php'; ?>
<?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

<main class="job-orders-page front-job-create-page">
    <form id="frontJobOrderForm" method="POST" action="/hwtires/api/job-orders-api.php">
        <input type="hidden" name="csrf_token" value="<?php echo esc_attr(generate_csrf_token()); ?>">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="redirect" value="/hwtires/front-desk/job-orders/">
        <input type="hidden" name="customer_id" id="job_customer_id">
        <input type="hidden" name="vehicle_id" id="job_vehicle_id">
        <input type="hidden" name="branch_id" id="job_branch_id" value="<?php echo (int) $user_branch_id; ?>">

        <header class="job-create-hero">
            <div>
                <h1>Create Job Order</h1>
                <p>Manage service job orders</p>
            </div>
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

        <section class="job-create-card job-create-launch-card">
            <div>
                <span class="job-create-eyebrow">Create Job Order</span>
                <h2>Create from approved service operation</h2>
                <p>Customer, vehicle, and service details will be filled after selecting a service operation.</p>
            </div>
            <button type="button" class="job-create-open-btn" data-bs-toggle="modal" data-bs-target="#jobCreateSelectorModal">
                <i class="fas fa-plus"></i>
                <span>Select Service Operation</span>
            </button>
        </section>

        <div class="modal fade job-create-selector-modal" id="jobCreateSelectorModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h2>Select Service Operation</h2>
                            <p>Choose an approved service operation to create a job order.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <label for="quotation_id">Approved service operation <span>*</span></label>
                        <label class="job-quote-search" for="jobQuotationSearch">
                            <span>Search approved service operation</span>
                            <input type="search" id="jobQuotationSearch" placeholder="Type customer, plate, or service operation number..." autocomplete="off">
                            <div class="job-quote-results" id="jobQuotationResults" hidden></div>
                        </label>
                        <select id="quotation_id" name="quotation_id" class="job-quote-select" required>
                            <option value="">-- Select a service operation --</option>
                            <?php foreach ($approved_quotations as $quote): ?>
                                <?php
                                $plate = $quote['plate_number'] ?: 'No plate';
                                $selected = (int) $quote['id'] === $selected_quotation_id ? 'selected' : '';
                                $search_text = implode(' ', [
                                    $quote['quotation_number'] ?? '',
                                    $quote['customer_name'] ?? '',
                                    $plate,
                                    $quote['vehicle_make'] ?? '',
                                    $quote['vehicle_model'] ?? '',
                                    number_format((float) ($quote['display_total'] ?? 0), 0, '.', ''),
                                    number_format((float) ($quote['display_total'] ?? 0), 2, '.', ''),
                                    implode(' ', $quote['service_names'] ?? []),
                                ]);
                                ?>
                                <option value="<?php echo (int) $quote['id']; ?>" data-search="<?php echo esc_attr($search_text); ?>" <?php echo $selected; ?>>
                                    <?php echo esc_html(($quote['quotation_number'] ?? 'Service Operation') . ' - ' . ($quote['customer_name'] ?? 'Customer') . ' (' . $plate . ') - '); ?><?php echo front_job_money($quote['display_total']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="job-quote-year-note">Showing approved service operations from <?php echo (int) $approved_quote_year; ?> only.</p>
                        <p class="job-quote-no-results" id="jobQuotationNoResults" hidden>No matching approved service operation found.</p>

                        <div class="job-quote-helper" id="quoteEmptyNotice">
                            <strong><i class="fas fa-info-circle"></i> Please select a service operation to proceed</strong>
                            <p>
                                <?php if (empty($approved_quotations)): ?>
                                    No approved service operations are currently available for <?php echo esc_html(front_job_branch_label($user_branch_name)); ?>.
                                <?php else: ?>
                                    Job orders are created based on approved service operations. Customer, vehicle, and service details will be auto-populated.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="job-modal-close-btn" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="job-create-selector-done" id="jobCreateSelectorDone" data-bs-dismiss="modal" disabled>Use Selected Service Operation</button>
                    </div>
                </div>
            </div>
        </div>

        <section class="job-create-card job-selected-block" data-selected-quote hidden style="display: none;">
            <h2>Customer &amp; Vehicle Details</h2>
            <div class="job-info-band">
                <div>
                    <strong>Customer Info</strong>
                    <span id="quoteCustomerName">-</span>
                    <span id="quoteCustomerPhone">-</span>
                </div>
                <div>
                    <strong>Vehicle Info</strong>
                    <span id="quoteVehicleName">-</span>
                    <span id="quoteVehicleMeta">-</span>
                </div>
            </div>
        </section>

        <section class="job-create-card job-selected-block" data-selected-quote hidden style="display: none;">
            <h2>Service Requested</h2>
            <div class="job-service-band">
                <strong>Services from Service Operation:</strong>
                <div class="job-service-chip-list" id="quoteServiceList"></div>
            </div>
        </section>

        <section class="job-create-card job-selected-block" data-selected-quote hidden style="display: none;">
            <h2>Job Order Details</h2>
            <div class="job-form-grid">
                <label>
                    <span>Job Order Status</span>
                    <select name="status">
                        <option value="waiting" selected>Waiting</option>
                        <option value="in-progress">In Progress</option>
                    </select>
                </label>
                <label>
                    <span>Job Date</span>
                    <input type="date" name="job_date" id="jobDate" value="<?php echo date('Y-m-d'); ?>" required>
                </label>
                <label>
                    <span>Front Desk</span>
                    <input type="text" value="<?php echo esc_attr($front_desk_label); ?>" readonly>
                </label>
                <div class="job-form-field job-technician-field">
                    <span>Assigned Technician(s)</span>
                    <?php if (empty($branch_technicians)): ?>
                        <div class="job-technician-options is-empty">
                            <p>No technicians configured for this branch.</p>
                        </div>
                    <?php else: ?>
                        <div class="job-technician-picker">
                            <div class="job-technician-selected" id="jobTechnicianSelected"></div>
                            <label class="job-technician-search" for="jobTechnicianSearch">
                                <input type="search" id="jobTechnicianSearch" placeholder="Search technician name..." autocomplete="off">
                                <div class="job-technician-results" id="jobTechnicianResults" hidden></div>
                            </label>
                            <select id="jobTechnicianDropdown" class="job-technician-select">
                                <option value="">-- Add technician --</option>
                                <?php foreach ($branch_technicians as $technician): ?>
                                    <option value="<?php echo esc_attr($technician['name']); ?>">
                                        <?php echo esc_html($technician['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <small class="job-technician-help">
                        <?php echo empty($branch_technicians) ? 'Ask the admin to add technicians in Branch Management.' : 'Choose one or more technicians assigned to this branch.'; ?>
                    </small>
                </div>
                <label>
                    <span>Duration Type</span>
                    <select name="duration_type" id="jobDurationType">
                        <option value="time" selected>Time / Same Day</option>
                        <option value="day">Day(s) / Multi-day</option>
                    </select>
                </label>
                <label>
                    <span>Expected Completion Date</span>
                    <input type="date" name="scheduled_end_date" id="jobEndDate" value="<?php echo date('Y-m-d'); ?>">
                </label>
                <label>
                    <span>Start Time</span>
                    <input type="time" name="scheduled_start_time" id="jobStartTime">
                </label>
                <label>
                    <span>End Time</span>
                    <input type="time" name="scheduled_end_time" id="jobEndTime">
                </label>
                <label class="job-duration-field">
                    <span>Estimated Job Duration</span>
                    <input type="text" name="estimated_duration" id="jobEstimatedDuration" placeholder="e.g., 2 hours or 2 days">
                </label>
            </div>
            <label class="job-notes-field">
                <span>Notes</span>
                <textarea name="notes" id="jobNotes" rows="5" placeholder="Enter any special instructions or notes..."></textarea>
            </label>
            <div class="job-form-actions">
                <button type="submit" class="job-save-button" id="jobSaveButton">
                    <i class="far fa-save"></i>
                    <span>Save Job Order</span>
                </button>
            </div>
        </section>
    </form>

    <section class="job-orders-panel" id="job-order-records">
        <div class="job-orders-panel-header">
            <h2><?php echo esc_html(record_date_filter_heading('Job Orders', $date_filter)); ?></h2>
            <div class="job-orders-panel-controls">
                <form method="GET" action="./#job-order-records" class="records-select-filter job-status-select-filter">
                    <?php if ($job_search_filter !== ''): ?>
                        <input type="hidden" name="search" value="<?php echo esc_attr($job_search_filter); ?>">
                    <?php endif; ?>
                    <?php record_date_filter_hidden_inputs(record_date_filter_query_params($date_filter)); ?>
                    <select name="status" onchange="this.form.submit()" aria-label="Filter job orders by status">
                    <?php foreach ($allowed_job_statuses as $status => $label): ?>
                        <option value="<?php echo esc_attr($status); ?>" <?php echo $job_status_filter === $status ? 'selected' : ''; ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                    </select>
                    <button type="submit">Apply</button>
                </form>
                <?php
                record_date_filter_controls($date_filter, [
                    'status' => $job_status_filter !== 'all' ? $job_status_filter : '',
                    'search' => $job_search_filter,
                ], 'job-order-records');
                ?>
                <form method="GET" action="./#job-order-records" class="records-search-form">
                    <?php if ($job_status_filter !== 'all'): ?>
                        <input type="hidden" name="status" value="<?php echo esc_attr($job_status_filter); ?>">
                    <?php endif; ?>
                    <?php record_date_filter_hidden_inputs(record_date_filter_query_params($date_filter)); ?>
                    <label class="records-search-field">
                        <i class="fas fa-search"></i>
                        <input type="search"
                               name="search"
                               value="<?php echo esc_attr($job_search_filter); ?>"
                               placeholder="Search job order, customer, plate...">
                    </label>
                    <button type="submit" class="records-search-btn">Search</button>
                    <?php if ($job_search_filter !== ''): ?>
                        <a class="records-search-clear"
                           href="<?php echo esc_attr(front_job_order_filter_url($job_status_filter, '', $date_filter)); ?>#job-order-records">
                            Clear
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <?php if (empty($job_orders)): ?>
            <div class="job-orders-empty">No job orders found.</div>
        <?php else: ?>
            <div class="job-order-list job-order-records-table" role="table" aria-label="Job order records">
                <div class="job-order-records-head" role="row">
                    <span>Customer / Vehicle</span>
                    <span>Job #</span>
                    <span>Date / Branch</span>
                    <span>Technician</span>
                    <span>Services</span>
                    <span>Status</span>
                    <span>Action</span>
                </div>
                <?php foreach ($job_orders as $job): ?>
                    <?php
                    $job_number = front_job_order_number($job);
                    $status = $job['status'] ?? 'waiting';
                    $services = $services_by_quotation[(int) ($job['quotation_id'] ?? 0)] ?? [];
                    $service_summary = !empty($services) ? implode(', ', $services) : 'No services listed';
                    $visible_services = array_slice($services, 0, 2);
                    $hidden_service_count = max(0, count($services) - count($visible_services));
                    $note = app_format_record_notes($job['notes'] ?? '');
                    $technician = trim($job['assigned_technician_name'] ?? '') ?: 'Unassigned';
                    $display_date = front_job_date($job['job_date'] ?? $job['created_at'] ?? '');
                    $phone = $job['customer_phone'] ?: ($job['customer_contact'] ?? '');
                    ?>
                    <article class="job-order-card job-order-row" role="row">
                        <div class="job-order-cell job-order-primary" role="cell">
                            <strong><?php echo esc_html($job['customer_name'] ?? 'Customer'); ?></strong>
                            <span><?php echo esc_html(front_job_vehicle_label($job)); ?></span>
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
                            <span><?php echo esc_html(front_job_branch_label($job['branch_name'] ?? '')); ?></span>
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
                        <div class="job-order-cell" role="cell">
                            <span class="job-status-pill status-<?php echo esc_attr($status); ?>">
                                <?php echo esc_html(front_job_status_label($status)); ?>
                            </span>
                        </div>
                        <div class="job-order-cell job-order-action" role="cell" style="display: flex; gap: 6px; align-items: center; justify-content: flex-end;">
                            <button type="button" class="job-action-icon-btn" data-bs-toggle="modal" data-bs-target="#frontJobDetailsModal<?php echo (int) $job['id']; ?>" title="View Job Details" aria-label="View Job Details">
                                <i class="fas fa-eye"></i>
                            </button>
                            <?php if ($status === 'archived'): ?>
                                <form method="POST" action="/hwtires/api/job-orders-api.php" style="display:inline; margin: 0;" onsubmit="return confirm('Restore / Unarchive this job order?');">
                                    <input type="hidden" name="action" value="unarchive">
                                    <input type="hidden" name="id" value="<?php echo (int) $job['id']; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo esc_attr(get_csrf_token()); ?>">
                                    <input type="hidden" name="redirect" value="<?php echo esc_attr($_SERVER['REQUEST_URI'] ?? './'); ?>">
                                    <button type="submit" class="job-action-icon-btn is-restore" title="Restore Job Order" aria-label="Restore Job Order">
                                        <i class="fas fa-rotate-left"></i>
                                    </button>
                                </form>
                            <?php elseif (in_array($status, ['completed', 'waiting', 'cancelled', 'rejected'], true)): ?>
                                <form method="POST" action="/hwtires/api/job-orders-api.php" style="display:inline; margin: 0;" onsubmit="return confirm('Archive this job order?');">
                                    <input type="hidden" name="action" value="archive">
                                    <input type="hidden" name="id" value="<?php echo (int) $job['id']; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo esc_attr(get_csrf_token()); ?>">
                                    <input type="hidden" name="redirect" value="<?php echo esc_attr($_SERVER['REQUEST_URI'] ?? './'); ?>">
                                    <button type="submit" class="job-action-icon-btn is-archive" title="Archive Job Order" aria-label="Archive Job Order">
                                        <i class="fas fa-box-archive"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>

                    <div class="modal fade job-details-modal" id="frontJobDetailsModal<?php echo (int) $job['id']; ?>" tabindex="-1" aria-hidden="true">
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
                                            <strong><?php echo esc_html(front_job_vehicle_label($job, false)); ?></strong>
                                            <p><?php echo esc_html($job['plate_number'] ?: '-'); ?></p>
                                        </div>
                                    </section>

                                    <section class="job-detail-grid">
                                        <div>
                                            <span>Branch</span>
                                            <strong><?php echo esc_html(front_job_branch_label($job['branch_name'] ?? '')); ?></strong>
                                        </div>
                                        <div>
                                            <span>Date Created</span>
                                            <strong><?php echo esc_html($display_date); ?></strong>
                                        </div>
                                        <div>
                                            <span>Status</span>
                                            <strong>
                                                <span class="job-status-pill status-<?php echo esc_attr($status); ?>">
                                                    <?php echo esc_html(front_job_status_label($status)); ?>
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
                                        <?php if (!empty($job['scheduled_end_date'])): ?>
                                            <div>
                                                <span>Expected Completion</span>
                                                <strong><?php echo esc_html(date('Y-m-d', strtotime($job['scheduled_end_date']))); ?></strong>
                                            </div>
                                        <?php endif; ?>
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
                                                <input type="hidden" name="id" value="<?php echo (int) $job['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo esc_attr(get_csrf_token()); ?>">
                                                <input type="hidden" name="redirect" value="<?php echo esc_attr($_SERVER['REQUEST_URI'] ?? './'); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success">
                                                    <i class="fas fa-rotate-left"></i> Restore / Unarchive
                                                </button>
                                            </form>
                                        <?php elseif (in_array($status, ['completed', 'waiting', 'cancelled', 'rejected'], true)): ?>
                                            <form method="POST" action="/hwtires/api/job-orders-api.php" style="display:inline;" onsubmit="return confirm('Archive this job order?');">
                                                <input type="hidden" name="action" value="archive">
                                                <input type="hidden" name="id" value="<?php echo (int) $job['id']; ?>">
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
        <?php endif; ?>
    </section>
</main>

<script>
const frontJobQuotations = <?php echo json_encode($quotation_payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const frontJobTechnicians = <?php echo json_encode(array_values(array_map(static fn($technician) => (string) ($technician['name'] ?? ''), $branch_technicians)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>.filter(Boolean);
const quoteSelect = document.getElementById('quotation_id');
const quoteSearch = document.getElementById('jobQuotationSearch');
const quoteResults = document.getElementById('jobQuotationResults');
const quoteNoResults = document.getElementById('jobQuotationNoResults');
const quoteBlocks = document.querySelectorAll('[data-selected-quote]');
const quoteEmptyNotice = document.getElementById('quoteEmptyNotice');
const quoteSelectorDone = document.getElementById('jobCreateSelectorDone');
const jobSaveButton = document.getElementById('jobSaveButton');
const frontJobOrderForm = document.getElementById('frontJobOrderForm');
const durationInput = document.getElementById('jobEstimatedDuration');
const durationType = document.getElementById('jobDurationType');
const jobDateInput = document.getElementById('jobDate');
const jobEndDateInput = document.getElementById('jobEndDate');
const jobStartTimeInput = document.getElementById('jobStartTime');
const jobEndTimeInput = document.getElementById('jobEndTime');
const technicianSearchInput = document.getElementById('jobTechnicianSearch');
const technicianResults = document.getElementById('jobTechnicianResults');
const technicianSelect = document.getElementById('jobTechnicianDropdown');
const technicianSelected = document.getElementById('jobTechnicianSelected');
const selectedTechnicians = new Set();

function jobMoney(amount) {
    return '\u20b1' + Number(amount || 0).toLocaleString('en-PH', { maximumFractionDigits: 0 });
}

function setText(id, value) {
    const element = document.getElementById(id);
    if (element) {
        element.textContent = value || '-';
    }
}

function normalizeJobSearchText(value) {
    return String(value || '')
        .toLowerCase()
        .replace(/[\u20b1,]/g, ' ')
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();
}

function normalizeJobSearchTerms(value) {
    const normalized = normalizeJobSearchText(value);
    return normalized === '' ? [] : normalized.split(/\s+/).filter(Boolean);
}

function optionMatchesJobTerms(option, terms) {
    if (!option || !option.value) return false;
    const text = normalizeJobSearchText(`${option.dataset.search || ''} ${option.textContent || ''}`);
    return terms.every(term => text.includes(term));
}

function closeJobQuoteResults() {
    if (!quoteResults) return;
    quoteResults.hidden = true;
    quoteResults.innerHTML = '';
}

function closeTechnicianResults() {
    if (!technicianResults) return;
    technicianResults.hidden = true;
    technicianResults.innerHTML = '';
}

function availableTechnicianMatches(terms) {
    return frontJobTechnicians.filter((name) => {
        if (selectedTechnicians.has(name)) return false;
        const searchable = name.toLowerCase();
        return terms.length === 0 || terms.every(term => searchable.includes(term));
    });
}

function renderSelectedTechnicians() {
    if (!technicianSelected) return;

    technicianSelected.innerHTML = '';
    if (selectedTechnicians.size === 0) {
        const empty = document.createElement('span');
        empty.className = 'job-technician-empty';
        empty.textContent = 'No technician selected';
        technicianSelected.appendChild(empty);
        return;
    }

    selectedTechnicians.forEach((name) => {
        const chip = document.createElement('span');
        chip.className = 'job-technician-chip';

        const label = document.createElement('strong');
        label.textContent = name;

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.setAttribute('aria-label', `Remove ${name}`);
        remove.innerHTML = '&times;';
        remove.addEventListener('click', () => {
            selectedTechnicians.delete(name);
            renderSelectedTechnicians();
            renderTechnicianResults(normalizeJobSearchTerms(technicianSearchInput ? technicianSearchInput.value : ''));
        });

        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'assigned_technician_name[]';
        hidden.value = name;

        chip.appendChild(label);
        chip.appendChild(remove);
        chip.appendChild(hidden);
        technicianSelected.appendChild(chip);
    });
}

function addTechnician(name) {
    const technicianName = String(name || '').trim();
    if (technicianName === '' || selectedTechnicians.has(technicianName)) return;

    selectedTechnicians.add(technicianName);
    if (technicianSearchInput) {
        technicianSearchInput.value = '';
    }
    if (technicianSelect) {
        technicianSelect.value = '';
    }
    renderSelectedTechnicians();
    closeTechnicianResults();
}

function renderTechnicianResults(terms) {
    if (!technicianResults) return;

    technicianResults.innerHTML = '';
    if (terms.length === 0) {
        closeTechnicianResults();
        return;
    }

    const matches = availableTechnicianMatches(terms).slice(0, 8);
    if (matches.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'job-technician-result-empty';
        empty.textContent = 'No matching technician';
        technicianResults.appendChild(empty);
        technicianResults.hidden = false;
        return;
    }

    matches.forEach((name) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'job-technician-result';
        button.textContent = name;
        button.addEventListener('mousedown', (event) => event.preventDefault());
        button.addEventListener('click', () => addTechnician(name));
        technicianResults.appendChild(button);
    });

    technicianResults.hidden = false;
}

function quoteOptionLabel(option) {
    const quote = frontJobQuotations[option.value];
    if (!quote) return option.textContent.trim();

    return [quote.quotation_number || 'Service Operation', quote.customer_name || 'Customer'].filter(Boolean).join(' - ');
}

function quoteOptionDetail(option) {
    const quote = frontJobQuotations[option.value];
    if (!quote) return option.textContent.trim();

    const vehicle = [quote.plate_number, quote.vehicle_name].filter(Boolean).join(' | ');
    const services = Array.isArray(quote.services) ? quote.services.slice(0, 3).join(', ') : '';
    const parts = [vehicle, services, jobMoney(quote.display_total)].filter(Boolean);
    return parts.join(' | ');
}

function selectJobQuoteById(quoteId) {
    if (!quoteSelect) return;

    quoteSelect.value = String(quoteId);
    syncJobQuoteSearchFromSelection();
    renderSelectedQuotation();
    filterJobQuotationOptions();
    closeJobQuoteResults();
}

function syncJobQuoteSearchFromSelection() {
    if (!quoteSearch || !quoteSelect) return;

    const option = quoteSelect.options[quoteSelect.selectedIndex];
    quoteSearch.value = quoteSelect.value && option ? option.textContent.trim() : '';
    closeJobQuoteResults();
}

function renderJobQuoteResults(terms, matches) {
    if (!quoteResults) return;

    quoteResults.innerHTML = '';
    if (terms.length === 0) {
        closeJobQuoteResults();
        return;
    }

    const visibleMatches = matches.slice(0, 8);
    if (visibleMatches.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'job-quote-result-empty';
        empty.textContent = 'No matching approved service operation';
        quoteResults.appendChild(empty);
        quoteResults.hidden = false;
        return;
    }

    visibleMatches.forEach((option) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'job-quote-result';
        button.dataset.quoteId = option.value;

        const copy = document.createElement('span');
        const title = document.createElement('strong');
        const detail = document.createElement('small');
        const badge = document.createElement('em');

        title.textContent = quoteOptionLabel(option);
        detail.textContent = quoteOptionDetail(option);
        badge.textContent = 'Service Operation';

        copy.appendChild(title);
        copy.appendChild(detail);
        button.appendChild(copy);
        button.appendChild(badge);
        button.addEventListener('mousedown', (event) => event.preventDefault());
        button.addEventListener('click', () => selectJobQuoteById(option.value));
        quoteResults.appendChild(button);
    });

    quoteResults.hidden = false;
}

function jobDurationUnitToMinutes(unit) {
    const value = String(unit || '').toLowerCase();
    if (value.startsWith('day')) return 1440;
    if (value.startsWith('hour') || value === 'hr' || value === 'hrs') return 60;
    return 1;
}

function rangesOverlap(start, end, ranges) {
    return ranges.some(range => start < range.end && end > range.start);
}

function parseJobDurationText(text) {
    const normalized = String(text || '').toLowerCase().replace(/[\u2013\u2014]/g, '-');
    const ranges = [];
    let totalMinutes = 0;
    let hasDay = false;
    let found = false;

    function addDuration(amount, unit) {
        const unitMinutes = jobDurationUnitToMinutes(unit);
        const minutes = Number(amount || 0) * unitMinutes;
        if (!Number.isFinite(minutes) || minutes <= 0) return;

        totalMinutes += minutes;
        hasDay = hasDay || unitMinutes >= 1440;
        found = true;
    }

    const crossUnitRange = /(\d+(?:\.\d+)?)\s*(minutes?|mins?|hours?|hrs?|days?)\s*(?:-|to)\s*(\d+(?:\.\d+)?)\s*(minutes?|mins?|hours?|hrs?|days?)/gi;
    let match;
    while ((match = crossUnitRange.exec(normalized)) !== null) {
        const firstMinutes = Number(match[1]) * jobDurationUnitToMinutes(match[2]);
        const secondMinutes = Number(match[3]) * jobDurationUnitToMinutes(match[4]);
        addDuration(Math.max(firstMinutes, secondMinutes), 'minutes');
        hasDay = hasDay || jobDurationUnitToMinutes(match[2]) >= 1440 || jobDurationUnitToMinutes(match[4]) >= 1440;
        ranges.push({ start: match.index, end: match.index + match[0].length });
    }

    const sameUnitRange = /(\d+(?:\.\d+)?)\s*(?:-|to)\s*(\d+(?:\.\d+)?)\s*(minutes?|mins?|hours?|hrs?|days?)/gi;
    while ((match = sameUnitRange.exec(normalized)) !== null) {
        const start = match.index;
        const end = match.index + match[0].length;
        if (rangesOverlap(start, end, ranges)) continue;

        addDuration(Math.max(Number(match[1]), Number(match[2])), match[3]);
        ranges.push({ start, end });
    }

    const singleDuration = /(\d+(?:\.\d+)?)\s*(minutes?|mins?|hours?|hrs?|days?)/gi;
    while ((match = singleDuration.exec(normalized)) !== null) {
        const start = match.index;
        const end = match.index + match[0].length;
        if (rangesOverlap(start, end, ranges)) continue;

        addDuration(Number(match[1]), match[2]);
    }

    return {
        found,
        hasDay,
        minutes: Math.round(totalMinutes),
    };
}

function parseJobDateValue(value) {
    const parts = String(value || '').split('-').map(Number);
    if (parts.length !== 3 || parts.some(part => !Number.isFinite(part))) return null;
    return new Date(parts[0], parts[1] - 1, parts[2]);
}

function formatJobDateValue(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function formatJobTimeValue(date) {
    return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
}

function combineJobDateTime(dateValue, timeValue) {
    const date = parseJobDateValue(dateValue);
    const timeParts = String(timeValue || '').split(':').map(Number);
    if (!date || timeParts.length < 2 || timeParts.some(part => !Number.isFinite(part))) return null;
    date.setHours(timeParts[0], timeParts[1], 0, 0);
    return date;
}

function getTodayJobDateValue() {
    return formatJobDateValue(new Date());
}

function defaultJobStartTimeValue() {
    if (!jobDateInput || !jobDateInput.value) return '';

    if (jobDateInput.value !== getTodayJobDateValue()) {
        return '08:00';
    }

    const now = new Date();
    let totalMinutes = (now.getHours() * 60) + Math.ceil(now.getMinutes() / 15) * 15;

    if (totalMinutes >= 1440) {
        totalMinutes = 1425;
    }

    return `${String(Math.floor(totalMinutes / 60)).padStart(2, '0')}:${String(totalMinutes % 60).padStart(2, '0')}`;
}

function updateJobDurationFieldState() {
    if (!durationType || !durationInput || !jobEndDateInput) return;

    const isMultiDay = durationType.value === 'day';
    durationInput.placeholder = isMultiDay ? 'e.g., 1 day, 2 days' : 'e.g., 30 minutes, 2 hours';
    jobEndDateInput.required = isMultiDay;
}

function applyEstimatedDurationSchedule() {
    if (!durationInput || !jobDateInput || !jobEndDateInput || !jobDateInput.value) return;

    const parsed = parseJobDurationText(durationInput.value);
    const jobDate = parseJobDateValue(jobDateInput.value);
    if (!jobDate) return;

    if (!parsed.found || parsed.minutes <= 0) {
        if (!jobEndDateInput.value || (durationType && durationType.value === 'time')) {
            jobEndDateInput.value = jobDateInput.value;
        }
        return;
    }

    if (durationType && durationType.value === 'day') {
        const endDate = parseJobDateValue(jobDateInput.value);
        const daysToAdd = Math.max(1, Math.ceil(parsed.minutes / 1440));
        endDate.setDate(endDate.getDate() + daysToAdd);
        jobEndDateInput.value = formatJobDateValue(endDate);
        if (jobStartTimeInput) {
            jobStartTimeInput.value = '';
        }
        if (jobEndTimeInput) {
            jobEndTimeInput.value = '';
        }
        return;
    }

    if (jobStartTimeInput && !jobStartTimeInput.value) {
        jobStartTimeInput.value = defaultJobStartTimeValue();
    }

    if (jobStartTimeInput && jobStartTimeInput.value) {
        const startDateTime = combineJobDateTime(jobDateInput.value, jobStartTimeInput.value);
        if (startDateTime) {
            const endDateTime = new Date(startDateTime.getTime() + (parsed.minutes * 60000));
            jobEndDateInput.value = formatJobDateValue(endDateTime);
            if (jobEndTimeInput) {
                jobEndTimeInput.value = formatJobTimeValue(endDateTime);
            }
            return;
        }
    }

    jobEndDateInput.value = jobDateInput.value;
}

function syncJobDurationControls() {
    updateJobDurationFieldState();
    applyEstimatedDurationSchedule();
}

function syncJobDurationTypeFromText() {
    if (!durationType || !durationInput) return;

    const parsed = parseJobDurationText(durationInput.value);
    durationType.value = (parsed.hasDay || parsed.minutes >= 1440) ? 'day' : 'time';
    syncJobDurationControls();
}

function renderSelectedQuotation() {
    const quote = frontJobQuotations[quoteSelect.value];
    const hasQuote = Boolean(quote);

    quoteBlocks.forEach(block => {
        block.hidden = !hasQuote;
        block.style.display = hasQuote ? '' : 'none';
    });
    quoteEmptyNotice.hidden = hasQuote;
    if (quoteSelectorDone) {
        quoteSelectorDone.disabled = !hasQuote;
    }
    jobSaveButton.disabled = !hasQuote;

    if (!hasQuote) {
        document.getElementById('job_customer_id').value = '';
        document.getElementById('job_vehicle_id').value = '';
        document.getElementById('job_branch_id').value = '<?php echo (int) $user_branch_id; ?>';
        document.getElementById('jobNotes').value = '';
        if (durationInput) {
            durationInput.value = '';
        }
        syncJobDurationControls();
        return;
    }

    document.getElementById('job_customer_id').value = quote.customer_id || '';
    document.getElementById('job_vehicle_id').value = quote.vehicle_id || '';
    document.getElementById('job_branch_id').value = quote.branch_id || '<?php echo (int) $user_branch_id; ?>';
    document.getElementById('jobNotes').value = quote.notes || '';
    if (durationInput) {
        durationInput.value = quote.estimated_duration || '';
        syncJobDurationTypeFromText();
    }

    setText('quoteCustomerName', quote.customer_name);
    setText('quoteCustomerPhone', quote.customer_phone);
    setText('quoteVehicleName', quote.vehicle_name);
    setText('quoteVehicleMeta', [quote.plate_number, quote.vehicle_year].filter(Boolean).join(' - '));

    const services = document.getElementById('quoteServiceList');
    services.innerHTML = '';

    if (!quote.services || quote.services.length === 0) {
        const empty = document.createElement('span');
        empty.textContent = 'No services listed';
        services.appendChild(empty);
        return;
    }

    quote.services.forEach(service => {
        const chip = document.createElement('span');
        chip.textContent = service;
        services.appendChild(chip);
    });
}

function filterJobQuotationOptions() {
    if (!quoteSearch || !quoteSelect) return;

    const terms = normalizeJobSearchTerms(quoteSearch.value);
    let visibleCount = 0;
    const matches = [];

    Array.from(quoteSelect.options).forEach((option) => {
        if (!option.value) {
            option.hidden = false;
            option.disabled = false;
            return;
        }

        const visible = terms.length === 0 || optionMatchesJobTerms(option, terms);
        option.hidden = !visible;
        option.disabled = !visible;
        if (visible) {
            visibleCount++;
            matches.push(option);
        }
    });

    if (quoteSelect.selectedOptions[0] && quoteSelect.selectedOptions[0].disabled) {
        quoteSelect.value = '';
        renderSelectedQuotation();
    }

    if (quoteNoResults) {
        quoteNoResults.hidden = visibleCount > 0 || terms.length === 0;
    }

    renderJobQuoteResults(terms, matches);
}

if (quoteSearch) {
    quoteSearch.addEventListener('input', filterJobQuotationOptions);
    quoteSearch.addEventListener('focus', filterJobQuotationOptions);
}

if (durationType) {
    durationType.addEventListener('change', syncJobDurationControls);
}

if (durationInput) {
    durationInput.addEventListener('input', syncJobDurationTypeFromText);
}

if (jobDateInput) {
    jobDateInput.addEventListener('change', () => {
        if (!jobEndDateInput || !jobDateInput.value) return;

        if (!jobEndDateInput.value || jobEndDateInput.value < jobDateInput.value) {
            jobEndDateInput.value = jobDateInput.value;
        }

        syncJobDurationControls();
    });
}

if (jobStartTimeInput) {
    jobStartTimeInput.addEventListener('change', syncJobDurationControls);
}

if (technicianSearchInput) {
    technicianSearchInput.addEventListener('input', () => {
        renderTechnicianResults(normalizeJobSearchTerms(technicianSearchInput.value));
    });
    technicianSearchInput.addEventListener('focus', () => {
        renderTechnicianResults(normalizeJobSearchTerms(technicianSearchInput.value));
    });
    technicianSearchInput.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        const firstMatch = availableTechnicianMatches(normalizeJobSearchTerms(technicianSearchInput.value))[0];
        if (!firstMatch) return;
        event.preventDefault();
        addTechnician(firstMatch);
    });
}

if (technicianSelect) {
    technicianSelect.addEventListener('change', () => addTechnician(technicianSelect.value));
}

if (quoteResults) {
    document.addEventListener('click', (event) => {
        if (!quoteResults.contains(event.target) && event.target !== quoteSearch) {
            closeJobQuoteResults();
        }
    });
}

if (technicianResults) {
    document.addEventListener('click', (event) => {
        if (!technicianResults.contains(event.target) && event.target !== technicianSearchInput) {
            closeTechnicianResults();
        }
    });
}

quoteSelect.addEventListener('change', () => {
    syncJobQuoteSearchFromSelection();
    renderSelectedQuotation();
});
filterJobQuotationOptions();
renderSelectedQuotation();
syncJobDurationControls();
renderSelectedTechnicians();

if (frontJobOrderForm) {
    frontJobOrderForm.addEventListener('submit', (event) => {
        if (frontJobTechnicians.length > 0 && selectedTechnicians.size === 0) {
            event.preventDefault();
            if (technicianSearchInput) {
                technicianSearchInput.focus();
            }
            alert('Please choose at least one assigned technician.');
            return;
        }

        if (jobDateInput && jobEndDateInput && jobEndDateInput.value && jobDateInput.value && jobEndDateInput.value < jobDateInput.value) {
            event.preventDefault();
            jobEndDateInput.focus();
            alert('Expected completion date cannot be earlier than the job date.');
        }
    });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
