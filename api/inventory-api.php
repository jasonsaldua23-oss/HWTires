<?php
/**
 * Inventory API Handler
 */

require_once __DIR__ . '/../includes/config.php';
global $pdo;
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

if (!is_logged_in()) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$user = app_get_session_user();
$action = $_POST['action'] ?? $_GET['action'] ?? null;

if (!function_exists('inventory_api_finish')) {
    function inventory_api_finish($success, $message, $status_code = 200, $payload = []) {
        if (!empty($_POST['redirect'])) {
            $redirect = (string) $_POST['redirect'];
            if ($success && !empty($payload['id'])) {
                $parts = explode('#', $redirect, 2);
                $base_url = $parts[0];
                $fragment = isset($parts[1]) ? '#' . $parts[1] : '';
                $separator = (strpos($base_url, '?') !== false) ? '&' : '?';
                $redirect = $base_url . $separator . 'added_id=' . urlencode($payload['id']) . $fragment;
            }
            set_flash_message($message, $success ? 'success' : 'danger');
            redirect($redirect);
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
        $source_type = inventory_api_clean_text($_POST['source_type'] ?? 'supplier_delivery', 'Supply Source', 50, true);
        $valid_source_types = ['supplier_delivery', 'tangub_warehouse', 'sancarlos_warehouse', 'other', 'branch_transfer', 'adjustment'];
        if (!in_array($source_type, $valid_source_types, true)) {
            throw new Exception('Invalid supply source');
        }

        $supplier_name = '';
        if ($source_type === 'supplier_delivery') {
            $supplier_name = inventory_api_clean_text($_POST['supplier_name'] ?? '', 'Supplier name', 150, true);
        } elseif ($source_type === 'tangub_warehouse') {
            $supplier_name = 'Central Warehouse (Tangub Hub)';
        } elseif ($source_type === 'sancarlos_warehouse') {
            $supplier_name = 'Auxiliary Warehouse (San Carlos Hub)';
        } elseif ($source_type === 'other') {
            $supplier_name = inventory_api_clean_text($_POST['supplier_name'] ?? '', 'Source name', 150, true);
        } else {
            $supplier_name = inventory_api_clean_text($_POST['supplier_name'] ?? '', 'Supplier / Source name', 150, false);
        }

        $reference_number = inventory_api_clean_text($_POST['reference_number'] ?? '', 'Reference number', 100, true);
        $custom_notes = inventory_api_clean_text($_POST['notes'] ?? '', 'Notes', 500, false);

        if ($item_id <= 0) {
            throw new Exception('Invalid inventory item');
        }

        $item = inventory_api_get_item($item_id);
        $old_quantity = (int) $item['quantity'];
        $new_quantity = $old_quantity + $quantity;

        $note_parts = [];
        if ($source_type === 'tangub_warehouse') {
            $note_parts[] = 'Central Warehouse (Tangub Hub) Delivery';
        } elseif ($source_type === 'sancarlos_warehouse') {
            $note_parts[] = 'Auxiliary Warehouse (San Carlos Hub) Delivery';
        } elseif ($source_type === 'supplier_delivery') {
            $note_parts[] = 'Direct Supplier Delivery' . ($supplier_name !== '' ? ': ' . $supplier_name : '');
        } elseif ($source_type === 'branch_transfer') {
            $note_parts[] = 'Stock Transfer Received' . ($supplier_name !== '' ? ' from ' . $supplier_name : '');
        } elseif ($source_type === 'adjustment') {
            $note_parts[] = 'Physical Count / Inventory Adjustment';
        } elseif ($source_type === 'other') {
            $note_parts[] = 'Other / Custom Source' . ($supplier_name !== '' ? ': ' . $supplier_name : '');
        } elseif ($source_type !== '') {
            $note_parts[] = ucwords(str_replace('_', ' ', $source_type));
        }

        if ($reference_number !== '') {
            if ($source_type === 'tangub_warehouse' || $source_type === 'sancarlos_warehouse') {
                $note_parts[] = 'Transfer Ref #: ' . $reference_number;
            } else {
                $note_parts[] = 'DR #: ' . $reference_number;
            }
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

// Handle Stock Quantity Correction / Physical Count Adjustment
if ($action === 'adjust_stock') {
    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        $user_role = $user['role'] ?? '';
        if (!in_array($user_role, ['admin', 'front-desk'], true)) {
            throw new Exception('Unauthorized to perform stock quantity adjustments');
        }

        $item_id = intval($_POST['inventory_id'] ?? $_POST['item_id'] ?? 0);
        if ($item_id <= 0) {
            throw new Exception('Invalid inventory item');
        }

        $physical_quantity = inventory_api_clean_int($_POST['physical_quantity'] ?? $_POST['quantity'] ?? 0, 'Physical quantity', 0, 100000);
        $reason_category = trim((string) ($_POST['reason_category'] ?? ''));
        $remarks = inventory_api_clean_text($_POST['remarks'] ?? $_POST['notes'] ?? '', 'Remarks', 500, true);

        $category_labels = [
            'physical_count' => 'Physical Count Discrepancy',
            'damaged_stock' => 'Damaged / Defective Stock',
            'missing_stock' => 'Missing / Unaccounted Stock',
            'encoding_error' => 'Data Entry / Encoding Correction',
            'found_stock' => 'Found Unrecorded Stock',
            'other' => 'Other Adjustment',
        ];

        if (!array_key_exists($reason_category, $category_labels)) {
            throw new Exception('Please select a valid reason category');
        }

        $category_label = $category_labels[$reason_category];

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT i.*, b.has_inventory, b.status AS branch_status, b.name AS branch_name
            FROM inventory_items i
            LEFT JOIN branches b ON b.id = i.branch_id
            WHERE i.id = ? AND i.status = 'active'
            FOR UPDATE
        ");
        $stmt->execute([$item_id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            throw new Exception('Inventory item not found or inactive');
        }

        if ((int) ($item['has_inventory'] ?? 0) !== 1 || ($item['branch_status'] ?? '') !== 'active') {
            throw new Exception('Inventory is only available for active inventory branches');
        }

        $item_branch_id = (int) ($item['branch_id'] ?? 0);

        // Branch Isolation: Admin can adjust any branch, Front Desk can only adjust own branch
        if ($user_role === 'front-desk') {
            $user_branch_id = (int) ($user['branch_id'] ?? 0);
            if ($item_branch_id !== $user_branch_id) {
                throw new Exception('Unauthorized: Front Desk can only adjust inventory for their assigned branch');
            }
        } elseif (!has_branch_access($item_branch_id)) {
            throw new Exception('Unauthorized branch access');
        }

        $old_quantity = (int) $item['quantity'];
        $new_quantity = (int) $physical_quantity;
        $difference = $new_quantity - $old_quantity;

        if ($difference === 0) {
            $pdo->rollBack();
            inventory_api_finish(false, 'Physical count matches the current system quantity (' . $old_quantity . '). No adjustment needed.', 400);
        }

        $diff_formatted = ($difference > 0 ? '+' : '') . $difference;
        $final_notes = "Stock correction: {$old_quantity} → {$new_quantity} (Difference: {$diff_formatted}). Reason: {$category_label} | Remarks: {$remarks}";
        $ref_type = $difference > 0 ? 'inventory_recount_up' : 'inventory_recount_down';

        $update_stmt = $pdo->prepare("UPDATE inventory_items SET quantity = ?, updated_at = NOW() WHERE id = ?");
        $update_stmt->execute([$new_quantity, $item_id]);

        inventory_api_log_transaction($item_id, 'adjustment', abs($difference), $final_notes, $ref_type, null);

        log_audit('inventory_items', 'stock_adjustment', $item_id, [
            'quantity' => $old_quantity,
        ], [
            'quantity' => $new_quantity,
            'physical_count' => $new_quantity,
            'difference' => $difference,
            'reason_category' => $reason_category,
            'remarks' => $remarks,
            'item_name' => $item['item_name'] ?? null,
            'branch_id' => $item_branch_id,
        ]);

        $pdo->commit();

        inventory_api_finish(true, "Stock quantity adjusted from {$old_quantity} to {$new_quantity} ({$diff_formatted} units)", 200, [
            'old_quantity' => $old_quantity,
            'new_quantity' => $new_quantity,
            'difference' => $difference,
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Stock adjustment error: ' . $e->getMessage());
        inventory_api_finish(false, $e->getMessage(), 400);
    }
}

// Handle Add Inventory Item
if ($action === 'add') {
    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        if (($user['role'] ?? '') !== 'admin') {
            throw new Exception('Only administrators can add new inventory items.');
        }

        $branch_id = intval($_POST['branch_id'] ?? ($user['branch_id'] ?? 0));
        $category = strtolower(inventory_api_clean_text($_POST['category'] ?? 'tire', 'Category', 40, true));

        if (!in_array($category, ['tire', 'accessory', 'part'], true)) {
            throw new Exception('Invalid inventory category');
        }

        if ($branch_id <= 0) {
            throw new Exception('Branch is required');
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

        $creation_mode = strtolower(trim((string) ($_POST['creation_mode'] ?? 'new')));
        if (!in_array($creation_mode, ['new', 'existing'], true)) {
            $creation_mode = 'new';
        }

        $pdo->beginTransaction();

        // Acquire global catalog sequence row lock as creation mutex
        $stmtSeq = $pdo->prepare("
            SELECT last_catalog_number
            FROM inventory_catalog_sequence
            WHERE sequence_key = 'global_catalog'
            FOR UPDATE
        ");
        $stmtSeq->execute();
        $seqRow = $stmtSeq->fetch(PDO::FETCH_ASSOC);
        if (!$seqRow) {
            throw new Exception('Catalog sequence allocator record is missing. Please initialize inventory_catalog_sequence.');
        }
        $last_catalog_number = (int) $seqRow['last_catalog_number'];

        $item_name = '';
        $category = '';
        $brand = '';
        $model = '';
        $size = '';
        $description = '';
        $sku = '';
        $serial_number = '';
        $unit_price = 0.00;
        $reorder_level = 5;
        $manufacturing_date = null;

        if ($creation_mode === 'existing') {
            $source_item_id = intval($_POST['source_item_id'] ?? 0);
            $submitted_catalog_num = intval($_POST['catalog_number'] ?? $_POST['existing_catalog_number'] ?? 0);

            if ($source_item_id <= 0) {
                throw new Exception('Please select an existing catalog product to link.');
            }

            $stmtSource = $pdo->prepare("
                SELECT id, branch_id, category, item_name, brand, model, size, description, unit_price, reorder_level, sku, serial_number
                FROM inventory_items
                WHERE id = ? AND status = 'active'
            ");
            $stmtSource->execute([$source_item_id]);
            $sourceItem = $stmtSource->fetch(PDO::FETCH_ASSOC);
            if (!$sourceItem) {
                throw new Exception('Selected catalog source product was not found or is inactive.');
            }

            if (!preg_match('/^B[0-9]+-(TIR|PAR|ACC)-([0-9]{5})$/', $sourceItem['sku'], $sm)) {
                throw new Exception('Selected source product does not use a canonical catalog SKU format.');
            }
            $source_cat_prefix = $sm[1];
            $source_catalog_num = (int) $sm[2];

            if ($submitted_catalog_num > 0 && $submitted_catalog_num !== $source_catalog_num) {
                throw new Exception('Submitted catalog number does not match the source product catalog identity.');
            }

            $expected_category = ($source_cat_prefix === 'TIR') ? 'tire' : (($source_cat_prefix === 'ACC') ? 'accessory' : 'part');
            if ($sourceItem['category'] !== $expected_category) {
                throw new Exception('Source item category does not match its canonical SKU code.');
            }

            // Inherit canonical product specs
            $item_name = $sourceItem['item_name'];
            $category = $sourceItem['category'];
            $brand = $sourceItem['brand'];
            $model = $sourceItem['model'];
            $size = $sourceItem['size'];

            // Format destination identifiers
            $sku = sprintf('B%d-%s-%05d', $branch_id, $source_cat_prefix, $source_catalog_num);
            $suffix = (($source_catalog_num * 7) % 89) + 10;
            $serial_number = sprintf('HW-%s-B%d-%05d-%02d', $source_cat_prefix, $branch_id, $source_catalog_num, $suffix);

            // Check if destination branch already carries this catalog product (active or archived)
            $stmtBranchCheck = $pdo->prepare("
                SELECT id, item_name, status
                FROM inventory_items
                WHERE branch_id = ?
                  AND (sku = ? OR serial_number = ?)
            ");
            $stmtBranchCheck->execute([$branch_id, $sku, $serial_number]);
            $existingBranchItem = $stmtBranchCheck->fetch(PDO::FETCH_ASSOC);
            if ($existingBranchItem) {
                $status_msg = ($existingBranchItem['status'] === 'active') ? 'active' : 'archived';
                throw new Exception("This catalog product (Catalog #{$source_catalog_num}) is already registered at this branch as Item #{$existingBranchItem['id']} ({$status_msg}).");
            }

            // Destination editable fields
            $raw_price = trim((string) ($_POST['unit_price'] ?? ''));
            $unit_price = $raw_price !== '' ? inventory_api_clean_money($raw_price, 'Unit price', true) : floatval($sourceItem['unit_price']);
            $raw_reorder = trim((string) ($_POST['reorder_level'] ?? ''));
            $reorder_level = $raw_reorder !== '' ? inventory_api_clean_int($raw_reorder, 'Reorder level', 1, 100000) : intval($sourceItem['reorder_level'] ?: 5);
            $raw_desc = trim((string) ($_POST['description'] ?? ''));
            $description = $raw_desc !== '' ? inventory_api_clean_text($raw_desc, 'Description', 500, false) : ($sourceItem['description'] ?: null);
            $manufacturing_date = inventory_api_clean_date($_POST['manufacturing_date'] ?? '', 'Manufacturing date', false);

        } else {
            // creation_mode === 'new'
            $category = strtolower(inventory_api_clean_text($_POST['category'] ?? 'tire', 'Category', 40, true));
            if (!in_array($category, ['tire', 'accessory', 'part'], true)) {
                throw new Exception('Invalid inventory category');
            }
            $cat_prefix = ($category === 'tire') ? 'TIR' : (($category === 'accessory') ? 'ACC' : 'PAR');

            // Allocate next catalog number
            $allocated_catalog_num = $last_catalog_number + 1;
            $stmtUpdateSeq = $pdo->prepare("
                UPDATE inventory_catalog_sequence
                SET last_catalog_number = ?
                WHERE sequence_key = 'global_catalog'
            ");
            $stmtUpdateSeq->execute([$allocated_catalog_num]);

            // Brand resolution (Select or Custom)
            $raw_brand = trim((string) ($_POST['brand'] ?? ''));
            $raw_brand_custom = trim((string) ($_POST['brand_custom'] ?? ''));
            if ($raw_brand === 'Other' || ($raw_brand === '' && $raw_brand_custom !== '')) {
                $brand = inventory_api_clean_text($raw_brand_custom, 'Custom brand', 100, true);
            } else {
                $brand = inventory_api_clean_text($raw_brand, 'Brand', 100, true);
            }

            // Model resolution (Required for tires, optional for parts/accessories)
            $is_tire = ($category === 'tire');
            $raw_model = trim((string) ($_POST['model'] ?? ''));
            $raw_model_custom = trim((string) ($_POST['model_custom'] ?? ''));
            if ($raw_model === 'Other' || ($raw_brand === 'Other' && $raw_model_custom !== '')) {
                $model = inventory_api_clean_text($raw_model_custom, 'Custom model', 100, $is_tire);
            } else {
                $model = inventory_api_clean_text($raw_model, 'Model', 100, $is_tire);
            }

            // Size (Required for tires, optional for parts/accessories)
            $size = inventory_api_clean_text($_POST['size'] ?? $_POST['tire_size'] ?? '', 'Size', 80, $is_tire);

            $item_name = inventory_api_clean_text($_POST['item_name'] ?? '', 'Item name', 255, true);
            $description = inventory_api_clean_text($_POST['description'] ?? '', 'Description', 500, false);
            $unit_price = inventory_api_clean_money($_POST['unit_price'] ?? $_POST['unit_cost'] ?? '', 'Unit price', true);
            $reorder_level = inventory_api_clean_int($_POST['reorder_level'] ?? 5, 'Reorder level', 1, 100000);
            $manufacturing_date = inventory_api_clean_date($_POST['manufacturing_date'] ?? '', 'Manufacturing date', false);

            $sku = sprintf('B%d-%s-%05d', $branch_id, $cat_prefix, $allocated_catalog_num);
            $suffix = (($allocated_catalog_num * 7) % 89) + 10;
            $serial_number = sprintf('HW-%s-B%d-%05d-%02d', $cat_prefix, $branch_id, $allocated_catalog_num, $suffix);
        }

        // Uniqueness checks
        inventory_api_ensure_unique_value('sku', $sku, 'SKU');
        inventory_api_ensure_unique_value('serial_number', $serial_number, 'Serial number');

        // On-hand available stock always starts at 0 for new product registrations
        $quantity = 0;

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
            0,
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
        $item_id = (int) $pdo->lastInsertId();

        // Handle Optional Initial Delivery (Pending Arrival)
        $schedule_delivery = !empty($_POST['schedule_delivery']);
        $incoming_id = null;
        $expected_quantity = 0;

        if ($schedule_delivery) {
            $expected_quantity = inventory_api_clean_int($_POST['expected_quantity'] ?? 0, 'Expected delivery quantity', 1, 100000);
            $source_type = inventory_api_clean_text($_POST['source_type'] ?? 'supplier_delivery', 'Delivery source', 50, true);
            $valid_source_types = ['supplier_delivery', 'tangub_warehouse', 'sancarlos_warehouse', 'other'];
            if (!in_array($source_type, $valid_source_types, true)) {
                throw new Exception('Invalid delivery source type');
            }

            $supplier_name = '';
            if ($source_type === 'supplier_delivery') {
                $supplier_name = inventory_api_clean_text($_POST['supplier_name'] ?? '', 'Supplier name', 150, true);
            } elseif ($source_type === 'tangub_warehouse') {
                $supplier_name = 'Central Warehouse (Tangub Hub)';
            } elseif ($source_type === 'sancarlos_warehouse') {
                $supplier_name = 'Auxiliary Warehouse (San Carlos Hub)';
            } elseif ($source_type === 'other') {
                $supplier_name = inventory_api_clean_text($_POST['supplier_name'] ?? '', 'Source name', 150, true);
            }

            $reference_number = inventory_api_clean_text($_POST['reference_number'] ?? '', 'Delivery reference number', 100, true);

            $expected_arrival_date = '';
            if (!empty($_POST['expected_arrival_date'])) {
                $raw_arrival_date = trim((string) $_POST['expected_arrival_date']);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw_arrival_date)) {
                    throw new Exception('Invalid expected arrival date');
                }
                [$year, $month, $day] = array_map('intval', explode('-', $raw_arrival_date));
                if (!checkdate($month, $day, $year) || sprintf('%04d-%02d-%02d', $year, $month, $day) !== $raw_arrival_date) {
                    throw new Exception('Invalid expected arrival date');
                }
                $expected_arrival_date = $raw_arrival_date;
            }

            $delivery_notes = inventory_api_clean_text($_POST['delivery_notes'] ?? '', 'Delivery notes', 1000, false);

            $incoming_stmt = $pdo->prepare("
                INSERT INTO inventory_incoming_stock (
                    item_id, branch_id, expected_quantity, actual_quantity,
                    source_type, supplier_name, reference_number,
                    expected_arrival_date, status, notes, created_by
                ) VALUES (
                    ?, ?, ?, NULL,
                    ?, ?, ?,
                    ?, 'pending', ?, ?
                )
            ");
            $incoming_stmt->execute([
                $item_id,
                $branch_id,
                $expected_quantity,
                $source_type,
                $supplier_name !== '' ? $supplier_name : null,
                $reference_number !== '' ? $reference_number : null,
                $expected_arrival_date !== '' ? $expected_arrival_date : null,
                $delivery_notes !== '' ? $delivery_notes : null,
                (int) ($user['id'] ?? 0)
            ]);
            $incoming_id = (int) $pdo->lastInsertId();

            if (app_table_exists('transfer_notifications')) {
                $notif_title = 'Pending Stock Arrival';
                $notif_message = $item_name . ' — ' . $expected_quantity . ' unit(s) expected';
                $notif_action_url = '/hwtires/front-desk/tire-inventory/#pending-arrivals';

                $notif_stmt = $pdo->prepare("
                    INSERT INTO transfer_notifications
                        (branch_id, user_id, transfer_request_id, title, message, type, action_url, is_read, created_at)
                    VALUES (?, NULL, NULL, ?, ?, 'info', ?, 0, NOW())
                ");
                $notif_stmt->execute([
                    $branch_id,
                    $notif_title,
                    $notif_message,
                    $notif_action_url,
                ]);
            }

            log_audit('inventory_incoming_stock', 'create', $incoming_id, null, [
                'item_id' => $item_id,
                'branch_id' => $branch_id,
                'expected_quantity' => $expected_quantity,
                'source_type' => $source_type,
                'supplier_name' => $supplier_name,
                'reference_number' => $reference_number,
                'expected_arrival_date' => $expected_arrival_date,
                'status' => 'pending'
            ]);
        }

        // Log audit for inventory_items
        log_audit('inventory_items', 'create', $item_id, null, [
            'item_name' => $item_name,
            'category' => $category,
            'branch_id' => $branch_id,
            'sku' => $sku,
            'quantity' => 0,
            'scheduled_delivery' => $schedule_delivery,
            'expected_quantity' => $expected_quantity,
        ]);

        $pdo->commit();

        $success_msg = $schedule_delivery
            ? "Inventory product registered successfully (Available: 0). Delivery of {$expected_quantity} unit(s) scheduled (Pending Arrival)."
            : "Inventory product registered successfully (Available: 0).";

        inventory_api_finish(true, $success_msg, 200, [
            'id' => $item_id,
            'incoming_id' => $incoming_id,
            'quantity' => 0
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Add inventory error: ' . $e->getMessage());
        inventory_api_finish(false, $e->getMessage(), 400);
    }
}

// Handle Receive Incoming Stock (Front Desk Physical Arrival Confirmation)
if ($action === 'receive_incoming_stock') {
    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        $user_role = $user['role'] ?? '';
        if ($user_role !== 'front-desk') {
            throw new Exception('Only front desk personnel can receive incoming stock deliveries for their branch.');
        }

        $incoming_id = intval($_POST['incoming_id'] ?? $_POST['id'] ?? 0);
        if ($incoming_id <= 0) {
            throw new Exception('Invalid incoming stock delivery record');
        }

        $actual_quantity = inventory_api_clean_int($_POST['actual_quantity'] ?? $_POST['quantity'] ?? 0, 'Actual quantity received', 1, 100000);

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT inc.*, i.item_name, i.category, i.branch_id AS item_branch_id, i.quantity AS current_item_qty
            FROM inventory_incoming_stock inc
            INNER JOIN inventory_items i ON i.id = inc.item_id
            WHERE inc.id = ?
            FOR UPDATE
        ");
        $stmt->execute([$incoming_id]);
        $incoming = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$incoming) {
            throw new Exception('Incoming delivery record not found');
        }

        if ($incoming['status'] !== 'pending') {
            throw new Exception('This incoming delivery has already been processed and is no longer pending arrival.');
        }

        $incoming_branch_id = (int) ($incoming['branch_id'] ?? 0);
        $item_branch_id = (int) ($incoming['item_branch_id'] ?? 0);

        // Security: Front Desk can only receive for their own assigned branch
        $user_branch_id = (int) ($user['branch_id'] ?? 0);
        if ($incoming_branch_id !== $user_branch_id) {
            throw new Exception('Unauthorized: You can only receive stock arrivals for your assigned branch');
        }

        if ($incoming_branch_id !== $item_branch_id) {
            throw new Exception('Branch mismatch between delivery and inventory item');
        }

        $item_id = (int) $incoming['item_id'];
        $old_quantity = (int) $incoming['current_item_qty'];
        $new_quantity = $old_quantity + $actual_quantity;

        // 1. Update inventory_items quantity and restock date
        $update_item = $pdo->prepare("
            UPDATE inventory_items
            SET quantity = quantity + ?,
                last_restock_date = CURDATE(),
                updated_at = NOW()
            WHERE id = ?
        ");
        $update_item->execute([$actual_quantity, $item_id]);

        // 2. Format transaction notes
        $source_type = $incoming['source_type'] ?? 'supplier_delivery';
        $supplier_name = trim((string) ($incoming['supplier_name'] ?? ''));
        $reference_number = trim((string) ($incoming['reference_number'] ?? ''));
        $delivery_notes = trim((string) ($incoming['notes'] ?? ''));

        $note_parts = ['Scheduled Delivery Receipt (Pending Arrival)'];
        if ($source_type === 'tangub_warehouse') {
            $note_parts[] = 'Central Warehouse (Tangub Hub) Delivery';
        } elseif ($source_type === 'sancarlos_warehouse') {
            $note_parts[] = 'Auxiliary Warehouse (San Carlos Hub) Delivery';
        } elseif ($source_type === 'supplier_delivery') {
            $note_parts[] = 'Direct Supplier Delivery' . ($supplier_name !== '' ? ': ' . $supplier_name : '');
        } elseif ($source_type === 'other') {
            $note_parts[] = 'Other / Custom Source' . ($supplier_name !== '' ? ': ' . $supplier_name : '');
        } elseif ($source_type !== '') {
            $note_parts[] = ucwords(str_replace('_', ' ', $source_type));
        }

        if ($reference_number !== '') {
            if ($source_type === 'tangub_warehouse' || $source_type === 'sancarlos_warehouse') {
                $note_parts[] = 'Transfer Ref #: ' . $reference_number;
            } else {
                $note_parts[] = 'DR #: ' . $reference_number;
            }
        }
        if ((int) $actual_quantity !== (int) $incoming['expected_quantity']) {
            $note_parts[] = 'Discrepancy: Received ' . $actual_quantity . ' of ' . (int) $incoming['expected_quantity'] . ' expected';
        }
        if ($delivery_notes !== '') {
            $note_parts[] = 'Original Note: ' . $delivery_notes;
        }
        $final_notes = implode(' | ', $note_parts);

        // 3. Log stock_in transaction
        inventory_api_log_transaction($item_id, 'stock_in', $actual_quantity, $final_notes, $source_type ?: 'supplier_delivery', $incoming_id);

        // 4. Update incoming delivery record
        $update_incoming = $pdo->prepare("
            UPDATE inventory_incoming_stock
            SET actual_quantity = ?,
                status = 'received',
                received_by = ?,
                received_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ");
        $update_incoming->execute([
            $actual_quantity,
            (int) ($user['id'] ?? 0),
            $incoming_id
        ]);

        // 5. Audit logs
        log_audit('inventory_incoming_stock', 'receive', $incoming_id, [
            'status' => 'pending',
            'actual_quantity' => null,
        ], [
            'status' => 'received',
            'actual_quantity' => $actual_quantity,
            'expected_quantity' => (int) $incoming['expected_quantity'],
            'received_by' => (int) ($user['id'] ?? 0),
        ]);

        log_audit('inventory_items', 'stock_in', $item_id, [
            'quantity' => $old_quantity,
        ], [
            'quantity' => $new_quantity,
            'added_quantity' => $actual_quantity,
            'source_type' => $source_type,
            'incoming_stock_id' => $incoming_id,
        ]);

        $pdo->commit();

        $success_msg = 'Stock arrival confirmed successfully (' . $actual_quantity . ' unit(s) added to inventory).';
        inventory_api_finish(true, $success_msg, 200, [
            'id' => $item_id,
            'incoming_id' => $incoming_id,
            'received_quantity' => $actual_quantity,
            'new_quantity' => $new_quantity,
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Receive incoming stock error: ' . $e->getMessage());
        inventory_api_finish(false, $e->getMessage(), 400);
    }
}

// Handle Customer Search (Read-only autocomplete for stock out tagging)
if ($action === 'search_customers') {
    try {
        $query = trim((string) ($_GET['query'] ?? $_POST['query'] ?? ''));
        $customers = [];

        if (mb_strlen($query) < 2) {
            $stmt = $pdo->query("
                SELECT DISTINCT
                    c.id,
                    c.name,
                    COALESCE(NULLIF(c.phone_mobile, ''), NULLIF(c.contact, ''), '') AS phone,
                    c.branch_id
                FROM customers c
                WHERE c.status = 'active'
                ORDER BY c.name ASC
                LIMIT 15
            ");
            $customers = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } else {
            $lower_query = '%' . mb_strtolower($query, 'UTF-8') . '%';
            $stmt = $pdo->prepare("
                SELECT DISTINCT
                    c.id,
                    c.name,
                    COALESCE(NULLIF(c.phone_mobile, ''), NULLIF(c.contact, ''), '') AS phone,
                    c.branch_id
                FROM customers c
                WHERE c.status = 'active'
                  AND (
                      LOWER(c.name) LIKE ?
                      OR c.phone_mobile LIKE ?
                      OR c.contact LIKE ?
                  )
                ORDER BY c.name ASC
                LIMIT 15
            ");
            $stmt->execute([$lower_query, $lower_query, $lower_query]);
            $customers = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        }

        inventory_api_finish(true, 'Customers fetched', 200, ['customers' => $customers]);
    } catch (Exception $e) {
        error_log('Search customers error: ' . $e->getMessage());
        inventory_api_finish(false, $e->getMessage(), 400);
    }
}

// Handle Archive Inventory Item (Admin-only)
if ($action === 'archive') {
    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        if (($user['role'] ?? '') !== 'admin') {
            throw new Exception('Only administrators can archive inventory items.');
        }

        $item_id = intval($_POST['inventory_id'] ?? $_POST['item_id'] ?? $_POST['id'] ?? 0);
        if ($item_id <= 0) {
            throw new Exception('Invalid inventory item.');
        }

        $stmt = $pdo->prepare("SELECT * FROM inventory_items WHERE id = ?");
        $stmt->execute([$item_id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            throw new Exception('Inventory item not found.');
        }

        if (strtolower((string) ($item['status'] ?? '')) === 'inactive') {
            throw new Exception('This inventory item is already archived.');
        }

        $reason = inventory_api_clean_text($_POST['archive_reason'] ?? $_POST['reason'] ?? '', 'Archive reason', 500, true);

        // Guard 1: Active Job Orders referencing this item
        $meta_pattern = '%"inventory_item_id":' . $item_id . '%';
        $job_check_stmt = $pdo->prepare("
            SELECT jo.id, jo.job_number, jo.status
            FROM job_orders jo
            INNER JOIN quotations q ON q.id = jo.quotation_id
            INNER JOIN quotation_items qi ON qi.quotation_id = q.id
            WHERE jo.status IN ('waiting', 'pending', 'in-progress')
              AND (
                  (qi.item_name = ? AND jo.branch_id = ?)
                  OR qi.notes LIKE ?
              )
            LIMIT 1
        ");
        $job_check_stmt->execute([$item['item_name'], (int) $item['branch_id'], $meta_pattern]);
        $active_job = $job_check_stmt->fetch(PDO::FETCH_ASSOC);
        if ($active_job) {
            $jo_ref = !empty($active_job['job_number']) ? $active_job['job_number'] : ('JO #' . $active_job['id']);
            throw new Exception('Cannot archive this item: It is currently assigned to active Job Order ' . $jo_ref . '. Complete or cancel the Job Order first.');
        }

        // Guard 2: Open Quotations referencing this item
        $quote_check_stmt = $pdo->prepare("
            SELECT q.id, q.quotation_number, q.status
            FROM quotations q
            INNER JOIN quotation_items qi ON qi.quotation_id = q.id
            LEFT JOIN job_orders jo ON jo.quotation_id = q.id
            WHERE q.status IN ('pending', 'approved')
              AND (jo.id IS NULL OR jo.status IN ('waiting', 'pending', 'in-progress'))
              AND (
                  (qi.item_name = ? AND q.branch_id = ?)
                  OR qi.notes LIKE ?
              )
            LIMIT 1
        ");
        $quote_check_stmt->execute([$item['item_name'], (int) $item['branch_id'], $meta_pattern]);
        $open_quote = $quote_check_stmt->fetch(PDO::FETCH_ASSOC);
        if ($open_quote) {
            $quote_ref = !empty($open_quote['quotation_number']) ? $open_quote['quotation_number'] : ('Quotation #' . $open_quote['id']);
            throw new Exception('Cannot archive this item: It is currently included in open Service Operation / Quotation ' . $quote_ref . '. Complete, reject, or cancel the quotation first.');
        }

        // Guard 3: Unresolved Transfers
        $transfer_check_stmt = $pdo->prepare("
            SELECT id, request_number, status
            FROM inter_branch_transfer_requests
            WHERE item_id = ?
              AND status IN ('pending', 'approved', 'shipped')
            LIMIT 1
        ");
        $transfer_check_stmt->execute([$item_id]);
        $open_transfer = $transfer_check_stmt->fetch(PDO::FETCH_ASSOC);
        if ($open_transfer) {
            $transfer_ref = !empty($open_transfer['request_number']) ? $open_transfer['request_number'] : ('Transfer #' . $open_transfer['id']);
            throw new Exception('Cannot archive this item: It is currently part of an active transfer request ' . $transfer_ref . ' (Status: ' . ucfirst($open_transfer['status']) . '). Complete or cancel the transfer first.');
        }

        $pdo->beginTransaction();

        $update_stmt = $pdo->prepare("
            UPDATE inventory_items
            SET status = 'inactive',
                archived_at = NOW(),
                archived_by = ?,
                archive_reason = ?
            WHERE id = ?
        ");
        $update_stmt->execute([$user['id'] ?? null, $reason, $item_id]);

        log_audit('inventory_items', 'archive', $item_id, $item, [
            'status' => 'inactive',
            'archive_reason' => $reason,
            'item_name' => $item['item_name'] ?? null,
            'sku' => $item['sku'] ?? null,
            'branch_id' => (int) ($item['branch_id'] ?? 0),
            'quantity' => (int) ($item['quantity'] ?? 0),
            'records_preserved' => true,
        ]);

        $pdo->commit();

        inventory_api_finish(true, 'Inventory item archived successfully', 200, ['id' => $item_id]);
    } catch (Exception $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Archive inventory item error: ' . $e->getMessage());
        inventory_api_finish(false, $e->getMessage(), 400);
    }
}

// Handle Restore / Reactivate Inventory Item (Admin-only)
if ($action === 'restore' || $action === 'reactivate') {
    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        if (($user['role'] ?? '') !== 'admin') {
            throw new Exception('Only administrators can reactivate inventory items.');
        }

        $item_id = intval($_POST['inventory_id'] ?? $_POST['item_id'] ?? $_POST['id'] ?? 0);
        if ($item_id <= 0) {
            throw new Exception('Invalid inventory item.');
        }

        $stmt = $pdo->prepare("SELECT * FROM inventory_items WHERE id = ?");
        $stmt->execute([$item_id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            throw new Exception('Inventory item not found.');
        }

        if (strtolower((string) ($item['status'] ?? '')) === 'active') {
            throw new Exception('This inventory item is already active.');
        }

        $pdo->beginTransaction();

        $update_stmt = $pdo->prepare("
            UPDATE inventory_items
            SET status = 'active',
                restored_at = NOW(),
                restored_by = ?
            WHERE id = ?
        ");
        $update_stmt->execute([$user['id'] ?? null, $item_id]);

        log_audit('inventory_items', 'restore', $item_id, $item, [
            'status' => 'active',
            'item_name' => $item['item_name'] ?? null,
            'sku' => $item['sku'] ?? null,
            'branch_id' => (int) ($item['branch_id'] ?? 0),
            'quantity' => (int) ($item['quantity'] ?? 0),
            'records_restored' => true,
        ]);

        $pdo->commit();

        inventory_api_finish(true, 'Inventory item reactivated successfully', 200, ['id' => $item_id]);
    } catch (Exception $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Restore inventory item error: ' . $e->getMessage());
        inventory_api_finish(false, $e->getMessage(), 400);
    }
}

inventory_api_finish(false, 'Invalid action', 400);
?>
