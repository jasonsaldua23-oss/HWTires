<?php
/**
 * Create Quotation - Front Desk
 */

require_once '../../includes/config.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$user = app_get_session_user();
if (!is_array($user)) {
    redirect('/hwtires/index.php');
}

$page_title = 'Service Operation';
$branch_id = intval($user['branch_id'] ?? 0);

if ($branch_id <= 0) {
    set_flash_message('Invalid branch context for user', 'error');
    redirect('/hwtires/front-desk/');
}

if (!function_exists('quote_branch_label')) {
    function quote_branch_label($name) {
        return app_branch_label($name, 'Branch');
    }
}

if (!function_exists('quote_item_group')) {
    function quote_item_group($item) {
        $category = strtolower((string) ($item['category'] ?? ''));
        $name = strtolower((string) ($item['item_name'] ?? ''));

        if ($category === 'tire' || str_contains($name, 'tire')) return 'Tires';
        if (str_contains($name, 'oil')) return 'Engine Oil';
        if (str_contains($name, 'filter')) return 'Filters';
        if (str_contains($name, 'brake')) return 'Brakes';
        if (str_contains($name, 'battery')) return 'Battery';
        if (str_contains($name, 'spark')) return 'Spark Plugs';
        if (str_contains($name, 'fluid') || str_contains($name, 'coolant')) return 'Fluids';

        return 'Other Parts';
    }
}

$customers_stmt = $pdo->prepare("
    SELECT id, name, phone_mobile, contact
    FROM customers
    WHERE status = 'active'
    ORDER BY name ASC
");
$customers_stmt->execute();
$customers = $customers_stmt->fetchAll();

$selected_customer_id = intval($_GET['customer_id'] ?? 0);
$selected_vehicle_id = intval($_GET['vehicle_id'] ?? 0);

$vehicles = [];
if ($selected_customer_id > 0) {
    $vehicles_stmt = $pdo->prepare("
        SELECT id, customer_id, plate_number, make, model, year, color, last_mileage
        FROM vehicles
        WHERE customer_id = ? AND (status = 'active' OR status IS NULL)
        ORDER BY make ASC, model ASC
    ");
    $vehicles_stmt->execute([$selected_customer_id]);
    $vehicles = $vehicles_stmt->fetchAll();
}

$all_vehicles_stmt = $pdo->prepare("
    SELECT id, customer_id, plate_number, make, model, year, color, last_mileage
    FROM vehicles
    WHERE (status = 'active' OR status IS NULL)
    ORDER BY customer_id ASC, make ASC, model ASC, plate_number ASC
");
$all_vehicles_stmt->execute();
$vehicles_by_customer = [];
foreach ($all_vehicles_stmt->fetchAll() as $vehicle) {
    $vehicle_customer_id = (int) ($vehicle['customer_id'] ?? 0);
    if ($vehicle_customer_id <= 0) {
        continue;
    }
    $vehicles_by_customer[$vehicle_customer_id][] = [
        'id' => (int) $vehicle['id'],
        'customer_id' => $vehicle_customer_id,
        'plate_number' => $vehicle['plate_number'] ?? '',
        'make' => $vehicle['make'] ?? '',
        'model' => $vehicle['model'] ?? '',
        'year' => $vehicle['year'] ?? '',
        'color' => $vehicle['color'] ?? '',
        'last_mileage' => $vehicle['last_mileage'] ?? '',
    ];
}

$inventory_stmt = $pdo->prepare("
    SELECT i.id, i.branch_id, i.item_name, i.category, i.size, i.brand, i.unit_price, i.quantity,
           b.name AS branch_name
    FROM inventory_items i
    LEFT JOIN branches b ON b.id = i.branch_id
    WHERE i.status = 'active'
      AND i.quantity > 0
      AND b.status = 'active'
      AND b.has_inventory = 1
    ORDER BY b.id ASC, i.category ASC, i.item_name ASC
");
$inventory_stmt->execute();
$inventory_items = $inventory_stmt->fetchAll();

$inventory_branches = [];
$inventory_data = [];
foreach ($inventory_items as $item) {
    $item_branch_id = intval($item['branch_id'] ?? 0);
    if ($item_branch_id > 0 && !isset($inventory_branches[$item_branch_id])) {
        $inventory_branches[$item_branch_id] = [
            'id' => $item_branch_id,
            'name' => quote_branch_label($item['branch_name'] ?? ('Branch ' . $item_branch_id))
        ];
    }

    $inventory_data[] = [
        'id' => (int) $item['id'],
        'branch_id' => $item_branch_id,
        'branch_name' => quote_branch_label($item['branch_name'] ?? ''),
        'name' => app_display_item_name($item['item_name'], $item['category'] ?? null),
        'category' => $item['category'],
        'group' => quote_item_group($item),
        'brand' => $item['brand'],
        'size' => $item['size'],
        'unit_price' => (float) $item['unit_price'],
        'quantity' => (int) $item['quantity']
    ];
}

$service_catalog = [];
try {
    $service_stmt = $pdo->query("
        SELECT name, category, price, labor_cost, estimated_duration, description, is_variable_price
        FROM service_catalog
        WHERE status = 'active'
        ORDER BY name ASC
    ");
    $service_catalog = array_map(static function ($service) {
        return [
            'name' => $service['name'],
            'category' => $service['category'] ?? 'Service',
            'price' => (float) $service['price'],
            'labor_cost' => (float) ($service['labor_cost'] ?? $service['price'] ?? 0),
            'estimated_duration' => $service['estimated_duration'] ?? '',
            'description' => $service['description'] ?? '',
            'is_variable_price' => (int) ($service['is_variable_price'] ?? 0),
        ];
    }, $service_stmt->fetchAll());
} catch (Exception $e) {
    error_log('Unable to load service catalog: ' . $e->getMessage());
}

if (empty($service_catalog)) {
    $service_catalog = [
        ['name' => 'Computerized Four Wheel Alignment', 'category' => 'Alignment', 'price' => 1920, 'labor_cost' => 1920, 'estimated_duration' => '1 hour full alignment; 30 minutes for 2-in/2-out adjustment', 'description' => 'Full computerized four wheel alignment. 2-in/2-out adjustment starts at 480 per adjustment.', 'is_variable_price' => 1],
        ['name' => 'Wheel Balancing / Computerized Wheel Balancing', 'category' => 'Tires', 'price' => 700, 'labor_cost' => 700, 'estimated_duration' => '2 hours for 4 wheels', 'description' => 'Computerized wheel balancing for four wheels.', 'is_variable_price' => 0],
        ['name' => 'Tire Mounting and Rotation / Pneumatic Tire Mounting', 'category' => 'Tires', 'price' => 200, 'labor_cost' => 200, 'estimated_duration' => '4 hours when combined with wheel balancing', 'description' => 'Pneumatic tire mounting. Default labor is per tire.', 'is_variable_price' => 1],
        ['name' => 'Under Chassis and Suspension Repair', 'category' => 'Suspension', 'price' => 4500, 'labor_cost' => 4500, 'estimated_duration' => 'Minor repair 1-2 hours; full suspension replacement up to 2 days', 'description' => 'Price depends on issue and material availability.', 'is_variable_price' => 1],
        ['name' => 'Oil Change and Engine Tune-Up', 'category' => 'Maintenance', 'price' => 1000, 'labor_cost' => 1000, 'estimated_duration' => '1 hour', 'description' => 'Engine tune-up with oil change. Oil change only starts at 500.', 'is_variable_price' => 1],
        ['name' => 'Suspension Parts Installation', 'category' => 'Suspension', 'price' => 6250, 'labor_cost' => 6250, 'estimated_duration' => 'Up to 2 days depending on kit', 'description' => 'Labor estimate based on around one-fourth of a 25000 total kit/service package.', 'is_variable_price' => 1],
        ['name' => 'Nitrogen Air Tire Inflation', 'category' => 'Tires', 'price' => 100, 'labor_cost' => 100, 'estimated_duration' => '5-10 minutes per tire', 'description' => 'Nitrogen inflation, default price per tire.', 'is_variable_price' => 1],
        ['name' => 'Battery Check-Up and Fast Charging', 'category' => 'Electrical', 'price' => 100, 'labor_cost' => 100, 'estimated_duration' => '30 minutes', 'description' => 'Battery check-up and fast charging.', 'is_variable_price' => 0],
        ['name' => 'Automatic Transmission Flushing / ATF Changer Machine to Automatic Transmission', 'category' => 'Transmission', 'price' => 2000, 'labor_cost' => 2000, 'estimated_duration' => '3 hours', 'description' => 'ATF changer machine service for automatic transmission.', 'is_variable_price' => 0],
        ['name' => 'Brake Cleaning and Adjustment / Brake Repair', 'category' => 'Brakes', 'price' => 1600, 'labor_cost' => 1600, 'estimated_duration' => '45 minutes for 4 wheels; repair time depends on parts availability', 'description' => 'Brake cleaning is 800 front and 800 rear. Repair price may vary.', 'is_variable_price' => 1],
        ['name' => 'Manual Clutch Repair', 'category' => 'Transmission', 'price' => 6000, 'labor_cost' => 6000, 'estimated_duration' => '1 day', 'description' => 'Manual clutch repair labor estimate.', 'is_variable_price' => 1],
        ['name' => 'Big Bike / Car Tire Change', 'category' => 'Tires', 'price' => 200, 'labor_cost' => 200, 'estimated_duration' => '30 minutes per tire', 'description' => 'Default price per tire.', 'is_variable_price' => 1],
        ['name' => 'Car Sanitizing Service (BACKTOZERO)', 'category' => 'Sanitizing', 'price' => 1500, 'labor_cost' => 1500, 'estimated_duration' => '10 minutes', 'description' => 'BACKTOZERO car sanitizing service.', 'is_variable_price' => 0],
        ['name' => 'Auto Diagnostic Scanning / Auto Diagnostic Scanner', 'category' => 'Diagnostics', 'price' => 1500, 'labor_cost' => 1500, 'estimated_duration' => '5 minutes', 'description' => 'Diagnostic scanning using scanner tool.', 'is_variable_price' => 0],
        ['name' => 'Preventive Maintenance Check-Up', 'category' => 'Maintenance', 'price' => 2500, 'labor_cost' => 2500, 'estimated_duration' => '1 hour 45 minutes', 'description' => 'Package includes oil change plus additional check-up time.', 'is_variable_price' => 0],
        ['name' => 'Cold Patch Vulcanizing and Tire Repair', 'category' => 'Tires', 'price' => 270, 'labor_cost' => 270, 'estimated_duration' => '15 minutes per tire', 'description' => 'Cold patch vulcanizing and tire repair, default price per tire.', 'is_variable_price' => 1],
        ['name' => 'EGR Cleaning', 'category' => 'Maintenance', 'price' => 1200, 'labor_cost' => 1200, 'estimated_duration' => '1 hour', 'description' => 'EGR cleaning service.', 'is_variable_price' => 0],
        ['name' => 'Car Accessories Installation', 'category' => 'Accessories', 'price' => 500, 'labor_cost' => 500, 'estimated_duration' => '30 minutes to 2 hours depending on accessory and parts availability', 'description' => 'Labor may be free when the accessory is purchased from the company.', 'is_variable_price' => 1],
        ['name' => 'AC Refrigerant Charging', 'category' => 'Air Conditioning', 'price' => 1500, 'labor_cost' => 1500, 'estimated_duration' => '20 minutes full charging', 'description' => 'Full charging is 1500. Topping freon starts at 500.', 'is_variable_price' => 1],
        ['name' => 'Tire Rotation', 'category' => 'Tires', 'price' => 400, 'labor_cost' => 400, 'estimated_duration' => '30-40 minutes for 4 wheels', 'description' => 'Default total for 4 wheels; 100 per tire.', 'is_variable_price' => 1],
    ];
}

$item_tabs = ['All', 'Engine Oil', 'Filters', 'Brakes', 'Battery', 'Spark Plugs', 'Fluids', 'Other Parts', 'Tires'];
$redirect_url = '/hwtires/front-desk/quotations/';
?>

<?php require_once '../../includes/header.php'; ?>
<?php require_once '../../includes/sidebar.php'; ?>

<style>
.quote-inspection-tech-section {
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px dashed #cbd5e1;
}
.quote-tech-section-header h3 {
    margin: 0 0 4px;
    font-size: 17px;
    font-weight: 800;
    color: #15233f;
}
.quote-tech-section-header p {
    margin: 0 0 16px;
    font-size: 13px;
    color: #64748b;
}
.quote-tech-block {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.quote-tech-heading {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.quote-tech-heading span {
    font-size: 15px;
    font-weight: 800;
    color: #15233f;
    display: flex;
    align-items: center;
    gap: 8px;
}
.quote-tech-heading small {
    font-size: 12px;
    color: #64748b;
}
.quote-tech-search {
    min-height: 44px;
    padding: 0 14px;
}
.quote-tech-search input {
    min-height: 40px;
    font-size: 15px;
}
.quote-tech-search i {
    font-size: 15px;
}
.quote-tech-list {
    max-height: 220px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 10px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
}
.quote-tech-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 8px 12px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.15s ease, border-color 0.15s ease;
}
.quote-tech-item:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
}
.quote-tech-item.is-checked {
    background: #f0fdf4;
    border-color: #86efac;
}
.quote-tech-item-left {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}
.quote-tech-item-left input[type="checkbox"] {
    width: 16px;
    height: 16px;
    min-height: 16px;
    accent-color: #0897b4;
    cursor: pointer;
}
.quote-tech-item-info {
    display: flex;
    flex-direction: column;
    min-width: 0;
}
.quote-tech-item-name {
    font-size: 13px;
    font-weight: 700;
    color: #1e293b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.quote-tech-item-meta {
    font-size: 11px;
    color: #64748b;
}
.quote-tech-item-price {
    font-size: 13px;
    font-weight: 800;
    color: #007496;
    white-space: nowrap;
}
.quote-badge-required {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    color: #b91c1c;
    background: #fee2e2;
    border: 1px solid #fca5a5;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    vertical-align: middle;
    margin-left: 8px;
}
.quote-badge-recommended {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    color: #0369a1;
    background: #e0f2fe;
    border: 1px solid #bae6fd;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 800;
    vertical-align: middle;
    margin-left: 8px;
}
.quote-badge-manual {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 1px 6px;
    color: #475569;
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    vertical-align: middle;
    margin-left: 6px;
}
.quote-opt-checkbox {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-right: 4px;
    font-size: 13px;
    font-weight: 700;
    color: #1e293b;
    cursor: pointer;
}
.quote-opt-checkbox input[type="checkbox"] {
    width: 18px;
    height: 18px;
    min-height: 18px;
    cursor: pointer;
    accent-color: #0897b4;
}
.quote-line-name {
    display: grid;
    gap: 6px;
    margin: 0;
}
.quote-line-name-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 6px;
}
.quote-line-name-header > label {
    margin: 0;
    color: #15233f;
    font-size: 13px;
    font-weight: 800;
    text-transform: uppercase;
    cursor: pointer;
}
.quote-tech-empty {
    padding: 16px;
    text-align: center;
    font-size: 13px;
    color: #94a3b8;
    background: #ffffff;
    border: 1px dashed #cbd5e1;
    border-radius: 6px;
}

/* Collapsible Browse Sections */
.quote-tech-browse-details {
    margin-top: 8px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    background: #ffffff;
    overflow: hidden;
}
.quote-tech-browse-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 12px;
    background: #f1f5f9;
    color: #334155;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    user-select: none;
    list-style: none;
    transition: background 0.15s ease;
}
.quote-tech-browse-summary:hover {
    background: #e2e8f0;
}
.quote-tech-browse-summary::-webkit-details-marker {
    display: none;
}
.quote-tech-browse-summary .quote-chevron {
    font-size: 10px;
    color: #64748b;
    transition: transform 0.2s ease;
}
.quote-tech-browse-details[open] .quote-chevron {
    transform: rotate(180deg);
}
.quote-tech-browse-body {
    padding: 8px;
    border-top: 1px solid #e2e8f0;
    background: #f8fafc;
}

/* Compact Step 2 Selected Line Rows */
.quote-line-list {
    display: grid;
    gap: 10px;
}
.quote-line-row {
    padding: 12px 14px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    gap: 12px;
    align-items: center;
    grid-template-columns: minmax(220px, 1.4fr) minmax(90px, 110px) minmax(130px, 160px) minmax(130px, 160px) 40px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    transition: border-color 0.15s ease;
}
.quote-line-row:hover {
    border-color: #cbd5e1;
}
.quote-line-row label {
    display: grid;
    gap: 4px;
    margin: 0;
}
.quote-line-row label > span,
.quote-line-total > span,
.quote-source-row > span {
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    letter-spacing: 0.3px;
    text-transform: uppercase;
}
.quote-line-row input,
.quote-line-row select {
    min-height: 38px;
    padding: 6px 10px;
    font-size: 13px;
    border-radius: 6px;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #0f172a;
    line-height: 1.3;
}
.quote-line-row input:focus,
.quote-line-row select:focus {
    border-color: #0897b4;
    box-shadow: 0 0 0 2px rgba(8, 151, 180, 0.15);
}
.quote-source-row {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 4px;
}
.quote-source-row select {
    min-height: 32px;
    padding: 4px 8px;
    font-size: 12px;
}
.quote-line-total {
    display: grid;
    gap: 4px;
}
.quote-line-total strong {
    min-height: 38px;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding: 6px 12px;
    font-size: 14px;
    font-weight: 800;
    border-radius: 6px;
    border: 1px solid #c9f5fa;
    background: #f0fdff;
    color: #007496;
}
.quote-remove-line {
    width: 36px;
    height: 36px;
    min-height: 36px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    color: #94a3b8;
    cursor: pointer;
    transition: all 0.15s ease;
}
.quote-remove-line:hover:not(:disabled):not(.is-locked) {
    background: #fef2f2;
    border-color: #fca5a5;
    color: #dc2626;
}
.quote-remove-line.is-locked {
    opacity: 0.65;
    cursor: not-allowed;
    background: #f8fafc;
    border-color: #e2e8f0;
    color: #94a3b8;
}

/* Expandable Line Details for Duration & Description */
.quote-line-expandable-details {
    grid-column: 1 / -1;
    margin-top: 4px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    overflow: hidden;
}
.quote-line-expandable-summary {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 10px;
    font-size: 11px;
    font-weight: 600;
    color: #475569;
    cursor: pointer;
    user-select: none;
    list-style: none;
}
.quote-line-expandable-summary::-webkit-details-marker {
    display: none;
}
.quote-line-expandable-summary .quote-chevron {
    margin-left: auto;
    font-size: 10px;
    color: #94a3b8;
    transition: transform 0.2s ease;
}
.quote-line-expandable-details[open] .quote-chevron {
    transform: rotate(180deg);
}
.quote-line-expandable-body {
    padding: 8px 12px;
    font-size: 12px;
    color: #475569;
    line-height: 1.4;
    border-top: 1px solid #e2e8f0;
    background: #ffffff;
}
.quote-line-expandable-body p {
    margin: 0 0 4px;
}
.quote-line-expandable-body p:last-child {
    margin: 0;
}
.quote-duration-pill,
.quote-stock-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 7px;
    font-size: 11px;
    font-weight: 600;
    border-radius: 4px;
    background: #e0f2fe;
    color: #0369a1;
}
.quote-stock-pill {
    background: #dcfce7;
    color: #15803d;
}

/* Compact Step 2 Picker Options */
.quote-picker-option {
    min-height: 48px;
    padding: 6px 10px;
    border-radius: 6px;
    gap: 8px;
}
.quote-picker-option-main strong {
    font-size: 13px;
    line-height: 1.25;
}
.quote-picker-option-main small,
.quote-picker-option-side em {
    font-size: 11px;
}
.quote-picker-option-side b {
    font-size: 13px;
}

/* Step 2 Collapsible Catalog Accordions */
.quote-catalog-collapse {
    margin-top: 14px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #ffffff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    overflow: hidden;
}
.quote-catalog-toggle-btn {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 16px;
    background: #f8fafc;
    color: #1e293b;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    user-select: none;
    list-style: none;
    border-bottom: 1px solid transparent;
    transition: background 0.15s ease, border-color 0.15s ease;
}
.quote-catalog-toggle-btn:hover {
    background: #f1f5f9;
}
.quote-catalog-collapse[open] .quote-catalog-toggle-btn {
    background: #f1f5f9;
    border-bottom-color: #e2e8f0;
}
.quote-catalog-toggle-btn::-webkit-details-marker {
    display: none;
}
.quote-catalog-toggle-state {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    font-weight: 600;
    color: #0897b4;
}
.quote-catalog-toggle-btn .quote-chevron {
    font-size: 11px;
    transition: transform 0.2s ease;
}
.quote-catalog-collapse[open] .quote-chevron {
    transform: rotate(180deg);
}
.quote-catalog-collapse-body {
    padding: 16px;
    background: #ffffff;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

/* Responsive 3-column desktop layout for expanded picker lists (prevents 4-6 column squishing) */
.quote-picker-list {
    display: grid !important;
    grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
    gap: 12px !important;
    max-height: 340px !important;
    padding: 12px !important;
    background: #f8fafc !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 8px !important;
    overflow-y: auto !important;
}
.quote-picker-list-large {
    max-height: 400px !important;
}

/* Ensure service names, badges, stock, and prices do not overlap or become clipped */
.quote-picker-option {
    display: grid !important;
    grid-template-columns: 20px minmax(0, 1fr) auto !important;
    align-items: center !important;
    gap: 12px !important;
    min-height: 52px !important;
    padding: 8px 12px !important;
    background: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 6px !important;
    box-sizing: border-box !important;
    transition: all 0.15s ease !important;
}
.quote-picker-option:hover {
    border-color: #cbd5e1 !important;
    background: #f8fafc !important;
}
.quote-picker-option:has(input:checked) {
    background: #f0fdff !important;
    border-color: #67e8f9 !important;
}
.quote-picker-option-main {
    min-width: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 3px !important;
}
.quote-picker-title {
    display: flex !important;
    flex-wrap: wrap !important;
    align-items: center !important;
    gap: 6px !important;
    min-width: 0 !important;
}
.quote-picker-option-main strong {
    font-size: 13px !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    line-height: 1.3 !important;
    word-break: break-word !important;
}
.quote-picker-option-main small {
    font-size: 11px !important;
    color: #64748b !important;
    line-height: 1.25 !important;
}
.quote-picker-option-side {
    text-align: right !important;
    white-space: nowrap !important;
    flex-shrink: 0 !important;
    min-width: 85px !important;
    display: flex !important;
    flex-direction: column !important;
    align-items: flex-end !important;
    gap: 2px !important;
}
.quote-picker-option-side b {
    font-size: 14px !important;
    font-weight: 800 !important;
    color: #007496 !important;
}
.quote-picker-option-side em {
    font-size: 11px !important;
    color: #64748b !important;
    font-style: normal !important;
}

/* Responsive adjustments: 3 cols desktop, 2 cols medium/tablet, 1 col mobile */
@media (max-width: 1280px) {
    .quote-picker-list {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
}
@media (max-width: 960px) {
    .quote-line-row {
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }
    .quote-line-name {
        grid-column: 1 / -1;
    }
    .quote-line-total {
        grid-column: span 1;
    }
    .quote-remove-line {
        grid-column: span 1;
        justify-self: end;
    }
}
@media (max-width: 768px) {
    .quote-picker-list {
        grid-template-columns: 1fr !important;
    }
    .quote-picker-option {
        grid-template-columns: 22px minmax(0, 1fr) !important;
    }
    .quote-picker-option-side {
        grid-column: 2 !important;
        justify-self: start !important;
        align-items: flex-start !important;
    }
}
@media (max-width: 600px) {
    .quote-line-row {
        grid-template-columns: 1fr;
    }
    .quote-remove-line {
        justify-self: start;
    }
}
</style>

<div class="quote-create-page">
    <form id="quotationForm" method="POST" action="/hwtires/api/quotations-api.php">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="csrf_token" value="<?php echo esc_attr(generate_csrf_token()); ?>">
        <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
        <input type="hidden" name="redirect" value="<?php echo esc_attr($redirect_url); ?>">
        <div id="itemsData"></div>

        <section class="quote-create-hero">
            <div>
                <h1>Service Operation</h1>
                <p>Complete inspection before creating the service operation</p>
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

        <section class="service-operation-steps" aria-label="Service operation steps">
            <button type="button" class="service-step active" data-step="1">
                <span>1</span>
                <strong>Service Inspection</strong>
            </button>
            <button type="button" class="service-step" data-step="2">
                <span>2</span>
                <strong>Service Operation</strong>
            </button>
        </section>

        <section class="quote-card quote-details-card">
            <h2>Customer &amp; Vehicle Details</h2>
            <div class="quote-field-grid">
                <label>
                    <span>Select Customer</span>
                    <div class="quote-customer-selector">
                        <input type="search" id="customerAdvancedSearch" maxlength="100" data-text-format="first-letter" placeholder="Search customer name or phone..." autocomplete="off" data-no-autocomplete="true">
                        <div class="quote-customer-results" id="customerAdvancedResults" hidden></div>
                        <select id="customer_id" name="customer_id" required>
                            <option value="">Select Customer</option>
                            <?php foreach ($customers as $customer): ?>
                                <?php
                                $phone = ($customer['phone_mobile'] ?? '') ?: ($customer['contact'] ?? '');
                                $customer_option_text = $customer['name'] . ($phone ? ' - ' . $phone : '');
                                $customer_search_text = strtolower($customer['name'] . ' ' . $phone);
                                ?>
                                <option value="<?php echo (int) $customer['id']; ?>"
                                        data-search="<?php echo esc_attr($customer_search_text); ?>"
                                        <?php echo $selected_customer_id === (int) $customer['id'] ? 'selected' : ''; ?>>
                                    <?php echo esc_html($customer_option_text); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </label>
                <label>
                    <span>Select Vehicle</span>
                    <select id="vehicle_id" name="vehicle_id">
                        <option value="">Select Vehicle</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <option value="<?php echo (int) $vehicle['id']; ?>" <?php echo $selected_vehicle_id === (int) $vehicle['id'] ? 'selected' : ''; ?>>
                                <?php echo esc_html(trim(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? '')) . ' (' . ($vehicle['plate_number'] ?? '-') . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <div class="quote-info-band">
                <div>
                    <h3>Customer Info</h3>
                    <p id="customerInfoName">Select a customer</p>
                    <p id="customerInfoPhone">-</p>
                </div>
                <div>
                    <h3>Vehicle Info</h3>
                    <p id="vehicleInfoName">Select a vehicle</p>
                    <p id="vehicleInfoPlate">-</p>
                </div>
            </div>
        </section>

        <section class="quote-card quote-inspection-card" id="serviceInspectionStep">
            <div class="quote-section-head">
                <h2>Step 1: Service Inspection</h2>
                <span class="quote-step-label">Required before service operation</span>
            </div>
            <div class="quote-field-grid">
                <label>
                    <span>Customer Concern / Request <em>*</em></span>
                    <textarea id="inspection_complaint" name="inspection_complaint" rows="4" maxlength="2000" data-text-format="first-letter" placeholder="Describe what the customer reported..." required></textarea>
                    <div class="inspection-preset-group" data-preset-target="inspection_complaint">
                        <span>Quick choices</span>
                        <div class="inspection-preset-options">
                            <label><input type="checkbox" value="Steering pulls to one side">Steering pulls to one side</label>
                            <label><input type="checkbox" value="Vibration while driving">Vibration while driving</label>
                            <label><input type="checkbox" value="Tire pressure or leak concern">Tire pressure or leak concern</label>
                            <label><input type="checkbox" value="Brake noise or weak braking">Brake noise or weak braking</label>
                            <label><input type="checkbox" value="Engine performance concern">Engine performance concern</label>
                            <label><input type="checkbox" value="Battery or starting concern">Battery or starting concern</label>
                            <label><input type="checkbox" value="Air conditioning not cooling">Air conditioning not cooling</label>
                            <label><input type="checkbox" value="Scheduled maintenance check-up">Scheduled maintenance check-up</label>
                        </div>
                    </div>
                </label>
                <label>
                    <span>Current Mileage (km)</span>
                    <input type="number" id="inspection_mileage" name="inspection_mileage" min="0" step="1" placeholder="e.g., 45000">
                </label>
            </div>
            <div class="quote-field-grid">
                <label>
                    <span>Inspection Findings <em>*</em></span>
                    <textarea id="inspection_findings" name="inspection_findings" rows="4" maxlength="2000" data-text-format="first-letter" placeholder="Record visible issues, test results, or technician observations..." required></textarea>
                    <div class="inspection-preset-group" data-preset-target="inspection_findings">
                        <span>Recommended findings</span>
                        <div class="inspection-preset-options">
                            <label><input type="checkbox" value="Recommended: Wheel alignment check needed">Wheel alignment check needed</label>
                            <label><input type="checkbox" value="Recommended: Wheel balancing advised">Wheel balancing advised</label>
                            <label><input type="checkbox" value="Recommended: Tire repair or tire change needed">Tire repair or tire change needed</label>
                            <label><input type="checkbox" value="Recommended: Brake cleaning and adjustment needed">Brake cleaning and adjustment needed</label>
                            <label><input type="checkbox" value="Recommended: Suspension repair inspection required">Suspension repair inspection required</label>
                            <label><input type="checkbox" value="Recommended: Diagnostic scan needed">Diagnostic scan needed</label>
                            <label><input type="checkbox" value="Recommended: AC refrigerant service check needed">AC refrigerant service check needed</label>
                        </div>
                    </div>
                </label>
                <label>
                    <span>Recommended Action <em>*</em></span>
                    <textarea id="inspection_recommendations" name="inspection_recommendations" rows="4" maxlength="2000" data-text-format="first-letter" placeholder="List recommended services or parts before service operation..." required></textarea>
                    <div class="inspection-preset-group" data-preset-target="inspection_recommendations">
                        <span>Recommended actions</span>
                        <div class="inspection-preset-options">
                            <label><input type="checkbox" value="Recommended: Computerized Four Wheel Alignment">Computerized Four Wheel Alignment</label>
                            <label><input type="checkbox" value="Recommended: Wheel Balancing / Computerized Wheel Balancing">Wheel Balancing</label>
                            <label><input type="checkbox" value="Recommended: Cold Patch Vulcanizing and Tire Repair">Tire Repair</label>
                            <label><input type="checkbox" value="Recommended: Brake Cleaning and Adjustment / Brake Repair">Brake Cleaning / Repair</label>
                            <label><input type="checkbox" value="Recommended: Oil Change and Engine Tune-Up">Oil Change / Tune-Up</label>
                            <label><input type="checkbox" value="Recommended: Auto Diagnostic Scanning / Auto Diagnostic Scanner">Diagnostic Scanning</label>
                            <label><input type="checkbox" value="Recommended: Preventive Maintenance Check-Up">Preventive Maintenance Check-Up</label>
                        </div>
                    </div>
                </label>
            </div>
            <div class="quote-inspection-tech-section">
                <div class="quote-tech-section-header">
                    <h3>Technician Service &amp; Material Directives</h3>
                    <p>Explicit required services and recommended materials identified during technical inspection.</p>
                </div>
                <div class="quote-field-grid">
                    <div class="quote-tech-block">
                        <label class="quote-tech-heading">
                            <span><i class="fas fa-tools" style="color: #b91c1c;"></i> Required Services <em>(Technician Confirmed)</em></span>
                            <small>Finding-based suggested repairs. Check repairs to include as Required in Step 2.</small>
                        </label>
                        <div class="quote-picker-input-row quote-tech-search">
                            <i class="fas fa-search"></i>
                            <input type="search" id="requiredServicesSearch" placeholder="Filter suggested repairs..." autocomplete="off">
                        </div>
                        <div class="quote-tech-list" id="requiredServicesList" aria-label="Suggested required services list"></div>

                        <details class="quote-tech-browse-details" id="browseOtherServicesDetails">
                            <summary class="quote-tech-browse-summary">
                                <span><i class="fas fa-list-ul me-1"></i> Browse Other Services (<span id="otherServicesCount">0</span>)</span>
                                <i class="fas fa-chevron-down quote-chevron"></i>
                            </summary>
                            <div class="quote-tech-browse-body">
                                <div class="quote-picker-input-row quote-tech-search" style="margin-bottom: 8px;">
                                    <i class="fas fa-search"></i>
                                    <input type="search" id="otherServicesSearch" placeholder="Search full catalog to mark required..." autocomplete="off">
                                </div>
                                <div class="quote-tech-list" id="otherServicesList" style="max-height: 200px;" aria-label="Other catalog services list"></div>
                            </div>
                        </details>
                    </div>

                    <div class="quote-tech-block">
                        <label class="quote-tech-heading">
                            <span><i class="fas fa-box" style="color: #0369a1;"></i> Recommended Parts &amp; Items <em>(Optional SKU Selection)</em></span>
                            <small>Finding-based branch parts. Pre-selected in Step 2; customer may decline.</small>
                        </label>
                        <div class="quote-picker-input-row quote-tech-search">
                            <i class="fas fa-search"></i>
                            <input type="search" id="recommendedItemsSearch" placeholder="Filter suggested parts..." autocomplete="off">
                        </div>
                        <div class="quote-tech-list" id="recommendedItemsList" aria-label="Suggested recommended items list"></div>

                        <details class="quote-tech-browse-details" id="browseBranchStockDetails">
                            <summary class="quote-tech-browse-summary">
                                <span><i class="fas fa-boxes me-1"></i> Browse All Branch Stock (<span id="allBranchStockCount">0</span>)</span>
                                <i class="fas fa-chevron-down quote-chevron"></i>
                            </summary>
                            <div class="quote-tech-browse-body">
                                <div class="quote-picker-input-row quote-tech-search" style="margin-bottom: 8px;">
                                    <i class="fas fa-search"></i>
                                    <input type="search" id="allBranchStockSearch" placeholder="Search all branch in-stock parts..." autocomplete="off">
                                </div>
                                <div class="quote-tech-list" id="allBranchStockList" style="max-height: 200px;" aria-label="All branch stock list"></div>
                            </div>
                        </details>
                    </div>
                </div>
            </div>

            <div class="quote-step-actions">
                <button type="button" class="quote-continue-btn" id="continueToQuotationBtn">
                    <span>Continue to Service Operation</span>
                    <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </section>

        <section class="quote-card quote-step-two is-locked">
            <div class="quote-section-head">
                <h2>Step 2: Services</h2>
                <button type="button" class="quote-add-service" id="addServiceBtn">
                    <i class="fas fa-plus"></i>
                    <span>Add Typed Service</span>
                </button>
            </div>
            <div class="quote-line-list" id="serviceRows"></div>
            <details class="quote-catalog-collapse" id="browseAllServicesCollapse">
                <summary class="quote-catalog-toggle-btn">
                    <span><i class="fas fa-layer-group me-2"></i>Browse All Services</span>
                    <span class="quote-catalog-toggle-state"><span class="toggle-text">Show Catalog</span> <i class="fas fa-chevron-down quote-chevron"></i></span>
                </summary>
                <div class="quote-catalog-collapse-body">
                    <div class="quote-picker">
                        <label class="quote-picker-search">
                            <span>Search or type service</span>
                            <div class="quote-picker-input-row">
                                <i class="fas fa-search"></i>
                                <input type="search" id="servicePickerSearch" maxlength="100" data-text-format="first-letter" placeholder="Search services, or type a custom service..." autocomplete="off" data-no-autocomplete="true">
                            </div>
                        </label>
                        <div class="quote-picker-list" id="servicePickerList" aria-label="Available services"></div>
                    </div>
                </div>
            </details>
        </section>

        <section class="quote-card quote-step-two is-locked">
            <div class="quote-section-head">
                <h2>Parts &amp; Items</h2>
                <button type="button" class="quote-add-item" id="addItemBtn">
                    <i class="fas fa-plus"></i>
                    <span>Add Typed Item</span>
                </button>
            </div>
            <div class="quote-line-list" id="itemRows"></div>
            <details class="quote-catalog-collapse" id="browseAllPartsCollapse">
                <summary class="quote-catalog-toggle-btn">
                    <span><i class="fas fa-boxes me-2"></i>Browse All Parts &amp; Items</span>
                    <span class="quote-catalog-toggle-state"><span class="toggle-text">Show Catalog</span> <i class="fas fa-chevron-down quote-chevron"></i></span>
                </summary>
                <div class="quote-catalog-collapse-body">
                    <div class="quote-tabs" aria-label="Item categories">
                        <?php foreach ($item_tabs as $tab): ?>
                            <button type="button" class="<?php echo $tab === 'All' ? 'active' : ''; ?>" data-category="<?php echo esc_attr($tab); ?>">
                                <?php echo esc_html($tab); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <div class="quote-picker">
                        <label class="quote-picker-search">
                            <span>Search or type item</span>
                            <div class="quote-picker-input-row">
                                <i class="fas fa-search"></i>
                                <input type="search" id="itemPickerSearch" maxlength="100" data-text-format="first-letter" placeholder="Search inventory, or type a custom item..." autocomplete="off" data-no-autocomplete="true">
                            </div>
                        </label>
                        <div class="quote-picker-list quote-picker-list-large" id="itemPickerList" aria-label="Available parts and items"></div>
                    </div>
                </div>
            </details>
        </section>

        <section class="quote-card quote-cost-card quote-step-two is-locked">
            <h2>Cost Summary</h2>
            <div class="quote-cost-row">
                <span>Labor Cost</span>
                <label class="quote-labor-field">
                    <input type="number" id="labor_cost" name="labor_cost" min="0" step="0.01" value="0">
                    <small>Auto-filled from selected services; edit when the actual labor changes.</small>
                </label>
            </div>
            <div class="quote-cost-row">
                <span>Parts &amp; Items Cost</span>
                <strong>&#8369;<span id="partsCostDisplay">0</span></strong>
            </div>
            <div class="quote-total-row">
                <span>Total Amount</span>
                <strong>&#8369;<span id="totalAmountDisplay">0</span></strong>
            </div>
        </section>

        <section class="quote-submit-footer quote-step-two is-locked">
            <button type="submit" class="quote-save-btn" id="saveQuotationBtn" disabled>
                <i class="far fa-save"></i>
                <span>Save Service Operation</span>
            </button>
        </section>
    </form>
</div>

<datalist id="serviceOptionsList"></datalist>
<datalist id="itemOptionsList"></datalist>

<script>
const customers = <?php echo json_encode($customers, JSON_UNESCAPED_UNICODE); ?>;
const selectedCustomerId = <?php echo (int) $selected_customer_id; ?>;
const preselectedVehicleId = <?php echo (int) $selected_vehicle_id; ?>;
const currentBranchId = <?php echo (int) $branch_id; ?>;
const servicesCatalog = <?php echo json_encode($service_catalog, JSON_UNESCAPED_UNICODE); ?>;
const inventoryItems = <?php echo json_encode($inventory_data, JSON_UNESCAPED_UNICODE); ?>;
const inventoryBranches = <?php echo json_encode(array_values($inventory_branches), JSON_UNESCAPED_UNICODE); ?>;
const vehiclesByCustomer = <?php echo json_encode($vehicles_by_customer, JSON_UNESCAPED_UNICODE); ?>;

let loadedVehicles = <?php echo json_encode($vehicles, JSON_UNESCAPED_UNICODE); ?>;
let quoteLines = [];
let activeItemCategory = 'All';
let lineCounter = 0;
let quotationStep = 1;
let laborCostTouched = false;
let optedOutKeys = new Set();

const RECOMMENDED_ACTION_SERVICE_MAP = {
    'computerized four wheel alignment': 'Computerized Four Wheel Alignment',
    'wheel balancing / computerized wheel balancing': 'Wheel Balancing / Computerized Wheel Balancing',
    'wheel balancing': 'Wheel Balancing / Computerized Wheel Balancing',
    'cold patch vulcanizing and tire repair': 'Cold Patch Vulcanizing and Tire Repair',
    'tire repair': 'Cold Patch Vulcanizing and Tire Repair',
    'brake cleaning and adjustment / brake repair': 'Brake Cleaning and Adjustment / Brake Repair',
    'brake cleaning / repair': 'Brake Cleaning and Adjustment / Brake Repair',
    'oil change and engine tune-up': 'Oil Change and Engine Tune-Up',
    'oil change / tune-up': 'Oil Change and Engine Tune-Up',
    'auto diagnostic scanning / auto diagnostic scanner': 'Auto Diagnostic Scanning / Auto Diagnostic Scanner',
    'diagnostic scanning': 'Auto Diagnostic Scanning / Auto Diagnostic Scanner',
    'preventive maintenance check-up': 'Preventive Maintenance Check-Up'
};

function resolveRecommendedService(text) {
    if (!text) return null;
    const clean = String(text).replace(/^recommended:\s*/i, '').trim();
    const lower = clean.toLowerCase();

    if (RECOMMENDED_ACTION_SERVICE_MAP[lower]) {
        return findService(RECOMMENDED_ACTION_SERVICE_MAP[lower]);
    }

    const directMatch = findService(clean);
    if (directMatch) return directMatch;

    return servicesCatalog.find((s) => s.name.toLowerCase() === lower) || null;
}

function money(value) {
    return Number(value || 0).toLocaleString('en-PH', { maximumFractionDigits: 0 });
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[char]));
}

function normalizePickerText(value) {
    return String(value || '').trim().toLowerCase();
}

function getCustomer(customerId) {
    return customers.find((customer) => String(customer.id) === String(customerId));
}

let customerOptionCache = [];

function cacheCustomerOptions() {
    const select = document.getElementById('customer_id');
    customerOptionCache = Array.from(select.options).map((option) => ({
        value: option.value,
        text: option.textContent,
        search: String(option.dataset.search || option.textContent || '').toLowerCase(),
        selected: option.selected
    }));
}

function createCustomerOption(cachedOption) {
    const option = document.createElement('option');
    option.value = cachedOption.value;
    option.textContent = cachedOption.text;
    option.dataset.search = cachedOption.search;
    return option;
}

function filterCustomerDropdown() {
    const searchInput = document.getElementById('customerAdvancedSearch');
    const select = document.getElementById('customer_id');
    if (!searchInput || !select || customerOptionCache.length === 0) {
        return;
    }

    const selectedValue = select.value;
    const terms = searchInput.value
        .trim()
        .toLowerCase()
        .split(/\s+/)
        .filter(Boolean);
    const defaultOption = customerOptionCache.find((option) => option.value === '') || { value: '', text: 'Select Customer', search: '' };
    const matches = customerOptionCache
        .filter((option) => option.value !== '')
        .filter((option) => terms.length === 0 || terms.every((term) => option.search.includes(term)));

    select.innerHTML = '';
    select.appendChild(createCustomerOption(defaultOption));
    matches.forEach((option) => select.appendChild(createCustomerOption(option)));

    if (selectedValue && !matches.some((option) => option.value === selectedValue)) {
        const selectedOption = customerOptionCache.find((option) => option.value === selectedValue);
        if (selectedOption) {
            select.appendChild(createCustomerOption(selectedOption));
        }
    }

    select.value = selectedValue;
    renderCustomerSearchResults(terms, matches);
}

function syncCustomerSearchFromSelection() {
    const searchInput = document.getElementById('customerAdvancedSearch');
    const select = document.getElementById('customer_id');
    if (!searchInput || !select) {
        return;
    }

    const option = select.options[select.selectedIndex];
    searchInput.value = select.value && option ? option.textContent.trim() : '';
    closeCustomerSearchResults();
}

function closeCustomerSearchResults() {
    const results = document.getElementById('customerAdvancedResults');
    if (!results) {
        return;
    }

    results.hidden = true;
    results.innerHTML = '';
}

function customerOptionDetail(option) {
    const customer = getCustomer(option.value);
    if (!customer) {
        return '';
    }

    return customer.phone_mobile || customer.contact || '';
}

function renderCustomerSearchResults(terms, matches) {
    const results = document.getElementById('customerAdvancedResults');
    if (!results) {
        return;
    }

    results.innerHTML = '';
    if (terms.length === 0) {
        closeCustomerSearchResults();
        return;
    }

    const visibleMatches = matches.slice(0, 8);
    if (visibleMatches.length === 0) {
        results.innerHTML = '<div class="quote-customer-result-empty">No matching customers</div>';
        results.hidden = false;
        return;
    }

    visibleMatches.forEach((option) => {
        const customer = getCustomer(option.value);
        const label = customer ? customer.name : option.text.split(' - ')[0];
        const detail = customerOptionDetail(option);
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'quote-customer-result';
        button.dataset.customerId = option.value;
        button.innerHTML = `
            <span>
                <strong>${escapeHtml(label)}</strong>
                <small>${escapeHtml(detail || option.text)}</small>
            </span>
            <em>Customer</em>
        `;
        button.addEventListener('mousedown', (event) => event.preventDefault());
        button.addEventListener('click', () => selectCustomerById(option.value));
        results.appendChild(button);
    });

    results.hidden = false;
}

function selectCustomerById(customerId) {
    const select = document.getElementById('customer_id');
    if (!select) {
        return;
    }

    if (!Array.from(select.options).some((option) => option.value === String(customerId))) {
        const cachedOption = customerOptionCache.find((option) => option.value === String(customerId));
        if (cachedOption) {
            select.appendChild(createCustomerOption(cachedOption));
        }
    }

    select.value = String(customerId);
    syncCustomerSearchFromSelection();
    updateVehicleList();
}

function selectFirstFilteredCustomer() {
    const select = document.getElementById('customer_id');
    const firstMatch = Array.from(select.options).find((option) => option.value !== '');
    if (!firstMatch) {
        return;
    }

    selectCustomerById(firstMatch.value);
}

function getVehicle(vehicleId) {
    return loadedVehicles.find((vehicle) => String(vehicle.id) === String(vehicleId));
}

function vehicleMileageValue(vehicle) {
    if (!vehicle || vehicle.last_mileage === null || vehicle.last_mileage === undefined || vehicle.last_mileage === '') {
        return '';
    }

    const mileage = parseInt(String(vehicle.last_mileage).replace(/[^0-9]/g, ''), 10);
    return Number.isFinite(mileage) && mileage > 0 ? mileage : '';
}

function syncInspectionMileage(vehicle) {
    const mileageInput = document.getElementById('inspection_mileage');
    if (!mileageInput) return;

    const mileage = vehicleMileageValue(vehicle);
    mileageInput.value = mileage !== '' ? mileage : '';
}

function populateDatalists() {
    const serviceList = document.getElementById('serviceOptionsList');
    serviceList.innerHTML = servicesCatalog.map((service) =>
        `<option value="${escapeHtml(service.name)}" label="₱${money(service.price)}"></option>`
    ).join('');

    updateItemDatalist();
    renderPickerLists();
}

function updateItemDatalist() {
    const itemList = document.getElementById('itemOptionsList');
    const filtered = inventoryItems.filter((item) => activeItemCategory === 'All' || item.group === activeItemCategory);
    itemList.innerHTML = filtered.map((item) => {
        const label = `${item.branch_name} • ₱${money(item.unit_price)} • Stock ${item.quantity}`;
        return `<option value="${escapeHtml(item.name)}" label="${escapeHtml(label)}"></option>`;
    }).join('');
}

function getDirectRecommendedServices() {
    const directServices = new Set();
    const recGroup = document.querySelector('.inspection-preset-group[data-preset-target="inspection_recommendations"]');
    if (recGroup) {
        recGroup.querySelectorAll('input[type="checkbox"]:checked').forEach((cb) => {
            const matched = resolveRecommendedService(cb.value);
            if (matched) directServices.add(normalizePickerText(matched.name));
        });
    }
    const recText = document.getElementById('inspection_recommendations')?.value || '';
    if (recText) {
        recText.split('\n').map((l) => l.trim()).filter(Boolean).forEach((line) => {
            const matched = resolveRecommendedService(line);
            if (matched) directServices.add(normalizePickerText(matched.name));
        });
    }
    return directServices;
}

function renderRequiredServicesList() {
    const container = document.getElementById('requiredServicesList');
    const search = document.getElementById('requiredServicesSearch');
    const otherContainer = document.getElementById('otherServicesList');
    const otherSearch = document.getElementById('otherServicesSearch');
    const otherCountEl = document.getElementById('otherServicesCount');
    if (!container) return;

    const query = normalizePickerText(search?.value || '');
    const otherQuery = normalizePickerText(otherSearch?.value || '');
    const requiredKeys = new Set(
        quoteLines
            .filter((l) => l.kind === 'service' && l.is_required)
            .map((l) => l.picker_key)
    );

    const recText = getServiceRecommendationText();
    const directServices = getDirectRecommendedServices();

    const categorized = servicesCatalog.map((service) => {
        const normName = normalizePickerText(service.name);
        const isDirect = directServices.has(normName);
        const ruleScore = serviceRecommendationScore(service, recText);
        const pickerKey = `service:${normName}`;
        const isRequired = requiredKeys.has(pickerKey);
        const isRelevant = isDirect || ruleScore >= 4 || isRequired;
        const totalScore = (isRequired ? 200 : 0) + (isDirect ? 100 : 0) + ruleScore;

        return {
            ...service,
            pickerKey,
            isDirect,
            ruleScore,
            isRequired,
            isRelevant,
            totalScore
        };
    });

    // 1. Primary Suggested Repairs (Relevant to inspection findings or already confirmed as required)
    const suggested = categorized
        .filter((s) => s.isRelevant)
        .filter((s) => {
            const text = `${s.name} ${s.category || ''}`;
            return !query || normalizePickerText(text).includes(query);
        })
        .sort((a, b) => {
            if (b.totalScore !== a.totalScore) return b.totalScore - a.totalScore;
            return a.name.localeCompare(b.name);
        });

    if (!suggested.length) {
        container.innerHTML = '<div class="quote-tech-empty"><i class="fas fa-clipboard-list me-1"></i> No suggested repairs match current findings. Use "Browse Other Services" below or enter inspection findings.</div>';
    } else {
        container.innerHTML = suggested.map((service) => {
            const checked = service.isRequired ? 'checked' : '';
            const itemClass = checked ? 'quote-tech-item is-checked' : 'quote-tech-item';
            const manualBadge = (service.isRequired && !service.isDirect && service.ruleScore < 4)
                ? '<span class="quote-badge-manual">Manual</span>'
                : '';
            return `
                <label class="${itemClass}">
                    <span class="quote-tech-item-left">
                        <input type="checkbox" class="required-service-checkbox" value="${escapeHtml(service.name)}" ${checked}>
                        <span class="quote-tech-item-info">
                            <strong class="quote-tech-item-name">${escapeHtml(service.name)}${manualBadge}</strong>
                            <small class="quote-tech-item-meta">${escapeHtml(service.category || 'Service')}</small>
                        </span>
                    </span>
                    <span class="quote-tech-item-price">&#8369;${money(service.price)}</span>
                </label>
            `;
        }).join('');
    }

    // 2. Collapsed Browse Other Services (Full Catalog)
    if (otherContainer) {
        const others = categorized
            .filter((s) => !s.isRelevant)
            .filter((s) => {
                const text = `${s.name} ${s.category || ''}`;
                return !otherQuery || normalizePickerText(text).includes(otherQuery);
            })
            .sort((a, b) => a.name.localeCompare(b.name));

        if (otherCountEl) {
            const totalOthers = categorized.filter((s) => !s.isRelevant).length;
            otherCountEl.textContent = String(totalOthers);
        }

        if (!others.length) {
            otherContainer.innerHTML = '<div class="quote-tech-empty" style="padding:10px;">No other services found.</div>';
        } else {
            otherContainer.innerHTML = others.map((service) => {
                const checked = service.isRequired ? 'checked' : '';
                const itemClass = checked ? 'quote-tech-item is-checked' : 'quote-tech-item';
                return `
                    <label class="${itemClass}">
                        <span class="quote-tech-item-left">
                            <input type="checkbox" class="required-service-checkbox" value="${escapeHtml(service.name)}" ${checked}>
                            <span class="quote-tech-item-info">
                                <strong class="quote-tech-item-name">${escapeHtml(service.name)}</strong>
                                <small class="quote-tech-item-meta">${escapeHtml(service.category || 'Service')}</small>
                            </span>
                        </span>
                        <span class="quote-tech-item-price">&#8369;${money(service.price)}</span>
                    </label>
                `;
            }).join('');
        }
    }
}

function renderStep1RecommendedItemsList() {
    const container = document.getElementById('recommendedItemsList');
    const search = document.getElementById('recommendedItemsSearch');
    const otherContainer = document.getElementById('allBranchStockList');
    const otherSearch = document.getElementById('allBranchStockSearch');
    const otherCountEl = document.getElementById('allBranchStockCount');
    if (!container) return;

    const query = normalizePickerText(search?.value || '');
    const otherQuery = normalizePickerText(otherSearch?.value || '');
    const recommendedKeys = new Set(
        quoteLines
            .filter((l) => l.kind !== 'service' && l.is_recommended)
            .map((l) => l.picker_key)
    );

    const branchItems = inventoryItems.filter((item) =>
        Number(item.branch_id) === currentBranchId && Number(item.quantity || 0) > 0
    );

    const terms = getItemRecommendationTerms();

    const categorized = branchItems.map((item) => {
        const pickerKey = `inventory:${item.id}`;
        const isRecommended = recommendedKeys.has(pickerKey);
        const score = itemRecommendationScore(item, terms);
        const isRelevant = isRecommended || score > 0;
        const totalScore = (isRecommended ? 100 : 0) + score;

        return {
            ...item,
            pickerKey,
            isRecommended,
            score,
            isRelevant,
            totalScore
        };
    });

    // 1. Primary Suggested Items (Relevant to findings/services or already selected)
    const suggested = categorized
        .filter((item) => item.isRelevant)
        .filter((item) => {
            const text = `${item.name} ${item.brand || ''} ${item.size || ''} ${item.group || ''} ${item.category || ''}`;
            return !query || normalizePickerText(text).includes(query);
        })
        .sort((a, b) => {
            if (b.totalScore !== a.totalScore) return b.totalScore - a.totalScore;
            return a.name.localeCompare(b.name);
        });

    if (!suggested.length) {
        container.innerHTML = '<div class="quote-tech-empty"><i class="fas fa-boxes me-1"></i> No relevant parts identified for current inspection. Use "Browse All Branch Stock" below to select parts manually.</div>';
    } else {
        container.innerHTML = suggested.map((item) => {
            const checked = item.isRecommended ? 'checked' : '';
            const itemClass = checked ? 'quote-tech-item is-checked' : 'quote-tech-item';
            const meta = [item.brand, item.size, item.group].filter(Boolean).join(' • ');
            return `
                <label class="${itemClass}">
                    <span class="quote-tech-item-left">
                        <input type="checkbox" class="step1-recommended-item-checkbox" value="${item.id}" ${checked}>
                        <span class="quote-tech-item-info">
                            <strong class="quote-tech-item-name">${escapeHtml(item.name)}</strong>
                            <small class="quote-tech-item-meta">${escapeHtml(meta || 'In-Stock')}</small>
                        </span>
                    </span>
                    <span class="quote-tech-item-price">
                        &#8369;${money(item.unit_price)}
                        <small style="display:block;font-size:11px;color:#64748b;text-align:right;">Stock ${item.quantity}</small>
                    </span>
                </label>
            `;
        }).join('');
    }

    // 2. Collapsed Browse All Branch Stock
    if (otherContainer) {
        const others = categorized
            .filter((item) => !item.isRelevant)
            .filter((item) => {
                const text = `${item.name} ${item.brand || ''} ${item.size || ''} ${item.group || ''} ${item.category || ''}`;
                return !otherQuery || normalizePickerText(text).includes(otherQuery);
            })
            .sort((a, b) => a.name.localeCompare(b.name));

        if (otherCountEl) {
            const totalOthers = categorized.filter((item) => !item.isRelevant).length;
            otherCountEl.textContent = String(totalOthers);
        }

        if (!others.length) {
            otherContainer.innerHTML = '<div class="quote-tech-empty" style="padding:10px;">No other branch stock found.</div>';
        } else {
            otherContainer.innerHTML = others.slice(0, 50).map((item) => {
                const checked = item.isRecommended ? 'checked' : '';
                const itemClass = checked ? 'quote-tech-item is-checked' : 'quote-tech-item';
                const meta = [item.brand, item.size, item.group].filter(Boolean).join(' • ');
                return `
                    <label class="${itemClass}">
                        <span class="quote-tech-item-left">
                            <input type="checkbox" class="step1-recommended-item-checkbox" value="${item.id}" ${checked}>
                            <span class="quote-tech-item-info">
                                <strong class="quote-tech-item-name">${escapeHtml(item.name)}</strong>
                                <small class="quote-tech-item-meta">${escapeHtml(meta || 'In-Stock')}</small>
                            </span>
                        </span>
                        <span class="quote-tech-item-price">
                            &#8369;${money(item.unit_price)}
                            <small style="display:block;font-size:11px;color:#64748b;text-align:right;">Stock ${item.quantity}</small>
                        </span>
                    </label>
                `;
            }).join('');
        }
    }
}

function renderStep1Pickers() {
    renderRequiredServicesList();
    renderStep1RecommendedItemsList();
}

function syncStep1RequiredServiceCheckbox(serviceName, isChecked) {
    const norm = normalizePickerText(serviceName);
    document.querySelectorAll('.required-service-checkbox').forEach((cb) => {
        if (normalizePickerText(cb.value) === norm) {
            cb.checked = isChecked;
            const parent = cb.closest('.quote-tech-item');
            if (parent) parent.classList.toggle('is-checked', isChecked);
        }
    });
}

function syncStep1RecommendedItemCheckbox(itemId, isChecked) {
    document.querySelectorAll('.step1-recommended-item-checkbox').forEach((cb) => {
        if (String(cb.value) === String(itemId)) {
            cb.checked = isChecked;
            const parent = cb.closest('.quote-tech-item');
            if (parent) parent.classList.toggle('is-checked', isChecked);
        }
    });
}

function renderPickerLists() {
    renderServicePickerList();
    renderItemPickerList();
    renderStep1Pickers();
}

const NONREPAIRABLE_TIRE_MARKERS = [
    'beyond repair',
    'unrepairable',
    'cannot be repaired',
    'not repairable',
    'sidewall damage',
    'sidewall puncture',
    'sidewall tear',
    'sidewall cut',
    'sidewall bulge',
    'tire blowout',
    'blowout'
];

const PUNCTURE_REPAIR_SIGNALS = [
    'vulcanizing', 'cold patch', 'puncture', 'tire leak', 'slow leak', 'flat tire',
    'nail', 'screw', 'patch tire', 'tire repair', 'leaking air', 'tread puncture'
];

function splitInspectionClauses(text) {
    if (!text) return [];
    return String(text)
        .toLowerCase()
        .split(/[\n\r;]+|\.(?:\s+|$)|;\s*|\b(?:but|while|whereas|however|although)\b|,\s*(?=(?:rear|front|left|right|other|spare|second|2nd|\d+\.|\*|-)\b)/i)
        .map((s) => s.trim())
        .filter(Boolean);
}

function hasNonrepairableTireSignal(text) {
    const norm = String(text || '').toLowerCase();
    return NONREPAIRABLE_TIRE_MARKERS.some((marker) => norm.includes(marker));
}

function hasRepairablePunctureSignal(text) {
    const clauses = splitInspectionClauses(text);
    if (!clauses.length) return false;

    return clauses.some((clause) => {
        const hasPuncture = PUNCTURE_REPAIR_SIGNALS.some((sig) => clause.includes(sig));
        if (!hasPuncture) return false;
        const isNonrepairable = NONREPAIRABLE_TIRE_MARKERS.some((marker) => clause.includes(marker));
        return !isNonrepairable;
    });
}

function getServiceRecommendationText() {
    return normalizePickerText([
        document.getElementById('inspection_complaint')?.value || '',
        document.getElementById('inspection_findings')?.value || '',
        document.getElementById('inspection_recommendations')?.value || ''
    ].join(' '));
}

function serviceRecommendationScore(service, recommendationText) {
    if (!recommendationText) return 0;

    const serviceText = normalizePickerText([service.name, service.category].filter(Boolean).join(' '));
    const rules = [
        { keys: ['align', 'alignment', 'steering', 'pulls', 'camber', 'caster', 'toe'], services: ['computerized four wheel alignment'] },
        { keys: ['balance', 'balancing', 'vibration', 'shake', 'wheel wobble'], services: ['wheel balancing', 'computerized wheel balancing'] },
        { keys: ['mount', 'mounting', 'rotate', 'rotation', 'tire change', 'tire replacement', 'beyond repair', 'unrepairable', 'cannot be repaired', 'not repairable'], services: ['tire mounting', 'tire rotation', 'tire change'] },
        { keys: ['under chassis', 'underchassis', 'suspension', 'kalampag', 'bushing', 'shock', 'ball joint', 'tie rod'], services: ['under chassis', 'suspension repair', 'suspension parts installation'] },
        { keys: ['oil', 'tune', 'tune-up', 'maintenance', 'pms'], services: ['oil change', 'engine tune-up', 'preventive maintenance'] },
        { keys: ['nitrogen', 'tire pressure', 'hangin', 'pressure'], services: ['nitrogen air tire inflation'] },
        { keys: ['battery', 'charging', 'start', 'starting'], services: ['battery check-up', 'fast charging'] },
        { keys: ['atf', 'automatic transmission', 'transmission', 'flush'], services: ['automatic transmission flushing', 'atf changer'] },
        { keys: ['brake', 'brakes', 'pad', 'weak braking', 'brake noise'], services: ['brake cleaning', 'brake repair', 'adjustment'] },
        { keys: ['clutch', 'manual clutch'], services: ['manual clutch repair'] },
        { keys: ['sanitizing', 'sanitize', 'odor', 'backtozero'], services: ['car sanitizing', 'backtozero'] },
        { keys: ['diagnostic', 'scan', 'scanner', 'check engine'], services: ['auto diagnostic'] },
        { keys: ['vulcanizing', 'patch', 'flat', 'leak', 'puncture', 'nail', 'screw', 'slow leak'], services: ['cold patch vulcanizing', 'tire repair'] },
        { keys: ['egr', 'smoke', 'carbon'], services: ['egr cleaning'] },
        { keys: ['accessory', 'accessories', 'installation', 'install'], services: ['car accessories installation'] },
        { keys: ['aircon', 'air conditioning', 'a/c', 'ac ', 'cooling', 'freon', 'refrigerant'], services: ['ac refrigerant charging'] }
    ];

    let score = 0;
    rules.forEach((rule) => {
        if (!rule.keys.some((key) => recommendationText.includes(key))) {
            return;
        }

        if (rule.services.some((serviceName) => serviceText.includes(serviceName))) {
            // Suppress automatic puncture-repair service if tire findings are nonrepairable only
            if ((serviceText.includes('cold patch') || serviceText.includes('tire repair') || serviceText.includes('vulcaniz')) && !hasRepairablePunctureSignal(recommendationText)) {
                return;
            }
            score += 4;
        }
    });

    return score;
}

function renderServicePickerList() {
    const picker = document.getElementById('servicePickerList');
    const search = document.getElementById('servicePickerSearch');
    if (!picker || !search) return;

    const query = normalizePickerText(search.value);
    const recommendationText = getServiceRecommendationText();
    const selectedKeys = new Set(
        quoteLines
            .filter((line) => line.kind === 'service')
            .map((line) => line.picker_key || `service:${normalizePickerText(line.name)}`)
    );
    const services = servicesCatalog
        .map((service) => ({
            ...service,
            recommended_score: serviceRecommendationScore(service, recommendationText)
        }))
        .filter((service) => {
            const searchable = `${service.name} ${service.category || ''} ${service.price} ${service.estimated_duration || ''} ${service.description || ''}`;
            return !query || normalizePickerText(searchable).includes(query);
        })
        .sort((a, b) => {
            const scoreDiff = (b.recommended_score || 0) - (a.recommended_score || 0);
            if (scoreDiff !== 0) return scoreDiff;

            return String(a.name || '').localeCompare(String(b.name || ''));
        })
        .slice(0, 40);

    if (!services.length) {
        picker.innerHTML = '<div class="quote-picker-empty">No matching services. Type a name, then use Add Typed Service.</div>';
        return;
    }

    picker.innerHTML = services.map((service) => {
        const pickerKey = `service:${normalizePickerText(service.name)}`;
        const checked = selectedKeys.has(pickerKey) ? 'checked' : '';
        const recommendedBadge = service.recommended_score > 0
            ? '<span class="quote-recommended-badge">Recommended</span>'
            : '';
        const variableBadge = Number(service.is_variable_price || 0) === 1
            ? '<span class="quote-variable-badge">Adjustable</span>'
            : '';
        const meta = service.category || 'Service';
        return `
            <label class="quote-picker-option">
                <input type="checkbox" class="service-picker-check" value="${escapeHtml(service.name)}" ${checked}>
                <span class="quote-picker-option-main">
                    <span class="quote-picker-title">
                        <strong>${escapeHtml(service.name)}</strong>
                        ${recommendedBadge}
                        ${variableBadge}
                    </span>
                    <small class="quote-picker-meta">${escapeHtml(meta)}</small>
                </span>
                <span class="quote-picker-option-side">
                    <b>&#8369;${money(service.price)}</b>
                    <em>Labor &#8369;${money(service.labor_cost || service.price || 0)}</em>
                </span>
            </label>
        `;
    }).join('');
}

const NON_REPAIR_ACCESSORY_WORDS = [
    'steering wheel cover', 'steering wheel lock', 'steering cover', 'steering lock',
    'wheel cover', 'car cover', 'seat cover', 'dash cam', 'perfume', 'freshener',
    'phone holder', 'sunshade', 'floor mat', 'cushion', 'keychain', 'decal',
    'sticker', 'horn', 'tint', 'organizer'
];

function isNonRepairAccessory(item) {
    const category = normalizePickerText(item.category || '');
    const group = normalizePickerText(item.group || '');
    const name = normalizePickerText(item.name || '');

    if (category === 'accessories' || group === 'accessories') {
        return true;
    }

    return NON_REPAIR_ACCESSORY_WORDS.some((word) => name.includes(word));
}

function getItemRecommendationTerms() {
    const textSources = [
        document.getElementById('inspection_complaint')?.value || '',
        document.getElementById('inspection_findings')?.value || '',
        document.getElementById('inspection_recommendations')?.value || '',
        ...quoteLines.filter((line) => line.kind === 'service').map((line) => line.name || '')
    ];
    const text = normalizePickerText(textSources.join(' '));
    const terms = new Set();

    // 1. Engine Oil & Maintenance
    if (['oil change', 'change oil', 'engine tune', 'tune-up', 'maintenance', 'pms', 'lube'].some((k) => text.includes(k))) {
        terms.add('engine_oil');
        terms.add('oil_filter');
    }

    // 2. Air / Fuel / Cabin Filters
    if (text.includes('air filter') || text.includes('clean air filter')) {
        terms.add('air_filter');
    }
    if (text.includes('fuel filter')) {
        terms.add('fuel_filter');
    }
    if (text.includes('cabin filter') || text.includes('ac filter')) {
        terms.add('cabin_filter');
    }

    // 3. Brake Service
    if (['brake', 'brakes', 'brake noise', 'weak braking', 'brake pad', 'brake repair'].some((k) => text.includes(k))) {
        terms.add('brake_parts');
    }

    // 4. Battery / Starting
    if (['battery', 'not starting', 'hard start', 'starting concern', 'fast charging', 'battery check'].some((k) => text.includes(k))) {
        terms.add('battery');
    }

    // 5. Spark Plugs
    if (['spark plug', 'misfire', 'engine stumble', 'rough idle'].some((k) => text.includes(k))) {
        terms.add('spark_plug');
    }

    // 6. Fluids (Coolant / ATF / AC Refrigerant)
    if (['coolant', 'radiator', 'overheat', 'antifreeze'].some((k) => text.includes(k))) {
        terms.add('coolant');
    }
    if (['atf', 'automatic transmission', 'transmission flush', 'transmission fluid'].some((k) => text.includes(k))) {
        terms.add('atf');
    }
    if (['aircon', 'air conditioning', 'refrigerant', 'freon', 'cooling', 'a/c', 'ac '].some((k) => text.includes(k))) {
        terms.add('refrigerant');
    }

    // 7. Wheel Balancing Consumables (Weights, Valves)
    if (['wheel balancing', 'balancing', 'vibration while driving', 'wheel wobble', 'shake'].some((k) => text.includes(k))) {
        terms.add('balancing_weights');
        terms.add('tire_valve');
    }

    // 8. Tire Puncture / Patching Consumables (Requires genuinely repairable puncture)
    if (hasRepairablePunctureSignal(text)) {
        terms.add('cold_patch');
        terms.add('tire_valve');
    }

    // 9. Replacement Tires (STRICT: Requires explicit tire replacement/damage context or nonrepairable tire)
    const explicitTireReplacementSignals = [
        'replace tire', 'tire replacement', 'new tire', 'change tire', 'tire change',
        'worn tire', 'bald tire', 'tread wear', 'thread wear', 'tire blowout',
        'tire bulge', 'tire damage', 'sidewall damage', 'worn out tire', 'replacement required',
        'beyond repair', 'unrepairable', 'cannot be repaired', 'not repairable'
    ];
    if (explicitTireReplacementSignals.some((k) => text.includes(k))) {
        terms.add('replacement_tire');
    }

    return Array.from(terms);
}

function itemRecommendationScore(item, terms) {
    if (!terms.length) return 0;
    if (isNonRepairAccessory(item)) return 0;

    const name = normalizePickerText(item.name || '');
    const group = normalizePickerText(item.group || '');
    const category = normalizePickerText(item.category || '');
    let score = 0;

    for (const term of terms) {
        if (term === 'engine_oil' && (group === 'engine oil' || name.includes('oil') && !name.includes('filter'))) {
            score += 5;
        } else if (term === 'oil_filter' && (group === 'filters' || name.includes('filter')) && name.includes('oil')) {
            score += 5;
        } else if (term === 'air_filter' && (group === 'filters' || name.includes('filter')) && name.includes('air')) {
            score += 5;
        } else if (term === 'fuel_filter' && (group === 'filters' || name.includes('filter')) && name.includes('fuel')) {
            score += 5;
        } else if (term === 'cabin_filter' && (group === 'filters' || name.includes('filter')) && name.includes('cabin')) {
            score += 5;
        } else if (term === 'brake_parts' && (group === 'brakes' || name.includes('brake'))) {
            score += 5;
        } else if (term === 'battery' && (group === 'battery' || name.includes('battery'))) {
            score += 5;
        } else if (term === 'spark_plug' && (group === 'spark plugs' || name.includes('spark') || name.includes('plug'))) {
            score += 5;
        } else if (term === 'coolant' && (group === 'fluids' && (name.includes('coolant') || name.includes('radiator')))) {
            score += 5;
        } else if (term === 'atf' && (group === 'fluids' && (name.includes('atf') || name.includes('transmission')))) {
            score += 5;
        } else if (term === 'refrigerant' && (name.includes('refrigerant') || name.includes('freon') || name.includes('134a'))) {
            score += 5;
        } else if (term === 'balancing_weights' && (name.includes('weight') || name.includes('balancing weight'))) {
            score += 5;
        } else if (term === 'cold_patch' && (name.includes('patch') || name.includes('vulcaniz') || name.includes('sealant'))) {
            score += 5;
        } else if (term === 'tire_valve' && (name.includes('valve') || name.includes('valve stem') || name.includes('tire valve'))) {
            score += 4;
        } else if (term === 'replacement_tire' && (category === 'tire' || group === 'tires') && !name.includes('weight') && !name.includes('valve') && !name.includes('patch') && !name.includes('vulcaniz') && !name.includes('sealant') && !name.includes('rubber')) {
            score += 5;
        }
    }

    return score;
}

function renderItemPickerList() {
    const picker = document.getElementById('itemPickerList');
    const search = document.getElementById('itemPickerSearch');
    if (!picker || !search) return;

    const query = normalizePickerText(search.value);
    const recommendationTerms = getItemRecommendationTerms();
    const selectedKeys = new Set(
        quoteLines
            .filter((line) => line.kind !== 'service')
            .map((line) => line.picker_key || `custom_item:${normalizePickerText(line.name)}`)
    );
    const items = inventoryItems
        .filter((item) => activeItemCategory === 'All' || item.group === activeItemCategory)
        .map((item) => ({
            ...item,
            recommended_score: itemRecommendationScore(item, recommendationTerms)
        }))
        .filter((item) => {
            const searchable = `${item.name} ${item.brand || ''} ${item.size || ''} ${item.branch_name || ''} ${item.group || ''}`;
            return !query || normalizePickerText(searchable).includes(query);
        })
        .sort((a, b) => {
            const scoreDiff = (b.recommended_score || 0) - (a.recommended_score || 0);
            if (scoreDiff !== 0) return scoreDiff;

            return String(a.name || '').localeCompare(String(b.name || ''));
        })
        .slice(0, 90);

    if (!items.length) {
        picker.innerHTML = '<div class="quote-picker-empty">No matching inventory items. Type a name, then use Add Typed Item.</div>';
        return;
    }

    picker.innerHTML = items.map((item) => {
        const pickerKey = `inventory:${item.id}`;
        const checked = selectedKeys.has(pickerKey) ? 'checked' : '';
        const detail = item.branch_name || item.group || 'Inventory item';
        const recommendedBadge = item.recommended_score > 0
            ? '<span class="quote-recommended-badge">Recommended</span>'
            : '';
        return `
            <label class="quote-picker-option">
                <input type="checkbox" class="item-picker-check" value="${item.id}" ${checked}>
                <span class="quote-picker-option-main">
                    <span class="quote-picker-title">
                        <strong>${escapeHtml(item.name)}</strong>
                        ${recommendedBadge}
                    </span>
                    <small class="quote-picker-meta">${escapeHtml(detail)}</small>
                </span>
                <span class="quote-picker-option-side">
                    <b>&#8369;${money(item.unit_price)}</b>
                    <em>Stock ${item.quantity}</em>
                </span>
            </label>
        `;
    }).join('');
}

function updateInfoPanels() {
    const customer = getCustomer(document.getElementById('customer_id').value);
    const vehicle = getVehicle(document.getElementById('vehicle_id').value);

    document.getElementById('customerInfoName').textContent = customer ? customer.name : 'Select a customer';
    document.getElementById('customerInfoPhone').textContent = customer ? (customer.phone_mobile || customer.contact || '-') : '-';

    if (vehicle) {
        const vehicleName = `${vehicle.make || ''} ${vehicle.model || ''}`.trim() || 'Vehicle';
        const vehicleMeta = `${vehicle.plate_number || '-'}${vehicle.year ? ' • ' + vehicle.year : ''}`;
        document.getElementById('vehicleInfoName').textContent = vehicleName;
        document.getElementById('vehicleInfoPlate').textContent = vehicleMeta;
        syncInspectionMileage(vehicle);
    } else {
        document.getElementById('vehicleInfoName').textContent = 'Select a vehicle';
        document.getElementById('vehicleInfoPlate').textContent = '-';
        syncInspectionMileage(null);
    }
}

function validateServiceInspection(showMessage = true) {
    const customerId = document.getElementById('customer_id').value;
    const complaint = document.getElementById('inspection_complaint').value.trim();
    const findings = document.getElementById('inspection_findings').value.trim();
    const recommendations = document.getElementById('inspection_recommendations').value.trim();

    if (!customerId) {
        if (showMessage) alert('Please select a customer before continuing');
        return false;
    }

    if (!complaint || !findings || !recommendations) {
        if (showMessage) alert('Please complete the service inspection before continuing');
        return false;
    }

    return true;
}

function setServiceOperationStep(step) {
    quotationStep = step;
    document.querySelectorAll('.service-step').forEach((button) => {
        button.classList.toggle('active', Number(button.dataset.step) === step);
        button.classList.toggle('complete', Number(button.dataset.step) < step);
    });

    document.querySelectorAll('.quote-step-two').forEach((section) => {
        section.classList.toggle('is-locked', step < 2);
    });

    const saveButton = document.getElementById('saveQuotationBtn');
    if (saveButton) {
        saveButton.disabled = step < 2;
    }
}

function syncStep1ToQuoteLines() {
    // 1. Sync checked Required Services from Step 1
    const requiredBoxes = document.querySelectorAll('.required-service-checkbox:checked');
    requiredBoxes.forEach((cb) => {
        const serviceName = cb.value;
        const pickerKey = `service:${normalizePickerText(serviceName)}`;
        optedOutKeys.delete(pickerKey);
        const existing = quoteLines.find((l) => l.picker_key === pickerKey);
        if (existing) {
            existing.is_required = true;
            existing.is_recommended = false;
        } else {
            addServiceFromCatalog(serviceName, { is_required: true, is_recommended: false });
        }
    });

    // 2. Sync checked Recommended Actions from Step 1 presets
    const recGroup = document.querySelector('.inspection-preset-group[data-preset-target="inspection_recommendations"]');
    if (recGroup) {
        const checkedRecs = recGroup.querySelectorAll('input[type="checkbox"]:checked');
        checkedRecs.forEach((cb) => {
            const matchedService = resolveRecommendedService(cb.value);
            if (matchedService) {
                const pickerKey = `service:${normalizePickerText(matchedService.name)}`;
                if (!optedOutKeys.has(pickerKey)) {
                    const existing = quoteLines.find((l) => l.picker_key === pickerKey);
                    if (!existing) {
                        addServiceFromCatalog(matchedService.name, { is_required: false, is_recommended: true });
                    }
                }
            }
        });
    }

    // Also check lines in inspection_recommendations textarea in case typed manually
    const recText = document.getElementById('inspection_recommendations')?.value || '';
    if (recText) {
        recText.split('\n').map((l) => l.trim()).filter(Boolean).forEach((line) => {
            const matchedService = resolveRecommendedService(line);
            if (matchedService) {
                const pickerKey = `service:${normalizePickerText(matchedService.name)}`;
                if (!optedOutKeys.has(pickerKey)) {
                    const existing = quoteLines.find((l) => l.picker_key === pickerKey);
                    if (!existing) {
                        addServiceFromCatalog(matchedService.name, { is_required: false, is_recommended: true });
                    }
                }
            }
        });
    }

    // 3. Sync checked Recommended Items from Step 1
    const recItemBoxes = document.querySelectorAll('.step1-recommended-item-checkbox:checked');
    recItemBoxes.forEach((cb) => {
        const itemId = cb.value;
        const pickerKey = `inventory:${itemId}`;
        if (!optedOutKeys.has(pickerKey)) {
            const existing = quoteLines.find((l) => l.picker_key === pickerKey);
            if (!existing) {
                addItemFromInventory(itemId, { is_required: false, is_recommended: true });
            }
        }
    });
}

function continueToQuotation() {
    if (!validateServiceInspection(true)) {
        return;
    }

    syncStep1ToQuoteLines();
    setServiceOperationStep(2);
    const firstQuoteSection = document.querySelector('.quote-step-two');
    if (firstQuoteSection) {
        firstQuoteSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function updateVehicleList() {
    const customerId = document.getElementById('customer_id').value;
    const vehicleSelect = document.getElementById('vehicle_id');
    vehicleSelect.innerHTML = '<option value="">Select Vehicle</option>';
    loadedVehicles = customerId && vehiclesByCustomer[customerId] ? vehiclesByCustomer[customerId] : [];
    updateInfoPanels();

    if (!customerId) {
        return;
    }

    loadedVehicles.forEach((vehicle) => {
        const option = document.createElement('option');
        const name = `${vehicle.make || ''} ${vehicle.model || ''}`.trim() || 'Vehicle';
        option.value = vehicle.id;
        option.textContent = `${name} (${vehicle.plate_number || '-'})`;
        if (preselectedVehicleId && String(vehicle.id) === String(preselectedVehicleId)) {
            option.selected = true;
        }
        vehicleSelect.appendChild(option);
    });

    if (!vehicleSelect.value && loadedVehicles.length === 1) {
        vehicleSelect.value = String(loadedVehicles[0].id);
    }

    updateInfoPanels();
}

function addLine(type, overrides = {}) {
    const name = String(overrides.name || '').trim();
    const sourceSelect = overrides.source_select || (type === 'service' ? 'service' : (inventoryBranches[0] ? `inventory:${inventoryBranches[0].id}` : 'external'));
    const pickerKey = overrides.picker_key || (type === 'service'
        ? `service:${normalizePickerText(name)}`
        : `custom_item:${normalizePickerText(name)}`);

    const existingLine = quoteLines.find((line) => line.picker_key === pickerKey);
    if (existingLine) {
        if (overrides.is_required) {
            existingLine.is_required = true;
            existingLine.is_recommended = false;
        } else if (overrides.is_recommended && !existingLine.is_required) {
            existingLine.is_recommended = true;
        }
        renderLines();
        return;
    }

    quoteLines.push({
        id: `line_${lineCounter++}`,
        kind: type,
        name,
        quantity: overrides.quantity || 1,
        unit_price: Number(overrides.unit_price || 0),
        item_type: overrides.item_type || (type === 'service' ? 'service' : (activeItemCategory === 'Tires' ? 'tire' : 'part')),
        category: overrides.category || (type === 'service' ? 'Service' : activeItemCategory),
        labor_cost: Number(overrides.labor_cost ?? overrides.unit_price ?? 0),
        estimated_duration: overrides.estimated_duration || '',
        description: overrides.description || '',
        is_variable_price: Number(overrides.is_variable_price || 0),
        source_select: sourceSelect,
        picker_key: pickerKey,
        inventory_item_id: overrides.inventory_item_id || '',
        inventory_branch_id: overrides.inventory_branch_id || '',
        inventory_quantity: Number(overrides.inventory_quantity || 0),
        is_required: Boolean(overrides.is_required || false),
        is_recommended: Boolean(overrides.is_recommended || false)
    });
    renderLines();
}

function addServiceFromCatalog(serviceName, options = {}) {
    const service = findService(serviceName);
    const name = service ? service.name : String(serviceName || '').trim();
    if (!name) return;

    addLine('service', {
        name,
        unit_price: service ? Number(service.price || 0) : 0,
        labor_cost: service ? Number(service.labor_cost || service.price || 0) : 0,
        estimated_duration: service ? String(service.estimated_duration || '') : '',
        description: service ? String(service.description || '') : '',
        is_variable_price: service ? Number(service.is_variable_price || 0) : 0,
        category: service ? (service.category || 'Service') : 'Service',
        picker_key: `service:${normalizePickerText(name)}`,
        is_required: Boolean(options.is_required || false),
        is_recommended: Boolean(options.is_recommended || false)
    });
}

function addItemFromInventory(itemId, options = {}) {
    const item = inventoryItems.find((entry) => String(entry.id) === String(itemId));
    if (!item) return;

    addLine('item', {
        name: item.name,
        unit_price: Number(item.unit_price || 0),
        item_type: item.category === 'tire' ? 'tire' : 'part',
        category: item.group || item.category || 'Part',
        source_select: `inventory:${item.branch_id}`,
        picker_key: `inventory:${item.id}`,
        inventory_item_id: item.id,
        inventory_branch_id: item.branch_id,
        inventory_quantity: Number(item.quantity || 0),
        is_required: Boolean(options.is_required || false),
        is_recommended: Boolean(options.is_recommended || false)
    });
}

function addTypedService() {
    const search = document.getElementById('servicePickerSearch');
    const name = String(search?.value || '').trim();
    if (!name) return;
    addServiceFromCatalog(name);
    search.value = '';
    renderPickerLists();
}

function addTypedItem() {
    const search = document.getElementById('itemPickerSearch');
    const name = String(search?.value || '').trim();
    if (!name) return;

    addLine('item', {
        name,
        unit_price: 0,
        item_type: activeItemCategory === 'Tires' ? 'tire' : 'part',
        category: activeItemCategory === 'All' ? 'Part' : activeItemCategory,
        source_select: 'external',
        picker_key: `custom_item:${normalizePickerText(name)}`
    });
    search.value = '';
    renderPickerLists();
}

function removeLine(lineId) {
    const line = quoteLines.find((l) => l.id === lineId);
    if (!line) return;
    if (line.is_required) {
        alert('This service is marked as Required in Step 1. To remove it, uncheck it in the Step 1 Required Services section.');
        return;
    }
    if (line.is_recommended && !line.is_required) {
        optedOutKeys.add(line.picker_key);
    }
    if (line.kind !== 'service' && line.inventory_item_id) {
        syncStep1RecommendedItemCheckbox(line.inventory_item_id, false);
    }
    quoteLines = quoteLines.filter((l) => l.id !== lineId);
    renderLines();
    renderStep1Pickers();
    renderPickerLists();
}

function removeLineByPickerKey(pickerKey) {
    const line = quoteLines.find((l) => l.picker_key === pickerKey);
    if (!line) return;
    if (line.is_required) {
        alert('This service is marked as Required in Step 1. To remove it, uncheck it in the Step 1 Required Services section.');
        return;
    }
    if (line.is_recommended && !line.is_required) {
        optedOutKeys.add(pickerKey);
    }
    if (line.kind !== 'service' && line.inventory_item_id) {
        syncStep1RecommendedItemCheckbox(line.inventory_item_id, false);
    }
    quoteLines = quoteLines.filter((l) => l.picker_key !== pickerKey);
    renderLines();
    renderStep1Pickers();
    renderPickerLists();
}

function declineRecommendedLine(lineId) {
    const line = quoteLines.find((l) => l.id === lineId);
    if (!line) return;
    optedOutKeys.add(line.picker_key);
    if (line.kind !== 'service' && line.inventory_item_id) {
        syncStep1RecommendedItemCheckbox(line.inventory_item_id, false);
    }
    quoteLines = quoteLines.filter((l) => l.id !== lineId);
    renderLines();
    renderStep1Pickers();
    renderPickerLists();
}

function findService(name) {
    return servicesCatalog.find((service) => service.name.toLowerCase() === String(name).trim().toLowerCase());
}

function findInventoryItem(name, sourceSelect) {
    const normalized = String(name).trim().toLowerCase();
    let items = inventoryItems.filter((item) => item.name.toLowerCase() === normalized);

    if (sourceSelect && sourceSelect.startsWith('inventory:')) {
        const branchId = parseInt(sourceSelect.split(':')[1], 10);
        items = items.filter((item) => Number(item.branch_id) === branchId);
    }

    return items[0] || null;
}

function validateInventoryQuantities(showMessage = true) {
    const requestedByItem = new Map();

    quoteLines.forEach((line) => {
        if (line.kind === 'service') {
            return;
        }

        if (!line.inventory_item_id) {
            return;
        }

        if (line.source_select === 'external' || line.source_select === 'customer_supplied') {
            return;
        }

        const inventoryId = String(line.inventory_item_id);
        const requestedQuantity = Math.max(1, parseInt(line.quantity, 10) || 1);
        const current = requestedByItem.get(inventoryId) || { name: line.name, quantity: 0, available: Number(line.inventory_quantity || 0) };
        current.quantity += requestedQuantity;
        current.available = Number(line.inventory_quantity || current.available || 0);
        requestedByItem.set(inventoryId, current);
    });

    for (const entry of requestedByItem.values()) {
        if (entry.quantity > entry.available) {
            if (showMessage) {
                alert(`Insufficient stock for ${entry.name}. Available: ${entry.available}, requested: ${entry.quantity}.`);
            }
            return false;
        }
    }

    return true;
}

function updateLine(lineId, key, value) {
    const line = quoteLines.find((item) => item.id === lineId);
    if (!line) return;

    if (key === 'quantity') {
        line.quantity = Math.max(1, parseInt(value, 10) || 1);
    } else if (key === 'unit_price') {
        line.unit_price = Math.max(0, parseFloat(value) || 0);
        if (line.kind === 'service') {
            line.labor_cost = line.unit_price;
        }
    } else if (key === 'source_select') {
        line.source_select = value;
        if (value === 'external' || value === 'customer_supplied') {
            line.unit_price = 0;
            line.picker_key = `custom_item:${normalizePickerText(line.name)}`;
            line.inventory_item_id = '';
            line.inventory_branch_id = '';
            line.inventory_quantity = 0;
        } else {
            const inventoryItem = findInventoryItem(line.name, value);
            if (inventoryItem) {
                line.unit_price = Number(inventoryItem.unit_price || 0);
                line.category = inventoryItem.group;
                line.item_type = inventoryItem.category === 'tire' ? 'tire' : 'part';
                line.picker_key = `inventory:${inventoryItem.id}`;
                line.inventory_item_id = inventoryItem.id;
                line.inventory_branch_id = inventoryItem.branch_id;
                line.inventory_quantity = Number(inventoryItem.quantity || 0);
            } else {
                line.inventory_item_id = '';
                line.inventory_branch_id = '';
                line.inventory_quantity = 0;
            }
        }
    } else if (key === 'name') {
        line.name = value;
        if (line.kind === 'service') {
            const service = findService(value);
            if (service) {
                line.unit_price = Number(service.price || 0);
                line.labor_cost = Number(service.labor_cost || service.price || 0);
                line.estimated_duration = String(service.estimated_duration || '');
                line.description = String(service.description || '');
                line.is_variable_price = Number(service.is_variable_price || 0);
                line.category = service.category || 'Service';
            }
            line.picker_key = `service:${normalizePickerText(value)}`;
        } else if (line.source_select !== 'external' && line.source_select !== 'customer_supplied') {
            const inventoryItem = findInventoryItem(value, line.source_select);
            if (inventoryItem) {
                line.unit_price = Number(inventoryItem.unit_price || 0);
                line.category = inventoryItem.group;
                line.item_type = inventoryItem.category === 'tire' ? 'tire' : 'part';
                line.picker_key = `inventory:${inventoryItem.id}`;
                line.inventory_item_id = inventoryItem.id;
                line.inventory_branch_id = inventoryItem.branch_id;
                line.inventory_quantity = Number(inventoryItem.quantity || 0);
            } else {
                line.inventory_item_id = '';
                line.inventory_branch_id = '';
                line.inventory_quantity = 0;
            }
        } else {
            line.picker_key = `custom_item:${normalizePickerText(value)}`;
            line.inventory_item_id = '';
            line.inventory_branch_id = '';
            line.inventory_quantity = 0;
        }
    } else {
        line[key] = value;
    }

    renderLines();
}

function renderLines() {
    const serviceRows = document.getElementById('serviceRows');
    const itemRows = document.getElementById('itemRows');
    serviceRows.innerHTML = '';
    itemRows.innerHTML = '';
    let hasServices = false;
    let hasItems = false;

    quoteLines.forEach((line) => {
        const total = Number(line.quantity || 0) * Number(line.unit_price || 0);
        const hint = lineHint(line);
        const statusBadge = line.is_required
            ? '<span class="quote-badge-required"><i class="fas fa-exclamation-circle"></i> Required</span>'
            : (line.is_recommended
                ? '<span class="quote-badge-recommended"><i class="fas fa-check-circle"></i> Recommended (Optional)</span>'
                : '');
        const optDeclineControl = (line.is_recommended && !line.is_required)
            ? `<label class="quote-opt-checkbox" title="Uncheck to decline recommendation" for="opt_avail_${line.id}"><input type="checkbox" id="opt_avail_${line.id}" checked onchange="declineRecommendedLine('${line.id}')"> <span>Avail</span></label>`
            : '';

        if (line.kind === 'service') {
            hasServices = true;
            serviceRows.insertAdjacentHTML('beforeend', `
                <article class="quote-line-row service-row">
                    <div class="quote-line-name">
                        <div class="quote-line-name-header">
                            <label for="line_name_${line.id}">Selected Service ${statusBadge}</label>
                            ${optDeclineControl}
                        </div>
                        <input id="line_name_${line.id}" value="${escapeHtml(line.name)}" maxlength="150" data-text-format="first-letter" placeholder="Service name..." onchange="updateLine('${line.id}', 'name', this.value)">
                    </div>
                    ${lineControl(line, 'Quantity', 'quantity')}
                    ${lineControl(line, 'Service Labor', 'unit_price')}
                    ${lineTotal(total)}
                    ${removeButton(line.id)}
                    ${hint}
                </article>
            `);
        } else {
            hasItems = true;
            itemRows.insertAdjacentHTML('beforeend', `
                <article class="quote-line-row item-row">
                    <div class="quote-line-name">
                        <div class="quote-line-name-header">
                            <label for="line_name_${line.id}">Selected Item ${statusBadge}</label>
                            ${optDeclineControl}
                        </div>
                        <input id="line_name_${line.id}" value="${escapeHtml(line.name)}" maxlength="255" data-text-format="first-letter" placeholder="Item name..." onchange="updateLine('${line.id}', 'name', this.value)">
                        ${sourceSelect(line)}
                    </div>
                    ${lineControl(line, 'Quantity', 'quantity')}
                    ${lineControl(line, 'Price Per Piece', 'unit_price')}
                    ${lineTotal(total)}
                    ${removeButton(line.id)}
                    ${hint}
                </article>
            `);
        }
    });

    if (!hasServices) {
        serviceRows.innerHTML = '<div class="quote-empty-line">No services selected yet.</div>';
    }
    if (!hasItems) {
        itemRows.innerHTML = '<div class="quote-empty-line">No parts or items selected yet.</div>';
    }

    syncLaborCostFromServices();
    calculateTotals();
    renderPickerLists();
}

function lineHint(line) {
    if (line.kind === 'service') {
        const hasDuration = Boolean(line.estimated_duration);
        const hasDesc = Boolean(line.description);
        const hasVariable = Number(line.is_variable_price || 0) === 1;

        if (!hasDuration && !hasDesc && !hasVariable) {
            return '';
        }

        const durationPill = hasDuration
            ? `<span class="quote-duration-pill"><i class="far fa-clock"></i> ${escapeHtml(line.estimated_duration)}</span>`
            : '';
        const variablePill = hasVariable
            ? `<span class="quote-duration-pill" style="background:#fef3c7;color:#92400e;"><i class="fas fa-sliders-h"></i> Adjustable</span>`
            : '';

        return `
            <details class="quote-line-expandable-details">
                <summary class="quote-line-expandable-summary">
                    <span><i class="fas fa-info-circle text-info"></i> Details &amp; Scope</span>
                    ${durationPill}
                    ${variablePill}
                    <i class="fas fa-chevron-down quote-chevron"></i>
                </summary>
                <div class="quote-line-expandable-body">
                    ${hasDuration ? `<p><strong>Estimated Duration:</strong> ${escapeHtml(line.estimated_duration)}</p>` : ''}
                    ${hasDesc ? `<p>${escapeHtml(line.description)}</p>` : ''}
                    ${hasVariable ? `<p style="color:#b45309;font-weight:600;"><i class="fas fa-info-circle"></i> Price can be adjusted if work scope changes.</p>` : ''}
                </div>
            </details>
        `;
    }

    const details = [];
    if (Number(line.inventory_quantity || 0) > 0) {
        details.push(`<span class="quote-stock-pill"><i class="fas fa-check"></i> Stock: ${Number(line.inventory_quantity || 0)}</span>`);
    }
    if (line.source_select === 'external') {
        details.push('<span class="quote-stock-pill" style="background:#f1f5f9;color:#475569;">External Purchase</span>');
    } else if (line.source_select === 'customer_supplied') {
        details.push('<span class="quote-stock-pill" style="background:#f1f5f9;color:#475569;">Customer Supplied</span>');
    }

    return details.length
        ? `<div style="grid-column: 1 / -1; margin-top: 2px;">${details.join(' ')}</div>`
        : '';
}

function lineControl(line, label, key) {
    const step = key === 'unit_price' ? '0.01' : '1';
    const min = key === 'unit_price' ? '0' : '1';
    const value = key === 'unit_price' ? Number(line[key] || 0).toFixed(2) : line[key];
    const max = key === 'quantity' && line.kind !== 'service' && Number(line.inventory_quantity || 0) > 0
        ? ` max="${Number(line.inventory_quantity || 0)}" title="Available quantity: ${Number(line.inventory_quantity || 0)}"`
        : '';
    return `
        <label>
            <span>${label}</span>
            <input type="number" min="${min}" step="${step}" value="${value}"${max} onchange="updateLine('${line.id}', '${key}', this.value)">
        </label>
    `;
}

function lineTotal(total) {
    return `
        <div class="quote-line-total">
            <span>Total Price</span>
            <strong>&#8369;${money(total)}</strong>
        </div>
    `;
}

function removeButton(lineId) {
    const line = quoteLines.find((l) => l.id === lineId);
    if (line && line.is_required) {
        return `
            <button type="button" class="quote-remove-line is-locked" title="Required service — uncheck in Step 1 to remove" onclick="alert('This service is marked as Required in Step 1. To remove it, uncheck it in the Step 1 Required Services section.');" aria-label="Required service cannot be removed here">
                <i class="fas fa-lock"></i>
            </button>
        `;
    }
    return `
        <button type="button" class="quote-remove-line" onclick="removeLine('${lineId}')" aria-label="Remove line">
            <i class="far fa-trash-alt"></i>
        </button>
    `;
}

function sourceSelect(line) {
    const inventoryOptions = inventoryBranches.map((branch) => {
        const selected = line.source_select === `inventory:${branch.id}` ? 'selected' : '';
        return `<option value="inventory:${branch.id}" ${selected}>${escapeHtml(branch.name)} (Inventory)</option>`;
    }).join('');

    return `
        <div class="quote-source-row">
            <span>Source:</span>
            <select onchange="updateLine('${line.id}', 'source_select', this.value)">
                ${inventoryOptions}
                <option value="external" ${line.source_select === 'external' ? 'selected' : ''}>External Purchase</option>
                <option value="customer_supplied" ${line.source_select === 'customer_supplied' ? 'selected' : ''}>Customer Supplied</option>
            </select>
        </div>
    `;
}

function calculateTotals() {
    const partsCost = quoteLines.reduce((sum, line) => {
        if (line.kind === 'service') {
            return sum;
        }

        return sum + (Number(line.quantity || 0) * Number(line.unit_price || 0));
    }, 0);
    const laborCost = parseFloat(document.getElementById('labor_cost').value) || 0;
    const total = partsCost + laborCost;

    document.getElementById('partsCostDisplay').textContent = money(partsCost);
    document.getElementById('totalAmountDisplay').textContent = money(total);
}

function suggestedLaborCost() {
    return quoteLines
        .filter((line) => line.kind === 'service')
        .reduce((sum, line) => sum + (Number(line.quantity || 0) * Number(line.labor_cost ?? line.unit_price ?? 0)), 0);
}

function syncLaborCostFromServices() {
    const laborInput = document.getElementById('labor_cost');
    if (!laborInput || laborCostTouched) {
        return;
    }

    laborInput.value = suggestedLaborCost().toFixed(2);
}

function serializeLines() {
    const itemsData = document.getElementById('itemsData');
    itemsData.innerHTML = '';

    quoteLines.forEach((line, index) => {
        const source = line.kind === 'service'
            ? 'customer_supplied'
            : (line.source_select === 'external' || line.source_select === 'customer_supplied'
                ? line.source_select
                : (parseInt(line.source_select.split(':')[1], 10) === currentBranchId ? 'own_inventory' : 'other_branch'));

        const category = line.kind === 'service' ? 'Service' : (line.category || activeItemCategory || 'Part');
        itemsData.insertAdjacentHTML('beforeend', `
            <input type="hidden" name="items[${index}][name]" value="${escapeHtml(line.name)}">
            <input type="hidden" name="items[${index}][quantity]" value="${line.quantity}">
            <input type="hidden" name="items[${index}][unit_price]" value="${line.unit_price}">
            <input type="hidden" name="items[${index}][type]" value="${line.item_type}">
            <input type="hidden" name="items[${index}][category]" value="${escapeHtml(category)}">
            <input type="hidden" name="items[${index}][source]" value="${source}">
            <input type="hidden" name="items[${index}][inventory_item_id]" value="${line.inventory_item_id || ''}">
            <input type="hidden" name="items[${index}][inventory_branch_id]" value="${line.inventory_branch_id || ''}">
        `);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    populateDatalists();

    cacheCustomerOptions();

    const customerSearch = document.getElementById('customerAdvancedSearch');
    const customerSelect = document.getElementById('customer_id');

    if (customerSearch) {
        customerSearch.addEventListener('input', filterCustomerDropdown);
        customerSearch.addEventListener('focus', filterCustomerDropdown);
        customerSearch.addEventListener('search', filterCustomerDropdown);
        customerSearch.addEventListener('blur', () => {
            window.setTimeout(closeCustomerSearchResults, 140);
        });
        customerSearch.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                selectFirstFilteredCustomer();
            } else if (event.key === 'Escape') {
                closeCustomerSearchResults();
            }
        });
    }

    customerSelect.addEventListener('change', () => {
        syncCustomerSearchFromSelection();
        updateVehicleList();
    });
    document.getElementById('vehicle_id').addEventListener('change', updateInfoPanels);
    document.getElementById('labor_cost').addEventListener('input', () => {
        laborCostTouched = true;
        calculateTotals();
    });
    document.getElementById('addServiceBtn').addEventListener('click', addTypedService);
    document.getElementById('addItemBtn').addEventListener('click', addTypedItem);
    document.getElementById('servicePickerSearch').addEventListener('input', renderServicePickerList);
    document.getElementById('servicePickerSearch').addEventListener('search', renderServicePickerList);
    document.getElementById('itemPickerSearch').addEventListener('input', renderItemPickerList);
    document.getElementById('itemPickerSearch').addEventListener('search', renderItemPickerList);
    document.getElementById('requiredServicesSearch')?.addEventListener('input', renderRequiredServicesList);
    document.getElementById('requiredServicesSearch')?.addEventListener('search', renderRequiredServicesList);
    document.getElementById('otherServicesSearch')?.addEventListener('input', renderRequiredServicesList);
    document.getElementById('otherServicesSearch')?.addEventListener('search', renderRequiredServicesList);
    document.getElementById('recommendedItemsSearch')?.addEventListener('input', renderStep1RecommendedItemsList);
    document.getElementById('recommendedItemsSearch')?.addEventListener('search', renderStep1RecommendedItemsList);
    document.getElementById('allBranchStockSearch')?.addEventListener('input', renderStep1RecommendedItemsList);
    document.getElementById('allBranchStockSearch')?.addEventListener('search', renderStep1RecommendedItemsList);

    const handleRequiredServiceChange = (event) => {
        if (!event.target.classList.contains('required-service-checkbox')) return;
        const serviceName = event.target.value;
        const pickerKey = `service:${normalizePickerText(serviceName)}`;

        if (event.target.checked) {
            optedOutKeys.delete(pickerKey);
            addServiceFromCatalog(serviceName, { is_required: true, is_recommended: false });
        } else {
            quoteLines = quoteLines.filter((l) => l.picker_key !== pickerKey);
            renderLines();
        }
        renderStep1Pickers();
    };

    document.getElementById('requiredServicesList')?.addEventListener('change', handleRequiredServiceChange);
    document.getElementById('otherServicesList')?.addEventListener('change', handleRequiredServiceChange);

    const handleRecommendedItemChange = (event) => {
        if (!event.target.classList.contains('step1-recommended-item-checkbox')) return;
        const itemId = event.target.value;
        const pickerKey = `inventory:${itemId}`;

        if (event.target.checked) {
            optedOutKeys.delete(pickerKey);
            addItemFromInventory(itemId, { is_required: false, is_recommended: true });
        } else {
            quoteLines = quoteLines.filter((l) => l.picker_key !== pickerKey);
            renderLines();
        }
        renderStep1Pickers();
    };

    document.getElementById('recommendedItemsList')?.addEventListener('change', handleRecommendedItemChange);
    document.getElementById('allBranchStockList')?.addEventListener('change', handleRecommendedItemChange);

    ['inspection_complaint', 'inspection_findings', 'inspection_recommendations'].forEach((fieldId) => {
        const field = document.getElementById(fieldId);
        if (field) {
            field.addEventListener('input', renderPickerLists);
        }
    });
    document.querySelectorAll('.inspection-preset-group').forEach((group) => {
        const field = document.getElementById(group.dataset.presetTarget || '');
        if (!field) return;

        group.addEventListener('change', (event) => {
            if (!event.target.matches('input[type="checkbox"]')) return;

            const presetValues = Array.from(group.querySelectorAll('input[type="checkbox"]'))
                .map((checkbox) => checkbox.value.trim())
                .filter(Boolean);
            const selected = Array.from(group.querySelectorAll('input[type="checkbox"]:checked'))
                .map((checkbox) => checkbox.value.trim())
                .filter(Boolean);
            const customText = field.value
                .split('\n')
                .filter((line) => !presetValues.includes(line.trim()))
                .join('\n')
                .trim();
            field.value = [customText, ...selected].filter(Boolean).join('\n');

            if (group.dataset.presetTarget === 'inspection_recommendations') {
                const matchedService = resolveRecommendedService(event.target.value);
                if (matchedService) {
                    const pickerKey = `service:${normalizePickerText(matchedService.name)}`;
                    if (event.target.checked) {
                        optedOutKeys.delete(pickerKey);
                        const existing = quoteLines.find((l) => l.picker_key === pickerKey);
                        if (!existing) {
                            addServiceFromCatalog(matchedService.name, { is_required: false, is_recommended: true });
                        }
                    } else {
                        const existing = quoteLines.find((l) => l.picker_key === pickerKey);
                        if (existing && existing.is_recommended && !existing.is_required) {
                            quoteLines = quoteLines.filter((l) => l.picker_key !== pickerKey);
                            renderLines();
                            renderStep1Pickers();
                        }
                    }
                }
            }

            renderPickerLists();
        });
    });
    document.getElementById('servicePickerSearch').addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            addTypedService();
        }
    });
    document.getElementById('itemPickerSearch').addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            addTypedItem();
        }
    });
    document.getElementById('servicePickerList').addEventListener('change', (event) => {
        if (!event.target.classList.contains('service-picker-check')) return;

        const serviceName = event.target.value;
        const pickerKey = `service:${normalizePickerText(serviceName)}`;
        const existing = quoteLines.find((l) => l.picker_key === pickerKey);
        if (!event.target.checked && existing && existing.is_required) {
            event.target.checked = true;
            alert('This service is marked as Required in Step 1. To remove it, uncheck it in the Step 1 Required Services section.');
            return;
        }

        if (event.target.checked) {
            optedOutKeys.delete(pickerKey);
            const isRec = Boolean(resolveRecommendedService(serviceName));
            addServiceFromCatalog(serviceName, { is_recommended: isRec });
        } else {
            removeLineByPickerKey(pickerKey);
        }
    });
    document.getElementById('itemPickerList').addEventListener('change', (event) => {
        if (!event.target.classList.contains('item-picker-check')) return;

        const itemId = event.target.value;
        const pickerKey = `inventory:${itemId}`;
        if (event.target.checked) {
            optedOutKeys.delete(pickerKey);
            addItemFromInventory(itemId);
        } else {
            removeLineByPickerKey(pickerKey);
        }
    });
    document.getElementById('continueToQuotationBtn').addEventListener('click', continueToQuotation);

    document.querySelector('.service-step[data-step="1"]').addEventListener('click', () => {
        setServiceOperationStep(1);
        renderStep1Pickers();
    });
    document.querySelector('.service-step[data-step="2"]').addEventListener('click', () => {
        if (validateServiceInspection(true)) {
            syncStep1ToQuoteLines();
            setServiceOperationStep(2);
        }
    });

    document.querySelectorAll('.quote-tabs button').forEach((button) => {
        button.addEventListener('click', () => {
            document.querySelectorAll('.quote-tabs button').forEach((item) => item.classList.remove('active'));
            button.classList.add('active');
            activeItemCategory = button.dataset.category;
            updateItemDatalist();
            renderItemPickerList();
        });
    });

    document.querySelectorAll('.quote-catalog-collapse').forEach((el) => {
        el.addEventListener('toggle', () => {
            const toggleText = el.querySelector('.toggle-text');
            if (toggleText) {
                toggleText.textContent = el.open ? 'Hide Catalog' : 'Show Catalog';
            }
        });
    });

    renderLines();
    renderStep1Pickers();

    if (customerSelect.value) {
        syncCustomerSearchFromSelection();
        updateVehicleList();
    } else {
        updateInfoPanels();
    }

    setServiceOperationStep(1);
});

document.getElementById('quotationForm').addEventListener('submit', (event) => {
    const validLines = quoteLines.filter((line) => String(line.name || '').trim() !== '');
    if (quotationStep < 2 || !validateServiceInspection(true)) {
        event.preventDefault();
        return;
    }
    if (!document.getElementById('customer_id').value) {
        event.preventDefault();
        alert('Please select a customer');
        return;
    }
    if (validLines.length === 0) {
        event.preventDefault();
        alert('Please add at least one service or item');
        return;
    }

    if (!validateInventoryQuantities(true)) {
        event.preventDefault();
        return;
    }

    quoteLines = validLines;
    serializeLines();
});
</script>

<?php require_once '../../includes/footer.php'; ?>
