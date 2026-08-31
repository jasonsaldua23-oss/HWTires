<?php
/**
 * Admin Inventory Records
 */

require_once '../../includes/config.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$page_title = 'Inventory Records';
$user = app_get_session_user();

if (!function_exists('inventory_money')) {
    function inventory_money($amount) {
        return '₱' . number_format((float) $amount, 0);
    }
}

if (!function_exists('inventory_category_label')) {
    function inventory_category_label($category, $plural = false) {
        $labels = [
            'tire' => $plural ? 'Tires' : 'Tire',
            'accessory' => $plural ? 'Accessories' : 'Accessory',
            'part' => $plural ? 'Parts' : 'Part',
        ];

        return $labels[$category] ?? ($plural ? 'All Items' : 'Item');
    }
}

if (!function_exists('inventory_branch_label')) {
    function inventory_branch_label($branch_name) {
        return app_branch_label($branch_name, 'Branch');
    }
}

if (!function_exists('inventory_item_details')) {
    function inventory_item_details($item) {
        $details = inventory_item_detail_lines($item);
        $values = array_map(static function ($detail) {
            return $detail['value'];
        }, array_filter($details, static function ($detail) {
            return ($detail['value'] ?? '-') !== '-';
        }));

        return !empty($values) ? implode(' | ', $values) : '-';
    }
}

if (!function_exists('inventory_item_detail_lines')) {
    function inventory_item_detail_lines($item) {
        $serial = trim((string) ($item['serial_number'] ?? $item['sku'] ?? ''));
        $model = trim((string) ($item['model'] ?? ''));
        $size = trim((string) ($item['size'] ?? ''));
        $category = strtolower(trim((string) ($item['category'] ?? '')));
        $size_label = $category === 'tire' ? 'Tire Size' : 'Size/Fitment';
        $description = trim((string) ($item['description'] ?? ''));
        $manufacturing_date = trim((string) ($item['manufacturing_date'] ?? ''));

        if ($model === '') {
            $model = trim((string) ($item['brand'] ?? ''));
        }

        if ($model === '' && $description !== '') {
            $model = $description;
        }

        return [
            ['label' => $size_label, 'value' => $size !== '' ? $size : '-'],
            ['label' => 'Model', 'value' => $model !== '' ? $model : '-'],
            ['label' => 'Serial/SKU', 'value' => $serial !== '' ? $serial : '-'],
            ['label' => 'Mfg Date', 'value' => $manufacturing_date !== '' ? format_date($manufacturing_date, 'M d, Y') : '-'],
        ];
    }
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

if (!function_exists('inventory_transaction_source_links')) {
    function inventory_transaction_source_links(array $transaction) {
        $links = [];
        $reference_type = strtolower(trim((string) ($transaction['reference_type'] ?? '')));
        $quotation_id = (int) ($transaction['tagged_quotation_id'] ?? $transaction['quotation_id'] ?? 0);
        $quotation_number = trim((string) ($transaction['tagged_quotation_number'] ?? ''));
        $job_order_id = (int) ($transaction['tagged_job_order_id'] ?? $transaction['job_order_id'] ?? 0);
        $job_number = trim((string) ($transaction['tagged_job_number'] ?? ''));

        if ($job_order_id <= 0 && $reference_type === 'job_order') {
            $job_order_id = (int) ($transaction['reference_id'] ?? 0);
        }

        if ($quotation_id > 0) {
            $links[] = [
                'href' => '/hwtires/admin/quotations/view.php?id=' . $quotation_id,
                'label' => $quotation_number !== '' ? $quotation_number : 'Quotation',
                'icon' => 'fas fa-receipt',
                'title' => 'Open quotation',
            ];
        }

        if ($job_order_id > 0) {
            $links[] = [
                'href' => '/hwtires/admin/job-orders/view.php?id=' . $job_order_id,
                'label' => $job_number !== '' ? $job_number : 'Job Order',
                'icon' => 'fas fa-clipboard-list',
                'title' => 'Open job order',
            ];
        }

        return $links;
    }
}

if (!function_exists('inventory_filter_url')) {
    function inventory_filter_url($category, $branch, $search = '', $per_page = null, $page = null, $view = 'all', $sales_mode = 'all', $brand = '', $size = '') {
        $query = [];

        if ($view !== 'all') {
            $query['view'] = $view;
        }

        if ($view === 'last_month_sales' && $sales_mode === 'top10') {
            $query['sales_mode'] = 'top10';
        }

        if ($category !== 'all' && $category !== '') {
            $query['category'] = $category;
        }

        $brand = trim((string) $brand);
        if ($brand !== '') {
            $query['brand'] = $brand;
        }

        $size = trim((string) $size);
        if ($size !== '') {
            $query['size'] = $size;
        }

        if ($branch !== 'all' && $branch !== '') {
            $query['branch'] = $branch;
        }

        $search = trim((string) $search);
        if ($search !== '') {
            $query['search'] = $search;
        }

        $per_page = (int) $per_page;
        if (in_array($per_page, [10, 20, 50], true)) {
            $query['per_page'] = $per_page;
        }

        $page = (int) $page;
        if ($page > 1) {
            $query['page'] = $page;
        }

        return empty($query) ? './' : '?' . http_build_query($query);
    }
}

$branches_query = "SELECT id, name FROM branches WHERE status = 'active' AND has_inventory = 1";
$branches_params = [];

if (($user['role'] ?? '') !== 'admin') {
    $branches_query .= " AND id = ?";
    $branches_params[] = (int) ($user['branch_id'] ?? 0);
}

$branches_query .= " ORDER BY id";
$branches_stmt = $pdo->prepare($branches_query);
$branches_stmt->execute($branches_params);
$inventory_branches = $branches_stmt->fetchAll();
$allowed_branch_ids = array_map(static function ($branch) {
    return (int) $branch['id'];
}, $inventory_branches);

$valid_categories = ['all', 'tire', 'accessory', 'part'];
$category_filter = strtolower(trim($_GET['category'] ?? 'all'));
if (!in_array($category_filter, $valid_categories, true)) {
    $category_filter = 'all';
}

$brand_filter = trim((string) ($_GET['brand'] ?? ''));
$size_filter = trim((string) ($_GET['size'] ?? ''));

// Fetch distinct categories, brands, and sizes for dropdowns (scoped to selected branch if applicable)
if ($branch_filter !== 'all') {
    $filter_meta_stmt = $pdo->prepare("
        SELECT DISTINCT category, brand, size 
        FROM inventory_items 
        WHERE status = 'active' AND branch_id = ?
        ORDER BY category ASC, brand ASC, size ASC
    ");
    $filter_meta_stmt->execute([(int) $branch_filter]);
} else {
    $filter_meta_stmt = $pdo->query("
        SELECT DISTINCT category, brand, size 
        FROM inventory_items 
        WHERE status = 'active'
        ORDER BY category ASC, brand ASC, size ASC
    ");
}
$raw_filter_meta = $filter_meta_stmt ? $filter_meta_stmt->fetchAll(PDO::FETCH_ASSOC) : [];

$brands_by_category = [];
$sizes_by_brand = [];
$all_brands = [];
$all_sizes = [];

foreach ($raw_filter_meta as $row) {
    $cat = strtolower(trim((string) ($row['category'] ?? '')));
    $b = trim((string) ($row['brand'] ?? ''));
    $s = trim((string) ($row['size'] ?? ''));
    
    if ($b !== '') {
        $all_brands[$b] = $b;
        if ($cat !== '') {
            $brands_by_category[$cat][$b] = $b;
        }
    }
    if ($s !== '') {
        $all_sizes[$s] = $s;
        if ($b !== '') {
            $sizes_by_brand[$b][$s] = $s;
        }
    }
}

$available_brands = ($category_filter !== 'all' && isset($brands_by_category[$category_filter]))
    ? array_values($brands_by_category[$category_filter])
    : array_values($all_brands);

$available_sizes = ($brand_filter !== '' && isset($sizes_by_brand[$brand_filter]))
    ? array_values($sizes_by_brand[$brand_filter])
    : array_values($all_sizes);

$valid_inventory_views = ['all', 'stock_in', 'stock_out', 'low_stock', 'last_month_sales'];
$view_filter = strtolower(trim($_GET['view'] ?? 'all'));
if (!in_array($view_filter, $valid_inventory_views, true)) {
    $view_filter = 'all';
}
$transaction_views = ['stock_in', 'stock_out', 'last_month_sales'];
$is_transaction_view = in_array($view_filter, $transaction_views, true);

$sales_mode = strtolower(trim($_GET['sales_mode'] ?? 'all'));
if (!in_array($sales_mode, ['all', 'top10'], true)) {
    $sales_mode = 'all';
}

$branch_filter = trim($_GET['branch'] ?? 'all');
if ($branch_filter === '') {
    $branch_filter = 'all';
}

if ($branch_filter !== 'all') {
    $branch_id_candidate = (int) $branch_filter;
    $branch_filter = in_array($branch_id_candidate, $allowed_branch_ids, true) ? (string) $branch_id_candidate : 'all';
}

$search_filter = trim($_GET['search'] ?? '');
if (function_exists('mb_substr')) {
    $search_filter = mb_substr($search_filter, 0, 100);
} else {
    $search_filter = substr($search_filter, 0, 100);
}

$page_sizes = [10, 20, 50];
$per_page = (int) ($_GET['per_page'] ?? 10);
if (!in_array($per_page, $page_sizes, true)) {
    $per_page = 10;
}
$page = max(1, (int) ($_GET['page'] ?? 1));

$where = ["i.status = 'active'"];
$filter_params = [];

if (empty($allowed_branch_ids)) {
    $where[] = '1 = 0';
} elseif ($branch_filter !== 'all') {
    $where[] = 'i.branch_id = ?';
    $filter_params[] = (int) $branch_filter;
} else {
    $where[] = 'i.branch_id IN (' . implode(',', array_fill(0, count($allowed_branch_ids), '?')) . ')';
    $filter_params = array_merge($filter_params, $allowed_branch_ids);
}

if ($category_filter !== 'all') {
    $where[] = 'i.category = ?';
    $filter_params[] = $category_filter;
}

if ($brand_filter !== '') {
    $where[] = 'i.brand = ?';
    $filter_params[] = $brand_filter;
}

if ($size_filter !== '') {
    $where[] = '(i.size = ? OR i.item_name LIKE ?)';
    $filter_params[] = $size_filter;
    $filter_params[] = '%' . $size_filter . '%';
}

if ($search_filter !== '') {
    $item_search_columns = [
        'i.item_name',
        'i.brand',
        'i.size',
        'i.sku',
        'i.description',
        'i.category',
    ];

    if (app_column_exists('inventory_items', 'model')) {
        $item_search_columns[] = 'i.model';
    }

    if (app_column_exists('inventory_items', 'serial_number')) {
        $item_search_columns[] = 'i.serial_number';
    }

    foreach (app_search_terms($search_filter) as $term) {
        $where[] = '(' . implode(' OR ', array_map(static function ($column) {
            return $column . ' LIKE ?';
        }, $item_search_columns)) . ')';
        $search_like = '%' . $term . '%';
        $filter_params = array_merge($filter_params, array_fill(0, count($item_search_columns), $search_like));
    }
}

$where_sql = implode(' AND ', $where);

$stats_stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_items,
        COALESCE(SUM(i.quantity * i.unit_price), 0) AS total_stock_value,
        SUM(CASE WHEN i.quantity <= i.reorder_level THEN 1 ELSE 0 END) AS low_stock_count
    FROM inventory_items i
    WHERE $where_sql
");
$stats_stmt->execute($filter_params);
$stats = $stats_stmt->fetch() ?: ['total_items' => 0, 'total_stock_value' => 0, 'low_stock_count' => 0];

$last_month_start = date('Y-m-01 00:00:00', strtotime('first day of last month'));
$this_month_start = date('Y-m-01 00:00:00');
$last_month_label = date('M j', strtotime($last_month_start)) . ' - ' . date('M j, Y', strtotime($this_month_start . ' -1 day'));

$transaction_where = ["LOWER(REPLACE(t.transaction_type, ' ', '_')) IN ('stock_in', 'stock_out')"];
$transaction_params = [];

if (empty($allowed_branch_ids)) {
    $transaction_where[] = '1 = 0';
} elseif ($branch_filter !== 'all') {
    $transaction_where[] = 'i.branch_id = ?';
    $transaction_params[] = (int) $branch_filter;
} else {
    $transaction_where[] = 'i.branch_id IN (' . implode(',', array_fill(0, count($allowed_branch_ids), '?')) . ')';
    $transaction_params = array_merge($transaction_params, $allowed_branch_ids);
}

if ($category_filter !== 'all') {
    $transaction_where[] = 'i.category = ?';
    $transaction_params[] = $category_filter;
}

if ($brand_filter !== '') {
    $transaction_where[] = 'i.brand = ?';
    $transaction_params[] = $brand_filter;
}

if ($size_filter !== '') {
    $transaction_where[] = '(i.size = ? OR i.item_name LIKE ?)';
    $transaction_params[] = $size_filter;
    $transaction_params[] = '%' . $size_filter . '%';
}

if ($view_filter === 'stock_in' || $view_filter === 'stock_out') {
    $transaction_where[] = "LOWER(REPLACE(t.transaction_type, ' ', '_')) = ?";
    $transaction_params[] = $view_filter;
} elseif ($view_filter === 'last_month_sales') {
    $transaction_where[] = "LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out'";
    $transaction_where[] = 't.created_at >= ? AND t.created_at < ?';
    $transaction_params[] = $last_month_start;
    $transaction_params[] = $this_month_start;
}

if ($search_filter !== '') {
    $transaction_search_columns = [
        'i.item_name',
        'i.brand',
        'i.size',
        'i.sku',
        'i.description',
        'i.category',
        'b.name',
        't.notes',
        'u.name',
        't.reference_type',
        'tagged_customer.name',
        'tagged_customer.phone_mobile',
        'tagged_vehicle.plate_number',
        'tagged_vehicle.make',
        'tagged_vehicle.model',
        'tagged_quotation.quotation_number',
        'tagged_job.job_number',
    ];

    if (app_column_exists('inventory_items', 'model')) {
        $transaction_search_columns[] = 'i.model';
    }

    if (app_column_exists('inventory_items', 'serial_number')) {
        $transaction_search_columns[] = 'i.serial_number';
    }

    foreach (app_search_terms($search_filter) as $term) {
        $transaction_where[] = '(' . implode(' OR ', array_map(static function ($column) {
            return $column . ' LIKE ?';
        }, $transaction_search_columns)) . ')';
        $search_like = '%' . $term . '%';
        $transaction_params = array_merge($transaction_params, array_fill(0, count($transaction_search_columns), $search_like));
    }
}

$transactions = [];
$item_list_where = $where;
$item_list_params = $filter_params;

if ($view_filter === 'low_stock') {
    $item_list_where[] = 'i.quantity <= i.reorder_level';
}

$item_list_where_sql = implode(' AND ', $item_list_where);
$transaction_where_sql = implode(' AND ', $transaction_where);

if ($is_transaction_view) {
    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM inventory_transactions t
        INNER JOIN inventory_items i ON i.id = t.item_id
        LEFT JOIN branches b ON b.id = i.branch_id
        LEFT JOIN users u ON u.id = t.created_by
        LEFT JOIN customers tagged_customer ON tagged_customer.id = t.customer_id
        LEFT JOIN vehicles tagged_vehicle ON tagged_vehicle.id = t.vehicle_id
        LEFT JOIN job_orders tagged_job ON tagged_job.id = t.job_order_id
        LEFT JOIN quotations tagged_quotation ON tagged_quotation.id = t.quotation_id
        WHERE $transaction_where_sql
    ");
    $count_stmt->execute($transaction_params);
    $total_records = (int) ($count_stmt->fetch()['total'] ?? 0);
} else {
    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM inventory_items i
        WHERE $item_list_where_sql
    ");
    $count_stmt->execute($item_list_params);
    $total_records = (int) ($count_stmt->fetch()['total'] ?? 0);
}

$total_pages = max(1, (int) ceil($total_records / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;
$showing_from = $total_records > 0 ? $offset + 1 : 0;
$showing_to = min($offset + $per_page, $total_records);

if ($is_transaction_view) {
    $transaction_stmt = $pdo->prepare("
        SELECT
            t.*,
            i.item_name,
            i.category,
            i.brand,
            i.size,
            i.description,
            i.sku,
            i.unit_price,
            i.branch_id,
            u.name AS user_name,
            b.name AS branch_name,
            tagged_customer.name AS tagged_customer_name,
            tagged_customer.phone_mobile AS tagged_customer_phone,
            tagged_vehicle.plate_number AS tagged_plate_number,
            tagged_vehicle.make AS tagged_vehicle_make,
            tagged_vehicle.model AS tagged_vehicle_model,
            tagged_vehicle.year AS tagged_vehicle_year,
            tagged_job.id AS tagged_job_order_id,
            tagged_job.job_number AS tagged_job_number,
            tagged_quotation.id AS tagged_quotation_id,
            tagged_quotation.quotation_number AS tagged_quotation_number
        FROM inventory_transactions t
        INNER JOIN inventory_items i ON i.id = t.item_id
        LEFT JOIN branches b ON b.id = i.branch_id
        LEFT JOIN users u ON u.id = t.created_by
        LEFT JOIN customers tagged_customer ON tagged_customer.id = t.customer_id
        LEFT JOIN vehicles tagged_vehicle ON tagged_vehicle.id = t.vehicle_id
        LEFT JOIN job_orders tagged_job ON tagged_job.id = COALESCE(NULLIF(t.job_order_id, 0), CASE WHEN t.reference_type = 'job_order' THEN t.reference_id ELSE NULL END)
        LEFT JOIN quotations tagged_quotation ON tagged_quotation.id = COALESCE(NULLIF(t.quotation_id, 0), tagged_job.quotation_id)
        WHERE $transaction_where_sql
        ORDER BY t.created_at DESC, t.id DESC
        LIMIT $per_page OFFSET $offset
    ");
    $transaction_stmt->execute($transaction_params);
    $transactions = $transaction_stmt->fetchAll();
    $inventory = [];
} else {
    $inventory_stmt = $pdo->prepare("
        SELECT i.*, b.name AS branch_name
        FROM inventory_items i
        LEFT JOIN branches b ON b.id = i.branch_id
        WHERE $item_list_where_sql
        ORDER BY i.branch_id ASC, FIELD(i.category, 'tire', 'accessory', 'part'), i.item_name ASC
        LIMIT $per_page OFFSET $offset
    ");
    $inventory_stmt->execute($item_list_params);
    $inventory = $inventory_stmt->fetchAll();
}

$low_stock_stmt = $pdo->prepare("
    SELECT i.*, b.name AS branch_name
    FROM inventory_items i
    LEFT JOIN branches b ON b.id = i.branch_id
    WHERE $where_sql AND i.quantity <= i.reorder_level
    ORDER BY i.quantity ASC, i.item_name ASC
");
$low_stock_stmt->execute($filter_params);
$low_stock_items = $low_stock_stmt->fetchAll();

$branch_names = array_map(static function ($branch) {
    return inventory_branch_label($branch['name'] ?? '');
}, $inventory_branches);
$branch_copy = 'available inventory branches';
if (count($branch_names) === 1) {
    $branch_copy = $branch_names[0];
} elseif (count($branch_names) > 1) {
    $last_branch = array_pop($branch_names);
    $branch_copy = implode(', ', $branch_names) . ' and ' . $last_branch;
}

$inventory_view_options = [
    'all' => ['label' => 'All Items', 'icon' => 'fas fa-boxes-stacked', 'noun' => 'items'],
    'stock_in' => ['label' => 'Stock In', 'icon' => 'fas fa-arrow-trend-up', 'noun' => 'transactions'],
    'stock_out' => ['label' => 'Stock Out', 'icon' => 'fas fa-arrow-trend-down', 'noun' => 'transactions'],
    'low_stock' => ['label' => 'Low Stock', 'icon' => 'fas fa-triangle-exclamation', 'noun' => 'items'],
    'last_month_sales' => ['label' => 'Last Month Sales', 'icon' => 'fas fa-calendar-days', 'noun' => 'sales records'],
];
$active_view = $inventory_view_options[$view_filter] ?? $inventory_view_options['all'];
$records_heading = $active_view['label'];
$records_noun = $active_view['noun'];
$records_empty_message = $is_transaction_view ? 'No stock movement records found.' : 'No inventory items found.';
if ($view_filter === 'low_stock') {
    $records_empty_message = 'No low stock items found.';
} elseif ($view_filter === 'last_month_sales') {
    $records_empty_message = 'No sales records found for ' . $last_month_label . '.';
}

$dynamic_stats = [];
$top_10_items = [];
if ($view_filter === 'last_month_sales') {
    $top10_stmt = $pdo->prepare("
        SELECT
            i.id,
            i.item_name,
            i.category,
            i.brand,
            i.size,
            i.sku,
            i.unit_price,
            i.branch_id,
            b.name AS branch_name,
            COUNT(t.id) AS total_orders,
            COALESCE(SUM(t.quantity), 0) AS total_sold_qty,
            COALESCE(SUM(t.quantity * i.unit_price), 0) AS total_sold_amount
        FROM inventory_transactions t
        INNER JOIN inventory_items i ON i.id = t.item_id
        LEFT JOIN branches b ON b.id = i.branch_id
        LEFT JOIN users u ON u.id = t.created_by
        LEFT JOIN customers tagged_customer ON tagged_customer.id = t.customer_id
        LEFT JOIN vehicles tagged_vehicle ON tagged_vehicle.id = t.vehicle_id
        LEFT JOIN job_orders tagged_job ON tagged_job.id = t.job_order_id
        LEFT JOIN quotations tagged_quotation ON tagged_quotation.id = t.quotation_id
        WHERE $transaction_where_sql
        GROUP BY t.item_id
        ORDER BY total_sold_qty DESC, total_sold_amount DESC
        LIMIT 10
    ");
    $top10_stmt->execute($transaction_params);
    $top_10_items = $top10_stmt->fetchAll();
    $top_item = $top_10_items[0] ?? null;

    $tx_summary_stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_tx,
            COALESCE(SUM(t.quantity), 0) AS total_units,
            COALESCE(SUM(t.quantity * i.unit_price), 0) AS total_revenue
        FROM inventory_transactions t
        INNER JOIN inventory_items i ON i.id = t.item_id
        LEFT JOIN branches b ON b.id = i.branch_id
        LEFT JOIN users u ON u.id = t.created_by
        LEFT JOIN customers tagged_customer ON tagged_customer.id = t.customer_id
        LEFT JOIN vehicles tagged_vehicle ON tagged_vehicle.id = t.vehicle_id
        LEFT JOIN job_orders tagged_job ON tagged_job.id = t.job_order_id
        LEFT JOIN quotations tagged_quotation ON tagged_quotation.id = t.quotation_id
        WHERE $transaction_where_sql
    ");
    $tx_summary_stmt->execute($transaction_params);
    $tx_summary = $tx_summary_stmt->fetch() ?: ['total_tx' => 0, 'total_units' => 0, 'total_revenue' => 0];

    $dynamic_stats = [
        [
            'label' => 'Total Sales Revenue',
            'value' => inventory_money($tx_summary['total_revenue']),
            'subtext' => 'Sales in ' . $last_month_label,
            'icon' => 'fas fa-peso-sign',
            'icon_class' => 'icon-green',
        ],
        [
            'label' => 'Total Units Sold',
            'value' => number_format((float) $tx_summary['total_units']) . ' units',
            'subtext' => number_format((int) $tx_summary['total_tx']) . ' sales records',
            'icon' => 'fas fa-box-open',
            'icon_class' => 'icon-cyan',
        ],
        [
            'label' => 'Most Sold Item',
            'value' => $top_item ? esc_html($top_item['item_name']) : 'None',
            'subtext' => $top_item ? (number_format((float) $top_item['total_sold_qty']) . ' units · ₱' . number_format((float) $top_item['total_sold_amount'])) : 'No sales recorded',
            'icon' => 'fas fa-fire',
            'icon_class' => 'icon-gold',
        ],
    ];
} elseif ($view_filter === 'stock_out') {
    $tx_summary_stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_tx,
            COALESCE(SUM(t.quantity), 0) AS total_units,
            COALESCE(SUM(t.quantity * i.unit_price), 0) AS total_value
        FROM inventory_transactions t
        INNER JOIN inventory_items i ON i.id = t.item_id
        LEFT JOIN branches b ON b.id = i.branch_id
        LEFT JOIN users u ON u.id = t.created_by
        LEFT JOIN customers tagged_customer ON tagged_customer.id = t.customer_id
        LEFT JOIN vehicles tagged_vehicle ON tagged_vehicle.id = t.vehicle_id
        LEFT JOIN job_orders tagged_job ON tagged_job.id = t.job_order_id
        LEFT JOIN quotations tagged_quotation ON tagged_quotation.id = t.quotation_id
        WHERE $transaction_where_sql
    ");
    $tx_summary_stmt->execute($transaction_params);
    $tx_summary = $tx_summary_stmt->fetch() ?: ['total_tx' => 0, 'total_units' => 0, 'total_value' => 0];

    $top_item_stmt = $pdo->prepare("
        SELECT
            MAX(i.item_name) AS item_name,
            COALESCE(SUM(t.quantity), 0) AS total_qty,
            COALESCE(SUM(t.quantity * i.unit_price), 0) AS total_amount
        FROM inventory_transactions t
        INNER JOIN inventory_items i ON i.id = t.item_id
        LEFT JOIN branches b ON b.id = i.branch_id
        LEFT JOIN users u ON u.id = t.created_by
        LEFT JOIN customers tagged_customer ON tagged_customer.id = t.customer_id
        LEFT JOIN vehicles tagged_vehicle ON tagged_vehicle.id = t.vehicle_id
        LEFT JOIN job_orders tagged_job ON tagged_job.id = t.job_order_id
        LEFT JOIN quotations tagged_quotation ON tagged_quotation.id = t.quotation_id
        WHERE $transaction_where_sql
        GROUP BY t.item_id
        ORDER BY total_qty DESC, total_amount DESC
        LIMIT 1
    ");
    $top_item_stmt->execute($transaction_params);
    $top_item = $top_item_stmt->fetch() ?: null;

    $dynamic_stats = [
        [
            'label' => 'Total Stock-Out Value',
            'value' => inventory_money($tx_summary['total_value']),
            'subtext' => 'Total outbound value',
            'icon' => 'fas fa-arrow-trend-up',
            'icon_class' => 'icon-green',
        ],
        [
            'label' => 'Units Dispatched',
            'value' => number_format((float) $tx_summary['total_units']) . ' units',
            'subtext' => number_format((int) $tx_summary['total_tx']) . ' dispatch records',
            'icon' => 'fas fa-dolly',
            'icon_class' => 'icon-cyan',
        ],
        [
            'label' => 'Most Dispatched Item',
            'value' => $top_item ? esc_html($top_item['item_name']) : 'None',
            'subtext' => $top_item ? (number_format((float) $top_item['total_qty']) . ' units · ₱' . number_format((float) $top_item['total_amount'])) : 'No dispatches recorded',
            'icon' => 'fas fa-fire',
            'icon_class' => 'icon-gold',
        ],
    ];
} elseif ($view_filter === 'stock_in') {
    $tx_summary_stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_tx,
            COALESCE(SUM(t.quantity), 0) AS total_units,
            COALESCE(SUM(t.quantity * i.unit_price), 0) AS total_value
        FROM inventory_transactions t
        INNER JOIN inventory_items i ON i.id = t.item_id
        LEFT JOIN branches b ON b.id = i.branch_id
        LEFT JOIN users u ON u.id = t.created_by
        LEFT JOIN customers tagged_customer ON tagged_customer.id = t.customer_id
        LEFT JOIN vehicles tagged_vehicle ON tagged_vehicle.id = t.vehicle_id
        LEFT JOIN job_orders tagged_job ON tagged_job.id = t.job_order_id
        LEFT JOIN quotations tagged_quotation ON tagged_quotation.id = t.quotation_id
        WHERE $transaction_where_sql
    ");
    $tx_summary_stmt->execute($transaction_params);
    $tx_summary = $tx_summary_stmt->fetch() ?: ['total_tx' => 0, 'total_units' => 0, 'total_value' => 0];

    $top_item_stmt = $pdo->prepare("
        SELECT
            MAX(i.item_name) AS item_name,
            COALESCE(SUM(t.quantity), 0) AS total_qty,
            COALESCE(SUM(t.quantity * i.unit_price), 0) AS total_amount
        FROM inventory_transactions t
        INNER JOIN inventory_items i ON i.id = t.item_id
        LEFT JOIN branches b ON b.id = i.branch_id
        LEFT JOIN users u ON u.id = t.created_by
        LEFT JOIN customers tagged_customer ON tagged_customer.id = t.customer_id
        LEFT JOIN vehicles tagged_vehicle ON tagged_vehicle.id = t.vehicle_id
        LEFT JOIN job_orders tagged_job ON tagged_job.id = t.job_order_id
        LEFT JOIN quotations tagged_quotation ON tagged_quotation.id = t.quotation_id
        WHERE $transaction_where_sql
        GROUP BY t.item_id
        ORDER BY total_qty DESC, total_amount DESC
        LIMIT 1
    ");
    $top_item_stmt->execute($transaction_params);
    $top_item = $top_item_stmt->fetch() ?: null;

    $dynamic_stats = [
        [
            'label' => 'Total Stock-In Value',
            'value' => inventory_money($tx_summary['total_value']),
            'subtext' => 'Total restocked value',
            'icon' => 'fas fa-boxes-stacked',
            'icon_class' => 'icon-green',
        ],
        [
            'label' => 'Units Received',
            'value' => number_format((float) $tx_summary['total_units']) . ' units',
            'subtext' => number_format((int) $tx_summary['total_tx']) . ' receiving records',
            'icon' => 'fas fa-truck-ramp-box',
            'icon_class' => 'icon-cyan',
        ],
        [
            'label' => 'Most Restocked Item',
            'value' => $top_item ? esc_html($top_item['item_name']) : 'None',
            'subtext' => $top_item ? (number_format((float) $top_item['total_qty']) . ' units · ₱' . number_format((float) $top_item['total_amount'])) : 'No restocks recorded',
            'icon' => 'fas fa-cubes',
            'icon_class' => 'icon-cyan',
        ],
    ];
} elseif ($view_filter === 'low_stock') {
    $low_stock_summary_stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_items,
            COALESCE(SUM(i.quantity * i.unit_price), 0) AS total_stock_value,
            SUM(CASE WHEN i.quantity = 0 THEN 1 ELSE 0 END) AS out_of_stock_count
        FROM inventory_items i
        WHERE $item_list_where_sql
    ");
    $low_stock_summary_stmt->execute($item_list_params);
    $ls_summary = $low_stock_summary_stmt->fetch() ?: ['total_items' => 0, 'total_stock_value' => 0, 'out_of_stock_count' => 0];

    $lowest_item_stmt = $pdo->prepare("
        SELECT i.item_name, i.quantity, i.reorder_level
        FROM inventory_items i
        WHERE $item_list_where_sql
        ORDER BY i.quantity ASC, i.reorder_level DESC, i.item_name ASC
        LIMIT 1
    ");
    $lowest_item_stmt->execute($item_list_params);
    $lowest_item = $lowest_item_stmt->fetch() ?: null;

    $dynamic_stats = [
        [
            'label' => 'Low Stock Items',
            'value' => number_format((int) $ls_summary['total_items']) . ' items',
            'subtext' => 'At or below reorder level',
            'icon' => 'fas fa-triangle-exclamation',
            'icon_class' => 'icon-gold',
        ],
        [
            'label' => 'Low Stock Value',
            'value' => inventory_money($ls_summary['total_stock_value']),
            'subtext' => 'Capital in low stock',
            'icon' => 'fas fa-vault',
            'icon_class' => 'icon-cyan',
        ],
        [
            'label' => 'Lowest Stock Item',
            'value' => $lowest_item ? esc_html($lowest_item['item_name']) : 'None',
            'subtext' => $lowest_item ? ((int) $lowest_item['quantity'] . ' unit' . ((int) $lowest_item['quantity'] === 1 ? '' : 's') . ' left · Reorder: ' . (int) $lowest_item['reorder_level']) : 'No low stock items',
            'icon' => 'fas fa-circle-exclamation',
            'icon_class' => 'icon-red',
        ],
    ];
} else {
    $dynamic_stats = [
        [
            'label' => 'Total Items',
            'value' => number_format((int) ($stats['total_items'] ?? 0)),
            'subtext' => 'Active inventory items',
            'icon' => 'fas fa-boxes-stacked',
            'icon_class' => 'icon-cyan',
        ],
        [
            'label' => 'Total Stock Value',
            'value' => inventory_money($stats['total_stock_value'] ?? 0),
            'subtext' => 'Total inventory valuation',
            'icon' => 'fas fa-vault',
            'icon_class' => 'icon-green',
        ],
        [
            'label' => 'Low Stock Alerts',
            'value' => number_format((int) ($stats['low_stock_count'] ?? 0)),
            'subtext' => 'Items needing reorder',
            'icon' => 'fas fa-triangle-exclamation',
            'icon_class' => 'icon-red',
        ],
    ];
}

$active_filter_url = inventory_filter_url($category_filter, $branch_filter, $search_filter, $per_page, $page, $view_filter, $sales_mode);
$redirect_url = '/hwtires/admin/tire-inventory/' . ($active_filter_url === './' ? '' : $active_filter_url) . '#inventory-records';
?>

<?php require_once '../../includes/header.php'; ?>
<?php require_once '../../includes/sidebar.php'; ?>

<main class="inventory-records-page">
    <header class="inventory-hero">
        <div>
            <h1>Inventory Records</h1>
            <p>Manage tires, parts, and accessories for <?php echo esc_html($branch_copy); ?></p>
        </div>
        <div class="inventory-hero-actions">
            <a href="/hwtires/admin/tire-inventory/transactions.php" class="inventory-history-btn">
                <i class="fas fa-history"></i>
                <span>Inventory Transactions</span>
            </a>
            <button type="button" class="inventory-add-btn" data-bs-toggle="modal" data-bs-target="#addInventoryModal">
                <i class="fas fa-plus"></i>
                <span>Add Inventory Item</span>
            </button>
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

    <section class="inventory-summary-grid" aria-label="Inventory summary">
        <?php foreach ($dynamic_stats as $stat): ?>
            <article class="inventory-summary-card">
                <div>
                    <span><?php echo esc_html($stat['label']); ?></span>
                    <strong><?php echo esc_html($stat['value']); ?></strong>
                    <?php if (!empty($stat['subtext'])): ?>
                        <small style="display:block; font-size: 11px; color: var(--company-muted); margin-top: 2px;" title="<?php echo esc_attr(strip_tags($stat['subtext'])); ?>"><?php echo esc_html($stat['subtext']); ?></small>
                    <?php endif; ?>
                </div>
                <span class="inventory-summary-icon <?php echo esc_attr($stat['icon_class']); ?>">
                    <i class="<?php echo esc_attr($stat['icon']); ?>"></i>
                </span>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="inventory-filter-card">
        <form class="inventory-unified-filter-form" method="get" action="./#inventory-records" style="display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; width: 100%;">
            <input type="hidden" name="per_page" value="<?php echo (int) $per_page; ?>">
            <div class="inventory-filter-group" style="flex: 1; min-width: 120px;">
                <h2 style="font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #475569;">Category</h2>
                <select name="category" id="adminCategoryFilter" aria-label="Filter inventory category" class="form-select" style="height: 42px; border-radius: 8px; border-color: #cbd5e1; font-weight: 500;">
                <?php foreach (['all' => 'All Items', 'tire' => 'Tires', 'accessory' => 'Accessories', 'part' => 'Parts'] as $category_value => $category_label): ?>
                    <option value="<?php echo esc_attr($category_value); ?>" <?php echo $category_filter === $category_value ? 'selected' : ''; ?>>
                        <?php echo esc_html($category_label); ?>
                    </option>
                <?php endforeach; ?>
                </select>
            </div>
            <div class="inventory-filter-group" id="adminBrandGroup" style="flex: 1; min-width: 130px; <?php echo ($category_filter === 'all' && $brand_filter === '') ? 'display: none;' : ''; ?>">
                <h2 style="font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #475569;">Brand</h2>
                <select name="brand" id="adminBrandFilter" aria-label="Filter inventory brand" class="form-select" style="height: 42px; border-radius: 8px; border-color: #cbd5e1; font-weight: 500;">
                    <option value="">All Brands</option>
                    <?php foreach ($available_brands as $brand_name): ?>
                        <option value="<?php echo esc_attr($brand_name); ?>" <?php echo $brand_filter === $brand_name ? 'selected' : ''; ?>>
                            <?php echo esc_html($brand_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="inventory-filter-group" id="adminSizeGroup" style="flex: 1; min-width: 130px; <?php echo ($category_filter === 'all' && $size_filter === '') ? 'display: none;' : ''; ?>">
                <h2 style="font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #475569;">Size / Spec</h2>
                <select name="size" id="adminSizeFilter" aria-label="Filter inventory size" class="form-select" style="height: 42px; border-radius: 8px; border-color: #cbd5e1; font-weight: 500;">
                    <option value="">All Sizes</option>
                    <?php foreach ($available_sizes as $size_val): ?>
                        <option value="<?php echo esc_attr($size_val); ?>" <?php echo $size_filter === $size_val ? 'selected' : ''; ?>>
                            <?php echo esc_html($size_val); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="inventory-filter-group" style="flex: 1; min-width: 140px;">
                <h2 style="font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #475569;">Branch</h2>
                <select name="branch" aria-label="Filter inventory branch" class="form-select" style="height: 42px; border-radius: 8px; border-color: #cbd5e1; font-weight: 500;">
                    <option value="all" <?php echo $branch_filter === 'all' ? 'selected' : ''; ?>>All Branches</option>
                <?php foreach ($inventory_branches as $branch): ?>
                    <?php $branch_value = (string) (int) $branch['id']; ?>
                    <option value="<?php echo esc_attr($branch_value); ?>" <?php echo $branch_filter === $branch_value ? 'selected' : ''; ?>>
                        <?php echo esc_html(inventory_branch_label($branch['name'])); ?>
                    </option>
                <?php endforeach; ?>
                </select>
            </div>
            <div class="inventory-filter-group inventory-view-group" style="flex: 1; min-width: 130px;">
                <h2 style="font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #475569;">Record View</h2>
                <select name="view" aria-label="Select inventory record view" class="form-select" style="height: 42px; border-radius: 8px; border-color: #cbd5e1; font-weight: 500;">
                <?php foreach ($inventory_view_options as $view_value => $view_option): ?>
                    <option value="<?php echo esc_attr($view_value); ?>" <?php echo $view_filter === $view_value ? 'selected' : ''; ?>>
                        <?php echo esc_html($view_option['label']); ?>
                    </option>
                <?php endforeach; ?>
                </select>
            </div>
            <div class="inventory-filter-group inventory-search-group" style="flex: 2; min-width: 200px;">
                <h2 style="font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #475569;">Search</h2>
                <label class="inventory-search-field" style="margin: 0; width: 100%;">
                    <i class="fas fa-search"></i>
                    <input type="search"
                           name="search"
                           value="<?php echo esc_attr($search_filter); ?>"
                           placeholder="Search item, SKU, vehicle..."
                           style="height: 42px; border-radius: 8px; border-color: #cbd5e1;">
                </label>
            </div>
            <div class="inventory-filter-actions-group" style="display: flex; gap: 8px; align-items: center;">
                <button type="submit" class="btn btn-primary" style="height: 42px; padding: 0 18px; font-weight: 600; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="fas fa-filter"></i> Apply
                </button>
                <?php if ($category_filter !== 'all' || $brand_filter !== '' || $size_filter !== '' || $branch_filter !== 'all' || $view_filter !== 'all' || $search_filter !== ''): ?>
                    <a class="btn btn-outline-secondary"
                       href="<?php echo esc_attr(inventory_filter_url('all', 'all', '', $per_page, null, 'all')); ?>#inventory-records"
                       style="height: 42px; padding: 0 14px; font-weight: 600; border-radius: 8px; display: inline-flex; align-items: center;">
                        Reset
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const brandsByCategory = <?php echo json_encode($brands_by_category, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?> || {};
        const sizesByBrand = <?php echo json_encode($sizes_by_brand, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?> || {};
        const allBrands = <?php echo json_encode(array_values($all_brands), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?> || [];
        const allSizes = <?php echo json_encode(array_values($all_sizes), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?> || [];

        const catSelect = document.getElementById('adminCategoryFilter');
        const brandSelect = document.getElementById('adminBrandFilter');
        const sizeSelect = document.getElementById('adminSizeFilter');
        const brandGroup = document.getElementById('adminBrandGroup');
        const sizeGroup = document.getElementById('adminSizeGroup');

        if (!catSelect || !brandSelect || !sizeSelect) return;

        catSelect.addEventListener('change', function() {
            const cat = this.value;
            const currentBrand = brandSelect.value;

            if (cat === 'all') {
                if (brandGroup) brandGroup.style.display = 'none';
                if (sizeGroup) sizeGroup.style.display = 'none';
                brandSelect.value = '';
                sizeSelect.value = '';
                return;
            }

            if (brandGroup) brandGroup.style.display = '';
            if (sizeGroup) sizeGroup.style.display = '';

            let brands = (cat !== 'all' && brandsByCategory[cat]) ? Object.values(brandsByCategory[cat]) : allBrands;
            
            brandSelect.innerHTML = '<option value="">All Brands</option>';
            brands.forEach(function(b) {
                const opt = document.createElement('option');
                opt.value = b;
                opt.textContent = b;
                if (b === currentBrand) opt.selected = true;
                brandSelect.appendChild(opt);
            });

            brandSelect.dispatchEvent(new Event('change'));
        });

        brandSelect.addEventListener('change', function() {
            const brand = this.value;
            const currentSize = sizeSelect.value;
            let sizes = (brand && sizesByBrand[brand]) ? Object.values(sizesByBrand[brand]) : allSizes;

            sizeSelect.innerHTML = '<option value="">All Sizes</option>';
            sizes.forEach(function(s) {
                const opt = document.createElement('option');
                opt.value = s;
                opt.textContent = s;
                if (s === currentSize) opt.selected = true;
                sizeSelect.appendChild(opt);
            });
        });
    });
    </script>

    <details class="inventory-support-details inventory-low-stock-panel" id="low-stock-alerts">
        <summary class="inventory-support-summary">
            <span class="inventory-support-title">
                <i class="fas fa-exclamation"></i>
                <strong>Low Stock Alerts</strong>
            </span>
            <?php
            $admin_low_stock_count = count($low_stock_items);
            ?>
            <span class="inventory-support-count">
                <?php echo (int) $admin_low_stock_count; ?>
                <?php echo $admin_low_stock_count === 1 ? 'item' : 'items'; ?>
            </span>
        </summary>
        <div class="inventory-support-body">

        <?php if (empty($low_stock_items)): ?>
            <div class="inventory-empty-state">No low stock alerts for the selected filters.</div>
        <?php else: ?>
            <div class="inventory-alert-grid">
                <?php foreach ($low_stock_items as $item): ?>
                    <?php
                    $category = $item['category'] ?? 'part';
                    $detail = inventory_item_details($item);
                    $alert_branch_id = (int) ($item['branch_id'] ?? 0);
                    $alert_branch_name = inventory_branch_label($item['branch_name'] ?? ($alert_branch_id ? 'Branch ' . $alert_branch_id : 'Branch'));
                    ?>
                    <article class="inventory-alert-card">
                        <div class="inventory-alert-card-top">
                            <div>
                                <h3><?php echo esc_html(app_display_item_name($item['item_name'], $item['category'] ?? null)); ?></h3>
                                <p>
                                    <?php echo esc_html($item['brand'] ?: 'Unbranded'); ?>
                                    <?php if ($detail !== '-'): ?>
                                        &bull; <?php echo esc_html($detail); ?>
                                    <?php endif; ?>
                                </p>
                                <div class="inventory-alert-tags">
                                    <span class="inventory-category category-<?php echo esc_attr($category); ?>">
                                        <?php echo esc_html(inventory_category_label($category)); ?>
                                    </span>
                                    <span class="inventory-branch-pill inventory-branch-<?php echo $alert_branch_id; ?>">
                                        <?php echo esc_html($alert_branch_name); ?>
                                    </span>
                                </div>
                            </div>
                            <span class="inventory-low-badge">Low Stock</span>
                        </div>
                        <div class="inventory-alert-card-bottom">
                            <div>
                                <span>Current: <?php echo (int) $item['quantity']; ?></span>
                                <span>Reorder: <?php echo (int) $item['reorder_level']; ?></span>
                            </div>
                            <strong><?php echo inventory_money($item['unit_price'] ?? 0); ?></strong>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        </div>
    </details>

    <section class="inventory-table-card" id="inventory-records" aria-label="Inventory records">
        <?php if ($view_filter === 'last_month_sales'): ?>
            <div class="inventory-sales-subtabs">
                <a href="<?php echo esc_attr(inventory_filter_url($category_filter, $branch_filter, $search_filter, $per_page, 1, 'last_month_sales', 'all')); ?>#inventory-records"
                   class="inventory-sales-subtab <?php echo $sales_mode !== 'top10' ? 'active' : ''; ?>">
                    <i class="fas fa-list-ul"></i>
                    <span>All Sales Transactions (<?php echo (int) $total_records; ?>)</span>
                </a>
                <a href="<?php echo esc_attr(inventory_filter_url($category_filter, $branch_filter, $search_filter, $per_page, 1, 'last_month_sales', 'top10')); ?>#inventory-records"
                   class="inventory-sales-subtab <?php echo $sales_mode === 'top10' ? 'active' : ''; ?>">
                    <i class="fas fa-trophy"></i>
                    <span>Top 10 Best Sellers</span>
                </a>
            </div>
        <?php endif; ?>

        <div class="records-table-toolbar inventory-table-toolbar">
            <div>
                <h2>
                    <?php if ($view_filter === 'last_month_sales' && $sales_mode === 'top10'): ?>
                        Top 10 Best Selling Items
                    <?php else: ?>
                        <?php echo esc_html($records_heading); ?>
                    <?php endif; ?>
                </h2>
                <p>
                    <?php if ($view_filter === 'last_month_sales' && $sales_mode === 'top10'): ?>
                        Showing top 10 items ranked by sales quantity for <?php echo esc_html($last_month_label); ?>
                    <?php else: ?>
                        Showing <?php echo (int) $showing_from; ?>-<?php echo (int) $showing_to; ?>
                        of <?php echo (int) $total_records; ?> <?php echo esc_html($records_noun); ?>
                        <?php if ($view_filter === 'last_month_sales'): ?>
                            for <?php echo esc_html($last_month_label); ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
            </div>
            <?php if (!($view_filter === 'last_month_sales' && $sales_mode === 'top10')): ?>
            <form class="records-page-size-form" method="get" action="./#inventory-records">
                <?php if ($view_filter !== 'all'): ?>
                    <input type="hidden" name="view" value="<?php echo esc_attr($view_filter); ?>">
                <?php endif; ?>
                <?php if ($category_filter !== 'all'): ?>
                    <input type="hidden" name="category" value="<?php echo esc_attr($category_filter); ?>">
                <?php endif; ?>
                <?php if ($branch_filter !== 'all'): ?>
                    <input type="hidden" name="branch" value="<?php echo esc_attr($branch_filter); ?>">
                <?php endif; ?>
                <?php if ($search_filter !== ''): ?>
                    <input type="hidden" name="search" value="<?php echo esc_attr($search_filter); ?>">
                <?php endif; ?>
                <label>
                    <span>Rows per page</span>
                    <select name="per_page" onchange="this.form.submit()">
                        <?php foreach ($page_sizes as $size): ?>
                            <option value="<?php echo (int) $size; ?>" <?php echo $per_page === $size ? 'selected' : ''; ?>>
                                <?php echo (int) $size; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <?php if ($view_filter === 'last_month_sales' && $sales_mode === 'top10'): ?>
                <table class="inventory-table">
                    <thead>
                        <tr>
                            <th style="width: 70px; text-align: center;">Rank</th>
                            <th>Item Name</th>
                            <th>Category</th>
                            <th>Branch</th>
                            <th style="text-align: right;">Unit Price</th>
                            <th style="text-align: center;">Units Sold</th>
                            <th style="text-align: right;">Total Revenue</th>
                            <th style="text-align: center;">Sales Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($top_10_items)): ?>
                            <tr>
                                <td colspan="8" class="inventory-table-empty">No sales records found for <?php echo esc_html($last_month_label); ?>.</td>
                            </tr>
                        <?php else: ?>
                            <?php
                            $rank = 1;
                            $overall_units = max(1, (float) ($tx_summary['total_units'] ?? 1));
                            foreach ($top_10_items as $top_item_row):
                                $badge_class = $rank === 1 ? 'rank-gold' : ($rank === 2 ? 'rank-silver' : ($rank === 3 ? 'rank-bronze' : 'rank-normal'));
                                $item_detail = inventory_item_details($top_item_row);
                                $share_pct = round(((float) $top_item_row['total_sold_qty'] / $overall_units) * 100, 1);
                            ?>
                                <tr>
                                    <td style="text-align: center;">
                                        <span class="inventory-rank-badge <?php echo esc_attr($badge_class); ?>">
                                            <?php if ($rank === 1): ?><i class="fas fa-crown"></i><?php endif; ?>
                                            #<?php echo $rank; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html($top_item_row['item_name']); ?></strong>
                                        <span class="inventory-item-sub">
                                            <?php echo esc_html($top_item_row['brand'] ?: 'Unbranded'); ?>
                                            <?php if ($item_detail !== '-'): ?>
                                                &bull; <?php echo esc_html($item_detail); ?>
                                            <?php endif; ?>
                                            <?php if (!empty($top_item_row['sku'])): ?>
                                                &bull; SKU: <?php echo esc_html($top_item_row['sku']); ?>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="inventory-category category-<?php echo esc_attr($top_item_row['category']); ?>">
                                            <?php echo esc_html(inventory_category_label($top_item_row['category'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="inventory-branch-pill inventory-branch-<?php echo (int) $top_item_row['branch_id']; ?>">
                                            <?php echo esc_html(inventory_branch_label($top_item_row['branch_name'] ?? '')); ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        <strong><?php echo inventory_money($top_item_row['unit_price']); ?></strong>
                                    </td>
                                    <td style="text-align: center;">
                                        <strong style="font-size: 15px; color: #0096b6;"><?php echo number_format((float) $top_item_row['total_sold_qty']); ?> units</strong>
                                        <small style="display: block; font-size: 11px; color: var(--company-muted);">in <?php echo (int) $top_item_row['total_orders']; ?> orders</small>
                                    </td>
                                    <td style="text-align: right;">
                                        <strong style="font-size: 15px; color: #059669;"><?php echo inventory_money($top_item_row['total_sold_amount']); ?></strong>
                                    </td>
                                    <td style="text-align: center;">
                                        <span style="display: inline-block; padding: 2px 8px; border-radius: 999px; background: #e0f2fe; color: #0369a1; font-weight: 700; font-size: 12px;">
                                            <?php echo $share_pct; ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php
                                $rank++;
                            endforeach;
                            ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php elseif ($is_transaction_view): ?>
                <table class="inventory-table inventory-transactions-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Branch</th>
                            <th>Item</th>
                            <th>Type</th>
                            <th>Qty</th>
                            <th>Tagged To</th>
                            <th>Value</th>
                            <th>Entered By</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="9" class="inventory-table-empty"><?php echo esc_html($records_empty_message); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $transaction): ?>
                                <?php
                                $tagged_customer = trim((string) ($transaction['tagged_customer_name'] ?? ''));
                                $tagged_vehicle = inventory_transaction_vehicle_label($transaction);
                                $source_links = inventory_transaction_source_links($transaction);
                                $empty_tag_label = app_inventory_transaction_tag_empty_label($transaction['reference_type'] ?? '', $transaction['transaction_type'] ?? '');
                                $transaction_value = (float) ($transaction['unit_price'] ?? 0) * (int) ($transaction['quantity'] ?? 0);
                                ?>
                                <tr>
                                    <td><span class="inventory-transaction-date"><?php echo esc_html(format_date($transaction['created_at'], 'M d, Y h:i A')); ?></span></td>
                                    <td>
                                        <span class="inventory-branch-pill inventory-branch-<?php echo (int) $transaction['branch_id']; ?>">
                                            <?php echo esc_html(inventory_branch_label($transaction['branch_name'] ?? '-')); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html($transaction['item_name'] ?? '-'); ?></strong>
                                        <small><?php echo esc_html(trim(($transaction['brand'] ?? '') . (!empty($transaction['size']) ? ' (' . $transaction['size'] . ')' : ''))); ?></small>
                                    </td>
                                    <td>
                                        <span class="inventory-transaction-type <?php echo esc_attr(inventory_transaction_type_class($transaction['transaction_type'] ?? 'stock_out')); ?>">
                                            <?php echo esc_html(inventory_transaction_type_label($transaction['transaction_type'] ?? 'Stock Out')); ?>
                                        </span>
                                    </td>
                                    <td><strong class="inventory-quantity"><?php echo (int) $transaction['quantity']; ?></strong></td>
                                    <td>
                                        <?php if ($tagged_customer !== '' || $tagged_vehicle !== '' || !empty($source_links)): ?>
                                            <div class="inventory-tag-cell">
                                                <?php if ($tagged_customer !== ''): ?>
                                                    <strong><?php echo esc_html($tagged_customer); ?></strong>
                                                <?php endif; ?>
                                                <?php if ($tagged_vehicle !== ''): ?>
                                                    <small><i class="fas fa-car"></i><?php echo esc_html($tagged_vehicle); ?></small>
                                                <?php endif; ?>
                                                <?php if (!empty($source_links)): ?>
                                                    <span class="inventory-source-links">
                                                        <?php foreach ($source_links as $source_link): ?>
                                                            <a href="<?php echo esc_attr($source_link['href']); ?>" class="inventory-source-link" title="<?php echo esc_attr($source_link['title']); ?>">
                                                                <i class="<?php echo esc_attr($source_link['icon']); ?>"></i>
                                                                <?php echo esc_html($source_link['label']); ?>
                                                            </a>
                                                        <?php endforeach; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="inventory-tag-empty"><?php echo esc_html($empty_tag_label); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo inventory_money($transaction_value); ?></strong></td>
                                    <td><?php echo esc_html($transaction['user_name'] ?? 'System'); ?></td>
                                    <td class="inventory-transaction-notes"><?php echo esc_html($transaction['notes'] ?? '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <table class="inventory-table">
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th>Category</th>
                            <th>Product Details</th>
                            <th>Branch</th>
                            <th>Quantity</th>
                            <th>Reorder Level</th>
                            <th>Unit Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($inventory)): ?>
                            <tr>
                                <td colspan="7" class="inventory-table-empty"><?php echo esc_html($records_empty_message); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($inventory as $item): ?>
                                <?php
                                $is_low_stock = (int) $item['quantity'] <= (int) $item['reorder_level'];
                                $category = $item['category'] ?? 'part';
                                $branch_id = (int) ($item['branch_id'] ?? 0);
                                $branch_label = inventory_branch_label($item['branch_name'] ?? '');
                                $detail_lines = inventory_item_detail_lines($item);
                                ?>
                                <tr class="<?php echo $is_low_stock ? 'is-low-stock' : ''; ?>">
                                    <td>
                                        <strong><?php echo esc_html(app_display_item_name($item['item_name'], $item['category'] ?? null)); ?></strong>
                                        <small class="inventory-item-brand"><?php echo esc_html($item['brand'] ?: 'Unbranded'); ?></small>
                                    </td>
                                    <td>
                                        <span class="inventory-category category-<?php echo esc_attr($category); ?>">
                                            <?php echo esc_html(inventory_category_label($category)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="inventory-detail-list">
                                            <?php foreach ($detail_lines as $detail_line): ?>
                                                <span>
                                                    <strong><?php echo esc_html($detail_line['label']); ?>:</strong>
                                                    <?php echo esc_html($detail_line['value']); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="inventory-branch-pill inventory-branch-<?php echo $branch_id; ?>">
                                            <?php echo esc_html($branch_label); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong class="inventory-quantity <?php echo $is_low_stock ? 'is-low' : ''; ?>">
                                            <?php echo (int) $item['quantity']; ?>
                                            <?php if ($is_low_stock): ?>
                                                <i class="fas fa-arrow-trend-down"></i>
                                            <?php endif; ?>
                                        </strong>
                                        <?php if ($is_low_stock): ?>
                                            <span class="inventory-stock-status">Low Stock</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo (int) $item['reorder_level']; ?></td>
                                    <td><strong><?php echo inventory_money($item['unit_price'] ?? 0); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php if ($total_pages > 1 && !($view_filter === 'last_month_sales' && $sales_mode === 'top10')): ?>
            <nav class="records-pagination inventory-records-pagination" aria-label="Inventory records pages">
                <ul class="pagination justify-content-center">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo esc_attr(inventory_filter_url($category_filter, $branch_filter, $search_filter, $per_page, 1, $view_filter, $sales_mode)); ?>#inventory-records">First</a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo esc_attr(inventory_filter_url($category_filter, $branch_filter, $search_filter, $per_page, $page - 1, $view_filter, $sales_mode)); ?>#inventory-records">Previous</a>
                        </li>
                    <?php endif; ?>
                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo esc_attr(inventory_filter_url($category_filter, $branch_filter, $search_filter, $per_page, $i, $view_filter, $sales_mode)); ?>#inventory-records"><?php echo (int) $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo esc_attr(inventory_filter_url($category_filter, $branch_filter, $search_filter, $per_page, $page + 1, $view_filter, $sales_mode)); ?>#inventory-records">Next</a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo esc_attr(inventory_filter_url($category_filter, $branch_filter, $search_filter, $per_page, $total_pages, $view_filter, $sales_mode)); ?>#inventory-records">Last</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </section>
</main>

<div class="modal fade inventory-add-modal" id="addInventoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="/hwtires/api/inventory-api.php" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="redirect" value="<?php echo esc_attr($redirect_url); ?>">

            <div class="inventory-modal-header">
                <div>
                    <h2>Add Inventory Item</h2>
                    <p>Register a new stock record</p>
                </div>
                <button type="button" class="inventory-modal-close" data-bs-dismiss="modal" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="inventory-modal-body">
                <div class="inventory-form-grid">
                    <label>
                        <span>Item Name</span>
                        <input type="text" name="item_name" required>
                    </label>
                    <label>
                        <span>Branch</span>
                        <select name="branch_id" required>
                            <?php foreach ($inventory_branches as $branch): ?>
                                <option value="<?php echo (int) $branch['id']; ?>">
                                    <?php echo esc_html(inventory_branch_label($branch['name'])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span>Category</span>
                        <select name="category" required>
                            <option value="tire">Tire</option>
                            <option value="accessory">Accessory</option>
                            <option value="part">Part</option>
                        </select>
                    </label>
                    <label>
                        <span>Brand</span>
                        <input type="text" name="brand" placeholder="e.g., Bridgestone" required>
                    </label>
                    <label>
                        <span>Model</span>
                        <input type="text" name="model" placeholder="e.g., Turanza T005" required>
                    </label>
                    <label>
                        <span>Size</span>
                        <input type="text" name="size" placeholder="Size, fitment, or short detail" required>
                    </label>
                    <label>
                        <span>SKU</span>
                        <input type="text" name="sku" placeholder="e.g., LAC-TIR-0001" required>
                    </label>
                    <label>
                        <span>Serial Number</span>
                        <input type="text" name="serial_number" placeholder="e.g., HWT-2026-000001" required>
                    </label>
                    <label>
                        <span>Manufacturing Date</span>
                        <input type="date" name="manufacturing_date" max="<?php echo date('Y-m-d'); ?>" required>
                    </label>
                    <label>
                        <span>Unit Price</span>
                        <input type="number" name="unit_price" min="0.01" step="0.01" placeholder="0.00" required>
                    </label>
                    <label>
                        <span>Quantity</span>
                        <input type="number" name="quantity" min="0" value="0" required>
                    </label>
                    <label>
                        <span>Reorder Level</span>
                        <input type="number" name="reorder_level" min="1" value="5" required>
                    </label>
                </div>
                <label class="inventory-description-field">
                    <span>Description</span>
                    <textarea name="description" rows="2"></textarea>
                </label>
            </div>

            <div class="inventory-modal-footer">
                <button type="button" class="inventory-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="inventory-confirm-btn stock-in">Add Item</button>
            </div>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
