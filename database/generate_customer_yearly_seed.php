<?php
/**
 * Generate yearly customer/service operation seed files.
 *
 * Import order:
 *  1. hwtires_customer_records_seed_2023_reset.sql
 *  2. hwtires_customer_records_seed_2024_append.sql
 *  3. hwtires_customer_records_seed_2025_append.sql
 *  4. hwtires_customer_records_seed_2026_append.sql
 */

date_default_timezone_set('Asia/Manila');
mt_srand(5202026);

$outputDir = __DIR__;
$years = [2023, 2024, 2025, 2026];
$seedEndDate = '2026-08-16';

$rows = [];
foreach ($years as $year) {
    $rows[$year] = [
        'customers' => [],
        'vehicles' => [],
        'vehicle_ownership_history' => [],
        'customer_branch_records' => [],
        'customer_visits' => [],
        'quotations' => [],
        'quotation_items' => [],
        'job_orders' => [],
        'job_order_progress' => [],
        'service_history' => [],
    ];
}

$branches = [
    1 => ['name' => 'Lacson Branch', 'label' => 'Lacson Branch', 'sales' => 'Peaches', 'user_id' => 2, 'technicians' => [[1, 'Mike Johnson'], [2, 'David Brown']]],
    2 => ['name' => 'Magsaysay Branch', 'label' => 'Magsaysay Branch', 'sales' => 'James', 'user_id' => 3, 'technicians' => [[3, 'Carlos Lopez'], [4, 'Robert Wilson']]],
    3 => ['name' => 'Bata Branch', 'label' => 'Bata Branch', 'sales' => 'Diane', 'user_id' => 4, 'technicians' => [[5, 'James Martinez'], [6, 'Tom Anderson']]],
];

$serviceCatalog = [
    ['name' => 'Computerized Four Wheel Alignment', 'category' => 'Alignment', 'price' => 1920, 'duration' => '1 hour'],
    ['name' => 'Wheel Balancing / Computerized Wheel Balancing', 'category' => 'Tires', 'price' => 700, 'duration' => '2 hours'],
    ['name' => 'Tire Mounting and Rotation / Pneumatic Tire Mounting', 'category' => 'Tires', 'price' => 200, 'duration' => '4 hours', 'qty_min' => 2, 'qty_max' => 4],
    ['name' => 'Under Chassis and Suspension Repair', 'category' => 'Suspension', 'price' => 4500, 'duration' => '1-2 days'],
    ['name' => 'Oil Change and Engine Tune-Up', 'category' => 'Maintenance', 'price' => 1000, 'duration' => '1 hour'],
    ['name' => 'Suspension Parts Installation', 'category' => 'Suspension', 'price' => 6250, 'duration' => '2 days'],
    ['name' => 'Nitrogen Air Tire Inflation', 'category' => 'Tires', 'price' => 100, 'duration' => '10 minutes', 'qty_min' => 2, 'qty_max' => 4],
    ['name' => 'Battery Check-Up and Fast Charging', 'category' => 'Electrical', 'price' => 100, 'duration' => '30 minutes'],
    ['name' => 'Automatic Transmission Flushing / ATF Changer Machine', 'category' => 'Transmission', 'price' => 2000, 'duration' => '3 hours'],
    ['name' => 'Brake Cleaning and Adjustment / Brake Repair', 'category' => 'Brakes', 'price' => 1600, 'duration' => '45 minutes'],
    ['name' => 'Manual Clutch Repair', 'category' => 'Transmission', 'price' => 6000, 'duration' => '1 day'],
    ['name' => 'Big Bike / Car Tire Change', 'category' => 'Tires', 'price' => 200, 'duration' => '30 minutes', 'qty_min' => 1, 'qty_max' => 4],
    ['name' => 'Car Sanitizing Service (BACKTOZERO)', 'category' => 'Cleaning', 'price' => 1500, 'duration' => '10 minutes'],
    ['name' => 'Auto Diagnostic Scanning / Auto Diagnostic Scanner', 'category' => 'Diagnostics', 'price' => 1500, 'duration' => '5 minutes'],
    ['name' => 'Preventive Maintenance Check-Up', 'category' => 'Maintenance', 'price' => 2500, 'duration' => '1 hour 45 minutes'],
    ['name' => 'Cold Patch Vulcanizing and Tire Repair', 'category' => 'Tires', 'price' => 270, 'duration' => '15 minutes', 'qty_min' => 1, 'qty_max' => 2],
    ['name' => 'EGR Cleaning', 'category' => 'Cleaning', 'price' => 1200, 'duration' => '1 hour'],
    ['name' => 'Car Accessories Installation', 'category' => 'Accessories', 'price' => 500, 'duration' => '1-3 hours'],
    ['name' => 'AC Refrigerant Charging', 'category' => 'Air Conditioning', 'price' => 1500, 'duration' => '20 minutes'],
    ['name' => 'Tire Rotation', 'category' => 'Tires', 'price' => 400, 'duration' => '30-40 minutes'],
];

$partsCatalog = [
    ['name' => 'Brake Pads Set', 'category' => 'Brakes', 'type' => 'part', 'price' => 1450],
    ['name' => 'Oil Filter', 'category' => 'Filters', 'type' => 'part', 'price' => 350],
    ['name' => 'Fuel Filter', 'category' => 'Filters', 'type' => 'part', 'price' => 650],
    ['name' => 'Air Filter', 'category' => 'Filters', 'type' => 'part', 'price' => 300],
    ['name' => 'Spark Plugs Set', 'category' => 'Spark Plugs', 'type' => 'part', 'price' => 1200],
    ['name' => 'Rubber Valve Replacement', 'category' => 'Tires', 'type' => 'part', 'price' => 120],
    ['name' => 'Drive Belt Replacement', 'category' => 'Engine', 'type' => 'part', 'price' => 900],
    ['name' => 'Battery Terminal Set', 'category' => 'Electrical', 'type' => 'part', 'price' => 280],
    ['name' => 'Tie Rod End', 'category' => 'Suspension', 'type' => 'part', 'price' => 1300],
    ['name' => 'Shock Absorber', 'category' => 'Suspension', 'type' => 'part', 'price' => 2800],
    ['name' => '185/65R14 Tire', 'category' => 'Tires', 'type' => 'tire', 'price' => 3000],
    ['name' => '195/55R15 Tire', 'category' => 'Tires', 'type' => 'tire', 'price' => 3500],
    ['name' => '205/55R16 Tire', 'category' => 'Tires', 'type' => 'tire', 'price' => 4200],
    ['name' => 'Car Floor Mat Set', 'category' => 'Accessories', 'type' => 'part', 'price' => 850],
    ['name' => 'Wiper Blades Pair', 'category' => 'Accessories', 'type' => 'part', 'price' => 650],
];

$concerns = [
    'Customer reports vibration while driving at highway speed.',
    'Customer requested regular maintenance and safety check.',
    'Customer reports uneven tire wear and steering pull.',
    'Customer requested brake inspection before a long trip.',
    'Customer reports weak battery start in the morning.',
    'Customer requested tire repair after finding a slow leak.',
    'Customer reports suspension noise on rough roads.',
    'Customer requested air conditioning cooling check.',
    'Customer requested engine scan after warning light appeared.',
    'Customer requested accessories installation and fitment check.',
];

$findings = [
    'Road test completed; tire wear pattern and under chassis condition inspected.',
    'Visual inspection completed; serviceable parts identified and safety items checked.',
    'Wheel condition checked; alignment readings and tire pressure verified.',
    'Brake components inspected; cleaning and adjustment recommended.',
    'Battery voltage and charging condition tested.',
    'Leak source confirmed and tire condition inspected.',
    'Suspension links, bushings, and shock absorbers inspected.',
    'Cooling performance checked and refrigerant level verified.',
    'Diagnostic scan completed and stored fault codes reviewed.',
    'Accessory fitment checked before installation.',
];

$recommendations = [
    'Proceed with recommended service items and update mileage after service.',
    'Perform service operation based on inspection findings.',
    'Complete alignment and tire service, then road test vehicle.',
    'Complete brake service and verify pedal feel.',
    'Charge or replace battery-related components as needed.',
    'Repair affected tire and rebalance if necessary.',
    'Replace worn suspension parts and perform alignment after repair.',
    'Recharge refrigerant and inspect for leaks if cooling remains weak.',
    'Perform diagnostic service and confirm issue after repair.',
    'Install accessory and check secure fitment.',
];

$firstNames = ['Anthony', 'Arvin', 'Bryan', 'Carlo', 'Daniel', 'Dennis', 'Edwin', 'Francis', 'Gerald', 'Harold', 'Ivan', 'Jerome', 'Kevin', 'Leo', 'Marco', 'Nathan', 'Oliver', 'Paolo', 'Ramon', 'Samuel', 'Victor', 'Warren', 'Aileen', 'Angela', 'Bianca', 'Carla', 'Diane', 'Elaine', 'Faith', 'Grace', 'Hannah', 'Irene', 'Jessa', 'Karen', 'Liza', 'Mara', 'Nicole', 'Patricia', 'Rhea', 'Sofia'];
$lastNames = ['Abarquez', 'Alcantara', 'Arceo', 'Benedicto', 'Castillo', 'Cuenca', 'Dela Cruz', 'Diaz', 'Escalante', 'Fernandez', 'Garcia', 'Gomez', 'Jalandoni', 'Javellana', 'Lacson', 'Locsin', 'Lopez', 'Magbanua', 'Montelibano', 'Ong', 'Reyes', 'Rojas', 'Santos', 'Tupas', 'Villanueva', 'Yulo'];
$companyRoots = ['Bacolod', 'Negros', 'Cityline', 'Golden Cane', 'Northline', 'Central', 'Panaad', 'Silay', 'La Salle', 'Capitol', 'San Carlos'];
$companyTypes = ['Trading Co', 'Logistics', 'Transport Services', 'Food Products', 'Hardware Supply', 'Courier Fleet', 'Construction Supply', 'Farm Services'];
$streets = ['Lacson Street', 'Magsaysay Avenue', 'Araneta Avenue', 'Burgos Street', 'Rosario Street', 'Galo Street', 'BS Aquino Drive', 'Circumferential Road', 'Rizal Street', 'Hilado Street', 'Hernaez Street', 'Lopez Jaena Street'];
$barangays = ['Mandalagan', 'Villamonte', 'Taculing', 'Mansilingan', 'Alijis', 'Tangub', 'Bata', 'Estefania', 'Singcang-Airport', 'Downtown', 'Granada', 'Pahanocoy'];
$cities = ['Bacolod City', 'Talisay City', 'Silay City', 'Bago City', 'Murcia', 'La Carlota City'];
$vehicleModels = [
    ['Toyota', 'Vios'], ['Toyota', 'Innova'], ['Toyota', 'Hi-Lux'], ['Toyota', 'Fortuner'], ['Toyota', 'Tamaraw FX'],
    ['Honda', 'City'], ['Honda', 'Civic'], ['Mitsubishi', 'L300'], ['Mitsubishi', 'Mirage'], ['Mitsubishi', 'Montero'],
    ['Nissan', 'Navara'], ['Nissan', 'Almera'], ['Isuzu', 'Elf'], ['Isuzu', 'D-Max'], ['Suzuki', 'Ertiga'],
    ['Hyundai', 'Accent'], ['Kia', 'K2500'], ['Ford', 'Ranger'], ['Chevrolet', 'Trailblazer'], ['Mazda', 'BT-50'],
];
$colors = ['White', 'Silver', 'Gray', 'Black', 'Red', 'Blue', 'Pearl White', 'Brown', 'Champagne'];

$customers = [];
$customerRecords = [];
$customerIds = [];
$customerVisits = [];
$vehicles = [];
$vehiclesByCustomer = [];
$usedNames = [];
$usedPhones = [];
$usedPlates = [];
$branchRecordLatest = [];
$customerId = 100001;
$vehicleId = 200001;
$quotationId = 300001;
$quotationItemId = 400001;
$jobOrderId = 500001;
$progressId = 600001;
$historyId = 700001;
$visitId = 800001;
$branchRecordId = 900001;
$ownershipId = 950001;
$dailySequence = [];

function sql_value($value) {
    if ($value === null) {
        return 'NULL';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    return "'" . str_replace("'", "''", (string) $value) . "'";
}

function sql_tuple(array $values) {
    return '(' . implode(', ', array_map('sql_value', $values)) . ')';
}

function weighted_pick(array $weights) {
    $total = array_sum($weights);
    $roll = mt_rand(1, $total);
    $running = 0;
    foreach ($weights as $value => $weight) {
        $running += $weight;
        if ($roll <= $running) {
            return $value;
        }
    }
    return array_key_first($weights);
}

function pick(array $items) {
    return $items[mt_rand(0, count($items) - 1)];
}

function next_time_for_day(DateTime $date, int $index) {
    $hour = 8 + (($index - 1) % 8);
    $minute = [0, 10, 20, 30, 40, 50][mt_rand(0, 5)];
    return $date->format('Y-m-d') . ' ' . sprintf('%02d:%02d:00', $hour, $minute);
}

function make_phone(array &$usedPhones) {
    do {
        $phone = '09' . mt_rand(10, 99) . mt_rand(100, 999) . mt_rand(1000, 9999);
    } while (isset($usedPhones[$phone]));
    $usedPhones[$phone] = true;
    return $phone;
}

function make_plate(array &$usedPlates) {
    $letters = 'ABCDEFGHJKLMNPRSTUVWXYZ';
    do {
        $plate = $letters[mt_rand(0, strlen($letters) - 1)] .
            $letters[mt_rand(0, strlen($letters) - 1)] .
            $letters[mt_rand(0, strlen($letters) - 1)] .
            '-' . mt_rand(1000, 9999);
    } while (isset($usedPlates[$plate]));
    $usedPlates[$plate] = true;
    return $plate;
}

function make_customer_name(array &$usedNames, array $firstNames, array $lastNames, array $companyRoots, array $companyTypes) {
    $isCompany = mt_rand(1, 100) <= 10;
    do {
        if ($isCompany) {
            $name = pick($companyRoots) . ' ' . pick($companyTypes) . ' ' . mt_rand(10, 99);
        } else {
            $first = pick($firstNames);
            $last = pick($lastNames);
            if (mt_rand(1, 100) <= 25) {
                $name = $first . ' ' . chr(mt_rand(65, 77)) . '. ' . $last;
            } else {
                $name = $first . ' ' . $last;
            }
        }
    } while (isset($usedNames[strtolower($name)]));
    $usedNames[strtolower($name)] = true;
    return [$name, $isCompany ? 'corporate' : 'individual'];
}

function customer_branch_set(int $primaryBranch) {
    $roll = mt_rand(1, 100);
    if ($roll <= 80) {
        return [$primaryBranch];
    }
    if ($roll <= 96) {
        $others = array_values(array_diff([1, 2, 3], [$primaryBranch]));
        return [$primaryBranch, pick($others)];
    }
    return [1, 2, 3];
}

function choose_services(array $serviceCatalog) {
    $count = weighted_pick([1 => 54, 2 => 35, 3 => 11]);
    $selected = [];
    $used = [];
    while (count($selected) < $count) {
        $svc = pick($serviceCatalog);
        if (isset($used[$svc['name']])) {
            continue;
        }
        $used[$svc['name']] = true;
        $svc['quantity'] = isset($svc['qty_min']) ? mt_rand($svc['qty_min'], $svc['qty_max']) : 1;
        $selected[] = $svc;
    }
    return $selected;
}

function choose_parts(array $partsCatalog, array $services) {
    $serviceNames = implode(' ', array_column($services, 'name'));
    $parts = [];
    $chance = mt_rand(1, 100);
    if ($chance > 44) {
        return $parts;
    }

    $count = $chance <= 15 ? 2 : 1;
    for ($i = 0; $i < $count; $i++) {
        if (stripos($serviceNames, 'Brake') !== false) {
            $part = pick(array_values(array_filter($partsCatalog, fn($p) => $p['category'] === 'Brakes')));
        } elseif (stripos($serviceNames, 'Oil') !== false || stripos($serviceNames, 'Maintenance') !== false) {
            $part = pick(array_values(array_filter($partsCatalog, fn($p) => in_array($p['category'], ['Filters', 'Spark Plugs'], true))));
        } elseif (stripos($serviceNames, 'Tire') !== false || stripos($serviceNames, 'Wheel') !== false) {
            $part = pick(array_values(array_filter($partsCatalog, fn($p) => in_array($p['category'], ['Tires', 'Accessories'], true))));
        } elseif (stripos($serviceNames, 'Suspension') !== false || stripos($serviceNames, 'Chassis') !== false) {
            $part = pick(array_values(array_filter($partsCatalog, fn($p) => $p['category'] === 'Suspension')));
        } else {
            $part = pick($partsCatalog);
        }
        $part['quantity'] = $part['type'] === 'tire' ? mt_rand(1, 4) : mt_rand(1, 2);
        $parts[] = $part;
    }
    return $parts;
}

function combine_durations(array $services) {
    $durations = array_values(array_unique(array_map(fn($s) => $s['duration'], $services)));
    if (count($durations) === 1) {
        return $durations[0];
    }
    if (in_array('2 days', $durations, true) || in_array('1-2 days', $durations, true)) {
        return '1-2 days';
    }
    if (in_array('1 day', $durations, true)) {
        return '1 day';
    }
    return count($services) >= 3 ? '3-4 hours' : '1-2 hours';
}

function job_status_for(DateTime $date, string $quotationStatus) {
    if ($quotationStatus !== 'approved') {
        return null;
    }

    $dateKey = $date->format('Y-m-d');
    if ($dateKey >= '2026-08-10') {
        return weighted_pick([
            'completed' => 48,
            'in-progress' => 28,
            'waiting' => 19,
            'cancelled' => 5,
        ]);
    }

    return weighted_pick([
        'completed' => 93,
        'cancelled' => 4,
        'in-progress' => 2,
        'waiting' => 1,
    ]);
}

function quotation_status_for(DateTime $date) {
    $dateKey = $date->format('Y-m-d');
    if ($dateKey >= '2026-08-10') {
        return weighted_pick([
            'approved' => 78,
            'pending' => 15,
            'rejected' => 7,
        ]);
    }

    return weighted_pick([
        'approved' => 91,
        'pending' => 4,
        'rejected' => 5,
    ]);
}

function choose_technicians(array $branchTechnicians) {
    $count = mt_rand(1, 100) <= 24 ? 2 : 1;
    shuffle($branchTechnicians);
    return array_slice($branchTechnicians, 0, $count);
}

function customer_index_for_branch(array $customers, int $branchId) {
    $matches = [];
    foreach ($customers as $id => $customer) {
        if (in_array($branchId, $customer['branch_set'], true)) {
            $matches[] = $id;
        }
    }
    return $matches;
}

function write_insert($fh, string $table, array $columns, array $tableRows, string $suffix = '') {
    if (empty($tableRows)) {
        return;
    }
    $chunks = array_chunk($tableRows, 350);
    $columnSql = '`' . implode('`, `', $columns) . '`';
    foreach ($chunks as $chunk) {
        fwrite($fh, "INSERT INTO `$table` ($columnSql) VALUES\n");
        fwrite($fh, implode(",\n", $chunk));
        if ($suffix !== '') {
            fwrite($fh, "\n$suffix;\n\n");
        } else {
            fwrite($fh, ";\n\n");
        }
    }
}

function seed_has_control_chars(string $value): bool {
    return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1;
}

function seed_valid_date(string $date, string $maxDate = '2026-08-16'): bool {
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed instanceof DateTime
        && $parsed->format('Y-m-d') === $date
        && $date <= $maxDate
        && $date !== '0000-00-00';
}

function seed_valid_datetime(string $datetime, string $maxDatetime = '2026-08-16 23:59:59'): bool {
    $parsed = DateTime::createFromFormat('!Y-m-d H:i:s', $datetime);
    return $parsed instanceof DateTime
        && $parsed->format('Y-m-d H:i:s') === $datetime
        && $datetime <= $maxDatetime
        && strpos($datetime, '0000-00-00') === false;
}

function validate_customer_vehicle_seed(array $customerRecords, array $vehicles, array $vehiclesByCustomer, array $rows, array $branchIds): array {
    $errors = [];
    $contacts = [];
    $names = [];
    $plates = [];
    $branchVehicleCounts = array_fill_keys($branchIds, 0);
    $previousOwnerCount = 0;
    $multiVehicleCustomerCount = 0;
    $singleVehicleCustomerCount = 0;

    foreach ($customerRecords as $customer) {
        $id = (int) ($customer['id'] ?? 0);
        $name = trim((string) ($customer['name'] ?? ''));
        $contact = trim((string) ($customer['contact'] ?? ''));
        $address = trim((string) ($customer['address'] ?? ''));
        $city = trim((string) ($customer['city'] ?? ''));
        $branchId = (int) ($customer['primary_branch'] ?? 0);
        $createdAt = (string) ($customer['created_at'] ?? '');

        if ($id <= 0 || $name === '' || $address === '' || $city === '') {
            $errors[] = "Customer {$id} has incomplete required details";
        }

        foreach ([$name, $contact, $address, $city] as $value) {
            if (seed_has_control_chars((string) $value)) {
                $errors[] = "Customer {$id} contains invalid characters";
                break;
            }
        }

        $nameKey = strtolower($name);
        if ($nameKey === '' || isset($names[$nameKey])) {
            $errors[] = "Customer {$id} has a missing or duplicate name";
        }
        $names[$nameKey] = true;

        if (!preg_match('/^09\d{9}$/', $contact) || isset($contacts[$contact])) {
            $errors[] = "Customer {$id} has a missing, invalid, or duplicate contact number";
        }
        $contacts[$contact] = true;

        if (!in_array($branchId, $branchIds, true)) {
            $errors[] = "Customer {$id} has an invalid branch";
        }

        if (!seed_valid_datetime($createdAt)) {
            $errors[] = "Customer {$id} has an invalid created_at date";
        }
    }

    foreach ($vehiclesByCustomer as $customerId => $customerVehicles) {
        $vehicleCount = count($customerVehicles);
        if ($vehicleCount === 1) {
            $singleVehicleCustomerCount++;
        } elseif ($vehicleCount > 1) {
            $multiVehicleCustomerCount++;
        }

        if (!isset($customerRecords[$customerId])) {
            $errors[] = "Vehicle owner customer {$customerId} is missing";
        }
    }

    foreach ($vehicles as $vehicle) {
        $id = (int) ($vehicle['id'] ?? 0);
        $customerId = (int) ($vehicle['customer_id'] ?? 0);
        $branchId = (int) ($vehicle['branch_id'] ?? 0);
        $plate = trim((string) ($vehicle['plate'] ?? ''));
        $make = trim((string) ($vehicle['make'] ?? ''));
        $model = trim((string) ($vehicle['model'] ?? ''));
        $color = trim((string) ($vehicle['color'] ?? ''));
        $year = (int) ($vehicle['year'] ?? 0);
        $lastServiceDate = (string) ($vehicle['last_service_date'] ?? '');
        $createdAt = (string) ($vehicle['created_at'] ?? '');
        $currentOwnedFrom = (string) ($vehicle['current_owned_from'] ?? '');

        if (!isset($customerRecords[$customerId])) {
            $errors[] = "Vehicle {$id} has a missing current owner";
        }

        if (!in_array($branchId, $branchIds, true)) {
            $errors[] = "Vehicle {$id} has an invalid branch";
        } else {
            $branchVehicleCounts[$branchId]++;
        }

        foreach ([$plate, $make, $model, $color] as $value) {
            if ($value === '' || seed_has_control_chars((string) $value)) {
                $errors[] = "Vehicle {$id} has incomplete or invalid text fields";
                break;
            }
        }

        if (!preg_match('/^[A-Z]{3}-\d{4}$/', $plate) || isset($plates[$plate])) {
            $errors[] = "Vehicle {$id} has a missing, invalid, or duplicate plate number";
        }
        $plates[$plate] = true;

        if ($year < 1995 || $year > 2026) {
            $errors[] = "Vehicle {$id} has an invalid model year";
        }

        if (!seed_valid_date($lastServiceDate) || !seed_valid_datetime($createdAt) || !seed_valid_date($currentOwnedFrom)) {
            $errors[] = "Vehicle {$id} has invalid service, created, or ownership dates";
        }

        if ($currentOwnedFrom > $lastServiceDate) {
            $errors[] = "Vehicle {$id} current ownership starts after its last service date";
        }

        if ((int) ($vehicle['last_mileage'] ?? 0) < 0) {
            $errors[] = "Vehicle {$id} has invalid mileage";
        }

        if (!empty($vehicle['previous_owner_id'])) {
            $previousOwnerCount++;
            $previousOwnerId = (int) $vehicle['previous_owner_id'];
            $previousOwnedFrom = (string) ($vehicle['previous_owned_from'] ?? '');
            $previousOwnedUntil = (string) ($vehicle['previous_owned_until'] ?? '');

            if (!isset($customerRecords[$previousOwnerId])) {
                $errors[] = "Vehicle {$id} previous owner {$previousOwnerId} is missing";
            }

            if ($previousOwnerId === $customerId) {
                $errors[] = "Vehicle {$id} previous owner matches current owner";
            }

            if (!seed_valid_date($previousOwnedFrom) || !seed_valid_date($previousOwnedUntil)) {
                $errors[] = "Vehicle {$id} has invalid previous ownership dates";
            } elseif ($previousOwnedFrom > $previousOwnedUntil || $previousOwnedUntil >= $currentOwnedFrom) {
                $errors[] = "Vehicle {$id} has overlapping previous and current ownership dates";
            }
        }
    }

    foreach ($branchVehicleCounts as $branchId => $count) {
        if ($count === 0) {
            $errors[] = "Branch {$branchId} has no vehicle records";
        }
    }

    if ($singleVehicleCustomerCount === 0) {
        $errors[] = 'Seed must include customers with one vehicle';
    }

    if ($multiVehicleCustomerCount === 0) {
        $errors[] = 'Seed must include customers with multiple vehicles';
    }

    if ($previousOwnerCount === 0) {
        $errors[] = 'Seed must include vehicles with previous ownership history';
    }

    $ownershipRowCount = 0;
    foreach ($rows as $yearRows) {
        $ownershipRowCount += count($yearRows['vehicle_ownership_history'] ?? []);
    }

    if ($ownershipRowCount !== count($vehicles) + $previousOwnerCount) {
        $errors[] = 'Ownership history row count does not match the generated vehicles';
    }

    if (!empty($errors)) {
        throw new RuntimeException("Customer and vehicle seed validation failed:\n - " . implode("\n - ", array_slice($errors, 0, 30)));
    }

    return [
        'customers' => count($customerRecords),
        'vehicles' => count($vehicles),
        'single_vehicle_customers' => $singleVehicleCustomerCount,
        'multi_vehicle_customers' => $multiVehicleCustomerCount,
        'vehicles_with_previous_owner' => $previousOwnerCount,
        'ownership_rows' => $ownershipRowCount,
        'branch_vehicle_counts' => $branchVehicleCounts,
    ];
}

function create_customer(
    DateTime $date,
    int $branchId,
    array &$rows,
    array &$customers,
    array &$customerRecords,
    array &$customerIds,
    array &$customerVisits,
    int &$customerId,
    array &$usedNames,
    array &$usedPhones,
    array $firstNames,
    array $lastNames,
    array $companyRoots,
    array $companyTypes,
    array $streets,
    array $barangays,
    array $cities
) {
    [$name, $type] = make_customer_name($usedNames, $firstNames, $lastNames, $companyRoots, $companyTypes);
    $phone = make_phone($usedPhones);
    $city = pick($cities);
    $address = mt_rand(10, 999) . ' ' . pick($streets) . ', ' . pick($barangays) . ', ' . $city . ', Negros Occidental';
    $createdAt = $date->format('Y-m-d') . ' ' . sprintf('%02d:%02d:00', mt_rand(8, 16), mt_rand(0, 59));
    $email = null;
    $branchSet = customer_branch_set($branchId);

    $id = $customerId++;
    $customerRecord = [
        'id' => $id,
        'name' => $name,
        'contact' => $phone,
        'email' => $email,
        'address' => $address,
        'city' => $city,
        'type' => $type,
        'primary_branch' => $branchId,
        'status' => 'active',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ];
    $customerRecords[$id] = $customerRecord;
    $customers[$id] = [
        'id' => $customerRecord['id'],
        'name' => $customerRecord['name'],
        'contact' => $customerRecord['contact'],
        'email' => $customerRecord['email'],
        'address' => $customerRecord['address'],
        'city' => $customerRecord['city'],
        'type' => $customerRecord['type'],
        'primary_branch' => $customerRecord['primary_branch'],
        'status' => $customerRecord['status'],
        'created_at' => $customerRecord['created_at'],
        'updated_at' => $customerRecord['updated_at'],
        'branch_set' => $branchSet,
    ];
    $customerIds[] = $id;
    $customerVisits[$id] = 0;

    $year = (int) $date->format('Y');
    $rows[$year]['customers'][] = sql_tuple([
        $id,
        $name,
        $phone,
        $email,
        $address,
        $city,
        $phone,
        null,
        $type,
        $branchId,
        'active',
        $createdAt,
        $createdAt,
    ]);

    return $id;
}

function create_previous_owner_customer(
    DateTime $date,
    int $branchId,
    array &$rows,
    array &$customerRecords,
    array &$branchRecordLatest,
    int &$customerId,
    int &$branchRecordId,
    array &$usedNames,
    array &$usedPhones,
    array $firstNames,
    array $lastNames,
    array $companyRoots,
    array $companyTypes,
    array $streets,
    array $barangays,
    array $cities,
    array $branches
) {
    [$name, $type] = make_customer_name($usedNames, $firstNames, $lastNames, $companyRoots, $companyTypes);
    $phone = make_phone($usedPhones);
    $city = pick($cities);
    $address = mt_rand(10, 999) . ' ' . pick($streets) . ', ' . pick($barangays) . ', ' . $city . ', Negros Occidental';
    $createdDate = max('2023-01-01', (clone $date)->modify('-' . mt_rand(3, 21) . ' days')->format('Y-m-d'));
    $createdAt = $createdDate . ' ' . sprintf('%02d:%02d:00', mt_rand(8, 16), mt_rand(0, 59));
    $year = (int) substr($createdAt, 0, 4);
    $branch = $branches[$branchId];

    $id = $customerId++;
    $customerRecords[$id] = [
        'id' => $id,
        'name' => $name,
        'contact' => $phone,
        'email' => null,
        'address' => $address,
        'city' => $city,
        'type' => $type,
        'primary_branch' => $branchId,
        'status' => 'active',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ];

    $rows[$year]['customers'][] = sql_tuple([
        $id,
        $name,
        $phone,
        null,
        $address,
        $city,
        $phone,
        null,
        $type,
        $branchId,
        'active',
        $createdAt,
        $createdAt,
    ]);

    $branchKey = $id . '-' . $branchId;
    $branchRecordLatest[$branchKey] = [
        'id' => $branchRecordId++,
        'customer_id' => $id,
        'branch_id' => $branchId,
        'status' => 'active',
        'sales_in_charge' => $branch['sales'],
        'sales_branch_label' => $branch['label'],
        'last_visit_at' => $createdAt,
        'created_by' => $branch['user_id'],
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
        'year' => $year,
    ];

    return $id;
}

function create_vehicle(
    int $customerId,
    DateTime $date,
    int $branchId,
    array &$rows,
    array &$vehicles,
    array &$vehiclesByCustomer,
    array &$customerRecords,
    array &$branchRecordLatest,
    int &$vehicleId,
    int &$customerIdCounter,
    int &$branchRecordId,
    array &$usedPlates,
    array &$usedNames,
    array &$usedPhones,
    array $vehicleModels,
    array $colors,
    array $firstNames,
    array $lastNames,
    array $companyRoots,
    array $companyTypes,
    array $streets,
    array $barangays,
    array $cities,
    array $branches
) {
    [$make, $model] = pick($vehicleModels);
    $yearMade = mt_rand(2012, min(2026, (int) $date->format('Y')));
    $plate = make_plate($usedPlates);
    $mileage = mt_rand(18000, 145000);
    $createdAt = $date->format('Y-m-d') . ' ' . sprintf('%02d:%02d:00', mt_rand(8, 17), mt_rand(0, 59));
    $vehicleAge = max(0, (int) $date->format('Y') - $yearMade);
    $previousOwnerChance = $vehicleAge >= 8 ? 45 : ($vehicleAge >= 4 ? 30 : 10);
    $previousOwnerId = null;
    $previousOwnedFrom = null;
    $previousOwnedUntil = null;
    $currentOwnedFrom = $date->format('Y-m-d');

    if (mt_rand(1, 100) <= $previousOwnerChance) {
        $transferDate = (clone $date)->modify('-' . mt_rand(7, max(14, min(540, max(7, $vehicleAge * 70)))) . ' days');
        if ($transferDate->format('Y-m-d') < '2023-01-01') {
            $transferDate = new DateTime('2023-01-01');
        }

        $previousOwnerId = create_previous_owner_customer(
            $transferDate,
            $branchId,
            $rows,
            $customerRecords,
            $branchRecordLatest,
            $customerIdCounter,
            $branchRecordId,
            $usedNames,
            $usedPhones,
            $firstNames,
            $lastNames,
            $companyRoots,
            $companyTypes,
            $streets,
            $barangays,
            $cities,
            $branches
        );

        $previousOwnedFromYear = max(2008, min($yearMade, ((int) $transferDate->format('Y')) - mt_rand(1, 4)));
        $previousOwnedFrom = sprintf('%04d-%02d-%02d', $previousOwnedFromYear, mt_rand(1, 12), mt_rand(1, 28));
        $previousOwnedUntil = (clone $transferDate)->modify('-1 day')->format('Y-m-d');
        if ($previousOwnedUntil < $previousOwnedFrom) {
            $previousOwnedUntil = $transferDate->format('Y-m-d');
        }
        $currentOwnedFrom = $transferDate->format('Y-m-d');
    } else {
        $firstOwnedYear = max(2008, min($yearMade, (int) $date->format('Y')));
        $currentOwnedFrom = sprintf('%04d-%02d-%02d', $firstOwnedYear, mt_rand(1, 12), mt_rand(1, 28));
        if ($currentOwnedFrom > $date->format('Y-m-d')) {
            $currentOwnedFrom = $date->format('Y-m-d');
        }
    }

    $id = $vehicleId++;
    $vehicles[$id] = [
        'id' => $id,
        'customer_id' => $customerId,
        'branch_id' => $branchId,
        'plate' => $plate,
        'make' => $make,
        'model' => $model,
        'year' => $yearMade,
        'color' => pick($colors),
        'last_service_date' => $date->format('Y-m-d'),
        'last_mileage' => $mileage,
        'created_at' => $createdAt,
        'previous_owner_id' => $previousOwnerId,
        'previous_owned_from' => $previousOwnedFrom,
        'previous_owned_until' => $previousOwnedUntil,
        'current_owned_from' => $currentOwnedFrom,
        'created_by' => $branches[$branchId]['user_id'],
    ];
    $vehiclesByCustomer[$customerId][] = $id;

    return $id;
}

function select_vehicle_for_customer(
    int $customerId,
    DateTime $date,
    int $branchId,
    array &$rows,
    array &$vehicles,
    array &$vehiclesByCustomer,
    array &$customerRecords,
    array &$branchRecordLatest,
    int &$vehicleId,
    int &$customerIdCounter,
    int &$branchRecordId,
    array &$usedPlates,
    array &$usedNames,
    array &$usedPhones,
    array $vehicleModels,
    array $colors,
    array $firstNames,
    array $lastNames,
    array $companyRoots,
    array $companyTypes,
    array $streets,
    array $barangays,
    array $cities,
    array $branches,
    int $visitCount
) {
    if (empty($vehiclesByCustomer[$customerId])) {
        return create_vehicle($customerId, $date, $branchId, $rows, $vehicles, $vehiclesByCustomer, $customerRecords, $branchRecordLatest, $vehicleId, $customerIdCounter, $branchRecordId, $usedPlates, $usedNames, $usedPhones, $vehicleModels, $colors, $firstNames, $lastNames, $companyRoots, $companyTypes, $streets, $barangays, $cities, $branches);
    }

    if ($visitCount > 3 && count($vehiclesByCustomer[$customerId]) < 3 && mt_rand(1, 100) <= 8) {
        return create_vehicle($customerId, $date, $branchId, $rows, $vehicles, $vehiclesByCustomer, $customerRecords, $branchRecordLatest, $vehicleId, $customerIdCounter, $branchRecordId, $usedPlates, $usedNames, $usedPhones, $vehicleModels, $colors, $firstNames, $lastNames, $companyRoots, $companyTypes, $streets, $barangays, $cities, $branches);
    }

    return pick($vehiclesByCustomer[$customerId]);
}

$start = new DateTime('2023-01-01');
$end = new DateTime($seedEndDate);
$period = new DatePeriod($start, new DateInterval('P1D'), (clone $end)->modify('+1 day'));

foreach ($period as $date) {
    $year = (int) $date->format('Y');
    $dayKey = $date->format('Y-m-d');
    $dailySequence[$dayKey] = 0;

    $weekday = (int) $date->format('N');
    $recordCount = mt_rand(5, 10);
    if ($weekday === 6) {
        $recordCount = min(10, $recordCount + 1);
    }
    if ($weekday === 7) {
        $recordCount = max(5, $recordCount - 1);
    }

    for ($i = 1; $i <= $recordCount; $i++) {
        $dailySequence[$dayKey]++;
        $branchId = (int) weighted_pick([1 => 35, 2 => 33, 3 => 32]);
        $eligible = customer_index_for_branch($customers, $branchId);
        $reuseExisting = count($eligible) > 25 && mt_rand(1, 100) <= 62;
        if ($reuseExisting) {
            $customerIdSelected = pick($eligible);
        } else {
            $customerIdSelected = create_customer($date, $branchId, $rows, $customers, $customerRecords, $customerIds, $customerVisits, $customerId, $usedNames, $usedPhones, $firstNames, $lastNames, $companyRoots, $companyTypes, $streets, $barangays, $cities);
        }

        $customerVisits[$customerIdSelected]++;
        $vehicleIdSelected = select_vehicle_for_customer($customerIdSelected, $date, $branchId, $rows, $vehicles, $vehiclesByCustomer, $customerRecords, $branchRecordLatest, $vehicleId, $customerId, $branchRecordId, $usedPlates, $usedNames, $usedPhones, $vehicleModels, $colors, $firstNames, $lastNames, $companyRoots, $companyTypes, $streets, $barangays, $cities, $branches, $customerVisits[$customerIdSelected]);

        $visitTime = next_time_for_day($date, $dailySequence[$dayKey]);
        $customer = $customers[$customerIdSelected];
        $vehicle = $vehicles[$vehicleIdSelected];
        $branch = $branches[$branchId];
        $salesName = $branch['sales'];

        $services = choose_services($serviceCatalog);
        array_unshift($services, ['name' => 'Service Inspection', 'category' => 'Inspection', 'price' => 450, 'duration' => '15 minutes', 'quantity' => 1]);
        $parts = choose_parts($partsCatalog, $services);

        $quotationStatus = quotation_status_for($date);
        $laborCost = 0;
        $partsCost = 0;
        $tiresCost = 0;
        $itemIdsForJob = [];
        $serviceNames = [];

        $quoteNo = 'Q' . $date->format('Ymd') . '-' . str_pad((string) $dailySequence[$dayKey], 3, '0', STR_PAD_LEFT);
        $quoteCreatedAt = $visitTime;
        $validUntil = (clone $date)->modify('+14 days')->format('Y-m-d');
        $complaint = pick($concerns);
        $finding = pick($findings);
        $recommendation = pick($recommendations);
        $inspectionMileage = $vehicles[$vehicleIdSelected]['last_mileage'] + mt_rand(35, 420);

        $currentQuotationId = $quotationId++;
        foreach ($services as $svc) {
            $qty = max(1, (int) ($svc['quantity'] ?? 1));
            $price = (float) $svc['price'];
            $laborCost += $qty * $price;
            $serviceNames[] = $svc['name'];
            $currentItemId = $quotationItemId++;
            $itemIdsForJob[] = [$currentItemId, $svc['name'], 'service', $qty];
            $rows[$year]['quotation_items'][] = sql_tuple([
                $currentItemId,
                $currentQuotationId,
                $svc['name'],
                $svc['category'],
                'service',
                $qty,
                number_format($price, 2, '.', ''),
                'own_inventory',
                null,
                $quoteCreatedAt,
            ]);
        }

        foreach ($parts as $part) {
            $qty = max(1, (int) ($part['quantity'] ?? 1));
            $price = (float) $part['price'];
            if ($part['type'] === 'tire') {
                $tiresCost += $qty * $price;
            } else {
                $partsCost += $qty * $price;
            }
            $currentItemId = $quotationItemId++;
            $itemIdsForJob[] = [$currentItemId, $part['name'], $part['type'], $qty];
            $rows[$year]['quotation_items'][] = sql_tuple([
                $currentItemId,
                $currentQuotationId,
                $part['name'],
                $part['category'],
                $part['type'],
                $qty,
                number_format($price, 2, '.', ''),
                $branchId === 1 ? 'external' : 'own_inventory',
                null,
                $quoteCreatedAt,
            ]);
        }

        $totalAmount = $laborCost + $partsCost + $tiresCost;
        $quoteNotes = 'Sales in charge: ' . $salesName . '; Service notes: ' . $recommendation;
        $rows[$year]['quotations'][] = sql_tuple([
            $currentQuotationId,
            $quoteNo,
            $customerIdSelected,
            $vehicleIdSelected,
            $branchId,
            $date->format('Y-m-d'),
            number_format($laborCost, 2, '.', ''),
            number_format($partsCost, 2, '.', ''),
            number_format($tiresCost, 2, '.', ''),
            '0.00',
            number_format($totalAmount, 2, '.', ''),
            $quotationStatus,
            $quoteNotes,
            $validUntil,
            $complaint,
            $finding,
            $recommendation,
            $inspectionMileage,
            $branch['user_id'],
            $quoteCreatedAt,
            $quoteCreatedAt,
        ]);

        $jobStatus = job_status_for($date, $quotationStatus);
        $jobOrderIdCurrent = null;
        $selectedTechnicians = [];
        if ($jobStatus !== null) {
            $selectedTechnicians = choose_technicians($branch['technicians']);
            $technicianNames = implode(', ', array_map(fn($t) => $t[1], $selectedTechnicians));
            $firstTechId = $selectedTechnicians[0][0] ?? null;
            $jobOrderIdCurrent = $jobOrderId++;
            $jobNo = 'JO' . $date->format('Ymd') . '-' . str_pad((string) $dailySequence[$dayKey], 3, '0', STR_PAD_LEFT);
            $startTime = substr($visitTime, 11, 8);
            $endHour = min(18, ((int) substr($startTime, 0, 2)) + mt_rand(1, 4));
            $endTime = sprintf('%02d:%02d:00', $endHour, (int) substr($startTime, 3, 2));
            $actualStart = in_array($jobStatus, ['in-progress', 'completed'], true) ? $date->format('Y-m-d') . ' ' . $startTime : null;
            $actualEnd = $jobStatus === 'completed' ? $date->format('Y-m-d') . ' ' . $endTime : null;
            $jobNotes = 'Sales in charge: ' . $salesName . '; Technician assigned: ' . $technicianNames . '; Service notes: ' . $recommendation;

            $rows[$year]['job_orders'][] = sql_tuple([
                $jobOrderIdCurrent,
                $jobNo,
                $customerIdSelected,
                $vehicleIdSelected,
                $technicianNames,
                $branchId,
                $currentQuotationId,
                $date->format('Y-m-d'),
                $startTime,
                $endTime,
                combine_durations($services),
                $actualStart,
                $actualEnd,
                $jobStatus,
                $firstTechId,
                $jobNotes,
                $branch['user_id'],
                $quoteCreatedAt,
                $actualEnd ?: $quoteCreatedAt,
            ]);

            $totalTasks = count($itemIdsForJob);
            $doneTarget = 0;
            if ($jobStatus === 'completed') {
                $doneTarget = $totalTasks;
            } elseif ($jobStatus === 'in-progress') {
                $doneTarget = max(1, min($totalTasks - 1, mt_rand(1, max(1, $totalTasks - 1))));
            }

            $taskIndex = 0;
            foreach ($itemIdsForJob as [$qiId, $taskName, $taskType, $qty]) {
                $taskIndex++;
                $done = $taskIndex <= $doneTarget ? 1 : 0;
                $completedAt = $done ? ($actualEnd ?: $date->format('Y-m-d') . ' ' . sprintf('%02d:%02d:00', mt_rand(10, 17), mt_rand(0, 59))) : null;
                $rows[$year]['job_order_progress'][] = sql_tuple([
                    $progressId++,
                    $jobOrderIdCurrent,
                    $qiId,
                    'qi:' . $qiId,
                    $taskName,
                    $taskType,
                    $qty,
                    $done,
                    $completedAt,
                    $done ? $branch['user_id'] : null,
                    $quoteCreatedAt,
                    $completedAt ?: $quoteCreatedAt,
                ]);
            }

            if ($jobStatus === 'completed') {
                $historyDate = $date->format('Y-m-d');
                $historyNotes = 'Sales in charge: ' . $salesName . '; Technician assigned: ' . $technicianNames . '; Service notes: ' . $recommendation;
                $rows[$year]['service_history'][] = sql_tuple([
                    $historyId++,
                    $customerIdSelected,
                    $vehicleIdSelected,
                    $branchId,
                    $historyDate,
                    implode(', ', $serviceNames),
                    number_format($totalAmount, 2, '.', ''),
                    $inspectionMileage,
                    $jobOrderIdCurrent,
                    $currentQuotationId,
                    $historyNotes,
                    $actualEnd ?: $quoteCreatedAt,
                ]);
            }
        }

        $visitType = $jobStatus === 'completed' ? 'service' : 'quotation';
        $rows[$year]['customer_visits'][] = sql_tuple([
            $visitId++,
            $customerIdSelected,
            $branchId,
            $date->format('Y-m-d'),
            $visitType,
            'Sales in charge: ' . $salesName . '; Service notes: ' . $recommendation,
            $branch['user_id'],
            $quoteCreatedAt,
        ]);

        $branchKey = $customerIdSelected . '-' . $branchId;
        if (!isset($branchRecordLatest[$branchKey]) || $branchRecordLatest[$branchKey]['last_visit_at'] < $quoteCreatedAt) {
            $branchRecordLatest[$branchKey] = [
                'id' => $branchRecordId++,
                'customer_id' => $customerIdSelected,
                'branch_id' => $branchId,
                'status' => 'active',
                'sales_in_charge' => $salesName,
                'sales_branch_label' => $branch['label'],
                'last_visit_at' => $quoteCreatedAt,
                'created_by' => $branch['user_id'],
                'created_at' => $quoteCreatedAt,
                'updated_at' => $quoteCreatedAt,
                'year' => $year,
            ];
        }

        $vehicles[$vehicleIdSelected]['last_service_date'] = $date->format('Y-m-d');
        $vehicles[$vehicleIdSelected]['last_mileage'] = max($vehicles[$vehicleIdSelected]['last_mileage'], $inspectionMileage);
    }
}

foreach ($vehicles as $vehicle) {
    $year = (int) substr($vehicle['created_at'], 0, 4);
    $rows[$year]['vehicles'][] = sql_tuple([
        $vehicle['id'],
        $vehicle['customer_id'],
        $vehicle['branch_id'],
        $vehicle['plate'],
        'VIN' . str_pad((string) $vehicle['id'], 6, '0', STR_PAD_LEFT),
        'good',
        $vehicle['make'],
        $vehicle['model'],
        $vehicle['year'],
        $vehicle['color'],
        $vehicle['last_service_date'],
        $vehicle['last_mileage'],
        'active',
        $vehicle['created_at'],
        $vehicle['created_at'],
    ]);

    if (!empty($vehicle['previous_owner_id'])) {
        $rows[$year]['vehicle_ownership_history'][] = sql_tuple([
            $ownershipId++,
            $vehicle['id'],
            $vehicle['previous_owner_id'],
            $vehicle['previous_owned_from'],
            $vehicle['previous_owned_until'],
            0,
            'Previous owner before second-hand purchase',
            $vehicle['created_by'],
            $vehicle['created_at'],
            $vehicle['created_at'],
        ]);
    }

    $rows[$year]['vehicle_ownership_history'][] = sql_tuple([
        $ownershipId++,
        $vehicle['id'],
        $vehicle['customer_id'],
        $vehicle['current_owned_from'],
        null,
        1,
        !empty($vehicle['previous_owner_id']) ? 'Current owner after second-hand purchase' : 'First recorded owner',
        $vehicle['created_by'],
        $vehicle['created_at'],
        $vehicle['created_at'],
    ]);
}

foreach ($branchRecordLatest as $record) {
    $year = (int) $record['year'];
    $rows[$year]['customer_branch_records'][] = sql_tuple([
        $record['id'],
        $record['customer_id'],
        $record['branch_id'],
        $record['status'],
        $record['sales_in_charge'],
        $record['sales_branch_label'],
        $record['last_visit_at'],
        $record['created_by'],
        $record['created_at'],
        $record['updated_at'],
    ]);
}

$seedSummary = validate_customer_vehicle_seed($customerRecords, $vehicles, $vehiclesByCustomer, $rows, array_keys($branches));

$files = [
    2023 => $outputDir . DIRECTORY_SEPARATOR . 'hwtires_customer_records_seed_2023_reset.sql',
    2024 => $outputDir . DIRECTORY_SEPARATOR . 'hwtires_customer_records_seed_2024_append.sql',
    2025 => $outputDir . DIRECTORY_SEPARATOR . 'hwtires_customer_records_seed_2025_append.sql',
    2026 => $outputDir . DIRECTORY_SEPARATOR . 'hwtires_customer_records_seed_2026_append.sql',
];

$columns = [
    'customers' => ['id', 'name', 'contact', 'email', 'address', 'city', 'phone_mobile', 'phone_work', 'customer_type', 'branch_id', 'status', 'created_at', 'updated_at'],
    'vehicles' => ['id', 'customer_id', 'branch_id', 'plate_number', 'vin', 'condition', 'make', 'model', 'year', 'color', 'last_service_date', 'last_mileage', 'status', 'created_at', 'updated_at'],
    'vehicle_ownership_history' => ['id', 'vehicle_id', 'customer_id', 'owned_from', 'owned_until', 'is_current', 'transfer_notes', 'created_by', 'created_at', 'updated_at'],
    'customer_branch_records' => ['id', 'customer_id', 'branch_id', 'status', 'sales_in_charge', 'sales_branch_label', 'last_visit_at', 'created_by', 'created_at', 'updated_at'],
    'customer_visits' => ['id', 'customer_id', 'branch_id', 'visit_date', 'visit_type', 'notes', 'created_by', 'created_at'],
    'quotations' => ['id', 'quotation_number', 'customer_id', 'vehicle_id', 'branch_id', 'quotation_date', 'labor_cost', 'parts_cost', 'tires_cost', 'tax_amount', 'total_amount', 'status', 'notes', 'valid_until', 'inspection_complaint', 'inspection_findings', 'inspection_recommendations', 'inspection_mileage', 'created_by', 'created_at', 'updated_at'],
    'quotation_items' => ['id', 'quotation_id', 'item_name', 'category', 'item_type', 'quantity', 'unit_price', 'source', 'notes', 'created_at'],
    'job_orders' => ['id', 'job_number', 'customer_id', 'vehicle_id', 'assigned_technician_name', 'branch_id', 'quotation_id', 'job_date', 'scheduled_start_time', 'scheduled_end_time', 'estimated_duration', 'actual_start_time', 'actual_end_time', 'status', 'assigned_technician_id', 'notes', 'created_by', 'created_at', 'updated_at'],
    'job_order_progress' => ['id', 'job_order_id', 'quotation_item_id', 'task_key', 'task_name', 'task_type', 'quantity', 'is_done', 'completed_at', 'updated_by', 'created_at', 'updated_at'],
    'service_history' => ['id', 'customer_id', 'vehicle_id', 'branch_id', 'service_date', 'services_description', 'total_cost', 'mileage_at_service', 'job_order_id', 'quotation_id', 'notes', 'created_at'],
];

foreach ($files as $year => $path) {
    $fh = fopen($path, 'wb');
    if (!$fh) {
        throw new RuntimeException("Unable to write $path");
    }

    fwrite($fh, "-- Highway Tires yearly customer/service operations seed for $year\n");
    fwrite($fh, "-- Generated " . date('Y-m-d H:i:s') . "\n");
    fwrite($fh, "USE hwtires;\n\n");

    if ($year === 2023) {
        fwrite($fh, "CREATE TABLE IF NOT EXISTS `job_order_progress` (\n");
        fwrite($fh, "  `id` INT PRIMARY KEY AUTO_INCREMENT,\n");
        fwrite($fh, "  `job_order_id` INT NOT NULL,\n");
        fwrite($fh, "  `quotation_item_id` INT NULL,\n");
        fwrite($fh, "  `task_key` VARCHAR(120) NOT NULL,\n");
        fwrite($fh, "  `task_name` VARCHAR(255) NOT NULL,\n");
        fwrite($fh, "  `task_type` VARCHAR(30) DEFAULT 'service',\n");
        fwrite($fh, "  `quantity` INT DEFAULT 1,\n");
        fwrite($fh, "  `is_done` TINYINT(1) NOT NULL DEFAULT 0,\n");
        fwrite($fh, "  `completed_at` DATETIME NULL,\n");
        fwrite($fh, "  `updated_by` INT NULL,\n");
        fwrite($fh, "  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n");
        fwrite($fh, "  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n");
        fwrite($fh, "  UNIQUE KEY `uniq_job_progress_task` (`job_order_id`, `task_key`),\n");
        fwrite($fh, "  INDEX `idx_job_progress_job` (`job_order_id`),\n");
        fwrite($fh, "  INDEX `idx_job_progress_done` (`is_done`),\n");
        fwrite($fh, "  CONSTRAINT `fk_seed_job_progress_job` FOREIGN KEY (`job_order_id`) REFERENCES `job_orders` (`id`) ON DELETE CASCADE,\n");
        fwrite($fh, "  CONSTRAINT `fk_seed_job_progress_item` FOREIGN KEY (`quotation_item_id`) REFERENCES `quotation_items` (`id`) ON DELETE SET NULL,\n");
        fwrite($fh, "  CONSTRAINT `fk_seed_job_progress_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL\n");
        fwrite($fh, ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\n");
        fwrite($fh, "CREATE TABLE IF NOT EXISTS `vehicle_ownership_history` (\n");
        fwrite($fh, "  `id` INT PRIMARY KEY AUTO_INCREMENT,\n");
        fwrite($fh, "  `vehicle_id` INT NOT NULL,\n");
        fwrite($fh, "  `customer_id` INT NOT NULL,\n");
        fwrite($fh, "  `owned_from` DATE NULL,\n");
        fwrite($fh, "  `owned_until` DATE NULL,\n");
        fwrite($fh, "  `is_current` TINYINT(1) NOT NULL DEFAULT 1,\n");
        fwrite($fh, "  `transfer_notes` TEXT NULL,\n");
        fwrite($fh, "  `created_by` INT NULL,\n");
        fwrite($fh, "  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n");
        fwrite($fh, "  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n");
        fwrite($fh, "  INDEX `idx_vehicle_ownership_vehicle` (`vehicle_id`),\n");
        fwrite($fh, "  INDEX `idx_vehicle_ownership_customer` (`customer_id`),\n");
        fwrite($fh, "  INDEX `idx_vehicle_ownership_current` (`vehicle_id`, `is_current`),\n");
        fwrite($fh, "  INDEX `idx_vehicle_ownership_dates` (`owned_from`, `owned_until`),\n");
        fwrite($fh, "  CONSTRAINT `fk_seed_vehicle_ownership_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE,\n");
        fwrite($fh, "  CONSTRAINT `fk_seed_vehicle_ownership_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,\n");
        fwrite($fh, "  CONSTRAINT `fk_seed_vehicle_ownership_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL\n");
        fwrite($fh, ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\n");
        fwrite($fh, "SET @sql := (SELECT IF(COUNT(*) = 0, 'ALTER TABLE `job_orders` ADD COLUMN `estimated_duration` VARCHAR(120) NULL AFTER `scheduled_end_time`', 'SELECT 1') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'job_orders' AND COLUMN_NAME = 'estimated_duration');\n");
        fwrite($fh, "PREPARE stmt FROM @sql;\nEXECUTE stmt;\nDEALLOCATE PREPARE stmt;\n\n");
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n");
        fwrite($fh, "TRUNCATE TABLE `job_order_progress`;\n");
        fwrite($fh, "SET @sql := (SELECT IF(COUNT(*) = 1, 'TRUNCATE TABLE `sms_outbox`', 'SELECT 1') FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sms_outbox');\n");
        fwrite($fh, "PREPARE stmt FROM @sql;\nEXECUTE stmt;\nDEALLOCATE PREPARE stmt;\n");
        fwrite($fh, "TRUNCATE TABLE `service_history`;\n");
        fwrite($fh, "TRUNCATE TABLE `job_orders`;\n");
        fwrite($fh, "TRUNCATE TABLE `quotation_items`;\n");
        fwrite($fh, "TRUNCATE TABLE `quotations`;\n");
        fwrite($fh, "TRUNCATE TABLE `customer_visits`;\n");
        fwrite($fh, "TRUNCATE TABLE `customer_branch_records`;\n");
        fwrite($fh, "TRUNCATE TABLE `vehicle_ownership_history`;\n");
        fwrite($fh, "TRUNCATE TABLE `vehicles`;\n");
        fwrite($fh, "TRUNCATE TABLE `customers`;\n");
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n\n");
    }

    write_insert($fh, 'customers', $columns['customers'], $rows[$year]['customers']);
    write_insert($fh, 'vehicles', $columns['vehicles'], $rows[$year]['vehicles']);
    write_insert($fh, 'vehicle_ownership_history', $columns['vehicle_ownership_history'], $rows[$year]['vehicle_ownership_history']);
    write_insert(
        $fh,
        'customer_branch_records',
        $columns['customer_branch_records'],
        $rows[$year]['customer_branch_records'],
        "ON DUPLICATE KEY UPDATE\n" .
        "  `status` = VALUES(`status`),\n" .
        "  `sales_in_charge` = VALUES(`sales_in_charge`),\n" .
        "  `sales_branch_label` = VALUES(`sales_branch_label`),\n" .
        "  `last_visit_at` = GREATEST(`last_visit_at`, VALUES(`last_visit_at`)),\n" .
        "  `updated_at` = VALUES(`updated_at`)"
    );
    write_insert($fh, 'customer_visits', $columns['customer_visits'], $rows[$year]['customer_visits']);
    write_insert($fh, 'quotations', $columns['quotations'], $rows[$year]['quotations']);
    write_insert($fh, 'quotation_items', $columns['quotation_items'], $rows[$year]['quotation_items']);
    write_insert($fh, 'job_orders', $columns['job_orders'], $rows[$year]['job_orders']);
    write_insert($fh, 'job_order_progress', $columns['job_order_progress'], $rows[$year]['job_order_progress']);
    write_insert($fh, 'service_history', $columns['service_history'], $rows[$year]['service_history']);

    fwrite($fh, "-- End of $year customer/service operations seed\n");
    fclose($fh);
}

foreach ($files as $year => $path) {
    $counts = [];
    foreach ($rows[$year] as $table => $tableRows) {
        $counts[] = $table . '=' . count($tableRows);
    }
    echo basename($path) . ': ' . implode(', ', $counts) . PHP_EOL;
}

echo 'Clean customer/vehicle summary: customers=' . $seedSummary['customers']
    . ', vehicles=' . $seedSummary['vehicles']
    . ', single_vehicle_customers=' . $seedSummary['single_vehicle_customers']
    . ', multi_vehicle_customers=' . $seedSummary['multi_vehicle_customers']
    . ', vehicles_with_previous_owner=' . $seedSummary['vehicles_with_previous_owner']
    . ', ownership_rows=' . $seedSummary['ownership_rows'] . PHP_EOL;
foreach ($seedSummary['branch_vehicle_counts'] as $branchId => $count) {
    echo ' - Branch ' . $branchId . ' vehicles=' . $count . PHP_EOL;
}
