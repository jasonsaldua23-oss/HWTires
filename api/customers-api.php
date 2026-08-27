<?php
/**
 * Customers API Handler
 * Handles CRUD operations via AJAX/Forms
 */

require_once __DIR__ . '/../includes/config.php';
session_name(SESSION_NAME);
session_start();

// Check authentication
if (!is_logged_in()) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$user = app_get_session_user();

// Get action
$action = $_POST['action'] ?? $_GET['action'] ?? null;

if (!function_exists('customers_normalize_phone')) {
    function customers_normalize_phone($phone) {
        return preg_replace('/\D+/', '', trim((string) $phone));
    }
}

if (!function_exists('customers_is_valid_ph_mobile')) {
    function customers_is_valid_ph_mobile($phone) {
        return preg_match('/^09\d{9}$/', (string) $phone) === 1;
    }
}

if (!function_exists('customers_valid_session_user_id')) {
    function customers_valid_session_user_id(array $user = null) {
        global $pdo;

        $user_id = (int) ($user['id'] ?? 0);
        if ($user_id <= 0) {
            return null;
        }

        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$user_id]);
            return $stmt->fetchColumn() ? $user_id : null;
        } catch (Exception $e) {
            return null;
        }
    }
}

// Handle Add Customer
if ($action === 'add') {
    try {
        enforce_modify_permission();

        // Validate CSRF
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        // Validate input
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone_mobile = customers_normalize_phone($_POST['phone_mobile'] ?? '');
        $contact = customers_normalize_phone($_POST['contact'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $customer_type = $_POST['customer_type'] ?? 'individual';
        $branch_id = intval($user['branch_id'] ?? 0);
        $vehicle_plate_number = app_normalize_plate_number($_POST['vehicle_plate_number'] ?? $_POST['plate_number'] ?? '');
        $vehicle_make = trim($_POST['vehicle_make'] ?? $_POST['make'] ?? '');
        $vehicle_model = trim($_POST['vehicle_model'] ?? $_POST['model'] ?? '');
        $vehicle_year = intval($_POST['vehicle_year'] ?? $_POST['year'] ?? 0);
        $vehicle_last_mileage = intval($_POST['vehicle_last_mileage'] ?? $_POST['last_mileage'] ?? 0);
        $vehicle_color = trim($_POST['vehicle_color'] ?? $_POST['color'] ?? '');
        $vehicle_vin = trim($_POST['vehicle_vin'] ?? $_POST['vin'] ?? '');
        $vehicle_condition = trim($_POST['vehicle_condition'] ?? $_POST['condition'] ?? 'good');
        if (!in_array($vehicle_condition, ['excellent', 'good', 'fair', 'poor'], true)) {
            $vehicle_condition = 'good';
        }
        $has_vehicle_details = $vehicle_plate_number !== ''
            || $vehicle_make !== ''
            || $vehicle_model !== ''
            || $vehicle_year > 0
            || $vehicle_last_mileage > 0
            || $vehicle_color !== ''
            || $vehicle_vin !== '';
        if ($contact === '') {
            $contact = $phone_mobile;
        }

        // Validate required fields
        if (empty($name)) {
            throw new Exception('Customer name is required');
        }
        if (empty($phone_mobile)) {
            throw new Exception('Contact number is required');
        }
        if (!customers_is_valid_ph_mobile($phone_mobile)) {
            throw new Exception('Contact number must be an 11-digit Philippine mobile number, e.g. 09171234567');
        }
        if ($contact !== '' && !customers_is_valid_ph_mobile($contact)) {
            throw new Exception('Contact number must be an 11-digit Philippine mobile number, e.g. 09171234567');
        }
        if ($branch_id <= 0) {
            throw new Exception('Invalid branch context for user');
        }

        // CHECK FOR DUPLICATE CUSTOMER
        $check_customer = $pdo->prepare("
            SELECT id, name, phone_mobile
            FROM customers
            WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))
            AND status = 'active'
            LIMIT 1
        ");
        $check_customer->execute([$name]);
        $existing_customer = $check_customer->fetch();

        if ($existing_customer) {
            throw new Exception(
                'Customer already exists: ' . esc_html($existing_customer['name']) .
                ' (' . esc_html($existing_customer['phone_mobile']) . '). ' .
                'To add a new vehicle for this customer, view their profile and add the vehicle there.'
            );
        }
        if ($has_vehicle_details && (empty($vehicle_plate_number) || empty($vehicle_make) || empty($vehicle_model) || $vehicle_last_mileage <= 0)) {
            throw new Exception('Vehicle plate number, make, model, and last mileage are required when adding vehicle information');
        }
        if ($vehicle_plate_number !== '' && !app_is_valid_plate_number($vehicle_plate_number)) {
            throw new Exception('Plate number must follow the ABC-1234 format');
        }
        $existing_vehicle_by_plate = null;
        if ($vehicle_plate_number !== '') {
            $existing_vehicle_by_plate = app_find_vehicle_by_plate($vehicle_plate_number);
            if ($existing_vehicle_by_plate && !app_vehicle_is_inactive($existing_vehicle_by_plate)) {
                throw new Exception('This plate number is already registered to an active vehicle. Please search the existing vehicle records first.');
            }
        }

        $created_vehicle_id = null;
        $vehicle_audit_action = 'create';
        $user_id = customers_valid_session_user_id($user);
        $pdo->beginTransaction();

        // Insert customer
        $stmt = $pdo->prepare("INSERT INTO customers (name, email, phone_mobile, contact, address, customer_type, branch_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')");
        $stmt->execute([$name, $email, $phone_mobile, $contact, $address, $customer_type, $branch_id]);

        $customer_id = $pdo->lastInsertId();
        app_touch_customer_branch_record($customer_id, $branch_id, $user_id);

        if ($has_vehicle_details) {
            if ($existing_vehicle_by_plate && app_vehicle_is_inactive($existing_vehicle_by_plate)) {
                $created_vehicle_id = app_restore_inactive_vehicle((int) $existing_vehicle_by_plate['id'], [
                    'customer_id' => $customer_id,
                    'branch_id' => $branch_id,
                    'plate_number' => $vehicle_plate_number,
                    'vin' => $vehicle_vin,
                    'condition' => $vehicle_condition,
                    'make' => $vehicle_make,
                    'model' => $vehicle_model,
                    'year' => $vehicle_year,
                    'color' => $vehicle_color,
                    'last_mileage' => $vehicle_last_mileage,
                    'created_by_user_id' => $user_id
                ]);
                $vehicle_audit_action = 'restore';
            } else {
                $vehicle_stmt = $pdo->prepare("
                    INSERT INTO vehicles (customer_id, branch_id, plate_number, vin, `condition`, make, model, year, color, last_mileage, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
                ");
                $vehicle_stmt->execute([
                    $customer_id,
                    $branch_id,
                    $vehicle_plate_number,
                    $vehicle_vin,
                    $vehicle_condition,
                    $vehicle_make,
                    $vehicle_model,
                    $vehicle_year ?: null,
                    $vehicle_color,
                    $vehicle_last_mileage
                ]);
                $created_vehicle_id = $pdo->lastInsertId();
            }
        }

        $pdo->commit();

        // Log audit
        log_audit('customers', 'create', $customer_id, null, [
            'name' => $name,
            'email' => $email,
            'type' => $customer_type
        ]);
        if ($created_vehicle_id) {
            log_audit('vehicles', $vehicle_audit_action, $created_vehicle_id, null, [
                'customer_id' => $customer_id,
                'plate_number' => $vehicle_plate_number,
                'make' => $vehicle_make,
                'model' => $vehicle_model
            ]);
        }

        set_flash_message('Customer added successfully', 'success');
        http_response_code(200);

        if (!empty($_POST['redirect'])) {
            redirect($_POST['redirect']);
        }

        die(json_encode(['success' => true, 'message' => 'Customer added successfully', 'id' => $customer_id]));

    } catch (Exception $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Add customer error: ' . $e->getMessage());
        set_flash_message($e->getMessage(), 'error');

        if (!empty($_POST['redirect'])) {
            redirect($_POST['redirect']);
        }

        http_response_code(400);
        die(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
}

// Handle Update Customer
if ($action === 'update') {
    try {
        enforce_modify_permission();

        if (($user['role'] ?? '') !== 'front-desk') {
            throw new Exception('Only front desk users can update customer records');
        }

        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        $customer_id = intval($_POST['id'] ?? 0);
        if ($customer_id <= 0) {
            throw new Exception('Invalid customer ID');
        }

        // Fetch old values for audit
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
        $stmt->execute([$customer_id]);
        $old_customer = $stmt->fetch();

        if (!$old_customer) {
            throw new Exception('Customer not found');
        }

        // Update customer
        $name = trim($_POST['name'] ?? $old_customer['name']);
        $email = trim($_POST['email'] ?? $old_customer['email']);
        $phone_mobile = customers_normalize_phone($_POST['phone_mobile'] ?? $old_customer['phone_mobile']);
        $contact = customers_normalize_phone($_POST['contact'] ?? $old_customer['contact']);
        $address = trim($_POST['address'] ?? $old_customer['address']);
        $customer_type = $_POST['customer_type'] ?? $old_customer['customer_type'];
        if ($contact === '') {
            $contact = $phone_mobile;
        }
        if ($phone_mobile === '' || !customers_is_valid_ph_mobile($phone_mobile)) {
            throw new Exception('Contact number must be an 11-digit Philippine mobile number, e.g. 09171234567');
        }
        if ($contact !== '' && !customers_is_valid_ph_mobile($contact)) {
            throw new Exception('Contact number must be an 11-digit Philippine mobile number, e.g. 09171234567');
        }

        $stmt = $pdo->prepare("UPDATE customers SET name = ?, email = ?, phone_mobile = ?, contact = ?, address = ?, customer_type = ? WHERE id = ?");
        $stmt->execute([$name, $email, $phone_mobile, $contact, $address, $customer_type, $customer_id]);

        // Log audit
        log_audit('customers', 'update', $customer_id, $old_customer, [
            'name' => $name,
            'email' => $email,
            'customer_type' => $customer_type
        ]);

        set_flash_message('Customer updated successfully', 'success');

        if (!empty($_POST['redirect'])) {
            redirect($_POST['redirect']);
        }

        die(json_encode(['success' => true, 'message' => 'Customer updated successfully']));

    } catch (Exception $e) {
        error_log('Update customer error: ' . $e->getMessage());
        set_flash_message($e->getMessage(), 'error');

        if (!empty($_POST['redirect'])) {
            redirect($_POST['redirect']);
        }

        http_response_code(400);
        die(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
}

// Handle Add Vehicle to Customer
if ($action === 'add_vehicle') {
    try {
        enforce_modify_permission();

        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        $customer_id = intval($_POST['customer_id'] ?? 0);
        if ($customer_id <= 0) {
            throw new Exception('Invalid customer ID');
        }

        $customer_stmt = $pdo->prepare("SELECT id FROM customers WHERE id = ? AND status = 'active'");
        $customer_stmt->execute([$customer_id]);
        $customer = $customer_stmt->fetch();
        if (!$customer) {
            throw new Exception('Customer not found');
        }

        $branch_id = intval($user['branch_id'] ?? 0);
        if ($branch_id <= 0) {
            throw new Exception('Invalid branch context for user');
        }

        // Validate input
        $plate_number = app_normalize_plate_number($_POST['plate_number'] ?? '');
        $make = trim($_POST['make'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $year = intval($_POST['year'] ?? 0);
        $color = trim($_POST['color'] ?? '');
        $condition = trim($_POST['condition'] ?? 'good');
        $last_mileage = intval($_POST['last_mileage'] ?? $_POST['vehicle_last_mileage'] ?? 0);

        if (empty($plate_number) || empty($make) || empty($model)) {
            throw new Exception('Please fill all required vehicle fields');
        }
        if (!app_is_valid_plate_number($plate_number)) {
            throw new Exception('Plate number must follow the ABC-1234 format');
        }

        // Check for duplicate plate number
        $existing_vehicle_by_plate = app_find_vehicle_by_plate($plate_number);
        if ($existing_vehicle_by_plate && !app_vehicle_is_inactive($existing_vehicle_by_plate)) {
            throw new Exception('This plate number is already registered to an active vehicle. Please search the existing vehicle records first.');
        }

        // Insert vehicle with optional user tracking when the column exists.
        $user_id = customers_valid_session_user_id($user);
        if ($existing_vehicle_by_plate && app_vehicle_is_inactive($existing_vehicle_by_plate)) {
            $pdo->beginTransaction();

            $vehicle_id = app_restore_inactive_vehicle((int) $existing_vehicle_by_plate['id'], [
                'customer_id' => $customer_id,
                'branch_id' => $branch_id,
                'plate_number' => $plate_number,
                'condition' => $condition,
                'make' => $make,
                'model' => $model,
                'year' => $year,
                'color' => $color,
                'last_mileage' => $last_mileage,
                'created_by_user_id' => $user_id
            ]);
            app_touch_customer_branch_record($customer_id, $branch_id, $user_id);

            $pdo->commit();

            log_audit('vehicles', 'restore', $vehicle_id, null, [
                'plate_number' => $plate_number,
                'make' => $make,
                'model' => $model,
                'added_by_branch' => $branch_id,
                'added_by_user' => $user_id
            ]);

            set_flash_message('Vehicle restored and added successfully', 'success');

            if (!empty($_POST['redirect'])) {
                redirect($_POST['redirect']);
            }

            die(json_encode(['success' => true, 'message' => 'Vehicle restored and added successfully', 'id' => $vehicle_id]));
        }

        $vehicle_columns = ['customer_id', 'branch_id', 'plate_number', '`condition`', 'make', 'model', 'year', 'color', 'last_mileage', 'status'];
        $vehicle_placeholders = ['?', '?', '?', '?', '?', '?', '?', '?', '?', "'active'"];
        $vehicle_values = [$customer_id, $branch_id, $plate_number, $condition, $make, $model, $year ?: null, $color, $last_mileage];

        if (app_column_exists('vehicles', 'created_by_user_id')) {
            $vehicle_columns[] = 'created_by_user_id';
            $vehicle_placeholders[] = '?';
            $vehicle_values[] = $user_id;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO vehicles (' . implode(', ', $vehicle_columns) . ') VALUES (' . implode(', ', $vehicle_placeholders) . ')'
        );
        $stmt->execute($vehicle_values);

        $vehicle_id = $pdo->lastInsertId();
        app_touch_customer_branch_record($customer_id, $branch_id, $user_id);

        // Log audit with branch and user info
        log_audit('vehicles', 'create', $vehicle_id, null, [
            'plate_number' => $plate_number,
            'make' => $make,
            'model' => $model,
            'added_by_branch' => $branch_id,
            'added_by_user' => $user_id
        ]);

        set_flash_message('Vehicle added successfully', 'success');

        if (!empty($_POST['redirect'])) {
            redirect($_POST['redirect']);
        }

        die(json_encode(['success' => true, 'message' => 'Vehicle added successfully', 'id' => $vehicle_id]));

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

// Handle Archive Customer
if ($action === 'delete' || $action === 'archive') {
    try {
        enforce_modify_permission();

        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        $customer_id = intval($_POST['id'] ?? 0);
        if ($customer_id <= 0) {
            throw new Exception('Invalid customer ID');
        }

        $customer_stmt = $pdo->prepare("SELECT id, name FROM customers WHERE id = ?");
        $customer_stmt->execute([$customer_id]);
        $customer = $customer_stmt->fetch();

        if (!$customer) {
            throw new Exception('Customer not found');
        }

        $pdo->beginTransaction();

        // Get all related records before archiving
        $vehicles_stmt = $pdo->prepare("SELECT id FROM vehicles WHERE customer_id = ?");
        $vehicles_stmt->execute([$customer_id]);
        $vehicle_ids = array_column($vehicles_stmt->fetchAll(), 'id');

        // Get all quotation IDs
        $quotations_stmt = $pdo->prepare("SELECT id FROM quotations WHERE customer_id = ?");
        $quotations_stmt->execute([$customer_id]);
        $quotation_ids = array_column($quotations_stmt->fetchAll(), 'id');

        // Get all job order IDs
        $jobs_stmt = $pdo->prepare("SELECT id FROM job_orders WHERE customer_id = ?");
        $jobs_stmt->execute([$customer_id]);
        $job_ids = array_column($jobs_stmt->fetchAll(), 'id');

        $archive_reason = app_archive_reason('Customer record archived');

        $vehicle_set = ["status = 'inactive'"];
        $vehicle_values = [];
        app_archive_metadata_update('vehicles', $vehicle_set, $vehicle_values, $archive_reason);
        if (app_column_exists('vehicles', 'updated_at')) {
            $vehicle_set[] = 'updated_at = NOW()';
        }
        $vehicle_values[] = $customer_id;
        $pdo->prepare('UPDATE vehicles SET ' . implode(', ', $vehicle_set) . ' WHERE customer_id = ?')->execute($vehicle_values);

        $branch_record_set = ["status = 'inactive'"];
        $branch_record_values = [];
        app_archive_metadata_update('customer_branch_records', $branch_record_set, $branch_record_values, $archive_reason);
        if (app_column_exists('customer_branch_records', 'updated_at')) {
            $branch_record_set[] = 'updated_at = NOW()';
        }
        $branch_record_values[] = $customer_id;
        $pdo->prepare('UPDATE customer_branch_records SET ' . implode(', ', $branch_record_set) . ' WHERE customer_id = ?')->execute($branch_record_values);

        $job_set = ["status = 'archived'"];
        $job_values = [];
        app_archive_metadata_update('job_orders', $job_set, $job_values, $archive_reason);
        if (app_column_exists('job_orders', 'updated_at')) {
            $job_set[] = 'updated_at = NOW()';
        }
        $job_values[] = $customer_id;
        $pdo->prepare('UPDATE job_orders SET ' . implode(', ', $job_set) . ' WHERE customer_id = ?')->execute($job_values);

        $quotation_set = ["status = 'archived'"];
        $quotation_values = [];
        app_archive_metadata_update('quotations', $quotation_set, $quotation_values, $archive_reason);
        if (app_column_exists('quotations', 'updated_at')) {
            $quotation_set[] = 'updated_at = NOW()';
        }
        $quotation_values[] = $customer_id;
        $pdo->prepare('UPDATE quotations SET ' . implode(', ', $quotation_set) . ' WHERE customer_id = ?')->execute($quotation_values);

        $customer_set = ["status = 'inactive'"];
        $customer_values = [];
        app_archive_metadata_update('customers', $customer_set, $customer_values, $archive_reason);
        if (app_column_exists('customers', 'updated_at')) {
            $customer_set[] = 'updated_at = NOW()';
        }
        $customer_values[] = $customer_id;
        $pdo->prepare('UPDATE customers SET ' . implode(', ', $customer_set) . ' WHERE id = ?')->execute($customer_values);

        $pdo->commit();

        // Log audit
        log_audit('customers', 'archive', $customer_id, $customer, [
            'name' => $customer['name'],
            'vehicles_archived' => count($vehicle_ids),
            'quotations_archived' => count($quotation_ids),
            'job_orders_archived' => count($job_ids),
            'records_preserved' => true
        ]);

        set_flash_message('Customer and related records archived successfully', 'success');

        if (!empty($_POST['redirect'])) {
            redirect($_POST['redirect']);
        }

        die(json_encode(['success' => true, 'message' => 'Customer archived successfully']));

    } catch (Exception $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Archive customer error: ' . $e->getMessage());
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
