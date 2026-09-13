<?php
/**
 * Customer Profile & Detail View
 */

require_once '../../includes/config.php';
require_once '../../includes/record-filters.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$user = app_get_session_user();
if (!is_array($user)) {
    redirect('/hwtires/index.php');
}

$customer_id = intval($_GET['id'] ?? 0);
$branch_filter = $_GET['branch'] ?? '';
$branch_filter = $branch_filter !== '' ? intval($branch_filter) : '';
if ($branch_filter !== '' && $branch_filter <= 0) {
    $branch_filter = '';
}
$date_filter = record_date_filter_current('all');
$page_title = 'Customer Profile';

if ($customer_id <= 0) {
    redirect('index.php');
}

if (!function_exists('profile_branch_label')) {
    function profile_branch_label($name) {
        return app_branch_label($name, '-');
    }
}

if (!function_exists('profile_branch_class')) {
    function profile_branch_class($branch_id) {
        $branch_id = intval($branch_id);
        if ($branch_id <= 0) {
            return 'customer-branch-empty';
        }

        return 'customer-branch-' . ((($branch_id - 1) % 3) + 1);
    }
}

if (!function_exists('profile_vehicle_name')) {
    function profile_vehicle_name($record, $include_plate = false) {
        $name = trim(($record['make'] ?? '') . ' ' . ($record['model'] ?? ''));
        if ($name === '') {
            $name = 'Vehicle';
        }

        if ($include_plate && !empty($record['plate_number'])) {
            $name .= ' (' . $record['plate_number'] . ')';
        }

        return $name;
    }
}

if (!function_exists('profile_short_date')) {
    function profile_short_date($date) {
        return !empty($date) ? date('Y-m-d', strtotime($date)) : '-';
    }
}

if (!function_exists('profile_money')) {
    function profile_money($amount) {
        return '&#8369;' . number_format((float) $amount, 0);
    }
}

if (!function_exists('profile_split_services')) {
    function profile_split_services($value) {
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

try {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ? AND status = 'active'");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch();

    if (!$customer) {
        redirect('index.php');
    }

    $branches = $pdo->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name ASC")->fetchAll();

    $branch_union_queries = [];
    $branch_union_params = [];

    if (app_table_exists('customer_branch_records')) {
        $branch_union_queries[] = "SELECT branch_id FROM customer_branch_records WHERE customer_id = ? AND status = 'active'";
        $branch_union_params[] = $customer_id;
    }

    $branch_union_queries[] = "SELECT branch_id FROM vehicles WHERE customer_id = ? AND status = 'active'";
    $branch_union_params[] = $customer_id;

    $branch_union_queries[] = "SELECT branch_id FROM quotations WHERE customer_id = ?";
    $branch_union_params[] = $customer_id;

    $branch_union_queries[] = "SELECT branch_id FROM job_orders WHERE customer_id = ?";
    $branch_union_params[] = $customer_id;

    $branch_union_queries[] = "SELECT branch_id FROM service_history WHERE customer_id = ?";
    $branch_union_params[] = $customer_id;

    $branch_union_sql = implode(' UNION ', $branch_union_queries);

    $customer_branches_stmt = $pdo->prepare("
        SELECT DISTINCT b.id, b.name
        FROM branches b
        INNER JOIN (
            $branch_union_sql
        ) used_branches ON used_branches.branch_id = b.id
        WHERE b.status = 'active'
        ORDER BY b.id ASC
    ");
    $customer_branches_stmt->execute($branch_union_params);
    $customer_branches = $customer_branches_stmt->fetchAll();
    $display_customer_branches = $customer_branches;

    if ($branch_filter !== '') {
        $display_customer_branches = array_values(array_filter($customer_branches, function ($branch) use ($branch_filter) {
            return intval($branch['id'] ?? 0) === $branch_filter;
        }));
    }

    $vehicle_filter_sql = '';
    $vehicle_params = [$customer_id];
    if ($branch_filter !== '') {
        $vehicle_filter_sql = " AND (
            v.branch_id = ?
            OR EXISTS (
                SELECT 1 FROM quotations q_vehicle
                WHERE q_vehicle.vehicle_id = v.id
                  AND q_vehicle.customer_id = v.customer_id
                  AND q_vehicle.branch_id = ?
                  AND q_vehicle.status <> 'archived'
            )
            OR EXISTS (
                SELECT 1 FROM job_orders jo_vehicle
                WHERE jo_vehicle.vehicle_id = v.id
                  AND jo_vehicle.customer_id = v.customer_id
                  AND jo_vehicle.branch_id = ?
                  AND jo_vehicle.status <> 'archived'
            )
            OR EXISTS (
                SELECT 1 FROM service_history sh_vehicle
                WHERE sh_vehicle.vehicle_id = v.id
                  AND sh_vehicle.customer_id = v.customer_id
                  AND sh_vehicle.branch_id = ?
            )
        )";
        $vehicle_params = array_merge($vehicle_params, [$branch_filter, $branch_filter, $branch_filter, $branch_filter]);
    }

    $vehicles_stmt = $pdo->prepare("
        SELECT v.*,
               b.name AS created_by_branch
        FROM vehicles v
        LEFT JOIN branches b ON v.branch_id = b.id
        WHERE v.customer_id = ?
          AND v.status = 'active'
          $vehicle_filter_sql
        ORDER BY v.last_service_date DESC, v.id DESC
    ");
    $vehicles_stmt->execute($vehicle_params);
    $vehicles = $vehicles_stmt->fetchAll();

    $record_filter_sql = '';
    $filter_params = [$customer_id];
    $quote_activity_expr = record_activity_datetime_expr('q.quotation_date', 'q.created_at', 'q.updated_at');
    $quote_record_date_expr = record_business_datetime_expr('q.quotation_date', 'q.created_at');
    if ($branch_filter !== '') {
        $record_filter_sql = ' AND q.branch_id = ?';
        $filter_params[] = $branch_filter;
    }
    $record_date_params = [];
    $record_date_condition = record_date_filter_condition($quote_record_date_expr, $date_filter, $record_date_params);
    if ($record_date_condition !== '') {
        $record_filter_sql .= " AND $record_date_condition";
        $filter_params = array_merge($filter_params, $record_date_params);
    }

    $quotations_stmt = $pdo->prepare("
        SELECT q.*, b.name AS branch_name, v.make, v.model, v.plate_number
        FROM quotations q
        LEFT JOIN branches b ON b.id = q.branch_id
        LEFT JOIN vehicles v ON v.id = q.vehicle_id
        WHERE q.customer_id = ?
          AND q.status <> 'archived'
          $record_filter_sql
        ORDER BY $quote_activity_expr DESC, q.id DESC
        LIMIT 10
    ");
    $quotations_stmt->execute($filter_params);
    $quotations = $quotations_stmt->fetchAll();

    $job_filter_sql = '';
    $job_params = [$customer_id];
    $job_activity_expr = record_activity_datetime_expr('jo.job_date', 'jo.created_at', 'jo.updated_at');
    $job_record_date_expr = record_business_datetime_expr('jo.job_date', 'jo.created_at');
    if ($branch_filter !== '') {
        $job_filter_sql = ' AND jo.branch_id = ?';
        $job_params[] = $branch_filter;
    }
    $job_date_params = [];
    $job_date_condition = record_date_filter_condition($job_record_date_expr, $date_filter, $job_date_params);
    if ($job_date_condition !== '') {
        $job_filter_sql .= " AND $job_date_condition";
        $job_params = array_merge($job_params, $job_date_params);
    }

    $jobs_stmt = $pdo->prepare("
        SELECT jo.*, b.name AS branch_name, v.make, v.model, v.plate_number
        FROM job_orders jo
        LEFT JOIN branches b ON b.id = jo.branch_id
        LEFT JOIN vehicles v ON v.id = jo.vehicle_id
        WHERE jo.customer_id = ?
          AND jo.status <> 'archived'
          $job_filter_sql
        ORDER BY $job_activity_expr DESC, jo.id DESC
        LIMIT 10
    ");
    $jobs_stmt->execute($job_params);
    $job_orders = $jobs_stmt->fetchAll();

    $history_filter_sql = '';
    $history_params = [$customer_id];
    $history_activity_expr = record_activity_datetime_expr('sh.service_date', 'sh.created_at');
    $history_record_date_expr = record_business_datetime_expr('sh.service_date', 'sh.created_at');
    if ($branch_filter !== '') {
        $history_filter_sql = ' AND sh.branch_id = ?';
        $history_params[] = $branch_filter;
    }
    $history_date_params = [];
    $history_date_condition = record_date_filter_condition($history_record_date_expr, $date_filter, $history_date_params);
    if ($history_date_condition !== '') {
        $history_filter_sql .= " AND $history_date_condition";
        $history_params = array_merge($history_params, $history_date_params);
    }

    $history_stmt = $pdo->prepare("
        SELECT sh.*, b.name AS branch_name, v.make, v.model, v.plate_number
        FROM service_history sh
        LEFT JOIN branches b ON b.id = sh.branch_id
        LEFT JOIN vehicles v ON v.id = sh.vehicle_id
        WHERE sh.customer_id = ? $history_filter_sql
        ORDER BY $history_activity_expr DESC, sh.id DESC
        LIMIT 15
    ");
    $history_stmt->execute($history_params);
    $service_history = $history_stmt->fetchAll();

    $quotation_ids = array_map('intval', array_column($quotations, 'id'));
    $job_quotation_ids = array_filter(array_map('intval', array_column($job_orders, 'quotation_id')));
    $item_quote_ids = array_values(array_unique(array_merge($quotation_ids, $job_quotation_ids)));
    $quotation_items = [];

    if (!empty($item_quote_ids)) {
        $placeholders = implode(',', array_fill(0, count($item_quote_ids), '?'));
        $items_stmt = $pdo->prepare("SELECT * FROM quotation_items WHERE quotation_id IN ($placeholders) ORDER BY id ASC");
        $items_stmt->execute($item_quote_ids);

        foreach ($items_stmt->fetchAll() as $item) {
            $quotation_items[intval($item['quotation_id'])][] = $item;
        }
    }

    $last_visit = null;
    foreach ($service_history as $entry) {
        if (!empty($entry['service_date'])) {
            $last_visit = [
                'date' => $entry['service_date'],
                'branch_id' => $entry['branch_id'] ?? null,
                'branch_name' => $entry['branch_name'] ?? null
            ];
            break;
        }
    }

    if (!$last_visit) {
        foreach ($job_orders as $job) {
            if (!empty($job['job_date'])) {
                $last_visit = [
                    'date' => $job['job_date'],
                    'branch_id' => $job['branch_id'] ?? null,
                    'branch_name' => $job['branch_name'] ?? null
                ];
                break;
            }
        }
    }

    if (!$last_visit) {
        foreach ($quotations as $quotation) {
            if (!empty($quotation['quotation_date'])) {
                $last_visit = [
                    'date' => $quotation['quotation_date'],
                    'branch_id' => $quotation['branch_id'] ?? null,
                    'branch_name' => $quotation['branch_name'] ?? null
                ];
                break;
            }
        }
    }
} catch (Exception $e) {
    error_log('Customer profile error: ' . $e->getMessage());
    redirect('index.php');
}

$primary_vehicle = $vehicles[0] ?? null;
$customer_address = trim(($customer['address'] ?? '') . (!empty($customer['city']) ? ', ' . $customer['city'] : ''));
$customer_address = $customer_address !== '' ? $customer_address : '-';
$customer_contact = ($customer['phone_mobile'] ?? '') ?: (($customer['contact'] ?? '') ?: '-');
?>

<?php require_once '../../includes/header.php'; ?>
<?php require_once '../../includes/sidebar.php'; ?>

<div class="customer-profile-page">
    <a href="/hwtires/admin/customers/" class="customer-profile-back">
        <i class="fas fa-arrow-left"></i>
        <span>Back</span>
    </a>

    <section class="customer-profile-topbar">
        <div class="customer-profile-hero">
            <h1><?php echo esc_html($customer['name']); ?></h1>
            <p>Customer Profile &amp; Complete Records</p>
        </div>
        <div class="customer-profile-actions">
            <form id="deleteCustomerForm" action="/hwtires/api/customers-api.php" method="POST" style="display:inline-block; margin:0;">
                <input type="hidden" name="action" value="archive">
                <input type="hidden" name="id" value="<?php echo (int) $customer_id; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo esc_attr(generate_csrf_token()); ?>">
                <input type="hidden" name="redirect" value="/hwtires/admin/customers/">
                <button type="submit" class="profile-create-btn" style="background:#fff1f2; border:1px solid #fecdd3; color:#e11d48;" title="Archive customer record"
                        onclick="return confirm('Archive this customer and related records? The records will be hidden but kept in the database.')">
                    <i class="fas fa-box-archive"></i>
                    <span>Archive Customer</span>
                </button>
            </form>
        </div>
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

    <section class="customer-profile-filter-card" style="padding: 10px 16px; margin-bottom: 14px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px;">
        <form method="GET" class="customer-profile-inline-filter" data-record-date-filter style="display: flex; align-items: center; gap: 12px; margin: 0; flex-wrap: wrap;">
            <input type="hidden" name="id" value="<?php echo (int) $customer_id; ?>">

            <div style="display: inline-flex; align-items: center; gap: 8px;">
                <label for="filter_branch" style="font-weight: 700; color: #1e293b; font-size: 0.875rem; margin: 0; white-space: nowrap;">Branch:</label>
                <select id="filter_branch" name="branch" class="form-select form-select-sm" style="width: auto; min-width: 160px; height: 36px; font-size: 0.875rem; border-radius: 8px; border: 1px solid #cbd5e1;" onchange="this.form.submit()">
                    <option value="">All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?php echo (int) $branch['id']; ?>" <?php echo $branch_filter === (int) $branch['id'] ? 'selected' : ''; ?>>
                            <?php echo esc_html(profile_branch_label($branch['name'])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display: inline-flex; align-items: center; gap: 8px;">
                <label for="filter_period" style="font-weight: 700; color: #1e293b; font-size: 0.875rem; margin: 0; white-space: nowrap;">Period:</label>
                <select id="filter_period" name="date_scope" class="records-date-scope form-select form-select-sm" style="width: auto; min-width: 140px; height: 36px; font-size: 0.875rem; border-radius: 8px; border: 1px solid #cbd5e1;" aria-label="Select record period">
                    <option value="all" <?php echo ($date_filter['scope'] ?? '') === 'all' ? 'selected' : ''; ?>>All Records</option>
                    <option value="recent" <?php echo ($date_filter['scope'] ?? '') === 'recent' ? 'selected' : ''; ?>>Current Week</option>
                    <option value="day" <?php echo ($date_filter['scope'] ?? '') === 'day' ? 'selected' : ''; ?>>Day</option>
                    <option value="week" <?php echo ($date_filter['scope'] ?? '') === 'week' ? 'selected' : ''; ?>>Week</option>
                    <option value="month" <?php echo ($date_filter['scope'] ?? '') === 'month' ? 'selected' : ''; ?>>Month</option>
                    <option value="year" <?php echo ($date_filter['scope'] ?? '') === 'year' ? 'selected' : ''; ?>>Year</option>
                    <option value="range" <?php echo ($date_filter['scope'] ?? '') === 'range' ? 'selected' : ''; ?>>Date Range</option>
                </select>
            </div>

            <div data-date-input="day" style="display: inline-flex; align-items: center; gap: 6px;">
                <input type="date" name="date_day" class="form-control form-control-sm" style="width: auto; height: 36px; font-size: 0.875rem; border-radius: 8px; border: 1px solid #cbd5e1;" value="<?php echo esc_attr($date_filter['day']); ?>">
            </div>
            <div data-date-input="week" style="display: inline-flex; align-items: center; gap: 6px;">
                <input type="week" name="date_week" class="form-control form-control-sm" style="width: auto; height: 36px; font-size: 0.875rem; border-radius: 8px; border: 1px solid #cbd5e1;" value="<?php echo esc_attr($date_filter['week']); ?>">
            </div>
            <div data-date-input="month" style="display: inline-flex; align-items: center; gap: 6px;">
                <input type="month" name="date_month" class="form-control form-control-sm" style="width: auto; height: 36px; font-size: 0.875rem; border-radius: 8px; border: 1px solid #cbd5e1;" value="<?php echo esc_attr($date_filter['month']); ?>">
            </div>
            <div data-date-input="year" style="display: inline-flex; align-items: center; gap: 6px;">
                <input type="number" name="date_year" class="form-control form-control-sm" style="width: 90px; height: 36px; font-size: 0.875rem; border-radius: 8px; border: 1px solid #cbd5e1;" min="2020" max="2100" value="<?php echo (int) $date_filter['year']; ?>">
            </div>
            <div data-date-input="range" style="display: inline-flex; align-items: center; gap: 6px;">
                <span style="font-size: 0.85rem; color: #64748b;">From:</span>
                <input type="date" name="date_from" class="form-control form-control-sm" style="width: auto; height: 36px; font-size: 0.875rem; border-radius: 8px; border: 1px solid #cbd5e1;" value="<?php echo esc_attr($date_filter['from']); ?>">
                <span style="font-size: 0.85rem; color: #64748b;">To:</span>
                <input type="date" name="date_to" class="form-control form-control-sm" style="width: auto; height: 36px; font-size: 0.875rem; border-radius: 8px; border: 1px solid #cbd5e1;" value="<?php echo esc_attr($date_filter['to']); ?>">
            </div>

            <button type="submit" class="btn btn-sm btn-primary" style="height: 36px; padding: 0 18px; font-weight: 700; font-size: 0.875rem; border-radius: 8px; background: #0096b6; border: none; white-space: nowrap;">Apply</button>
            <?php if ($branch_filter !== '' || ($date_filter['scope'] ?? 'all') !== 'all'): ?>
                <a href="profile.php?id=<?php echo (int) $customer_id; ?>" class="btn btn-sm btn-outline-secondary" style="height: 36px; display: inline-flex; align-items: center; padding: 0 12px; font-size: 0.85rem; border-radius: 8px; white-space: nowrap;">Clear</a>
            <?php endif; ?>
        </form>
    </section>
    <?php record_date_filter_script(); ?>

    <section class="customer-profile-summary-grid" id="customer-profile-records" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 14px; margin-bottom: 16px;">
        <article class="customer-profile-card customer-info-card" style="padding: 12px 16px; margin: 0; border-radius: 10px;">
            <div class="customer-profile-card-title" style="margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; gap: 8px;">
                <span class="profile-title-icon icon-cyan" style="width: 26px; height: 26px; font-size: 12px;"><i class="far fa-user"></i></span>
                <h2 style="font-size: 0.95rem; font-weight: 700; margin: 0;">Customer Information</h2>
            </div>

            <div style="display: grid; gap: 6px; font-size: 0.875rem;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-phone text-muted" style="width: 14px;"></i>
                    <span class="text-muted" style="font-size: 0.8rem;">Contact:</span>
                    <strong style="color: #0f172a;"><?php echo esc_html($customer_contact); ?></strong>
                </div>
                <div style="display: flex; align-items: flex-start; gap: 8px;">
                    <i class="fas fa-map-marker-alt text-muted" style="width: 14px; margin-top: 2px;"></i>
                    <span class="text-muted" style="font-size: 0.8rem;">Address:</span>
                    <strong style="color: #0f172a;"><?php echo esc_html($customer_address); ?></strong>
                </div>
                <div style="display: flex; align-items: center; gap: 8px; margin-top: 2px; padding-top: 6px; border-top: 1px solid #f8fafc;">
                    <span class="text-muted" style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700;">Last Visited:</span>
                    <?php if ($last_visit): ?>
                        <span class="customer-branch-pill <?php echo esc_attr(profile_branch_class($last_visit['branch_id'] ?? 0)); ?>" style="padding: 2px 8px; font-size: 0.75rem;">
                            <?php echo esc_html(profile_branch_label($last_visit['branch_name'] ?? '')); ?>
                        </span>
                        <span class="text-muted" style="font-size: 0.8rem;"><?php echo esc_html(profile_short_date($last_visit['date'])); ?></span>
                    <?php else: ?>
                        <span class="text-muted" style="font-size: 0.8rem;">No visits recorded</span>
                    <?php endif; ?>
                </div>
            </div>
        </article>

        <article class="customer-profile-card vehicle-info-card" style="padding: 12px 16px; margin: 0; border-radius: 10px;">
            <div class="customer-profile-card-title" style="margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; gap: 8px;">
                <span class="profile-title-icon icon-green" style="width: 26px; height: 26px; font-size: 12px;"><i class="fas fa-car-side"></i></span>
                <h2 style="font-size: 0.95rem; font-weight: 700; margin: 0;">Vehicles (<?php echo count($vehicles); ?>)</h2>
            </div>

            <?php if (empty($vehicles)): ?>
                <div class="profile-empty-state" style="padding: 12px; font-size: 0.85rem;">
                    <?php echo $branch_filter !== '' ? 'No vehicle records found for this branch.' : 'No vehicles registered for this customer.'; ?>
                </div>
            <?php else: ?>
                <div style="display: grid; gap: 6px;">
                    <?php foreach ($vehicles as $vehicle): ?>
                        <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 6px; padding: 6px 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <strong style="font-size: 0.875rem; color: #0f172a;"><?php echo esc_html(profile_vehicle_name($vehicle)); ?></strong>
                                <?php if (!empty($vehicle['plate_number'])): ?>
                                    <span class="customer-plate-pill" style="padding: 1px 6px; font-size: 0.75rem;"><?php echo esc_html($vehicle['plate_number']); ?></span>
                                <?php endif; ?>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px; font-size: 0.8rem; color: #475569;">
                                <span><?php echo esc_html($vehicle['color'] ?? ''); ?> <?php echo esc_html($vehicle['year'] ?? ''); ?></span>
                                <span>•</span>
                                <span><?php echo !empty($vehicle['last_mileage']) ? number_format((float) $vehicle['last_mileage']) . ' km' : '-'; ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
    </section>

    <!-- SERVICE OPERATIONS (COLLAPSIBLE ACCORDION) -->
    <section class="profile-record-card profile-quotations-card" style="padding: 14px 18px; margin-bottom: 14px; border-radius: 10px;">
        <div class="profile-section-title" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span class="profile-title-icon icon-purple" style="width: 26px; height: 26px; font-size: 12px;"><i class="far fa-file-alt"></i></span>
                <h2 style="font-size: 1rem; font-weight: 750; margin: 0;">Service Operations (<?php echo count($quotations); ?>)</h2>
            </div>
            <?php if (!empty($quotations)): ?>
                <button type="button" class="btn btn-sm btn-link text-decoration-none" style="font-size: 0.8rem; padding: 0; color: #6366f1;" onclick="toggleSectionCollapse(this, '.profile-quotations-card')">
                    Expand All
                </button>
            <?php endif; ?>
        </div>

        <?php if (empty($quotations)): ?>
            <div class="profile-empty-state" style="padding: 14px; font-size: 0.85rem;">No service operation records found for this customer.</div>
        <?php else: ?>
            <div class="profile-record-list" style="display: grid; gap: 8px;">
                <?php foreach ($quotations as $quotation): ?>
                    <?php
                    $items = $quotation_items[intval($quotation['id'])] ?? [];
                    $quotation_status = strtolower(str_replace(' ', '-', $quotation['status'] ?? 'pending'));
                    ?>
                    <article class="profile-record-item record-purple" style="margin-bottom: 0; padding: 10px 14px; border-radius: 8px;">
                        <div class="profile-record-top" style="cursor: pointer; user-select: none;" onclick="toggleRecordItem(this)">
                            <div class="profile-record-id" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <span class="customer-branch-pill <?php echo esc_attr(profile_branch_class($quotation['branch_id'] ?? 0)); ?>" style="padding: 2px 8px; font-size: 0.75rem;">
                                    <?php echo esc_html(profile_branch_label($quotation['branch_name'] ?? '')); ?>
                                </span>
                                <strong style="font-size: 0.875rem; color: #0f172a;"><?php echo esc_html($quotation['quotation_number'] ?? '-'); ?></strong>
                                <span class="text-muted" style="font-size: 0.8rem;"><?php echo esc_html(profile_short_date($quotation['quotation_date'] ?? '')); ?></span>
                                <span class="text-muted" style="font-size: 0.8rem;">• <?php echo esc_html(profile_vehicle_name($quotation, true)); ?></span>
                            </div>
                            <div class="profile-record-total" style="display: flex; align-items: center; gap: 10px;">
                                <span class="profile-status-pill status-<?php echo esc_attr($quotation_status); ?>" style="padding: 2px 8px; font-size: 0.75rem;">
                                    <?php echo esc_html(ucfirst($quotation['status'] ?? 'Pending')); ?>
                                </span>
                                <strong style="font-size: 0.95rem; color: #0f172a;"><?php echo profile_money($quotation['total_amount']); ?></strong>
                                <span class="profile-toggle-chevron" style="display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; background: #e2e8f0; color: #475569; font-size: 10px; transition: transform 0.2s ease;">
                                    <i class="fas fa-chevron-down"></i>
                                </span>
                            </div>
                        </div>

                        <div class="profile-record-collapsible" style="display: none; margin-top: 8px; padding-top: 8px; border-top: 1px dashed #cbd5e1;">
                            <?php
                            $has_inspection = trim((string) ($quotation['inspection_complaint'] ?? '')) !== ''
                                || trim((string) ($quotation['inspection_findings'] ?? '')) !== ''
                                || trim((string) ($quotation['inspection_recommendations'] ?? '')) !== ''
                                || !empty($quotation['inspection_mileage']);
                            ?>
                            <?php if ($has_inspection): ?>
                                <div class="profile-inspection-summary" style="margin-bottom: 8px;">
                                    <h3>Service Inspection:</h3>
                                    <div class="profile-inspection-grid">
                                        <?php if (!empty($quotation['inspection_mileage'])): ?>
                                            <div>
                                                <span>Mileage</span>
                                                <strong><?php echo number_format((int) $quotation['inspection_mileage']); ?> km</strong>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (trim((string) ($quotation['inspection_complaint'] ?? '')) !== ''): ?>
                                            <div>
                                                <span>Concern</span>
                                                <p><?php echo nl2br(esc_html($quotation['inspection_complaint'])); ?></p>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (trim((string) ($quotation['inspection_findings'] ?? '')) !== ''): ?>
                                            <div>
                                                <span>Findings</span>
                                                <p><?php echo nl2br(esc_html($quotation['inspection_findings'])); ?></p>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (trim((string) ($quotation['inspection_recommendations'] ?? '')) !== ''): ?>
                                            <div>
                                                <span>Recommended Action</span>
                                                <p><?php echo nl2br(esc_html($quotation['inspection_recommendations'])); ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <h3 style="font-size: 0.8rem; margin: 4px 0 6px;">Services &amp; Items:</h3>
                            <div class="profile-line-items">
                                <?php if (empty($items)): ?>
                                    <div class="profile-line-item"><span>No items listed</span><span></span></div>
                                <?php else: ?>
                                    <?php foreach ($items as $item): ?>
                                        <div class="profile-line-item">
                                            <span>
                                                <?php echo esc_html(app_display_item_name($item['item_name'], $item['item_type'] ?? null)); ?>
                                                <?php echo intval($item['quantity'] ?? 1) > 1 ? ' (x' . intval($item['quantity']) . ')' : ''; ?>
                                            </span>
                                            <span><?php echo profile_money(($item['subtotal'] ?? 0) ?: ((float) $item['quantity'] * (float) $item['unit_price'])); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <div class="profile-line-total">
                                    <strong>Total</strong>
                                    <strong><?php echo profile_money($quotation['total_amount']); ?></strong>
                                </div>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- JOB ORDERS (COLLAPSIBLE ACCORDION) -->
    <section class="profile-record-card profile-jobs-card" style="padding: 14px 18px; margin-bottom: 14px; border-radius: 10px;">
        <div class="profile-section-title" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span class="profile-title-icon icon-cyan" style="width: 26px; height: 26px; font-size: 12px;"><i class="far fa-clipboard"></i></span>
                <h2 style="font-size: 1rem; font-weight: 750; margin: 0;">Job Orders (<?php echo count($job_orders); ?>)</h2>
            </div>
            <?php if (!empty($job_orders)): ?>
                <button type="button" class="btn btn-sm btn-link text-decoration-none" style="font-size: 0.8rem; padding: 0; color: #0891b2;" onclick="toggleSectionCollapse(this, '.profile-jobs-card')">
                    Expand All
                </button>
            <?php endif; ?>
        </div>

        <?php if (empty($job_orders)): ?>
            <div class="profile-empty-state" style="padding: 14px; font-size: 0.85rem;">No job orders found for this customer.</div>
        <?php else: ?>
            <div class="profile-record-list" style="display: grid; gap: 8px;">
                <?php foreach ($job_orders as $job): ?>
                    <?php
                    $job_items = !empty($job['quotation_id']) ? ($quotation_items[intval($job['quotation_id'])] ?? []) : [];
                    $job_status = strtolower(str_replace(' ', '-', $job['status'] ?? 'waiting'));
                    $job_status_label = $job_status === 'waiting' ? 'Pending' : ucwords(str_replace('-', ' ', $job_status));
                    ?>
                    <article class="profile-record-item record-cyan" style="margin-bottom: 0; padding: 10px 14px; border-radius: 8px;">
                        <div class="profile-record-top" style="cursor: pointer; user-select: none;" onclick="toggleRecordItem(this)">
                            <div class="profile-record-id" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <span class="customer-branch-pill <?php echo esc_attr(profile_branch_class($job['branch_id'] ?? 0)); ?>" style="padding: 2px 8px; font-size: 0.75rem;">
                                    <?php echo esc_html(profile_branch_label($job['branch_name'] ?? '')); ?>
                                </span>
                                <strong style="font-size: 0.875rem; color: #0f172a;"><?php echo esc_html($job['job_number'] ?? '-'); ?></strong>
                                <span class="text-muted" style="font-size: 0.8rem;"><?php echo esc_html(profile_short_date($job['job_date'] ?? $job['created_at'] ?? '')); ?></span>
                                <span class="text-muted" style="font-size: 0.8rem;">• <?php echo esc_html(profile_vehicle_name($job, true)); ?></span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <span class="profile-status-pill status-<?php echo esc_attr($job_status); ?>" style="padding: 2px 8px; font-size: 0.75rem;">
                                    <?php echo esc_html($job_status_label); ?>
                                </span>
                                <span class="profile-toggle-chevron" style="display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; background: #e2e8f0; color: #475569; font-size: 10px; transition: transform 0.2s ease;">
                                    <i class="fas fa-chevron-down"></i>
                                </span>
                            </div>
                        </div>

                        <div class="profile-record-collapsible" style="display: none; margin-top: 8px; padding-top: 8px; border-top: 1px dashed #cbd5e1;">
                            <div class="profile-record-facts" style="margin: 6px 0;">
                                <div>
                                    <span>Assigned Technician</span>
                                    <strong><?php echo esc_html(($job['assigned_technician_name'] ?? '') ?: 'Unassigned'); ?></strong>
                                </div>
                                <div>
                                    <span>Completed Date</span>
                                    <strong><?php echo $job_status === 'completed' ? esc_html(profile_short_date($job['actual_end_time'] ?? $job['job_date'] ?? '')) : '-'; ?></strong>
                                </div>
                            </div>

                            <h3 style="font-size: 0.8rem; margin: 6px 0 4px;">Services Requested:</h3>
                            <div class="profile-chip-list">
                                <?php if (empty($job_items)): ?>
                                    <span class="profile-service-chip chip-cyan" style="font-size: 0.75rem;">Service Request</span>
                                <?php else: ?>
                                    <?php foreach ($job_items as $item): ?>
                                        <span class="profile-service-chip chip-cyan" style="font-size: 0.75rem;"><?php echo esc_html(app_display_item_name($item['item_name'], $item['item_type'] ?? null)); ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($job['notes'])): ?>
                                <div class="profile-record-notes" style="margin-top: 6px; padding-top: 6px;">
                                    <span>Notes:</span>
                                    <p><?php echo esc_html(app_format_record_notes($job['notes'])); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- SERVICE HISTORY (COLLAPSIBLE ACCORDION) -->
    <section class="profile-record-card profile-history-card" style="padding: 14px 18px; margin-bottom: 14px; border-radius: 10px;">
        <div class="profile-section-title" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span class="profile-title-icon icon-green" style="width: 26px; height: 26px; font-size: 12px;"><i class="fas fa-clock-rotate-left"></i></span>
                <h2 style="font-size: 1rem; font-weight: 750; margin: 0;">Service History (<?php echo count($service_history); ?>)</h2>
            </div>
            <?php if (!empty($service_history)): ?>
                <button type="button" class="btn btn-sm btn-link text-decoration-none" style="font-size: 0.8rem; padding: 0; color: #10b981;" onclick="toggleSectionCollapse(this, '.profile-history-card')">
                    Expand All
                </button>
            <?php endif; ?>
        </div>

        <?php if (empty($service_history)): ?>
            <div class="profile-empty-state" style="padding: 14px; font-size: 0.85rem;">No service history found for this customer.</div>
        <?php else: ?>
            <div class="profile-record-list" style="display: grid; gap: 8px;">
                <?php foreach ($service_history as $entry): ?>
                    <?php $service_names = profile_split_services($entry['services_description'] ?? 'Service'); ?>
                    <article class="profile-record-item" style="margin-bottom: 0; padding: 10px 14px; border-radius: 8px; border-left: 4px solid #10b981;">
                        <div class="profile-history-top" style="cursor: pointer; user-select: none;" onclick="toggleRecordItem(this)">
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <span class="customer-branch-pill <?php echo esc_attr(profile_branch_class($entry['branch_id'] ?? 0)); ?>" style="padding: 2px 8px; font-size: 0.75rem;">
                                    <?php echo esc_html(profile_branch_label($entry['branch_name'] ?? '')); ?>
                                </span>
                                <strong style="font-size: 0.875rem; color: #0f172a;"><?php echo esc_html(profile_vehicle_name($entry, true)); ?></strong>
                                <span class="text-muted" style="font-size: 0.8rem;"><?php echo esc_html(profile_short_date($entry['service_date'] ?? '')); ?></span>
                            </div>
                            <div class="profile-history-total" style="display: flex; align-items: center; gap: 10px;">
                                <span class="text-muted" style="font-size: 0.8rem;"><?php echo !empty($entry['mileage_at_service']) ? number_format((float) $entry['mileage_at_service']) . ' km' : '-'; ?></span>
                                <strong style="font-size: 0.95rem; color: #0f172a;"><?php echo profile_money($entry['total_cost']); ?></strong>
                                <span class="profile-toggle-chevron" style="display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; background: #e2e8f0; color: #475569; font-size: 10px; transition: transform 0.2s ease;">
                                    <i class="fas fa-chevron-down"></i>
                                </span>
                            </div>
                        </div>

                        <div class="profile-record-collapsible" style="display: none; margin-top: 8px; padding-top: 8px; border-top: 1px dashed #cbd5e1;">
                            <div style="margin-bottom: 6px;">
                                <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Services Rendered:</span>
                                <div class="profile-chip-list" style="margin-top: 4px;">
                                    <?php foreach ($service_names as $service_name): ?>
                                        <span class="profile-service-chip chip-green" style="font-size: 0.75rem;"><?php echo esc_html($service_name); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <?php if (!empty($entry['quotation_number']) || !empty($entry['job_number'])): ?>
                                <div class="profile-history-links" style="margin-top: 6px; padding-top: 6px; font-size: 0.8rem;">
                                    <?php if (!empty($entry['quotation_number'])): ?>
                                        <span><i class="far fa-file-lines"></i> Service Operation: <?php echo esc_html($entry['quotation_number']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($entry['job_number'])): ?>
                                        <span><i class="far fa-clipboard"></i> Job Order: <?php echo esc_html($entry['job_number']); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($entry['notes'])): ?>
                                <div class="profile-record-notes" style="margin-top: 6px; padding-top: 6px;">
                                    <span>Notes:</span>
                                    <p><?php echo esc_html(app_format_record_notes($entry['notes'])); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<!-- ADD VEHICLE MODAL -->
<div class="modal fade" id="addVehicleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Vehicle for <?php echo esc_html($customer['name']); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addVehicleForm" action="/hwtires/api/customers-api.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_vehicle">
                    <input type="hidden" name="customer_id" value="<?php echo (int) $customer_id; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo esc_attr(generate_csrf_token()); ?>">

                    <div class="mb-3">
                        <label class="form-label">Plate Number <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="plate_number" maxlength="8" pattern="[A-Z]{3}-[0-9]{4}" placeholder="ABC-1234" title="Use the ABC-1234 format" autocomplete="off" autocapitalize="characters" data-plate-input required>
                        <small class="text-muted">Must be unique in the system</small>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Make <span class="text-danger">*</span></label>
                            <select class="form-select vehicle-make-select" name="make" id="admin_profile_add_veh_make" required>
                                <option value="" selected disabled>-- Select Car Brand --</option>
                            </select>
                            <input type="text" class="form-control vehicle-make-custom mt-2" name="make_custom" id="admin_profile_add_veh_make_custom" maxlength="50" data-text-format="first-letter" placeholder="Type custom car brand..." style="display: none;">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Model <span class="text-danger">*</span></label>
                            <select class="form-select vehicle-model-select" name="model" id="admin_profile_add_veh_model" required disabled>
                                <option value="" selected disabled>-- Select Brand First --</option>
                            </select>
                            <input type="text" class="form-control vehicle-model-custom mt-2" name="model_custom" id="admin_profile_add_veh_model_custom" maxlength="50" data-text-format="first-letter" placeholder="Type custom model..." style="display: none;">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Year</label>
                            <input type="number" class="form-control" name="year" placeholder="e.g., 2020">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Color</label>
                            <input type="text" class="form-control" name="color" maxlength="30" data-text-format="first-letter" placeholder="e.g., Silver">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Condition</label>
                        <select class="form-select" name="condition">
                            <option value="excellent">Excellent</option>
                            <option value="good" selected>Good</option>
                            <option value="fair">Fair</option>
                            <option value="poor">Poor</option>
                        </select>
                    </div>

                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> <strong>Note:</strong>
                        This vehicle will be added to the branch: <strong><?php echo esc_html($user['branch_name'] ?? 'Unknown'); ?></strong>
                        by: <strong><?php echo esc_html($user['name'] ?? 'Unknown'); ?></strong>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Vehicle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleRecordItem(headerEl) {
    const card = headerEl.closest('.profile-record-item');
    if (!card) return;
    const body = card.querySelector('.profile-record-collapsible');
    const chevron = headerEl.querySelector('.profile-toggle-chevron');
    if (!body) return;

    const isHidden = (window.getComputedStyle(body).display === 'none');
    if (isHidden) {
        body.style.display = 'block';
        if (chevron) chevron.style.transform = 'rotate(180deg)';
    } else {
        body.style.display = 'none';
        if (chevron) chevron.style.transform = 'rotate(0deg)';
    }
}

function toggleSectionCollapse(btn, sectionSelector) {
    const section = document.querySelector(sectionSelector);
    if (!section) return;
    const items = section.querySelectorAll('.profile-record-item');
    const isExpanding = (btn.textContent.trim() === 'Expand All');

    items.forEach(function(item) {
        const body = item.querySelector('.profile-record-collapsible');
        const chevron = item.querySelector('.profile-toggle-chevron');
        if (body) {
            body.style.display = isExpanding ? 'block' : 'none';
        }
        if (chevron) {
            chevron.style.transform = isExpanding ? 'rotate(180deg)' : 'rotate(0deg)';
        }
    });

    btn.textContent = isExpanding ? 'Collapse All' : 'Expand All';
}
</script>

<?php require_once '../../includes/footer.php'; ?>
