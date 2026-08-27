<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');
mt_srand(20260520);

$output_dir = __DIR__;
$years = [2023, 2024, 2025, 2026];
$end_date = '2026-08-16';

$branches = [
    1 => 'Lacson Branch',
    2 => 'Magsaysay Branch',
    3 => 'Bata Branch',
];

$suppliers = [
    'tire' => [
        ['Negros Tire Supply', '034-445-8101'],
        ['Visayas Wheel Traders', '034-441-2290'],
        ['Bacolod Tire Depot', '034-433-9012'],
        ['Northline Rubber Supply', '034-702-1888'],
    ],
    'accessory' => [
        ['Bacolod Auto Accessories', '034-476-1180'],
        ['Cityline Car Care Supply', '034-435-7720'],
        ['Magsaysay Auto Finds', '034-441-7001'],
        ['DriveStyle Auto Hub', '034-707-2424'],
    ],
    'part' => [
        ['Negros Parts Center', '034-433-1288'],
        ['Bacolod Motor Parts', '034-435-8080'],
        ['South Auto Components', '034-704-3377'],
        ['Prime Filters and Fluids', '034-444-2199'],
    ],
];

$items = [];
$next_item_id = 30001;

function sql_value($value): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return "'" . str_replace("'", "''", (string) $value) . "'";
}

function sql_tuple(array $row): string
{
    return '(' . implode(', ', array_map('sql_value', $row)) . ')';
}

function has_control_chars(?string $value): bool
{
    return $value !== null && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1;
}

function clean_required_string($value): bool
{
    return is_string($value) && trim($value) !== '' && !has_control_chars($value);
}

function valid_date_string(string $date, string $max_date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    [$year, $month, $day] = array_map('intval', explode('-', $date));
    return checkdate($month, $day, $year) && $date <= $max_date;
}

function valid_datetime_string(string $date_time, string $max_date_time): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date_time)) {
        return false;
    }

    $date = substr($date_time, 0, 10);
    $time = substr($date_time, 11);
    [$hour, $minute, $second] = array_map('intval', explode(':', $time));

    return valid_date_string($date, substr($max_date_time, 0, 10))
        && $hour >= 0 && $hour <= 23
        && $minute >= 0 && $minute <= 59
        && $second >= 0 && $second <= 59
        && $date_time <= $max_date_time;
}

function write_insert($fh, string $table, array $columns, array $rows, int $chunk_size = 250): void
{
    if (empty($rows)) {
        return;
    }

    foreach (array_chunk($rows, $chunk_size) as $chunk) {
        fwrite($fh, "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES\n");
        fwrite($fh, implode(",\n", array_map('sql_tuple', $chunk)));
        fwrite($fh, ";\n\n");
    }
}

function month_range(int $year): array
{
    $months = [];
    $last_month = $year === 2026 ? 8 : 12;

    for ($month = 1; $month <= $last_month; $month++) {
        $months[] = [$year, $month];
    }

    return $months;
}

function month_end_date(int $year, int $month): string
{
    $last_day = (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
    if ($year === 2026 && $month === 8) {
        $last_day = 16;
    }

    return sprintf('%04d-%02d-%02d', $year, $month, $last_day);
}

function choose_supplier(array $suppliers, string $category): array
{
    $list = $suppliers[$category] ?? $suppliers['part'];
    return $list[array_rand($list)];
}

function product_model_code(string $brand, string $name, int $id): string
{
    $seed = strtoupper(preg_replace('/[^A-Z0-9]+/i', '', $brand . ' ' . $name));
    $prefix = substr($seed !== '' ? $seed : 'HWT', 0, 4);

    return sprintf('%s-%03d', $prefix, ($id * 17) % 997);
}

function product_serial_number(int $id, int $branch_id, string $category): string
{
    $prefix = strtoupper(substr($category, 0, 3));

    return sprintf('HW-%s-B%d-%05d-%02d', $prefix, $branch_id, $id, (($id * 7) % 89) + 10);
}

function manufacturing_date_as_of_year(array $item, int $year): string
{
    $month_limit = $year === 2026 ? 8 : 12;
    $month = (($item['id'] * 5) % $month_limit) + 1;
    $day_max = $year === 2026 && $month === 8 ? 16 : 24;
    $day = (($item['id'] * 7) % $day_max) + 1;

    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function accessory_size_variant(string $name, int $index): string
{
    $name = strtolower($name);

    if (str_contains($name, 'wiper')) return ['16 in / 14 in', '18 in / 16 in', '22 in / 20 in'][$index % 3];
    if (str_contains($name, 'floor mat')) return ['Universal 4-pc', 'Universal 5-pc', 'SUV 5-pc'][$index % 3];
    if (str_contains($name, 'seat cover')) return ['Sedan 5-seat', 'SUV 7-seat', 'Van 10-seat'][$index % 3];
    if (str_contains($name, 'sunshade')) return ['Small', 'Medium', 'Large'][$index % 3];
    if (str_contains($name, 'dash camera')) return ['1080p front', '2K front', 'Front + rear'][$index % 3];
    if (str_contains($name, 'car cover')) return ['Medium', 'Large', 'XL'][$index % 3];
    if (str_contains($name, 'fog light')) return ['H11 kit', 'H8 kit', 'Universal kit'][$index % 3];
    if (str_contains($name, 'roof rack')) return ['110 cm', '120 cm', '135 cm'][$index % 3];
    if (str_contains($name, 'charger')) return ['24W dual USB', '36W USB-C', '65W USB-C'][$index % 3];
    if (str_contains($name, 'inflator')) return ['12V compact', '12V digital', 'Cordless digital'][$index % 3];
    if (str_contains($name, 'jump starter')) return ['800A', '1000A', '1500A'][$index % 3];
    if (str_contains($name, 'vacuum')) return ['12V', 'Cordless', 'Wet/Dry'][$index % 3];

    return ['Universal', 'Compact', 'Premium'][$index % 3];
}

function part_size_variant(string $name, int $index): string
{
    $name = strtolower($name);

    if (str_contains($name, 'oil filter')) return ['Toyota/Honda compact', 'SUV diesel', 'Universal spin-on'][$index % 3];
    if (str_contains($name, 'air filter')) return ['Compact sedan', 'SUV', 'Pickup'][$index % 3];
    if (str_contains($name, 'cabin')) return ['PM2.5 carbon', 'Standard cabin', 'Premium cabin'][$index % 3];
    if (str_contains($name, 'battery 2sm')) return '2SM';
    if (str_contains($name, 'battery 3sm')) return '3SM';
    if (str_contains($name, 'brake pad')) return ['Front compact', 'Front SUV', 'Rear compact'][$index % 3];
    if (str_contains($name, 'spark plug')) return ['BKR6E set', 'Iridium set', 'Nickel set'][$index % 3];
    if (str_contains($name, 'shock absorber')) return str_contains($name, 'front') ? 'Front pair' : 'Rear pair';
    if (str_contains($name, 'drive belt')) return ['4PK875', '5PK970', '6PK1050'][$index % 3];
    if (str_contains($name, 'atf')) return '1 liter';
    if (str_contains($name, 'brake fluid')) return 'DOT4 500 ml';
    if (str_contains($name, 'coolant')) return '1 gallon';
    if (str_contains($name, 'headlight')) return 'H4 12V';
    if (str_contains($name, 'valve stem')) return 'TR413';

    return ['Standard fit', 'Compact fit', 'SUV fit'][$index % 3];
}

function add_inventory_item(
    array &$items,
    int &$next_item_id,
    array $suppliers,
    int $branch_id,
    string $category,
    string $item_name,
    string $brand,
    string $size,
    string $description,
    float $unit_price,
    int $reorder_level,
    int $opening_stock,
    float $monthly_demand,
    string $model = ''
): int {
    [$supplier_name, $supplier_contact] = choose_supplier($suppliers, $category);
    $id = $next_item_id++;
    $prefix = strtoupper(substr($category, 0, 3));
    $sku = sprintf('B%d-%s-%05d', $branch_id, $prefix, $id);
    $model = trim($model) !== '' ? trim($model) : product_model_code($brand, $item_name, $id);

    $items[$id] = [
        'id' => $id,
        'branch_id' => $branch_id,
        'category' => $category,
        'item_name' => $item_name,
        'brand' => $brand,
        'model' => $model,
        'size' => $size,
        'description' => $description,
        'sku' => $sku,
        'serial_number' => product_serial_number($id, $branch_id, $category),
        'quantity' => $opening_stock,
        'reorder_level' => $reorder_level,
        'unit_price' => $unit_price,
        'supplier_name' => $supplier_name,
        'supplier_contact' => $supplier_contact,
        'status' => 'active',
        'last_restock_date' => '2023-01-01',
        'created_at' => '2023-01-01 08:00:00',
        'updated_at' => '2023-01-01 08:00:00',
        'opening_stock' => $opening_stock,
        'monthly_demand' => $monthly_demand,
        'target_status' => 'good',
    ];

    return $id;
}

$tire_sizes = [
    '155R12' => 2600, '165R13' => 2800, '175/70R13' => 3000, '185/70R13' => 3150,
    '185/65R14' => 3200, '195R14' => 3500, '175/65R14' => 3100, '185/65R15' => 3600,
    '195/55R15' => 3850, '195/60R15' => 3900, '205/55R16' => 4300, '205/60R16' => 4500,
    '215/65R16' => 5200, '225/70R15' => 5600, '225/65R17' => 6200, '265/65R17' => 8500,
    '265/70R16' => 7800, '31X10.5R15' => 8200, '7.00R16' => 6900, '7.50R16' => 7600,
    '8.25R16' => 8500, '9.00R20' => 11200, '10.00R20' => 13800, '11R22.5' => 16800,
];
$tire_brands = ['GOODRIDE', 'DEESTONE', 'KINTO', 'APOLLO', 'AEOLUS', 'BLACKLION', 'GOODYEAR', 'BRIDGESTONE', 'YOKOHAMA', 'MICHELIN', 'HI FLY', 'TRIANGLE'];
$tire_models = ['G118', 'NAKARA R201', 'VINCENTE R203', 'AMAR DLX RIB', 'LUG', 'FM RIB', 'RP28', 'SM1', 'CL928', 'ECOPIA EP150', 'PRIMACY 4', 'S2000'];
$ply_tags = ['PI', 'HVG', '16 PLY', '18 PLY', '20 PLY', '8 PLY'];

function build_tire_items(array &$items, int &$next_item_id, array $suppliers, int $branch_id, int $count, float $demand_bias): void
{
    global $tire_sizes, $tire_brands, $tire_models, $ply_tags;

    $sizes = array_keys($tire_sizes);
    for ($i = 0; $i < $count; $i++) {
        $size = $sizes[($i + ($branch_id * 3)) % count($sizes)];
        $brand = $tire_brands[($i * 2 + $branch_id) % count($tire_brands)];
        $model = $tire_models[($i * 3 + $branch_id) % count($tire_models)];
        $ply = $ply_tags[($i + $branch_id) % count($ply_tags)];
        $base_price = $tire_sizes[$size];
        $unit_price = (float) (round(($base_price + (($i % 5) * 120)) / 50) * 50);
        $name = "{$brand} {$model} {$size} Tire";
        $description = "Tubeless radial tire. Load/Ply detail: {$ply}. Catalog display includes size, model, serial/SKU, and latest manufacturing date.";
        $reorder = mt_rand(8, 14) + ($branch_id === 3 ? 2 : 0);
        $opening = mt_rand(26, 68);
        $monthly = max(2.2, ($unit_price < 4000 ? mt_rand(55, 110) / 10 : mt_rand(25, 75) / 10) * $demand_bias);

        add_inventory_item(
            $items,
            $next_item_id,
            $suppliers,
            $branch_id,
            'tire',
            $name,
            $brand,
            $size,
            $description,
            $unit_price,
            $reorder,
            $opening,
            $monthly,
            $model
        );
    }
}

build_tire_items($items, $next_item_id, $suppliers, 1, 50, 1.00);
build_tire_items($items, $next_item_id, $suppliers, 2, 44, 0.92);
build_tire_items($items, $next_item_id, $suppliers, 3, 58, 1.08);

$accessories = [
    ['Wiper Blades Pair', 'Bosch', 'All-weather windshield wiper blades', 780],
    ['Car Floor Mat Set', 'AutoPlus', 'Universal fit rubber floor mats', 850],
    ['Steering Wheel Cover', 'ComfortGrip', 'Leather steering wheel cover', 450],
    ['Seat Cover Set', 'DriveStyle', 'Washable fabric seat covers', 1800],
    ['Dashboard Sunshade', 'HeatGuard', 'Foldable reflective sunshade', 350],
    ['Phone Mount', 'RoadMate', 'Dashboard phone holder', 320],
    ['Dash Camera', 'SafeCam', 'Front HD dash camera', 2500],
    ['Car Cover', 'WeatherShield', 'Weatherproof car cover - medium', 1200],
    ['Trunk Organizer', 'CargoMate', 'Foldable trunk storage box', 950],
    ['Air Freshener Pack', 'FreshRide', 'Hanging car air freshener pack', 180],
    ['LED Fog Light Kit', 'BrightWay', 'Auxiliary LED fog light kit', 2200],
    ['Roof Rack Cross Bars', 'CarryPro', 'Universal roof rack cross bars', 3800],
    ['Emergency Roadside Kit', 'SafeDrive', 'Basic roadside emergency kit', 1600],
    ['Dual USB Car Charger', 'VoltGo', 'Fast charging USB adapter', 420],
    ['Ceramic Wax Bottle', 'ShinePro', 'Ceramic liquid wax polish', 680],
    ['Tire Pressure Gauge', 'AirCheck', 'Analog tire pressure gauge', 300],
    ['License Plate Frame', 'ChromeLine', 'Chrome plate frame pair', 300],
    ['Door Edge Guard Set', 'GuardPro', 'Door edge protective trim', 450],
    ['Blind Spot Mirror Pair', 'ClearView', 'Stick-on blind spot mirrors', 260],
    ['Parking Sensor Kit', 'ParkSafe', 'Rear parking sensor kit', 2800],
    ['Reverse Camera Kit', 'SafeCam', 'Rear camera and monitor set', 3200],
    ['Window Visor Set', 'RainGuard', 'Vehicle window visor set', 1900],
    ['Muffler Tip', 'SportLine', 'Stainless decorative muffler tip', 650],
    ['Car Shampoo Gallon', 'WashPro', 'Concentrated car shampoo', 520],
    ['Microfiber Towel Set', 'DetailMax', 'Microfiber towel pack', 380],
    ['Wheel Cleaner Spray', 'ShinePro', 'Wheel and rim cleaner', 450],
    ['Interior Cleaner Spray', 'DetailMax', 'Interior plastic cleaner', 430],
    ['Tire Black Gel', 'ShinePro', 'Tire dressing gel', 390],
    ['Seat Organizer', 'CargoMate', 'Back-seat storage organizer', 580],
    ['Neck Pillow Pair', 'ComfortGrip', 'Memory foam neck pillow pair', 720],
    ['Rain Repellent Bottle', 'ClearView', 'Glass rain repellent', 360],
    ['Scratch Remover Tube', 'DetailMax', 'Paint scratch remover compound', 440],
    ['Portable Tire Inflator', 'AirCheck', '12V portable tire inflator', 1850],
    ['Battery Jump Starter', 'VoltGo', 'Compact battery jump starter', 4200],
    ['Car Vacuum Cleaner', 'CleanRide', '12V portable car vacuum', 1450],
    ['Mud Guard Set', 'GuardPro', 'Universal mud guard set', 1400],
    ['Bumper Guard Strip', 'GuardPro', 'Rubber bumper protection strip', 600],
    ['Alloy Wheel Cap Set', 'ChromeLine', 'Decorative wheel cap set', 1100],
    ['Steering Wheel Lock', 'SafeDrive', 'Anti-theft steering wheel lock', 1600],
    ['Tablet Headrest Mount', 'RoadMate', 'Rear-seat tablet mount', 760],
    ['Car Trash Bin', 'CargoMate', 'Compact interior trash bin', 280],
    ['Leather Care Kit', 'DetailMax', 'Leather cleaner and conditioner kit', 900],
];

function build_accessories(array &$items, int &$next_item_id, array $suppliers, int $branch_id, array $catalog, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        [$name, $brand, $description, $price] = $catalog[$i % count($catalog)];
        $branch_bonus = $branch_id === 2 ? 1.35 : ($branch_id === 1 ? 1.00 : 0.72);
        $monthly = max(1.2, mt_rand(18, 80) / 10 * $branch_bonus);
        $opening = $branch_id === 2 ? mt_rand(28, 92) : ($branch_id === 1 ? mt_rand(22, 68) : mt_rand(14, 46));
        $reorder = $branch_id === 2 ? mt_rand(6, 14) : ($branch_id === 1 ? mt_rand(5, 11) : mt_rand(4, 9));
        $size = accessory_size_variant($name, $i);
        $model = product_model_code($brand, $name, $next_item_id);
        $catalog_name = "{$brand} {$name}";

        add_inventory_item(
            $items,
            $next_item_id,
            $suppliers,
            $branch_id,
            'accessory',
            $catalog_name,
            $brand,
            $size,
            "{$description}. Variant: {$size}.",
            (float) $price,
            $reorder,
            $opening,
            $monthly,
            $model
        );
    }
}

build_accessories($items, $next_item_id, $suppliers, 1, $accessories, 28);
build_accessories($items, $next_item_id, $suppliers, 2, $accessories, count($accessories));
build_accessories($items, $next_item_id, $suppliers, 3, $accessories, 16);

$parts = [
    ['Engine Oil Filter', 'Mann Filter', 'High-performance oil filter', 280],
    ['Air Filter', 'K&N', 'Reusable air filter', 650],
    ['Cabin Air Filter', 'Denso', 'Cabin air filter element', 550],
    ['Fuel Filter', 'Vic', 'Inline fuel filter', 480],
    ['Brake Pads Set', 'Bendix', 'Front brake pad set', 2200],
    ['Spark Plug Set', 'NGK', 'Spark plugs set of four', 1200],
    ['Battery 2SM', 'Motolite', 'Automotive battery 2SM', 5800],
    ['Battery 3SM', 'Motolite', 'Automotive battery 3SM', 6800],
    ['Tie Rod End', '555', 'Steering tie rod end', 950],
    ['Ball Joint', '555', 'Suspension ball joint', 1250],
    ['Shock Absorber Front', 'KYB', 'Front shock absorber', 3200],
    ['Shock Absorber Rear', 'KYB', 'Rear shock absorber', 2800],
    ['CV Joint Boot', 'NKN', 'CV joint boot kit', 750],
    ['Drive Belt', 'Bando', 'Engine drive belt', 900],
    ['ATF Fluid Liter', 'Toyota', 'Automatic transmission fluid', 520],
    ['Brake Fluid DOT4', 'Prestone', 'DOT4 brake fluid', 380],
    ['Coolant Gallon', 'Prestone', 'Engine coolant gallon', 650],
    ['Grease Cartridge', 'Shell', 'Multipurpose grease cartridge', 300],
    ['Clutch Disc', 'Exedy', 'Manual clutch disc', 4200],
    ['Pressure Plate', 'Exedy', 'Manual clutch pressure plate', 5200],
    ['Release Bearing', 'NSK', 'Clutch release bearing', 1600],
    ['Wheel Bearing Kit', 'Koyo', 'Wheel bearing repair kit', 1800],
    ['Stabilizer Link', '555', 'Suspension stabilizer link', 850],
    ['Lower Arm Assembly', '555', 'Lower control arm assembly', 4800],
    ['Radiator Hose Upper', 'Gates', 'Upper radiator hose', 900],
    ['Radiator Hose Lower', 'Gates', 'Lower radiator hose', 950],
    ['Thermostat Valve', 'Tama', 'Engine thermostat valve', 1150],
    ['Wiper Motor Relay', 'Denso', 'Electrical relay for wiper motor', 420],
    ['Headlight Bulb H4', 'Osram', 'Halogen headlight bulb H4', 360],
    ['Tail Light Bulb', 'Stanley', 'Tail light bulb pair', 180],
    ['Rubber Valve Stem', 'TR413', 'Tubeless tire valve stem', 35],
    ['Wheel Weight Strip', '3M', 'Adhesive wheel balancing weights', 450],
    ['Tire Patch Pack', 'Rema', 'Cold patch tire repair pack', 320],
    ['EGR Cleaning Fluid', 'Liqui Moly', 'EGR cleaner solution', 780],
    ['AC Refrigerant Can', 'Dupont', 'Automotive AC refrigerant', 620],
    ['Nitrogen Valve Cap Set', 'AirCheck', 'Nitrogen valve cap set', 120],
];

function build_parts(array &$items, int &$next_item_id, array $suppliers, int $branch_id, array $catalog, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        [$name, $brand, $description, $price] = $catalog[$i % count($catalog)];
        $branch_bonus = $branch_id === 3 ? 1.18 : ($branch_id === 1 ? 1.00 : 0.82);
        $monthly = max(1.1, mt_rand(16, 70) / 10 * $branch_bonus);
        $opening = $branch_id === 3 ? mt_rand(24, 78) : ($branch_id === 1 ? mt_rand(20, 62) : mt_rand(14, 42));
        $reorder = $branch_id === 3 ? mt_rand(7, 15) : ($branch_id === 1 ? mt_rand(5, 12) : mt_rand(4, 10));
        $size = part_size_variant($name, $i);
        $model = product_model_code($brand, $name, $next_item_id);
        $catalog_name = "{$brand} {$name}";

        add_inventory_item(
            $items,
            $next_item_id,
            $suppliers,
            $branch_id,
            'part',
            $catalog_name,
            $brand,
            $size,
            "{$description}. Fitment/size: {$size}.",
            (float) $price,
            $reorder,
            $opening,
            $monthly,
            $model
        );
    }
}

build_parts($items, $next_item_id, $suppliers, 1, $parts, 24);
build_parts($items, $next_item_id, $suppliers, 2, $parts, 14);
build_parts($items, $next_item_id, $suppliers, 3, $parts, 32);

function pick_item_id(array $items, int $branch_id, string $category, string $contains = ''): ?int
{
    foreach ($items as $id => $item) {
        if ((int) $item['branch_id'] !== $branch_id || $item['category'] !== $category) {
            continue;
        }

        if ($contains === '' || stripos($item['item_name'], $contains) !== false || stripos($item['size'], $contains) !== false) {
            return (int) $id;
        }
    }

    return null;
}

$critical_ids = array_filter([
    pick_item_id($items, 1, 'tire', '185/65R15'),
    pick_item_id($items, 2, 'tire', '165R13'),
    pick_item_id($items, 3, 'tire', '175/70R13'),
    pick_item_id($items, 1, 'part', 'Brake Pads'),
    pick_item_id($items, 3, 'accessory', 'Wiper'),
    pick_item_id($items, 2, 'accessory', 'Dash Camera'),
    pick_item_id($items, 3, 'part', 'Engine Oil Filter'),
]);

$warning_ids = array_filter([
    pick_item_id($items, 1, 'tire', '205/55R16'),
    pick_item_id($items, 2, 'tire', '195R14'),
    pick_item_id($items, 3, 'tire', '205/55R16'),
    pick_item_id($items, 1, 'accessory', 'Portable Tire Inflator'),
    pick_item_id($items, 2, 'accessory', 'Car Floor Mat'),
    pick_item_id($items, 3, 'accessory', 'Emergency'),
    pick_item_id($items, 1, 'part', 'Battery 2SM'),
    pick_item_id($items, 2, 'part', 'Air Filter'),
    pick_item_id($items, 3, 'part', 'Brake Pads'),
    pick_item_id($items, 2, 'accessory', 'Phone Mount'),
    pick_item_id($items, 3, 'part', 'Spark Plug'),
]);

foreach ($critical_ids as $id) {
    $items[$id]['target_status'] = 'critical';
    $items[$id]['monthly_demand'] = max($items[$id]['monthly_demand'], mt_rand(20, 32));
}

foreach ($warning_ids as $id) {
    if (($items[$id]['target_status'] ?? 'good') !== 'critical') {
        $items[$id]['target_status'] = 'warning';
        $items[$id]['monthly_demand'] = max($items[$id]['monthly_demand'], mt_rand(12, 22));
    }
}

$transactions = [];
$balances = [];
$transaction_id = 1;
$reference_id = 800001;

function add_transaction(array &$transactions, int &$transaction_id, int $item_id, string $type, int $quantity, string $reference_type, ?int $reference_id, string $notes, string $created_at): void
{
    $transactions[] = [
        'id' => $transaction_id++,
        'item_id' => $item_id,
        'transaction_type' => $type,
        'quantity' => max(1, $quantity),
        'reference_type' => $reference_type,
        'reference_id' => $reference_id,
        'notes' => $notes,
        'created_by' => null,
        'created_at' => $created_at,
    ];
}

function validate_inventory_dataset(array $items, array $transactions, array $branches, array $years): void
{
    $errors = [];
    $allowed_categories = ['tire', 'accessory', 'part'];
    $allowed_transaction_types = ['stock_in', 'stock_out', 'adjustment', 'damage'];
    $item_ids = [];
    $skus = [];
    $serials = [];

    foreach ($items as $item) {
        $id = (int) ($item['id'] ?? 0);
        if ($id <= 0 || isset($item_ids[$id])) {
            $errors[] = "Invalid or duplicate inventory item id: {$id}";
            continue;
        }
        $item_ids[$id] = true;

        foreach (['item_name', 'category', 'brand', 'model', 'size', 'description', 'sku', 'serial_number', 'supplier_name', 'supplier_contact', 'status'] as $field) {
            if (!clean_required_string((string) ($item[$field] ?? ''))) {
                $errors[] = "Item {$id} has dirty or missing {$field}";
            }
        }

        if (!isset($branches[(int) ($item['branch_id'] ?? 0)])) {
            $errors[] = "Item {$id} uses an unknown branch";
        }

        if (!in_array((string) ($item['category'] ?? ''), $allowed_categories, true)) {
            $errors[] = "Item {$id} uses an invalid category";
        }

        $sku = strtoupper(trim((string) ($item['sku'] ?? '')));
        $serial = strtoupper(trim((string) ($item['serial_number'] ?? '')));
        if ($sku === '' || isset($skus[$sku])) {
            $errors[] = "Item {$id} has a missing or duplicate SKU";
        }
        if ($serial === '' || isset($serials[$serial])) {
            $errors[] = "Item {$id} has a missing or duplicate serial number";
        }
        $skus[$sku] = true;
        $serials[$serial] = true;

        if ((int) ($item['quantity'] ?? -1) < 0) {
            $errors[] = "Item {$id} has negative quantity";
        }
        if ((int) ($item['reorder_level'] ?? 0) <= 0) {
            $errors[] = "Item {$id} has invalid reorder level";
        }
        if ((float) ($item['unit_price'] ?? 0) <= 0) {
            $errors[] = "Item {$id} has invalid unit price";
        }

        if (!valid_date_string((string) ($item['last_restock_date'] ?? ''), '2026-08-16')) {
            $errors[] = "Item {$id} has an invalid last restock date";
        }

        if (!valid_datetime_string((string) ($item['created_at'] ?? ''), '2026-08-16 23:59:59')
            || !valid_datetime_string((string) ($item['updated_at'] ?? ''), '2026-08-16 23:59:59')
        ) {
            $errors[] = "Item {$id} has invalid created/updated dates";
        }

        foreach ($years as $year) {
            $max_date = $year === 2026 ? '2026-08-16' : month_end_date((int) $year, 12);
            if (!valid_date_string(manufacturing_date_as_of_year($item, (int) $year), $max_date)) {
                $errors[] = "Item {$id} has an invalid manufacturing date for {$year}";
            }
        }
    }

    $transaction_ids = [];
    $balances = array_fill_keys(array_keys($item_ids), 0);
    foreach ($transactions as $transaction) {
        $id = (int) ($transaction['id'] ?? 0);
        $item_id = (int) ($transaction['item_id'] ?? 0);
        $type = (string) ($transaction['transaction_type'] ?? '');
        $quantity = (int) ($transaction['quantity'] ?? 0);

        if ($id <= 0 || isset($transaction_ids[$id])) {
            $errors[] = "Invalid or duplicate inventory transaction id: {$id}";
        }
        $transaction_ids[$id] = true;

        if (!isset($item_ids[$item_id])) {
            $errors[] = "Transaction {$id} references unknown item {$item_id}";
            continue;
        }

        if (!in_array($type, $allowed_transaction_types, true)) {
            $errors[] = "Transaction {$id} uses an invalid type";
        }
        if ($quantity <= 0) {
            $errors[] = "Transaction {$id} has invalid quantity";
        }
        if (!clean_required_string((string) ($transaction['reference_type'] ?? ''))) {
            $errors[] = "Transaction {$id} has dirty reference type";
        }
        if (!clean_required_string((string) ($transaction['notes'] ?? ''))) {
            $errors[] = "Transaction {$id} has dirty notes";
        }
        if (!valid_datetime_string((string) ($transaction['created_at'] ?? ''), '2026-08-16 23:59:59')) {
            $errors[] = "Transaction {$id} has invalid created date";
        }

        if ($type === 'stock_in') {
            $balances[$item_id] += $quantity;
        } elseif (in_array($type, ['stock_out', 'damage'], true)) {
            $balances[$item_id] -= $quantity;
        } elseif ($type === 'adjustment' && ($transaction['reference_type'] ?? '') === 'inventory_recount_up') {
            $balances[$item_id] += $quantity;
        } elseif ($type === 'adjustment' && ($transaction['reference_type'] ?? '') === 'inventory_recount_down') {
            $balances[$item_id] -= $quantity;
        }
    }

    foreach ($items as $item) {
        $id = (int) $item['id'];
        if (($balances[$id] ?? null) !== (int) $item['quantity']) {
            $errors[] = "Item {$id} quantity does not reconcile with stock movement history (computed " . ($balances[$id] ?? 'missing') . ', expected ' . (int) $item['quantity'] . ')';
        }
    }

    if (!empty($errors)) {
        throw new RuntimeException("Inventory seed data failed validation:\n - " . implode("\n - ", array_slice($errors, 0, 40)));
    }
}

function inventory_balances_from_transactions(array $items, array $transactions): array
{
    $balances = array_fill_keys(array_map('intval', array_keys($items)), 0);

    foreach ($transactions as $transaction) {
        $item_id = (int) ($transaction['item_id'] ?? 0);
        if (!array_key_exists($item_id, $balances)) {
            continue;
        }

        $quantity = (int) ($transaction['quantity'] ?? 0);
        if (($transaction['transaction_type'] ?? '') === 'stock_in') {
            $balances[$item_id] += $quantity;
        } elseif (in_array(($transaction['transaction_type'] ?? ''), ['stock_out', 'damage'], true)) {
            $balances[$item_id] -= $quantity;
        } elseif (($transaction['transaction_type'] ?? '') === 'adjustment' && ($transaction['reference_type'] ?? '') === 'inventory_recount_up') {
            $balances[$item_id] += $quantity;
        } elseif (($transaction['transaction_type'] ?? '') === 'adjustment' && ($transaction['reference_type'] ?? '') === 'inventory_recount_down') {
            $balances[$item_id] -= $quantity;
        }
    }

    return $balances;
}

foreach ($items as $id => $item) {
    $balances[$id] = (int) $item['opening_stock'];
    add_transaction(
        $transactions,
        $transaction_id,
        (int) $id,
        'stock_in',
        (int) $item['opening_stock'],
        'opening_balance',
        null,
        'Opening stock balance for inventory seed.',
        '2023-01-01 08:00:00'
    );
}

$seasonality = [
    1 => 0.95, 2 => 0.92, 3 => 1.03, 4 => 1.05, 5 => 1.08, 6 => 1.12,
    7 => 0.98, 8 => 1.04, 9 => 1.00, 10 => 1.08, 11 => 1.15, 12 => 1.24,
];

foreach ($years as $year) {
    foreach (month_range($year) as [$loop_year, $month]) {
        $days_in_month = (int) date('t', strtotime(sprintf('%04d-%02d-01', $loop_year, $month)));
        $effective_days = ($loop_year === 2026 && $month === 8) ? 16 : $days_in_month;
        $month_factor = $seasonality[$month] * (1 + (($loop_year - 2023) * 0.045)) * ($effective_days / $days_in_month);

        foreach ($items as $id => $item) {
            $category = $item['category'];
            $branch_id = (int) $item['branch_id'];
            $target = $item['target_status'] ?? 'good';
            $base = (float) $item['monthly_demand'];
            $noise = mt_rand(-18, 22) / 100;
            $out_qty = (int) max(0, round($base * $month_factor * (1 + $noise)));

            if ($loop_year === 2026 && $month >= 7) {
                if ($target === 'critical') {
                    $out_qty = max($out_qty, $month === 8 ? mt_rand(16, 24) : mt_rand(17, 27));
                } elseif ($target === 'warning') {
                    $out_qty = max($out_qty, $month === 8 ? mt_rand(9, 16) : mt_rand(10, 18));
                } elseif ($month === 8) {
                    $out_qty = max(1, (int) round($out_qty * mt_rand(75, 115) / 100));
                }
            }

            if ($out_qty > 0) {
                $splits = min(mt_rand(1, 4), max(1, $out_qty));
                $remaining = $out_qty;
                for ($s = 1; $s <= $splits; $s++) {
                    $piece = $s === $splits ? $remaining : mt_rand(1, max(1, (int) floor($remaining / 2)));
                    $remaining -= $piece;
                    $day_max = $loop_year === 2026 && $month === 8 ? 16 : $days_in_month;
                    $day = min($day_max, max(1, (int) round(($s / ($splits + 1)) * $day_max) + mt_rand(-2, 2)));
                    $time = sprintf('%02d:%02d:00', mt_rand(9, 17), mt_rand(0, 59));
                    $date = sprintf('%04d-%02d-%02d %s', $loop_year, $month, $day, $time);
                    $reference_type = mt_rand(1, 100) <= ($category === 'accessory' ? 42 : 72) ? 'job_order' : 'counter_sale';
                    $note_category = $reference_type === 'job_order' ? 'Issued for service job order.' : 'Walk-in counter sale.';

                    add_transaction($transactions, $transaction_id, (int) $id, 'stock_out', $piece, $reference_type, $reference_id++, $note_category, $date);
                    $balances[$id] -= $piece;
                }
            }

            $cycle = $category === 'tire' ? 2 : ($category === 'accessory' ? ($branch_id === 2 ? 1 : 3) : ($branch_id === 3 ? 2 : 3));
            $should_restock = (($loop_year * 12 + $month + $id) % $cycle) === 0;
            $restock_floor = (int) $item['reorder_level'] + (int) ceil($base * 2.2);
            $restock_ceiling = (int) $item['reorder_level'] + (int) ceil($base * mt_rand(34, 54) / 10);

            if ($balances[$id] < $restock_floor || ($should_restock && $balances[$id] < $restock_ceiling)) {
                $target_balance = max($restock_floor + mt_rand(4, 12), $restock_ceiling);
                $stock_in_qty = (int) max(1, $target_balance - $balances[$id]);
                $day = min($effective_days, mt_rand(2, min(24, $days_in_month)));
                $date = sprintf('%04d-%02d-%02d %02d:%02d:00', $loop_year, $month, $day, mt_rand(8, 15), mt_rand(0, 59));
                add_transaction($transactions, $transaction_id, (int) $id, 'stock_in', $stock_in_qty, 'supplier_delivery', $reference_id++, 'Supplier delivery received and checked.', $date);
                $balances[$id] += $stock_in_qty;
                $items[$id]['last_restock_date'] = substr($date, 0, 10);
            }
        }
    }
}

function paired_item_id(array $items, int $source_id): ?int
{
    $source = $items[$source_id] ?? null;
    if (!$source) {
        return null;
    }

    foreach ($items as $id => $candidate) {
        if ((int) $candidate['branch_id'] === (int) $source['branch_id']) {
            continue;
        }

        if ($source['category'] === 'tire' && $candidate['category'] === 'tire' && $candidate['size'] === $source['size']) {
            return (int) $id;
        }

        if ($source['category'] !== 'tire' && $candidate['category'] === $source['category'] && $candidate['item_name'] === $source['item_name']) {
            return (int) $id;
        }
    }

    return null;
}

$transfer_candidates = array_values(array_filter(array_merge($critical_ids, $warning_ids)));
$transfer_months = [
    '2023-03-14', '2023-06-21', '2023-09-18', '2023-11-28',
    '2024-02-13', '2024-05-20', '2024-08-16', '2024-12-09',
    '2025-02-11', '2025-04-24', '2025-07-17', '2025-10-22',
    '2026-01-18', '2026-02-26', '2026-03-19', '2026-04-23',
    '2026-06-18', '2026-07-23', '2026-08-12',
];

foreach ($transfer_months as $idx => $date_base) {
    if (empty($transfer_candidates)) {
        break;
    }

    $to_id = $transfer_candidates[$idx % count($transfer_candidates)];
    $from_id = paired_item_id($items, $to_id);
    if (!$from_id) {
        continue;
    }

    $qty = mt_rand(3, 14);
    $ref = $reference_id++;
    $created_at = $date_base . ' ' . sprintf('%02d:%02d:00', mt_rand(10, 15), mt_rand(0, 59));
    add_transaction($transactions, $transaction_id, $from_id, 'stock_out', $qty, 'branch_transfer', $ref, 'Transferred stock to another inventory branch.', $created_at);
    add_transaction($transactions, $transaction_id, $to_id, 'stock_in', $qty, 'branch_transfer', $ref, 'Received branch stock transfer.', $created_at);
    $balances[$from_id] -= $qty;
    $balances[$to_id] += $qty;
}

function forecast_status_for_item(array $item, array $transactions): array
{
    $anchor = strtotime('2026-08-16 17:00:00');
    $recent_start = strtotime('-30 days', $anchor);
    $previous_start = strtotime('-60 days', $anchor);
    $recent_out = 0;
    $previous_out = 0;

    foreach ($transactions as $transaction) {
        if ((int) $transaction['item_id'] !== (int) $item['id']) {
            continue;
        }

        if ($transaction['transaction_type'] !== 'stock_out' || $transaction['reference_type'] === 'branch_transfer') {
            continue;
        }

        $timestamp = strtotime($transaction['created_at']);
        if ($timestamp > $recent_start && $timestamp <= $anchor) {
            $recent_out += abs((int) $transaction['quantity']);
        } elseif ($timestamp > $previous_start && $timestamp <= $recent_start) {
            $previous_out += abs((int) $transaction['quantity']);
        }
    }

    $weekly_usage = round($recent_out / (30 / 7), 1);
    $previous_weekly = round($previous_out / (30 / 7), 1);
    $stock_duration = $weekly_usage > 0 ? round(((int) $item['quantity']) / $weekly_usage, 1) : null;

    if ($weekly_usage > 0 && $stock_duration < 2) {
        $status = 'critical';
    } elseif ($weekly_usage > 0 && $stock_duration < 4) {
        $status = 'warning';
    } elseif ($weekly_usage <= 0 && (int) $item['quantity'] <= (int) $item['reorder_level']) {
        $status = 'warning';
    } else {
        $status = 'good';
    }

    return [
        'status' => $status,
        'weekly_usage' => $weekly_usage,
        'previous_weekly' => $previous_weekly,
        'duration' => $stock_duration,
        'recent_out' => $recent_out,
    ];
}

foreach ($items as $id => $item) {
    $forecast_probe = forecast_status_for_item($item, $transactions);
    $weekly = max(0.8, (float) $forecast_probe['weekly_usage']);
    $target = $item['target_status'] ?? 'good';

    if ($target === 'critical') {
        $items[$id]['quantity'] = max(1, (int) floor($weekly * mt_rand(7, 15) / 10));
    } elseif ($target === 'warning') {
        $items[$id]['quantity'] = max((int) $item['reorder_level'] - mt_rand(0, 3), (int) ceil($weekly * mt_rand(22, 34) / 10));
    } else {
        $healthy = max((int) $item['reorder_level'] + mt_rand(12, 55), (int) ceil($weekly * mt_rand(52, 95) / 10));
        $items[$id]['quantity'] = $healthy;
    }

    $items[$id]['updated_at'] = '2026-08-16 18:00:00';
}

$current_balances = inventory_balances_from_transactions($items, $transactions);

foreach ($items as $id => $item) {
    $desired_quantity = (int) $item['quantity'];
    $current_quantity = (int) ($current_balances[$id] ?? 0);
    $difference = $desired_quantity - $current_quantity;

    if ($difference > 0) {
        add_transaction(
            $transactions,
            $transaction_id,
            (int) $id,
            'adjustment',
            $difference,
            'inventory_recount_up',
            $reference_id++,
            'Clean inventory recount adjustment to match verified on-hand stock.',
            '2026-08-16 17:30:00'
        );
    } elseif ($difference < 0) {
        add_transaction(
            $transactions,
            $transaction_id,
            (int) $id,
            'adjustment',
            abs($difference),
            'inventory_recount_down',
            $reference_id++,
            'Clean inventory recount adjustment to match verified on-hand stock.',
            '2026-08-16 17:30:00'
        );
    }

    $balances[$id] = $desired_quantity;
}

usort($transactions, static function ($left, $right) {
    $date_compare = strcmp((string) $left['created_at'], (string) $right['created_at']);
    if ($date_compare !== 0) {
        return $date_compare;
    }

    return ((int) $left['id']) <=> ((int) $right['id']);
});

foreach ($transactions as $index => &$transaction) {
    $transaction['id'] = $index + 1;
}
unset($transaction);
$transaction_id = count($transactions) + 1;

validate_inventory_dataset($items, $transactions, $branches, $years);

$transactions_by_year = [];
foreach ($transactions as $transaction) {
    $year = (int) substr($transaction['created_at'], 0, 4);
    $transactions_by_year[$year][] = $transaction;
}

function quantity_as_of_year(array $item, int $year): int
{
    if ($year === 2026) {
        return (int) $item['quantity'];
    }

    $offset = (2026 - $year) * mt_rand(8, 18);
    $base = max((int) $item['reorder_level'] + mt_rand(8, 28), (int) $item['quantity'] + $offset);

    if (($item['target_status'] ?? 'good') !== 'good' && $year < 2026) {
        $base += mt_rand(12, 32);
    }

    return $base;
}

function write_quantity_update($fh, array $items, int $year): void
{
    if (empty($items)) {
        return;
    }

    fwrite($fh, "UPDATE inventory_items\nSET quantity = CASE id\n");
    foreach ($items as $item) {
        fwrite($fh, '    WHEN ' . (int) $item['id'] . ' THEN ' . quantity_as_of_year($item, $year) . "\n");
    }
    fwrite($fh, "    ELSE quantity\nEND,\nlast_restock_date = CASE id\n");
    foreach ($items as $item) {
        $date = $year === 2026 ? ($item['last_restock_date'] ?? '2026-08-16') : month_end_date($year, 12);
        fwrite($fh, '    WHEN ' . (int) $item['id'] . ' THEN ' . sql_value($date) . "\n");
    }
    fwrite($fh, "    ELSE last_restock_date\nEND,\nmanufacturing_date = CASE id\n");
    foreach ($items as $item) {
        fwrite($fh, '    WHEN ' . (int) $item['id'] . ' THEN ' . sql_value(manufacturing_date_as_of_year($item, $year)) . "\n");
    }
    fwrite($fh, "    ELSE manufacturing_date\nEND,\nupdated_at = " . sql_value($year === 2026 ? '2026-08-16 18:00:00' : month_end_date($year, 12) . ' 18:00:00') . "\nWHERE id IN (" . implode(', ', array_map(static fn($item) => (int) $item['id'], $items)) . ");\n\n");
}

$item_columns = [
    'id', 'branch_id', 'item_name', 'category', 'brand', 'model', 'size', 'description', 'sku',
    'serial_number', 'manufacturing_date', 'quantity', 'reorder_level', 'unit_price', 'supplier_name', 'supplier_contact',
    'status', 'last_restock_date', 'created_at', 'updated_at',
];
$transaction_columns = [
    'id', 'item_id', 'transaction_type', 'quantity', 'reference_type', 'reference_id', 'notes', 'created_by', 'created_at',
];

foreach ($years as $year) {
    $file_name = $year === 2023
        ? 'hwtires_inventory_seed_2023_reset.sql'
        : sprintf('hwtires_inventory_seed_%d_append.sql', $year);
    $fh = fopen($output_dir . DIRECTORY_SEPARATOR . $file_name, 'wb');
    if (!$fh) {
        throw new RuntimeException("Unable to write {$file_name}");
    }

    fwrite($fh, "-- Highway Tires inventory yearly seed for {$year}\n");
    fwrite($fh, "-- Generated by database/generate_inventory_yearly_seed.php\n");
    fwrite($fh, "USE hwtires;\n\n");
    fwrite($fh, "SET FOREIGN_KEY_CHECKS = 0;\n");

    if ($year === 2023) {
        fwrite($fh, "DELETE FROM transfer_notifications;\n");
        fwrite($fh, "DELETE FROM inter_branch_transfer_requests;\n");
        fwrite($fh, "DELETE FROM inventory_transactions;\n");
        fwrite($fh, "DELETE FROM inventory_items;\n");
        fwrite($fh, "ALTER TABLE inventory_transactions AUTO_INCREMENT = 1;\n");
        fwrite($fh, "ALTER TABLE inventory_items AUTO_INCREMENT = 1;\n");
        fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n\n");
        fwrite($fh, "UPDATE branches SET has_inventory = 1 WHERE id IN (1, 2, 3);\n\n");

        $item_rows = [];
        foreach ($items as $item) {
            $item_rows[] = [
                $item['id'],
                $item['branch_id'],
                $item['item_name'],
                $item['category'],
                $item['brand'],
                $item['model'],
                $item['size'],
                $item['description'],
                $item['sku'],
                $item['serial_number'],
                manufacturing_date_as_of_year($item, 2023),
                quantity_as_of_year($item, 2023),
                $item['reorder_level'],
                number_format((float) $item['unit_price'], 2, '.', ''),
                $item['supplier_name'],
                $item['supplier_contact'],
                $item['status'],
                '2023-12-31',
                $item['created_at'],
                '2023-12-31 18:00:00',
            ];
        }
        write_insert($fh, 'inventory_items', $item_columns, $item_rows);
    } else {
        $start = sprintf('%04d-01-01', $year);
        $end = $year === 2026 ? '2026-08-17' : sprintf('%04d-01-01', $year + 1);
        fwrite($fh, "DELETE FROM inventory_transactions WHERE created_at >= " . sql_value($start) . " AND created_at < " . sql_value($end) . ";\n");
        fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n\n");
    }

    $transaction_rows = [];
    foreach ($transactions_by_year[$year] ?? [] as $transaction) {
        $transaction_rows[] = [
            $transaction['id'],
            $transaction['item_id'],
            $transaction['transaction_type'],
            $transaction['quantity'],
            $transaction['reference_type'],
            $transaction['reference_id'],
            $transaction['notes'],
            $transaction['created_by'],
            $transaction['created_at'],
        ];
    }
    write_insert($fh, 'inventory_transactions', $transaction_columns, $transaction_rows, 300);
    write_quantity_update($fh, array_values($items), $year);
    fwrite($fh, "ALTER TABLE inventory_items AUTO_INCREMENT = " . ($next_item_id + 100) . ";\n");
    fwrite($fh, "ALTER TABLE inventory_transactions AUTO_INCREMENT = " . ($transaction_id + 100) . ";\n");
    fclose($fh);
}

$status_counts = ['critical' => 0, 'warning' => 0, 'good' => 0];
$category_counts = [];
$branch_counts = [];
$may_movement = [];
foreach ($items as $item) {
    $status = forecast_status_for_item($item, $transactions)['status'];
    $status_counts[$status]++;
    $branch_counts[$item['branch_id']][$item['category']] = ($branch_counts[$item['branch_id']][$item['category']] ?? 0) + 1;
}

foreach ($transactions as $transaction) {
    if (substr($transaction['created_at'], 0, 7) !== '2026-08' || $transaction['reference_type'] === 'opening_balance') {
        continue;
    }
    $item = $items[$transaction['item_id']] ?? null;
    if (!$item) {
        continue;
    }
    $key = $item['branch_id'] . ':' . $item['category'] . ':' . $transaction['transaction_type'];
    $may_movement[$key] = ($may_movement[$key] ?? 0) + abs((int) $transaction['quantity']);
}

echo "Generated yearly inventory SQL files:\n";
foreach ($years as $year) {
    $file_name = $year === 2023
        ? 'hwtires_inventory_seed_2023_reset.sql'
        : sprintf('hwtires_inventory_seed_%d_append.sql', $year);
    echo " - database/{$file_name}\n";
}
echo "\nInventory items: " . count($items) . "\n";
echo "Transactions: " . count($transactions) . "\n";
echo "Forecast status as of 2026-08-16:\n";
foreach ($status_counts as $status => $count) {
    echo " - " . ucfirst($status) . ": {$count}\n";
}
echo "Branch/category item mix:\n";
foreach ($branch_counts as $branch_id => $counts) {
    echo " - {$branches[$branch_id]}: tires " . ($counts['tire'] ?? 0) . ', accessories ' . ($counts['accessory'] ?? 0) . ', parts ' . ($counts['part'] ?? 0) . "\n";
}
echo "August 2026 movement sample by branch/category/type:\n";
ksort($may_movement);
foreach ($may_movement as $key => $qty) {
    [$branch_id, $category, $type] = explode(':', $key);
    echo " - {$branches[(int) $branch_id]} {$category} {$type}: {$qty}\n";
}
