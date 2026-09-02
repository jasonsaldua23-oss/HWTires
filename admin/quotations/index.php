<?php
/**
 * Quotations List / Records
 */

require_once '../../includes/config.php';
require_once '../../includes/record-filters.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$page_title = 'Service Operations';
$user = app_get_session_user();

if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    set_flash_message('Admin is view-only for service operation records.', 'warning');
    redirect($_SERVER['HTTP_REFERER']);
}

if (!function_exists('quotation_branch_label')) {
    function quotation_branch_label($name) {
        return app_branch_label($name, '-');
    }
}

if (!function_exists('quotation_vehicle_name')) {
    function quotation_vehicle_name($record, $include_plate = false) {
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

if (!function_exists('quotation_money')) {
    function quotation_money($amount, $decimals = 0) {
        return '&#8369;' . number_format((float) $amount, $decimals);
    }
}

if (!function_exists('quotation_short_date')) {
    function quotation_short_date($date) {
        return !empty($date) ? date('Y-m-d', strtotime($date)) : '-';
    }
}

if (!function_exists('quotation_status_class')) {
    function quotation_status_class($status) {
        $status = strtolower((string) $status);
        return in_array($status, ['pending', 'approved', 'rejected', 'archived'], true) ? $status : 'pending';
    }
}

if (!function_exists('quotation_total_amount')) {
    function quotation_total_amount($quotation, $items) {
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

if (!function_exists('quotation_filter_url')) {
    function quotation_filter_url($status, $branch, $search = '', $page = null, $date_filter = null, $record_filter = 'active') {
        $query = [];

        if ($page !== null && (int) $page > 1) {
            $query['page'] = (int) $page;
        }

        if ($status !== 'all') {
            $query['status'] = $status;
        }

        if ($branch !== '') {
            $query['branch'] = (int) $branch;
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
$branch_filter = $_GET['branch'] ?? '';
$branch_filter = $branch_filter !== '' ? intval($branch_filter) : '';
if ($branch_filter !== '' && $branch_filter <= 0) {
    $branch_filter = '';
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
$quote_activity_expr = record_activity_datetime_expr('q.quotation_date', 'q.created_at', 'q.updated_at');
$quote_record_date_expr = record_business_datetime_expr('q.quotation_date', 'q.created_at');

$branches = $pdo->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name ASC")->fetchAll();

$where = ['1=1'];
$params = [];
if ($record_filter === 'active') {
    $where[] = "q.status <> 'archived'";
} elseif ($record_filter === 'archived') {
    $where[] = "q.status = 'archived'";
}

if ($status_filter !== 'all') {
    $where[] = 'q.status = ?';
    $params[] = $status_filter;
}

if ($branch_filter !== '') {
    $where[] = 'q.branch_id = ?';
    $params[] = $branch_filter;
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

$count_query = "SELECT COUNT(*) AS total $from_sql WHERE $where_sql";
$stmt = $pdo->prepare($count_query);
$stmt->execute($params);
$total_records = (int) $stmt->fetch()['total'];
$total_pages = ceil($total_records / RECORDS_PER_PAGE);

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
        $quotation_items[intval($item['quotation_id'])][] = $item;
    }
}

$pagination_params = '';
if ($status_filter !== 'all') {
    $pagination_params .= '&status=' . urlencode($status_filter);
}
if ($record_filter !== 'active') {
    $pagination_params .= '&records=' . urlencode($record_filter);
}
if ($branch_filter !== '') {
    $pagination_params .= '&branch=' . urlencode((string) $branch_filter);
}
if ($search_filter !== '') {
    $pagination_params .= '&search=' . urlencode($search_filter);
}
$pagination_params .= record_date_filter_query_string($date_filter);
?>

<?php require_once '../../includes/header.php'; ?>
<?php require_once '../../includes/sidebar.php'; ?>

<div class="quotation-records-page">
    <section class="quotation-records-hero">
        <h1>Service Operations</h1>
        <p>View all service operation records</p>
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
            <div class="quotation-panel-controls">
                <form method="GET" action="./#quotation-records" class="quotation-branch-filter records-select-filter">
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
                    <select name="branch" onchange="this.form.submit()" aria-label="Filter quotations by branch">
                        <option value="">All Branches</option>
                        <?php foreach ($branches as $branch): ?>
                            <?php $branch_label = quotation_branch_label($branch['name']); ?>
                            <option value="<?php echo (int) $branch['id']; ?>" <?php echo $branch_filter === (int) $branch['id'] ? 'selected' : ''; ?>>
                                <?php echo esc_html($branch_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">Apply</button>
                </form>
                <form method="GET" action="./#quotation-records" class="quotation-branch-filter records-select-filter">
                    <?php if ($status_filter !== 'all'): ?>
                        <input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>">
                    <?php endif; ?>
                    <?php if ($branch_filter !== ''): ?>
                        <input type="hidden" name="branch" value="<?php echo esc_attr((string) $branch_filter); ?>">
                    <?php endif; ?>
                    <?php if ($search_filter !== ''): ?>
                        <input type="hidden" name="search" value="<?php echo esc_attr($search_filter); ?>">
                    <?php endif; ?>
                    <?php record_date_filter_hidden_inputs(record_date_filter_query_params($date_filter)); ?>
                    <select name="records" onchange="this.form.submit()" aria-label="Filter service operations by record state">
                        <?php foreach (record_archive_filter_options() as $record_value => $record_label): ?>
                            <option value="<?php echo esc_attr($record_value); ?>" <?php echo $record_filter === $record_value ? 'selected' : ''; ?>>
                                <?php echo esc_html($record_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">Apply</button>
                </form>
                <form method="GET" action="./#quotation-records" class="quotation-branch-filter records-select-filter">
                    <?php if ($record_filter !== 'active'): ?>
                        <input type="hidden" name="records" value="<?php echo esc_attr($record_filter); ?>">
                    <?php endif; ?>
                    <?php if ($branch_filter !== ''): ?>
                        <input type="hidden" name="branch" value="<?php echo esc_attr((string) $branch_filter); ?>">
                    <?php endif; ?>
                    <?php if ($search_filter !== ''): ?>
                        <input type="hidden" name="search" value="<?php echo esc_attr($search_filter); ?>">
                    <?php endif; ?>
                    <?php record_date_filter_hidden_inputs(record_date_filter_query_params($date_filter)); ?>
                    <select name="status" onchange="this.form.submit()" aria-label="Filter service operations by status">
                        <?php foreach ($allowed_statuses as $status): ?>
                        <?php $label = $status === 'all' ? 'All' : ucfirst($status); ?>
                            <option value="<?php echo esc_attr($status); ?>" <?php echo $status_filter === $status ? 'selected' : ''; ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">Apply</button>
                </form>
                <?php
                record_date_filter_controls($date_filter, [
                    'status' => $status_filter !== 'all' ? $status_filter : '',
                    'branch' => $branch_filter !== '' ? $branch_filter : '',
                    'search' => $search_filter,
                    'records' => $record_filter !== 'active' ? $record_filter : '',
                ], 'quotation-records');
                ?>
                <form method="GET" action="./#quotation-records" class="records-search-form">
                    <?php if ($status_filter !== 'all'): ?>
                        <input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>">
                    <?php endif; ?>
                    <?php if ($branch_filter !== ''): ?>
                        <input type="hidden" name="branch" value="<?php echo esc_attr((string) $branch_filter); ?>">
                    <?php endif; ?>
                    <?php if ($record_filter !== 'active'): ?>
                        <input type="hidden" name="records" value="<?php echo esc_attr($record_filter); ?>">
                    <?php endif; ?>
                    <?php record_date_filter_hidden_inputs(record_date_filter_query_params($date_filter)); ?>
                    <label class="records-search-field">
                        <i class="fas fa-search"></i>
                        <input type="search"
                               name="search"
                               value="<?php echo esc_attr($search_filter); ?>"
                               placeholder="Search service operation, customer, plate...">
                    </label>
                    <button type="submit" class="records-search-btn">Search</button>
                    <?php if ($search_filter !== ''): ?>
                        <a class="records-search-clear"
                           href="<?php echo esc_attr(quotation_filter_url($status_filter, $branch_filter, '', null, $date_filter, $record_filter)); ?>#quotation-records">
                            Clear
                        </a>
                    <?php endif; ?>
                </form>
            </div>
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
                    $status_class = quotation_status_class($quotation['status'] ?? 'pending');
                    ?>
                    <article class="quotation-record-card quotation-record-row" role="row">
                        <div class="quotation-record-cell quotation-record-primary">
                            <strong><?php echo esc_html($quotation['customer_name'] ?? '-'); ?></strong>
                            <span><?php echo esc_html(quotation_vehicle_name($quotation, true)); ?></span>
                            <small><?php echo esc_html($quotation['quotation_number'] ?? '-'); ?></small>
                        </div>
                        <div class="quotation-record-cell">
                            <strong><?php echo esc_html(quotation_short_date($quotation['quotation_date'] ?? '')); ?></strong>
                            <span><?php echo esc_html(quotation_branch_label($quotation['branch_name'] ?? '')); ?></span>
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
                            <strong><?php echo quotation_money(quotation_total_amount($quotation, $items)); ?></strong>
                        </div>
                        <div class="quotation-record-cell quotation-record-action">
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
                                            <strong><?php echo esc_html(quotation_vehicle_name($quotation)); ?></strong>
                                            <p><?php echo esc_html($quotation['plate_number'] ?? '-'); ?></p>
                                        </div>
                                    </section>

                                    <section class="quotation-detail-meta">
                                        <div>
                                            <span>Branch</span>
                                            <strong><?php echo esc_html(quotation_branch_label($quotation['branch_name'] ?? '')); ?></strong>
                                        </div>
                                        <div>
                                            <span>Date</span>
                                            <strong><?php echo esc_html(quotation_short_date($quotation['quotation_date'] ?? '')); ?></strong>
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
                                                $quantity = (int) ($item['quantity'] ?? 1);
                                                $unit_price = (float) ($item['unit_price'] ?? 0);
                                                $line_total = (float) (($item['subtotal'] ?? 0) ?: ($quantity * $unit_price));
                                                $is_service_line = ($item['item_type'] ?? '') === 'service';
                                                ?>
                                                <article class="quotation-detail-item">
                                                    <div>
                                                        <h4><?php echo esc_html(app_display_item_name($item['item_name'], $item['item_type'] ?? null)); ?></h4>
                                                        <p>Quantity: <?php echo $quantity; ?></p>
                                                    </div>
                                                    <div>
                                                        <span><?php echo quotation_money($unit_price); ?> each</span>
                                                        <strong><?php echo $is_service_line ? 'Included in labor' : quotation_money($line_total); ?></strong>
                                                    </div>
                                                </article>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </section>

                                    <section class="quotation-detail-costs">
                                        <?php if ((float) ($quotation['labor_cost'] ?? 0) > 0): ?>
                                            <div>
                                                <span>Labor Cost</span>
                                                <strong><?php echo quotation_money($quotation['labor_cost']); ?></strong>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ((float) ($quotation['parts_cost'] ?? 0) > 0): ?>
                                            <div>
                                                <span>Parts Cost</span>
                                                <strong><?php echo quotation_money($quotation['parts_cost']); ?></strong>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ((float) ($quotation['tires_cost'] ?? 0) > 0): ?>
                                            <div>
                                                <span>Tires Cost</span>
                                                <strong><?php echo quotation_money($quotation['tires_cost']); ?></strong>
                                            </div>
                                        <?php endif; ?>
                                    </section>

                                    <section class="quotation-detail-total">
                                        <span>Total Amount</span>
                                        <strong><?php echo quotation_money(quotation_total_amount($quotation, $items)); ?></strong>
                                    </section>
                                </div>

                                <div class="modal-footer">
                                    <div class="quotation-modal-actions">
                                        <a class="quotation-modal-print" href="/hwtires/api/quotation-print.php?id=<?php echo $quotation_id; ?>" target="_blank" rel="noopener">
                                            <i class="fas fa-print"></i>
                                            <span>Print</span>
                                        </a>
                                        <button type="button" class="quotation-modal-close" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
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
            <?php if ($page > 1): ?>
            <li class="page-item">
                <a class="page-link" href="?page=1<?php echo $pagination_params; ?>#quotation-records">First</a>
            </li>
            <li class="page-item">
                <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo $pagination_params; ?>#quotation-records">Previous</a>
            </li>
            <?php endif; ?>

            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                <a class="page-link" href="?page=<?php echo $i; ?><?php echo $pagination_params; ?>#quotation-records"><?php echo $i; ?></a>
            </li>
            <?php endfor; ?>

            <?php if ($page < $total_pages): ?>
            <li class="page-item">
                <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo $pagination_params; ?>#quotation-records">Next</a>
            </li>
            <li class="page-item">
                <a class="page-link" href="?page=<?php echo $total_pages; ?><?php echo $pagination_params; ?>#quotation-records">Last</a>
            </li>
            <?php endif; ?>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<?php require_once '../../includes/footer.php'; ?>
