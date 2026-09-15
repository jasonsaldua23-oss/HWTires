<?php
/**
 * Admin Inventory Forecasting
 */

require_once '../../includes/config.php';
require_once '../../includes/forecasting.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$page_title = 'Inventory Forecasting';
$user = app_get_session_user();

if (!function_exists('forecast_chart_percent')) {
    function forecast_chart_percent($value, $max) {
        if ((float) $value <= 0 || (float) $max <= 0) {
            return 0;
        }

        return max(4, min(100, round(((float) $value / (float) $max) * 100, 2)));
    }
}

if (!function_exists('forecast_risk_percent')) {
    function forecast_risk_percent($duration, $max_duration = 8) {
        if ($duration === null || $duration === '' || !is_numeric($duration)) {
            return 100;
        }

        $duration = max(0, (float) $duration);
        $max_duration = max(1, (float) $max_duration);

        return max(8, min(100, round(100 - ((min($duration, $max_duration) / $max_duration) * 92), 2)));
    }
}

$inventory_branches = forecast_load_inventory_branches($pdo, $user);
$allowed_branch_ids = array_map(static function ($branch) {
    return (int) $branch['id'];
}, $inventory_branches);

$category_filter = strtolower(trim($_GET['category'] ?? 'all'));
$brand_filter = trim((string) ($_GET['brand'] ?? ''));
$size_filter = trim((string) ($_GET['size'] ?? ''));
$branch_filter = trim($_GET['branch'] ?? 'all');
$status_filter = strtolower(trim($_GET['status'] ?? 'all'));
$search_filter = trim($_GET['search'] ?? '');
$year_filter = trim($_GET['year'] ?? 'latest');
$view_filter = strtolower(trim($_GET['view'] ?? 'weekly'));
$sort_filter = strtolower(trim($_GET['sort'] ?? 'urgency'));
$page_sizes = [10, 20, 50];
$per_page = (int) ($_GET['per_page'] ?? 10);
if (!in_array($per_page, $page_sizes, true)) {
    $per_page = 10;
}
$page = max(1, (int) ($_GET['page'] ?? 1));

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
        $b_key = strtolower($b);
        $canonical_b = strcasecmp($b, 'bridgestone') === 0 ? 'BRIDGESTONE' : $b;
        if (!isset($all_brands[$b_key])) {
            $all_brands[$b_key] = $canonical_b;
        }
        if ($cat !== '' && !isset($brands_by_category[$cat][$b_key])) {
            $brands_by_category[$cat][$b_key] = $canonical_b;
        }
    }
    if ($s !== '') {
        $all_sizes[$s] = $s;
        if ($b !== '') {
            $canonical_b = strcasecmp($b, 'bridgestone') === 0 ? 'BRIDGESTONE' : $b;
            $sizes_by_brand[$canonical_b][$s] = $s;
        }
    }
}

$available_brands = ($category_filter !== 'all' && isset($brands_by_category[$category_filter]))
    ? array_values($brands_by_category[$category_filter])
    : array_values($all_brands);

$canonical_selected_brand = strcasecmp($brand_filter, 'bridgestone') === 0 ? 'BRIDGESTONE' : $brand_filter;

$available_sizes = ($canonical_selected_brand !== '' && isset($sizes_by_brand[$canonical_selected_brand]))
    ? array_values($sizes_by_brand[$canonical_selected_brand])
    : array_values($all_sizes);

$forecast = forecast_build_inventory_dss($pdo, [
    'category' => $category_filter,
    'brand' => $brand_filter,
    'size' => $size_filter,
    'branch' => $branch_filter,
    'status' => $status_filter,
    'search' => $search_filter,
    'year' => $year_filter,
    'view' => $view_filter,
    'sort' => $sort_filter,
    'allowed_branch_ids' => $allowed_branch_ids,
]);

$category_filter = $forecast['filters']['category'];
$brand_filter = $forecast['filters']['brand'] ?? $brand_filter;
$size_filter = $forecast['filters']['size'] ?? $size_filter;
$branch_filter = $forecast['filters']['branch'];
$status_filter = $forecast['filters']['status'];
$sort_filter = $forecast['filters']['sort'];
$search_filter = $forecast['filters']['search'];
$year_filter = $forecast['filters']['year'];
$view_filter = $forecast['filters']['view'];
$analysis_date = $forecast['filters']['analysis_date'];
$summary = $forecast['summary'];
$all_forecast_items = $forecast['items'];
$total_records = count($all_forecast_items);
$total_pages = max(1, (int) ceil($total_records / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;
$showing_from = $total_records > 0 ? $offset + 1 : 0;
$showing_to = min($offset + $per_page, $total_records);
$forecast_items = array_slice($all_forecast_items, $offset, $per_page);
$decision_support = $forecast['decision_support'];
$available_years = forecast_load_transaction_years($pdo, $allowed_branch_ids);
$forecast_view_tabs = [
    'weekly' => ['label' => 'Weekly Forecast', 'icon' => 'far fa-calendar-days'],
    'monthly' => ['label' => 'Monthly Forecast', 'icon' => 'far fa-calendar'],
    'items' => ['label' => 'Item Forecast', 'icon' => 'fas fa-list-check'],
];
$growth_value = (int) ($summary['monthly_growth_percent'] ?? 0);
$growth_label = ($growth_value > 0 ? '+' : '') . $growth_value . '%';

if ($view_filter === 'monthly') {
    $forecast_summary_cards = [
        ['label' => 'Critical Items', 'value' => number_format((int) $summary['critical']), 'note' => 'Need monthly order attention', 'icon' => 'fas fa-triangle-exclamation', 'class' => 'icon-critical'],
        ['label' => 'Monthly Order', 'value' => number_format((int) $summary['monthly_reorder_units']) . ' units', 'note' => 'Suggested stock-in quantity', 'icon' => 'fas fa-cart-shopping', 'class' => 'icon-value'],
        ['label' => 'Demand Change', 'value' => $growth_label, 'note' => 'Latest 30 days vs previous 30', 'icon' => 'fas fa-arrow-trend-up', 'class' => 'icon-tracked'],
        ['label' => 'Monthly Demand', 'value' => number_format((int) $summary['monthly_demand_units']) . ' units', 'note' => 'Estimated from last 90 days', 'icon' => 'fas fa-chart-column', 'class' => 'icon-warning'],
    ];
} elseif ($view_filter === 'items') {
    $forecast_summary_cards = [
        ['label' => 'Tracked Items', 'value' => number_format((int) $summary['total']), 'note' => 'Active inventory records', 'icon' => 'fas fa-boxes-stacked', 'class' => 'icon-tracked'],
        ['label' => 'Critical', 'value' => number_format((int) $summary['critical']), 'note' => 'Less than 2 weeks coverage', 'icon' => 'fas fa-triangle-exclamation', 'class' => 'icon-critical'],
        ['label' => 'Warning', 'value' => number_format((int) $summary['warning']), 'note' => '2-4 weeks coverage', 'icon' => 'fas fa-cube', 'class' => 'icon-warning'],
        ['label' => 'Healthy', 'value' => number_format((int) $summary['good']), 'note' => 'Above forecast threshold', 'icon' => 'far fa-circle-check', 'class' => 'icon-value'],
    ];
} else {
    $forecast_summary_cards = [
        ['label' => 'Critical Items', 'value' => number_format((int) $summary['critical']), 'note' => '< 2 weeks stock', 'icon' => 'fas fa-triangle-exclamation', 'class' => 'icon-critical'],
        ['label' => 'Reorder Needed', 'value' => number_format((int) $summary['weekly_reorder_units']) . ' units', 'note' => 'Suggested weekly coverage', 'icon' => 'fas fa-cart-shopping', 'class' => 'icon-value'],
        ['label' => 'Low Stock Soon', 'value' => number_format((int) $summary['warning']), 'note' => '2-4 weeks stock', 'icon' => 'fas fa-cube', 'class' => 'icon-warning'],
        ['label' => 'Transfer Matches', 'value' => number_format((int) $summary['transfer_matches']), 'note' => 'Possible branch transfers', 'icon' => 'fas fa-right-left', 'class' => 'icon-tracked'],
    ];
}

$forecast_scope_label = 'All Branches';
if ($branch_filter !== 'all') {
    foreach ($inventory_branches as $branch) {
        if ((string) (int) $branch['id'] === $branch_filter) {
            $forecast_scope_label = forecast_branch_label($branch['name']);
            break;
        }
    }
}

$forecast_risk_items = array_values(array_filter($all_forecast_items, static function ($item) {
    $status = strtolower((string) ($item['status'] ?? ''));

    return in_array($status, ['critical', 'warning'], true)
        || (int) ($item['recommended_order'] ?? 0) > 0
        || (int) ($item['recommended_monthly_order'] ?? 0) > 0;
}));
$forecast_risk_items = array_slice($forecast_risk_items, 0, 6);

$movement_chart_year = $year_filter !== 'latest' ? (int) $year_filter : (int) date('Y', strtotime($analysis_date ?: 'now'));
$movement_chart = [];
for ($month = 1; $month <= 12; $month++) {
    $movement_chart[$month] = [
        'label' => date('M', mktime(0, 0, 0, $month, 1)),
        'stock_in' => 0,
        'stock_out' => 0,
    ];
}

$movement_category_totals = [
    'tire' => ['category' => 'tire', 'label' => forecast_category_label('tire', true), 'stock_in' => 0, 'stock_out' => 0],
    'accessory' => ['category' => 'accessory', 'label' => forecast_category_label('accessory', true), 'stock_in' => 0, 'stock_out' => 0],
    'part' => ['category' => 'part', 'label' => forecast_category_label('part', true), 'stock_in' => 0, 'stock_out' => 0],
];
$movement_chart_max = 1;
if (!empty($allowed_branch_ids)) {
    $movement_where = [
        'YEAR(t.created_at) = ?',
        "COALESCE(t.reference_type, '') <> 'opening_balance'",
    ];
    $movement_params = [$movement_chart_year];

    if ($branch_filter !== 'all') {
        $movement_where[] = 'i.branch_id = ?';
        $movement_params[] = (int) $branch_filter;
    } else {
        $movement_where[] = 'i.branch_id IN (' . implode(',', array_fill(0, count($allowed_branch_ids), '?')) . ')';
        $movement_params = array_merge($movement_params, $allowed_branch_ids);
    }

    if ($category_filter !== 'all') {
        $movement_where[] = 'i.category = ?';
        $movement_params[] = $category_filter;
    }

    if ($search_filter !== '') {
        foreach (app_search_terms($search_filter) as $term) {
            $movement_where[] = "(
                i.item_name LIKE ?
                OR i.brand LIKE ?
                OR i.size LIKE ?
                OR i.sku LIKE ?
                OR i.description LIKE ?
                OR i.category LIKE ?
                OR b.name LIKE ?
            )";
            $movement_like = '%' . $term . '%';
            $movement_params = array_merge($movement_params, array_fill(0, 7, $movement_like));
        }
    }

    $stmt = $pdo->prepare("
        SELECT
            MONTH(t.created_at) AS movement_month,
            i.category,
            COALESCE(SUM(CASE WHEN LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_in' THEN ABS(t.quantity) ELSE 0 END), 0) AS stock_in_units,
            COALESCE(SUM(CASE WHEN LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out' THEN ABS(t.quantity) ELSE 0 END), 0) AS stock_out_units
        FROM inventory_transactions t
        INNER JOIN inventory_items i ON i.id = t.item_id
        LEFT JOIN branches b ON b.id = i.branch_id
        WHERE " . implode(' AND ', $movement_where) . "
        GROUP BY MONTH(t.created_at), i.category
        ORDER BY movement_month ASC, i.category ASC
    ");
    $stmt->execute($movement_params);

    foreach ($stmt->fetchAll() as $row) {
        $month = (int) $row['movement_month'];
        if (!isset($movement_chart[$month])) {
            continue;
        }

        $stock_in_units = (int) $row['stock_in_units'];
        $stock_out_units = (int) $row['stock_out_units'];
        $movement_chart[$month]['stock_in'] += $stock_in_units;
        $movement_chart[$month]['stock_out'] += $stock_out_units;
        $movement_chart_max = max($movement_chart_max, $movement_chart[$month]['stock_in'], $movement_chart[$month]['stock_out']);

        $category = strtolower((string) ($row['category'] ?? 'part'));
        if (!isset($movement_category_totals[$category])) {
            $category = 'part';
        }
        $movement_category_totals[$category]['stock_in'] += $stock_in_units;
        $movement_category_totals[$category]['stock_out'] += $stock_out_units;
    }
}

$movement_stock_in_total = array_sum(array_map(static fn($month) => (int) $month['stock_in'], $movement_chart));
$movement_stock_out_total = array_sum(array_map(static fn($month) => (int) $month['stock_out'], $movement_chart));
$movement_peak = ['label' => '-', 'value' => 0, 'type' => 'Stock movement'];
foreach ($movement_chart as $month) {
    if ((int) $month['stock_in'] > $movement_peak['value']) {
        $movement_peak = ['label' => $month['label'], 'value' => (int) $month['stock_in'], 'type' => 'Stock in'];
    }
    if ((int) $month['stock_out'] > $movement_peak['value']) {
        $movement_peak = ['label' => $month['label'], 'value' => (int) $month['stock_out'], 'type' => 'Stock out'];
    }
}
$movement_category_totals = array_values($movement_category_totals);
$movement_category_max = 1;
foreach ($movement_category_totals as $category_total) {
    $movement_category_max = max($movement_category_max, (int) $category_total['stock_in'], (int) $category_total['stock_out']);
}
?>

<?php require_once '../../includes/header.php'; ?>
<?php require_once '../../includes/sidebar.php'; ?>

<main class="forecasting-page">
    <header class="forecasting-hero forecast-scope-hero">
        <div>
            <h1>Inventory Forecasting</h1>
            <p>Predictive analysis based on historical consumption data<?php echo !empty($analysis_date) ? ' as of ' . esc_html(date('F d, Y', strtotime($analysis_date))) : ''; ?></p>
        </div>
    </header>

    <?php
    $flash_message = get_flash_message();
    if ($flash_message):
        $flash_type = $flash_message['type'] === 'error' ? 'danger' : $flash_message['type'];
    ?>
        <div class="alert alert-<?php echo esc_attr($flash_type); ?> alert-dismissible fade show forecasting-flash" role="alert">
            <?php echo esc_html($flash_message['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <section class="forecast-summary-grid" aria-label="Forecast summary">
        <?php foreach ($forecast_summary_cards as $card): ?>
            <article class="forecast-summary-card">
                <div>
                    <span><?php echo esc_html($card['label']); ?></span>
                    <strong><?php echo esc_html($card['value']); ?></strong>
                    <p><?php echo esc_html($card['note']); ?></p>
                </div>
                <span class="forecast-summary-icon <?php echo esc_attr($card['class']); ?>"><i class="<?php echo esc_attr($card['icon']); ?>"></i></span>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="forecast-overview-panel" aria-label="Forecast overview">
        <header class="forecast-overview-header">
            <div>
                <h2>Forecast Overview</h2>
                <p>Top stock risks for <?php echo esc_html($forecast_scope_label); ?> based on the selected forecast view.</p>
            </div>
            <span class="forecast-overview-count"><?php echo count($forecast_risk_items); ?> priority items</span>
        </header>
        <?php if (empty($forecast_risk_items)): ?>
            <div class="forecast-risk-empty">No priority forecast risks for the selected filters.</div>
        <?php else: ?>
            <div class="forecast-risk-list">
                <?php foreach ($forecast_risk_items as $risk_item): ?>
                    <?php
                    $risk_inventory_item = $risk_item['item'];
                    $risk_status = strtolower((string) ($risk_item['status'] ?? 'good'));
                    $risk_duration = $view_filter === 'monthly' ? ($risk_item['months_duration'] ?? null) : ($risk_item['stock_duration'] ?? null);
                    $risk_duration_label = $view_filter === 'monthly'
                        ? ($risk_item['months_duration_human'] ?? 'No movement data')
                        : ($risk_item['duration_human'] ?? 'No movement data');
                    $risk_order = $view_filter === 'monthly'
                        ? (int) ($risk_item['recommended_monthly_order'] ?? 0)
                        : (int) ($risk_item['recommended_order'] ?? 0);
                    $risk_width = forecast_risk_percent($risk_duration, $view_filter === 'monthly' ? 4 : 8);
                    ?>
                    <article class="forecast-risk-row risk-<?php echo esc_attr($risk_status); ?>">
                        <div class="forecast-risk-main">
                            <strong><?php echo esc_html(app_display_item_name($risk_inventory_item['item_name'], $risk_inventory_item['category'] ?? null)); ?></strong>
                            <span>
                                <?php echo esc_html(forecast_category_label($risk_inventory_item['category'])); ?>
                                &middot;
                                <?php echo esc_html(forecast_branch_label($risk_inventory_item['branch_name'])); ?>
                            </span>
                        </div>
                        <div class="forecast-risk-track" aria-hidden="true">
                            <i style="width: <?php echo esc_attr((string) $risk_width); ?>%"></i>
                        </div>
                        <div class="forecast-risk-value">
                            <strong><?php echo esc_html($risk_duration_label); ?></strong>
                            <span><?php echo $risk_order > 0 ? 'Order ' . number_format($risk_order) . ' units' : 'Monitor'; ?></span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="forecast-secondary-filter-card hw-filter-card" aria-label="Forecast filters">
        <form method="get" action="./#forecast-analysis" class="forecasting-unified-filter-form hw-filter-toolbar">
            <input type="hidden" name="per_page" value="<?php echo (int) $per_page; ?>">

            <!-- Row 1: Main Filters -->
            <div class="hw-filter-cluster forecasting-filters-row">
                <div class="hw-filter-group hw-group-md">
                    <label for="adminForecastCategoryFilter" class="hw-filter-label">Category</label>
                    <select name="category" id="adminForecastCategoryFilter" class="hw-filter-select">
                        <?php foreach (['all' => 'All Items', 'tire' => 'Tires', 'accessory' => 'Accessories', 'part' => 'Parts'] as $category_value => $category_label): ?>
                            <option value="<?php echo esc_attr($category_value); ?>" <?php echo $category_filter === $category_value ? 'selected' : ''; ?>>
                                <?php echo esc_html($category_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="hw-filter-group hw-group-md" id="adminForecastBrandField" style="<?php echo ($category_filter === 'all' && $brand_filter === '') ? 'display: none;' : ''; ?>">
                    <label for="adminForecastBrandFilter" class="hw-filter-label">Brand</label>
                    <select name="brand" id="adminForecastBrandFilter" class="hw-filter-select">
                        <option value="">All Brands</option>
                        <?php foreach ($available_brands as $brand_name): ?>
                            <option value="<?php echo esc_attr($brand_name); ?>" <?php echo strcasecmp($brand_filter, $brand_name) === 0 ? 'selected' : ''; ?>>
                                <?php echo esc_html($brand_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="hw-filter-group hw-group-md" id="adminForecastSizeField" style="<?php echo ($category_filter === 'all' && $size_filter === '') ? 'display: none;' : ''; ?>">
                    <label for="adminForecastSizeFilter" class="hw-filter-label">Size / Spec</label>
                    <select name="size" id="adminForecastSizeFilter" class="hw-filter-select">
                        <option value="">All Sizes</option>
                        <?php foreach ($available_sizes as $size_val): ?>
                            <option value="<?php echo esc_attr($size_val); ?>" <?php echo $size_filter === $size_val ? 'selected' : ''; ?>>
                                <?php echo esc_html($size_val); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="hw-filter-group hw-group-branch">
                    <label for="adminForecastBranchFilter" class="hw-filter-label">Branch</label>
                    <select name="branch" id="adminForecastBranchFilter" class="hw-filter-select">
                        <option value="all" <?php echo $branch_filter === 'all' ? 'selected' : ''; ?>>All Branches</option>
                        <?php foreach ($inventory_branches as $branch): ?>
                            <?php $branch_value = (string) (int) $branch['id']; ?>
                            <option value="<?php echo esc_attr($branch_value); ?>" <?php echo $branch_filter === $branch_value ? 'selected' : ''; ?>>
                                <?php echo esc_html(forecast_branch_label($branch['name'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="hw-filter-group hw-group-status">
                    <label for="adminForecastStatusFilter" class="hw-filter-label">Status</label>
                    <select name="status" id="adminForecastStatusFilter" class="hw-filter-select">
                        <?php foreach (['all' => 'All Statuses', 'critical' => 'Critical', 'warning' => 'Warning', 'watch' => 'Watch', 'good' => 'Good'] as $status_value => $status_label): ?>
                            <option value="<?php echo esc_attr($status_value); ?>" <?php echo $status_filter === $status_value ? 'selected' : ''; ?>>
                                <?php echo esc_html($status_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="hw-filter-group hw-group-md">
                    <label for="adminForecastViewFilter" class="hw-filter-label">Forecast</label>
                    <select name="view" id="adminForecastViewFilter" class="hw-filter-select">
                        <?php foreach ($forecast_view_tabs as $tab_key => $tab): ?>
                            <option value="<?php echo esc_attr($tab_key); ?>" <?php echo $view_filter === $tab_key ? 'selected' : ''; ?>>
                                <?php echo esc_html($tab['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="hw-filter-group hw-group-md">
                    <label for="adminForecastSortFilter" class="hw-filter-label">Sort By</label>
                    <select name="sort" id="adminForecastSortFilter" class="hw-filter-select">
                        <?php
                        $sort_options = [
                            'urgency' => 'Highest Risk / Urgency',
                            'demand' => 'Highest Demand (Fast Movers)',
                            'growth' => 'Demand Surge (+% Growth)',
                            'stock_asc' => 'Lowest Stock First',
                            'name' => 'Product Name (A-Z)',
                        ];
                        foreach ($sort_options as $sort_val => $sort_label):
                        ?>
                            <option value="<?php echo esc_attr($sort_val); ?>" <?php echo $sort_filter === $sort_val ? 'selected' : ''; ?>>
                                <?php echo esc_html($sort_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="hw-filter-group hw-group-sm">
                    <label for="adminForecastYearFilter" class="hw-filter-label">Analysis Year</label>
                    <select name="year" id="adminForecastYearFilter" class="hw-filter-select">
                        <option value="latest" <?php echo $year_filter === 'latest' ? 'selected' : ''; ?>>Latest Available</option>
                        <?php foreach ($available_years as $year): ?>
                            <option value="<?php echo (int) $year; ?>" <?php echo $year_filter === (string) $year ? 'selected' : ''; ?>>
                                <?php echo (int) $year; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="hw-filter-actions">
                    <button type="submit" class="btn btn-primary hw-filter-icon-btn hw-btn-filter" title="Apply filters" aria-label="Apply filters">
                        <i class="fas fa-filter"></i>
                    </button>
                    <a class="btn btn-outline-secondary hw-filter-icon-btn hw-btn-reset"
                       title="Reset filters"
                       aria-label="Reset filters"
                       href="<?php echo esc_attr(forecast_filter_url('all', 'all', 'all', '', $per_page, null, 'latest', 'weekly', 'urgency')); ?>#forecast-analysis">
                        <i class="fas fa-rotate-left"></i>
                    </a>
                </div>
            </div>

            <div class="hw-search-cluster">
                <div class="hw-filter-group hw-group-search flex-grow-1">
                    <label for="adminForecastSearch" class="hw-filter-label">Search</label>
                    <div class="hw-search-wrapper">
                        <input id="adminForecastSearch"
                               type="text"
                               name="search"
                               maxlength="100"
                               data-text-format="first-letter"
                               class="hw-search-input"
                               value="<?php echo esc_attr($search_filter); ?>"
                               placeholder="Search item, brand, size, SKU...">
                    </div>
                </div>
                <div class="hw-filter-actions">
                    <button type="submit" class="btn btn-primary hw-filter-icon-btn hw-btn-search" title="Search" aria-label="Search">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
        </form>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const brandsByCategory = <?php echo json_encode($brands_by_category, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?> || {};
            const sizesByBrand = <?php echo json_encode($sizes_by_brand, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?> || {};
            const allBrands = <?php echo json_encode(array_values($all_brands), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?> || [];
            const allSizes = <?php echo json_encode(array_values($all_sizes), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?> || [];

            const catSelect = document.getElementById('adminForecastCategoryFilter');
            const brandSelect = document.getElementById('adminForecastBrandFilter');
            const sizeSelect = document.getElementById('adminForecastSizeFilter');
            const brandField = document.getElementById('adminForecastBrandField');
            const sizeField = document.getElementById('adminForecastSizeField');

            if (!catSelect || !brandSelect || !sizeSelect) return;

            catSelect.addEventListener('change', function() {
                const cat = this.value;
                const currentBrand = brandSelect.value;

                if (cat === 'all') {
                    if (brandField) brandField.style.display = 'none';
                    if (sizeField) sizeField.style.display = 'none';
                    brandSelect.value = '';
                    sizeSelect.value = '';
                    return;
                }

                if (brandField) brandField.style.display = '';
                if (sizeField) sizeField.style.display = '';

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
    </section>

    <nav class="forecast-view-tabs" aria-label="Forecast views">
        <?php foreach ($forecast_view_tabs as $tab_key => $tab): ?>
            <a class="forecast-view-tab <?php echo $view_filter === $tab_key ? 'active' : ''; ?>"
               href="<?php echo esc_attr(forecast_filter_url($category_filter, $branch_filter, $status_filter, $search_filter, $per_page, null, $year_filter, $tab_key, $sort_filter)); ?>#forecast-analysis"
               aria-current="<?php echo $view_filter === $tab_key ? 'page' : 'false'; ?>">
                <i class="<?php echo esc_attr($tab['icon']); ?>"></i>
                <span><?php echo esc_html($tab['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <section class="forecast-movement-panel">
        <header class="forecast-movement-header">
            <div>
                <h2>Inventory Movement Trend by Category</h2>
                <p>Monthly stock movement with tire, accessory, and part totals for <?php echo (int) $movement_chart_year; ?></p>
                <div class="forecast-movement-details">
                    <span>Total In: <?php echo number_format((int) $movement_stock_in_total); ?> units</span>
                    <span>Total Out: <?php echo number_format((int) $movement_stock_out_total); ?> units</span>
                    <span>Scale: units moved</span>
                    <span>Peak: <?php echo esc_html($movement_peak['label'] . ' ' . $movement_peak['type']); ?> (<?php echo number_format((int) $movement_peak['value']); ?>)</span>
                </div>
            </div>
            <span><i class="fas fa-chart-column"></i></span>
        </header>
        <div class="forecast-movement-chart">
            <div class="forecast-movement-axis">
                <span><?php echo (int) $movement_chart_max; ?></span>
                <span><?php echo number_format($movement_chart_max * 0.75, 1); ?></span>
                <span><?php echo number_format($movement_chart_max * 0.5, 1); ?></span>
                <span><?php echo number_format($movement_chart_max * 0.25, 1); ?></span>
                <span>0</span>
            </div>
            <div class="forecast-movement-plot">
                <div class="forecast-movement-grid"></div>
                <div class="forecast-movement-bars">
                    <?php foreach ($movement_chart as $month): ?>
                        <div class="forecast-movement-month">
                            <div class="forecast-movement-bar-pair">
                                <span class="bar-in" title="Stock In: <?php echo (int) $month['stock_in']; ?>" style="height: <?php echo forecast_chart_percent($month['stock_in'], $movement_chart_max); ?>%">
                                    <b><?php echo (int) $month['stock_in'] > 0 ? number_format((int) $month['stock_in']) : ''; ?></b>
                                </span>
                                <span class="bar-out" title="Stock Out: <?php echo (int) $month['stock_out']; ?>" style="height: <?php echo forecast_chart_percent($month['stock_out'], $movement_chart_max); ?>%">
                                    <b><?php echo (int) $month['stock_out'] > 0 ? number_format((int) $month['stock_out']) : ''; ?></b>
                                </span>
                            </div>
                            <strong><?php echo esc_html($month['label']); ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="forecast-movement-legend">
            <span><i class="legend-in"></i> Stock In: units added</span>
            <span><i class="legend-out"></i> Stock Out: units used, removed, or transferred out</span>
        </div>
        <div class="inventory-category-breakdown">
            <div class="inventory-category-breakdown-heading">
                <strong>Category Breakdown</strong>
                <span>These totals explain which item type moved most during <?php echo (int) $movement_chart_year; ?>.</span>
            </div>
            <?php foreach ($movement_category_totals as $category_total): ?>
                <div class="inventory-category-stat">
                    <strong><?php echo esc_html($category_total['label']); ?></strong>
                    <span>In <?php echo number_format((int) $category_total['stock_in']); ?> / Out <?php echo number_format((int) $category_total['stock_out']); ?></span>
                    <div class="inventory-category-mini-bars" aria-label="<?php echo esc_attr($category_total['label'] . ' category movement'); ?>">
                        <i class="mini-in" style="width: <?php echo forecast_chart_percent($category_total['stock_in'], $movement_category_max); ?>%"></i>
                        <i class="mini-out" style="width: <?php echo forecast_chart_percent($category_total['stock_out'], $movement_category_max); ?>%"></i>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <details class="forecast-support-details forecast-dss-panel">
        <summary class="forecast-support-summary">
            <span class="forecast-support-title">
                <i class="far fa-lightbulb"></i>
                <strong>Forecast-Based Decision Support</strong>
            </span>
            <?php
            $admin_forecast_action_count = count($decision_support['high_priority'] ?? []) + count($decision_support['medium_priority'] ?? []);
            ?>
            <span class="forecast-support-count">
                <?php echo (int) $admin_forecast_action_count; ?>
                <?php echo $admin_forecast_action_count === 1 ? 'action' : 'actions'; ?>
            </span>
        </summary>
        <div class="forecast-support-body">

        <div class="forecast-dss-grid">
            <article class="forecast-dss-card dss-high">
                <h3><i class="fas fa-triangle-exclamation"></i> High Priority</h3>
                <?php if (empty($decision_support['high_priority'])): ?>
                    <p class="forecast-muted">No critical forecast actions for the selected filters.</p>
                <?php else: ?>
                    <?php foreach ($decision_support['high_priority'] as $item): ?>
                        <div class="forecast-dss-item">
                            <strong><?php echo esc_html(app_display_item_name($item['item']['item_name'], $item['item']['category'] ?? null)); ?></strong>
                            <p>
                                URGENT: Order <?php echo (int) $item['recommended_order']; ?> units immediately.
                                <?php if ($item['stock_duration'] !== null): ?>
                                    Stock will run out in <?php echo number_format((float) $item['stock_duration'], 1); ?> weeks.
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
                    <p class="forecast-muted">No warning-level forecast actions for the selected filters.</p>
                <?php else: ?>
                    <?php foreach ($decision_support['medium_priority'] as $item): ?>
                        <div class="forecast-dss-item">
                            <strong><?php echo esc_html(app_display_item_name($item['item']['item_name'], $item['item']['category'] ?? null)); ?></strong>
                            <p>Order <?php echo (int) $item['recommended_order']; ?> units within the next week to maintain optimal stock levels.</p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </article>

            <article class="forecast-dss-card dss-insights">
                <h3><i class="far fa-circle-check"></i> Key Insights</h3>
                <div class="forecast-insight-list">
                    <div>
                        <strong>Growing Demand</strong>
                        <p><?php echo (int) $decision_support['insights']['growing']; ?> items showing strong growth</p>
                    </div>
                    <div>
                        <strong>Declining Items</strong>
                        <p><?php echo (int) $decision_support['insights']['declining']; ?> items with decreasing demand</p>
                    </div>
                    <div>
                        <strong>Optimal Stock</strong>
                        <p><?php echo (int) $decision_support['insights']['optimal']; ?> items at healthy levels</p>
                    </div>
                </div>
            </article>
        </div>
        </div>
    </details>

    <details class="forecast-support-details forecast-transfer-panel">
        <summary class="forecast-support-summary">
            <span class="forecast-support-title">
                <i class="fas fa-right-left"></i>
                <strong>Branch Transfer Recommendations</strong>
            </span>
            <?php
            $admin_transfer_count = count($decision_support['transfers'] ?? []);
            ?>
            <span class="forecast-support-count">
                <?php echo (int) $admin_transfer_count; ?>
                <?php echo $admin_transfer_count === 1 ? 'recommendation' : 'recommendations'; ?>
            </span>
        </summary>
        <div class="forecast-support-body">

        <?php if (empty($decision_support['transfers'])): ?>
            <div class="forecast-empty-state">No branch transfer recommendations for the selected filters.</div>
        <?php else: ?>
            <div class="forecast-transfer-list">
                <?php foreach ($decision_support['transfers'] as $transfer): ?>
                    <?php
                    $to = $transfer['to'];
                    $from = $transfer['from'];
                    $item = $transfer['item'];
                    $priority = $transfer['priority'];
                    $status_duration = $to['stock_duration'] !== null ? number_format((float) $to['stock_duration'], 1) : 'low';
                    ?>
                    <article class="forecast-transfer-card priority-<?php echo esc_attr($priority); ?>">
                        <div class="forecast-transfer-main">
                            <div class="forecast-transfer-badges">
                                <span class="transfer-priority"><?php echo $priority === 'high' ? 'HIGH PRIORITY' : 'MEDIUM PRIORITY'; ?></span>
                                <span class="forecast-category category-<?php echo esc_attr($item['category']); ?>">
                                    <?php echo esc_html(forecast_category_label($item['category'])); ?>
                                </span>
                            </div>
                            <h3><?php echo esc_html(app_display_item_name($item['item_name'], $item['category'] ?? null)); ?></h3>
                            <p>
                                <?php echo $priority === 'high' ? 'Critical' : 'Warning'; ?> shortage at
                                <?php echo esc_html(strtoupper(forecast_branch_label($item['branch_name']))); ?>
                                <?php if ($to['stock_duration'] !== null): ?>
                                    - only <?php echo esc_html($status_duration); ?> weeks of stock remaining
                                <?php else: ?>
                                    - stock is at or below reorder level
                                <?php endif; ?>
                            </p>
                            <div class="forecast-transfer-route">
                                <span class="forecast-branch-pill branch-<?php echo (int) $from['item']['branch_id']; ?>">
                                    <?php echo esc_html(strtoupper(forecast_branch_label($from['item']['branch_name']))); ?>
                                </span>
                                <em>Stock: <?php echo (int) $from['item']['quantity']; ?></em>
                                <i class="fas fa-truck"></i>
                                <span class="forecast-branch-pill branch-<?php echo (int) $to['item']['branch_id']; ?>">
                                    <?php echo esc_html(strtoupper(forecast_branch_label($to['item']['branch_name']))); ?>
                                </span>
                                <em>Stock: <?php echo (int) $to['item']['quantity']; ?></em>
                            </div>
                        </div>
                        <div class="forecast-transfer-qty">
                            <span>Recommended Transfer</span>
                            <strong><?php echo (int) $transfer['quantity']; ?></strong>
                            <p>units</p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        </div>
    </details>

    <section class="forecast-analysis-panel" id="forecast-analysis">
        <header class="forecast-analysis-header">
            <div>
                <h2>Inventory Forecast Analysis</h2>
                <p>Based on historical consumption patterns</p>
            </div>
        </header>

        <?php
        $timeframe_badge = [
            'weekly' => ['label' => 'Weekly Replenishment Horizon (7–14 Days)', 'icon' => 'far fa-calendar-days', 'class' => 'pill-weekly'],
            'monthly' => ['label' => 'Monthly Procurement Horizon (30–60 Days)', 'icon' => 'far fa-calendar', 'class' => 'pill-monthly'],
            'items' => ['label' => 'Catalog Health & Movement Velocity', 'icon' => 'fas fa-list-check', 'class' => 'pill-items'],
        ][$view_filter] ?? ['label' => 'Forecast Analysis', 'icon' => 'fas fa-chart-line', 'class' => ''];
        ?>
        <div class="records-table-toolbar forecast-table-toolbar">
            <div>
                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <h3><?php echo esc_html(forecast_view_label($view_filter)); ?> Records</h3>
                    <span class="forecast-timeframe-pill <?php echo esc_attr($timeframe_badge['class']); ?>">
                        <i class="<?php echo esc_attr($timeframe_badge['icon']); ?>"></i>
                        <?php echo esc_html($timeframe_badge['label']); ?>
                    </span>
                </div>
                <p>Showing <?php echo (int) $showing_from; ?>-<?php echo (int) $showing_to; ?> of <?php echo (int) $total_records; ?> items</p>
            </div>
            <div class="forecast-toolbar-controls">
                <a href="<?php echo esc_attr(forecast_export_url($category_filter, $branch_filter, $status_filter, $search_filter, $year_filter, $view_filter, $sort_filter, $brand_filter, $size_filter)); ?>"
                   class="forecast-export-btn"
                   title="Export currently filtered forecasting recommendations as CSV"
                   download>
                    <i class="fas fa-file-csv"></i>
                    <span>Export Recommendations</span>
                </a>
                <span class="forecast-toolbar-divider" aria-hidden="true"></span>
                <form class="records-page-size-form" method="get" action="./#forecast-analysis">
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
                <?php if ($status_filter !== 'all'): ?>
                    <input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>">
                <?php endif; ?>
                <?php if ($sort_filter !== 'urgency'): ?>
                    <input type="hidden" name="sort" value="<?php echo esc_attr($sort_filter); ?>">
                <?php endif; ?>
                <?php if ($search_filter !== ''): ?>
                    <input type="hidden" name="search" value="<?php echo esc_attr($search_filter); ?>">
                <?php endif; ?>
                <?php if ($year_filter !== 'latest'): ?>
                    <input type="hidden" name="year" value="<?php echo esc_attr($year_filter); ?>">
                <?php endif; ?>
                <?php if ($view_filter !== 'weekly'): ?>
                    <input type="hidden" name="view" value="<?php echo esc_attr($view_filter); ?>">
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
            </div>
        </div>

        <div class="table-responsive">
            <table class="forecast-table">
                <thead>
                    <tr>
                        <th>Forecast Status</th>
                        <th>Item</th>
                        <th>Branch</th>
                        <th>Current Stock</th>
                        <?php if ($view_filter === 'monthly'): ?>
                            <th>Monthly Demand</th>
                            <th>Trend</th>
                            <th>Months Left</th>
                            <th>Projected Stock</th>
                            <th>Monthly Order</th>
                        <?php elseif ($view_filter === 'items'): ?>
                            <th>Reorder Level</th>
                            <th>90-Day Stock Out</th>
                            <th>Projected Stock</th>
                            <th>Suggested Action</th>
                            <th>Reason</th>
                        <?php else: ?>
                            <th>Weekly Usage</th>
                            <th>Trend</th>
                            <th>Weeks Left</th>
                            <th>Recommended Order</th>
                            <th>Order Value</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($forecast_items)): ?>
                        <tr>
                            <td colspan="9" class="forecast-empty-cell">No forecast records match the selected filters.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($forecast_items as $item): ?>
                            <?php
                            $inventory_item = $item['item'];
                            $status = $item['status'];
                            $trend = (int) $item['trend_percent'];
                            $trend_class = $trend > 0 ? 'trend-up' : ($trend < 0 ? 'trend-down' : 'trend-flat');
                            $trend_icon = $trend > 0 ? 'fa-arrow-trend-up' : ($trend < 0 ? 'fa-arrow-trend-down' : 'fa-minus');
                            ?>
                            <tr class="forecast-row row-<?php echo esc_attr($status); ?>">
                                <td>
                                    <span class="forecast-status-pill status-<?php echo esc_attr($status); ?>">
                                        <?php echo esc_html(forecast_status_label($status)); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?php echo esc_html(app_display_item_name($inventory_item['item_name'], $inventory_item['category'])); ?></strong>
                                    <span class="forecast-category category-<?php echo esc_attr($inventory_item['category']); ?>">
                                        <?php echo esc_html(forecast_category_label($inventory_item['category'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="forecast-branch-pill branch-<?php echo (int) $inventory_item['branch_id']; ?>">
                                        <?php echo esc_html(strtoupper(forecast_branch_label($inventory_item['branch_name']))); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?php echo (int) $inventory_item['quantity']; ?></strong>
                                    <small>Reorder at <?php echo (int) $inventory_item['reorder_level']; ?></small>
                                </td>
                                <?php if ($view_filter === 'monthly'): ?>
                                    <td>
                                        <strong><?php echo number_format((float) $item['monthly_usage'], 1); ?></strong>
                                        <small>units/month</small>
                                    </td>
                                    <td>
                                        <span class="forecast-trend <?php echo esc_attr($trend_class); ?>">
                                            <i class="fas <?php echo esc_attr($trend_icon); ?>"></i>
                                            <?php echo $trend > 0 ? '+' : ''; ?><?php echo $trend; ?>%
                                        </span>
                                    </td>
                                    <td>
                                        <span class="forecast-duration <?php echo $status === 'critical' ? 'is-critical' : ($status === 'warning' ? 'is-warning' : ''); ?>">
                                            <i class="far fa-calendar"></i>
                                            <?php echo esc_html($item['months_duration_human']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html($item['projected_stock_text']); ?></strong>
                                    </td>
                                    <td>
                                        <strong class="forecast-recommendation"><?php echo (int) $item['recommended_monthly_order']; ?> units</strong>
                                        <small><?php echo forecast_money($item['monthly_order_value']); ?></small>
                                    </td>
                                <?php elseif ($view_filter === 'items'): ?>
                                    <td>
                                        <strong><?php echo (int) $inventory_item['reorder_level']; ?></strong>
                                        <small>minimum level</small>
                                    </td>
                                    <td>
                                        <strong><?php echo number_format((float) $item['basis_out'], 1); ?></strong>
                                        <small>units used</small>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html($item['projected_stock_text']); ?></strong>
                                    </td>
                                    <td>
                                        <?php if ((int) $item['recommended_order'] > 0): ?>
                                             <strong class="forecast-recommendation">Stock in <?php echo (int) $item['recommended_order']; ?> units</strong>
                                        <?php else: ?>
                                            <strong>Monitor</strong>
                                        <?php endif; ?>
                                        <small><?php echo esc_html(forecast_status_label($status)); ?> priority</small>
                                    </td>
                                    <td>
                                        <small class="forecast-reason-text"><?php echo esc_html($item['forecast_reason']); ?></small>
                                    </td>
                                <?php else: ?>
                                    <td>
                                        <strong><?php echo number_format((float) $item['weekly_usage'], 1); ?></strong>
                                        <small>units/week</small>
                                    </td>
                                    <td>
                                        <span class="forecast-trend <?php echo esc_attr($trend_class); ?>">
                                            <i class="fas <?php echo esc_attr($trend_icon); ?>"></i>
                                            <?php echo $trend > 0 ? '+' : ''; ?><?php echo $trend; ?>%
                                        </span>
                                    </td>
                                    <td>
                                        <span class="forecast-duration <?php echo $status === 'critical' ? 'is-critical' : ($status === 'warning' ? 'is-warning' : ''); ?>">
                                            <i class="far fa-calendar"></i>
                                            <?php echo esc_html($item['duration_human']); ?>
                                        </span>
                                    </td>
                                    <td><strong class="forecast-recommendation"><?php echo (int) $item['recommended_order']; ?> units</strong></td>
                                    <td><strong><?php echo forecast_money($item['order_value']); ?></strong></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total_pages > 1): ?>
            <nav class="records-pagination forecast-records-pagination" aria-label="Forecast analysis pages">
                <ul class="pagination justify-content-center">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo esc_attr(forecast_filter_url($category_filter, $branch_filter, $status_filter, $search_filter, $per_page, 1, $year_filter, $view_filter, $sort_filter, $brand_filter, $size_filter)); ?>#forecast-analysis">First</a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo esc_attr(forecast_filter_url($category_filter, $branch_filter, $status_filter, $search_filter, $per_page, $page - 1, $year_filter, $view_filter, $sort_filter, $brand_filter, $size_filter)); ?>#forecast-analysis">Previous</a>
                        </li>
                    <?php endif; ?>
                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo esc_attr(forecast_filter_url($category_filter, $branch_filter, $status_filter, $search_filter, $per_page, $i, $year_filter, $view_filter, $sort_filter, $brand_filter, $size_filter)); ?>#forecast-analysis"><?php echo (int) $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo esc_attr(forecast_filter_url($category_filter, $branch_filter, $status_filter, $search_filter, $per_page, $page + 1, $year_filter, $view_filter, $sort_filter, $brand_filter, $size_filter)); ?>#forecast-analysis">Next</a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo esc_attr(forecast_filter_url($category_filter, $branch_filter, $status_filter, $search_filter, $per_page, $total_pages, $year_filter, $view_filter, $sort_filter, $brand_filter, $size_filter)); ?>#forecast-analysis">Last</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </section>
</main>

<?php require_once '../../includes/footer.php'; ?>
