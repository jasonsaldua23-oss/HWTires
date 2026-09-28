<?php
/**
 * Vehicles API Handler
 */

require_once __DIR__ . '/../includes/config.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$user = app_get_session_user();
$action = $_POST['action'] ?? $_GET['action'] ?? null;

// Handle Catalog Fetch
if ($action === 'get_catalog') {
    header('Content-Type: application/json');
    $custom_catalog = app_get_custom_vehicle_catalog();
    die(json_encode(['success' => true, 'catalog' => $custom_catalog]));
}

// Handle Add Vehicle
if ($action === 'add') {
    try {
        enforce_modify_permission();

        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        $customer_id = intval($_POST['customer_id'] ?? 0);
        $make = trim($_POST['make'] ?? $_POST['vehicle_make'] ?? '');
        if (strcasecmp($make, 'Other') === 0 && !empty($_POST['make_custom'] ?? $_POST['vehicle_make_custom'] ?? '')) {
            $make = trim($_POST['make_custom'] ?? $_POST['vehicle_make_custom'] ?? '');
        }
        $model = trim($_POST['model'] ?? $_POST['vehicle_model'] ?? '');
        if (strcasecmp($model, 'Other') === 0 && !empty($_POST['model_custom'] ?? $_POST['vehicle_model_custom'] ?? '')) {
            $model = trim($_POST['model_custom'] ?? $_POST['vehicle_model_custom'] ?? '');
        }

        $make = app_normalize_vehicle_catalog_text($make);
        $model = app_normalize_vehicle_catalog_text($model);
        $year = intval($_POST['year'] ?? 0);
        $vin = trim($_POST['vin'] ?? '');
        $plate_number = app_normalize_plate_number($_POST['plate_number'] ?? $_POST['license_plate'] ?? '');
        $condition = trim($_POST['condition'] ?? 'good');
        if (!in_array($condition, ['excellent', 'good', 'fair', 'poor'], true)) {
            $condition = 'good';
        }

        if ($customer_id <= 0) {
            throw new Exception('Customer is required');
        }

        $customer_stmt = $pdo->prepare("SELECT id, branch_id FROM customers WHERE id = ? AND status = 'active'");
        $customer_stmt->execute([$customer_id]);
        $customer = $customer_stmt->fetch();
        if (!$customer) {
            throw new Exception('Customer not found');
        }

        $branch_id = intval($_POST['branch_id'] ?? $user['branch_id'] ?? ($customer['branch_id'] ?? 0));
        if ($branch_id <= 0 && ($user['role'] ?? '') === 'admin') {
            $branch_id = 1;
        }

        if ($branch_id <= 0) throw new Exception('Invalid branch context for user');
        if (empty($make)) throw new Exception('Make is required');
        if (empty($model)) throw new Exception('Model is required');

        if ($plate_number !== '' && !app_is_valid_plate_number($plate_number)) {
            throw new Exception('Plate number must follow the ABC-1234 format');
        }
        $existing_vehicle_by_plate = null;
        if ($plate_number !== '') {
            $existing_vehicle_by_plate = app_find_vehicle_by_plate($plate_number);
            if ($existing_vehicle_by_plate && !app_vehicle_is_inactive($existing_vehicle_by_plate)) {
                throw new Exception('This plate number is already registered to an active vehicle. Please search the existing vehicle records first.');
            }
        }

        if ($existing_vehicle_by_plate && app_vehicle_is_inactive($existing_vehicle_by_plate)) {
            $restore_data = [
                'customer_id' => $customer_id,
                'branch_id' => $branch_id,
                'plate_number' => $plate_number,
                'vin' => $vin,
                'condition' => $condition,
                'make' => $make,
                'model' => $model,
                'year' => $year,
                'color' => $color,
                'created_by_user_id' => $user['id'] ?? null
            ];

            if (isset($_POST['last_mileage']) || isset($_POST['vehicle_last_mileage'])) {
                $restore_data['last_mileage'] = intval($_POST['last_mileage'] ?? $_POST['vehicle_last_mileage'] ?? 0);
            }

            $pdo->beginTransaction();
            $vehicle_id = app_restore_inactive_vehicle((int) $existing_vehicle_by_plate['id'], $restore_data);
            app_touch_customer_branch_record($customer_id, $branch_id, $user['id'] ?? null);
            $pdo->commit();

            if ($make !== '' && $model !== '') {
                app_persist_custom_vehicle_make_model($make, $model);
            }

            log_audit('vehicles', 'restore', $vehicle_id, null, [
                'customer_id' => $customer_id,
                'plate_number' => $plate_number,
                'make' => $make,
                'model' => $model
            ]);

            set_flash_message('Vehicle restored and added successfully', 'success');

            if (!empty($_POST['redirect'])) {
                redirect($_POST['redirect']);
            }

            die(json_encode(['success' => true, 'message' => 'Vehicle restored and added successfully', 'id' => $vehicle_id, 'custom_catalog' => app_get_custom_vehicle_catalog()]));
        }

        $stmt = $pdo->prepare("
            INSERT INTO vehicles (customer_id, branch_id, plate_number, vin, `condition`, make, model, year, color, last_mileage, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
        ");

        $stmt->execute([
            $customer_id,
            $branch_id,
            $plate_number !== '' ? $plate_number : null,
            $vin,
            $condition,
            $make,
            $model,
            $year ?: null,
            $color,
            $last_mileage
        ]);
        $vehicle_id = $pdo->lastInsertId();
        app_touch_customer_branch_record($customer_id, $branch_id, $user['id'] ?? null);

        if ($make !== '' && $model !== '') {
            app_persist_custom_vehicle_make_model($make, $model);
        }

        log_audit('vehicles', 'create', $vehicle_id, null, [
            'customer_id' => $customer_id,
            'plate_number' => $plate_number,
            'make' => $make,
            'model' => $model
        ]);

        set_flash_message('Vehicle added successfully', 'success');

        if (!empty($_POST['redirect'])) {
            redirect($_POST['redirect']);
        }

        die(json_encode(['success' => true, 'message' => 'Vehicle added', 'id' => $vehicle_id, 'custom_catalog' => app_get_custom_vehicle_catalog()]));

    } catch (Exception $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Add vehicle error: ' . $e->getMessage());
        set_flash_message($e->getMessage(), 'error');

        if (!empty($_POST['redirect'])) {
            redirect($_POST['redirect']);
        }

        http_response_code(400);
        die(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
}

// Handle Update Vehicle
if ($action === 'update') {
    try {
        enforce_modify_permission();

        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        $vehicle_id = intval($_POST['id'] ?? 0);
        $make = trim($_POST['make'] ?? $_POST['vehicle_make'] ?? '');
        if (strcasecmp($make, 'Other') === 0 && !empty($_POST['make_custom'] ?? $_POST['vehicle_make_custom'] ?? '')) {
            $make = trim($_POST['make_custom'] ?? $_POST['vehicle_make_custom'] ?? '');
        }
        $model = trim($_POST['model'] ?? $_POST['vehicle_model'] ?? '');
        if (strcasecmp($model, 'Other') === 0 && !empty($_POST['model_custom'] ?? $_POST['vehicle_model_custom'] ?? '')) {
            $model = trim($_POST['model_custom'] ?? $_POST['vehicle_model_custom'] ?? '');
        }
        $year = intval($_POST['year'] ?? 0);
        $vin = trim($_POST['vin'] ?? '');
        $plate_number = app_normalize_plate_number($_POST['plate_number'] ?? $_POST['license_plate'] ?? '');
        $condition = trim($_POST['condition'] ?? 'good');
        $color = trim($_POST['color'] ?? '');
        $last_mileage = intval($_POST['last_mileage'] ?? $_POST['mileage'] ?? $_POST['vehicle_last_mileage'] ?? 0);
        if (!in_array($condition, ['excellent', 'good', 'fair', 'poor'], true)) {
            $condition = 'good';
        }

        if ($vehicle_id <= 0) throw new Exception('Invalid vehicle ID');
        if (empty($make)) throw new Exception('Make is required');
        if (empty($model)) throw new Exception('Model is required');
        if ($plate_number !== '' && !app_is_valid_plate_number($plate_number)) {
            throw new Exception('Plate number must follow the ABC-1234 format');
        }

        // Get old vehicle
        $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ?");
        $stmt->execute([$vehicle_id]);
        $old_vehicle = $stmt->fetch();

        if (!$old_vehicle) throw new Exception('Vehicle not found');

        if ($plate_number !== '' && $plate_number !== ($old_vehicle['plate_number'] ?? '')) {
            $check_stmt = $pdo->prepare("SELECT id FROM vehicles WHERE plate_number = ? AND id <> ? LIMIT 1");
            $check_stmt->execute([$plate_number, $vehicle_id]);
            if ($check_stmt->fetch()) {
                throw new Exception('This plate number is already registered. Please search the existing vehicle records first.');
            }
        }

        $update_stmt = $pdo->prepare("
            UPDATE vehicles
            SET make = ?, model = ?, year = ?, vin = ?, plate_number = ?, `condition` = ?, color = ?, last_mileage = ?
            WHERE id = ?
        ");

        $update_stmt->execute([
            $make,
            $model,
            $year ?: null,
            $vin,
            $plate_number !== '' ? $plate_number : null,
            $condition,
            $color,
            $last_mileage > 0 ? $last_mileage : ($old_vehicle['last_mileage'] ?? 0),
            $vehicle_id
        ]);

        log_audit('vehicles', 'update', $vehicle_id,
            [
                'plate_number' => $old_vehicle['plate_number'] ?? null,
                'make' => $old_vehicle['make'],
                'model' => $old_vehicle['model']
            ],
            [
                'plate_number' => $plate_number,
                'make' => $make,
                'model' => $model
            ]
        );

        set_flash_message('Vehicle updated successfully', 'success');

        if (!empty($_POST['redirect'])) {
            redirect($_POST['redirect']);
        }

        die(json_encode(['success' => true, 'message' => 'Vehicle updated']));

    } catch (Exception $e) {
        error_log('Update vehicle error: ' . $e->getMessage());
        set_flash_message($e->getMessage(), 'error');

        if (!empty($_POST['redirect'])) {
            redirect($_POST['redirect']);
        }

        http_response_code(400);
        die(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
}

// Handle Restore Vehicle
if ($action === 'restore') {
    try {
        enforce_modify_permission();

        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        $vehicle_id = intval($_POST['id'] ?? $_POST['vehicle_id'] ?? 0);
        if ($vehicle_id <= 0) {
            throw new Exception('Invalid vehicle ID');
        }

        $vehicle_stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ? AND status = 'inactive'");
        $vehicle_stmt->execute([$vehicle_id]);
        $vehicle = $vehicle_stmt->fetch();
        if (!$vehicle) {
            throw new Exception('Archived vehicle not found');
        }

        enforce_branch_record_ownership($vehicle['branch_id'] ?? 0);

        $customer_id = intval($vehicle['customer_id'] ?? 0);
        $branch_id = intval($vehicle['branch_id'] ?? 0);
        if ($customer_id <= 0 || $branch_id <= 0) {
            throw new Exception('Vehicle restore is missing customer or branch details');
        }

        $pdo->beginTransaction();

        $restore_set = ["status = 'active'"];
        $restore_values = [];
        app_restore_metadata_update('vehicles', $restore_set, $restore_values);
        if (app_column_exists('vehicles', 'updated_at')) {
            $restore_set[] = 'updated_at = NOW()';
        }
        $restore_values[] = $vehicle_id;

        $restore_stmt = $pdo->prepare('UPDATE vehicles SET ' . implode(', ', $restore_set) . ' WHERE id = ?');
        $restore_stmt->execute($restore_values);

        app_touch_customer_branch_record($customer_id, $branch_id, $user['id'] ?? null);

        $pdo->commit();

        log_audit('vehicles', 'restore', $vehicle_id, $vehicle, [
            'status' => 'active',
            'customer_id' => $customer_id,
            'records_restored' => true
        ]);

        set_flash_message('Vehicle restored successfully', 'success');
    } catch (Exception $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Restore vehicle error: ' . $e->getMessage());
        set_flash_message($e->getMessage(), 'error');
    }

    redirect($_POST['redirect'] ?? $_SERVER['HTTP_REFERER'] ?? '/hwtires/front-desk/vehicles/');
}

// Handle Archive Vehicle
if ($action === 'delete' || $action === 'archive') {
    try {
        enforce_modify_permission();

        $is_post_delete = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        $csrf_token = $is_post_delete ? ($_POST['csrf_token'] ?? '') : ($_GET['csrf_token'] ?? '');
        if (!verify_csrf_token($csrf_token)) {
            throw new Exception('Invalid security token');
        }

        $vehicle_id = $is_post_delete
            ? intval($_POST['id'] ?? $_POST['vehicle_id'] ?? 0)
            : intval($_GET['id'] ?? $_GET['vehicle_id'] ?? 0);

        if ($vehicle_id <= 0) throw new Exception('Invalid vehicle ID');

        $vehicle_stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ? AND status = 'active'");
        $vehicle_stmt->execute([$vehicle_id]);
        $vehicle = $vehicle_stmt->fetch();
        if (!$vehicle) {
            throw new Exception('Vehicle not found');
        }

        enforce_branch_record_ownership($vehicle['branch_id'] ?? 0);

        $set = ["status = 'inactive'"];
        $values = [];
        app_archive_metadata_update('vehicles', $set, $values, 'Vehicle record archived');
        if (app_column_exists('vehicles', 'updated_at')) {
            $set[] = 'updated_at = NOW()';
        }
        $values[] = $vehicle_id;

        $stmt = $pdo->prepare('UPDATE vehicles SET ' . implode(', ', $set) . ' WHERE id = ?');
        $stmt->execute($values);

        log_audit('vehicles', 'archive', $vehicle_id, $vehicle, [
            'status' => 'inactive',
            'records_preserved' => true
        ]);

        set_flash_message('Vehicle archived successfully', 'success');

    } catch (Exception $e) {
        error_log('Archive vehicle error: ' . $e->getMessage());
        set_flash_message($e->getMessage(), 'error');
    }

    redirect($_POST['redirect'] ?? $_SERVER['HTTP_REFERER'] ?? '/hwtires/front-desk/vehicles/');
}

// Handle Get Customer Vehicles (AJAX for quotation form)
if ($action === 'get_customer_vehicles') {
    try {
        $customer_id = intval($_GET['customer_id'] ?? 0);

        if ($customer_id <= 0) {
            throw new Exception('Invalid customer ID');
        }

        $stmt = $pdo->prepare("
            SELECT id, customer_id, plate_number, make, model, year, vin, color, `condition`, last_mileage
            FROM vehicles
            WHERE customer_id = ? AND (status = 'active' OR status IS NULL)
            ORDER BY make, model
        ");
        $stmt->execute([$customer_id]);
        $vehicles = $stmt->fetchAll();

        header('Content-Type: application/json');
        die(json_encode(['success' => true, 'vehicles' => $vehicles]));

    } catch (Exception $e) {
        error_log('Get customer vehicles error: ' . $e->getMessage());
        header('Content-Type: application/json');
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
}

// Handle Transfer Vehicle Ownership
if ($action === 'transfer_ownership') {
    try {
        enforce_modify_permission();

        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        $vehicle_id = intval($_POST['vehicle_id'] ?? $_POST['id'] ?? 0);
        if ($vehicle_id <= 0) {
            throw new Exception('Invalid vehicle ID');
        }

        // Fetch vehicle record
        $veh_stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ?");
        $veh_stmt->execute([$vehicle_id]);
        $vehicle = $veh_stmt->fetch();
        if (!$vehicle) {
            throw new Exception('Vehicle not found');
        }
        if (($vehicle['status'] ?? 'active') !== 'active') {
            throw new Exception('Only active vehicles can undergo ownership transfer');
        }

        $old_customer_id = intval($vehicle['customer_id'] ?? 0);

        // Fetch current active ownership record
        $cur_owner_stmt = $pdo->prepare("
            SELECT id, customer_id, owned_from
            FROM vehicle_ownership_history
            WHERE vehicle_id = ? AND is_current = 1
            ORDER BY id DESC LIMIT 1
        ");
        $cur_owner_stmt->execute([$vehicle_id]);
        $current_ownership = $cur_owner_stmt->fetch();

        // 1. Check for unresolved Job Orders (HARD BLOCK)
        $active_job_stmt = $pdo->prepare("
            SELECT id, job_number, status
            FROM job_orders
            WHERE vehicle_id = ?
              AND status IN ('waiting', 'pending', 'in-progress')
            LIMIT 1
        ");
        $active_job_stmt->execute([$vehicle_id]);
        $active_job = $active_job_stmt->fetch();
        if ($active_job) {
            throw new Exception('Ownership transfer cannot proceed because this vehicle has an ongoing Job Order. Please complete the Job Order before transferring vehicle ownership.');
        }

        // 2. Validate transfer date
        $transfer_date = trim((string) ($_POST['transfer_date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $transfer_date)) {
            throw new Exception('Invalid transfer date format. Use YYYY-MM-DD');
        }
        $today = date('Y-m-d');
        if ($transfer_date > $today) {
            throw new Exception('Transfer date cannot be in the future');
        }
        if ($current_ownership && !empty($current_ownership['owned_from']) && $transfer_date < $current_ownership['owned_from']) {
            throw new Exception('Transfer date (' . $transfer_date . ') cannot be earlier than the current ownership start date (' . $current_ownership['owned_from'] . ').');
        }

        $transfer_notes = trim((string) ($_POST['transfer_notes'] ?? ''));
        $owner_mode = trim((string) ($_POST['owner_mode'] ?? 'existing'));
        $new_customer_id = 0;

        $user_branch_id = intval($user['branch_id'] ?? 0);
        if ($user_branch_id <= 0 && ($user['role'] ?? '') === 'admin') {
            $user_branch_id = intval($vehicle['branch_id'] ?? 1);
        }
        if ($user_branch_id <= 0) {
            $user_branch_id = 1;
        }

        $pdo->beginTransaction();

        if ($owner_mode === 'new') {
            // Option 2: Register New Customer
            $new_name = trim((string) ($_POST['new_customer_name'] ?? ''));
            $new_phone = preg_replace('/\D+/', '', trim((string) ($_POST['new_customer_phone'] ?? '')));
            $new_type = strtolower(trim((string) ($_POST['new_customer_type'] ?? 'individual')));
            if (!in_array($new_type, ['individual', 'corporate'], true)) {
                $new_type = 'individual';
            }

            if ($new_name === '') {
                throw new Exception('New customer name is required');
            }
            if ($new_phone === '') {
                throw new Exception('New customer contact number is required');
            }
            if (preg_match('/^09\d{9}$/', $new_phone) !== 1) {
                throw new Exception('Contact number must be an 11-digit Philippine mobile number, e.g. 09171234567');
            }

            // Check duplicate customer by name
            $check_cust_stmt = $pdo->prepare("
                SELECT id, name, phone_mobile
                FROM customers
                WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) AND status = 'active'
                LIMIT 1
            ");
            $check_cust_stmt->execute([$new_name]);
            $existing_match = $check_cust_stmt->fetch();
            if ($existing_match) {
                throw new Exception('A customer named "' . esc_html($existing_match['name']) . '" already exists (' . esc_html($existing_match['phone_mobile']) . '). Please choose "Select Existing Customer" instead.');
            }

            $reg = trim((string) ($_POST['new_address_region'] ?? ''));
            $prov = trim((string) ($_POST['new_address_province'] ?? ''));
            $city = trim((string) ($_POST['new_address_city'] ?? ''));
            $brgy = trim((string) ($_POST['new_address_barangay'] ?? ''));
            $street = trim((string) ($_POST['new_street_address'] ?? ''));

            if ($reg === '') throw new Exception('Region is required');
            if ($prov === '') throw new Exception('Province is required');
            if ($city === '') throw new Exception('City / Municipality is required');
            if ($brgy === '') throw new Exception('Barangay is required');

            $composed_address = app_compose_philippine_address($street, $brgy, $city, $prov, $reg);
            if (mb_strlen($composed_address, 'UTF-8') > 255) {
                throw new Exception('Full address exceeds 255 characters. Please shorten the street address.');
            }

            $insert_cust_stmt = $pdo->prepare("
                INSERT INTO customers (name, phone_mobile, contact, address, city, customer_type, branch_id, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            $insert_cust_stmt->execute([
                $new_name,
                $new_phone,
                $new_phone,
                $composed_address,
                $city,
                $new_type,
                $user_branch_id
            ]);
            $new_customer_id = (int) $pdo->lastInsertId();

        } else {
            // Option 1: Select Existing Customer
            $new_customer_id = intval($_POST['existing_customer_id'] ?? 0);
            if ($new_customer_id <= 0) {
                throw new Exception('Please select an existing customer');
            }
            if ($new_customer_id === $old_customer_id) {
                throw new Exception('Vehicle is already registered to this customer');
            }

            $check_new_cust = $pdo->prepare("SELECT id, name FROM customers WHERE id = ? AND status = 'active'");
            $check_new_cust->execute([$new_customer_id]);
            $new_customer_record = $check_new_cust->fetch();
            if (!$new_customer_record) {
                throw new Exception('Selected customer not found or inactive');
            }
        }

        if ($new_customer_id <= 0) {
            throw new Exception('Failed to determine new owner');
        }

        // 4. Update vehicle customer association ONLY (preserve original home branch_id)
        $upd_veh = $pdo->prepare("
            UPDATE vehicles
            SET customer_id = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $upd_veh->execute([$new_customer_id, $vehicle_id]);

        // 5. Close previous ownership record
        if ($current_ownership) {
            $close_stmt = $pdo->prepare("
                UPDATE vehicle_ownership_history
                SET is_current = 0,
                    owned_until = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $close_stmt->execute([$transfer_date, $current_ownership['id']]);
        } else {
            $close_all = $pdo->prepare("
                UPDATE vehicle_ownership_history
                SET is_current = 0,
                    owned_until = ?,
                    updated_at = NOW()
                WHERE vehicle_id = ? AND is_current = 1
            ");
            $close_all->execute([$transfer_date, $vehicle_id]);
        }

        // 6. Insert new current ownership record
        $effective_notes = $transfer_notes !== '' ? $transfer_notes : 'Vehicle ownership transferred to new owner';
        $ins_hist = $pdo->prepare("
            INSERT INTO vehicle_ownership_history (
                vehicle_id, customer_id, owned_from, owned_until, is_current, transfer_notes, created_by, created_at, updated_at
            ) VALUES (
                ?, ?, ?, NULL, 1, ?, ?, NOW(), NOW()
            )
        ");
        $ins_hist->execute([
            $vehicle_id,
            $new_customer_id,
            $transfer_date,
            $effective_notes,
            $user['id'] ?? null
        ]);

        // 7. Associate new customer with current servicing branch
        app_touch_customer_branch_record($new_customer_id, $user_branch_id, $user['id'] ?? null);

        // 8. Audit logging
        log_audit('vehicles', 'transfer_ownership', $vehicle_id,
            [
                'previous_customer_id' => $old_customer_id,
                'home_branch_id' => $vehicle['branch_id'] ?? null
            ],
            [
                'new_customer_id' => $new_customer_id,
                'transfer_date' => $transfer_date,
                'transfer_notes' => $effective_notes,
                'servicing_branch_id' => $user_branch_id
            ]
        );

        $pdo->commit();

        set_flash_message('Vehicle ownership transferred successfully', 'success');

        if (!empty($_POST['redirect'])) {
            redirect($_POST['redirect']);
        }

        die(json_encode([
            'success' => true,
            'message' => 'Vehicle ownership transferred successfully',
            'vehicle_id' => $vehicle_id,
            'customer_id' => $new_customer_id
        ]));

    } catch (Exception $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Transfer ownership error: ' . $e->getMessage());
        set_flash_message($e->getMessage(), 'error');

        if (!empty($_POST['redirect'])) {
            redirect($_POST['redirect']);
        }

        http_response_code(400);
        die(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
}

http_response_code(400);
die(json_encode(['success' => false, 'message' => 'Invalid action']));
?>
