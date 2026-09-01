<?php
/**
 * Front Desk Inventory Forecasting
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

if (($user['role'] ?? '') !== 'front-desk') {
    set_flash_message('You do not have access to this page', 'danger');
    redirect('/hwtires/' . ($user['role'] ?? 'admin') . '/');
}

if (!can_access_inventory((int) ($user['branch_id'] ?? 0))) {
    set_flash_message('Your branch does not have inventory access', 'danger');
    redirect('/hwtires/front-desk/');
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
$status_filter = strtolower(trim($_GET['status'] ?? 'all'));
$search_filter = trim($_GET['search'] ?? '');
$year_filter = trim($_GET['year'] ?? 'latest');
$view_filter = strtolower(trim($_GET['view'] ?? 'weekly'));
$sort_filter = strtolower(trim($_GET['sort'] ?? 'urgency'));
$branch_filter = (string) (int) ($user['branch_id'] ?? 0);
$page_sizes = [10, 20, 50];
$per_page = (int) ($_GET['per_page'] ?? 10);
if (!in_array($per_page, $page_sizes, true)) {
    $per_page = 10;
}
$page = max(1, (int) ($_GET['page'] ?? 1));

// Fetch distinct categories, brands, and sizes for dropdowns scoped to user's branch
$raw_filter_meta = [];
if (!empty($allowed_branch_ids)) {
    $meta_placeholders = implode(',', array_fill(0, count($allowed_branch_ids), '?'));
    $filter_meta_stmt = $pdo->prepare("
        SELECT DISTINCT category, brand, size 
        FROM inventory_items 
        WHERE status = 'active'
          AND branch_id IN ($meta_placeholders)
        ORDER BY category ASC, brand ASC, size ASC
    ");
    $filter_meta_stmt->execute($allowed_branch_ids);
    $raw_filter_meta = $filter_meta_stmt ? $filter_meta_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

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
$branch_label = $inventory_branches[0]['name'] ?? 'Your Branch';
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
        ['label' => 'Items Tracked', 'value' => number_format((int) $summary['total']), 'note' => forecast_branch_label($branch_label), 'icon' => 'fas fa-boxes-stacked', 'class' => 'icon-tracked'],
    ];
}

$forecast_risk_items = array_values(array_filter($all_forecast_items, static function ($item) {
    $status = strtolower((string) ($item['status'] ?? ''));

    return in_array($status, ['critical', 'warning'], true)
        || (int) ($item['recommended_order'] ?? 0) > 0
        || (int) ($item['recommended_monthly_order'] ?? 0) > 0;
}));
$forecast_risk_items = array_slice($forecast_risk_items, 0, 6);
?>

<?php require_once '../../includes/header.php'; ?>
<?php require_once '../../includes/sidebar.php'; ?>

<main class="forecasting-page">
    <header class="forecasting-hero forecasting-hero-with-action forecast-scope-hero">
        <div>
            <h1>Inventory Forecasting</h1>
            <p><?php echo esc_html(forecast_branch_label($branch_label)); ?> forecast as of <?php echo esc_html(date('F d, Y', strtotime($analysis_date ?: 'now'))); ?></p>
        </div>
        <div class="forecast-scope-actions">
            <a href="/hwtires/front-desk/tire-inventory/" class="forecast-back-link">
                <i class="fas fa-arrow-left"></i>
                <span>Inventory</span>
            </a>
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
                <p>Top stock risks for <?php echo esc_html(forecast_branch_label($branch_label)); ?> based on the selected forecast view.</p>
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
                            <span><?php echo $risk_order > 0 ? 'Stock in ' . number_format($risk_order) . ' units' : 'Monitor'; ?></span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="forecast-secondary-filter-card" aria-label="Forecast filters">
        <form method="get" action="./#forecast-analysis" class="forecast-compact-filters">
            <input type="hidden" name="branch" value="<?php echo esc_attr($branch_filter); ?>">
            <input type="hidden" name="per_page" value="<?php echo (int) $per_page; ?>">

            <!-- Row 1: Main Filters -->
            <div class="forecast-filter-row forecast-filter-row-main">
                <label class="forecast-compact-field">
                    <span>Category</span>
                    <select name="category" id="frontForecastCategoryFilter">
                        <?php foreach (['all' => 'All Items', 'tire' => 'Tires', 'accessory' => 'Accessories', 'part' => 'Parts'] as $category_value => $category_label): ?>
                            <option value="<?php echo esc_attr($category_value); ?>" <?php echo $category_filter === $category_value ? 'selected' : ''; ?>>
                                <?php echo esc_html($category_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="forecast-compact-field">
                    <span>Status</span>
                    <select name="status">
                        <?php foreach (['all' => 'All Statuses', 'critical' => 'Critical', 'warning' => 'Warning', 'watch' => 'Watch', 'good' => 'Good'] as $status_value => $status_label): ?>
                            <option value="<?php echo esc_attr($status_value); ?>" <?php echo $status_filter === $status_value ? 'selected' : ''; ?>>
                                <?php echo esc_html($status_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="forecast-compact-field">
                    <span>Forecast</span>
                    <select name="view">
                        <?php foreach ($forecast_view_tabs as $tab_key => $tab): ?>
                            <option value="<?php echo esc_attr($tab_key); ?>" <?php echo $view_filter === $tab_key ? 'selected' : ''; ?>>
                                <?php echo esc_html($tab['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="forecast-compact-field">
                    <span>Sort By</span>
                    <select name="sort">
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
                </label>
                <label class="forecast-compact-field">
                    <span>Analysis Year</span>
                    <select name="year">
                        <option value="latest" <?php echo $year_filter === 'latest' ? 'selected' : ''; ?>>Latest Available</option>
                        <?php foreach ($available_years as $year): ?>
                            <option value="<?php echo (int) $year; ?>" <?php echo $year_filter === (string) $year ? 'selected' : ''; ?>>
                                <?php echo (int) $year; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="forecast-compact-apply">Apply</button>
                <?php if ($category_filter !== 'all' || $brand_filter !== '' || $size_filter !== '' || $status_filter !== 'all' || $year_filter !== 'latest' || $view_filter !== 'weekly' || $sort_filter !== 'urgency'): ?>
                    <a class="forecast-compact-reset"
                       href="<?php echo esc_attr(forecast_filter_url('all', $branch_filter, 'all', $search_filter, $per_page, null, 'latest', 'weekly', 'urgency')); ?>#forecast-analysis">
                        Reset filters
                    </a>
                <?php endif; ?>
            </div>

            <!-- Row 2: Category-Specific Filters (Left) + Search Group (Right) -->
            <div class="forecast-filter-row forecast-filter-row-secondary">
                <label class="forecast-compact-field" id="frontForecastBrandField" style="<?php echo ($category_filter === 'all' && $brand_filter === '') ? 'display: none;' : ''; ?>">
                    <span>Brand</span>
                    <select name="brand" id="frontForecastBrandFilter">
                        <option value="">All Brands</option>
                        <?php foreach ($available_brands as $brand_name): ?>
                            <option value="<?php echo esc_attr($brand_name); ?>" <?php echo strcasecmp($brand_filter, $brand_name) === 0 ? 'selected' : ''; ?>>
                                <?php echo esc_html($brand_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="forecast-compact-field" id="frontForecastSizeField" style="<?php echo ($category_filter === 'all' && $size_filter === '') ? 'display: none;' : ''; ?>">
                    <span>Size / Spec</span>
                    <select name="size" id="frontForecastSizeFilter">
                        <option value="">All Sizes</option>
                        <?php foreach ($available_sizes as $size_val): ?>
                            <option value="<?php echo esc_attr($size_val); ?>" <?php echo $size_filter === $size_val ? 'selected' : ''; ?>>
                                <?php echo esc_html($size_val); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="forecast-search-group">
                    <label class="forecast-search-field">
                        <i class="fas fa-search"></i>
                        <input type="search"
                               name="search"
                               value="<?php echo esc_attr($search_filter); ?>"
                               placeholder="Search item, brand, size, SKU...">
                    </label>
                    <button type="submit" class="forecast-search-btn">Search</button>
                    <?php if ($search_filter !== ''): ?>
                        <a class="forecast-search-clear"
                           href="<?php echo esc_attr(forecast_filter_url($category_filter, $branch_filter, $status_filter, '', $per_page, null, $year_filter, $view_filter, $sort_filter)); ?>#forecast-analysis">
                            Clear
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const brandsByCategory = <?php echo json_encode($brands_by_category, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?> || {};
            const sizesByBrand = <?php echo json_encode($sizes_by_brand, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?> || {};
            const allBrands = <?php echo json_encode(array_values($all_brands), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?> || [];
            const allSizes = <?php echo json_encode(array_values($all_sizes), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?> || [];

            const catSelect = document.getElementById('frontForecastCategoryFilter');
            const brandSelect = document.getElementById('frontForecastBrandFilter');
            const sizeSelect = document.getElementById('frontForecastSizeFilter');
            const brandField = document.getElementById('frontForecastBrandField');
            const sizeField = document.getElementById('frontForecastSizeField');

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

    <details class="forecast-support-details forecast-dss-panel">
        <summary class="forecast-support-summary">
            <span class="forecast-support-title">
                <i class="far fa-lightbulb"></i>
                <strong>Forecast-Based Actions</strong>
            </span>
            <?php
            $front_forecast_action_count = count($decision_support['high_priority'] ?? []) + count($decision_support['medium_priority'] ?? []);
            ?>
            <span class="forecast-support-count">
                <?php echo (int) $front_forecast_action_count; ?>
                <?php echo $front_forecast_action_count === 1 ? 'action' : 'actions'; ?>
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
                            <p>Stock in <?php echo (int) $item['recommended_order']; ?> units immediately.</p>
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
                            <p>Stock in <?php echo (int) $item['recommended_order']; ?> units within the next week.</p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </article>

            <article class="forecast-dss-card dss-insights">
                <h3><i class="far fa-circle-check"></i> Key Insights</h3>
                <div class="forecast-insight-list">
                    <div>
                        <strong>Growing Demand</strong>
                        <p><?php echo (int) $decision_support['insights']['growing']; ?> items showing growth</p>
                    </div>
                    <div>
                        <strong>Declining Items</strong>
                        <p><?php echo (int) $decision_support['insights']['declining']; ?> items with decreasing demand</p>
                    </div>
                    <div>
                        <strong>Healthy Stock</strong>
                        <p><?php echo (int) $decision_support['insights']['optimal']; ?> items above forecast threshold</p>
                    </div>
                </div>
            </article>
        </div>
        </div>
    </details>

    <section class="forecast-analysis-panel" id="forecast-analysis">
        <header class="forecast-analysis-header">
            <div>
                <h2><?php echo esc_html(forecast_view_label($view_filter)); ?></h2>
                <p>Demand basis: stock-out movement from the last 90 days</p>
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
                    <h3>Forecast Records</h3>
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
                <input type="hidden" name="branch" value="<?php echo esc_attr($branch_filter); ?>">
                <?php if ($category_filter !== 'all'): ?>
                    <input type="hidden" name="category" value="<?php echo esc_attr($category_filter); ?>">
                <?php endif; ?>
                <?php if ($brand_filter !== ''): ?>
                    <input type="hidden" name="brand" value="<?php echo esc_attr($brand_filter); ?>">
                <?php endif; ?>
                <?php if ($size_filter !== ''): ?>
                    <input type="hidden" name="size" value="<?php echo esc_attr($size_filter); ?>">
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
                        <th>Current Stock</th>
                        <?php if ($view_filter === 'monthly'): ?>
                            <th>Monthly Demand</th>
                            <th>Trend</th>
                            <th>Estimated Runway</th>
                            <th>Depletion Projection</th>
                            <th>Suggested Stock In</th>
                            <th>Action</th>
                        <?php elseif ($view_filter === 'items'): ?>
                            <th>Reorder Threshold</th>
                            <th>90-Day Consumption</th>
                            <th>Depletion Projection</th>
                            <th>Suggested Action</th>
                            <th>Demand Basis</th>
                        <?php else: ?>
                            <th>Weekly Burn Rate</th>
                            <th>Trend</th>
                            <th>Estimated Runway</th>
                            <th>Suggested Stock In</th>
                            <th>Action</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($forecast_items)): ?>
                        <tr>
                            <td colspan="8" class="forecast-empty-cell">No forecast records match the selected filters.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($forecast_items as $item): ?>
                            <?php
                            $inventory_item = $item['item'];
                            $status = $item['status'];
                            $trend = (int) $item['trend_percent'];
                            $trend_class = $trend > 0 ? 'trend-up' : ($trend < 0 ? 'trend-down' : 'trend-flat');
                            $trend_icon = $trend > 0 ? 'fa-arrow-trend-up' : ($trend < 0 ? 'fa-arrow-trend-down' : 'fa-minus');
                            $stock_in_quantity = $view_filter === 'monthly' ? (int) $item['recommended_monthly_order'] : (int) $item['recommended_order'];
                            $stock_in_url = '/hwtires/front-desk/tire-inventory/?action=stock_in&item_id=' . (int) $inventory_item['id'] . '&quantity=' . (int) $stock_in_quantity . '&supplier_name=' . rawurlencode($inventory_item['supplier_name'] ?? '') . '&notes=' . rawurlencode('Forecast restock: ' . ($item['duration_human'] ?? ''));
                            ?>
                            <tr class="forecast-row row-<?php echo esc_attr($status); ?>">
                                <td>
                                    <span class="forecast-status-pill status-<?php echo esc_attr($status); ?>">
                                        <?php echo esc_html(forecast_status_label($status)); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?php echo esc_html(app_display_item_name($inventory_item['item_name'], $inventory_item['category'] ?? null)); ?></strong>
                                    <span class="forecast-category category-<?php echo esc_attr($inventory_item['category']); ?>">
                                        <?php echo esc_html(forecast_category_label($inventory_item['category'])); ?>
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
                                    <td>
                                        <?php if ((int) $item['recommended_monthly_order'] > 0): ?>
                                            <a class="forecast-row-action" href="<?php echo esc_attr($stock_in_url); ?>">
                                                <i class="fas fa-plus"></i>
                                                <span>Stock In</span>
                                            </a>
                                        <?php else: ?>
                                            <span class="forecast-action-muted">Healthy</span>
                                        <?php endif; ?>
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
                                    <td>
                                        <?php if ((int) $item['recommended_order'] > 0): ?>
                                            <a class="forecast-row-action" href="<?php echo esc_attr($stock_in_url); ?>">
                                                <i class="fas fa-plus"></i>
                                                <span>Stock In</span>
                                            </a>
                                        <?php else: ?>
                                            <span class="forecast-action-muted">Healthy</span>
                                        <?php endif; ?>
                                    </td>
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

    <details class="forecast-support-details forecast-method-panel">
        <summary class="forecast-support-summary">
            <span class="forecast-support-title">
                <i class="fas fa-calculator"></i>
                <strong>Forecast Method</strong>
            </span>
            <span class="forecast-support-count">Reference</span>
        </summary>
        <div class="forecast-support-body">
        <p>This is a rule-based forecast from inventory movement, not machine learning. Branch transfers are excluded from demand so transferred stock does not look like customer usage.</p>
        <div class="forecast-method-grid">
            <span>Demand basis: last 90 days stock out</span>
            <span>Trend: latest 30 days vs previous 30 days</span>
            <span>Critical: less than 2 weeks coverage</span>
            <span>Warning: 2-4 weeks coverage</span>
        </div>
        </div>
    </details>
</main>

<?php require_once '../../includes/footer.php'; ?>
