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

            die(json_encode(['success' => true, 'message' => 'Vehicle restored and added successfully', 'id' => $vehicle_id]));
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

        die(json_encode(['success' => true, 'message' => 'Vehicle added', 'id' => $vehicle_id]));

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

http_response_code(400);
die(json_encode(['success' => false, 'message' => 'Invalid action']));
?>
