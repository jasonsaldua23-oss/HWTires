<?php
/**
 * Front Desk Inventory Management
 */

require_once '../../includes/config.php';
require_once '../../includes/forecasting.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$page_title = 'Inventory Management';
$user = app_get_session_user();
$branch_id = (int) ($user['branch_id'] ?? 0);

if (!can_access_inventory($branch_id)) {
    set_flash_message('Inventory is available only for branches configured with inventory.', 'warning');
    redirect('/hwtires/front-desk/');
}

if (!function_exists('front_inventory_money')) {
    function front_inventory_money($amount) {
        return '₱' . number_format((float) $amount, 0);
    }
}

if (!function_exists('front_inventory_category_label')) {
    function front_inventory_category_label($category, $plural = false) {
        $labels = [
            'tire' => $plural ? 'Tires' : 'Tire',
            'accessory' => $plural ? 'Accessories' : 'Accessory',
            'part' => $plural ? 'Parts' : 'Part',
        ];

        return $labels[$category] ?? ($plural ? 'All Items' : 'Item');
    }
}

if (!function_exists('front_inventory_branch_label')) {
    function front_inventory_branch_label($branch_name) {
        return app_branch_label($branch_name, 'Branch');
    }
}

if (!function_exists('front_inventory_item_details')) {
    function front_inventory_item_details($item) {
        $details = front_inventory_item_detail_lines($item);
        $values = array_map(static function ($detail) {
            return $detail['value'];
        }, array_filter($details, static function ($detail) {
            return ($detail['value'] ?? '-') !== '-';
        }));

        return !empty($values) ? implode(' | ', $values) : '-';
    }
}

if (!function_exists('front_inventory_item_detail_lines')) {
    function front_inventory_item_detail_lines($item) {
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

if (!function_exists('front_inventory_transaction_source_links')) {
    function front_inventory_transaction_source_links(array $transaction) {
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
                'href' => '/hwtires/front-desk/quotations/view.php?id=' . $quotation_id,
                'label' => $quotation_number !== '' ? $quotation_number : 'Quotation',
                'icon' => 'fas fa-receipt',
                'title' => 'Open quotation',
            ];
        }

        if ($job_order_id > 0) {
            $links[] = [
                'href' => '/hwtires/front-desk/job-orders/view.php?id=' . $job_order_id,
                'label' => $job_number !== '' ? $job_number : 'Job Order',
                'icon' => 'fas fa-clipboard-list',
                'title' => 'Open job order',
            ];
        }

        return $links;
    }
}

if (!function_exists('front_inventory_filter_url')) {
    function front_inventory_filter_url($category, $search = '', $per_page = null, $page = null, $view = 'all', $sales_mode = 'all', $brand = '', $size = '') {
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

$branch_stmt = $pdo->prepare("SELECT id, name FROM branches WHERE id = ? AND status = 'active' AND has_inventory = 1 LIMIT 1");
$branch_stmt->execute([$branch_id]);
$inventory_branch = $branch_stmt->fetch();

if (!$inventory_branch) {
    set_flash_message('Inventory is available only for active inventory branches.', 'warning');
    redirect('/hwtires/front-desk/');
}

$requested_stock_in_item = null;
if (($_GET['action'] ?? '') === 'stock_in' && !empty($_GET['item_id'])) {
    $req_stmt = $pdo->prepare("SELECT * FROM inventory_items WHERE id = ? AND branch_id = ? AND status = 'active' LIMIT 1");
    $req_stmt->execute([(int) $_GET['item_id'], $branch_id]);
    $requested_stock_in_item = $req_stmt->fetch();
}

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
    WHERE status = 'active'
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

$where = ["i.status = 'active'", "i.branch_id = ?"];
$filter_params = [$branch_id];

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

$transaction_where = ["LOWER(REPLACE(t.transaction_type, ' ', '_')) IN ('stock_in', 'stock_out')", 'i.branch_id = ?'];
$transaction_params = [$branch_id];

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
        ORDER BY FIELD(i.category, 'tire', 'accessory', 'part'), i.item_name ASC
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

$inventory_branches = forecast_load_inventory_branches($pdo);
$inventory_branch_ids = array_values(array_map(static function ($branch) {
    return (int) $branch['id'];
}, $inventory_branches));

$forecast_support = forecast_build_inventory_dss($pdo, [
    'category' => $category_filter,
    'branch' => (string) $branch_id,
    'status' => 'all',
    'search' => $search_filter,
    'allowed_branch_ids' => [$branch_id],
]);
$decision_support = $forecast_support['decision_support'];

$transfer_support = forecast_build_inventory_dss($pdo, [
    'category' => $category_filter,
    'branch' => 'all',
    'status' => 'all',
    'search' => $search_filter,
    'allowed_branch_ids' => $inventory_branch_ids,
]);
$branch_transfer_recommendations = array_values(array_filter($transfer_support['decision_support']['transfers'] ?? [], static function ($transfer) use ($branch_id) {
    $from_branch_id = (int) ($transfer['from']['item']['branch_id'] ?? 0);
    $to_branch_id = (int) ($transfer['to']['item']['branch_id'] ?? 0);

    return $from_branch_id === $branch_id || $to_branch_id === $branch_id;
}));

$incoming_item_requests = [];
try {
    $incoming_request_stmt = $pdo->prepare("
        SELECT
            tr.*,
            rb.name AS requesting_branch_name,
            db.name AS donor_branch_name,
            u.name AS requested_by_name,
            c.name AS customer_name,
            q.quotation_number
        FROM inter_branch_transfer_requests tr
        LEFT JOIN branches rb ON rb.id = tr.requesting_branch_id
        LEFT JOIN branches db ON db.id = tr.donor_branch_id
        LEFT JOIN users u ON u.id = tr.requested_by
        LEFT JOIN customers c ON c.id = tr.customer_id
        LEFT JOIN quotations q ON q.id = tr.quotation_id
        WHERE (tr.donor_branch_id = ? OR tr.requesting_branch_id = ?)
          AND (
              tr.status IN ('pending', 'approved', 'shipped')
              OR (tr.status = 'cancelled' AND tr.notes LIKE '%[RETURN_PENDING]%' AND tr.notes NOT LIKE '%[RETURNED]%')
              OR (
                  tr.status IN ('received', 'cancelled')
                  AND COALESCE(tr.received_date, tr.updated_at, tr.created_at) >= DATE_SUB(NOW(), INTERVAL 7 DAY)
              )
          )
        ORDER BY FIELD(tr.status, 'pending', 'approved', 'shipped', 'cancelled', 'received'), tr.created_at DESC
        LIMIT 20
    ");
    $incoming_request_stmt->execute([$branch_id, $branch_id]);
    $incoming_item_requests = $incoming_request_stmt->fetchAll();
} catch (Exception $request_error) {
    error_log('Front desk incoming inventory requests error: ' . $request_error->getMessage());
}

$pending_item_requests = array_values(array_filter($incoming_item_requests, static function ($request) use ($branch_id) {
    $st = strtolower((string) ($request['status'] ?? 'pending'));
    $notes = (string) ($request['notes'] ?? '');
    $is_donor = (int) ($request['donor_branch_id'] ?? 0) === (int) $branch_id;
    $is_receiver = (int) ($request['requesting_branch_id'] ?? 0) === (int) $branch_id;

    if ($is_donor && in_array($st, ['pending', 'approved'], true)) {
        return true;
    }
    if ($is_receiver && $st === 'shipped') {
        return true;
    }
    if ($is_donor && $st === 'cancelled' && strpos($notes, '[RETURN_PENDING]') !== false && strpos($notes, '[RETURNED]') === false) {
        return true;
    }
    if ($is_donor && $st === 'shipped') {
        return true; // Still in transit, waiting for receiver
    }
    return false;
}));

$transferred_item_requests = array_values(array_filter($incoming_item_requests, static function ($request) use ($branch_id) {
    $st = strtolower((string) ($request['status'] ?? 'pending'));
    $notes = (string) ($request['notes'] ?? '');
    return $st === 'received' || strpos($notes, '[RETURNED]') !== false;
}));

$stock_out_tag_customers = [];
$stock_out_tag_vehicles = [];
try {
    $tag_customer_stmt = $pdo->query("
        SELECT DISTINCT
            c.id,
            c.name,
            COALESCE(NULLIF(c.phone_mobile, ''), NULLIF(c.contact, ''), '') AS phone
        FROM customers c
        WHERE c.status = 'active'
        ORDER BY c.name ASC
        LIMIT 15
    ");
    $stock_out_tag_customers = $tag_customer_stmt ? $tag_customer_stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $tag_vehicle_stmt = $pdo->query("
        SELECT
            v.id,
            v.customer_id,
            v.plate_number,
            v.make,
            v.model,
            v.year,
            c.name AS customer_name
        FROM vehicles v
        INNER JOIN customers c ON c.id = v.customer_id
        WHERE v.status = 'active'
          AND c.status = 'active'
        ORDER BY c.name ASC, v.plate_number ASC, v.id DESC
        LIMIT 500
    ");
    $stock_out_tag_vehicles = $tag_vehicle_stmt ? $tag_vehicle_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Exception $tag_error) {
    error_log('Stock out tag option load error: ' . $tag_error->getMessage());
}

$branch_label = front_inventory_branch_label($inventory_branch['name'] ?? '');
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
            'value' => front_inventory_money($tx_summary['total_revenue']),
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
            'value' => front_inventory_money($tx_summary['total_value']),
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
            'value' => front_inventory_money($tx_summary['total_value']),
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
            'value' => front_inventory_money($ls_summary['total_stock_value']),
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
            'value' => front_inventory_money($stats['total_stock_value'] ?? 0),
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

$active_filter_url = front_inventory_filter_url($category_filter, $search_filter, $per_page, $page, $view_filter, $sales_mode);
$redirect_url = '/hwtires/front-desk/tire-inventory/' . ($active_filter_url === './' ? '' : $active_filter_url) . '#inventory-records';
?>

<?php require_once '../../includes/header.php'; ?>
<?php require_once '../../includes/sidebar.php'; ?>

<main class="inventory-records-page front-inventory-page">
    <header class="inventory-hero">
        <div>
            <h1>Inventory Management</h1>
            <p>Manage tires, parts, and accessories for <?php echo esc_html($branch_label); ?></p>
        </div>
        <div class="inventory-hero-actions">
            <a href="/hwtires/front-desk/tire-inventory/transactions.php" class="inventory-history-btn">
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

    <section class="inventory-filter-card front-inventory-filter-card">
        <form class="inventory-unified-filter-form" method="get" action="./#inventory-records" style="display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; width: 100%;">
            <input type="hidden" name="per_page" value="<?php echo (int) $per_page; ?>">
            <div class="inventory-filter-group" style="flex: 1; min-width: 130px;">
                <h2 style="font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #475569;">Category</h2>
                <select name="category" id="frontCategoryFilter" aria-label="Filter inventory category" class="form-select" style="height: 42px; border-radius: 8px; border-color: #cbd5e1; font-weight: 500;">
                <?php foreach (['all' => 'All Items', 'tire' => 'Tires', 'accessory' => 'Accessories', 'part' => 'Parts'] as $category_value => $category_label): ?>
                    <option value="<?php echo esc_attr($category_value); ?>" <?php echo $category_filter === $category_value ? 'selected' : ''; ?>>
                        <?php echo esc_html($category_label); ?>
                    </option>
                <?php endforeach; ?>
                </select>
            </div>
            <div class="inventory-filter-group" id="frontBrandGroup" style="flex: 1; min-width: 130px; <?php echo ($category_filter === 'all' && $brand_filter === '') ? 'display: none;' : ''; ?>">
                <h2 style="font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #475569;">Brand</h2>
                <select name="brand" id="frontBrandFilter" aria-label="Filter inventory brand" class="form-select" style="height: 42px; border-radius: 8px; border-color: #cbd5e1; font-weight: 500;">
                    <option value="">All Brands</option>
                    <?php foreach ($available_brands as $brand_name): ?>
                        <option value="<?php echo esc_attr($brand_name); ?>" <?php echo $brand_filter === $brand_name ? 'selected' : ''; ?>>
                            <?php echo esc_html($brand_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="inventory-filter-group" id="frontSizeGroup" style="flex: 1; min-width: 130px; <?php echo ($category_filter === 'all' && $size_filter === '') ? 'display: none;' : ''; ?>">
                <h2 style="font-size: 13px; font-weight: 700; margin-bottom: 6px; color: #475569;">Size / Spec</h2>
                <select name="size" id="frontSizeFilter" aria-label="Filter inventory size" class="form-select" style="height: 42px; border-radius: 8px; border-color: #cbd5e1; font-weight: 500;">
                    <option value="">All Sizes</option>
                    <?php foreach ($available_sizes as $size_val): ?>
                        <option value="<?php echo esc_attr($size_val); ?>" <?php echo $size_filter === $size_val ? 'selected' : ''; ?>>
                            <?php echo esc_html($size_val); ?>
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
                <?php if ($category_filter !== 'all' || $brand_filter !== '' || $size_filter !== '' || $view_filter !== 'all' || $search_filter !== ''): ?>
                    <a class="btn btn-outline-secondary"
                       href="<?php echo esc_attr(front_inventory_filter_url('all', '', $per_page, null, 'all')); ?>#inventory-records"
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

        const catSelect = document.getElementById('frontCategoryFilter');
        const brandSelect = document.getElementById('frontBrandFilter');
        const sizeSelect = document.getElementById('frontSizeFilter');
        const brandGroup = document.getElementById('frontBrandGroup');
        const sizeGroup = document.getElementById('frontSizeGroup');

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

    <details class="inventory-support-details inventory-service-request-panel" id="requested-items">
        <summary class="inventory-support-summary">
            <span class="inventory-support-title">
                <i class="fas fa-right-left"></i>
                <strong>Requested Items</strong>
            </span>
            <?php
            $front_request_count = count($pending_item_requests) + count($transferred_item_requests);
            ?>
            <span class="inventory-support-count">
                <?php echo (int) $front_request_count; ?>
                <?php echo $front_request_count === 1 ? 'request' : 'requests'; ?>
            </span>
        </summary>
        <div class="inventory-support-body">
        <?php if (empty($pending_item_requests) && empty($transferred_item_requests)): ?>
            <div class="inventory-service-request-empty">No requested item yet.</div>
        <?php else: ?>
            <div class="inventory-service-request-sections">
                <div class="inventory-service-request-section">
                    <div class="inventory-service-request-subheading">
                        <h3>Pending Requests</h3>
                        <span><?php echo count($pending_item_requests); ?></span>
                    </div>
                    <?php if (empty($pending_item_requests)): ?>
                        <div class="inventory-service-request-empty">No pending item requests.</div>
                    <?php else: ?>
                        <div class="inventory-service-request-grid">
                            <?php foreach ($pending_item_requests as $request): ?>
                                <?php
                                $request_status = strtolower($request['status'] ?? 'pending');
                                $request_notes = (string) ($request['notes'] ?? '');
                                $is_donor = (int) ($request['donor_branch_id'] ?? 0) === (int) $branch_id;
                                $is_receiver = (int) ($request['requesting_branch_id'] ?? 0) === (int) $branch_id;

                                if ($request_status === 'shipped') {
                                    $request_status_label = $is_receiver ? 'In Transit (Awaiting Receipt)' : 'In Transit';
                                } elseif ($request_status === 'cancelled' && strpos($request_notes, '[RETURN_PENDING]') !== false) {
                                    $request_status_label = 'Return Pending';
                                } else {
                                    $request_status_label = [
                                        'pending' => 'Pending',
                                        'approved' => 'Approved',
                                    ][$request_status] ?? ucfirst($request_status);
                                }

                                $approved_quantity = (int) ($request['approved_quantity'] ?? 0);
                                $display_quantity = $approved_quantity > 0
                                    ? $approved_quantity
                                    : (int) ($request['requested_quantity'] ?? 0);
                                ?>
                                <article class="inventory-service-request-card status-<?php echo esc_attr($request_status); ?>">
                                    <div class="inventory-service-request-card-top">
                                        <div>
                                            <h3><?php echo esc_html($request['item_name'] ?? 'Requested item'); ?></h3>
                                            <p>
                                                <?php if ($is_receiver): ?>
                                                    From: <strong><?php echo esc_html($request['donor_branch_name'] ?? 'Donor branch'); ?></strong>
                                                <?php else: ?>
                                                    To: <strong><?php echo esc_html($request['requesting_branch_name'] ?? 'Requesting branch'); ?></strong>
                                                <?php endif; ?>
                                                <?php if (!empty($request['quotation_number'])): ?>
                                                    &bull; <?php echo esc_html($request['quotation_number']); ?>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <span class="inventory-service-request-status"><?php echo esc_html($request_status_label); ?></span>
                                    </div>
                                    <div class="inventory-service-request-meta">
                                        <span>Qty: <strong><?php echo $display_quantity; ?></strong></span>
                                        <?php if (!empty($request['customer_name'])): ?>
                                            <span>Customer: <strong><?php echo esc_html($request['customer_name']); ?></strong></span>
                                        <?php endif; ?>
                                        <span>Requested: <strong><?php echo esc_html(date('M j, Y g:i A', strtotime($request['created_at'] ?? 'now'))); ?></strong></span>
                                    </div>
                                    <div class="inventory-service-request-footer">
                                        <?php if ($is_receiver && $request_status === 'shipped'): ?>
                                            <div style="display: flex; gap: 8px; width: 100%;">
                                                <button type="button"
                                                        class="inventory-service-request-done-btn js-transfer-accept-btn"
                                                        style="background: #0d9488; flex: 1;"
                                                        data-transfer-accept
                                                        data-transfer-id="<?php echo (int) ($request['id'] ?? 0); ?>"
                                                        data-request-number="<?php echo esc_attr($request['request_number'] ?? 'this request'); ?>"
                                                        data-item-name="<?php echo esc_attr($request['item_name'] ?? 'this item'); ?>"
                                                        data-donor-branch="<?php echo esc_attr($request['donor_branch_name'] ?? 'Donor branch'); ?>"
                                                        data-qty="<?php echo $display_quantity; ?>"
                                                        title="Accept transfer and add to inventory">
                                                    <i class="fas fa-check-circle"></i>
                                                    <span>Accept</span>
                                                </button>
                                                <button type="button"
                                                        class="inventory-service-request-done-btn js-transfer-reject-btn"
                                                        style="background: #ef4444; flex: 1;"
                                                        data-transfer-reject
                                                        data-transfer-id="<?php echo (int) ($request['id'] ?? 0); ?>"
                                                        data-request-number="<?php echo esc_attr($request['request_number'] ?? 'this request'); ?>"
                                                        data-item-name="<?php echo esc_attr($request['item_name'] ?? 'this item'); ?>"
                                                        title="Reject transfer and initiate return">
                                                    <i class="fas fa-times-circle"></i>
                                                    <span>Reject</span>
                                                </button>
                                            </div>
                                        <?php elseif ($is_donor && in_array($request_status, ['pending', 'approved'], true)): ?>
                                            <button type="button"
                                                    class="inventory-service-request-done-btn"
                                                    data-transfer-done
                                                    data-transfer-id="<?php echo (int) ($request['id'] ?? 0); ?>"
                                                    data-request-number="<?php echo esc_attr($request['request_number'] ?? 'this request'); ?>"
                                                    data-item-name="<?php echo esc_attr($request['item_name'] ?? 'this item'); ?>"
                                                    title="Mark item as transferred/shipped">
                                                <i class="fas fa-truck"></i>
                                                <span>Mark Shipped</span>
                                            </button>
                                        <?php elseif ($is_donor && $request_status === 'cancelled' && strpos($request_notes, '[RETURN_PENDING]') !== false && strpos($request_notes, '[RETURNED]') === false): ?>
                                            <button type="button"
                                                    class="inventory-service-request-done-btn js-transfer-return-btn"
                                                    style="background: #0284c7; width: 100%;"
                                                    data-transfer-return
                                                    data-transfer-id="<?php echo (int) ($request['id'] ?? 0); ?>"
                                                    data-request-number="<?php echo esc_attr($request['request_number'] ?? 'this request'); ?>"
                                                    data-item-name="<?php echo esc_attr($request['item_name'] ?? 'this item'); ?>"
                                                    title="Confirm receipt of returned stock and restore inventory">
                                                <i class="fas fa-undo"></i>
                                                <span>Confirm Returned Stock</span>
                                            </button>
                                        <?php elseif ($is_donor && $request_status === 'shipped'): ?>
                                            <span class="inventory-service-request-complete" style="color: #0284c7;">
                                                <i class="fas fa-truck"></i>
                                                In transit to <?php echo esc_html($request['requesting_branch_name'] ?? 'receiver'); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="inventory-service-request-complete" style="color: #64748b;">
                                                <i class="fas fa-info-circle"></i>
                                                <?php echo esc_html($request_status_label); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="inventory-service-request-section">
                    <div class="inventory-service-request-subheading">
                        <h3>Transferred Requests</h3>
                        <span><?php echo count($transferred_item_requests); ?></span>
                    </div>
                    <?php if (empty($transferred_item_requests)): ?>
                        <div class="inventory-service-request-empty">No transferred item requests yet.</div>
                    <?php else: ?>
                        <div class="inventory-service-request-grid">
                            <?php foreach ($transferred_item_requests as $request): ?>
                                <?php
                                $approved_quantity = (int) ($request['approved_quantity'] ?? 0);
                                $display_quantity = $approved_quantity > 0
                                    ? $approved_quantity
                                    : (int) ($request['requested_quantity'] ?? 0);
                                $request_notes = (string) ($request['notes'] ?? '');
                                $is_returned = strpos($request_notes, '[RETURNED]') !== false;
                                ?>
                                <article class="inventory-service-request-card <?php echo $is_returned ? 'status-cancelled' : 'status-received'; ?>">
                                    <div class="inventory-service-request-card-top">
                                        <div>
                                            <h3><?php echo esc_html($request['item_name'] ?? 'Requested item'); ?></h3>
                                            <p>
                                                <?php echo esc_html($request['requesting_branch_name'] ?? 'Requesting branch'); ?>
                                                <?php if (!empty($request['quotation_number'])): ?>
                                                    &bull; <?php echo esc_html($request['quotation_number']); ?>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <span class="inventory-service-request-status"><?php echo $is_returned ? 'Returned' : 'Transferred'; ?></span>
                                    </div>
                                    <div class="inventory-service-request-meta">
                                        <span>Qty: <strong><?php echo $display_quantity; ?></strong></span>
                                        <?php if (!empty($request['customer_name'])): ?>
                                            <span>Customer: <strong><?php echo esc_html($request['customer_name']); ?></strong></span>
                                        <?php endif; ?>
                                        <span><?php echo $is_returned ? 'Returned:' : 'Transferred:'; ?> <strong><?php echo esc_html(date('M j, Y g:i A', strtotime($request['received_date'] ?? $request['updated_at'] ?? $request['created_at'] ?? 'now'))); ?></strong></span>
                                    </div>
                                    <div class="inventory-service-request-footer">
                                        <?php if ($is_returned): ?>
                                            <span class="inventory-service-request-complete" style="color: #64748b;">
                                                <i class="fas fa-undo"></i>
                                                Returned to sender
                                            </span>
                                        <?php else: ?>
                                            <span class="inventory-service-request-complete">
                                                <i class="fas fa-circle-check"></i>
                                                Transfer completed
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        </div>
    </details>

    <details class="inventory-support-details inventory-low-stock-panel" id="low-stock-alerts">
        <summary class="inventory-support-summary">
            <span class="inventory-support-title">
                <i class="fas fa-exclamation"></i>
                <strong>Low Stock Alerts</strong>
            </span>
            <?php
            $front_low_stock_count = count($low_stock_items);
            ?>
            <span class="inventory-support-count">
                <?php echo (int) $front_low_stock_count; ?>
                <?php echo $front_low_stock_count === 1 ? 'item' : 'items'; ?>
            </span>
        </summary>
        <div class="inventory-support-body">

        <?php if (empty($low_stock_items)): ?>
            <div class="inventory-empty-state">No low stock alerts for the selected category.</div>
        <?php else: ?>
            <div class="inventory-alert-grid">
                <?php foreach ($low_stock_items as $item): ?>
                    <?php
                    $category = $item['category'] ?? 'part';
                    $detail = front_inventory_item_details($item);
                    $alert_branch_id = (int) ($item['branch_id'] ?? 0);
                    $alert_branch_name = front_inventory_branch_label($item['branch_name'] ?? ($alert_branch_id ? 'Branch ' . $alert_branch_id : 'Branch'));
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
                                        <?php echo esc_html(front_inventory_category_label($category)); ?>
                                    </span>
                                    <span class="inventory-branch-pill inventory-branch-<?php echo $alert_branch_id; ?>">
                                        <?php echo esc_html($alert_branch_name); ?>
                                    </span>
                                </div>
                            </div>
                            <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 6px;">
                                <span class="inventory-low-badge" style="background: #fff1f2; color: #e11d48; border: 1px solid #fecdd3; padding: 3px 8px; border-radius: 4px; font-size: 0.76rem; font-weight: 700;">
                                    <i class="fas fa-triangle-exclamation" style="margin-right: 3px;"></i> Low Stock
                                </span>
                                <button type="button" class="js-stock-in"
                                    style="background: #00ad45; color: #ffffff; border: none; font-size: 0.78rem; font-weight: 700; padding: 5px 12px; border-radius: 6px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);"
                                    data-id="<?php echo (int) $item['id']; ?>"
                                    data-name="<?php echo esc_attr($item['item_name']); ?>"
                                    data-current="<?php echo (int) $item['quantity']; ?>"
                                    data-reorder="<?php echo (int) $item['reorder_level']; ?>"
                                    data-supplier="<?php echo esc_attr($item['supplier_name'] ?? ''); ?>"
                                    data-default-qty="<?php echo max(1, (int) $item['reorder_level'] - (int) $item['quantity']); ?>"
                                    title="Click to Stock In <?php echo esc_attr($item['item_name']); ?>">
                                    <i class="fas fa-plus"></i> Stock In (+<?php echo max(1, (int) $item['reorder_level'] - (int) $item['quantity']); ?>)
                                </button>
                            </div>
                        </div>
                        <div class="inventory-alert-card-bottom">
                            <div>
                                <span>Current: <strong><?php echo (int) $item['quantity']; ?></strong></span>
                                <span>Reorder Level: <strong><?php echo (int) $item['reorder_level']; ?></strong></span>
                            </div>
                            <strong><?php echo front_inventory_money($item['unit_price'] ?? 0); ?></strong>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        </div>
    </details>

    <details class="inventory-support-details forecast-dss-panel inventory-decision-support-panel">
        <summary class="inventory-support-summary">
            <span class="inventory-support-title">
                <i class="far fa-lightbulb"></i>
                <strong>Forecast-Based Decision Support</strong>
            </span>
            <?php
            $front_forecast_count = count($decision_support['high_priority'] ?? []) + count($decision_support['medium_priority'] ?? []);
            ?>
            <span class="inventory-support-count">
                <?php echo (int) $front_forecast_count; ?>
                <?php echo $front_forecast_count === 1 ? 'action' : 'actions'; ?>
            </span>
        </summary>
        <div class="inventory-support-body">

        <div class="forecast-dss-grid">
            <article class="forecast-dss-card dss-high">
                <h3><i class="fas fa-triangle-exclamation"></i> High Priority</h3>
                <?php if (empty($decision_support['high_priority'])): ?>
                    <p class="forecast-muted">No critical inventory actions for the selected filters.</p>
                <?php else: ?>
                    <?php foreach (array_slice($decision_support['high_priority'], 0, 3) as $support_item): ?>
                        <div class="forecast-dss-item">
                            <strong><?php echo esc_html($support_item['item']['item_name']); ?></strong>
                            <p>
                                Order <?php echo (int) $support_item['recommended_order']; ?> units.
                                <?php if ($support_item['stock_duration'] !== null): ?>
                                    Estimated stock duration: <?php echo number_format((float) $support_item['stock_duration'], 1); ?> weeks.
                                <?php else: ?>
                                    Current stock is below reorder level.
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </article>

            <article class="forecast-dss-card dss-medium">
                <h3><i class="fas fa-cube"></i> Medium Priority</h3>
                <?php if (empty($decision_support['medium_priority'])): ?>
                    <p class="forecast-muted">No warning-level inventory actions for the selected filters.</p>
                <?php else: ?>
                    <?php foreach (array_slice($decision_support['medium_priority'], 0, 3) as $support_item): ?>
                        <div class="forecast-dss-item">
                            <strong><?php echo esc_html($support_item['item']['item_name']); ?></strong>
                            <p>Order <?php echo (int) $support_item['recommended_order']; ?> units within the next week to maintain stock coverage.</p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </article>

            <article class="forecast-dss-card dss-insights">
                <h3><i class="far fa-circle-check"></i> Branch Insights</h3>
                <div class="forecast-insight-list">
                    <div>
                        <strong>Growing Demand</strong>
                        <p><?php echo (int) $decision_support['insights']['growing']; ?> items showing increased movement</p>
                    </div>
                    <div>
                        <strong>Declining Items</strong>
                        <p><?php echo (int) $decision_support['insights']['declining']; ?> items with slower movement</p>
                    </div>
                    <div>
                        <strong>Healthy Stock</strong>
                        <p><?php echo (int) $decision_support['insights']['optimal']; ?> items at healthy levels</p>
                    </div>
                </div>
            </article>
        </div>
        </div>
    </details>

    <details class="inventory-support-details forecast-transfer-panel front-transfer-panel">
        <summary class="inventory-support-summary">
            <span class="inventory-support-title">
                <i class="fas fa-right-left"></i>
                <strong>Branch Transfer Recommendations</strong>
            </span>
            <?php
            $front_transfer_count = count($branch_transfer_recommendations);
            ?>
            <span class="inventory-support-count">
                <?php echo (int) $front_transfer_count; ?>
                <?php echo $front_transfer_count === 1 ? 'recommendation' : 'recommendations'; ?>
            </span>
        </summary>
        <div class="inventory-support-body">

        <?php if (empty($branch_transfer_recommendations)): ?>
            <div class="forecast-empty-state">No branch transfer recommendations for this branch right now.</div>
        <?php else: ?>
            <div class="forecast-transfer-list">
                <?php foreach ($branch_transfer_recommendations as $transfer): ?>
                    <?php
                    $to = $transfer['to'];
                    $from = $transfer['from'];
                    $item = $transfer['item'];
                    $priority = $transfer['priority'];
                    $from_item = $from['item'];
                    $to_item = $to['item'];
                    $from_branch_id = (int) ($from_item['branch_id'] ?? 0);
                    $to_branch_id = (int) ($to_item['branch_id'] ?? 0);
                    $can_send_transfer = $from_branch_id === $branch_id;
                    $status_duration = $to['stock_duration'] !== null ? number_format((float) $to['stock_duration'], 1) : 'low';
                    ?>
                    <article class="forecast-transfer-card priority-<?php echo esc_attr($priority); ?>">
                        <div class="forecast-transfer-main">
                            <div class="forecast-transfer-badges">
                                <span class="transfer-priority"><?php echo $priority === 'high' ? 'HIGH PRIORITY' : 'MEDIUM PRIORITY'; ?></span>
                                <span class="forecast-category category-<?php echo esc_attr($item['category']); ?>">
                                    <?php echo esc_html(forecast_category_label($item['category'])); ?>
                                </span>
                                <?php if (!$can_send_transfer): ?>
                                    <span class="transfer-direction">Inbound</span>
                                <?php endif; ?>
                            </div>
                            <h3><?php echo esc_html(app_display_item_name($item['item_name'], $item['category'] ?? null)); ?></h3>
                            <p>
                                <?php echo $priority === 'high' ? 'Critical' : 'Warning'; ?> shortage at
                                <?php echo esc_html(strtoupper(forecast_branch_label($to_item['branch_name']))); ?>
                                <?php if ($to['stock_duration'] !== null): ?>
                                    - only <?php echo esc_html($status_duration); ?> weeks of stock remaining
                                <?php else: ?>
                                    - stock is at or below reorder level
                                <?php endif; ?>
                            </p>
                            <div class="forecast-transfer-route">
                                <span class="forecast-branch-pill branch-<?php echo $from_branch_id; ?>">
                                    <?php echo esc_html(strtoupper(forecast_branch_label($from_item['branch_name']))); ?>
                                </span>
                                <em>Stock: <?php echo (int) $from_item['quantity']; ?></em>
                                <i class="fas fa-truck"></i>
                                <span class="forecast-branch-pill branch-<?php echo $to_branch_id; ?>">
                                    <?php echo esc_html(strtoupper(forecast_branch_label($to_item['branch_name']))); ?>
                                </span>
                                <em>Stock: <?php echo (int) $to_item['quantity']; ?></em>
                            </div>
                        </div>
                        <div class="forecast-transfer-qty">
                            <span>Recommended Transfer</span>
                            <strong><?php echo (int) $transfer['quantity']; ?></strong>
                            <p>units</p>
                            <?php if ($can_send_transfer): ?>
                                <button type="button"
                                        class="inventory-action-btn transfer-stock js-transfer-stock"
                                        data-source-id="<?php echo (int) $from_item['id']; ?>"
                                        data-target-id="<?php echo (int) $to_item['id']; ?>"
                                        data-name="<?php echo esc_attr($item['item_name']); ?>"
                                        data-from="<?php echo esc_attr(forecast_branch_label($from_item['branch_name'])); ?>"
                                        data-to="<?php echo esc_attr(forecast_branch_label($to_item['branch_name'])); ?>"
                                        data-current="<?php echo (int) $from_item['quantity']; ?>"
                                        data-quantity="<?php echo (int) $transfer['quantity']; ?>">
                                    Transfer Stock
                                </button>
                            <?php else: ?>
                                <span class="transfer-note">Recommended inbound from another branch</span>
                            <?php endif; ?>
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
                <a href="<?php echo esc_attr(front_inventory_filter_url($category_filter, $search_filter, $per_page, 1, 'last_month_sales', 'all')); ?>#inventory-records"
                   class="inventory-sales-subtab <?php echo $sales_mode !== 'top10' ? 'active' : ''; ?>">
                    <i class="fas fa-list-ul"></i>
                    <span>All Sales Transactions (<?php echo (int) $total_records; ?>)</span>
                </a>
                <a href="<?php echo esc_attr(front_inventory_filter_url($category_filter, $search_filter, $per_page, 1, 'last_month_sales', 'top10')); ?>#inventory-records"
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
                                $item_detail = front_inventory_item_details($top_item_row);
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
                                            <?php echo esc_html(front_inventory_category_label($top_item_row['category'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="inventory-branch-pill inventory-branch-<?php echo (int) $top_item_row['branch_id']; ?>">
                                            <?php echo esc_html(front_inventory_branch_label($top_item_row['branch_name'] ?? '')); ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        <strong><?php echo front_inventory_money($top_item_row['unit_price']); ?></strong>
                                    </td>
                                    <td style="text-align: center;">
                                        <strong style="font-size: 15px; color: #0096b6;"><?php echo number_format((float) $top_item_row['total_sold_qty']); ?> units</strong>
                                        <small style="display: block; font-size: 11px; color: var(--company-muted);">in <?php echo (int) $top_item_row['total_orders']; ?> orders</small>
                                    </td>
                                    <td style="text-align: right;">
                                        <strong style="font-size: 15px; color: #059669;"><?php echo front_inventory_money($top_item_row['total_sold_amount']); ?></strong>
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
                                $source_links = front_inventory_transaction_source_links($transaction);
                                $empty_tag_label = app_inventory_transaction_tag_empty_label($transaction['reference_type'] ?? '', $transaction['transaction_type'] ?? '');
                                $transaction_value = (float) ($transaction['unit_price'] ?? 0) * (int) ($transaction['quantity'] ?? 0);
                                ?>
                                <tr>
                                    <td><span class="inventory-transaction-date"><?php echo esc_html(format_date($transaction['created_at'], 'M d, Y h:i A')); ?></span></td>
                                    <td>
                                        <span class="inventory-branch-pill inventory-branch-<?php echo (int) $transaction['branch_id']; ?>">
                                            <?php echo esc_html(front_inventory_branch_label($transaction['branch_name'] ?? '-')); ?>
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
                                    <td><strong><?php echo front_inventory_money($transaction_value); ?></strong></td>
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
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($inventory)): ?>
                            <tr>
                                <td colspan="8" class="inventory-table-empty"><?php echo esc_html($records_empty_message); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($inventory as $item): ?>
                                <?php
                                $is_low_stock = (int) $item['quantity'] <= (int) $item['reorder_level'];
                                $category = $item['category'] ?? 'part';
                                $row_branch_id = (int) ($item['branch_id'] ?? 0);
                                $row_branch_label = front_inventory_branch_label($item['branch_name'] ?? '');
                                $detail_lines = front_inventory_item_detail_lines($item);
                                ?>
                                <tr class="<?php echo $is_low_stock ? 'is-low-stock' : ''; ?>">
                                    <td>
                                        <strong><?php echo esc_html(app_display_item_name($item['item_name'], $item['category'] ?? null)); ?></strong>
                                        <small class="inventory-item-brand"><?php echo esc_html($item['brand'] ?: 'Unbranded'); ?></small>
                                    </td>
                                    <td>
                                        <span class="inventory-category category-<?php echo esc_attr($category); ?>">
                                            <?php echo esc_html(front_inventory_category_label($category)); ?>
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
                                        <span class="inventory-branch-pill inventory-branch-<?php echo $row_branch_id; ?>">
                                            <?php echo esc_html($row_branch_label); ?>
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
                                    <td><strong><?php echo front_inventory_money($item['unit_price'] ?? 0); ?></strong></td>
                                    <td>
                                        <div class="inventory-actions">
                                            <button type="button"
                                                    class="inventory-action-btn stock-in js-stock-in"
                                                    data-id="<?php echo (int) $item['id']; ?>"
                                                    data-name="<?php echo esc_attr($item['item_name']); ?>"
                                                    data-current="<?php echo (int) $item['quantity']; ?>"
                                                    data-reorder="<?php echo (int) $item['reorder_level']; ?>"
                                                    data-supplier="<?php echo esc_attr($item['supplier_name'] ?? ''); ?>">
                                                Stock In
                                            </button>
                                            <button type="button"
                                                    class="inventory-action-btn stock-out js-stock-out"
                                                    data-id="<?php echo (int) $item['id']; ?>"
                                                    data-name="<?php echo esc_attr($item['item_name']); ?>"
                                                    data-current="<?php echo (int) $item['quantity']; ?>"
                                                    data-reorder="<?php echo (int) $item['reorder_level']; ?>">
                                                Stock Out
                                            </button>
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
                            <a class="page-link" href="<?php echo esc_attr(front_inventory_filter_url($category_filter, $search_filter, $per_page, 1, $view_filter, $sales_mode)); ?>#inventory-records">First</a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo esc_attr(front_inventory_filter_url($category_filter, $search_filter, $per_page, $page - 1, $view_filter, $sales_mode)); ?>#inventory-records">Previous</a>
                        </li>
                    <?php endif; ?>
                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo esc_attr(front_inventory_filter_url($category_filter, $search_filter, $per_page, $i, $view_filter, $sales_mode)); ?>#inventory-records"><?php echo (int) $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo esc_attr(front_inventory_filter_url($category_filter, $search_filter, $per_page, $page + 1, $view_filter, $sales_mode)); ?>#inventory-records">Next</a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo esc_attr(front_inventory_filter_url($category_filter, $search_filter, $per_page, $total_pages, $view_filter, $sales_mode)); ?>#inventory-records">Last</a>
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
            <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
            <input type="hidden" name="redirect" value="<?php echo esc_attr($redirect_url); ?>">

            <div class="inventory-modal-header">
                <div>
                    <h2>Add Inventory Item</h2>
                    <p>Register a new stock record for <?php echo esc_html($branch_label); ?></p>
                </div>
                <button type="button" class="inventory-modal-close" data-bs-dismiss="modal" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="inventory-modal-body">
                <div class="inventory-form-grid">
                    <label>
                        <span>Item Name</span>
                        <input type="text" name="item_name" placeholder="e.g., Bridgestone Turanza T005" required>
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
                        <span>Quantity</span>
                        <input type="number" name="quantity" min="0" value="0" required>
                    </label>
                    <label>
                        <span>Reorder Level</span>
                        <input type="number" name="reorder_level" min="1" value="5" required>
                    </label>
                    <label>
                        <span>Unit Price</span>
                        <input type="number" name="unit_price" min="0.01" step="0.01" placeholder="0.00" required>
                    </label>
                </div>
                <label class="inventory-description-field">
                    <span>Description</span>
                    <textarea name="description" rows="2" placeholder="Optional item description"></textarea>
                </label>
            </div>

            <div class="inventory-modal-footer">
                <button type="button" class="inventory-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="inventory-confirm-btn stock-in">Add Item</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade inventory-stock-modal" id="stockInModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="/hwtires/api/inventory-api.php" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <input type="hidden" name="action" value="stock_in">
            <input type="hidden" name="inventory_id" id="stockInId">
            <input type="hidden" name="redirect" value="<?php echo esc_attr($redirect_url); ?>">

            <div class="inventory-modal-header">
                <h2>Stock In</h2>
                <button type="button" class="inventory-modal-close" data-bs-dismiss="modal" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="inventory-modal-body">
                <section class="inventory-stock-summary">
                    <h3 id="stockInName"></h3>
                    <p>Current Stock: <span id="stockInCurrent"></span> units</p>
                    <p>Reorder Level: <span id="stockInReorder"></span> units</p>
                </section>
                <div class="inventory-form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 14px;">
                    <label class="inventory-stock-field" style="grid-column: span 2;">
                        <span>Quantity to Add <b style="color: #dc3545;">*</b></span>
                        <input type="number" id="stockInQty" name="quantity" min="1" value="1" required>
                    </label>
                    <label class="inventory-stock-field">
                        <span>Supply Source</span>
                        <select name="source_type" id="stockInSource">
                            <option value="tangub_warehouse">Central Warehouse (Tangub Hub)</option>
                            <option value="sancarlos_warehouse">Auxiliary Warehouse (San Carlos Hub)</option>
                            <option value="supplier_delivery">Direct Supplier Delivery (Manila / Distributor)</option>
                            <option value="branch_transfer">Stock Transfer from Other Branch</option>
                            <option value="adjustment">Physical Inventory Adjustment</option>
                        </select>
                    </label>
                    <label class="inventory-stock-field">
                        <span>Supplier / Source Name</span>
                        <input type="text" id="stockInSupplier" name="supplier_name" placeholder="e.g., Yokohama PH / Manila Distributor">
                    </label>
                    <label class="inventory-stock-field" style="grid-column: span 2;">
                        <span>Delivery Receipt (DR) / Invoice #</span>
                        <input type="text" id="stockInRef" name="reference_number" placeholder="e.g., DR-2026-0831 / INV-9921">
                    </label>
                </div>
                <label class="inventory-stock-field" style="margin-top: 10px; display: block;">
                    <span>Notes / Remarks</span>
                    <input type="text" id="stockInNotes" name="notes" placeholder="Optional delivery notes or restock reference">
                </label>
                <div class="inventory-stock-preview" style="margin-top: 12px;">
                    New Stock Level: <strong><span id="stockInPreview"></span> units</strong>
                </div>
            </div>
            <div class="inventory-modal-footer">
                <button type="button" class="inventory-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="inventory-confirm-btn stock-in">Confirm Stock In</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade inventory-stock-modal" id="stockOutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 640px;">
        <form method="POST" action="/hwtires/api/inventory-api.php" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <input type="hidden" name="action" value="stock_out">
            <input type="hidden" name="inventory_id" id="stockOutId">
            <input type="hidden" name="redirect" value="<?php echo esc_attr($redirect_url); ?>">

            <div class="inventory-modal-header">
                <h2>Stock Out</h2>
                <button type="button" class="inventory-modal-close" data-bs-dismiss="modal" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="inventory-modal-body">
                <section class="inventory-stock-summary">
                    <h3 id="stockOutName"></h3>
                    <p>Current Stock: <span id="stockOutCurrent"></span> units</p>
                    <p>Reorder Level: <span id="stockOutReorder"></span> units</p>
                </section>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 14px;">
                    <label class="inventory-stock-field" style="display: flex; flex-direction: column; gap: 6px;">
                        <span style="font-weight: 700; font-size: 0.9rem; color: #1e293b;">Quantity to Remove <b style="color: #dc3545;">*</b></span>
                        <input type="number" id="stockOutQty" name="quantity" min="1" value="1" required style="border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 12px; font-size: 1rem; width: 100%;">
                    </label>
                    <label class="inventory-stock-field" style="display: flex; flex-direction: column; gap: 6px;">
                        <span style="font-weight: 700; font-size: 0.9rem; color: #1e293b;">Out Reason / Purpose</span>
                        <select name="reason_type" id="stockOutReasonType" style="border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 12px; font-size: 0.95rem; width: 100%;">
                            <option value="direct_sale">Direct Sale / Walk-In</option>
                            <option value="damaged">Damaged / Defective Stock</option>
                            <option value="shop_use">Shop Internal Use</option>
                            <option value="other">Inventory Adjustment</option>
                        </select>
                    </label>
                </div>

                <div style="margin-top: 16px; background: #f8fafc; padding: 14px; border-radius: 10px; border: 1px solid #e2e8f0; display: block;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0;">
                        <span style="font-size: 0.84rem; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.5px;">
                            <i class="fas fa-user-tag" style="color: #0d9488; margin-right: 6px;"></i> Tag Customer &amp; Vehicle
                        </span>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <a href="/hwtires/front-desk/customers/" target="_blank" style="font-size: 0.76rem; color: #0284c7; text-decoration: none; font-weight: 600;">
                                <i class="fas fa-user-plus"></i> Register Customer
                            </a>
                            <button type="button" id="stockOutClearTag" style="border: none; background: #e2e8f0; color: #475569; font-size: 0.76rem; padding: 4px 10px; border-radius: 6px; cursor: pointer; font-weight: 600;">
                                <i class="fas fa-times-circle" style="margin-right: 4px;"></i> Clear Selection
                            </button>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                        <!-- Customer Autocomplete -->
                        <div style="position: relative;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 700; color: #334155; margin-bottom: 5px;">
                                Tagged Customer <span id="stockOutCustomerRequired" style="color: #dc3545;">*</span>
                            </label>
                            <input type="hidden" name="customer_id" id="stockOutCustomer" value="">
                            <div style="position: relative;">
                                <input type="text" id="stockOutCustomerInput" placeholder="🔍 Type customer name or phone..." autocomplete="off" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 9px 30px 9px 12px; font-size: 0.88rem; background: #ffffff;">
                                <button type="button" id="stockOutCustomerClearBtn" title="Clear customer" style="display: none; position: absolute; right: 8px; top: 50%; transform: translateY(-50%); border: none; background: transparent; color: #94a3b8; font-size: 16px; cursor: pointer; line-height: 1;">&times;</button>
                            </div>
                            <div id="stockOutCustomerDropdown" class="tag-autocomplete-dropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; box-shadow: 0 10px 25px rgba(0,0,0,0.12); max-height: 220px; overflow-y: auto; z-index: 1060; margin-top: 4px;"></div>
                        </div>

                        <!-- Vehicle Autocomplete -->
                        <div style="position: relative;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 700; color: #334155; margin-bottom: 5px;">
                                Tagged Vehicle <span style="font-size: 0.75rem; font-weight: 400; color: #64748b;">(Optional)</span>
                            </label>
                            <input type="hidden" name="vehicle_id" id="stockOutVehicle" value="">
                            <div style="position: relative;">
                                <input type="text" id="stockOutVehicleInput" placeholder="🔍 Type plate # or car model..." autocomplete="off" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 9px 30px 9px 12px; font-size: 0.88rem; background: #ffffff;">
                                <button type="button" id="stockOutVehicleClearBtn" title="Clear vehicle" style="display: none; position: absolute; right: 8px; top: 50%; transform: translateY(-50%); border: none; background: transparent; color: #94a3b8; font-size: 16px; cursor: pointer; line-height: 1;">&times;</button>
                            </div>
                            <div id="stockOutVehicleDropdown" class="tag-autocomplete-dropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; box-shadow: 0 10px 25px rgba(0,0,0,0.12); max-height: 220px; overflow-y: auto; z-index: 1060; margin-top: 4px;"></div>
                        </div>
                    </div>
                </div>

                <label class="inventory-stock-field" style="margin-top: 14px; display: flex; flex-direction: column; gap: 6px;">
                    <span style="font-weight: 700; font-size: 0.9rem; color: #1e293b;">Notes / Remarks</span>
                    <input type="text" id="stockOutNotes" name="notes" placeholder="e.g., Sold 2 tires over counter / walk-in replacement" style="border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 12px; font-size: 0.9rem; width: 100%;">
                </label>

                <div class="inventory-stock-preview" style="margin-top: 14px;">
                    New Stock Level: <strong><span id="stockOutPreview"></span> units</strong>
                </div>
            </div>
            <div class="inventory-modal-footer">
                <button type="button" class="inventory-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="inventory-confirm-btn stock-out">Confirm Stock Out</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade inventory-stock-modal" id="transferStockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="/hwtires/api/inventory-api.php" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <input type="hidden" name="action" value="transfer_stock">
            <input type="hidden" name="source_item_id" id="transferSourceId">
            <input type="hidden" name="target_item_id" id="transferTargetId">
            <input type="hidden" name="redirect" value="<?php echo esc_attr($redirect_url); ?>">

            <div class="inventory-modal-header">
                <h2>Transfer Stock</h2>
                <button type="button" class="inventory-modal-close" data-bs-dismiss="modal" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="inventory-modal-body">
                <section class="inventory-stock-summary">
                    <h3 id="transferItemName"></h3>
                    <p>From: <strong id="transferFromBranch"></strong></p>
                    <p>To: <strong id="transferToBranch"></strong></p>
                    <p>Available Stock: <span id="transferCurrent"></span> units</p>
                </section>
                <label class="inventory-stock-field">
                    <span>Quantity to Transfer</span>
                    <input type="number" id="transferQty" name="quantity" min="1" value="1" required>
                </label>
                <label class="inventory-stock-field">
                    <span>Notes</span>
                    <textarea name="notes" rows="2" placeholder="Optional transfer notes"></textarea>
                </label>
                <div class="inventory-stock-preview">
                    Source Stock After Transfer: <strong><span id="transferPreview"></span> units</strong>
                </div>
            </div>
            <div class="inventory-modal-footer">
                <button type="button" class="inventory-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="inventory-confirm-btn transfer-stock">Confirm Transfer</button>
            </div>
        </form>
    </div>
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
    const stockInModalEl = document.getElementById('stockInModal');
    const stockOutModalEl = document.getElementById('stockOutModal');
    const transferStockModalEl = document.getElementById('transferStockModal');
    const stockInModal = new bootstrap.Modal(stockInModalEl);
    const stockOutModal = new bootstrap.Modal(stockOutModalEl);
    const transferStockModal = new bootstrap.Modal(transferStockModalEl);

    const tagCustomers = <?php echo json_encode($stock_out_tag_customers); ?>;
    const tagVehicles = <?php echo json_encode($stock_out_tag_vehicles); ?>;

    const stockInQty = document.getElementById('stockInQty');
    const stockOutQty = document.getElementById('stockOutQty');
    const stockOutCustomerHidden = document.getElementById('stockOutCustomer');
    const stockOutCustomerInput = document.getElementById('stockOutCustomerInput');
    const stockOutCustomerDropdown = document.getElementById('stockOutCustomerDropdown');
    const stockOutCustomerClearBtn = document.getElementById('stockOutCustomerClearBtn');

    const stockOutVehicleHidden = document.getElementById('stockOutVehicle');
    const stockOutVehicleInput = document.getElementById('stockOutVehicleInput');
    const stockOutVehicleDropdown = document.getElementById('stockOutVehicleDropdown');
    const stockOutVehicleClearBtn = document.getElementById('stockOutVehicleClearBtn');
    const stockOutClearTag = document.getElementById('stockOutClearTag');

    const stockOutReasonType = document.getElementById('stockOutReasonType');
    const stockOutNotes = document.getElementById('stockOutNotes');
    const transferQty = document.getElementById('transferQty');
    let stockInCurrent = 0;
    let stockOutCurrent = 0;
    let transferCurrent = 0;

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function updateStockInPreview() {
        const addQty = Math.max(0, parseInt(stockInQty.value || '0', 10));
        document.getElementById('stockInPreview').textContent = stockInCurrent + addQty;
    }

    function updateStockOutPreview() {
        const removeQty = Math.max(0, parseInt(stockOutQty.value || '0', 10));
        document.getElementById('stockOutPreview').textContent = Math.max(0, stockOutCurrent - removeQty);
    }

    let customerSearchDebounceTimer = null;
    let customerSearchAbortController = null;
    let customerSearchSeq = 0;
    let selectedCustomerName = '';

    function renderCustomerDropdownHtml(matches, isDirectSale, isLoading = false) {
        if (!stockOutCustomerDropdown) return;
        let html = '';
        if (!isDirectSale) {
            html += `<div class="tag-autocomplete-item tag-item-default" data-id="" style="font-weight: 600; color: #64748b; font-size: 0.8rem; background: #f8fafc;">-- Not Tagged --</div>`;
        }

        if (isLoading) {
            html += `<div style="padding: 10px 12px; color: #94a3b8; font-size: 0.82rem; text-align: center;"><i class="fas fa-spinner fa-spin" style="margin-right: 6px;"></i>Searching active customers...</div>`;
        } else if (!matches || matches.length === 0) {
            html += `<div style="padding: 10px 12px; color: #94a3b8; font-size: 0.82rem; text-align: center;">No matching active customer found</div>`;
        } else {
            matches.forEach(c => {
                const isSelected = stockOutCustomerHidden && stockOutCustomerHidden.value === String(c.id);
                html += `
                    <div class="tag-autocomplete-item ${isSelected ? 'active' : ''}" data-id="${c.id}" data-name="${escapeHtml(c.name)}" data-phone="${escapeHtml(c.phone || '')}">
                        <div style="font-weight: 600; font-size: 0.88rem; color: #1e293b;">${escapeHtml(c.name)}</div>
                        ${c.phone ? `<div style="font-size: 0.76rem; color: #64748b;"><i class="fas fa-phone" style="font-size: 10px; margin-right: 4px;"></i>${escapeHtml(c.phone)}</div>` : ''}
                    </div>
                `;
            });
        }

        stockOutCustomerDropdown.innerHTML = html;
        stockOutCustomerDropdown.style.display = 'block';
    }

    function renderCustomerSuggestions(query = '') {
        if (!stockOutCustomerDropdown) return;
        const q = query.toLowerCase().trim();
        const isDirectSale = stockOutReasonType && stockOutReasonType.value === 'direct_sale';

        if (q.length < 2) {
            if (customerSearchDebounceTimer) clearTimeout(customerSearchDebounceTimer);
            if (customerSearchAbortController) customerSearchAbortController.abort();
            let matches = tagCustomers.slice(0, 15);
            if (q.length === 1) {
                matches = tagCustomers.filter(c => {
                    const name = (c.name || '').toLowerCase();
                    const phone = (c.phone || '').toLowerCase();
                    return name.includes(q) || phone.includes(q);
                }).slice(0, 15);
            }
            renderCustomerDropdownHtml(matches, isDirectSale, false);
            return;
        }

        renderCustomerDropdownHtml([], isDirectSale, true);

        if (customerSearchDebounceTimer) {
            clearTimeout(customerSearchDebounceTimer);
        }
        if (customerSearchAbortController) {
            customerSearchAbortController.abort();
        }

        customerSearchAbortController = new AbortController();
        const currentSeq = ++customerSearchSeq;

        customerSearchDebounceTimer = setTimeout(async () => {
            try {
                const url = '/hwtires/api/inventory-api.php?action=search_customers&query=' + encodeURIComponent(q);
                const response = await fetch(url, {
                    signal: customerSearchAbortController.signal,
                    headers: { 'Accept': 'application/json' }
                });
                if (!response.ok) return;
                const data = await response.json();
                if (currentSeq !== customerSearchSeq) return; // Prevent race conditions

                const remoteMatches = (data && Array.isArray(data.customers)) ? data.customers : [];
                renderCustomerDropdownHtml(remoteMatches, isDirectSale, false);
            } catch (err) {
                if (err.name !== 'AbortError') {
                    console.error('Customer search error:', err);
                }
            }
        }, 250);
    }

    function renderVehicleSuggestions(query = '') {
        if (!stockOutVehicleDropdown) return;
        const q = query.toLowerCase().trim();
        const selectedCustomerId = parseInt(stockOutCustomerHidden ? stockOutCustomerHidden.value : '0', 10) || 0;

        let filtered = tagVehicles;
        if (selectedCustomerId > 0) {
            filtered = tagVehicles.filter(v => parseInt(v.customer_id, 10) === selectedCustomerId);
        }

        let matches = [];
        if (q === '') {
            matches = filtered.slice(0, 15);
        } else {
            matches = filtered.filter(v => {
                const plate = (v.plate_number || '').toLowerCase();
                const make = (v.make || '').toLowerCase();
                const model = (v.model || '').toLowerCase();
                const owner = (v.customer_name || '').toLowerCase();
                return plate.includes(q) || make.includes(q) || model.includes(q) || owner.includes(q);
            }).slice(0, 15);
        }

        let html = '';
        html += `<div class="tag-autocomplete-item tag-item-default" data-id="" style="font-weight: 600; color: #64748b; font-size: 0.8rem; background: #f8fafc;">-- No Vehicle Tagged --</div>`;

        if (matches.length === 0) {
            html += `<div style="padding: 10px 12px; color: #94a3b8; font-size: 0.82rem; text-align: center;">No matching vehicle found</div>`;
        } else {
            matches.forEach(v => {
                const isSelected = stockOutVehicleHidden && stockOutVehicleHidden.value === String(v.id);
                const plate = v.plate_number ? v.plate_number : ('Vehicle #' + v.id);
                const details = ((v.year || '') + ' ' + (v.make || '') + ' ' + (v.model || '')).trim();
                html += `
                    <div class="tag-autocomplete-item ${isSelected ? 'active' : ''}" data-id="${v.id}" data-customer-id="${v.customer_id || ''}" data-plate="${escapeHtml(plate)}" data-details="${escapeHtml(details)}" data-owner="${escapeHtml(v.customer_name || '')}">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-weight: 700; color: #0d9488; background: #ccfbff; padding: 2px 6px; border-radius: 4px; font-size: 0.78rem;">${escapeHtml(plate)}</span>
                            <span style="font-size: 0.82rem; font-weight: 600; color: #1e293b;">${escapeHtml(details)}</span>
                        </div>
                        ${v.customer_name ? `<div style="font-size: 0.74rem; color: #64748b; margin-top: 2px;">Owner: ${escapeHtml(v.customer_name)}</div>` : ''}
                    </div>
                `;
            });
        }

        stockOutVehicleDropdown.innerHTML = html;
        stockOutVehicleDropdown.style.display = 'block';
    }

    function selectCustomer(id, name, phone, syncVeh = true) {
        if (!id) {
            selectedCustomerName = '';
            if (stockOutCustomerHidden) stockOutCustomerHidden.value = '';
            if (stockOutCustomerInput) stockOutCustomerInput.value = '';
            if (stockOutCustomerClearBtn) stockOutCustomerClearBtn.style.display = 'none';
        } else {
            selectedCustomerName = name;
            if (stockOutCustomerHidden) stockOutCustomerHidden.value = id;
            if (stockOutCustomerInput) stockOutCustomerInput.value = name + (phone ? ' (' + phone + ')' : '');
            if (stockOutCustomerClearBtn) stockOutCustomerClearBtn.style.display = 'block';
        }
        if (stockOutCustomerDropdown) stockOutCustomerDropdown.style.display = 'none';

        if (id && syncVeh) {
            const customerId = parseInt(id, 10);
            const userVehicles = tagVehicles.filter(v => parseInt(v.customer_id, 10) === customerId);
            if (userVehicles.length === 1) {
                const v = userVehicles[0];
                selectVehicle(v.id, v.plate_number, ((v.year || '') + ' ' + (v.make || '') + ' ' + (v.model || '')).trim(), v.customer_name, false);
            } else if (userVehicles.length > 1) {
                const currentVehId = parseInt(stockOutVehicleHidden ? stockOutVehicleHidden.value : '0', 10);
                if (!userVehicles.some(v => parseInt(v.id, 10) === currentVehId)) {
                    if (stockOutVehicleHidden) stockOutVehicleHidden.value = '';
                    if (stockOutVehicleInput) {
                        stockOutVehicleInput.value = '';
                        stockOutVehicleInput.placeholder = `Select from ${userVehicles.length} vehicles owned by ${name}...`;
                    }
                    if (stockOutVehicleClearBtn) stockOutVehicleClearBtn.style.display = 'none';
                    renderVehicleSuggestions('');
                }
            } else {
                if (stockOutVehicleHidden) stockOutVehicleHidden.value = '';
                if (stockOutVehicleInput) {
                    stockOutVehicleInput.value = '';
                    stockOutVehicleInput.placeholder = 'No vehicle registered';
                }
                if (stockOutVehicleClearBtn) stockOutVehicleClearBtn.style.display = 'none';
            }
        }
    }

    function selectVehicle(id, plate, details, ownerName, syncCust = true) {
        if (!id) {
            if (stockOutVehicleHidden) stockOutVehicleHidden.value = '';
            if (stockOutVehicleInput) stockOutVehicleInput.value = '';
            if (stockOutVehicleClearBtn) stockOutVehicleClearBtn.style.display = 'none';
        } else {
            if (stockOutVehicleHidden) stockOutVehicleHidden.value = id;
            if (stockOutVehicleInput) stockOutVehicleInput.value = (plate ? plate + ' - ' : '') + details;
            if (stockOutVehicleClearBtn) stockOutVehicleClearBtn.style.display = 'block';
        }
        if (stockOutVehicleDropdown) stockOutVehicleDropdown.style.display = 'none';

        if (id && syncCust) {
            const veh = tagVehicles.find(v => String(v.id) === String(id));
            if (veh && veh.customer_id) {
                const cust = tagCustomers.find(c => String(c.id) === String(veh.customer_id));
                if (cust) {
                    selectCustomer(cust.id, cust.name, cust.phone, false);
                }
            }
        }
    }

    function resetStockOutTagging() {
        selectedCustomerName = '';
        if (stockOutCustomerHidden) stockOutCustomerHidden.value = '';
        if (stockOutCustomerInput) {
            stockOutCustomerInput.value = '';
            stockOutCustomerInput.placeholder = '🔍 Type customer name or phone...';
        }
        if (stockOutCustomerClearBtn) stockOutCustomerClearBtn.style.display = 'none';
        if (stockOutCustomerDropdown) stockOutCustomerDropdown.style.display = 'none';

        if (stockOutVehicleHidden) stockOutVehicleHidden.value = '';
        if (stockOutVehicleInput) {
            stockOutVehicleInput.value = '';
            stockOutVehicleInput.placeholder = '🔍 Type plate # or car model...';
        }
        if (stockOutVehicleClearBtn) stockOutVehicleClearBtn.style.display = 'none';
        if (stockOutVehicleDropdown) stockOutVehicleDropdown.style.display = 'none';
    }

    // Event listeners for Typeahead Customer
    if (stockOutCustomerInput) {
        stockOutCustomerInput.addEventListener('focus', function() {
            renderCustomerSuggestions(this.value);
        });
        stockOutCustomerInput.addEventListener('input', function() {
            // If user manually changes text after selecting a customer, immediately clear stored ID and reset vehicle
            if (stockOutCustomerHidden && stockOutCustomerHidden.value) {
                stockOutCustomerHidden.value = '';
                selectedCustomerName = '';
                if (stockOutVehicleHidden) stockOutVehicleHidden.value = '';
                if (stockOutVehicleInput) {
                    stockOutVehicleInput.value = '';
                    stockOutVehicleInput.placeholder = '🔍 Type plate # or car model...';
                }
                if (stockOutVehicleClearBtn) stockOutVehicleClearBtn.style.display = 'none';
            }
            if (stockOutCustomerClearBtn) stockOutCustomerClearBtn.style.display = this.value ? 'block' : 'none';
            renderCustomerSuggestions(this.value);
        });
    }

    if (stockOutCustomerDropdown) {
        stockOutCustomerDropdown.addEventListener('click', function(e) {
            const item = e.target.closest('.tag-autocomplete-item');
            if (!item) return;
            const id = item.dataset.id || '';
            const name = item.dataset.name || '';
            const phone = item.dataset.phone || '';
            selectCustomer(id, name, phone);
        });
    }

    if (stockOutCustomerClearBtn) {
        stockOutCustomerClearBtn.addEventListener('click', function() {
            selectCustomer('', '', '');
            if (stockOutCustomerInput) stockOutCustomerInput.focus();
        });
    }

    // Event listeners for Typeahead Vehicle
    if (stockOutVehicleInput) {
        stockOutVehicleInput.addEventListener('focus', function() {
            renderVehicleSuggestions(this.value);
        });
        stockOutVehicleInput.addEventListener('input', function() {
            renderVehicleSuggestions(this.value);
            if (stockOutVehicleHidden) stockOutVehicleHidden.value = '';
            if (stockOutVehicleClearBtn) stockOutVehicleClearBtn.style.display = this.value ? 'block' : 'none';
        });
    }

    if (stockOutVehicleDropdown) {
        stockOutVehicleDropdown.addEventListener('click', function(e) {
            const item = e.target.closest('.tag-autocomplete-item');
            if (!item) return;
            const id = item.dataset.id || '';
            const plate = item.dataset.plate || '';
            const details = item.dataset.details || '';
            const owner = item.dataset.owner || '';
            selectVehicle(id, plate, details, owner);
        });
    }

    if (stockOutVehicleClearBtn) {
        stockOutVehicleClearBtn.addEventListener('click', function() {
            selectVehicle('', '', '');
            if (stockOutVehicleInput) stockOutVehicleInput.focus();
        });
    }

    if (stockOutClearTag) {
        stockOutClearTag.addEventListener('click', resetStockOutTagging);
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('#stockOutCustomerInput') && !e.target.closest('#stockOutCustomerDropdown')) {
            if (stockOutCustomerDropdown) stockOutCustomerDropdown.style.display = 'none';
        }
        if (!e.target.closest('#stockOutVehicleInput') && !e.target.closest('#stockOutVehicleDropdown')) {
            if (stockOutVehicleDropdown) stockOutVehicleDropdown.style.display = 'none';
        }
    });

    function updateTransferPreview() {
        const transferAmount = Math.max(0, parseInt(transferQty.value || '0', 10));
        document.getElementById('transferPreview').textContent = Math.max(0, transferCurrent - transferAmount);
    }

    document.querySelectorAll('.js-stock-in').forEach(function(button) {
        button.addEventListener('click', function() {
            stockInCurrent = parseInt(button.dataset.current || '0', 10);
            document.getElementById('stockInId').value = button.dataset.id;
            document.getElementById('stockInName').textContent = button.dataset.name;
            document.getElementById('stockInCurrent').textContent = stockInCurrent;
            document.getElementById('stockInReorder').textContent = button.dataset.reorder || '0';
            const defaultQty = parseInt(button.dataset.defaultQty || '1', 10);
            stockInQty.value = defaultQty > 0 ? defaultQty : 1;
            const supplierInput = document.getElementById('stockInSupplier');
            if (supplierInput) {
                supplierInput.value = button.dataset.supplier || '';
            }
            const refInput = document.getElementById('stockInRef');
            if (refInput) {
                refInput.value = '';
            }
            const notesInput = document.getElementById('stockInNotes');
            if (notesInput) {
                notesInput.value = '';
            }
            const sourceSelect = document.getElementById('stockInSource');
            if (sourceSelect) {
                sourceSelect.value = 'supplier_delivery';
            }
            updateStockInPreview();
            stockInModal.show();
        });
    });

    function updateStockOutCustomerRequirement() {
        const isDirectSale = stockOutReasonType && stockOutReasonType.value === 'direct_sale';
        const reqEl = document.getElementById('stockOutCustomerRequired');
        if (reqEl) {
            reqEl.style.display = isDirectSale ? 'inline' : 'none';
        }
        if (stockOutCustomerInput && !stockOutCustomerHidden.value) {
            stockOutCustomerInput.placeholder = isDirectSale ? '🔍 Select active customer (required)...' : '🔍 Type customer name or phone...';
        }
    }

    if (stockOutReasonType) {
        stockOutReasonType.addEventListener('change', function() {
            updateStockOutCustomerRequirement();
            if (stockOutCustomerDropdown && stockOutCustomerDropdown.style.display === 'block') {
                renderCustomerSuggestions(stockOutCustomerInput ? stockOutCustomerInput.value : '');
            }
        });
    }

    const stockOutFormEl = stockOutModalEl ? stockOutModalEl.querySelector('form') : null;
    if (stockOutFormEl) {
        stockOutFormEl.addEventListener('submit', function(e) {
            if (stockOutReasonType && stockOutReasonType.value === 'direct_sale') {
                const custId = parseInt(stockOutCustomerHidden ? stockOutCustomerHidden.value : '0', 10);
                if (!custId || custId <= 0) {
                    e.preventDefault();
                    alert('Direct Sale / Walk-In stock out requires selecting an active registered customer.\n\nIf the customer is not yet in the system, please use the "+ Register Customer" link to register them first.');
                    if (stockOutCustomerInput) {
                        stockOutCustomerInput.focus();
                    }
                    return false;
                }
            }
        });
    }

    document.querySelectorAll('.js-stock-out').forEach(function(button) {
        button.addEventListener('click', function() {
            stockOutCurrent = parseInt(button.dataset.current || '0', 10);
            document.getElementById('stockOutId').value = button.dataset.id;
            document.getElementById('stockOutName').textContent = button.dataset.name;
            document.getElementById('stockOutCurrent').textContent = stockOutCurrent;
            document.getElementById('stockOutReorder').textContent = button.dataset.reorder || '0';
            stockOutQty.max = Math.max(1, stockOutCurrent);
            stockOutQty.value = stockOutCurrent > 0 ? 1 : 0;
            resetStockOutTagging();
            if (stockOutReasonType) {
                stockOutReasonType.value = 'direct_sale';
            }
            if (stockOutNotes) {
                stockOutNotes.value = '';
            }
            updateStockOutCustomerRequirement();
            updateStockOutPreview();
            stockOutModal.show();
        });
    });

    document.querySelectorAll('.js-transfer-stock').forEach(function(button) {
        button.addEventListener('click', function() {
            transferCurrent = parseInt(button.dataset.current || '0', 10);
            const recommendedQty = Math.max(1, parseInt(button.dataset.quantity || '1', 10) || 1);
            document.getElementById('transferSourceId').value = button.dataset.sourceId;
            document.getElementById('transferTargetId').value = button.dataset.targetId;
            document.getElementById('transferItemName').textContent = button.dataset.name;
            document.getElementById('transferFromBranch').textContent = button.dataset.from;
            document.getElementById('transferToBranch').textContent = button.dataset.to;
            document.getElementById('transferCurrent').textContent = transferCurrent;
            transferQty.max = Math.max(1, transferCurrent);
            transferQty.value = Math.min(recommendedQty, Math.max(1, transferCurrent));
            updateTransferPreview();
            transferStockModal.show();
        });
    });

    document.querySelectorAll('[data-transfer-done]').forEach(function(button) {
        button.addEventListener('click', async function() {
            const requestNumber = button.dataset.requestNumber || 'this request';
            const itemName = button.dataset.itemName || 'this item';
            const confirmed = window.confirm(
                'Mark ' + requestNumber + ' as shipped?\n\n' +
                'Item: ' + itemName + '\n\n' +
                'This will deduct the item from this branch inventory and put it in transit. The receiving branch will then accept or reject receipt.'
            );

            if (!confirmed) {
                return;
            }

            const originalContent = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Shipping...</span>';

            try {
                const body = new URLSearchParams();
                body.append('action', 'ship_transfer');
                body.append('transfer_id', button.dataset.transferId || '0');

                const response = await fetch('/hwtires/api/transfers-api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: body.toString()
                });
                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Unable to ship the transfer request.');
                }

                window.location.reload();
            } catch (error) {
                alert(error.message);
                button.disabled = false;
                button.innerHTML = originalContent;
            }
        });
    });

    document.querySelectorAll('[data-transfer-accept]').forEach(function(button) {
        button.addEventListener('click', async function() {
            const requestNumber = button.dataset.requestNumber || 'this request';
            const itemName = button.dataset.itemName || 'this item';
            const donorBranch = button.dataset.donorBranch || 'the sending branch';
            const qty = button.dataset.qty || '1';

            const confirmed = window.confirm(
                'Accept incoming transfer ' + requestNumber + '?\n\n' +
                'Item: ' + itemName + '\n' +
                'Quantity: ' + qty + '\n' +
                'From: ' + donorBranch + '\n\n' +
                'This will add ' + qty + ' unit(s) to this branch inventory.'
            );

            if (!confirmed) {
                return;
            }

            const originalContent = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            try {
                const body = new URLSearchParams();
                body.append('action', 'accept_transfer');
                body.append('transfer_id', button.dataset.transferId || '0');

                const response = await fetch('/hwtires/api/transfers-api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: body.toString()
                });
                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Unable to accept the transfer.');
                }

                window.location.reload();
            } catch (error) {
                alert(error.message);
                button.disabled = false;
                button.innerHTML = originalContent;
            }
        });
    });

    document.querySelectorAll('[data-transfer-reject]').forEach(function(button) {
        button.addEventListener('click', async function() {
            const requestNumber = button.dataset.requestNumber || 'this request';
            const itemName = button.dataset.itemName || 'this item';

            const reason = window.prompt(
                'Reject incoming transfer ' + requestNumber + ' (' + itemName + ')?\n\n' +
                'Please enter the reason for rejection (e.g. wrong item, defective, no longer needed):'
            );

            if (reason === null) {
                return; // User clicked Cancel
            }

            const trimmedReason = reason.trim();
            if (!trimmedReason) {
                alert('A rejection reason is required.');
                return;
            }

            const originalContent = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            try {
                const body = new URLSearchParams();
                body.append('action', 'reject_transfer');
                body.append('transfer_id', button.dataset.transferId || '0');
                body.append('reason', trimmedReason);

                const response = await fetch('/hwtires/api/transfers-api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: body.toString()
                });
                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Unable to reject the transfer.');
                }

                window.location.reload();
            } catch (error) {
                alert(error.message);
                button.disabled = false;
                button.innerHTML = originalContent;
            }
        });
    });

    document.querySelectorAll('[data-transfer-return]').forEach(function(button) {
        button.addEventListener('click', async function() {
            const requestNumber = button.dataset.requestNumber || 'this request';
            const itemName = button.dataset.itemName || 'this item';

            const confirmed = window.confirm(
                'Confirm physical return for ' + requestNumber + '?\n\n' +
                'Item: ' + itemName + '\n\n' +
                'Confirm that the returned stock has physically arrived back at this branch.\nThis will restore the item quantity in your inventory.'
            );

            if (!confirmed) {
                return;
            }

            const originalContent = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Restoring...</span>';

            try {
                const body = new URLSearchParams();
                body.append('action', 'confirm_return');
                body.append('transfer_id', button.dataset.transferId || '0');

                const response = await fetch('/hwtires/api/transfers-api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: body.toString()
                });
                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Unable to confirm return.');
                }

                window.location.reload();
            } catch (error) {
                alert(error.message);
                button.disabled = false;
                button.innerHTML = originalContent;
            }
        });
    });

    stockInQty.addEventListener('input', updateStockInPreview);
    stockOutQty.addEventListener('input', updateStockOutPreview);
    transferQty.addEventListener('input', updateTransferPreview);

    <?php if (!empty($requested_stock_in_item)): ?>
        stockInCurrent = <?php echo (int) $requested_stock_in_item['quantity']; ?>;
        document.getElementById('stockInId').value = <?php echo (int) $requested_stock_in_item['id']; ?>;
        document.getElementById('stockInName').textContent = <?php echo json_encode($requested_stock_in_item['item_name']); ?>;
        document.getElementById('stockInCurrent').textContent = stockInCurrent;
        document.getElementById('stockInReorder').textContent = <?php echo (int) $requested_stock_in_item['reorder_level']; ?>;
        stockInQty.value = <?php echo max(1, (int) ($_GET['quantity'] ?? 1)); ?>;
        if (document.getElementById('stockInSupplier')) {
            document.getElementById('stockInSupplier').value = <?php echo json_encode($_GET['supplier_name'] ?? $requested_stock_in_item['supplier_name'] ?? ''); ?>;
        }
        if (document.getElementById('stockInNotes')) {
            document.getElementById('stockInNotes').value = <?php echo json_encode($_GET['notes'] ?? ''); ?>;
        }
        if (document.getElementById('stockInSource')) {
            document.getElementById('stockInSource').value = <?php echo json_encode($_GET['source_type'] ?? 'supplier_delivery'); ?>;
        }
        if (document.getElementById('stockInRef')) {
            document.getElementById('stockInRef').value = '';
        }
        updateStockInPreview();
        stockInModal.show();
    <?php else: ?>
        const params = new URLSearchParams(window.location.search);
        const requestedAction = params.get('action');
        const requestedItemId = params.get('item_id') || params.get('inventory_id');
        const requestedQuantity = params.get('quantity');
        const requestedNotes = params.get('notes');
        const requestedSource = params.get('source_type');
        const requestedSupplier = params.get('supplier_name');

        if (requestedAction === 'stock_in' && requestedItemId) {
            const opener = Array.from(document.querySelectorAll('.js-stock-in')).find(function(button) {
                return button.dataset.id === requestedItemId;
            });

            if (opener) {
                opener.click();
                if (requestedQuantity) {
                    stockInQty.value = Math.max(1, parseInt(requestedQuantity, 10) || 1);
                    updateStockInPreview();
                }
                if (requestedSupplier && document.getElementById('stockInSupplier')) {
                    document.getElementById('stockInSupplier').value = requestedSupplier;
                }
                if (requestedSource && document.getElementById('stockInSource')) {
                    document.getElementById('stockInSource').value = requestedSource;
                }
                if (requestedNotes && document.getElementById('stockInNotes')) {
                    document.getElementById('stockInNotes').value = requestedNotes;
                }
            }
        }
    <?php endif; ?>
});
</script>

<?php require_once '../../includes/footer.php'; ?>
