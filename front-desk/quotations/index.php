<?php
/**
 * Front Desk Quotation Records
 */

require_once '../../includes/config.php';
require_once '../../includes/record-filters.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$page_title = 'Service Operation';
$user = app_get_session_user();
$current_url = $_SERVER['REQUEST_URI'] ?? '/hwtires/front-desk/quotations/';

if (!function_exists('front_quote_branch_label')) {
    function front_quote_branch_label($name) {
        return app_branch_label($name, '-');
    }
}

if (!function_exists('front_quote_vehicle_name')) {
    function front_quote_vehicle_name($record, $include_plate = false) {
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

if (!function_exists('front_quote_money')) {
    function front_quote_money($amount, $decimals = 0) {
        return '&#8369;' . number_format((float) $amount, $decimals);
    }
}

if (!function_exists('front_quote_short_date')) {
    function front_quote_short_date($date) {
        return !empty($date) ? date('Y-m-d', strtotime($date)) : '-';
    }
}

if (!function_exists('front_quote_status_class')) {
    function front_quote_status_class($status) {
        $status = strtolower((string) $status);
        return in_array($status, ['pending', 'approved', 'rejected', 'archived'], true) ? $status : 'pending';
    }
}

if (!function_exists('front_quote_total_amount')) {
    function front_quote_total_amount($quotation, $items) {
        $items_total = 0;
        foreach ($items as $item) {
            if (($item['item_type'] ?? '') === 'service') {
                continue;
            }

            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $unit_price = (float) ($item['unit_price'] ?? 0);
            $items_total += $quantity * $unit_price;
        }

        return (float) ($quotation['labor_cost'] ?? 0) + $items_total;
    }
}

if (!function_exists('front_quote_filter_url')) {
    function front_quote_filter_url($status, $search = '', $page = null, $date_filter = null, $record_filter = 'active', $branch_filter = null) {
        $query = [];

        if ($page !== null && (int) $page > 1) {
            $query['page'] = (int) $page;
        }

        if ($status !== 'all') {
            $query['status'] = $status;
        }

        $record_filter = record_archive_filter_current($record_filter);
        if ($record_filter !== 'active') {
            $query['records'] = $record_filter;
        }

        $search = trim((string) $search);
        if ($search !== '') {
            $query['search'] = $search;
        }

        if ($branch_filter !== null && trim((string) $branch_filter) !== '') {
            $branch_value = trim((string) $branch_filter);
            $query['branch'] = $branch_value === 'all' ? 'all' : (int) $branch_value;
        }

        if (is_array($date_filter)) {
            $query = array_merge($query, record_date_filter_query_params($date_filter));
        }

        return empty($query) ? './' : '?' . http_build_query($query);
    }
}

$status_filter = $_GET['status'] ?? 'all';
$allowed_statuses = ['all', 'pending', 'approved', 'rejected'];
if (!in_array($status_filter, $allowed_statuses, true)) {
    $status_filter = 'all';
}
$record_filter = record_archive_filter_current();
if ($record_filter === 'archived') {
    $status_filter = 'all';
}

$search_filter = trim($_GET['search'] ?? '');
if (function_exists('mb_substr')) {
    $search_filter = mb_substr($search_filter, 0, 100);
} else {
    $search_filter = substr($search_filter, 0, 100);
}

$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * RECORDS_PER_PAGE;
$date_filter = record_date_filter_current();
$branch_id = (int) ($user['branch_id'] ?? 0);
$raw_branch_filter = array_key_exists('branch', $_GET)
    ? trim((string) $_GET['branch'])
    : ($branch_id > 0 ? (string) $branch_id : '');
$branch_filter = $raw_branch_filter === 'all' ? '' : ($raw_branch_filter !== '' ? intval($raw_branch_filter) : '');
if ($branch_filter !== '' && $branch_filter <= 0) {
    $branch_filter = $branch_id > 0 ? $branch_id : '';
}
$branch_query_value = $branch_filter === '' ? 'all' : (string) $branch_filter;
$branches = $pdo->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name ASC")->fetchAll();
$branch_labels = [];
foreach ($branches as $branch_option) {
    $branch_labels[(int) $branch_option['id']] = front_quote_branch_label($branch_option['name'] ?? '');
}
$selected_branch_label = $branch_filter !== '' ? ($branch_labels[(int) $branch_filter] ?? 'Selected branch') : '';
$quote_activity_expr = record_activity_datetime_expr('q.quotation_date', 'q.created_at', 'q.updated_at');
$quote_record_date_expr = record_business_datetime_expr('q.quotation_date', 'q.created_at');

$where = ['1=1'];
$params = [];
if ($record_filter === 'active') {
    $where[] = "q.status <> 'archived'";
} elseif ($record_filter === 'archived') {
    $where[] = "q.status = 'archived'";
}
if ($branch_filter !== '') {
    $where[] = 'q.branch_id = ?';
    $params[] = (int) $branch_filter;
}
if ($status_filter !== 'all') {
    $where[] = 'q.status = ?';
    $params[] = $status_filter;
}

$date_params = [];
$date_condition = record_date_filter_condition($quote_record_date_expr, $date_filter, $date_params);
if ($date_condition !== '') {
    $where[] = $date_condition;
    $params = array_merge($params, $date_params);
}

if ($search_filter !== '') {
    foreach (app_search_terms($search_filter) as $term) {
        $where[] = "(
            q.quotation_number LIKE ?
            OR c.name LIKE ?
            OR c.phone_mobile LIKE ?
            OR c.contact LIKE ?
            OR c.email LIKE ?
            OR v.make LIKE ?
            OR v.model LIKE ?
            OR v.plate_number LIKE ?
            OR b.name LIKE ?
            OR q.inspection_complaint LIKE ?
            OR q.inspection_findings LIKE ?
            OR q.inspection_recommendations LIKE ?
            OR EXISTS (
                SELECT 1
                FROM quotation_items qi
                WHERE qi.quotation_id = q.id
                  AND qi.item_name LIKE ?
            )
        )";
        $params = array_merge($params, array_fill(0, 13, '%' . $term . '%'));
    }
}
$where_sql = implode(' AND ', $where);

$from_sql = "
    FROM quotations q
    LEFT JOIN customers c ON q.customer_id = c.id
    LEFT JOIN vehicles v ON q.vehicle_id = v.id
    LEFT JOIN branches b ON q.branch_id = b.id
";

$count_stmt = $pdo->prepare("SELECT COUNT(*) AS total $from_sql WHERE $where_sql");
$count_stmt->execute($params);
$total_records = (int) $count_stmt->fetch()['total'];
$total_pages = max(1, (int) ceil($total_records / RECORDS_PER_PAGE));

$query = "SELECT q.*, c.name AS customer_name, c.phone_mobile, c.contact, c.email,
                 v.make, v.model, v.plate_number, b.name AS branch_name
          $from_sql
          WHERE $where_sql
          ORDER BY $quote_record_date_expr DESC, q.id DESC
          LIMIT " . RECORDS_PER_PAGE . " OFFSET $offset";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$quotations = $stmt->fetchAll();

$quotation_items = [];
$quotation_ids = array_map('intval', array_column($quotations, 'id'));
if (!empty($quotation_ids)) {
    $placeholders = implode(',', array_fill(0, count($quotation_ids), '?'));
    $items_stmt = $pdo->prepare("SELECT * FROM quotation_items WHERE quotation_id IN ($placeholders) ORDER BY id ASC");
    $items_stmt->execute($quotation_ids);

    foreach ($items_stmt->fetchAll() as $item) {
        $quotation_items[(int) $item['quotation_id']][] = $item;
    }

    // Batched inter-branch transfer lookup for loaded quotations
    $quotation_item_transfers = [];
    $eligible_donors_by_item = [];

    if (function_exists('app_table_exists') && app_table_exists('inter_branch_transfer_requests')) {
        $tr_placeholders = implode(',', array_fill(0, count($quotation_ids), '?'));
        $tr_stmt = $pdo->prepare("
            SELECT tr.*, db.name AS donor_branch_name
            FROM inter_branch_transfer_requests tr
            LEFT JOIN branches db ON db.id = tr.donor_branch_id
            WHERE tr.quotation_id IN ($tr_placeholders)
            ORDER BY FIELD(tr.status, 'received', 'shipped', 'approved', 'pending', 'cancelled') ASC, tr.id DESC
        ");
        $tr_stmt->execute($quotation_ids);
        $all_transfers = $tr_stmt->fetchAll(PDO::FETCH_ASSOC);

        $transfers_by_quote = [];
        foreach ($all_transfers as $tr) {
            $transfers_by_quote[(int) $tr['quotation_id']][] = $tr;
        }

        $cancelled_items_to_lookup = [];
        foreach ($quotations as $quote) {
            $qid = (int) $quote['id'];
            $quote_branch_id = (int) ($quote['branch_id'] ?? 0);
            $q_transfers = $transfers_by_quote[$qid] ?? [];
            $items_for_quote = $quotation_items[$qid] ?? [];

            foreach ($items_for_quote as $item) {
                $item_id = (int) $item['id'];
                $is_other_branch = ($item['source'] ?? '') === 'other_branch';
                $is_service = strtolower((string) ($item['item_type'] ?? '')) === 'service';

                if (!$is_other_branch || $is_service) {
                    continue;
                }

                $meta = [];
                $notes_str = trim((string) ($item['notes'] ?? ''));
                if ($notes_str !== '' && $notes_str[0] === '{') {
                    $meta = json_decode($notes_str, true) ?: [];
                }
                $meta_item_id = (int) ($meta['inventory_item_id'] ?? 0);
                $meta_branch_id = (int) ($meta['inventory_branch_id'] ?? 0);

                $matched_tr = null;
                foreach ($q_transfers as $tr) {
                    if (($meta_item_id > 0 && (int) $tr['item_id'] === $meta_item_id && (int) $tr['donor_branch_id'] === $meta_branch_id)
                        || trim($tr['item_name']) === trim($item['item_name'])) {
                        $matched_tr = $tr;
                        break;
                    }
                }

                if ($matched_tr) {
                    $quotation_item_transfers[$item_id] = $matched_tr;
                    if ($matched_tr['status'] === 'cancelled') {
                        $cancelled_items_to_lookup[] = [
                            'item_id' => $item_id,
                            'item_name' => $item['item_name'],
                            'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                            'requesting_branch_id' => $quote_branch_id,
                            'rejected_donor_branch_id' => (int) ($matched_tr['donor_branch_id'] ?? 0),
                        ];
                    }
                }
            }
        }

        if (!empty($cancelled_items_to_lookup)) {
            $donor_stmt = $pdo->prepare("
                SELECT i.id AS inventory_item_id, i.branch_id, i.quantity, b.name AS branch_name
                FROM inventory_items i
                INNER JOIN branches b ON b.id = i.branch_id
                WHERE i.item_name = ?
                  AND i.branch_id != ?
                  AND i.status = 'active'
                  AND b.status = 'active'
                  AND b.has_inventory = 1
                  AND i.quantity >= ?
                ORDER BY b.name ASC
            ");
            foreach ($cancelled_items_to_lookup as $c_item) {
                $donor_stmt->execute([
                    $c_item['item_name'],
                    $c_item['requesting_branch_id'],
                    $c_item['quantity'],
                ]);
                $raw_donors = $donor_stmt->fetchAll(PDO::FETCH_ASSOC);

                $filtered_donors = [];
                foreach ($raw_donors as $d) {
                    if ((int) $d['branch_id'] !== (int) $c_item['rejected_donor_branch_id']) {
                        $filtered_donors[] = $d;
                    }
                }
                $eligible_donors_by_item[$c_item['item_id']] = !empty($filtered_donors) ? $filtered_donors : $raw_donors;
            }
        }
    }
}

$pagination_params = '';
if ($status_filter !== 'all') {
    $pagination_params .= '&status=' . urlencode($status_filter);
}
if ($record_filter !== 'active') {
    $pagination_params .= '&records=' . urlencode($record_filter);
}
if ($search_filter !== '') {
    $pagination_params .= '&search=' . urlencode($search_filter);
}
if ($branch_query_value !== '') {
    $pagination_params .= '&branch=' . urlencode((string) $branch_query_value);
}
$pagination_params .= record_date_filter_query_string($date_filter);
?>

<?php require_once '../../includes/header.php'; ?>
<?php require_once '../../includes/sidebar.php'; ?>

<div class="quotation-records-page">
    <section class="quotation-records-hero front-quote-records-hero">
        <div>
            <h1>Service Operation</h1>
            <p>View service inspection and quotation records</p>
        </div>
        <a class="front-quote-create-btn" href="/hwtires/front-desk/quotations/create.php">
            <i class="fas fa-plus"></i>
            <span>Start Service Operation</span>
        </a>
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

    <section class="quotation-records-panel" id="quotation-records">
        <div class="quotation-panel-header">
            <h2><?php echo esc_html(record_date_filter_heading('Service Operations', $date_filter)); ?></h2>
            <div class="quotation-panel-controls hw-filter-toolbar">
                <form method="GET" action="./#quotation-records" class="hw-filter-cluster" data-record-date-filter>
                    <?php if ($search_filter !== ''): ?>
                        <input type="hidden" name="search" value="<?php echo esc_attr($search_filter); ?>">
                    <?php endif; ?>
                    <div class="hw-filter-group hw-group-branch">
                        <label for="frontQuoteBranch" class="hw-filter-label">Branch</label>
                        <select id="frontQuoteBranch" name="branch" class="hw-filter-select" aria-label="Filter service operations by branch">
                            <option value="all" <?php echo $branch_filter === '' ? 'selected' : ''; ?>>All Branches</option>
                            <?php foreach ($branches as $branch_option): ?>
                                <?php $branch_option_id = (int) $branch_option['id']; ?>
                                <option value="<?php echo $branch_option_id; ?>" <?php echo $branch_filter === $branch_option_id ? 'selected' : ''; ?>>
                                    <?php echo esc_html(front_quote_branch_label($branch_option['name'] ?? '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="hw-filter-group hw-group-status">
                        <label for="frontQuoteRecords" class="hw-filter-label">Archive Status</label>
                        <select id="frontQuoteRecords" name="records" class="hw-filter-select" aria-label="Filter service operations by record state">
                            <?php foreach (record_archive_filter_options() as $record_value => $record_label): ?>
                                <option value="<?php echo esc_attr($record_value); ?>" <?php echo $record_filter === $record_value ? 'selected' : ''; ?>>
                                    <?php echo esc_html($record_label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="hw-filter-group hw-group-status">
                        <label for="frontQuoteStatus" class="hw-filter-label">Status</label>
                        <select id="frontQuoteStatus" name="status" class="hw-filter-select" aria-label="Filter service operations by status">
                            <?php foreach ($allowed_statuses as $status): ?>
                                <?php $label = $status === 'all' ? 'All' : ucfirst($status); ?>
                                <option value="<?php echo esc_attr($status); ?>" <?php echo $status_filter === $status ? 'selected' : ''; ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="hw-date-actions-wrapper">
                        <div class="records-date-filter">
                            <label>
                                <span>Records</span>
                                <select name="date_scope" class="records-date-scope" aria-label="Select record period">
                                    <option value="all" <?php echo ($date_filter['scope'] ?? '') === 'all' ? 'selected' : ''; ?>>All Records</option>
                                    <option value="recent" <?php echo ($date_filter['scope'] ?? '') === 'recent' ? 'selected' : ''; ?>>Current Week</option>
                                    <option value="day" <?php echo ($date_filter['scope'] ?? '') === 'day' ? 'selected' : ''; ?>>Day</option>
                                    <option value="week" <?php echo ($date_filter['scope'] ?? '') === 'week' ? 'selected' : ''; ?>>Week</option>
                                    <option value="month" <?php echo ($date_filter['scope'] ?? '') === 'month' ? 'selected' : ''; ?>>Month</option>
                                    <option value="year" <?php echo ($date_filter['scope'] ?? '') === 'year' ? 'selected' : ''; ?>>Year</option>
                                    <option value="range" <?php echo ($date_filter['scope'] ?? '') === 'range' ? 'selected' : ''; ?>>Date Range</option>
                                </select>
                            </label>
                            <label data-date-input="day" <?php echo ($date_filter['scope'] ?? '') !== 'day' ? 'hidden' : ''; ?>>
                                <span>Day</span>
                                <input type="date" name="date_day" value="<?php echo esc_attr($date_filter['day'] ?? ''); ?>" <?php echo ($date_filter['scope'] ?? '') !== 'day' ? 'disabled' : ''; ?>>
                            </label>
                            <label data-date-input="week" <?php echo ($date_filter['scope'] ?? '') !== 'week' ? 'hidden' : ''; ?>>
                                <span>Week</span>
                                <input type="week" name="date_week" value="<?php echo esc_attr($date_filter['week'] ?? ''); ?>" <?php echo ($date_filter['scope'] ?? '') !== 'week' ? 'disabled' : ''; ?>>
                            </label>
                            <label data-date-input="month" <?php echo ($date_filter['scope'] ?? '') !== 'month' ? 'hidden' : ''; ?>>
                                <span>Month</span>
                                <input type="month" name="date_month" value="<?php echo esc_attr($date_filter['month'] ?? ''); ?>" <?php echo ($date_filter['scope'] ?? '') !== 'month' ? 'disabled' : ''; ?>>
                            </label>
                            <label data-date-input="year" <?php echo ($date_filter['scope'] ?? '') !== 'year' ? 'hidden' : ''; ?>>
                                <span>Year</span>
                                <input type="number" name="date_year" min="2020" max="2100" value="<?php echo (int) ($date_filter['year'] ?? date('Y')); ?>" <?php echo ($date_filter['scope'] ?? '') !== 'year' ? 'disabled' : ''; ?>>
                            </label>
                            <label data-date-input="range" <?php echo ($date_filter['scope'] ?? '') !== 'range' ? 'hidden' : ''; ?>>
                                <span>From</span>
                                <input type="date" name="date_from" value="<?php echo esc_attr($date_filter['from'] ?? ''); ?>" <?php echo ($date_filter['scope'] ?? '') !== 'range' ? 'disabled' : ''; ?>>
                            </label>
                            <label data-date-input="range" <?php echo ($date_filter['scope'] ?? '') !== 'range' ? 'hidden' : ''; ?>>
                                <span>To</span>
                                <input type="date" name="date_to" value="<?php echo esc_attr($date_filter['to'] ?? ''); ?>" <?php echo ($date_filter['scope'] ?? '') !== 'range' ? 'disabled' : ''; ?>>
                            </label>
                            <div class="hw-date-action-pair">
                                <button type="submit"
                                        class="hw-filter-icon-btn hw-btn-filter"
                                        title="Apply filters"
                                        aria-label="Apply filters">
                                    <i class="fas fa-filter"></i>
                                </button>
                                <a href="./#quotation-records"
                                   class="hw-filter-icon-btn hw-btn-reset"
                                   title="Reset filters"
                                   aria-label="Reset filters">
                                    <i class="fas fa-rotate-left"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
                <?php record_date_filter_script(); ?>

                <form method="GET" action="./#quotation-records" class="hw-search-cluster">
                    <?php if ($status_filter !== 'all'): ?>
                        <input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>">
                    <?php endif; ?>
                    <?php if ($record_filter !== 'active'): ?>
                        <input type="hidden" name="records" value="<?php echo esc_attr($record_filter); ?>">
                    <?php endif; ?>
                    <input type="hidden" name="branch" value="<?php echo esc_attr((string) $branch_query_value); ?>">
                    <?php record_date_filter_hidden_inputs(record_date_filter_query_params($date_filter)); ?>
                    <div class="hw-filter-group hw-group-search flex-grow-1">
                        <label for="frontQuoteSearch" class="hw-filter-label">Search</label>
                        <div class="hw-search-wrapper">
                            <input id="frontQuoteSearch"
                                   type="search"
                                   name="search"
                                   maxlength="100"
                                   data-text-format="first-letter"
                                   class="hw-search-input"
                                   value="<?php echo esc_attr($search_filter); ?>"
                                   placeholder="Search service operation, customer, plate...">
                        </div>
                    </div>
                    <div class="hw-filter-actions">
                        <button type="submit" class="btn btn-primary hw-filter-icon-btn hw-btn-search" title="Search" aria-label="Search">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="quotation-branch-note" role="status">
            <i class="fas fa-circle-info"></i>
            <?php if ($branch_filter !== ''): ?>
                <span>You are viewing <?php echo esc_html($selected_branch_label); ?> records. Use branch filter to view all branches.</span>
            <?php else: ?>
                <span>You are viewing service operation records across all branches. Actions remain limited to your assigned branch.</span>
            <?php endif; ?>
        </div>

        <?php if (empty($quotations)): ?>
            <div class="quotation-empty-state">No service operation records found.</div>
        <?php else: ?>
            <div class="quotation-card-list quotation-records-table" role="table" aria-label="Service operation records">
                <div class="quotation-records-table-head" role="row">
                    <span>Customer / Vehicle</span>
                    <span>Date / Branch</span>
                    <span>Services / Items</span>
                    <span>Status</span>
                    <span>Amount</span>
                    <span>Action</span>
                </div>
                <?php foreach ($quotations as $quotation): ?>
                    <?php
                    $quotation_id = (int) $quotation['id'];
                    $modal_id = 'quotationDetailsModal' . $quotation_id;
                    $items = $quotation_items[$quotation_id] ?? [];
                    $visible_items = array_slice($items, 0, 2);
                    $hidden_item_count = max(0, count($items) - count($visible_items));
                    $status_class = front_quote_status_class($quotation['status'] ?? 'pending');
                    $can_update = intval($quotation['branch_id'] ?? 0) === intval($user['branch_id'] ?? 0);
                    $can_manage_quotation = ($user['role'] ?? '') === 'front-desk' && $can_update;
                    $can_edit_quotation = $can_manage_quotation && in_array((string) ($quotation['status'] ?? ''), ['pending', 'approved'], true);
                    $can_delete = $can_manage_quotation && ($quotation['status'] ?? '') !== 'archived';
                    $can_resource = $can_manage_quotation && in_array((string) ($quotation['status'] ?? ''), ['pending', 'approved', 'draft'], true);
                    ?>
                    <article class="quotation-record-card quotation-record-row" role="row">
                        <div class="quotation-record-cell quotation-record-primary">
                            <strong><?php echo esc_html($quotation['customer_name'] ?? '-'); ?></strong>
                            <span><?php echo esc_html(front_quote_vehicle_name($quotation, true)); ?></span>
                            <small><?php echo esc_html($quotation['quotation_number'] ?? '-'); ?></small>
                        </div>
                        <div class="quotation-record-cell">
                            <strong><?php echo esc_html(front_quote_short_date($quotation['quotation_date'] ?? '')); ?></strong>
                            <span><?php echo esc_html(front_quote_branch_label($quotation['branch_name'] ?? '')); ?></span>
                        </div>
                        <div class="quotation-record-cell quotation-record-items">
                            <?php if (empty($items)): ?>
                                <span class="quotation-service-chip">No items listed</span>
                            <?php else: ?>
                                <?php foreach ($visible_items as $item): ?>
                                    <span class="quotation-service-chip">
                                        <?php echo esc_html(app_display_item_name($item['item_name'], $item['item_type'] ?? null)); ?>
                                        <?php echo ' (' . max(1, (int) ($item['quantity'] ?? 1)) . 'x)'; ?>
                                    </span>
                                <?php endforeach; ?>
                                <?php if ($hidden_item_count > 0): ?>
                                    <span class="quotation-service-chip quotation-more-chip">+<?php echo (int) $hidden_item_count; ?> more</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div class="quotation-record-cell">
                            <span class="quotation-status-pill status-<?php echo esc_attr($status_class); ?>">
                                <?php echo esc_html(ucfirst($quotation['status'] ?? 'Pending')); ?>
                            </span>
                        </div>
                        <div class="quotation-record-cell quotation-record-amount">
                            <strong><?php echo front_quote_money(front_quote_total_amount($quotation, $items)); ?></strong>
                        </div>
                        <div class="quotation-record-cell quotation-record-action">
                            <?php if (($quotation['status'] ?? '') === 'archived'): ?>
                                <form method="POST" action="/hwtires/api/quotations-api.php" style="display:inline-block; margin-right:4px;" onsubmit="return confirm('Restore this service operation to active status?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo esc_attr(generate_csrf_token()); ?>">
                                    <input type="hidden" name="action" value="restore">
                                    <input type="hidden" name="id" value="<?php echo $quotation_id; ?>">
                                    <input type="hidden" name="redirect" value="<?php echo esc_attr($current_url); ?>">
                                    <button type="submit" class="quotation-details-button" style="background:#e7f6ec; border-color:#8ce1a4; color:#1e7e34;" title="Restore / Unarchive record">
                                        <i class="fas fa-rotate-left"></i>
                                        <span>Restore</span>
                                    </button>
                                </form>
                            <?php endif; ?>
                            <button type="button" class="quotation-details-button" data-bs-toggle="modal" data-bs-target="#<?php echo esc_attr($modal_id); ?>">
                                <i class="fas fa-eye"></i>
                                <span>View</span>
                            </button>
                        </div>
                    </article>

                    <div class="modal fade quotation-details-modal" id="<?php echo esc_attr($modal_id); ?>" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <div>
                                        <h2>Service Operation Details</h2>
                                        <p>Service Operation ID: <?php echo esc_html($quotation['quotation_number'] ?? '-'); ?></p>
                                    </div>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>

                                <div class="modal-body">
                                    <section class="quotation-detail-summary">
                                        <div>
                                            <span>Customer</span>
                                            <strong><?php echo esc_html($quotation['customer_name'] ?? '-'); ?></strong>
                                            <p><?php echo esc_html(($quotation['phone_mobile'] ?? '') ?: (($quotation['contact'] ?? '') ?: '-')); ?></p>
                                        </div>
                                        <div>
                                            <span>Vehicle</span>
                                            <strong><?php echo esc_html(front_quote_vehicle_name($quotation)); ?></strong>
                                            <p><?php echo esc_html($quotation['plate_number'] ?? '-'); ?></p>
                                        </div>
                                    </section>

                                    <section class="quotation-detail-meta">
                                        <div>
                                            <span>Branch</span>
                                            <strong><?php echo esc_html(front_quote_branch_label($quotation['branch_name'] ?? '')); ?></strong>
                                        </div>
                                        <div>
                                            <span>Date</span>
                                            <strong><?php echo esc_html(front_quote_short_date($quotation['quotation_date'] ?? '')); ?></strong>
                                        </div>
                                    </section>

                                    <?php
                                    $has_inspection = trim((string) ($quotation['inspection_complaint'] ?? '')) !== ''
                                        || trim((string) ($quotation['inspection_findings'] ?? '')) !== ''
                                        || trim((string) ($quotation['inspection_recommendations'] ?? '')) !== ''
                                        || !empty($quotation['inspection_mileage']);
                                    ?>
                                    <?php if ($has_inspection): ?>
                                        <h3 class="quotation-detail-heading">Service Inspection</h3>
                                        <section class="quotation-inspection-record">
                                            <?php if (!empty($quotation['inspection_mileage'])): ?>
                                                <div>
                                                    <span>Current Mileage</span>
                                                    <strong><?php echo number_format((int) $quotation['inspection_mileage']); ?> km</strong>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (trim((string) ($quotation['inspection_complaint'] ?? '')) !== ''): ?>
                                                <div>
                                                    <span>Customer Concern</span>
                                                    <p><?php echo nl2br(esc_html($quotation['inspection_complaint'])); ?></p>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (trim((string) ($quotation['inspection_findings'] ?? '')) !== ''): ?>
                                                <div>
                                                    <span>Inspection Findings</span>
                                                    <p><?php echo nl2br(esc_html($quotation['inspection_findings'])); ?></p>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (trim((string) ($quotation['inspection_recommendations'] ?? '')) !== ''): ?>
                                                <div>
                                                    <span>Recommended Action</span>
                                                    <p><?php echo nl2br(esc_html($quotation['inspection_recommendations'])); ?></p>
                                                </div>
                                            <?php endif; ?>
                                        </section>
                                    <?php endif; ?>

                                    <h3 class="quotation-detail-heading">Services/Items</h3>
                                    <section class="quotation-detail-items">
                                        <?php if (empty($items)): ?>
                                            <article class="quotation-detail-item">
                                                <div>
                                                    <h4>No items listed</h4>
                                                    <p>Quantity: 0</p>
                                                </div>
                                                <div></div>
                                            </article>
                                        <?php else: ?>
                                            <?php foreach ($items as $item): ?>
                                                <?php
                                                $quantity = max(1, (int) ($item['quantity'] ?? 1));
                                                $unit_price = (float) ($item['unit_price'] ?? 0);
                                                $line_total = (float) (($item['subtotal'] ?? 0) ?: ($quantity * $unit_price));
                                                $is_service_line = ($item['item_type'] ?? '') === 'service';
                                                ?>
                                                <article class="quotation-detail-item">
                                                    <div>
                                                        <h4><?php echo esc_html(app_display_item_name($item['item_name'], $item['item_type'] ?? null)); ?></h4>
                                                        <p>Quantity: <?php echo $quantity; ?></p>
                                                        <?php
                                                        $iid = (int) $item['id'];
                                                        $is_other_branch = ($item['source'] ?? '') === 'other_branch';
                                                        $tr = ($is_other_branch && !$is_service_line) ? ($quotation_item_transfers[$iid] ?? null) : null;
                                                        ?>
                                                        <?php if ($tr): ?>
                                                            <?php
                                                            $tr_status = strtolower((string) ($tr['status'] ?? ''));
                                                            $donor_name = trim((string) ($tr['donor_branch_name'] ?? ''));
                                                            ?>
                                                            <?php if ($tr_status === 'cancelled'): ?>
                                                                <div class="mt-2">
                                                                    <span class="badge bg-danger text-white">
                                                                        <i class="fas fa-times-circle"></i> Transfer Cancelled &mdash; <?php echo esc_html($donor_name ?: 'Donor Branch'); ?>
                                                                    </span>
                                                                </div>
                                                                <?php if ($can_resource): ?>
                                                                    <div class="mt-2">
                                                                        <button type="button" class="btn btn-sm btn-outline-primary" data-quotation-edit-target="resourceModal<?php echo $iid; ?>">
                                                                            <i class="fas fa-exchange-alt"></i> Re-source Item
                                                                        </button>
                                                                    </div>
                                                                <?php endif; ?>
                                                            <?php elseif (in_array($tr_status, ['pending', 'approved', 'shipped', 'received'], true)): ?>
                                                                <div class="mt-2">
                                                                    <span class="badge bg-<?php echo in_array($tr_status, ['received', 'shipped', 'approved'], true) ? 'success' : 'info'; ?> text-white">
                                                                        <i class="fas fa-truck"></i> Transfer: <?php echo esc_html(ucfirst($tr_status)); ?><?php if ($donor_name): ?> (<?php echo esc_html($donor_name); ?>)<?php endif; ?>
                                                                    </span>
                                                                </div>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <span><?php echo front_quote_money($unit_price); ?> each</span>
                                                        <strong><?php echo $is_service_line ? 'Included in labor' : front_quote_money($line_total); ?></strong>
                                                    </div>
                                                </article>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </section>

                                    <section class="quotation-detail-costs">
                                        <?php if ((float) ($quotation['labor_cost'] ?? 0) > 0): ?>
                                            <div>
                                                <span>Labor Cost</span>
                                                <strong><?php echo front_quote_money($quotation['labor_cost']); ?></strong>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ((float) ($quotation['parts_cost'] ?? 0) > 0): ?>
                                            <div>
                                                <span>Parts Cost</span>
                                                <strong><?php echo front_quote_money($quotation['parts_cost']); ?></strong>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ((float) ($quotation['tires_cost'] ?? 0) > 0): ?>
                                            <div>
                                                <span>Tires Cost</span>
                                                <strong><?php echo front_quote_money($quotation['tires_cost']); ?></strong>
                                            </div>
                                        <?php endif; ?>
                                    </section>

                                    <section class="quotation-detail-total">
                                        <span>Total Amount</span>
                                        <strong><?php echo front_quote_money(front_quote_total_amount($quotation, $items)); ?></strong>
                                    </section>
                                </div>

                                <div class="modal-footer">
                                    <div class="quotation-modal-actions">
                                        <?php if ($can_update && ($quotation['status'] ?? '') === 'pending'): ?>
                                            <form method="POST" action="/hwtires/api/quotations-api.php" class="quotation-modal-action-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo esc_attr(generate_csrf_token()); ?>">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="id" value="<?php echo $quotation_id; ?>">
                                                <input type="hidden" name="status" value="approved">
                                                <input type="hidden" name="redirect" value="<?php echo esc_attr($current_url); ?>">
                                                <button type="submit" class="quotation-modal-approve">
                                                    <i class="fas fa-check"></i>
                                                    <span>Approve</span>
                                                </button>
                                            </form>
                                            <form method="POST" action="/hwtires/api/quotations-api.php" class="quotation-modal-action-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo esc_attr(generate_csrf_token()); ?>">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="id" value="<?php echo $quotation_id; ?>">
                                                <input type="hidden" name="status" value="rejected">
                                                <input type="hidden" name="redirect" value="<?php echo esc_attr($current_url); ?>">
                                                <button type="submit" class="quotation-modal-reject">
                                                    <i class="fas fa-xmark"></i>
                                                    <span>Reject</span>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <a class="quotation-modal-print" href="/hwtires/api/quotation-print.php?id=<?php echo $quotation_id; ?>" target="_blank" rel="noopener">
                                            <i class="fas fa-print"></i>
                                            <span>Print</span>
                                        </a>
                                        <?php if ($can_edit_quotation): ?>
                                            <button type="button" class="quotation-modal-edit" data-quotation-edit-target="editServicesModal<?php echo $quotation_id; ?>">
                                                <i class="fas fa-edit"></i>
                                                <span>Edit Services</span>
                                            </button>
                                        <?php endif; ?>
                                        <?php if (($quotation['status'] ?? '') === 'archived'): ?>
                                            <form method="POST" action="/hwtires/api/quotations-api.php" class="quotation-modal-action-form" onsubmit="return confirm('Restore this service operation to active status?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo esc_attr(generate_csrf_token()); ?>">
                                                <input type="hidden" name="action" value="restore">
                                                <input type="hidden" name="id" value="<?php echo $quotation_id; ?>">
                                                <input type="hidden" name="redirect" value="<?php echo esc_attr($current_url); ?>">
                                                <button type="submit" class="quotation-modal-approve" style="background:#0f766e; border-color:#0f766e; color:#ffffff;">
                                                    <i class="fas fa-rotate-left"></i>
                                                    <span>Restore / Unarchive</span>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($can_delete): ?>
                                            <form method="POST" action="/hwtires/api/quotations-api.php" class="quotation-modal-action-form" onsubmit="return confirm('Archive this service operation? The record will be hidden but kept in the database.');">
                                                <input type="hidden" name="csrf_token" value="<?php echo esc_attr(generate_csrf_token()); ?>">
                                                <input type="hidden" name="action" value="archive">
                                                <input type="hidden" name="id" value="<?php echo $quotation_id; ?>">
                                                <input type="hidden" name="redirect" value="<?php echo esc_attr($current_url); ?>">
                                                <button type="submit" class="quotation-modal-archive">
                                                    <i class="fas fa-box-archive"></i>
                                                    <span>Archive</span>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <button type="button" class="quotation-modal-close" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php if ($can_edit_quotation): ?>
                        <!-- Inline Edit Services Modal for this quotation -->
                        <div class="modal fade" id="editServicesModal<?php echo $quotation_id; ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered modal-lg">
                                <div class="modal-content">
                                    <form method="POST" action="/hwtires/admin/quotations/edit.php">
                                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                        <input type="hidden" name="quotation_id" value="<?php echo $quotation_id; ?>">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Edit Services for <?php echo esc_html($quotation['quotation_number']); ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <p>Select the service lines to keep for this service operation. Unchecked service lines will be removed.</p>
                                            <?php if (empty($items)): ?>
                                                <p class="text-muted">No items to edit.</p>
                                            <?php else: ?>
                                                <div class="list-group">
                                                    <?php foreach ($items as $item): ?>
                                                        <?php $iid = (int) $item['id']; $itype = ($item['item_type'] ?? 'item'); ?>
                                                        <div class="list-group-item">
                                                            <div class="row align-items-center">
                                                                <div class="col-5">
                                                                    <label class="form-check-label">
                                                                        <input type="checkbox" class="form-check-input me-2" name="keep_item_ids[]" value="<?php echo $iid; ?>" checked>
                                                                        <strong><?php echo esc_html(app_display_item_name($item['item_name'], $itype)); ?></strong>
                                                                        <small class="text-muted"> &mdash; <?php echo esc_html(ucfirst($itype)); ?></small>
                                                                    </label>
                                                                </div>
                                                                <div class="col-2">
                                                                    <label class="form-label small mb-1">Qty</label>
                                                                    <input type="number" min="1" class="form-control form-control-sm" name="quantity[<?php echo $iid; ?>]" value="<?php echo (int) $item['quantity']; ?>">
                                                                </div>
                                                                <div class="col-3">
                                                                    <label class="form-label small mb-1">Notes</label>
                                                                    <input type="text" class="form-control form-control-sm" name="notes[<?php echo $iid; ?>]" maxlength="255" data-text-format="first-letter" value="<?php echo esc_attr($item['notes'] ?? ''); ?>" placeholder="optional">
                                                                </div>
                                                                <div class="col-2 text-end">
                                                                    <small class="text-muted">Unit: <?php echo '&#8369;' . number_format((float) ($item['unit_price'] ?? 0), 2); ?></small>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-primary">Save Changes</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($can_resource): ?>
                        <?php foreach ($items as $item): ?>
                            <?php
                            $item_id = (int) $item['id'];
                            $tr = $quotation_item_transfers[$item_id] ?? null;
                            if (!$tr || ($tr['status'] ?? '') !== 'cancelled') {
                                continue;
                            }
                            $donors = $eligible_donors_by_item[$item_id] ?? [];
                            $cancelled_donor = trim((string) ($tr['donor_branch_name'] ?? 'Donor branch'));
                            ?>
                            <!-- Re-source Modal for Item #<?php echo $item_id; ?> -->
                            <div class="modal fade" id="resourceModal<?php echo $item_id; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <form method="POST" action="/hwtires/api/quotations-api.php" class="resource-transfer-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo esc_attr(generate_csrf_token()); ?>">
                                            <input type="hidden" name="action" value="resource_transfer_item">
                                            <input type="hidden" name="quotation_id" value="<?php echo $quotation_id; ?>">
                                            <input type="hidden" name="quotation_item_id" value="<?php echo $item_id; ?>">
                                            <input type="hidden" name="redirect" value="<?php echo esc_attr($current_url); ?>">
                                            <input type="hidden" name="new_donor_branch_id" id="donor_branch_id_<?php echo $item_id; ?>" value="">
                                            <input type="hidden" name="new_donor_item_id" id="donor_item_id_<?php echo $item_id; ?>" value="">

                                            <div class="modal-header">
                                                <div>
                                                    <h5 class="modal-title mb-0">
                                                        <i class="fas fa-exchange-alt text-primary me-2"></i>Re-source Item
                                                    </h5>
                                                    <small class="text-muted"><?php echo esc_html($quotation['quotation_number'] ?? ''); ?></small>
                                                </div>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="mb-3">
                                                    <label class="form-label text-muted small mb-1">Item to Re-source</label>
                                                    <p class="fw-bold mb-1"><?php echo esc_html($item['item_name']); ?></p>
                                                    <small class="text-muted">Quantity Required: <strong><?php echo max(1, (int) ($item['quantity'] ?? 1)); ?></strong></small>
                                                </div>
                                                <div class="alert alert-warning py-2 mb-3">
                                                    <small>
                                                        <i class="fas fa-info-circle me-1"></i>
                                                        The previous transfer request (<strong><?php echo esc_html($tr['request_number']); ?></strong>) from <strong><?php echo esc_html($cancelled_donor); ?></strong> was cancelled due to insufficient stock.
                                                    </small>
                                                </div>

                                                <?php if (empty($donors)): ?>
                                                    <div class="alert alert-danger py-2 mb-0">
                                                        <i class="fas fa-exclamation-triangle me-1"></i>
                                                        No other branch currently has sufficient stock (&ge; <?php echo max(1, (int) ($item['quantity'] ?? 1)); ?>) for this item.
                                                    </div>
                                                <?php else: ?>
                                                    <div class="mb-3">
                                                        <label for="donor_select_<?php echo $item_id; ?>" class="form-label fw-bold">Select Alternative Donor Branch <span class="text-danger">*</span></label>
                                                        <select class="form-select resource-donor-select" id="donor_select_<?php echo $item_id; ?>" data-line-id="<?php echo $item_id; ?>" required>
                                                            <option value="">-- Select Donor Branch --</option>
                                                            <?php foreach ($donors as $donor): ?>
                                                                <option value="<?php echo (int) $donor['branch_id']; ?>" data-inventory-item-id="<?php echo (int) $donor['inventory_item_id']; ?>">
                                                                    <?php echo esc_html($donor['branch_name']); ?> (<?php echo (int) $donor['quantity']; ?> in stock)
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <div class="form-text">
                                                            Selecting an alternative donor branch will submit a new transfer request while preserving the cancelled request in the audit history.
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <?php if (!empty($donors)): ?>
                                                    <button type="submit" class="btn btn-primary" id="btn_submit_resource_<?php echo $item_id; ?>">
                                                        <i class="fas fa-paper-plane me-1"></i> Submit Re-source Request
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <div class="quotation-records-count">
        Showing <?php echo count($quotations); ?> of <?php echo (int) $total_records; ?> service operations
    </div>

    <?php if ($total_pages > 1): ?>
        <nav class="quotation-records-pagination" aria-label="Service operation pages">
            <ul class="pagination justify-content-center">
                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?><?php echo $pagination_params; ?>#quotation-records"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-quotation-edit-target]').forEach(function(button) {
        button.addEventListener('click', function() {
            const targetId = button.dataset.quotationEditTarget || '';
            const targetModal = targetId ? document.getElementById(targetId) : null;

            if (!targetModal || !window.bootstrap) {
                return;
            }

            const currentModalEl = button.closest('.modal');
            const showEditModal = function() {
                bootstrap.Modal.getOrCreateInstance(targetModal).show();
            };

            if (currentModalEl) {
                const currentModal = bootstrap.Modal.getInstance(currentModalEl);
                if (currentModal) {
                    currentModalEl.addEventListener('hidden.bs.modal', showEditModal, { once: true });
                    currentModal.hide();
                    return;
                }
            }

            showEditModal();
        });
    });

    document.querySelectorAll('.resource-donor-select').forEach(function(selectEl) {
        selectEl.addEventListener('change', function() {
            var lineId = this.dataset.lineId;
            var selectedOption = this.options[this.selectedIndex];
            var branchIdInput = document.getElementById('donor_branch_id_' + lineId);
            var itemIdInput = document.getElementById('donor_item_id_' + lineId);

            if (branchIdInput && itemIdInput) {
                branchIdInput.value = selectedOption ? (selectedOption.value || '') : '';
                itemIdInput.value = selectedOption ? (selectedOption.dataset.inventoryItemId || '') : '';
            }
        });
    });

    document.querySelectorAll('.resource-transfer-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            var lineIdInput = this.querySelector('input[name="quotation_item_id"]');
            var lineId = lineIdInput ? lineIdInput.value : '';
            var branchIdInput = document.getElementById('donor_branch_id_' + lineId);
            var itemIdInput = document.getElementById('donor_item_id_' + lineId);

            if (!branchIdInput || !branchIdInput.value || !itemIdInput || !itemIdInput.value) {
                e.preventDefault();
                alert('Please select an alternative donor branch.');
            }
        });
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>
