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
    function inventory_filter_url($category, $branch, $search = '', $per_page = null, $page = null, $view = 'all', $sales_mode = 'all', $brand = '', $size = '', $status = 'active') {
        $query = [];

        if ($view !== 'all') {
            $query['view'] = $view;
        }

        if ($view === 'last_month_sales' && $sales_mode === 'top10') {
            $query['sales_mode'] = 'top10';
        }

        if ($status !== 'active' && in_array($status, ['inactive', 'all'], true)) {
            $query['status'] = $status;
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

// Fetch distinct categories, brands, and sizes for dropdowns
$filter_meta_stmt = $pdo->query("
    SELECT DISTINCT category, brand, size 
    FROM inventory_items 
    WHERE status IN ('active', 'inactive')
    ORDER BY category ASC, brand ASC, size ASC
");
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

// Catalog brands and brand->models map for Add Inventory Item modal
$catalog_brands_stmt = $pdo->query("
    SELECT DISTINCT brand
    FROM inventory_items
    WHERE brand IS NOT NULL AND TRIM(brand) != ''
    ORDER BY brand ASC
");
$catalog_brands = $catalog_brands_stmt ? $catalog_brands_stmt->fetchAll(PDO::FETCH_COLUMN) : [];

$catalog_models_stmt = $pdo->query("
    SELECT DISTINCT brand, model
    FROM inventory_items
    WHERE brand IS NOT NULL AND TRIM(brand) != ''
      AND model IS NOT NULL AND TRIM(model) != ''
    ORDER BY brand ASC, model ASC
");
$raw_brand_models = $catalog_models_stmt ? $catalog_models_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
$brand_models_map = [];
foreach ($raw_brand_models as $bm_row) {
    $bm_brand = trim((string) $bm_row['brand']);
    $bm_model = trim((string) $bm_row['model']);
    if ($bm_brand !== '' && $bm_model !== '') {
        $brand_models_map[$bm_brand][] = $bm_model;
    }
}

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

$valid_statuses = ['active', 'inactive', 'all'];
$status_filter = strtolower(trim($_GET['status'] ?? 'active'));
if (!in_array($status_filter, $valid_statuses, true)) {
    $status_filter = 'active';
}

if ($status_filter === 'active') {
    $where = ["i.status = 'active'"];
} elseif ($status_filter === 'inactive') {
    $where = ["i.status = 'inactive'"];
} else {
    $where = ["i.status IN ('active', 'inactive')"];
}
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
    $has_incoming_table = app_table_exists('inventory_incoming_stock');
    $incoming_join = $has_incoming_table
        ? "LEFT JOIN (
            SELECT item_id, SUM(expected_quantity) AS pending_incoming_qty
            FROM inventory_incoming_stock
            WHERE status = 'pending'
            GROUP BY item_id
        ) inc ON inc.item_id = i.id"
        : "";
    $incoming_select = $has_incoming_table
        ? "COALESCE(inc.pending_incoming_qty, 0) AS pending_incoming_qty"
        : "0 AS pending_incoming_qty";

    $inventory_stmt = $pdo->prepare("
        SELECT i.*, b.name AS branch_name,
               $incoming_select
        FROM inventory_items i
        LEFT JOIN branches b ON b.id = i.branch_id
        $incoming_join
        WHERE $item_list_where_sql
        ORDER BY i.branch_id ASC, FIELD(i.category, 'tire', 'accessory', 'part'), i.item_name ASC
        LIMIT $per_page OFFSET $offset
    ");
    $inventory_stmt->execute($item_list_params);
    $inventory = $inventory_stmt->fetchAll();
}

$has_incoming_table = app_table_exists('inventory_incoming_stock');
$incoming_join = $has_incoming_table
    ? "LEFT JOIN (
        SELECT item_id, SUM(expected_quantity) AS pending_incoming_qty
        FROM inventory_incoming_stock
        WHERE status = 'pending'
        GROUP BY item_id
    ) inc ON inc.item_id = i.id"
    : "";
$incoming_select = $has_incoming_table
    ? "COALESCE(inc.pending_incoming_qty, 0) AS pending_incoming_qty"
    : "0 AS pending_incoming_qty";

$low_stock_stmt = $pdo->prepare("
    SELECT i.*, b.name AS branch_name,
           $incoming_select
    FROM inventory_items i
    LEFT JOIN branches b ON b.id = i.branch_id
    $incoming_join
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
            'value' => $top_item ? esc_html(app_display_item_name($top_item['item_name'], $top_item['category'] ?? null)) : 'None',
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
            MAX(i.category) AS category,
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
            'value' => $top_item ? esc_html(app_display_item_name($top_item['item_name'], $top_item['category'] ?? null)) : 'None',
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
            'value' => $top_item ? esc_html(app_display_item_name($top_item['item_name'], $top_item['category'] ?? null)) : 'None',
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
        SELECT i.item_name, i.category, i.quantity, i.reorder_level
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
            'value' => $lowest_item ? esc_html(app_display_item_name($lowest_item['item_name'], $lowest_item['category'] ?? null)) : 'None',
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

$added_id = intval($_GET['added_id'] ?? 0);
$active_filter_url = inventory_filter_url($category_filter, $branch_filter, $search_filter, $per_page, $page, $view_filter, $sales_mode, $brand_filter, $size_filter, $status_filter);
$redirect_url = '/hwtires/admin/tire-inventory/' . ($active_filter_url === './' ? '' : $active_filter_url) . '#inventory-records';
?>

<?php require_once '../../includes/header.php'; ?>
<?php require_once '../../includes/sidebar.php'; ?>

<style>
@keyframes pulseRowHighlight {
    0% { background-color: rgba(254, 240, 138, 0.95) !important; }
    40% { background-color: rgba(254, 240, 138, 0.65) !important; }
    100% { background-color: rgba(254, 240, 138, 0.25) !important; }
}
.table-row-highlight {
    animation: pulseRowHighlight 3s ease-out forwards;
    border-left: 4px solid #eab308 !important;
}
.inventory-incoming-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 7px;
    font-size: 11px;
    font-weight: 600;
    border-radius: 999px;
    background-color: #fef3c7;
    color: #92400e;
    border: 1px solid #fde68a;
    margin-top: 4px;
}
</style>

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
        <form class="inventory-unified-filter-form" method="get" action="./#inventory-records">
            <input type="hidden" name="per_page" value="<?php echo (int) $per_page; ?>">
            <div class="inventory-filter-group-wrap">
                <div class="inventory-filter-group">
                    <h2>Category</h2>
                    <select name="category" id="adminCategoryFilter" aria-label="Filter inventory category" class="form-select">
                    <?php foreach (['all' => 'All Items', 'tire' => 'Tires', 'accessory' => 'Accessories', 'part' => 'Parts'] as $category_value => $category_label): ?>
                        <option value="<?php echo esc_attr($category_value); ?>" <?php echo $category_filter === $category_value ? 'selected' : ''; ?>>
                            <?php echo esc_html($category_label); ?>
                        </option>
                    <?php endforeach; ?>
                    </select>
                </div>
                <div class="inventory-filter-group" id="adminBrandGroup" style="<?php echo ($category_filter === 'all' && $brand_filter === '') ? 'display: none;' : ''; ?>">
                    <h2>Brand</h2>
                    <select name="brand" id="adminBrandFilter" aria-label="Filter inventory brand" class="form-select">
                        <option value="">All Brands</option>
                        <?php foreach ($available_brands as $brand_name): ?>
                            <option value="<?php echo esc_attr($brand_name); ?>" <?php echo $brand_filter === $brand_name ? 'selected' : ''; ?>>
                                <?php echo esc_html($brand_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="inventory-filter-group" id="adminSizeGroup" style="<?php echo ($category_filter === 'all' && $size_filter === '') ? 'display: none;' : ''; ?>">
                    <h2>Size / Spec</h2>
                    <select name="size" id="adminSizeFilter" aria-label="Filter inventory size" class="form-select">
                        <option value="">All Sizes</option>
                        <?php foreach ($available_sizes as $size_val): ?>
                            <option value="<?php echo esc_attr($size_val); ?>" <?php echo $size_filter === $size_val ? 'selected' : ''; ?>>
                                <?php echo esc_html($size_val); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="inventory-filter-group">
                    <h2>Branch</h2>
                    <select name="branch" aria-label="Filter inventory branch" class="form-select">
                        <option value="all" <?php echo $branch_filter === 'all' ? 'selected' : ''; ?>>All Branches</option>
                    <?php foreach ($inventory_branches as $branch): ?>
                        <?php $branch_value = (string) (int) $branch['id']; ?>
                        <option value="<?php echo esc_attr($branch_value); ?>" <?php echo $branch_filter === $branch_value ? 'selected' : ''; ?>>
                            <?php echo esc_html(inventory_branch_label($branch['name'])); ?>
                        </option>
                    <?php endforeach; ?>
                    </select>
                </div>
                <div class="inventory-filter-group">
                    <h2>Status</h2>
                    <select name="status" aria-label="Filter inventory status" class="form-select">
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive / Archived</option>
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                    </select>
                </div>
                <div class="inventory-filter-group inventory-view-group">
                    <h2>Record View</h2>
                    <select name="view" aria-label="Select inventory record view" class="form-select">
                    <?php foreach ($inventory_view_options as $view_value => $view_option): ?>
                        <option value="<?php echo esc_attr($view_value); ?>" <?php echo $view_filter === $view_value ? 'selected' : ''; ?>>
                            <?php echo esc_html($view_option['label']); ?>
                        </option>
                    <?php endforeach; ?>
                    </select>
                </div>
                <div class="inventory-filter-actions-group">
                    <button type="submit" class="btn btn-primary inventory-filter-apply-btn">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <?php if ($category_filter !== 'all' || $brand_filter !== '' || $size_filter !== '' || $branch_filter !== 'all' || $view_filter !== 'all' || $search_filter !== '' || $status_filter !== 'active'): ?>
                        <a class="btn btn-outline-secondary inventory-filter-reset-btn"
                           href="<?php echo esc_attr(inventory_filter_url('all', 'all', '', $per_page, null, 'all', 'all', '', '', 'active')); ?>#inventory-records">
                            Reset
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="inventory-search-group">
                <h2>Search</h2>
                <div class="inventory-search-input-wrap">
                    <label class="inventory-search-field">
                        <i class="fas fa-search"></i>
                        <input type="search"
                               name="search"
                               maxlength="100"
                               data-text-format="first-letter"
                               value="<?php echo esc_attr($search_filter); ?>"
                               placeholder="Search item, SKU, vehicle...">
                    </label>
                    <button type="submit" class="btn btn-secondary inventory-search-btn">
                        <i class="fas fa-search"></i> Search
                    </button>
                </div>
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
                                <?php if (!empty($item['pending_incoming_qty']) && (int) $item['pending_incoming_qty'] > 0): ?>
                                    <span class="inventory-incoming-badge ms-1" title="Incoming shipment pending arrival at branch">
                                        <i class="fas fa-truck-ramp-box"></i> Incoming: <?php echo (int) $item['pending_incoming_qty']; ?> (Pending)
                                    </span>
                                <?php endif; ?>
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
                <?php if ($brand_filter !== ''): ?>
                    <input type="hidden" name="brand" value="<?php echo esc_attr($brand_filter); ?>">
                <?php endif; ?>
                <?php if ($size_filter !== ''): ?>
                    <input type="hidden" name="size" value="<?php echo esc_attr($size_filter); ?>">
                <?php endif; ?>
                <?php if ($branch_filter !== 'all'): ?>
                    <input type="hidden" name="branch" value="<?php echo esc_attr($branch_filter); ?>">
                <?php endif; ?>
                <?php if ($status_filter !== 'active'): ?>
                    <input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>">
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
                                        <strong><?php echo esc_html(app_display_item_name($top_item_row['item_name'], $top_item_row['category'] ?? null)); ?></strong>
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
                                    <td><span class="inventory-transaction-date"><?php echo esc_html(app_format_datetime_pht($transaction['created_at'])); ?></span></td>
                                    <td>
                                        <span class="inventory-branch-pill inventory-branch-<?php echo (int) $transaction['branch_id']; ?>">
                                            <?php echo esc_html(inventory_branch_label($transaction['branch_name'] ?? '-')); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html(app_display_item_name($transaction['item_name'] ?? '-', $transaction['category'] ?? null)); ?></strong>
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
                            <th>Status</th>
                            <th>Quantity</th>
                            <th>Reorder Level</th>
                            <th>Unit Price</th>
                            <th style="text-align: center; width: 170px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($inventory)): ?>
                            <tr>
                                <td colspan="9" class="inventory-table-empty"><?php echo esc_html($records_empty_message); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($inventory as $item): ?>
                                <?php
                                $item_status = strtolower(trim((string) ($item['status'] ?? 'active')));
                                $is_archived = ($item_status === 'inactive' || $item_status === 'discontinued');
                                $is_low_stock = !$is_archived && ((int) $item['quantity'] <= (int) $item['reorder_level']);
                                $category = $item['category'] ?? 'part';
                                $branch_id = (int) ($item['branch_id'] ?? 0);
                                $branch_label = inventory_branch_label($item['branch_name'] ?? '');
                                $detail_lines = inventory_item_detail_lines($item);
                                $is_highlighted = ($added_id > 0 && (int)$item['id'] === $added_id);
                                $pending_incoming = (int) ($item['pending_incoming_qty'] ?? 0);
                                ?>
                                <tr id="inventory-row-<?php echo (int) $item['id']; ?>"
                                    data-item-id="<?php echo (int) $item['id']; ?>"
                                    class="<?php echo $is_highlighted ? 'table-row-highlight ' : ''; ?><?php echo $is_low_stock ? 'is-low-stock' : ($is_archived ? 'table-light text-muted' : ''); ?>">
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
                                        <?php if ($is_archived): ?>
                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1" style="font-size: 11.5px; font-weight: 600;">
                                                <i class="fas fa-box-archive me-1"></i>Archived
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1" style="font-size: 11.5px; font-weight: 600;">
                                                <i class="fas fa-check-circle me-1"></i>Active
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-column align-items-start gap-1">
                                            <div>
                                                <strong class="inventory-quantity <?php echo $is_low_stock ? 'is-low' : ''; ?>">
                                                    <?php echo (int) $item['quantity']; ?>
                                                    <?php if ($is_low_stock): ?>
                                                        <i class="fas fa-arrow-trend-down"></i>
                                                    <?php endif; ?>
                                                </strong>
                                                <?php if ($is_low_stock): ?>
                                                    <span class="inventory-stock-status">Low Stock</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($pending_incoming > 0): ?>
                                                <span class="inventory-incoming-badge" title="Incoming shipment pending physical arrival at branch">
                                                    <i class="fas fa-truck-ramp-box"></i> Incoming: <?php echo $pending_incoming; ?> (Pending Arrival)
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?php echo (int) $item['reorder_level']; ?></td>
                                    <td><strong><?php echo inventory_money($item['unit_price'] ?? 0); ?></strong></td>
                                    <td style="text-align: center;">
                                        <div style="display: inline-flex; gap: 6px; justify-content: center; align-items: center; flex-wrap: wrap;">
                                        <?php if (!$is_archived): ?>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-primary js-adjust-stock-btn"
                                                    data-item-id="<?php echo (int) $item['id']; ?>"
                                                    data-item-name="<?php echo esc_attr(app_display_item_name($item['item_name'], $category)); ?>"
                                                    data-brand="<?php echo esc_attr($item['brand'] ?: 'Unbranded'); ?>"
                                                    data-size="<?php echo esc_attr($item['size'] ?: ''); ?>"
                                                    data-category="<?php echo esc_attr($category); ?>"
                                                    data-branch-id="<?php echo $branch_id; ?>"
                                                    data-branch-name="<?php echo esc_attr($branch_label); ?>"
                                                    data-current-qty="<?php echo (int) $item['quantity']; ?>"
                                                    title="Correct stock quantity based on physical count">
                                                <i class="fas fa-sliders me-1"></i> Correct Stock
                                            </button>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-danger js-archive-item-btn"
                                                    data-item-id="<?php echo (int) $item['id']; ?>"
                                                    data-item-name="<?php echo esc_attr(app_display_item_name($item['item_name'], $category)); ?>"
                                                    data-brand="<?php echo esc_attr($item['brand'] ?: 'Unbranded'); ?>"
                                                    data-size="<?php echo esc_attr($item['size'] ?: ''); ?>"
                                                    data-category="<?php echo esc_attr($category); ?>"
                                                    data-branch-name="<?php echo esc_attr($branch_label); ?>"
                                                    data-current-qty="<?php echo (int) $item['quantity']; ?>"
                                                    title="Archive / Deactivate inventory item">
                                                <i class="fas fa-box-archive me-1"></i> Archive
                                            </button>
                                        <?php else: ?>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-success js-restore-item-btn"
                                                    data-item-id="<?php echo (int) $item['id']; ?>"
                                                    data-item-name="<?php echo esc_attr(app_display_item_name($item['item_name'], $category)); ?>"
                                                    data-brand="<?php echo esc_attr($item['brand'] ?: 'Unbranded'); ?>"
                                                    data-size="<?php echo esc_attr($item['size'] ?: ''); ?>"
                                                    data-category="<?php echo esc_attr($category); ?>"
                                                    data-branch-name="<?php echo esc_attr($branch_label); ?>"
                                                    data-current-qty="<?php echo (int) $item['quantity']; ?>"
                                                    title="Reactivate / Restore inventory item">
                                                <i class="fas fa-rotate-left me-1"></i> Reactivate
                                            </button>
                                        <?php endif; ?>
                                        </div>
                                    </td>
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
                            <a class="page-link" href="<?php echo esc_attr(inventory_filter_url($category_filter, $branch_filter, $search_filter, $per_page, 1, $view_filter, $sales_mode, $brand_filter, $size_filter, $status_filter)); ?>#inventory-records">First</a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo esc_attr(inventory_filter_url($category_filter, $branch_filter, $search_filter, $per_page, $page - 1, $view_filter, $sales_mode, $brand_filter, $size_filter, $status_filter)); ?>#inventory-records">Previous</a>
                        </li>
                    <?php endif; ?>
                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo esc_attr(inventory_filter_url($category_filter, $branch_filter, $search_filter, $per_page, $i, $view_filter, $sales_mode, $brand_filter, $size_filter, $status_filter)); ?>#inventory-records"><?php echo (int) $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo esc_attr(inventory_filter_url($category_filter, $branch_filter, $search_filter, $per_page, $page + 1, $view_filter, $sales_mode, $brand_filter, $size_filter, $status_filter)); ?>#inventory-records">Next</a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo esc_attr(inventory_filter_url($category_filter, $branch_filter, $search_filter, $per_page, $total_pages, $view_filter, $sales_mode, $brand_filter, $size_filter, $status_filter)); ?>#inventory-records">Last</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </section>
</main>

<div class="modal fade inventory-add-modal" id="addInventoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" action="/hwtires/api/inventory-api.php" class="modal-content" id="addInventoryForm">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="redirect" value="<?php echo esc_attr($redirect_url); ?>">

            <div class="inventory-modal-header">
                <div>
                    <h2>Add Inventory Item</h2>
                    <p>Register a new product definition for active inventory</p>
                </div>
                <button type="button" class="inventory-modal-close" data-bs-dismiss="modal" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="inventory-modal-body">
                <div class="mb-3">
                    <h6 class="fw-bold text-dark mb-2 pb-1 border-bottom d-flex align-items-center gap-2">
                        <i class="fas fa-box text-primary"></i> Product Details
                    </h6>
                    <div class="inventory-form-grid">
                        <label>
                            <span>Item Name <span class="text-danger">*</span></span>
                            <input type="text" name="item_name" id="add_item_name" maxlength="255" data-text-format="first-letter" placeholder="e.g., Bridgestone Turanza T005" required>
                        </label>
                        <label>
                            <span>Branch <span class="text-danger">*</span></span>
                            <select name="branch_id" id="add_branch_id" required>
                                <?php foreach ($inventory_branches as $branch): ?>
                                    <option value="<?php echo (int) $branch['id']; ?>">
                                        <?php echo esc_html(inventory_branch_label($branch['name'])); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span>Category <span class="text-danger">*</span></span>
                            <select name="category" id="add_category" required>
                                <option value="tire">Tire</option>
                                <option value="accessory">Accessory</option>
                                <option value="part">Part</option>
                            </select>
                        </label>
                        <label>
                            <span>Brand <span class="text-danger">*</span></span>
                            <select name="brand" id="add_brand_select" class="inventory-brand-select" required>
                                <option value="">-- Select Brand --</option>
                                <?php foreach ($catalog_brands as $cat_brand): ?>
                                    <option value="<?php echo esc_attr($cat_brand); ?>"><?php echo esc_html($cat_brand); ?></option>
                                <?php endforeach; ?>
                                <option value="Other">Other / Custom Brand...</option>
                            </select>
                            <input type="text" name="brand_custom" id="add_brand_custom" class="inventory-brand-custom mt-2 d-none" maxlength="100" data-text-format="first-letter" placeholder="Enter custom brand">
                        </label>
                        <label>
                            <span>Model <span class="text-danger" id="add_model_required_mark">*</span></span>
                            <select name="model" id="add_model_select" class="inventory-model-select" required>
                                <option value="">-- Select Brand First --</option>
                                <option value="Other">Other / Custom Model...</option>
                            </select>
                            <input type="text" name="model_custom" id="add_model_custom" class="inventory-model-custom mt-2 d-none" maxlength="100" data-text-format="first-letter" placeholder="Enter custom model">
                        </label>
                        <label>
                            <span>Size / Fitment <span class="text-danger" id="add_size_required_mark">*</span></span>
                            <input type="text" name="size" id="add_size" maxlength="80" placeholder="e.g., 205/55R16 or Fitment Spec" required>
                        </label>
                        <label>
                            <span>SKU <span class="text-danger">*</span></span>
                            <input type="text" name="sku" id="add_sku" maxlength="100" placeholder="e.g., LAC-TIR-0001" required>
                        </label>
                        <label>
                            <span>Unit Price (₱) <span class="text-danger">*</span></span>
                            <input type="number" name="unit_price" id="add_unit_price" min="0.01" step="0.01" placeholder="0.00" required>
                        </label>
                        <label>
                            <span>Reorder Level <span class="text-danger">*</span></span>
                            <input type="number" name="reorder_level" id="add_reorder_level" min="1" value="5" required>
                        </label>
                        <label>
                            <span>Serial Number <small class="text-muted fw-normal">(Optional)</small></span>
                            <input type="text" name="serial_number" id="add_serial_number" maxlength="120" placeholder="e.g., HWT-2026-000001">
                        </label>
                        <label>
                            <span>Manufacturing Date <small class="text-muted fw-normal">(Optional)</small></span>
                            <input type="date" name="manufacturing_date" id="add_manufacturing_date" max="<?php echo date('Y-m-d'); ?>">
                        </label>
                    </div>
                    <label class="inventory-description-field mt-3">
                        <span>Description <small class="text-muted fw-normal">(Optional)</small></span>
                        <textarea name="description" id="add_description" rows="2" maxlength="1000" data-text-format="first-letter" placeholder="Optional item description or technical specs"></textarea>
                    </label>
                </div>

                <!-- Initial Delivery Section (Optional) -->
                <div class="mt-4 p-3 rounded-3 border bg-light">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <input class="form-check-input mt-0" type="checkbox" name="schedule_delivery" id="scheduleDeliveryToggle" value="1" style="width: 18px; height: 18px; cursor: pointer;">
                            <label class="form-check-label fw-bold text-dark mb-0" for="scheduleDeliveryToggle" style="cursor: pointer;">
                                <i class="fas fa-truck text-primary me-1"></i> Schedule Initial Delivery (Pending Arrival)
                            </label>
                        </div>
                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1" style="font-size: 11px;">
                            Available stock: 0
                        </span>
                    </div>
                    <div class="small text-muted mt-1 ms-4">
                        Newly registered products always start with <strong>0 available stock</strong>. Check this box to schedule an incoming shipment that Front Desk will receive upon physical arrival.
                    </div>

                    <div id="deliveryFieldsContainer" class="d-none mt-3 pt-3 border-top">
                        <div class="inventory-form-grid">
                            <label>
                                <span>Expected Quantity <span class="text-danger">*</span></span>
                                <input type="number" name="expected_quantity" id="add_expected_quantity" min="1" max="100000" placeholder="e.g., 20">
                            </label>
                            <label>
                                <span>Supply Source <span class="text-danger">*</span></span>
                                <select name="source_type" id="add_source_type">
                                    <option value="supplier_delivery">Direct Supplier Delivery</option>
                                    <option value="tangub_warehouse">Central Warehouse (Tangub Hub)</option>
                                    <option value="sancarlos_warehouse">Auxiliary Warehouse (San Carlos Hub)</option>
                                    <option value="other">Other / Custom Source</option>
                                </select>
                            </label>
                            <label id="deliverySupplierNameGroup">
                                <span id="deliverySupplierNameLabel">Supplier Name <span class="text-danger">*</span></span>
                                <input type="text" name="supplier_name" id="add_supplier_name" maxlength="150" data-text-format="first-letter" placeholder="e.g., Yokohama Philippines">
                            </label>
                            <label id="deliveryReferenceGroup">
                                <span id="deliveryReferenceLabel">DR / Invoice #</span>
                                <input type="text" name="reference_number" id="add_reference_number" maxlength="100" placeholder="e.g., DR-2026-0891 or Invoice #">
                            </label>
                            <label>
                                <span>Expected Arrival Date</span>
                                <input type="date" name="expected_arrival_date" id="add_expected_arrival_date">
                            </label>
                        </div>
                        <label class="inventory-description-field mt-3">
                            <span>Delivery Notes / Instructions <small class="text-muted fw-normal">(Optional)</small></span>
                            <textarea name="delivery_notes" id="add_delivery_notes" rows="2" maxlength="1000" placeholder="Optional notes for receiving branch staff..."></textarea>
                        </label>
                    </div>
                </div>
            </div>

            <div class="inventory-modal-footer">
                <button type="button" class="inventory-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="inventory-confirm-btn stock-in" id="addInventorySubmitBtn">
                    <i class="fas fa-plus-circle me-1"></i> Register Product
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Adjust Stock Modal -->
<div class="modal fade" id="adjustStockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="/hwtires/api/inventory-api.php" class="modal-content" id="adjustStockForm">
            <input type="hidden" name="csrf_token" value="<?php echo esc_attr(get_csrf_token()); ?>">
            <input type="hidden" name="action" value="adjust_stock">
            <input type="hidden" name="inventory_id" id="adjustItemId" value="">

            <div class="modal-header border-bottom">
                <h5 class="modal-title fw-bold text-dark">
                    <i class="fas fa-sliders text-primary me-2"></i>Correct Stock Quantity
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-4">
                <!-- Item Summary Card -->
                <div class="bg-light p-3 rounded-3 border mb-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="fw-bold mb-1 text-dark" id="adjustItemName">-</h6>
                            <div class="text-muted small" id="adjustItemMeta">-</div>
                        </div>
                        <span class="badge bg-secondary px-2 py-1" id="adjustBranchName">-</span>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-secondary mb-1">Current System Stock</label>
                        <div class="form-control-plaintext fs-5 fw-bold text-dark px-2 bg-light rounded border text-center" id="adjustCurrentQty">
                            0 units
                        </div>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-secondary mb-1">Actual Physical Count <span class="text-danger">*</span></label>
                        <input type="number" 
                               name="physical_quantity" 
                               id="adjustPhysicalQty" 
                               class="form-control form-control-lg fw-bold text-center border-primary" 
                               min="0" 
                               max="100000" 
                               required 
                               placeholder="e.g., 18">
                    </div>
                </div>

                <!-- Difference Preview Alert -->
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary mb-1">Stock Adjustment Preview</label>
                    <div class="p-2 rounded border text-center fw-bold" id="adjustDiffPreview" style="background: #f8fafc;">
                        <span class="text-muted">Enter actual physical count above</span>
                    </div>
                </div>

                <!-- Reason Category -->
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary mb-1">Reason Category <span class="text-danger">*</span></label>
                    <select name="reason_category" id="adjustReasonCategory" class="form-select" required>
                        <option value="">-- Select Reason Category --</option>
                        <option value="physical_count">Physical Count Discrepancy / Recount</option>
                        <option value="damaged_stock">Damaged / Defective Stock Found</option>
                        <option value="missing_stock">Missing / Unaccounted Stock</option>
                        <option value="encoding_error">Data Entry / Encoding Correction</option>
                        <option value="found_stock">Found Unrecorded Stock</option>
                        <option value="other">Other Inventory Adjustment</option>
                    </select>
                </div>

                <!-- Mandatory Remarks -->
                <div class="mb-2">
                    <label class="form-label small fw-semibold text-secondary mb-1">Remarks / Details <span class="text-danger">*</span></label>
                    <textarea name="remarks" 
                              id="adjustRemarks" 
                              class="form-control" 
                              rows="2" 
                              maxlength="500"
                              data-text-format="first-letter"
                              required 
                              placeholder="Describe why the count is being corrected (e.g. physical count reconciliation, found 2 damaged units)..."></textarea>
                </div>
            </div>

            <div class="modal-footer border-top bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="adjustSubmitBtn">
                    <i class="fas fa-check-circle me-1"></i> Confirm Adjustment
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Archive Item Modal -->
<div class="modal fade" id="archiveItemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="/hwtires/api/inventory-api.php" class="modal-content" id="archiveItemForm">
            <input type="hidden" name="csrf_token" value="<?php echo esc_attr(get_csrf_token()); ?>">
            <input type="hidden" name="action" value="archive">
            <input type="hidden" name="inventory_id" id="archiveItemId" value="">

            <div class="modal-header border-bottom">
                <h5 class="modal-title fw-bold text-dark">
                    <i class="fas fa-box-archive text-danger me-2"></i>Archive Inventory Item
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-4">
                <!-- Item Summary Card -->
                <div class="bg-light p-3 rounded-3 border mb-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="fw-bold mb-1 text-dark" id="archiveItemName">-</h6>
                            <div class="text-muted small" id="archiveItemMeta">-</div>
                        </div>
                        <span class="badge bg-secondary px-2 py-1" id="archiveBranchName">-</span>
                    </div>
                </div>

                <!-- Positive Physical Stock Warning (Shown dynamically if qty > 0) -->
                <div class="alert alert-warning border-warning d-none" id="archiveStockWarning" role="alert">
                    <div class="d-flex gap-2">
                        <i class="fas fa-triangle-exclamation text-warning mt-1 fs-5"></i>
                        <div class="small">
                            <strong>Remaining Stock Notice:</strong>
                            <p class="mb-0 mt-1">
                                This item currently has <strong id="archiveWarningQty">0 units</strong> in physical stock.
                                Archiving this item will deactivate it from active counter lookups and future quotations, but <strong>will preserve all historical records, physical stock count, and transaction logs</strong>.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Archive Reason (Required) -->
                <div class="mb-2">
                    <label class="form-label small fw-semibold text-secondary mb-1">Reason for Archiving <span class="text-danger">*</span></label>
                    <textarea name="archive_reason" 
                              id="archiveReason" 
                              class="form-control" 
                              rows="3" 
                              maxlength="500"
                              data-text-format="first-letter"
                              required 
                              placeholder="Please state why this inventory item is being archived / deactivated (e.g., discontinued product, supplier phase-out)..."></textarea>
                    <div class="form-text small text-muted">A valid reason is required for the audit trail.</div>
                </div>
            </div>

            <div class="modal-footer border-top bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger" id="archiveSubmitBtn">
                    <i class="fas fa-box-archive me-1"></i> Confirm Archive
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Restore / Reactivate Item Modal -->
<div class="modal fade" id="restoreItemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="/hwtires/api/inventory-api.php" class="modal-content" id="restoreItemForm">
            <input type="hidden" name="csrf_token" value="<?php echo esc_attr(get_csrf_token()); ?>">
            <input type="hidden" name="action" value="restore">
            <input type="hidden" name="inventory_id" id="restoreItemId" value="">

            <div class="modal-header border-bottom">
                <h5 class="modal-title fw-bold text-dark">
                    <i class="fas fa-rotate-left text-success me-2"></i>Reactivate Inventory Item
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-4">
                <!-- Item Summary Card -->
                <div class="bg-light p-3 rounded-3 border mb-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="fw-bold mb-1 text-dark" id="restoreItemName">-</h6>
                            <div class="text-muted small" id="restoreItemMeta">-</div>
                        </div>
                        <span class="badge bg-secondary px-2 py-1" id="restoreBranchName">-</span>
                    </div>
                </div>

                <div class="alert alert-info border-info" role="alert">
                    <div class="d-flex gap-2">
                        <i class="fas fa-info-circle text-info mt-1 fs-5"></i>
                        <div class="small">
                            <strong>Reactivation Notice:</strong>
                            <p class="mb-0 mt-1">
                                Reactivating this item will return its status to <strong>Active</strong>. It will immediately reappear in active counter lookups and future Quotation item selection.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer border-top bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success" id="restoreSubmitBtn">
                    <i class="fas fa-rotate-left me-1"></i> Confirm Reactivation
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const adjustModalEl = document.getElementById('adjustStockModal');
    const adjustModal = adjustModalEl ? new bootstrap.Modal(adjustModalEl) : null;
    const adjustForm = document.getElementById('adjustStockForm');
    const adjustPhysicalInput = document.getElementById('adjustPhysicalQty');
    const adjustDiffPreview = document.getElementById('adjustDiffPreview');
    const adjustSubmitBtn = document.getElementById('adjustSubmitBtn');
    let currentItemQty = 0;

    function updateDiffPreview() {
        if (!adjustPhysicalInput || !adjustDiffPreview) return;
        const val = adjustPhysicalInput.value.trim();
        if (val === '' || isNaN(val)) {
            adjustDiffPreview.className = 'p-2 rounded border text-center fw-bold bg-light text-muted';
            adjustDiffPreview.innerHTML = '<span class="text-muted">Enter actual physical count above</span>';
            return;
        }

        const newQty = parseInt(val, 10);
        if (newQty < 0) {
            adjustDiffPreview.className = 'p-2 rounded border text-center fw-bold bg-danger-subtle text-danger border-danger-subtle';
            adjustDiffPreview.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i> Count cannot be negative';
            return;
        }

        const diff = newQty - currentItemQty;
        if (diff === 0) {
            adjustDiffPreview.className = 'p-2 rounded border text-center fw-bold bg-secondary-subtle text-secondary border-secondary-subtle';
            adjustDiffPreview.innerHTML = '<i class="fas fa-info-circle me-1"></i> 0 units (No Change - Count matches system)';
        } else if (diff < 0) {
            adjustDiffPreview.className = 'p-2 rounded border text-center fw-bold bg-danger-subtle text-danger border-danger-subtle';
            adjustDiffPreview.innerHTML = '<i class="fas fa-arrow-trend-down me-1"></i> ' + diff + ' units (Reduction of ' + Math.abs(diff) + ' units)';
        } else {
            adjustDiffPreview.className = 'p-2 rounded border text-center fw-bold bg-success-subtle text-success border-success-subtle';
            adjustDiffPreview.innerHTML = '<i class="fas fa-arrow-trend-up me-1"></i> +' + diff + ' units (Addition of ' + diff + ' units)';
        }
    }

    if (adjustPhysicalInput) {
        adjustPhysicalInput.addEventListener('input', updateDiffPreview);
    }

    document.querySelectorAll('.js-adjust-stock-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const itemId = this.dataset.itemId || '';
            const itemName = this.dataset.itemName || '';
            const brand = this.dataset.brand || '';
            const size = this.dataset.size || '';
            const branchName = this.dataset.branchName || '';
            currentItemQty = parseInt(this.dataset.currentQty || '0', 10);

            document.getElementById('adjustItemId').value = itemId;
            document.getElementById('adjustItemName').textContent = itemName;
            document.getElementById('adjustItemMeta').textContent = [brand, size].filter(Boolean).join(' • ');
            document.getElementById('adjustBranchName').textContent = branchName;
            document.getElementById('adjustCurrentQty').textContent = currentItemQty + ' units';
            document.getElementById('adjustPhysicalQty').value = '';
            document.getElementById('adjustReasonCategory').value = '';
            document.getElementById('adjustRemarks').value = '';

            updateDiffPreview();

            if (adjustModal) {
                adjustModal.show();
                setTimeout(function() {
                    if (adjustPhysicalInput) adjustPhysicalInput.focus();
                }, 300);
            }
        });
    });

    if (adjustForm) {
        adjustForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const itemId = document.getElementById('adjustItemId').value;
            const physicalVal = adjustPhysicalInput.value.trim();
            const reasonCat = document.getElementById('adjustReasonCategory').value;
            const remarks = document.getElementById('adjustRemarks').value.trim();

            if (!itemId) {
                alert('Invalid inventory item.');
                return;
            }

            if (physicalVal === '' || isNaN(physicalVal) || parseInt(physicalVal, 10) < 0) {
                alert('Please enter a valid non-negative physical count.');
                adjustPhysicalInput.focus();
                return;
            }

            const newQty = parseInt(physicalVal, 10);
            if (newQty === currentItemQty) {
                alert('Physical count matches current system quantity (' + currentItemQty + '). No adjustment needed.');
                return;
            }

            if (!reasonCat) {
                alert('Please select a reason category.');
                document.getElementById('adjustReasonCategory').focus();
                return;
            }

            if (!remarks) {
                alert('Please provide remarks/details for this stock correction.');
                document.getElementById('adjustRemarks').focus();
                return;
            }

            adjustSubmitBtn.disabled = true;
            adjustSubmitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving...';

            try {
                const body = new URLSearchParams();
                body.append('csrf_token', adjustForm.querySelector('input[name="csrf_token"]').value);
                body.append('action', 'adjust_stock');
                body.append('inventory_id', itemId);
                body.append('physical_quantity', newQty);
                body.append('reason_category', reasonCat);
                body.append('remarks', remarks);

                const response = await fetch('/hwtires/api/inventory-api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                });

                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Unable to adjust stock.');
                }

                if (adjustModal) adjustModal.hide();
                window.location.reload();
            } catch (err) {
                alert(err.message || 'An error occurred while saving the stock adjustment.');
                adjustSubmitBtn.disabled = false;
                adjustSubmitBtn.innerHTML = '<i class="fas fa-check-circle me-1"></i> Confirm Adjustment';
            }
        });
    }

    // Archive Modal & Form Handling
    const archiveModalEl = document.getElementById('archiveItemModal');
    const archiveModal = archiveModalEl ? new bootstrap.Modal(archiveModalEl) : null;
    const archiveForm = document.getElementById('archiveItemForm');
    const archiveReasonInput = document.getElementById('archiveReason');
    const archiveSubmitBtn = document.getElementById('archiveSubmitBtn');
    const archiveStockWarning = document.getElementById('archiveStockWarning');
    const archiveWarningQty = document.getElementById('archiveWarningQty');

    document.querySelectorAll('.js-archive-item-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const itemId = this.dataset.itemId || '';
            const itemName = this.dataset.itemName || '';
            const brand = this.dataset.brand || '';
            const size = this.dataset.size || '';
            const branchName = this.dataset.branchName || '';
            const currentQty = parseInt(this.dataset.currentQty || '0', 10);

            document.getElementById('archiveItemId').value = itemId;
            document.getElementById('archiveItemName').textContent = itemName;
            document.getElementById('archiveItemMeta').textContent = [brand, size].filter(Boolean).join(' • ');
            document.getElementById('archiveBranchName').textContent = branchName;
            if (archiveReasonInput) archiveReasonInput.value = '';

            if (archiveStockWarning && archiveWarningQty) {
                if (currentQty > 0) {
                    archiveWarningQty.textContent = currentQty + ' unit' + (currentQty === 1 ? '' : 's');
                    archiveStockWarning.classList.remove('d-none');
                } else {
                    archiveStockWarning.classList.add('d-none');
                }
            }

            if (archiveModal) {
                archiveModal.show();
                setTimeout(function() {
                    if (archiveReasonInput) archiveReasonInput.focus();
                }, 300);
            }
        });
    });

    if (archiveForm) {
        archiveForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const itemId = document.getElementById('archiveItemId').value;
            const reason = archiveReasonInput ? archiveReasonInput.value.trim() : '';

            if (!itemId) {
                alert('Invalid inventory item.');
                return;
            }

            if (!reason) {
                alert('Please provide a reason for archiving this item.');
                if (archiveReasonInput) archiveReasonInput.focus();
                return;
            }

            archiveSubmitBtn.disabled = true;
            archiveSubmitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Archiving...';

            try {
                const body = new URLSearchParams();
                body.append('csrf_token', archiveForm.querySelector('input[name="csrf_token"]').value);
                body.append('action', 'archive');
                body.append('inventory_id', itemId);
                body.append('archive_reason', reason);

                const response = await fetch('/hwtires/api/inventory-api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                });

                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Unable to archive inventory item.');
                }

                if (archiveModal) archiveModal.hide();
                window.location.reload();
            } catch (err) {
                alert(err.message || 'An error occurred while archiving the inventory item.');
                archiveSubmitBtn.disabled = false;
                archiveSubmitBtn.innerHTML = '<i class="fas fa-box-archive me-1"></i> Confirm Archive';
            }
        });
    }

    // Restore / Reactivate Modal & Form Handling
    const restoreModalEl = document.getElementById('restoreItemModal');
    const restoreModal = restoreModalEl ? new bootstrap.Modal(restoreModalEl) : null;
    const restoreForm = document.getElementById('restoreItemForm');
    const restoreSubmitBtn = document.getElementById('restoreSubmitBtn');

    document.querySelectorAll('.js-restore-item-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const itemId = this.dataset.itemId || '';
            const itemName = this.dataset.itemName || '';
            const brand = this.dataset.brand || '';
            const size = this.dataset.size || '';
            const branchName = this.dataset.branchName || '';

            document.getElementById('restoreItemId').value = itemId;
            document.getElementById('restoreItemName').textContent = itemName;
            document.getElementById('restoreItemMeta').textContent = [brand, size].filter(Boolean).join(' • ');
            document.getElementById('restoreBranchName').textContent = branchName;

            if (restoreModal) {
                restoreModal.show();
            }
        });
    });

    if (restoreForm) {
        restoreForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const itemId = document.getElementById('restoreItemId').value;

            if (!itemId) {
                alert('Invalid inventory item.');
                return;
            }

            restoreSubmitBtn.disabled = true;
            restoreSubmitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Reactivating...';

            try {
                const body = new URLSearchParams();
                body.append('csrf_token', restoreForm.querySelector('input[name="csrf_token"]').value);
                body.append('action', 'restore');
                body.append('inventory_id', itemId);

                const response = await fetch('/hwtires/api/inventory-api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                });

                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Unable to reactivate inventory item.');
                }

                if (restoreModal) restoreModal.hide();
                window.location.reload();
            } catch (err) {
                alert(err.message || 'An error occurred while reactivating the inventory item.');
                restoreSubmitBtn.disabled = false;
                restoreSubmitBtn.innerHTML = '<i class="fas fa-rotate-left me-1"></i> Confirm Reactivation';
            }
        });
    }

    // ==========================================
    // Add Inventory Modal - Dynamic Cascading & Delivery Logic
    // ==========================================
    const catalogBrandModels = <?php echo json_encode($brand_models_map, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?> || {};
    const addForm = document.getElementById('addInventoryForm');
    const categorySelect = document.getElementById('add_category');
    const brandSelect = document.getElementById('add_brand_select');
    const brandCustomInput = document.getElementById('add_brand_custom');
    const modelSelect = document.getElementById('add_model_select');
    const modelCustomInput = document.getElementById('add_model_custom');
    const sizeInput = document.getElementById('add_size');
    const modelReqMark = document.getElementById('add_model_required_mark');
    const sizeReqMark = document.getElementById('add_size_required_mark');

    const scheduleToggle = document.getElementById('scheduleDeliveryToggle');
    const deliveryContainer = document.getElementById('deliveryFieldsContainer');
    const expectedQtyInput = document.getElementById('add_expected_quantity');
    const sourceTypeSelect = document.getElementById('add_source_type');
    const supplierNameInput = document.getElementById('add_supplier_name');
    const supplierGroup = document.getElementById('deliverySupplierNameGroup');
    const supplierLabel = document.getElementById('deliverySupplierNameLabel');
    const refLabel = document.getElementById('deliveryReferenceLabel');
    const refInput = document.getElementById('add_reference_number');

    function updateCategoryRequirements() {
        if (!categorySelect) return;
        const isTire = (categorySelect.value === 'tire');

        if (modelReqMark) modelReqMark.style.display = isTire ? '' : 'none';
        if (sizeReqMark) sizeReqMark.style.display = isTire ? '' : 'none';

        if (sizeInput) {
            sizeInput.required = isTire;
            sizeInput.placeholder = isTire ? 'e.g., 205/55R16' : 'Size, fitment, or short detail';
        }

        updateModelRequirement();
    }

    function updateModelRequirement() {
        if (!categorySelect) return;
        const isTire = (categorySelect.value === 'tire');
        const isCustomModel = modelCustomInput && !modelCustomInput.classList.contains('d-none');

        if (modelSelect) {
            modelSelect.required = isTire && !isCustomModel;
        }
        if (modelCustomInput) {
            modelCustomInput.required = isTire && isCustomModel;
        }
    }

    function updateModelDropdown(brand) {
        if (!modelSelect) return;
        const currentModel = modelSelect.value;
        const isOtherBrand = (brand === 'Other');

        if (isOtherBrand) {
            modelSelect.innerHTML = '<option value="Other" selected>Other / Custom Model...</option>';
            if (modelCustomInput) {
                modelCustomInput.classList.remove('d-none');
                modelCustomInput.focus();
            }
            updateModelRequirement();
            return;
        }

        if (!brand) {
            modelSelect.innerHTML = '<option value="">-- Select Brand First --</option><option value="Other">Other / Custom Model...</option>';
            if (modelCustomInput) {
                modelCustomInput.classList.add('d-none');
                modelCustomInput.value = '';
            }
            updateModelRequirement();
            return;
        }

        const models = catalogBrandModels[brand] || [];
        modelSelect.innerHTML = '<option value="">-- Select Model --</option>';
        models.forEach(function(m) {
            const opt = document.createElement('option');
            opt.value = m;
            opt.textContent = m;
            if (m === currentModel) opt.selected = true;
            modelSelect.appendChild(opt);
        });

        const otherOpt = document.createElement('option');
        otherOpt.value = 'Other';
        otherOpt.textContent = 'Other / Custom Model...';
        modelSelect.appendChild(otherOpt);

        if (modelSelect.value === 'Other') {
            if (modelCustomInput) modelCustomInput.classList.remove('d-none');
        } else {
            if (modelCustomInput) {
                modelCustomInput.classList.add('d-none');
                modelCustomInput.value = '';
            }
        }
        updateModelRequirement();
    }

    if (categorySelect) {
        categorySelect.addEventListener('change', updateCategoryRequirements);
    }

    if (brandSelect) {
        brandSelect.addEventListener('change', function() {
            const selectedBrand = this.value;
            if (selectedBrand === 'Other') {
                if (brandCustomInput) {
                    brandCustomInput.classList.remove('d-none');
                    brandCustomInput.required = true;
                    brandCustomInput.focus();
                }
            } else {
                if (brandCustomInput) {
                    brandCustomInput.classList.add('d-none');
                    brandCustomInput.required = false;
                    brandCustomInput.value = '';
                }
            }
            updateModelDropdown(selectedBrand);
        });
    }

    if (modelSelect) {
        modelSelect.addEventListener('change', function() {
            const selectedModel = this.value;
            if (selectedModel === 'Other') {
                if (modelCustomInput) {
                    modelCustomInput.classList.remove('d-none');
                    modelCustomInput.focus();
                }
            } else {
                if (modelCustomInput) {
                    modelCustomInput.classList.add('d-none');
                    modelCustomInput.value = '';
                }
            }
            updateModelRequirement();
        });
    }

    // Dynamic Delivery Source & Reference UI Logic
    function updateDeliverySourceUI() {
        if (!sourceTypeSelect || !supplierNameInput) return;
        const st = sourceTypeSelect.value;
        const isScheduled = scheduleToggle && scheduleToggle.checked;

        if (st === 'supplier_delivery') {
            if (supplierLabel) supplierLabel.innerHTML = 'Supplier Name <span class="text-danger">*</span>';
            supplierNameInput.readOnly = false;
            if (supplierNameInput.value === 'Central Warehouse (Tangub Hub)' || supplierNameInput.value === 'Auxiliary Warehouse (San Carlos Hub)') {
                supplierNameInput.value = '';
            }
            supplierNameInput.placeholder = 'e.g., Yokohama Philippines';
            supplierNameInput.required = isScheduled;
            if (refLabel) refLabel.textContent = 'DR / Invoice #';
            if (refInput) refInput.placeholder = 'e.g., DR-2026-0891 or Invoice #';
        } else if (st === 'tangub_warehouse') {
            if (supplierLabel) supplierLabel.innerHTML = 'Warehouse Source';
            supplierNameInput.value = 'Central Warehouse (Tangub Hub)';
            supplierNameInput.readOnly = true;
            supplierNameInput.required = false;
            if (refLabel) refLabel.textContent = 'Dispatch / Transfer Reference #';
            if (refInput) refInput.placeholder = 'e.g., TR-2026-104';
        } else if (st === 'sancarlos_warehouse') {
            if (supplierLabel) supplierLabel.innerHTML = 'Warehouse Source';
            supplierNameInput.value = 'Auxiliary Warehouse (San Carlos Hub)';
            supplierNameInput.readOnly = true;
            supplierNameInput.required = false;
            if (refLabel) refLabel.textContent = 'Dispatch / Transfer Reference #';
            if (refInput) refInput.placeholder = 'e.g., TR-2026-104';
        } else if (st === 'other') {
            if (supplierLabel) supplierLabel.innerHTML = 'Source Name <span class="text-danger">*</span>';
            supplierNameInput.readOnly = false;
            if (supplierNameInput.value === 'Central Warehouse (Tangub Hub)' || supplierNameInput.value === 'Auxiliary Warehouse (San Carlos Hub)') {
                supplierNameInput.value = '';
            }
            supplierNameInput.placeholder = 'e.g., Custom source / origin';
            supplierNameInput.required = isScheduled;
            if (refLabel) refLabel.textContent = 'Reference #';
            if (refInput) refInput.placeholder = 'e.g., Reference number or PO #';
        }
    }

    // Schedule Delivery Toggle Logic
    if (scheduleToggle) {
        scheduleToggle.addEventListener('change', function() {
            if (this.checked) {
                if (deliveryContainer) deliveryContainer.classList.remove('d-none');
                if (expectedQtyInput) expectedQtyInput.required = true;
                if (sourceTypeSelect) sourceTypeSelect.required = true;
                updateDeliverySourceUI();
            } else {
                if (deliveryContainer) deliveryContainer.classList.add('d-none');
                if (expectedQtyInput) {
                    expectedQtyInput.required = false;
                    expectedQtyInput.value = '';
                }
                if (sourceTypeSelect) {
                    sourceTypeSelect.required = false;
                    sourceTypeSelect.value = 'supplier_delivery';
                }
                if (supplierNameInput) {
                    supplierNameInput.required = false;
                    supplierNameInput.readOnly = false;
                    supplierNameInput.value = '';
                }
                if (refInput) {
                    refInput.value = '';
                }
                updateDeliverySourceUI();
            }
        });
    }

    if (sourceTypeSelect) {
        sourceTypeSelect.addEventListener('change', updateDeliverySourceUI);
    }

    // Add Form Validation
    if (addForm) {
        addForm.addEventListener('submit', function(e) {
            const isTire = (categorySelect && categorySelect.value === 'tire');
            const brandVal = brandSelect ? brandSelect.value : '';
            const brandCustomVal = brandCustomInput ? brandCustomInput.value.trim() : '';
            const modelVal = modelSelect ? modelSelect.value : '';
            const modelCustomVal = modelCustomInput ? modelCustomInput.value.trim() : '';
            const sizeVal = sizeInput ? sizeInput.value.trim() : '';

            if (brandVal === 'Other' && !brandCustomVal) {
                e.preventDefault();
                alert('Please enter a custom brand name.');
                if (brandCustomInput) brandCustomInput.focus();
                return;
            }

            if (isTire) {
                if (modelVal === 'Other' && !modelCustomVal) {
                    e.preventDefault();
                    alert('Please enter a model name for this tire.');
                    if (modelCustomInput) modelCustomInput.focus();
                    return;
                }
                if (!modelVal) {
                    e.preventDefault();
                    alert('Please select or specify a model for this tire.');
                    if (modelSelect) modelSelect.focus();
                    return;
                }
                if (!sizeVal) {
                    e.preventDefault();
                    alert('Please enter the tire size.');
                    if (sizeInput) sizeInput.focus();
                    return;
                }
            }

            if (scheduleToggle && scheduleToggle.checked) {
                const expQty = expectedQtyInput ? parseInt(expectedQtyInput.value, 10) : 0;
                if (isNaN(expQty) || expQty <= 0) {
                    e.preventDefault();
                    alert('Please enter a valid expected delivery quantity (1 or greater).');
                    if (expectedQtyInput) expectedQtyInput.focus();
                    return;
                }

                const st = sourceTypeSelect ? sourceTypeSelect.value : 'supplier_delivery';
                const supVal = supplierNameInput ? supplierNameInput.value.trim() : '';
                if (st === 'supplier_delivery' && !supVal) {
                    e.preventDefault();
                    alert('Please enter a supplier name for direct supplier delivery.');
                    if (supplierNameInput) supplierNameInput.focus();
                    return;
                }
                if (st === 'other' && !supVal) {
                    e.preventDefault();
                    alert('Please enter a source name.');
                    if (supplierNameInput) supplierNameInput.focus();
                    return;
                }
            }
        });
    }

    // Scroll to & highlight newly created item row if added_id is in URL
    const urlParams = new URLSearchParams(window.location.search);
    const addedId = urlParams.get('added_id');
    if (addedId) {
        const targetRow = document.getElementById('inventory-row-' + addedId) || document.querySelector(`tr[data-item-id="${addedId}"]`);
        if (targetRow) {
            targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>
