<?php
/**
 * Inventory Transaction History
 */

require_once '../../includes/config.php';
require_once '../../includes/record-filters.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$page_title = 'Inventory Transactions';
$user = app_get_session_user();

$valid_types = ['all', 'stock_in', 'stock_out', 'adjustment'];
$transaction_type = strtolower(trim($_GET['type'] ?? 'all'));
if (!in_array($transaction_type, $valid_types, true)) {
    $transaction_type = 'all';
}

$search = trim($_GET['search'] ?? '');
$date_filter = record_date_filter_current('all');

$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * RECORDS_PER_PAGE;
$branch_filter = $user['role'] === 'admin' ? trim($_GET['branch'] ?? '') : (string) ($user['branch_id'] ?? '');

$where = ["LOWER(REPLACE(t.transaction_type, ' ', '_')) IN ('stock_in', 'stock_out', 'adjustment')"];
$params = [];

if ($user['role'] !== 'admin') {
    $where[] = 'i.branch_id = ?';
    $params[] = (int) ($user['branch_id'] ?? 0);
} elseif ($branch_filter !== '') {
    $where[] = 'i.branch_id = ?';
    $params[] = (int) $branch_filter;
}

if ($transaction_type !== 'all') {
    $where[] = "LOWER(REPLACE(t.transaction_type, ' ', '_')) = ?";
    $params[] = $transaction_type;
}

$date_condition = record_date_filter_condition('t.created_at', $date_filter, $params);
if ($date_condition !== '') {
    $where[] = $date_condition;
}

if ($search !== '') {
    foreach (app_search_terms($search) as $term) {
        $where[] = "(
            i.item_name LIKE ?
            OR i.brand LIKE ?
            OR i.size LIKE ?
            OR i.sku LIKE ?
            OR i.category LIKE ?
            OR t.notes LIKE ?
            OR u.name LIKE ?
            OR t.reference_type LIKE ?
            OR tagged_customer.name LIKE ?
            OR tagged_customer.phone_mobile LIKE ?
            OR tagged_vehicle.plate_number LIKE ?
            OR tagged_vehicle.make LIKE ?
            OR tagged_vehicle.model LIKE ?
            OR tagged_quotation.quotation_number LIKE ?
            OR tagged_job.job_number LIKE ?
        )";
        $search_param = '%' . $term . '%';
        $params = array_merge($params, array_fill(0, 15, $search_param));
    }
}

$where_sql = implode(' AND ', $where);

$count_query = "
    SELECT COUNT(*) AS total
    FROM inventory_transactions t
    INNER JOIN inventory_items i ON i.id = t.item_id
    LEFT JOIN users u ON u.id = t.created_by
    LEFT JOIN customers tagged_customer ON tagged_customer.id = t.customer_id
    LEFT JOIN vehicles tagged_vehicle ON tagged_vehicle.id = t.vehicle_id
    LEFT JOIN job_orders tagged_job ON tagged_job.id = t.job_order_id
    LEFT JOIN quotations tagged_quotation ON tagged_quotation.id = t.quotation_id
    WHERE $where_sql
";
$stmt = $pdo->prepare($count_query);
$stmt->execute($params);
$total_records = (int) ($stmt->fetch()['total'] ?? 0);
$total_pages = max(1, (int) ceil($total_records / RECORDS_PER_PAGE));
$page = min($page, $total_pages);
$offset = ($page - 1) * RECORDS_PER_PAGE;
$showing_from = $total_records > 0 ? $offset + 1 : 0;
$showing_to = min($offset + RECORDS_PER_PAGE, $total_records);

$query = "
    SELECT
        t.*,
        i.item_name,
        i.category,
        i.brand,
        i.size,
        i.sku,
        i.branch_id,
        i.supplier_name,
        u.name AS user_name,
        b.name AS branch_name,
        tagged_customer.name AS tagged_customer_name,
        tagged_customer.phone_mobile AS tagged_customer_phone,
        tagged_vehicle.plate_number AS tagged_plate_number,
        tagged_vehicle.make AS tagged_vehicle_make,
        tagged_vehicle.model AS tagged_vehicle_model,
        tagged_vehicle.year AS tagged_vehicle_year,
        tagged_job.job_number AS tagged_job_number,
        tagged_quotation.quotation_number AS tagged_quotation_number
    FROM inventory_transactions t
    INNER JOIN inventory_items i ON i.id = t.item_id
    LEFT JOIN users u ON u.id = t.created_by
    LEFT JOIN branches b ON b.id = i.branch_id
    LEFT JOIN customers tagged_customer ON tagged_customer.id = t.customer_id
    LEFT JOIN vehicles tagged_vehicle ON tagged_vehicle.id = t.vehicle_id
    LEFT JOIN job_orders tagged_job ON tagged_job.id = t.job_order_id
    LEFT JOIN quotations tagged_quotation ON tagged_quotation.id = t.quotation_id
    WHERE $where_sql
    ORDER BY t.created_at DESC, t.id DESC
    LIMIT " . RECORDS_PER_PAGE . " OFFSET $offset
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

$branches = [];
if ($user['role'] === 'admin') {
    $branch_stmt = $pdo->query("SELECT id, name FROM branches WHERE status = 'active' AND has_inventory = 1 ORDER BY id");
    $branches = $branch_stmt->fetchAll();
}

$summary_query = "
    SELECT
        COUNT(*) AS total_transactions,
        SUM(CASE WHEN LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_in' THEN t.quantity ELSE 0 END) AS total_in,
        SUM(CASE WHEN LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out' THEN t.quantity ELSE 0 END) AS total_out
    FROM inventory_transactions t
    INNER JOIN inventory_items i ON i.id = t.item_id
    LEFT JOIN users u ON u.id = t.created_by
    LEFT JOIN customers tagged_customer ON tagged_customer.id = t.customer_id
    LEFT JOIN vehicles tagged_vehicle ON tagged_vehicle.id = t.vehicle_id
    LEFT JOIN job_orders tagged_job ON tagged_job.id = t.job_order_id
    LEFT JOIN quotations tagged_quotation ON tagged_quotation.id = t.quotation_id
    WHERE $where_sql
";
$summary_stmt = $pdo->prepare($summary_query);
$summary_stmt->execute($params);
$summary = $summary_stmt->fetch() ?: ['total_transactions' => 0, 'total_in' => 0, 'total_out' => 0];

function inventory_transaction_url($page, $search, $transaction_type, $branch_filter, $date_filter) {
    $query = ['page' => max(1, (int) $page)];

    if ($search !== '') {
        $query['search'] = $search;
    }

    if ($transaction_type !== 'all') {
        $query['type'] = $transaction_type;
    }

    if ($branch_filter !== '') {
        $query['branch'] = $branch_filter;
    }

    $date_params = record_date_filter_query_params($date_filter);
    $query = array_merge($query, $date_params);

    return '?' . http_build_query($query);
}

if (!function_exists('inventory_transaction_type_class')) {
    function inventory_transaction_type_class($type) {
        return 'type-' . str_replace('_', '-', strtolower((string) $type));
    }
}

if (!function_exists('inventory_transaction_type_label')) {
    function inventory_transaction_type_label($type) {
        return ucwords(str_replace('_', ' ', (string) $type));
    }
}

if (!function_exists('inventory_transaction_vehicle_label')) {
    function inventory_transaction_vehicle_label(array $transaction) {
        $plate = trim((string) ($transaction['tagged_plate_number'] ?? ''));
        $details = trim((string) ($transaction['tagged_vehicle_make'] ?? '') . ' ' . (string) ($transaction['tagged_vehicle_model'] ?? ''));

        if (!empty($transaction['tagged_vehicle_year'])) {
            $details = trim((string) $transaction['tagged_vehicle_year'] . ' ' . $details);
        }

        if ($plate !== '' && $details !== '') {
            return $plate . ' - ' . $details;
        }

        return $plate !== '' ? $plate : $details;
    }
}

if (!function_exists('inventory_transaction_direct_sale_vehicle_label')) {
    function inventory_transaction_direct_sale_vehicle_label(array $transaction) {
        $plate = trim((string) ($transaction['tagged_plate_number'] ?? ''));
        $details = trim((string) ($transaction['tagged_vehicle_make'] ?? '') . ' ' . (string) ($transaction['tagged_vehicle_model'] ?? ''));

        if ($details !== '' && $plate !== '') {
            return $details . ' • ' . $plate;
        }

        return $details !== '' ? $details : $plate;
    }
}
?>

<?php require_once '../../includes/header.php'; ?>
<?php require_once '../../includes/sidebar.php'; ?>

<main class="inventory-records-page inventory-transactions-page">
    <header class="inventory-hero">
        <div>
            <h1>Inventory Transactions</h1>
            <p>Stock movement history by date, branch, and year</p>
        </div>
        <div class="inventory-hero-actions">
            <a href="/hwtires/admin/tire-inventory/" class="inventory-history-btn">
                <i class="fas fa-arrow-left"></i>
                <span>Back to Inventory</span>
            </a>
        </div>
    </header>

    <?php
    $flash_message = get_flash_message();
    if ($flash_message):
        $flash_type = $flash_message['type'] === 'error' ? 'danger' : $flash_message['type'];
    ?>
        <div class="alert alert-<?php echo esc_attr($flash_type); ?> alert-dismissible fade show inventory-flash" role="alert">
            <?php echo esc_html($flash_message['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <section class="inventory-summary-grid" aria-label="Inventory transaction summary">
        <article class="inventory-summary-card">
            <div>
                <span>Transactions</span>
                <strong><?php echo (int) $summary['total_transactions']; ?></strong>
            </div>
            <span class="inventory-summary-icon icon-cyan"><i class="fas fa-right-left"></i></span>
        </article>
        <article class="inventory-summary-card">
            <div>
                <span>Stock In</span>
                <strong class="inventory-summary-positive"><?php echo (int) $summary['total_in']; ?></strong>
            </div>
            <span class="inventory-summary-icon icon-green"><i class="fas fa-arrow-trend-up"></i></span>
        </article>
        <article class="inventory-summary-card">
            <div>
                <span>Stock Out</span>
                <strong class="inventory-summary-warning"><?php echo (int) $summary['total_out']; ?></strong>
            </div>
            <span class="inventory-summary-icon icon-red"><i class="fas fa-arrow-trend-down"></i></span>
        </article>
    </section>

    <section class="inventory-filter-card hw-filter-card">
        <form method="GET" action="./transactions.php" class="hw-filter-toolbar" data-record-date-filter>
            <div class="hw-filter-cluster">
                <div class="hw-filter-group hw-group-type">
                    <label for="adminTxType" class="hw-filter-label">Type</label>
                    <select id="adminTxType" name="type" class="hw-filter-select">
                        <option value="all">All Types</option>
                        <option value="stock_in" <?php echo $transaction_type === 'stock_in' ? 'selected' : ''; ?>>Stock In</option>
                        <option value="stock_out" <?php echo $transaction_type === 'stock_out' ? 'selected' : ''; ?>>Stock Out</option>
                        <option value="adjustment" <?php echo $transaction_type === 'adjustment' ? 'selected' : ''; ?>>Adjustment</option>
                    </select>
                </div>
                <div class="hw-filter-group hw-group-branch">
                    <label for="adminTxBranch" class="hw-filter-label">Branch</label>
                    <select id="adminTxBranch" name="branch" class="hw-filter-select">
                        <option value="">All Branches</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?php echo (int) $branch['id']; ?>" <?php echo (string) $branch_filter === (string) $branch['id'] ? 'selected' : ''; ?>>
                                <?php echo esc_html($branch['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="hw-filter-group hw-group-date">
                    <label for="adminTxDateScope" class="hw-filter-label">Records</label>
                    <select id="adminTxDateScope" name="date_scope" class="records-date-scope hw-filter-select" aria-label="Select record period">
                        <option value="all" <?php echo ($date_filter['scope'] ?? 'all') === 'all' ? 'selected' : ''; ?>>All Records</option>
                        <option value="recent" <?php echo ($date_filter['scope'] ?? '') === 'recent' ? 'selected' : ''; ?>>Current Week</option>
                        <option value="day" <?php echo ($date_filter['scope'] ?? '') === 'day' ? 'selected' : ''; ?>>Day</option>
                        <option value="week" <?php echo ($date_filter['scope'] ?? '') === 'week' ? 'selected' : ''; ?>>Week</option>
                        <option value="month" <?php echo ($date_filter['scope'] ?? '') === 'month' ? 'selected' : ''; ?>>Month</option>
                        <option value="year" <?php echo ($date_filter['scope'] ?? '') === 'year' ? 'selected' : ''; ?>>Year</option>
                        <option value="range" <?php echo ($date_filter['scope'] ?? '') === 'range' ? 'selected' : ''; ?>>Date Range</option>
                    </select>
                </div>
                <div class="hw-filter-group" data-date-input="day">
                    <label for="adminTxDateDay" class="hw-filter-label">Day</label>
                    <input id="adminTxDateDay" type="date" name="date_day" class="hw-filter-input" value="<?php echo esc_attr($date_filter['day']); ?>">
                </div>
                <div class="hw-filter-group" data-date-input="week">
                    <label for="adminTxDateWeek" class="hw-filter-label">Week</label>
                    <input id="adminTxDateWeek" type="week" name="date_week" class="hw-filter-input" value="<?php echo esc_attr($date_filter['week']); ?>">
                </div>
                <div class="hw-filter-group" data-date-input="month">
                    <label for="adminTxDateMonth" class="hw-filter-label">Month</label>
                    <input id="adminTxDateMonth" type="month" name="date_month" class="hw-filter-input" value="<?php echo esc_attr($date_filter['month']); ?>">
                </div>
                <div class="hw-filter-group" data-date-input="year">
                    <label for="adminTxDateYear" class="hw-filter-label">Year</label>
                    <input id="adminTxDateYear" type="number" name="date_year" min="2020" max="2100" class="hw-filter-input" value="<?php echo (int) $date_filter['year']; ?>">
                </div>
                <div class="hw-filter-group" data-date-input="range">
                    <label for="adminTxDateFrom" class="hw-filter-label">From</label>
                    <input id="adminTxDateFrom" type="date" name="date_from" class="hw-filter-input" value="<?php echo esc_attr($date_filter['from']); ?>">
                </div>
                <div class="hw-filter-group" data-date-input="range">
                    <label for="adminTxDateTo" class="hw-filter-label">To</label>
                    <input id="adminTxDateTo" type="date" name="date_to" class="hw-filter-input" value="<?php echo esc_attr($date_filter['to']); ?>">
                </div>
                <div class="hw-filter-actions">
                    <button type="submit" class="btn btn-primary hw-filter-icon-btn hw-btn-filter" title="Apply filters" aria-label="Apply filters">
                        <i class="fas fa-filter"></i>
                    </button>
                    <a href="./transactions.php" class="btn btn-outline-secondary hw-filter-icon-btn hw-btn-reset" title="Reset filters" aria-label="Reset filters">
                        <i class="fas fa-rotate-left"></i>
                    </a>
                </div>
            </div>
            <div class="hw-search-cluster">
                <div class="hw-filter-group hw-group-search flex-grow-1">
                    <label for="adminTxSearch" class="hw-filter-label">Search</label>
                    <div class="hw-search-wrapper">
                        <input id="adminTxSearch" type="search" name="search" maxlength="100" data-text-format="first-letter" class="hw-search-input" placeholder="Search item, brand, size, notes..." value="<?php echo esc_attr($search); ?>">
                    </div>
                </div>
                <div class="hw-filter-actions">
                    <button type="submit" class="btn btn-primary hw-filter-icon-btn hw-btn-search" title="Search" aria-label="Search">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
        </form>
    </section>
    <?php record_date_filter_script(); ?>

    <section class="inventory-table-card" aria-label="Inventory transactions">
        <div class="records-table-toolbar inventory-table-toolbar">
            <div>
                <h2>Inventory Transactions</h2>
                <p>Showing <?php echo (int) $showing_from; ?>-<?php echo (int) $showing_to; ?> of <?php echo (int) $total_records; ?> transactions</p>
            </div>
        </div>
        <div class="table-responsive">
            <table class="inventory-table inventory-transactions-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Branch</th>
                        <th>Item</th>
                        <th>Type</th>
                        <th>Qty</th>
                        <th>Tagged To / Source</th>
                        <th>Entered By</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="8" class="inventory-table-empty">No transactions found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $transaction): ?>
                            <?php
                            $tagged_customer_name = trim((string) ($transaction['tagged_customer_name'] ?? ''));
                            $tagged_phone = trim((string) ($transaction['tagged_customer_phone'] ?? ''));
                            $tagged_customer = $tagged_customer_name;
                            if ($tagged_phone !== '') {
                                $tagged_customer = $tagged_customer !== '' ? $tagged_customer . ' (' . $tagged_phone . ')' : $tagged_phone;
                            }

                            $tagged_vehicle = inventory_transaction_vehicle_label($transaction);
                            $direct_sale_vehicle = inventory_transaction_direct_sale_vehicle_label($transaction);
                            $tagged_refs = [];
                            if (!empty($transaction['tagged_job_number'])) {
                                $tagged_refs[] = $transaction['tagged_job_number'];
                            }
                            if (!empty($transaction['tagged_quotation_number'])) {
                                $tagged_refs[] = $transaction['tagged_quotation_number'];
                            }
                            $source_display_label = app_inventory_transaction_source_display($transaction);
                            $ref_type = strtolower(trim((string) ($transaction['reference_type'] ?? '')));
                            $is_direct_sale = in_array($ref_type, ['direct_sale', 'counter_sale', 'walk_in'], true)
                                || stripos((string) ($transaction['notes'] ?? ''), 'Reason: Direct Sale') !== false;
                            ?>
                            <tr>
                                <td><?php echo esc_html(app_format_datetime_pht($transaction['created_at'])); ?></td>
                                <td><?php echo esc_html(app_branch_label($transaction['branch_name'] ?? '', '-')); ?></td>
                                <td>
                                    <div class="inventory-item-cell">
                                        <strong><?php echo esc_html(app_display_item_name($transaction['item_name'], $transaction['category'] ?? null)); ?></strong>
                                        <small><?php echo esc_html(trim(($transaction['brand'] ?? '') . ' ' . ($transaction['size'] ?? ''))); ?></small>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    $is_transfer = $ref_type === 'inter_branch_transfer' || $ref_type === 'transfer' || stripos((string) ($transaction['notes'] ?? ''), 'received from') !== false;
                                    $transfer_donor = '';
                                    if (preg_match('/received from\s+([^,;\.]+)/i', (string) ($transaction['notes'] ?? ''), $tm)) {
                                        $transfer_donor = trim($tm[1]);
                                    }
                                    ?>
                                    <span class="inventory-type-pill <?php echo esc_attr(inventory_transaction_type_class($transaction['transaction_type'])); ?>">
                                        <?php echo esc_html(inventory_transaction_type_label($transaction['transaction_type'])); ?>
                                    </span>
                                    <?php if ($is_transfer): ?>
                                        <div style="margin-top: 4px;">
                                            <span class="badge bg-info text-dark" style="font-size: 11px; font-weight: 600;">
                                                <i class="fas fa-right-left me-1"></i> Transferred<?php echo $transfer_donor !== '' ? ': ' . esc_html($transfer_donor) : ''; ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><strong class="inventory-quantity"><?php echo (int) $transaction['quantity']; ?></strong></td>
                                <td>
                                    <?php if ($transaction['transaction_type'] === 'stock_in'): ?>
                                        <div class="inventory-tag-cell">
                                            <strong><i class="fas fa-truck-ramp-box" style="margin-right: 4px; color: #0284c7;"></i><?php echo esc_html($source_display_label); ?></strong>
                                        </div>
                                    <?php elseif ($is_direct_sale): ?>
                                        <?php if (!empty($transaction['customer_id'])): ?>
                                            <div class="inventory-tag-cell">
                                                <strong>Walk-In / Counter Sale</strong>
                                                <?php if ($tagged_customer_name !== ''): ?>
                                                    <small><i class="fas fa-user"></i>Customer: <?php echo esc_html($tagged_customer); ?></small>
                                                <?php else: ?>
                                                    <small><i class="fas fa-user"></i>Customer: Registered customer unavailable</small>
                                                <?php endif; ?>
                                                <?php if (!empty($transaction['vehicle_id']) && $direct_sale_vehicle !== ''): ?>
                                                    <small><i class="fas fa-car"></i>Vehicle: <?php echo esc_html($direct_sale_vehicle); ?></small>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="inventory-tag-empty"><?php echo esc_html($source_display_label); ?></span>
                                        <?php endif; ?>
                                    <?php elseif ($tagged_customer !== '' || $tagged_vehicle !== '' || !empty($tagged_refs)): ?>
                                        <div class="inventory-tag-cell">
                                            <?php if ($tagged_customer !== ''): ?>
                                                <strong><?php echo esc_html($tagged_customer); ?></strong>
                                            <?php endif; ?>
                                            <?php if ($tagged_vehicle !== ''): ?>
                                                <small><i class="fas fa-car"></i><?php echo esc_html($tagged_vehicle); ?></small>
                                            <?php endif; ?>
                                            <?php if (!empty($tagged_refs)): ?>
                                                <small><i class="fas fa-receipt"></i><?php echo esc_html(implode(' / ', $tagged_refs)); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="inventory-tag-empty"><?php echo esc_html($source_display_label); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($transaction['user_name'] ?? 'System'); ?></td>
                                <td class="inventory-transaction-notes"><?php echo esc_html($transaction['notes'] ?? '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($total_pages > 1): ?>
        <nav class="records-pagination inventory-records-pagination" aria-label="Inventory transaction pages" style="order: 99; margin-top: 16px; margin-bottom: 24px;">
            <ul class="pagination justify-content-center">
                <?php if ($page > 1): ?>
                    <li class="page-item"><a class="page-link" href="<?php echo esc_attr(inventory_transaction_url(1, $search, $transaction_type, $branch_filter, $date_filter)); ?>">First</a></li>
                    <li class="page-item"><a class="page-link" href="<?php echo esc_attr(inventory_transaction_url($page - 1, $search, $transaction_type, $branch_filter, $date_filter)); ?>">Previous</a></li>
                <?php endif; ?>
                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>"><a class="page-link" href="<?php echo esc_attr(inventory_transaction_url($i, $search, $transaction_type, $branch_filter, $date_filter)); ?>"><?php echo (int) $i; ?></a></li>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <li class="page-item"><a class="page-link" href="<?php echo esc_attr(inventory_transaction_url($page + 1, $search, $transaction_type, $branch_filter, $date_filter)); ?>">Next</a></li>
                    <li class="page-item"><a class="page-link" href="<?php echo esc_attr(inventory_transaction_url($total_pages, $search, $transaction_type, $branch_filter, $date_filter)); ?>">Last</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    <?php endif; ?>
</main>

<?php require_once '../../includes/footer.php'; ?>
