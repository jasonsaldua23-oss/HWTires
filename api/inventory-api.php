<?php
/**
 * Inventory API Handler
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

if (!function_exists('inventory_api_finish')) {
    function inventory_api_finish($success, $message, $status_code = 200, $payload = []) {
        if (!empty($_POST['redirect'])) {
            set_flash_message($message, $success ? 'success' : 'danger');
            redirect($_POST['redirect']);
        }

        http_response_code($status_code);
        header('Content-Type: application/json');
        die(json_encode(array_merge([
            'success' => $success,
            'message' => $message,
        ], $payload)));
    }
}

if (!function_exists('inventory_api_clean_text')) {
    function inventory_api_clean_text($value, $label, $max_length = 255, $required = false) {
        $raw_value = (string) $value;

        if ($raw_value !== '' && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $raw_value)) {
            throw new Exception($label . ' contains invalid characters');
        }

        $clean_value = trim(preg_replace('/[ \t\r\n]+/', ' ', $raw_value));

        if ($required && $clean_value === '') {
            throw new Exception($label . ' is required');
        }

        if ($clean_value !== '' && $max_length > 0 && strlen($clean_value) > $max_length) {
            throw new Exception($label . ' is too long');
        }

        return $clean_value;
    }
}

if (!function_exists('inventory_api_clean_code')) {
    function inventory_api_clean_code($value, $label, $max_length = 100, $required = false) {
        $clean_value = strtoupper(inventory_api_clean_text($value, $label, $max_length, $required));

        if ($clean_value !== '' && !preg_match('/^[A-Z0-9][A-Z0-9 ._\/-]*$/', $clean_value)) {
            throw new Exception($label . ' must contain only letters, numbers, spaces, dots, dashes, slashes, or underscores');
        }

        return $clean_value;
    }
}

if (!function_exists('inventory_api_clean_date')) {
    function inventory_api_clean_date($value, $label, $required = false) {
        $clean_value = trim((string) $value);

        if ($clean_value === '') {
            if ($required) {
                throw new Exception($label . ' is required');
            }

            return '';
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $clean_value)) {
            throw new Exception('Invalid ' . strtolower($label));
        }

        [$year, $month, $day] = array_map('intval', explode('-', $clean_value));
        if (!checkdate($month, $day, $year) || sprintf('%04d-%02d-%02d', $year, $month, $day) !== $clean_value) {
            throw new Exception('Invalid ' . strtolower($label));
        }

        if ($clean_value > date('Y-m-d')) {
            throw new Exception($label . ' cannot be in the future');
        }

        return $clean_value;
    }
}

if (!function_exists('inventory_api_clean_int')) {
    function inventory_api_clean_int($value, $label, $min = 0, $max = 100000) {
        $clean_value = trim((string) $value);

        if ($clean_value === '' || !preg_match('/^\d+$/', $clean_value)) {
            throw new Exception($label . ' must be a whole number');
        }

        $int_value = (int) $clean_value;
        if ($int_value < $min || $int_value > $max) {
            throw new Exception($label . ' must be between ' . $min . ' and ' . $max);
        }

        return $int_value;
    }
}

if (!function_exists('inventory_api_clean_money')) {
    function inventory_api_clean_money($value, $label, $required = false) {
        $clean_value = trim((string) $value);

        if ($clean_value === '') {
            if ($required) {
                throw new Exception($label . ' is required');
            }

            return 0.0;
        }

        if (!preg_match('/^\d+(\.\d{1,2})?$/', $clean_value)) {
            throw new Exception($label . ' must be a valid amount');
        }

        $amount = (float) $clean_value;
        if ($amount <= 0 || $amount > 9999999.99) {
            throw new Exception($label . ' must be greater than 0');
        }

        return $amount;
    }
}

if (!function_exists('inventory_api_ensure_unique_value')) {
    function inventory_api_ensure_unique_value($column, $value, $label) {
        global $pdo;

        $clean_value = trim((string) $value);
        if ($clean_value === '' || !app_column_exists('inventory_items', $column)) {
            return;
        }

        $quoted_column = app_quote_identifier($column);
        $stmt = $pdo->prepare("
            SELECT id
            FROM inventory_items
            WHERE $quoted_column = ?
              AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$clean_value]);

        if ($stmt->fetchColumn()) {
            throw new Exception($label . ' already exists in active inventory');
        }
    }
}

if (!function_exists('inventory_api_get_item')) {
    function inventory_api_get_item($item_id) {
        global $pdo;

        $stmt = $pdo->prepare("
            SELECT i.*, b.has_inventory, b.status AS branch_status, b.name AS branch_name
            FROM inventory_items i
            LEFT JOIN branches b ON b.id = i.branch_id
            WHERE i.id = ? AND i.status = 'active'
        ");
        $stmt->execute([$item_id]);
        $item = $stmt->fetch();

        if (!$item) {
            throw new Exception('Inventory item not found');
        }

        if ((int) ($item['has_inventory'] ?? 0) !== 1 || ($item['branch_status'] ?? '') !== 'active') {
            throw new Exception('Inventory is only available for active inventory branches');
        }

        if (!has_branch_access($item['branch_id'])) {
            throw new Exception('Unauthorized access');
        }

        return $item;
    }
}

if (!function_exists('inventory_api_log_transaction')) {
    function inventory_api_log_transaction($item_id, $transaction_type, $quantity, $notes, $reference_type = null, $reference_id = null, array $tags = []) {
        global $pdo, $user;

        $columns = ['item_id', 'transaction_type', 'quantity', 'reference_type', 'reference_id', 'notes', 'created_by'];
        $values = [
            $item_id,
            $transaction_type,
            $quantity,
            $reference_type,
            $reference_id,
            $notes,
            $user['id'] ?? null,
        ];

        foreach (['customer_id', 'vehicle_id', 'job_order_id', 'quotation_id', 'quotation_item_id'] as $tag_column) {
            if (!array_key_exists($tag_column, $tags) || !app_column_exists('inventory_transactions', $tag_column)) {
                continue;
            }

            $tag_value = $tags[$tag_column] !== null ? intval($tags[$tag_column]) : null;
            $columns[] = $tag_column;
            $values[] = $tag_value && $tag_value > 0 ? $tag_value : null;
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $stmt = $pdo->prepare("
            INSERT INTO inventory_transactions (" . implode(', ', $columns) . ")
            VALUES ($placeholders)
        ");
        $stmt->execute($values);
    }
}

if (!function_exists('inventory_api_resolve_stock_out_tag')) {
    function inventory_api_resolve_stock_out_tag($branch_id) {
        global $pdo;

        $branch_id = (int) $branch_id;
        $customer_id = intval($_POST['customer_id'] ?? 0);
        $vehicle_id = intval($_POST['vehicle_id'] ?? 0);

        if ($vehicle_id > 0) {
            $vehicle_stmt = $pdo->prepare("
                SELECT v.id, v.customer_id
                FROM vehicles v
                INNER JOIN customers c ON c.id = v.customer_id
                WHERE v.id = ?
                  AND v.status = 'active'
                  AND c.status = 'active'
                LIMIT 1
            ");
            $vehicle_stmt->execute([$vehicle_id]);
            $vehicle = $vehicle_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$vehicle) {
                throw new Exception('Selected vehicle is not available for tagging');
            }

            $vehicle_customer_id = (int) ($vehicle['customer_id'] ?? 0);
            if ($customer_id > 0 && $customer_id !== $vehicle_customer_id) {
                throw new Exception('Selected vehicle does not belong to the selected customer');
            }

            $customer_id = $vehicle_customer_id;
        }

        if ($customer_id > 0) {
            $customer_stmt = $pdo->prepare("
                SELECT c.id
                FROM customers c
                WHERE c.id = ?
                  AND c.status = 'active'
                LIMIT 1
            ");
            $customer_stmt->execute([$customer_id]);

            if (!$customer_stmt->fetchColumn()) {
                throw new Exception('Selected customer is not active or does not exist');
            }
        }

        return [
            'customer_id' => $customer_id > 0 ? $customer_id : null,
            'vehicle_id' => $vehicle_id > 0 ? $vehicle_id : null,
        ];
    }
}

if (!function_exists('inventory_api_normalized_key')) {
    function inventory_api_normalized_key($item) {
        $category = strtolower((string) ($item['category'] ?? ''));

        if ($category === 'tire' && !empty($item['size'])) {
            return 'tire:' . strtolower(trim((string) $item['size']));
        }

        return $category . ':' . preg_replace('/\s+/', ' ', strtolower(trim((string) ($item['item_name'] ?? ''))));
    }
}

if (!function_exists('inventory_api_get_transfer_target')) {
    function inventory_api_get_transfer_target($item_id) {
        global $pdo;

        $stmt = $pdo->prepare("
            SELECT i.*, b.has_inventory, b.status AS branch_status, b.name AS branch_name
            FROM inventory_items i
            LEFT JOIN branches b ON b.id = i.branch_id
            WHERE i.id = ? AND i.status = 'active'
        ");
        $stmt->execute([$item_id]);
        $item = $stmt->fetch();

        if (!$item) {
            throw new Exception('Transfer target item not found');
        }

        if ((int) ($item['has_inventory'] ?? 0) !== 1 || ($item['branch_status'] ?? '') !== 'active') {
            throw new Exception('Transfers are only available between active inventory branches');
        }

        return $item;
    }
}

if (!function_exists('inventory_api_notify_branch_transfer')) {
    function inventory_api_notify_branch_transfer($source, $target, $quantity) {
        global $pdo, $user;

        try {
            $target_branch_id = (int) ($target['branch_id'] ?? 0);
            if ($target_branch_id <= 0) {
                return;
            }

            $users_stmt = $pdo->prepare("
                SELECT id
                FROM users
                WHERE branch_id = ?
                  AND status = 'active'
            ");
            $users_stmt->execute([$target_branch_id]);
            $target_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($target_users)) {
                return;
            }

            $item_name = trim((string) ($target['item_name'] ?? $source['item_name'] ?? 'Inventory item'));
            $source_branch = trim((string) ($source['branch_name'] ?? 'another branch'));
            $title = 'Stock Transfer Received';
            $message = $quantity . ' unit(s) of ' . $item_name . ' received from ' . $source_branch . '.';
            $action_url = '/hwtires/front-desk/tire-inventory/?search=' . rawurlencode($item_name) . '#inventory-records';

            $notification_stmt = $pdo->prepare("
                INSERT INTO transfer_notifications
                    (branch_id, user_id, transfer_request_id, title, message, type, action_url)
                VALUES (?, ?, NULL, ?, ?, 'success', ?)
            ");

            foreach ($target_users as $target_user) {
                $notification_stmt->execute([
                    $target_branch_id,
                    (int) $target_user['id'],
                    $title,
                    $message,
                    $action_url,
                ]);
            }
        } catch (Exception $e) {
            error_log('Branch transfer notification error: ' . $e->getMessage());
        }
    }
}

// Handle Stock In
if ($action === 'stock_in') {
    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        if (($user['role'] ?? '') === 'admin') {
            throw new Exception('Admin inventory is view-only for stock movement. Use the front desk inventory screen for stock in/out.');
        }

        $item_id = intval($_POST['inventory_id'] ?? $_POST['item_id'] ?? 0);
        $quantity = inventory_api_clean_int($_POST['quantity'] ?? 0, 'Quantity', 1, 100000);
        $source_type = inventory_api_clean_text($_POST['source_type'] ?? 'supplier_delivery', 'Source Type', 50);
        $supplier_name = inventory_api_clean_text($_POST['supplier_name'] ?? '', 'Supplier Name', 150);
        $reference_number = inventory_api_clean_text($_POST['reference_number'] ?? '', 'Reference/DR Number', 100);
        $custom_notes = inventory_api_clean_text($_POST['notes'] ?? '', 'Notes', 500);

        if ($item_id <= 0) {
            throw new Exception('Invalid inventory item');
        }

        $item = inventory_api_get_item($item_id);
        $old_quantity = (int) $item['quantity'];
        $new_quantity = $old_quantity + $quantity;

        $note_parts = [];
        if ($source_type === 'tangub_warehouse') {
            $note_parts[] = 'Tangub Central Warehouse Delivery';
        } elseif ($source_type === 'sancarlos_warehouse') {
            $note_parts[] = 'San Carlos Warehouse Delivery';
        } elseif ($source_type === 'supplier_delivery') {
            $note_parts[] = 'Supplier Delivery (Manila Distributor)' . ($supplier_name !== '' ? ': ' . $supplier_name : '');
        } elseif ($source_type === 'branch_transfer') {
            $note_parts[] = 'Stock Transfer Received' . ($supplier_name !== '' ? ' from ' . $supplier_name : '');
        } elseif ($source_type === 'adjustment') {
            $note_parts[] = 'Physical Count / Inventory Adjustment';
        } elseif ($source_type !== '') {
            $note_parts[] = ucwords(str_replace('_', ' ', $source_type));
        }

        if ($reference_number !== '') {
            $note_parts[] = 'DR #: ' . $reference_number;
        }

        if ($custom_notes !== '' && $custom_notes !== 'Manual stock in from Front Desk Inventory') {
            $note_parts[] = 'Remarks: ' . $custom_notes;
        }

        $final_notes = !empty($note_parts) ? implode(' | ', $note_parts) : 'Manual stock in from Front Desk Inventory';

        $update_stmt = $pdo->prepare("UPDATE inventory_items SET quantity = ?, last_restock_date = CURDATE() WHERE id = ?");
        $update_stmt->execute([$new_quantity, $item_id]);

        if ($supplier_name !== '' && empty($item['supplier_name'])) {
            $sup_stmt = $pdo->prepare("UPDATE inventory_items SET supplier_name = ? WHERE id = ?");
            $sup_stmt->execute([$supplier_name, $item_id]);
        }

        inventory_api_log_transaction($item_id, 'stock_in', $quantity, $final_notes, $source_type ?: 'supplier_delivery', null);
        log_audit('inventory_items', 'stock_in', $item_id, ['quantity' => $old_quantity], [
            'quantity' => $new_quantity,
            'added_quantity' => $quantity,
            'item_name' => $item['item_name'] ?? null,
            'branch_id' => (int) ($item['branch_id'] ?? 0),
            'source_type' => $source_type,
            'supplier_name' => $supplier_name,
            'reference_number' => $reference_number,
        ]);

        inventory_api_finish(true, 'Stock added successfully (' . $quantity . ' units added)', 200, ['quantity' => $new_quantity]);
    } catch (Exception $e) {
        error_log('Stock in error: ' . $e->getMessage());
        inventory_api_finish(false, $e->getMessage(), 400);
    }
}

// Handle Branch Stock Transfer
if ($action === 'transfer_stock') {
    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        if (($user['role'] ?? '') === 'admin') {
            throw new Exception('Admin inventory is view-only for stock movement. Use the front desk inventory screen for branch transfers.');
        }

        $source_id = intval($_POST['source_item_id'] ?? 0);
        $target_id = intval($_POST['target_item_id'] ?? 0);
        $quantity = inventory_api_clean_int($_POST['quantity'] ?? 0, 'Transfer quantity', 1, 100000);
        $notes = inventory_api_clean_text($_POST['notes'] ?? '', 'Notes', 500);

        if ($source_id <= 0 || $target_id <= 0) {
            throw new Exception('Invalid transfer items');
        }

        if ($source_id === $target_id) {
            throw new Exception('Source and target item must be different');
        }

        $source = inventory_api_get_item($source_id);
        $target = inventory_api_get_transfer_target($target_id);

        if ((int) $source['branch_id'] === (int) $target['branch_id']) {
            throw new Exception('Branch transfer requires two different branches');
        }

        if (inventory_api_normalized_key($source) !== inventory_api_normalized_key($target)) {
            throw new Exception('Transfer items must match by category and item details');
        }

        $source_old_quantity = (int) $source['quantity'];
        $target_old_quantity = (int) $target['quantity'];

        if ($source_old_quantity < $quantity) {
            throw new Exception('Insufficient stock available. Current: ' . $source_old_quantity);
        }

        $source_new_quantity = $source_old_quantity - $quantity;
        $target_new_quantity = $target_old_quantity + $quantity;

        $pdo->beginTransaction();

        $source_update = $pdo->prepare("UPDATE inventory_items SET quantity = ? WHERE id = ?");
        $source_update->execute([$source_new_quantity, $source_id]);

        $target_update = $pdo->prepare("UPDATE inventory_items SET quantity = ?, last_restock_date = CURDATE() WHERE id = ?");
        $target_update->execute([$target_new_quantity, $target_id]);

        $source_note = $notes !== ''
            ? $notes
            : 'Branch transfer to ' . ($target['branch_name'] ?? 'target branch');
        $target_note = $notes !== ''
            ? $notes
            : 'Branch transfer from ' . ($source['branch_name'] ?? 'source branch');

        inventory_api_log_transaction($source_id, 'stock_out', $quantity, $source_note, 'branch_transfer', $target_id);
        inventory_api_log_transaction($target_id, 'stock_in', $quantity, $target_note, 'branch_transfer', $source_id);

        log_audit('inventory_items', 'inventory_transfer', $source_id, [
            'source_quantity' => $source_old_quantity,
            'target_quantity' => $target_old_quantity,
        ], [
            'source_quantity' => $source_new_quantity,
            'target_quantity' => $target_new_quantity,
            'quantity' => $quantity,
            'item_name' => $source['item_name'] ?? null,
            'source_item_id' => $source_id,
            'target_item_id' => $target_id,
            'from_branch_id' => (int) ($source['branch_id'] ?? 0),
            'to_branch_id' => (int) ($target['branch_id'] ?? 0),
        ]);

        inventory_api_notify_branch_transfer($source, $target, $quantity);

        $pdo->commit();

        inventory_api_finish(true, 'Stock transferred successfully', 200, [
            'source_quantity' => $source_new_quantity,
            'target_quantity' => $target_new_quantity,
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Stock transfer error: ' . $e->getMessage());
        inventory_api_finish(false, $e->getMessage(), 400);
    }
}

// Handle Stock Out
if ($action === 'stock_out') {
    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        if (($user['role'] ?? '') === 'admin') {
            throw new Exception('Admin inventory is view-only for stock movement. Use the front desk inventory screen for stock in/out.');
        }

        $item_id = intval($_POST['inventory_id'] ?? $_POST['item_id'] ?? 0);
        $quantity = inventory_api_clean_int($_POST['quantity'] ?? 0, 'Quantity', 1, 100000);
        $notes = inventory_api_clean_text($_POST['notes'] ?? '', 'Notes', 500);

        if ($item_id <= 0) {
            throw new Exception('Invalid inventory item');
        }

        $item = inventory_api_get_item($item_id);
        $old_quantity = (int) $item['quantity'];
        $tag_data = inventory_api_resolve_stock_out_tag((int) ($item['branch_id'] ?? 0));

        $reason_type = trim((string) ($_POST['reason_type'] ?? ''));
        $reason_labels = [
            'direct_sale' => 'Direct Sale / Walk-In',
            'damaged' => 'Damaged / Defective Stock',
            'shop_use' => 'Shop Internal Use',
            'other' => 'Inventory Adjustment',
        ];
        $reason_label = $reason_labels[$reason_type] ?? '';

        if ($reason_type === 'direct_sale' && empty($tag_data['customer_id'])) {
            throw new Exception('Direct Sale / Walk-In stock out requires selecting an active registered customer. Please register the customer first if they are not yet in the system.');
        }

        if ($old_quantity < $quantity) {
            throw new Exception('Insufficient stock available. Current: ' . $old_quantity);
        }

        $new_quantity = $old_quantity - $quantity;
        $update_stmt = $pdo->prepare("UPDATE inventory_items SET quantity = ? WHERE id = ?");
        $update_stmt->execute([$new_quantity, $item_id]);

        $note_parts = [];
        if ($reason_label !== '') {
            $note_parts[] = 'Reason: ' . $reason_label;
        }
        if ($notes !== '' && $notes !== 'Manual stock out from Front Desk Inventory') {
            $note_parts[] = 'Remarks: ' . $notes;
        }
        $final_notes = !empty($note_parts) ? implode(' | ', $note_parts) : ($notes ?: 'Manual stock out from Front Desk Inventory');

        inventory_api_log_transaction($item_id, 'stock_out', $quantity, $final_notes, $reason_type ?: null, null, $tag_data);

        if (!empty($tag_data['customer_id'])) {
            app_touch_customer_branch_record((int) $tag_data['customer_id'], (int) ($item['branch_id'] ?? 0), $user['id'] ?? null);
        }

        log_audit('inventory_items', 'stock_out', $item_id, ['quantity' => $old_quantity], [
            'quantity' => $new_quantity,
            'reason_type' => $reason_type,
            'customer_id' => $tag_data['customer_id'] ?? null,
            'vehicle_id' => $tag_data['vehicle_id'] ?? null,
        ]);

        inventory_api_finish(true, 'Stock removed successfully', 200, ['quantity' => $new_quantity]);
    } catch (Exception $e) {
        error_log('Stock out error: ' . $e->getMessage());
        inventory_api_finish(false, $e->getMessage(), 400);
    }
}

// Handle Add Inventory Item
if ($action === 'add') {
    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        $branch_id = intval($_POST['branch_id'] ?? ($user['branch_id'] ?? 0));
        $has_model_column = app_column_exists('inventory_items', 'model');
        $has_serial_column = app_column_exists('inventory_items', 'serial_number');
        $has_manufacturing_date_column = app_column_exists('inventory_items', 'manufacturing_date');

        $item_name = inventory_api_clean_text($_POST['item_name'] ?? $_POST['tire_size'] ?? '', 'Item name', 255, true);
        $category = strtolower(inventory_api_clean_text($_POST['category'] ?? 'tire', 'Category', 40, true));
        $brand = inventory_api_clean_text($_POST['brand'] ?? '', 'Brand', 100, true);
        $model = inventory_api_clean_text($_POST['model'] ?? '', 'Model', 100, $has_model_column);
        $size = inventory_api_clean_text($_POST['size'] ?? $_POST['tire_size'] ?? '', 'Size', 80, true);
        $description = inventory_api_clean_text($_POST['description'] ?? '', 'Description', 500);
        $sku = inventory_api_clean_code($_POST['sku'] ?? '', 'SKU', 100, true);
        $serial_number = inventory_api_clean_code($_POST['serial_number'] ?? '', 'Serial number', 120, $has_serial_column);
        $manufacturing_date = inventory_api_clean_date($_POST['manufacturing_date'] ?? '', 'Manufacturing date', $has_manufacturing_date_column);
        $quantity = inventory_api_clean_int($_POST['quantity'] ?? 0, 'Quantity', 0, 100000);
        $reorder_level = inventory_api_clean_int($_POST['reorder_level'] ?? 5, 'Reorder level', 1, 100000);
        $unit_price = inventory_api_clean_money($_POST['unit_price'] ?? $_POST['unit_cost'] ?? '', 'Unit price', true);

        if ($branch_id <= 0) {
            throw new Exception('Branch is required');
        }

        if (!in_array($category, ['tire', 'accessory', 'part'], true)) {
            throw new Exception('Invalid inventory category');
        }

        if (!has_branch_access($branch_id)) {
            throw new Exception('Unauthorized access');
        }

        $branch_stmt = $pdo->prepare("SELECT has_inventory FROM branches WHERE id = ? AND status = 'active'");
        $branch_stmt->execute([$branch_id]);
        $branch = $branch_stmt->fetch();

        if (!$branch || (int) $branch['has_inventory'] !== 1) {
            throw new Exception('Inventory is only available for inventory branches');
        }

        inventory_api_ensure_unique_value('sku', $sku, 'SKU');
        inventory_api_ensure_unique_value('serial_number', $serial_number, 'Serial number');

        $insert_columns = [
            'branch_id',
            'item_name',
            'category',
            'brand',
        ];
        $insert_values = [
            $branch_id,
            $item_name,
            $category,
            $brand ?: null,
        ];

        if (app_column_exists('inventory_items', 'model')) {
            $insert_columns[] = 'model';
            $insert_values[] = $model ?: null;
        }

        $insert_columns = array_merge($insert_columns, [
            'size',
            'description',
            'sku',
        ]);
        $insert_values = array_merge($insert_values, [
            $size ?: null,
            $description ?: null,
            $sku ?: null,
        ]);

        if (app_column_exists('inventory_items', 'serial_number')) {
            $insert_columns[] = 'serial_number';
            $insert_values[] = $serial_number ?: null;
        }

        if (app_column_exists('inventory_items', 'manufacturing_date')) {
            $insert_columns[] = 'manufacturing_date';
            $insert_values[] = $manufacturing_date !== '' ? $manufacturing_date : null;
        }

        $insert_columns = array_merge($insert_columns, [
            'quantity',
            'reorder_level',
            'unit_price',
            'status',
        ]);
        $insert_values = array_merge($insert_values, [
            $quantity,
            $reorder_level,
            $unit_price,
            'active',
        ]);

        $quoted_columns = array_map('app_quote_identifier', $insert_columns);
        $placeholders = implode(', ', array_fill(0, count($insert_columns), '?'));
        $stmt = $pdo->prepare("
            INSERT INTO inventory_items
                (" . implode(', ', $quoted_columns) . ")
            VALUES
                ($placeholders)
        ");
        $stmt->execute($insert_values);

        $item_id = $pdo->lastInsertId();
        if ($quantity > 0) {
            inventory_api_log_transaction($item_id, 'stock_in', $quantity, 'Initial stock from add inventory item', 'initial_stock', $item_id);
            log_audit('inventory_items', 'stock_in', $item_id, ['quantity' => 0], [
                'quantity' => $quantity,
                'added_quantity' => $quantity,
                'item_name' => $item_name,
                'branch_id' => $branch_id,
            ]);
        }

        log_audit('inventory_items', 'create', $item_id, null, [
            'item_name' => $item_name,
            'quantity' => $quantity,
        ]);

        inventory_api_finish(true, 'Inventory item added successfully', 200, ['id' => $item_id]);
    } catch (Exception $e) {
        error_log('Add inventory error: ' . $e->getMessage());
        inventory_api_finish(false, $e->getMessage(), 400);
    }
}

inventory_api_finish(false, 'Invalid action', 400);
?>
