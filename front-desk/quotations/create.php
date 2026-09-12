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
                        <input type="search" id="customerAdvancedSearch" maxlength="100" data-text-format="first-letter" placeholder="Search customer name or phone...">
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
            <div class="quote-picker">
                <label class="quote-picker-search">
                    <span>Search or type service</span>
                    <div class="quote-picker-input-row">
                        <i class="fas fa-search"></i>
                        <input type="search" id="servicePickerSearch" maxlength="100" data-text-format="first-letter" placeholder="Search services, or type a custom service...">
                    </div>
                </label>
                <div class="quote-picker-list" id="servicePickerList" aria-label="Available services"></div>
            </div>
            <div class="quote-line-list" id="serviceRows"></div>
        </section>

        <section class="quote-card quote-step-two is-locked">
            <div class="quote-section-head">
                <h2>Parts &amp; Items</h2>
                <button type="button" class="quote-add-item" id="addItemBtn">
                    <i class="fas fa-plus"></i>
                    <span>Add Typed Item</span>
                </button>
            </div>
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
                        <input type="search" id="itemPickerSearch" maxlength="100" data-text-format="first-letter" placeholder="Search inventory, or type a custom item...">
                    </div>
                </label>
                <div class="quote-picker-list quote-picker-list-large" id="itemPickerList" aria-label="Available parts and items"></div>
            </div>
            <div class="quote-line-list" id="itemRows"></div>
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

function renderPickerLists() {
    renderServicePickerList();
    renderItemPickerList();
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
        { keys: ['mount', 'mounting', 'rotate', 'rotation', 'tire change', 'tire replacement'], services: ['tire mounting', 'tire rotation', 'tire change'] },
        { keys: ['under chassis', 'underchassis', 'suspension', 'kalampag', 'bushing', 'shock', 'ball joint', 'tie rod'], services: ['under chassis', 'suspension repair', 'suspension parts installation'] },
        { keys: ['oil', 'tune', 'tune-up', 'maintenance', 'pms'], services: ['oil change', 'engine tune-up', 'preventive maintenance'] },
        { keys: ['nitrogen', 'tire pressure', 'hangin', 'pressure'], services: ['nitrogen air tire inflation'] },
        { keys: ['battery', 'charging', 'start', 'starting'], services: ['battery check-up', 'fast charging'] },
        { keys: ['atf', 'automatic transmission', 'transmission', 'flush'], services: ['automatic transmission flushing', 'atf changer'] },
        { keys: ['brake', 'brakes', 'pad', 'weak braking', 'brake noise'], services: ['brake cleaning', 'brake repair', 'adjustment'] },
        { keys: ['clutch', 'manual clutch'], services: ['manual clutch repair'] },
        { keys: ['sanitizing', 'sanitize', 'odor', 'backtozero'], services: ['car sanitizing', 'backtozero'] },
        { keys: ['diagnostic', 'scan', 'scanner', 'check engine'], services: ['auto diagnostic'] },
        { keys: ['vulcanizing', 'patch', 'flat', 'leak', 'puncture'], services: ['cold patch vulcanizing', 'tire repair'] },
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
            score += 4;
        }
    });

    getItemRecommendationTerms().forEach((term) => {
        if (serviceText.includes(term)) {
            score += 1;
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

function getItemRecommendationTerms() {
    const textSources = [
        document.getElementById('inspection_complaint')?.value || '',
        document.getElementById('inspection_findings')?.value || '',
        document.getElementById('inspection_recommendations')?.value || '',
        ...quoteLines.filter((line) => line.kind === 'service').map((line) => line.name || '')
    ];
    const text = normalizePickerText(textSources.join(' '));
    const terms = new Set();
    const rules = [
        { keys: ['oil', 'change oil', 'lubrication'], terms: ['oil', 'filter'] },
        { keys: ['filter', 'air filter', 'fuel filter', 'oil filter'], terms: ['filter'] },
        { keys: ['brake', 'brakes'], terms: ['brake', 'pad'] },
        { keys: ['battery', 'electrical', 'starting'], terms: ['battery'] },
        { keys: ['spark', 'tune', 'tune-up', 'misfire'], terms: ['spark', 'plug'] },
        { keys: ['tire', 'wheel', 'balancing', 'alignment', 'rotation'], terms: ['tire', 'wheel', 'valve'] },
        { keys: ['coolant', 'radiator', 'overheat'], terms: ['coolant', 'fluid'] },
        { keys: ['transmission', 'fluid'], terms: ['transmission', 'fluid'] },
        { keys: ['wiper', 'windshield'], terms: ['wiper'] },
        { keys: ['aircon', 'air conditioning', 'ac ', 'a/c'], terms: ['aircon', 'refrigerant', 'cabin', 'filter'] }
    ];

    rules.forEach((rule) => {
        if (rule.keys.some((key) => text.includes(key))) {
            rule.terms.forEach((term) => terms.add(term));
        }
    });

    const stopWords = new Set([
        'with', 'from', 'that', 'this', 'need', 'needs', 'service', 'parts', 'part',
        'item', 'items', 'replace', 'replacement', 'check', 'customer', 'vehicle',
        'recommended', 'recommend', 'inspection', 'inspect', 'issue', 'issues'
    ]);

    text.split(/[^a-z0-9]+/)
        .filter((word) => word.length >= 4 && !stopWords.has(word))
        .slice(0, 10)
        .forEach((word) => terms.add(word));

    return Array.from(terms);
}

function itemRecommendationScore(item, terms) {
    if (!terms.length) return 0;

    const searchable = normalizePickerText([
        item.name,
        item.brand,
        item.size,
        item.branch_name,
        item.group,
        item.category
    ].filter(Boolean).join(' '));

    return terms.reduce((score, term) => score + (searchable.includes(term) ? 1 : 0), 0);
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

function continueToQuotation() {
    if (!validateServiceInspection(true)) {
        return;
    }

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

    if (name && quoteLines.some((line) => line.picker_key === pickerKey)) {
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
        inventory_quantity: Number(overrides.inventory_quantity || 0)
    });
    renderLines();
}

function addServiceFromCatalog(serviceName) {
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
        picker_key: `service:${normalizePickerText(name)}`
    });
}

function addItemFromInventory(itemId) {
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
        inventory_quantity: Number(item.quantity || 0)
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
    quoteLines = quoteLines.filter((line) => line.id !== lineId);
    renderLines();
}

function removeLineByPickerKey(pickerKey) {
    quoteLines = quoteLines.filter((line) => line.picker_key !== pickerKey);
    renderLines();
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

        if (line.kind === 'service') {
            hasServices = true;
            serviceRows.insertAdjacentHTML('beforeend', `
                <article class="quote-line-row service-row">
                    <label class="quote-line-name">
                        <span>Selected Service</span>
                        <input value="${escapeHtml(line.name)}" maxlength="150" data-text-format="first-letter" placeholder="Service name..." onchange="updateLine('${line.id}', 'name', this.value)">
                    </label>
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
                    <label class="quote-line-name">
                        <span>Selected Item</span>
                        <input value="${escapeHtml(line.name)}" maxlength="255" data-text-format="first-letter" placeholder="Item name..." onchange="updateLine('${line.id}', 'name', this.value)">
                        ${sourceSelect(line)}
                    </label>
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
    const details = [];
    if (line.kind === 'service' && line.estimated_duration) {
        details.push(`Estimated duration: ${line.estimated_duration}`);
    }
    if (line.kind === 'service' && line.description) {
        details.push(line.description);
    }
    if (line.kind === 'service' && Number(line.is_variable_price || 0) === 1) {
        details.push('Price can be adjusted if the work scope changes.');
    }
    if (line.kind !== 'service' && Number(line.inventory_quantity || 0) > 0) {
        details.push(`Available: ${Number(line.inventory_quantity || 0)}`);
    }

    return details.length
        ? `<p class="quote-line-hint">${escapeHtml(details.join(' | '))}</p>`
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
    document.getElementById('itemPickerSearch').addEventListener('input', renderItemPickerList);
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
        if (event.target.checked) {
            addServiceFromCatalog(serviceName);
        } else {
            removeLineByPickerKey(pickerKey);
        }
    });
    document.getElementById('itemPickerList').addEventListener('change', (event) => {
        if (!event.target.classList.contains('item-picker-check')) return;

        const itemId = event.target.value;
        const pickerKey = `inventory:${itemId}`;
        if (event.target.checked) {
            addItemFromInventory(itemId);
        } else {
            removeLineByPickerKey(pickerKey);
        }
    });
    document.getElementById('continueToQuotationBtn').addEventListener('click', continueToQuotation);

    document.querySelector('.service-step[data-step="1"]').addEventListener('click', () => setServiceOperationStep(1));
    document.querySelector('.service-step[data-step="2"]').addEventListener('click', () => {
        if (validateServiceInspection(true)) {
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

    renderLines();

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
