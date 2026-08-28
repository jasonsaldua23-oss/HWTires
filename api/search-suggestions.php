<?php
/**
 * Context-aware search suggestions for record search bars.
 */

require_once __DIR__ . '/../includes/config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'suggestions' => []]);
    exit;
}

$user = app_get_session_user();
$is_admin = in_array($user['role'] ?? '', ['admin', 'owner', 'admin_owner'], true);
$user_branch_id = (int) ($user['branch_id'] ?? 0);

$query = trim((string) ($_GET['q'] ?? ''));
if (function_exists('mb_substr')) {
    $query = mb_substr($query, 0, 80);
} else {
    $query = substr($query, 0, 80);
}

$context = strtolower(trim((string) ($_GET['context'] ?? 'all')));
$context = preg_replace('/[^a-z0-9_-]/', '', $context);
$limit = min(12, max(5, (int) ($_GET['limit'] ?? 10)));

if ($query === '') {
    echo json_encode(['success' => true, 'suggestions' => []]);
    exit;
}

$branch_filter = 0;
foreach (['branch_id', 'branch'] as $branch_key) {
    if (isset($_GET[$branch_key]) && is_numeric($_GET[$branch_key])) {
        $branch_filter = max(0, (int) $_GET[$branch_key]);
        break;
    }
}

$category_filter = strtolower(trim((string) ($_GET['category'] ?? 'all')));
$valid_inventory_categories = ['tire', 'accessory', 'part'];
if (!in_array($category_filter, $valid_inventory_categories, true)) {
    $category_filter = 'all';
}

$like = '%' . $query . '%';
$search_terms = search_suggestions_terms($query);
$suggestions = [];
$seen = [];

function search_suggestions_add(&$suggestions, &$seen, $type, $label, $detail, $value) {
    $label = trim((string) $label);
    $value = trim((string) $value);

    if ($label === '' || $value === '') {
        return;
    }

    $key = strtolower($type . '|' . $value . '|' . $label);
    if (isset($seen[$key])) {
        return;
    }

    $seen[$key] = true;
    $suggestions[] = [
        'type' => $type,
        'label' => $label,
        'detail' => trim((string) $detail),
        'value' => $value,
    ];
}

function search_suggestions_fetch($sql, array $params = []) {
    global $pdo;

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Search suggestions query error: ' . $e->getMessage());
        return [];
    }
}

function search_suggestions_terms($query) {
    $normalized = preg_replace('/[^a-z0-9]+/i', ' ', (string) $query);
    $terms = preg_split('/\s+/', trim((string) $normalized));

    return array_values(array_filter($terms, function ($term) {
        return $term !== '';
    }));
}

function search_suggestions_match_clause(array $columns, &$params, $like) {
    global $search_terms;

    if (!empty($search_terms)) {
        $groups = [];
        foreach ($search_terms as $term) {
            $variants = function_exists('app_search_term_variants') ? app_search_term_variants($term) : [$term];
            $variant_groups = [];
            foreach ($variants as $v_term) {
                $group = [];
                foreach ($columns as $column) {
                    $group[] = "LOWER(COALESCE({$column}, '')) LIKE ?";
                    $params[] = '%' . strtolower($v_term) . '%';
                }
                $variant_groups[] = '(' . implode(' OR ', $group) . ')';
            }
            $groups[] = '(' . implode(' OR ', $variant_groups) . ')';
        }

        return '(' . implode(' AND ', $groups) . ')';
    }

    $fallback = [];
    foreach ($columns as $column) {
        $fallback[] = "LOWER(COALESCE({$column}, '')) LIKE ?";
        $params[] = strtolower($like);
    }

    return '(' . implode(' OR ', $fallback) . ')';
}

function search_suggestions_branch_clause($alias, &$params, $branch_filter = 0, $force_user_branch = false) {
    global $is_admin, $user_branch_id;

    $branch_id = 0;
    if ($force_user_branch && !$is_admin && $user_branch_id > 0) {
        $branch_id = $user_branch_id;
    } elseif ($branch_filter > 0) {
        $branch_id = (int) $branch_filter;
    }

    if ($branch_id <= 0) {
        return '';
    }

    $params[] = $branch_id;
    return " AND {$alias}.branch_id = ?";
}

function search_suggestions_customer_branch_clause($customer_alias, &$params, $branch_filter = 0) {
    if ($branch_filter <= 0) {
        return '';
    }

    $params[] = (int) $branch_filter;
    return " AND ({$customer_alias}.branch_id = ? OR EXISTS (
        SELECT 1
        FROM customer_branch_records cbr_suggest
        WHERE cbr_suggest.customer_id = {$customer_alias}.id
          AND cbr_suggest.branch_id = ?
    ) OR EXISTS (
        SELECT 1
        FROM vehicles v_suggest
        WHERE v_suggest.customer_id = {$customer_alias}.id
          AND v_suggest.branch_id = ?
    ))";
}

function search_suggestions_customers($like, $limit, $branch_filter, &$suggestions, &$seen) {
    $params = [];
    $match = search_suggestions_match_clause(['c.name', 'c.phone_mobile', 'c.contact'], $params, $like);
    
    $branch_sql = '';
    if ($branch_filter > 0) {
        $branch_sql = " AND (c.branch_id = ? OR EXISTS (
            SELECT 1 FROM vehicles v_b WHERE v_b.customer_id = c.id AND v_b.branch_id = ?
        ))";
        $params[] = (int) $branch_filter;
        $params[] = (int) $branch_filter;
    }

    $rows = search_suggestions_fetch("
        SELECT DISTINCT
            c.id,
            c.name,
            COALESCE(NULLIF(c.phone_mobile, ''), NULLIF(c.contact, ''), '') AS phone
        FROM customers c
        WHERE c.status = 'active'
          AND {$match}
          {$branch_sql}
        ORDER BY c.name ASC
        LIMIT {$limit}
    ", $params);

    foreach ($rows as $row) {
        search_suggestions_add(
            $suggestions,
            $seen,
            'Customer',
            $row['name'] ?? '',
            $row['phone'] ?? '',
            $row['name'] ?? ''
        );
    }

    // Also match vehicles directly with customer name
    $v_params = [];
    $v_match = search_suggestions_match_clause(['v.plate_number', 'v.make', 'v.model', 'c.name'], $v_params, $like);
    $v_branch_sql = '';
    if ($branch_filter > 0) {
        $v_branch_sql = " AND (v.branch_id = ? OR c.branch_id = ?)";
        $v_params[] = (int) $branch_filter;
        $v_params[] = (int) $branch_filter;
    }

    $v_rows = search_suggestions_fetch("
        SELECT DISTINCT
            v.plate_number,
            v.make,
            v.model,
            c.name AS customer_name
        FROM vehicles v
        INNER JOIN customers c ON c.id = v.customer_id
        WHERE v.status = 'active'
          AND c.status = 'active'
          AND {$v_match}
          {$v_branch_sql}
        ORDER BY v.plate_number ASC
        LIMIT {$limit}
    ", $v_params);

    foreach ($v_rows as $row) {
        $plate = trim((string) ($row['plate_number'] ?? ''));
        $vehicle = trim(($row['make'] ?? '') . ' ' . ($row['model'] ?? ''));
        search_suggestions_add(
            $suggestions,
            $seen,
            'Vehicle',
            $plate !== '' ? $plate . ' - ' . ($row['customer_name'] ?? '') : $vehicle,
            $vehicle,
            $plate !== '' ? $plate : $vehicle
        );
    }
}

function search_suggestions_vehicles($like, $limit, $branch_filter, &$suggestions, &$seen) {
    $params = [];
    $match = search_suggestions_match_clause(['v.plate_number', 'v.make', 'v.model', 'c.name'], $params, $like);
    $branch_sql = '';
    if ($branch_filter > 0) {
        $branch_sql = " AND (v.branch_id = ? OR c.branch_id = ?)";
        $params[] = (int) $branch_filter;
        $params[] = (int) $branch_filter;
    }

    $rows = search_suggestions_fetch("
        SELECT DISTINCT
            v.plate_number,
            v.make,
            v.model,
            v.year,
            c.name AS customer_name,
            b.name AS branch_name
        FROM vehicles v
        INNER JOIN customers c ON c.id = v.customer_id
        LEFT JOIN branches b ON b.id = v.branch_id
        WHERE v.status = 'active'
          AND c.status = 'active'
          AND {$match}
          {$branch_sql}
        ORDER BY v.updated_at DESC, v.id DESC
        LIMIT {$limit}
    ", $params);

    foreach ($rows as $row) {
        $plate = trim((string) ($row['plate_number'] ?? ''));
        $vehicle = trim(($row['make'] ?? '') . ' ' . ($row['model'] ?? '') . ' ' . ($row['year'] ?? ''));
        search_suggestions_add(
            $suggestions,
            $seen,
            'Vehicle',
            $plate !== '' ? $plate . ' - ' . $vehicle : $vehicle,
            trim(($row['customer_name'] ?? '') . ' | ' . ($row['branch_name'] ?? ''), ' |'),
            $plate !== '' ? $plate : $vehicle
        );
    }
}

function search_suggestions_service_operations($like, $limit, $branch_filter, &$suggestions, &$seen) {
    $params = [];
    $match_clause = search_suggestions_match_clause([
        'q.quotation_number',
        'c.name',
        'c.phone_mobile',
        'c.contact',
        'v.plate_number',
        'v.make',
        'v.model',
        'qi.item_name',
        'b.name',
    ], $params, $like);
    $branch_clause = search_suggestions_branch_clause('q', $params, $branch_filter, true);
    $rows = search_suggestions_fetch("
        SELECT DISTINCT
            q.quotation_number,
            q.status,
            q.quotation_date,
            c.name AS customer_name,
            COALESCE(NULLIF(c.phone_mobile, ''), NULLIF(c.contact, ''), '') AS phone,
            v.plate_number,
            v.make,
            v.model,
            b.name AS branch_name
        FROM quotations q
        INNER JOIN customers c ON c.id = q.customer_id
        LEFT JOIN vehicles v ON v.id = q.vehicle_id
        LEFT JOIN branches b ON b.id = q.branch_id
        LEFT JOIN quotation_items qi ON qi.quotation_id = q.id
        WHERE {$match_clause}
          AND q.status <> 'archived'
        {$branch_clause}
        ORDER BY q.quotation_date DESC, q.id DESC
        LIMIT {$limit}
    ", $params);

    foreach ($rows as $row) {
        $vehicle = trim(($row['make'] ?? '') . ' ' . ($row['model'] ?? ''));
        $label_parts = array_filter([
            $row['customer_name'] ?? '',
            $row['phone'] ?? '',
        ]);
        $detail = trim(implode(' | ', array_filter([
            $vehicle !== '' ? trim($vehicle . ' (' . ($row['plate_number'] ?? '') . ')', ' ()') : ($row['plate_number'] ?? ''),
            $row['branch_name'] ?? '',
            ucfirst((string) ($row['status'] ?? '')),
            $row['quotation_date'] ?? '',
            $row['quotation_number'] ?? '',
        ])));
        search_suggestions_add(
            $suggestions,
            $seen,
            'Customer',
            implode(' - ', $label_parts),
            $detail,
            $row['customer_name'] ?? ''
        );
    }
}

function search_suggestions_job_orders($like, $limit, $branch_filter, &$suggestions, &$seen) {
    $params = [$like, $like, $like, $like, $like, $like, $like];
    $branch_clause = search_suggestions_branch_clause('jo', $params, $branch_filter, true);
    $rows = search_suggestions_fetch("
        SELECT DISTINCT
            jo.job_number,
            jo.status,
            jo.job_date,
            jo.assigned_technician_name,
            c.name AS customer_name,
            v.plate_number,
            v.make,
            v.model,
            b.name AS branch_name
        FROM job_orders jo
        INNER JOIN customers c ON c.id = jo.customer_id
        LEFT JOIN vehicles v ON v.id = jo.vehicle_id
        LEFT JOIN branches b ON b.id = jo.branch_id
        LEFT JOIN quotation_items qi ON qi.quotation_id = jo.quotation_id
        WHERE (
              jo.job_number LIKE ?
              OR c.name LIKE ?
              OR v.plate_number LIKE ?
              OR v.make LIKE ?
              OR v.model LIKE ?
              OR jo.assigned_technician_name LIKE ?
              OR qi.item_name LIKE ?
        )
          AND jo.status <> 'archived'
        {$branch_clause}
        ORDER BY jo.job_date DESC, jo.id DESC
        LIMIT {$limit}
    ", $params);

    foreach ($rows as $row) {
        $vehicle = trim(($row['make'] ?? '') . ' ' . ($row['model'] ?? ''));
        $detail = trim(implode(' | ', array_filter([
            $row['plate_number'] ?? '',
            $vehicle,
            $row['branch_name'] ?? '',
            $row['assigned_technician_name'] ?? '',
            ucfirst(str_replace('-', ' ', (string) ($row['status'] ?? ''))),
        ])));
        search_suggestions_add(
            $suggestions,
            $seen,
            'Job Order',
            trim(($row['job_number'] ?? '') . ' - ' . ($row['customer_name'] ?? ''), ' -'),
            $detail,
            $row['job_number'] ?: ($row['customer_name'] ?? '')
        );
    }
}

function search_suggestions_inventory($like, $limit, $branch_filter, $category_filter, &$suggestions, &$seen) {
    $params = [$like, $like, $like, $like, $like];
    $branch_clause = search_suggestions_branch_clause('i', $params, $branch_filter, true);
    $category_clause = '';
    if ($category_filter !== 'all') {
        $category_clause = ' AND i.category = ?';
        $params[] = $category_filter;
    }

    $rows = search_suggestions_fetch("
        SELECT DISTINCT
            i.item_name,
            i.brand,
            i.size,
            i.sku,
            i.quantity,
            i.category,
            b.name AS branch_name
        FROM inventory_items i
        LEFT JOIN branches b ON b.id = i.branch_id
        WHERE i.status = 'active'
          AND (
              i.item_name LIKE ?
              OR i.brand LIKE ?
              OR i.size LIKE ?
              OR i.sku LIKE ?
              OR i.description LIKE ?
          )
          {$branch_clause}
          {$category_clause}
        ORDER BY i.updated_at DESC, i.item_name ASC
        LIMIT {$limit}
    ", $params);

    foreach ($rows as $row) {
        $detail = trim(implode(' | ', array_filter([
            $row['branch_name'] ?? '',
            $row['brand'] ?? '',
            $row['size'] ?? '',
            ucfirst((string) ($row['category'] ?? '')),
            'Stock ' . (int) ($row['quantity'] ?? 0),
        ])));
        search_suggestions_add(
            $suggestions,
            $seen,
            'Inventory',
            $row['item_name'] ?? '',
            $detail,
            $row['item_name'] ?? ''
        );
    }
}

function search_suggestions_transfers($like, $limit, $branch_filter, &$suggestions, &$seen) {
    search_suggestions_inventory($like, $limit, $branch_filter, 'all', $suggestions, $seen);
}

switch ($context) {
    case 'customers':
    case 'customer':
        search_suggestions_customers($like, $limit, $branch_filter, $suggestions, $seen);
        break;

    case 'vehicles':
    case 'vehicle':
        search_suggestions_vehicles($like, $limit, $branch_filter, $suggestions, $seen);
        break;

    case 'quotations':
    case 'service_operations':
    case 'historical_transactions':
        search_suggestions_service_operations($like, $limit, $branch_filter, $suggestions, $seen);
        break;

    case 'job_orders':
    case 'service_status':
        search_suggestions_job_orders($like, $limit, $branch_filter, $suggestions, $seen);
        break;

    case 'inventory':
    case 'forecasting':
        search_suggestions_inventory($like, $limit, $branch_filter, $category_filter, $suggestions, $seen);
        break;

    case 'transfers':
        search_suggestions_transfers($like, $limit, $branch_filter, $suggestions, $seen);
        break;

    default:
        search_suggestions_customers($like, 4, $branch_filter, $suggestions, $seen);
        search_suggestions_vehicles($like, 4, $branch_filter, $suggestions, $seen);
        search_suggestions_service_operations($like, 4, $branch_filter, $suggestions, $seen);
        search_suggestions_job_orders($like, 4, $branch_filter, $suggestions, $seen);
        search_suggestions_inventory($like, 4, $branch_filter, $category_filter, $suggestions, $seen);
        break;
}

echo json_encode([
    'success' => true,
    'suggestions' => array_slice($suggestions, 0, $limit),
], JSON_UNESCAPED_UNICODE);
