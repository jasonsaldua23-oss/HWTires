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
    function inventory_api_log_transaction($item_id, $transaction_type, $quantity, $notes, $reference_type = null, $reference_id = null, array $tags = [], array $warranty_data = []) {
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

        $warranty_columns = [
            'warranty_snapshot_has',
            'warranty_duration',
            'warranty_unit',
            'warranty_coverage',
            'warranty_conditions',
            'warranty_start_date',
            'warranty_expiry_date',
        ];

        foreach ($warranty_columns as $col) {
            if (array_key_exists($col, $warranty_data) && app_column_exists('inventory_transactions', $col)) {
                $columns[] = $col;
                $values[] = $warranty_data[$col];
            }
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $stmt = $pdo->prepare("
            INSERT INTO inventory_transactions (" . implode(', ', $columns) . ")
            VALUES ($placeholders)
        ");
        $stmt->execute($values);
        return (int) $pdo->lastInsertId();
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

if (!function_exists('inventory_api_is_fifo_active')) {
    /**
     * Narrowly scoped check for Revision 3 FIFO activation in inventory workflows.
     * Delegates directly to the canonical app_is_fifo_active() in includes/config.php.
     *
     * @return bool
     */
    function inventory_api_is_fifo_active(): bool {
        return app_is_fifo_active();
    }
}

// ============================================================================
// READ-ONLY FIFO BATCH HISTORY ACTION
// ============================================================================
if ($action === 'get_fifo_batch_history') {
    try {
        if (!function_exists('app_is_fifo_active') || !app_is_fifo_active()) {
            inventory_api_finish(false, 'FIFO inventory is not active in this environment.', 403);
        }

        if (!function_exists('app_table_exists') || !app_table_exists('inventory_batches') || !app_table_exists('inventory_batch_allocations')) {
            inventory_api_finish(false, 'FIFO inventory schema is not available.', 400);
        }

        $item_id = intval($_GET['item_id'] ?? $_POST['item_id'] ?? 0);
        if ($item_id <= 0) {
            inventory_api_finish(false, 'Invalid inventory item ID.', 400);
        }

        $stmt = $pdo->prepare("
            SELECT i.id, i.item_name, i.category, i.brand, i.size, i.sku, i.quantity, i.reorder_level, i.unit_price, i.branch_id, i.status,
                   b.name AS branch_name
            FROM inventory_items i
            LEFT JOIN branches b ON i.branch_id = b.id
            WHERE i.id = ?
        ");
        $stmt->execute([$item_id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            inventory_api_finish(false, 'Inventory item not found.', 404);
        }

        if (!has_branch_access((int) $item['branch_id'])) {
            inventory_api_finish(false, 'Unauthorized: You do not have permission to view inventory for this branch.', 403);
        }

        $batch_stmt = $pdo->prepare("
            SELECT
                id,
                item_id,
                branch_id,
                batch_code,
                source_type,
                source_id,
                source_sequence,
                source_ref,
                initial_quantity,
                remaining_quantity,
                received_at,
                converted_at,
                is_legacy,
                status,
                created_at,
                updated_at
            FROM inventory_batches
            WHERE item_id = ?
              AND branch_id = ?
            ORDER BY
                is_legacy DESC,
                received_at ASC,
                id ASC
        ");
        $batch_stmt->execute([$item_id, (int) $item['branch_id']]);
        $raw_batches = $batch_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $alloc_sum_stmt = $pdo->prepare("
            SELECT
                batch_id,
                COALESCE(SUM(CASE WHEN alloc_type = 'issuance' THEN allocated_quantity ELSE 0 END), 0) AS total_issued,
                COALESCE(SUM(CASE WHEN alloc_type = 'reversal' THEN allocated_quantity ELSE 0 END), 0) AS total_reversed
            FROM inventory_batch_allocations
            WHERE item_id = ?
              AND branch_id = ?
            GROUP BY batch_id
        ");
        $alloc_sum_stmt->execute([$item_id, (int) $item['branch_id']]);
        $alloc_sums = [];
        foreach ($alloc_sum_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $alloc_sums[(int) $row['batch_id']] = [
                'total_issued' => (int) $row['total_issued'],
                'total_reversed' => (int) $row['total_reversed'],
                'net_issued' => (int) $row['total_issued'] - (int) $row['total_reversed'],
            ];
        }

        $batches = [];
        $total_batch_remaining = 0;
        $total_batch_initial = 0;
        $total_batch_issued = 0;
        $active_priority_index = 0;

        foreach ($raw_batches as $b) {
            $bid = (int) $b['id'];
            $initial = (int) $b['initial_quantity'];
            $remaining = (int) $b['remaining_quantity'];
            $b_status = (string) $b['status'];
            $is_legacy = (int) $b['is_legacy'] === 1;

            $issued = isset($alloc_sums[$bid]) ? $alloc_sums[$bid]['net_issued'] : ($initial - $remaining);

            $total_batch_initial += $initial;
            $total_batch_remaining += ($b_status === 'active' ? $remaining : 0);
            $total_batch_issued += $issued;

            if ($b_status === 'active' && $remaining > 0) {
                $active_priority_index++;
                $priority_label = "Priority #{$active_priority_index}" . ($active_priority_index === 1 ? ' (First Out Queue)' : ' (Next in Queue)');
                $priority_class = ($active_priority_index === 1 ? 'priority-first-out' : 'priority-next');
            } elseif ($remaining <= 0 || $b_status === 'exhausted') {
                $priority_label = 'Exhausted (Depleted)';
                $priority_class = 'priority-depleted';
            } else {
                $priority_label = ucfirst($b_status);
                $priority_class = 'priority-inactive';
            }

            $source_labels = [
                'legacy_opening' => 'Opening Balance',
                'manual_stock_in' => 'Supplier Delivery',
                'incoming_stock' => 'Incoming Shipment',
                'transfer_receipt' => 'Inter-Branch Transfer',
                'adjustment_positive' => 'Stock Adjustment (+)',
            ];
            $source_label = $source_labels[$b['source_type']] ?? ucwords(str_replace('_', ' ', (string) $b['source_type']));

            $received_date_display = 'Date unavailable';
            if ($is_legacy && empty($b['received_at'])) {
                $received_date_display = 'Opening Balance';
            } elseif (!empty($b['received_at'])) {
                $received_date_display = date('M d, Y h:i A', strtotime((string) $b['received_at']));
            }

            $batches[] = [
                'id' => $bid,
                'batch_code' => $b['batch_code'],
                'source_type' => $b['source_type'],
                'source_label' => $source_label,
                'source_ref' => $b['source_ref'] ?: 'N/A',
                'initial_quantity' => $initial,
                'issued_quantity' => $issued,
                'remaining_quantity' => $remaining,
                'received_at' => $b['received_at'],
                'received_date_display' => $received_date_display,
                'is_legacy' => $is_legacy,
                'status' => $b_status,
                'priority_label' => $priority_label,
                'priority_class' => $priority_class,
                'is_current_first_out' => ($active_priority_index === 1 && $b_status === 'active' && $remaining > 0),
                'created_at' => $b['created_at'],
            ];
        }

        $alloc_stmt = $pdo->prepare("
            SELECT
                a.id AS allocation_id,
                a.transaction_id,
                a.batch_id,
                b.batch_code,
                b.is_legacy,
                a.alloc_type,
                a.allocated_quantity,
                a.reversal_of_id,
                a.allocated_at,
                a.notes AS alloc_notes,
                t.transaction_type,
                t.reference_type,
                t.reference_id,
                t.notes AS transaction_notes,
                t.created_at AS transaction_date,
                u.name AS created_by_name
            FROM inventory_batch_allocations a
            JOIN inventory_batches b ON a.batch_id = b.id
            LEFT JOIN inventory_transactions t ON a.transaction_id = t.id
            LEFT JOIN users u ON t.created_by = u.id
            WHERE a.item_id = ?
              AND a.branch_id = ?
            ORDER BY a.allocated_at DESC, a.id DESC
        ");
        $alloc_stmt->execute([$item_id, (int) $item['branch_id']]);
        $allocations = [];
        foreach ($alloc_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $allocations[] = [
                'allocation_id' => (int) $row['allocation_id'],
                'transaction_id' => (int) $row['transaction_id'],
                'batch_id' => (int) $row['batch_id'],
                'batch_code' => $row['batch_code'],
                'is_legacy' => (int) $row['is_legacy'] === 1,
                'alloc_type' => $row['alloc_type'],
                'allocated_quantity' => (int) $row['allocated_quantity'],
                'reversal_of_id' => (int) $row['reversal_of_id'],
                'is_reversal' => ((int) $row['reversal_of_id'] > 0 || $row['alloc_type'] === 'reversal'),
                'allocated_at' => date('M d, Y h:i A', strtotime((string) $row['allocated_at'])),
                'transaction_type' => $row['transaction_type'] ?: 'stock_out',
                'reference_type' => $row['reference_type'] ?: 'N/A',
                'reference_id' => $row['reference_id'] ?: 'N/A',
                'transaction_notes' => $row['transaction_notes'] ?: ($row['alloc_notes'] ?: '-'),
                'created_by_name' => $row['created_by_name'] ?: 'System',
            ];
        }

        $item_qty = (int) $item['quantity'];
        $reconciliation = [
            'item_quantity' => $item_qty,
            'total_batch_remaining' => $total_batch_remaining,
            'total_batch_initial' => $total_batch_initial,
            'total_batch_issued' => $total_batch_issued,
            'is_balanced' => ($item_qty === $total_batch_remaining),
            'batch_count' => count($batches),
            'active_batch_count' => $active_priority_index,
            'allocation_count' => count($allocations),
        ];

        inventory_api_finish(true, 'FIFO batch history loaded successfully.', 200, [
            'item' => [
                'id' => (int) $item['id'],
                'item_name' => $item['item_name'],
                'brand' => $item['brand'] ?: 'Unbranded',
                'size' => $item['size'] ?: '-',
                'category' => $item['category'],
                'sku' => $item['sku'] ?: '-',
                'quantity' => $item_qty,
                'reorder_level' => (int) $item['reorder_level'],
                'branch_id' => (int) $item['branch_id'],
                'branch_name' => $item['branch_name'] ?: "Branch #{$item['branch_id']}",
                'branch_code' => "B-{$item['branch_id']}",
            ],
            'batches' => $batches,
            'allocations' => $allocations,
            'reconciliation' => $reconciliation,
        ]);
    } catch (Exception $e) {
        error_log('Get FIFO batch history error: ' . $e->getMessage());
        inventory_api_finish(false, $e->getMessage(), 500);
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
        if ($item_id <= 0) {
            throw new Exception('Invalid inventory item');
        }

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

        // ---------------------------------------------------------------------
        // Transaction-Safe Stock-In Execution
        // ---------------------------------------------------------------------
        $pdo->beginTransaction();

        // Lock item row FOR UPDATE and verify branch accessibility
        $item_stmt = $pdo->prepare("
            SELECT i.*, b.has_inventory, b.status AS branch_status, b.name AS branch_name
            FROM inventory_items i
            LEFT JOIN branches b ON b.id = i.branch_id
            WHERE i.id = ? AND i.status = 'active'
            FOR UPDATE
        ");
        $item_stmt->execute([$item_id]);
        $item = $item_stmt->fetch();

        if (!$item) {
            throw new Exception('Inventory item not found or is inactive');
        }

        if ((int) ($item['has_inventory'] ?? 0) !== 1 || ($item['branch_status'] ?? '') !== 'active') {
            throw new Exception('Inventory is only available for active inventory branches');
        }

        if (!has_branch_access($item['branch_id'])) {
            throw new Exception('Unauthorized access');
        }

        // Validate current quantity and guard against signed 32-bit INT overflow
        $max_signed_int = 2147483647;
        if (!array_key_exists('quantity', $item) || $item['quantity'] === null) {
            throw new Exception("Inventory item #{$item_id} has NULL or missing quantity in inventory_items");
        }
        $old_quantity = (int) $item['quantity'];
        if ($old_quantity < 0) {
            throw new Exception("Inventory item #{$item_id} has invalid negative quantity ({$old_quantity})");
        }
        if ($old_quantity > $max_signed_int - $quantity) {
            throw new Exception("Stock-in of {$quantity} units would exceed maximum allowable integer limit ({$max_signed_int})");
        }
        $new_quantity = $old_quantity + $quantity;
        $branch_id = (int) $item['branch_id'];

        $is_fifo_active = inventory_api_is_fifo_active();

        // If FIFO is active in the future, perform pre-addition parity check
        if ($is_fifo_active) {
            require_once __DIR__ . '/../includes/fifo-inventory.php';
            $pre_recon = fifo_reconcile_item_stock($pdo, $item_id, $branch_id);
            if (!$pre_recon['is_reconciled']) {
                throw new Exception('Pre-stock-in FIFO parity reconciliation failed: ' . implode('; ', $pre_recon['reconciliation_errors']));
            }
        }

        // Update aggregate inventory stock
        $update_stmt = $pdo->prepare("UPDATE inventory_items SET quantity = ?, last_restock_date = CURDATE() WHERE id = ?");
        $update_stmt->execute([$new_quantity, $item_id]);

        if ($supplier_name !== '' && empty($item['supplier_name'])) {
            $sup_stmt = $pdo->prepare("UPDATE inventory_items SET supplier_name = ? WHERE id = ?");
            $sup_stmt->execute([$supplier_name, $item_id]);
        }

        // Insert inventory transaction record and capture inserted ID
        $transaction_id = inventory_api_log_transaction(
            $item_id,
            'stock_in',
            $quantity,
            $final_notes,
            $source_type ?: 'supplier_delivery',
            null
        );

        if (!$transaction_id || $transaction_id <= 0) {
            $transaction_id = (int) $pdo->lastInsertId();
        }

        if ($transaction_id <= 0) {
            throw new Exception('Failed to record stock-in inventory transaction');
        }

        // If FIFO is active in the future, create batch and perform post-addition parity check
        if ($is_fifo_active) {
            fifo_create_batch($pdo, [
                'item_id' => $item_id,
                'branch_id' => $branch_id,
                'source_type' => 'manual_stock_in',
                'source_id' => $transaction_id,
                'source_sequence' => 1,
                'source_ref' => $reference_number !== '' ? $reference_number : null,
                'initial_quantity' => $quantity,
                'received_at' => date('Y-m-d H:i:s'),
            ]);

            $post_recon = fifo_reconcile_item_stock($pdo, $item_id, $branch_id);
            if (!$post_recon['is_reconciled']) {
                throw new Exception('Post-stock-in FIFO parity reconciliation failed: ' . implode('; ', $post_recon['reconciliation_errors']));
            }
        }

        // Log audit trail within same transaction
        log_audit('inventory_items', 'stock_in', $item_id, ['quantity' => $old_quantity], [
            'quantity' => $new_quantity,
            'added_quantity' => $quantity,
            'item_name' => $item['item_name'] ?? null,
            'branch_id' => $branch_id,
            'source_type' => $source_type,
            'supplier_name' => $supplier_name,
            'reference_number' => $reference_number,
            'transaction_id' => $transaction_id,
        ]);

        $pdo->commit();

        inventory_api_finish(true, 'Stock added successfully (' . $quantity . ' units added)', 200, [
            'id' => $item_id,
            'quantity' => $new_quantity,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
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

        // ---------------------------------------------------------------------
        // Transaction-Safe Stock Transfer Execution
        // Begin transaction BEFORE reading stock quantities to ensure snapshot consistency.
        // ---------------------------------------------------------------------
        $pdo->beginTransaction();

        // Lock both inventory item rows in deterministic ascending ID order to prevent deadlocks
        $first_id = min($source_id, $target_id);
        $second_id = max($source_id, $target_id);

        $lock_stmt = $pdo->prepare("
            SELECT i.*, b.has_inventory, b.status AS branch_status, b.name AS branch_name
            FROM inventory_items i
            LEFT JOIN branches b ON b.id = i.branch_id
            WHERE i.id = ? AND i.status = 'active'
            FOR UPDATE
        ");

        $lock_stmt->execute([$first_id]);
        $first_row = $lock_stmt->fetch(PDO::FETCH_ASSOC);

        $lock_stmt->execute([$second_id]);
        $second_row = $lock_stmt->fetch(PDO::FETCH_ASSOC);

        $source = ($source_id === $first_id) ? $first_row : $second_row;
        $target = ($target_id === $first_id) ? $first_row : $second_row;

        if (!$source) {
            throw new Exception('Source inventory item not found or is inactive');
        }

        if ((int) ($source['has_inventory'] ?? 0) !== 1 || ($source['branch_status'] ?? '') !== 'active') {
            throw new Exception('Inventory is only available for active inventory branches');
        }

        if (!has_branch_access($source['branch_id'])) {
            throw new Exception('Unauthorized access');
        }

        if (!$target) {
            throw new Exception('Transfer target item not found or is inactive');
        }

        if ((int) ($target['has_inventory'] ?? 0) !== 1 || ($target['branch_status'] ?? '') !== 'active') {
            throw new Exception('Transfers are only available between active inventory branches');
        }

        if ((int) $source['branch_id'] === (int) $target['branch_id']) {
            throw new Exception('Branch transfer requires two different branches');
        }

        if (inventory_api_normalized_key($source) !== inventory_api_normalized_key($target)) {
            throw new Exception('Transfer items must match by category and item details');
        }

        // Validate quantities with signed 32-bit INT bounds and reject NULL/negatives
        $max_signed_int = 2147483647;

        if (!array_key_exists('quantity', $source) || $source['quantity'] === null) {
            throw new Exception("Source inventory item #{$source_id} has NULL or missing quantity in inventory_items");
        }
        $source_old_quantity = (int) $source['quantity'];
        if ($source_old_quantity < 0) {
            throw new Exception("Source inventory item #{$source_id} has invalid negative quantity ({$source_old_quantity})");
        }

        if (!array_key_exists('quantity', $target) || $target['quantity'] === null) {
            throw new Exception("Target inventory item #{$target_id} has NULL or missing quantity in inventory_items");
        }
        $target_old_quantity = (int) $target['quantity'];
        if ($target_old_quantity < 0) {
            throw new Exception("Target inventory item #{$target_id} has invalid negative quantity ({$target_old_quantity})");
        }

        if ($source_old_quantity < $quantity) {
            throw new Exception('Insufficient stock available. Current: ' . $source_old_quantity);
        }

        if ($target_old_quantity > $max_signed_int - $quantity) {
            throw new Exception("Transfer of {$quantity} units would cause destination stock to exceed maximum allowable integer limit ({$max_signed_int})");
        }

        $source_new_quantity = $source_old_quantity - $quantity;
        $target_new_quantity = $target_old_quantity + $quantity;

        $source_branch_id = (int) $source['branch_id'];
        $target_branch_id = (int) $target['branch_id'];

        $is_fifo_active = inventory_api_is_fifo_active();

        // If FIFO is active in the future, perform pre-transfer checks
        if ($is_fifo_active) {
            require_once __DIR__ . '/../includes/fifo-inventory.php';

            // Validate source aggregate-to-batch parity
            $source_pre_recon = fifo_reconcile_item_stock($pdo, $source_id, $source_branch_id);
            if (!$source_pre_recon['is_reconciled']) {
                throw new Exception('Pre-transfer FIFO parity reconciliation failed for source item: ' . implode('; ', $source_pre_recon['reconciliation_errors']));
            }

            // Validate destination aggregate-to-batch parity
            $target_pre_recon = fifo_reconcile_item_stock($pdo, $target_id, $target_branch_id);
            if (!$target_pre_recon['is_reconciled']) {
                throw new Exception('Pre-transfer FIFO parity reconciliation failed for target item: ' . implode('; ', $target_pre_recon['reconciliation_errors']));
            }

            // Verify FIFO batch availability for the source
            fifo_verify_pre_issuance($pdo, $source_id, $source_branch_id, $quantity);
        }

        // Deduct source aggregate inventory
        $source_update = $pdo->prepare("UPDATE inventory_items SET quantity = ? WHERE id = ?");
        $source_update->execute([$source_new_quantity, $source_id]);

        $source_note = $notes !== ''
            ? $notes
            : 'Branch transfer to ' . ($target['branch_name'] ?? 'target branch');
        $target_note = $notes !== ''
            ? $notes
            : 'Branch transfer from ' . ($source['branch_name'] ?? 'source branch');

        // Record source stock_out transaction and capture inserted ID
        $source_tx_id = inventory_api_log_transaction($source_id, 'stock_out', $quantity, $source_note, 'branch_transfer', $target_id);

        // Allocate source batches using fifo_allocate_for_transaction() if FIFO is active
        if ($is_fifo_active) {
            fifo_allocate_for_transaction($pdo, $source_tx_id, $source_id, $source_branch_id, $quantity, $source_note);
        }

        // Increase destination aggregate inventory
        $target_update = $pdo->prepare("UPDATE inventory_items SET quantity = ?, last_restock_date = CURDATE() WHERE id = ?");
        $target_update->execute([$target_new_quantity, $target_id]);

        // Record destination stock_in transaction and capture inserted ID
        $target_tx_id = inventory_api_log_transaction($target_id, 'stock_in', $quantity, $target_note, 'branch_transfer', $source_id);

        // Create destination FIFO batch using fifo_create_batch() if FIFO is active
        if ($is_fifo_active) {
            $receipt_timestamp = date('Y-m-d H:i:s');
            $source_ref = 'DIR-XFER: Item #' . $source_id . ' -> Item #' . $target_id;
            fifo_create_batch($pdo, [
                'item_id' => $target_id,
                'branch_id' => $target_branch_id,
                'source_type' => 'transfer_receipt',
                'source_id' => $target_tx_id,
                'source_sequence' => 1,
                'source_ref' => $source_ref,
                'initial_quantity' => $quantity,
                'received_at' => $receipt_timestamp,
                'is_legacy' => 0,
            ]);

            // Reconcile source and destination aggregate quantities against batch balances
            $source_post_recon = fifo_reconcile_item_stock($pdo, $source_id, $source_branch_id);
            if (!$source_post_recon['is_reconciled']) {
                throw new Exception('Post-transfer FIFO parity reconciliation failed for source item: ' . implode('; ', $source_post_recon['reconciliation_errors']));
            }

            $target_post_recon = fifo_reconcile_item_stock($pdo, $target_id, $target_branch_id);
            if (!$target_post_recon['is_reconciled']) {
                throw new Exception('Post-transfer FIFO parity reconciliation failed for target item: ' . implode('; ', $target_post_recon['reconciliation_errors']));
            }
        }

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
            'from_branch_id' => $source_branch_id,
            'to_branch_id' => $target_branch_id,
        ]);

        inventory_api_notify_branch_transfer($source, $target, $quantity);

        $pdo->commit();

        inventory_api_finish(true, 'Stock transferred successfully', 200, [
            'source_quantity' => $source_new_quantity,
            'target_quantity' => $target_new_quantity,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Stock transfer error: ' . $e->getMessage());
        inventory_api_finish(false, $e->getMessage(), 400);
    }
}

// Handle Stock Out
if ($action === 'stock_out') {
    $started_transaction = false;
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

        // ---------------------------------------------------------------------
        // Transaction-Safe Stock-Out Execution
        // ---------------------------------------------------------------------
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started_transaction = true;
        }

        // Lock item row FOR UPDATE and verify branch accessibility
        $item_stmt = $pdo->prepare("
            SELECT i.*, b.has_inventory, b.status AS branch_status, b.name AS branch_name
            FROM inventory_items i
            LEFT JOIN branches b ON b.id = i.branch_id
            WHERE i.id = ? AND i.status = 'active'
            FOR UPDATE
        ");
        $item_stmt->execute([$item_id]);
        $item = $item_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            throw new Exception('Inventory item not found');
        }

        if ((int) ($item['has_inventory'] ?? 0) !== 1 || ($item['branch_status'] ?? '') !== 'active') {
            throw new Exception('Inventory is only available for active inventory branches');
        }

        if (!has_branch_access($item['branch_id'])) {
            throw new Exception('Unauthorized access');
        }

        $branch_id = (int) ($item['branch_id'] ?? 0);
        if ($branch_id <= 0) {
            throw new Exception("Inventory item #{$item_id} has invalid branch association");
        }

        // Validate current quantity and guard against signed 32-bit INT boundaries
        $max_signed_int = 2147483647;
        if (!array_key_exists('quantity', $item) || $item['quantity'] === null) {
            throw new Exception("Inventory item #{$item_id} has NULL or missing quantity in inventory_items");
        }

        if (!is_numeric($item['quantity'])) {
            throw new Exception("Inventory item #{$item_id} has non-numeric quantity in database");
        }

        $raw_old_quantity = (string) $item['quantity'];
        if (!preg_match('/^-?\d+$/', $raw_old_quantity)) {
            throw new Exception("Inventory item #{$item_id} has malformed quantity in database: '{$raw_old_quantity}'");
        }

        $old_quantity = (int) $item['quantity'];
        if ($old_quantity < 0) {
            throw new Exception("Inventory item #{$item_id} has invalid negative quantity ({$old_quantity})");
        }

        if ($old_quantity > $max_signed_int) {
            throw new Exception("Inventory item #{$item_id} exceeds maximum allowable integer limit ({$max_signed_int})");
        }

        if ($old_quantity < $quantity) {
            throw new Exception('Insufficient stock available. Current: ' . $old_quantity);
        }

        $new_quantity = $old_quantity - $quantity;
        if ($new_quantity < 0) {
            throw new Exception("Stock deduction would result in negative quantity ({$new_quantity})");
        }

        $tag_data = inventory_api_resolve_stock_out_tag($branch_id);

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

        $note_parts = [];
        if ($reason_label !== '') {
            $note_parts[] = 'Reason: ' . $reason_label;
        }
        if ($notes !== '' && $notes !== 'Manual stock out from Front Desk Inventory') {
            $note_parts[] = 'Remarks: ' . $notes;
        }
        $final_notes = !empty($note_parts) ? implode(' | ', $note_parts) : ($notes ?: 'Manual stock out from Front Desk Inventory');

        $is_fifo_active = inventory_api_is_fifo_active();

        // When FIFO is active, validate pre-issuance parity and candidate batch availability
        if ($is_fifo_active) {
            require_once __DIR__ . '/../includes/fifo-inventory.php';

            $pre_recon = fifo_reconcile_item_stock($pdo, $item_id, $branch_id);
            if (!$pre_recon['is_reconciled']) {
                throw new Exception('Pre-stock-out FIFO parity reconciliation failed: ' . implode('; ', $pre_recon['reconciliation_errors']));
            }

            fifo_verify_pre_issuance($pdo, $item_id, $branch_id, $quantity);
        }

        // Deduct aggregate inventory stock
        $update_stmt = $pdo->prepare("UPDATE inventory_items SET quantity = ? WHERE id = ?");
        $update_stmt->execute([$new_quantity, $item_id]);

        // HWTIRES Revision 4: Customer warranty snapshot for direct sales
        $warranty_data = [];
        $is_customer_sale = ($reason_type === 'direct_sale') || ($reason_type === '' && (!empty($tag_data['customer_id']) || !empty($tag_data['vehicle_id'])));

        if ($is_customer_sale) {
            $cat_has = array_key_exists('has_warranty', $item) && $item['has_warranty'] !== null
                ? (int) $item['has_warranty']
                : null;

            if ($cat_has === 1) {
                $duration = !empty($item['warranty_duration']) ? (int) $item['warranty_duration'] : null;
                $unit = !empty($item['warranty_unit']) ? (string) $item['warranty_unit'] : null;
                $coverage = !empty($item['warranty_coverage']) ? (string) $item['warranty_coverage'] : null;
                $conditions = !empty($item['warranty_conditions']) ? (string) $item['warranty_conditions'] : null;

                $sale_date = date('Y-m-d');
                $expiry_date = null;
                if ($duration && $duration > 0 && in_array($unit, ['days', 'months', 'years'], true)) {
                    if (function_exists('app_calculate_warranty_expiry_date')) {
                        $expiry_date = app_calculate_warranty_expiry_date($sale_date, $duration, $unit);
                    }
                }

                $warranty_data = [
                    'warranty_snapshot_has' => 1,
                    'warranty_duration' => $duration,
                    'warranty_unit' => $unit,
                    'warranty_coverage' => $coverage,
                    'warranty_conditions' => $conditions,
                    'warranty_start_date' => $sale_date,
                    'warranty_expiry_date' => $expiry_date,
                ];
            } elseif ($cat_has === 0) {
                $warranty_data = [
                    'warranty_snapshot_has' => 0,
                    'warranty_duration' => null,
                    'warranty_unit' => null,
                    'warranty_coverage' => null,
                    'warranty_conditions' => null,
                    'warranty_start_date' => null,
                    'warranty_expiry_date' => null,
                ];
            } else {
                $warranty_data = [
                    'warranty_snapshot_has' => null,
                    'warranty_duration' => null,
                    'warranty_unit' => null,
                    'warranty_coverage' => null,
                    'warranty_conditions' => null,
                    'warranty_start_date' => null,
                    'warranty_expiry_date' => null,
                ];
            }
        }

        // Record stock_out inventory transaction and capture inserted ID
        $transaction_id = inventory_api_log_transaction(
            $item_id,
            'stock_out',
            $quantity,
            $final_notes,
            $reason_type ?: null,
            null,
            $tag_data,
            $warranty_data
        );

        if (!$transaction_id || $transaction_id <= 0) {
            $transaction_id = (int) $pdo->lastInsertId();
        }

        if ($transaction_id <= 0) {
            throw new Exception('Failed to record stock-out inventory transaction');
        }

        // When FIFO is active, allocate stock from eligible batches in FIFO order
        if ($is_fifo_active) {
            fifo_allocate_for_transaction(
                $pdo,
                $transaction_id,
                $item_id,
                $branch_id,
                $quantity,
                $final_notes
            );

            // Verify post-issuance parity
            $post_recon = fifo_reconcile_item_stock($pdo, $item_id, $branch_id);
            if (!$post_recon['is_reconciled']) {
                throw new Exception('Post-stock-out FIFO parity reconciliation failed: ' . implode('; ', $post_recon['reconciliation_errors']));
            }
        }

        if (!empty($tag_data['customer_id'])) {
            app_touch_customer_branch_record((int) $tag_data['customer_id'], $branch_id, $user['id'] ?? null);
        }

        if ($started_transaction && $pdo->inTransaction()) {
            $pdo->commit();
        }

        log_audit('inventory_items', 'stock_out', $item_id, ['quantity' => $old_quantity], [
            'quantity' => $new_quantity,
            'reason_type' => $reason_type,
            'customer_id' => $tag_data['customer_id'] ?? null,
            'vehicle_id' => $tag_data['vehicle_id'] ?? null,
        ]);

        inventory_api_finish(true, 'Stock removed successfully', 200, ['quantity' => $new_quantity]);
    } catch (Throwable $e) {
        if ($started_transaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

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
        if ($item_branch_id <= 0) {
            throw new Exception("Inventory item #{$item_id} has invalid branch association");
        }

        // Branch Isolation: Admin can adjust any branch, Front Desk can only adjust own branch
        if ($user_role === 'front-desk') {
            $user_branch_id = (int) ($user['branch_id'] ?? 0);
            if ($item_branch_id !== $user_branch_id) {
                throw new Exception('Unauthorized: Front Desk can only adjust inventory for their assigned branch');
            }
        } elseif (!has_branch_access($item_branch_id)) {
            throw new Exception('Unauthorized branch access');
        }

        // Validate aggregate quantity from locked database row
        $max_signed_int = 2147483647;
        if (!array_key_exists('quantity', $item) || $item['quantity'] === null) {
            throw new Exception("Inventory item #{$item_id} has NULL or missing quantity in inventory_items");
        }

        if (!is_numeric($item['quantity'])) {
            throw new Exception("Inventory item #{$item_id} has non-numeric quantity in database");
        }

        $raw_old_quantity = (string) $item['quantity'];
        if (!preg_match('/^-?\d+$/', $raw_old_quantity)) {
            throw new Exception("Inventory item #{$item_id} has malformed quantity in database: '{$raw_old_quantity}'");
        }

        $old_quantity = (int) $item['quantity'];
        if ($old_quantity < 0) {
            throw new Exception("Inventory item #{$item_id} has invalid negative quantity ({$old_quantity})");
        }

        if ($old_quantity > $max_signed_int) {
            throw new Exception("Inventory item #{$item_id} exceeds maximum allowable integer limit ({$max_signed_int})");
        }

        $new_quantity = (int) $physical_quantity;
        if ($new_quantity < 0 || $new_quantity > $max_signed_int) {
            throw new Exception("Target physical quantity exceeds allowable integer limit ({$max_signed_int})");
        }

        $difference = $new_quantity - $old_quantity;

        if ($difference === 0) {
            $pdo->rollBack();
            inventory_api_finish(false, 'Physical count matches the current system quantity (' . $old_quantity . '). No adjustment needed.', 400);
        }

        $diff_formatted = ($difference > 0 ? '+' : '') . $difference;
        $final_notes = "Stock correction: {$old_quantity} → {$new_quantity} (Difference: {$diff_formatted}). Reason: {$category_label} | Remarks: {$remarks}";

        $is_fifo_active = inventory_api_is_fifo_active();

        // When FIFO is active, perform pre-adjustment parity reconciliation
        if ($is_fifo_active) {
            require_once __DIR__ . '/../includes/fifo-inventory.php';

            $pre_recon = fifo_reconcile_item_stock($pdo, $item_id, $item_branch_id);
            if (!$pre_recon['is_reconciled']) {
                throw new Exception('Pre-adjustment FIFO parity reconciliation failed: ' . implode('; ', $pre_recon['reconciliation_errors']));
            }
        }

        if ($difference > 0) {
            // Positive adjustment (recount up)
            $ref_type = 'inventory_recount_up';
            $adjustment_qty = $difference;

            $update_stmt = $pdo->prepare("UPDATE inventory_items SET quantity = ?, updated_at = NOW() WHERE id = ?");
            $update_stmt->execute([$new_quantity, $item_id]);

            $transaction_id = inventory_api_log_transaction(
                $item_id,
                'adjustment',
                $adjustment_qty,
                $final_notes,
                $ref_type,
                null
            );

            if (!$transaction_id || $transaction_id <= 0) {
                $transaction_id = (int) $pdo->lastInsertId();
            }

            if ($transaction_id <= 0) {
                throw new Exception('Failed to record stock adjustment inventory transaction');
            }

            if ($is_fifo_active) {
                fifo_create_batch($pdo, [
                    'item_id' => $item_id,
                    'branch_id' => $item_branch_id,
                    'source_type' => 'adjustment_positive',
                    'source_id' => $transaction_id,
                    'source_sequence' => 1,
                    'source_ref' => $reason_category,
                    'initial_quantity' => $adjustment_qty,
                    'received_at' => date('Y-m-d H:i:s'),
                ]);
            }
        } else {
            // Negative adjustment (recount down)
            $ref_type = 'inventory_recount_down';
            $reduction_quantity = abs($difference);

            if ($is_fifo_active) {
                fifo_verify_pre_issuance(
                    $pdo,
                    $item_id,
                    $item_branch_id,
                    $reduction_quantity
                );
            }

            $update_stmt = $pdo->prepare("UPDATE inventory_items SET quantity = ?, updated_at = NOW() WHERE id = ?");
            $update_stmt->execute([$new_quantity, $item_id]);

            $transaction_id = inventory_api_log_transaction(
                $item_id,
                'adjustment',
                $reduction_quantity,
                $final_notes,
                $ref_type,
                null
            );

            if (!$transaction_id || $transaction_id <= 0) {
                $transaction_id = (int) $pdo->lastInsertId();
            }

            if ($transaction_id <= 0) {
                throw new Exception('Failed to record stock adjustment inventory transaction');
            }

            if ($is_fifo_active) {
                fifo_allocate_for_transaction(
                    $pdo,
                    $transaction_id,
                    $item_id,
                    $item_branch_id,
                    $reduction_quantity,
                    $final_notes
                );
            }
        }

        // When FIFO is active, verify post-adjustment parity
        if ($is_fifo_active) {
            $post_recon = fifo_reconcile_item_stock($pdo, $item_id, $item_branch_id);
            if (!$post_recon['is_reconciled']) {
                throw new Exception('Post-adjustment FIFO parity reconciliation failed: ' . implode('; ', $post_recon['reconciliation_errors']));
            }
        }

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
            'id' => $item_id,
            'old_quantity' => $old_quantity,
            'new_quantity' => $new_quantity,
            'difference' => $difference,
        ]);
    } catch (Throwable $e) {
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

            $category = strtolower(trim((string) $sourceItem['category']));
            if (!in_array($category, ['tire', 'accessory', 'part'], true)) {
                throw new Exception('Invalid source item category.');
            }
            $source_cat_prefix = ($category === 'tire') ? 'TIR' : (($category === 'accessory') ? 'ACC' : 'PAR');

            // Determine canonical catalog number
            $source_catalog_num = 0;
            if (preg_match('/^B[0-9]+-(?:TIR|PAR|ACC)-([0-9]{5})$/', (string)($sourceItem['sku'] ?? ''), $sm)) {
                $source_catalog_num = (int) $sm[1];
            } else {
                // If source product was registered with a manual SKU, check if any sibling item matching the same specs has an assigned catalog number
                $stmtCatCheck = $pdo->prepare("
                    SELECT sku
                    FROM inventory_items
                    WHERE category = ?
                      AND LOWER(TRIM(item_name)) = LOWER(TRIM(?))
                      AND LOWER(TRIM(COALESCE(brand, ''))) = LOWER(TRIM(COALESCE(?, '')))
                      AND LOWER(TRIM(COALESCE(model, ''))) = LOWER(TRIM(COALESCE(?, '')))
                      AND LOWER(TRIM(COALESCE(size, ''))) = LOWER(TRIM(COALESCE(?, '')))
                      AND sku REGEXP '^B[0-9]+-(TIR|PAR|ACC)-[0-9]{5}$'
                    ORDER BY id ASC
                    LIMIT 1
                ");
                $stmtCatCheck->execute([
                    $sourceItem['category'],
                    $sourceItem['item_name'],
                    $sourceItem['brand'] ?? '',
                    $sourceItem['model'] ?? '',
                    $sourceItem['size'] ?? '',
                ]);
                $matchedSku = $stmtCatCheck->fetchColumn();

                if ($matchedSku && preg_match('/^B[0-9]+-(?:TIR|PAR|ACC)-([0-9]{5})$/', $matchedSku, $catMatches)) {
                    $source_catalog_num = (int) $catMatches[1];
                } else {
                    // Allocate next catalog sequence number for this canonical company product family
                    $source_catalog_num = $last_catalog_number + 1;
                    $stmtUpdateSeq = $pdo->prepare("
                        UPDATE inventory_catalog_sequence
                        SET last_catalog_number = ?
                        WHERE sequence_key = 'global_catalog'
                    ");
                    $stmtUpdateSeq->execute([$source_catalog_num]);
                    $last_catalog_number = $source_catalog_num;
                }
            }

            if ($submitted_catalog_num > 0 && $submitted_catalog_num !== $source_catalog_num) {
                throw new Exception('Submitted catalog number does not match the source product catalog identity.');
            }

            // Inherit canonical product specs
            $item_name = $sourceItem['item_name'];
            $brand = $sourceItem['brand'];
            $model = $sourceItem['model'];
            $size = $sourceItem['size'];

            // Format destination identifiers (derived branch-specific formula)
            $sku = sprintf('B%d-%s-%05d', $branch_id, $source_cat_prefix, $source_catalog_num);
            $suffix = (($source_catalog_num * 7) % 89) + 10;
            $serial_number = sprintf('HW-%s-B%d-%05d-%02d', $source_cat_prefix, $branch_id, $source_catalog_num, $suffix);

            // Check if destination branch already carries this product (by identifier or canonical specifications)
            $stmtBranchCheck = $pdo->prepare("
                SELECT id, item_name, status
                FROM inventory_items
                WHERE branch_id = ?
                  AND (
                      sku = ?
                      OR serial_number = ?
                      OR (
                          category = ?
                          AND LOWER(TRIM(item_name)) = LOWER(TRIM(?))
                          AND LOWER(TRIM(COALESCE(brand, ''))) = LOWER(TRIM(COALESCE(?, '')))
                          AND LOWER(TRIM(COALESCE(model, ''))) = LOWER(TRIM(COALESCE(?, '')))
                          AND LOWER(TRIM(COALESCE(size, ''))) = LOWER(TRIM(COALESCE(?, '')))
                      )
                  )
                LIMIT 1
            ");
            $stmtBranchCheck->execute([
                $branch_id,
                $sku,
                $serial_number,
                $category,
                $item_name,
                $brand ?? '',
                $model ?? '',
                $size ?? '',
            ]);
            $existingBranchItem = $stmtBranchCheck->fetch(PDO::FETCH_ASSOC);
            if ($existingBranchItem) {
                $status_msg = ($existingBranchItem['status'] === 'active') ? 'active' : 'archived';
                throw new Exception("This product is already registered at this branch as Item #{$existingBranchItem['id']} ({$status_msg}).");
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

            // Manual SKU & Inventory Serial Number (Required for Brand-New Product under Rule 1)
            $sku = inventory_api_clean_text($_POST['sku'] ?? '', 'SKU', 100, true);
            $serial_number = inventory_api_clean_text($_POST['serial_number'] ?? '', 'Inventory Serial Number', 100, true);

            // Branch-level duplicate check for matching product specifications or identifiers
            $stmtBranchCheck = $pdo->prepare("
                SELECT id, item_name, status
                FROM inventory_items
                WHERE branch_id = ?
                  AND (
                      sku = ?
                      OR serial_number = ?
                      OR (
                          category = ?
                          AND LOWER(TRIM(item_name)) = LOWER(TRIM(?))
                          AND LOWER(TRIM(COALESCE(brand, ''))) = LOWER(TRIM(COALESCE(?, '')))
                          AND LOWER(TRIM(COALESCE(model, ''))) = LOWER(TRIM(COALESCE(?, '')))
                          AND LOWER(TRIM(COALESCE(size, ''))) = LOWER(TRIM(COALESCE(?, '')))
                      )
                  )
                LIMIT 1
            ");
            $stmtBranchCheck->execute([
                $branch_id,
                $sku,
                $serial_number,
                $category,
                $item_name,
                $brand ?: '',
                $model ?: '',
                $size ?: '',
            ]);
            $existingBranchItem = $stmtBranchCheck->fetch(PDO::FETCH_ASSOC);
            if ($existingBranchItem) {
                $status_msg = ($existingBranchItem['status'] === 'active') ? 'active' : 'archived';
                throw new Exception("A matching product or identifier is already registered at this branch as Item #{$existingBranchItem['id']} ({$status_msg}).");
            }
        }

        // Uniqueness checks (system-wide active inventory)
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

        // Warranty terms processing for new item
        $raw_has_warranty = trim((string) ($_POST['has_warranty'] ?? ''));
        $has_warranty = null;
        $warranty_duration = null;
        $warranty_unit = null;
        $warranty_coverage = null;
        $warranty_conditions = null;

        if ($raw_has_warranty === '1') {
            $has_warranty = 1;
            $warranty_duration = inventory_api_clean_int($_POST['warranty_duration'] ?? '', 'Warranty duration', 1, 120);
            $raw_unit = strtolower(trim((string) ($_POST['warranty_unit'] ?? '')));
            if (!in_array($raw_unit, ['days', 'months', 'years'], true)) {
                throw new Exception('Please select a valid warranty unit (days, months, or years).');
            }
            $warranty_unit = $raw_unit;
            $warranty_coverage = inventory_api_clean_text($_POST['warranty_coverage'] ?? '', 'Warranty coverage', 255, true);
            $warranty_conditions = inventory_api_clean_text($_POST['warranty_conditions'] ?? '', 'Warranty conditions and exclusions', 2000, false) ?: null;
        } elseif ($raw_has_warranty === '0') {
            $has_warranty = 0;
        } else {
            // Null / Not Specified
            $has_warranty = null;
        }

        if (app_column_exists('inventory_items', 'has_warranty')) {
            $insert_columns[] = 'has_warranty';
            $insert_values[] = $has_warranty;
        }
        if (app_column_exists('inventory_items', 'warranty_duration')) {
            $insert_columns[] = 'warranty_duration';
            $insert_values[] = $warranty_duration;
        }
        if (app_column_exists('inventory_items', 'warranty_unit')) {
            $insert_columns[] = 'warranty_unit';
            $insert_values[] = $warranty_unit;
        }
        if (app_column_exists('inventory_items', 'warranty_coverage')) {
            $insert_columns[] = 'warranty_coverage';
            $insert_values[] = $warranty_coverage;
        }
        if (app_column_exists('inventory_items', 'warranty_conditions')) {
            $insert_columns[] = 'warranty_conditions';
            $insert_values[] = $warranty_conditions;
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
            SELECT inc.*, i.item_name, i.category, i.status AS item_status, i.branch_id AS item_branch_id, i.quantity AS current_item_qty
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

        // Validate inventory item active status
        if (($incoming['item_status'] ?? '') !== 'active') {
            throw new Exception("Inventory item #{$item_id} is not active (status: '{$incoming['item_status']}')");
        }

        // Validate current quantity, reject NULL or negative, guard against signed 32-bit INT overflow
        $max_signed_int = 2147483647;
        if (!array_key_exists('current_item_qty', $incoming) || $incoming['current_item_qty'] === null) {
            throw new Exception("Inventory item #{$item_id} has NULL or missing quantity in inventory_items");
        }
        $old_quantity = (int) $incoming['current_item_qty'];
        if ($old_quantity < 0) {
            throw new Exception("Inventory item #{$item_id} has invalid negative quantity ({$old_quantity}) in inventory_items");
        }
        if ($old_quantity > $max_signed_int - $actual_quantity) {
            throw new Exception("Stock receipt of {$actual_quantity} units would exceed maximum allowable integer limit ({$max_signed_int})");
        }
        $new_quantity = $old_quantity + $actual_quantity;

        $is_fifo_active = inventory_api_is_fifo_active();

        // If FIFO is active in the future, perform pre-receipt parity check
        if ($is_fifo_active) {
            require_once __DIR__ . '/../includes/fifo-inventory.php';
            $pre_recon = fifo_reconcile_item_stock($pdo, $item_id, $incoming_branch_id);
            if (!$pre_recon['is_reconciled']) {
                throw new Exception('Pre-receipt FIFO parity reconciliation failed: ' . implode('; ', $pre_recon['reconciliation_errors']));
            }
        }

        // 1. Update inventory_items quantity and restock date
        $update_item = $pdo->prepare("
            UPDATE inventory_items
            SET quantity = ?,
                last_restock_date = CURDATE(),
                updated_at = NOW()
            WHERE id = ?
        ");
        $update_item->execute([$new_quantity, $item_id]);

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

        // 3. Log stock_in transaction and capture ID
        $transaction_id = inventory_api_log_transaction(
            $item_id,
            'stock_in',
            $actual_quantity,
            $final_notes,
            $source_type ?: 'supplier_delivery',
            $incoming_id
        );

        if (!$transaction_id || $transaction_id <= 0) {
            $transaction_id = (int) $pdo->lastInsertId();
        }

        if ($transaction_id <= 0) {
            throw new Exception('Failed to record stock-in inventory transaction for incoming delivery');
        }

        $received_at = date('Y-m-d H:i:s');

        // 4. Update incoming delivery record
        $update_incoming = $pdo->prepare("
            UPDATE inventory_incoming_stock
            SET actual_quantity = ?,
                status = 'received',
                received_by = ?,
                received_at = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $update_incoming->execute([
            $actual_quantity,
            (int) ($user['id'] ?? 0),
            $received_at,
            $incoming_id
        ]);

        // 5. If FIFO is active in the future, create batch and perform post-receipt parity check
        if ($is_fifo_active) {
            fifo_create_batch($pdo, [
                'item_id' => $item_id,
                'branch_id' => $incoming_branch_id,
                'source_type' => 'incoming_stock',
                'source_id' => $incoming_id,
                'source_sequence' => 1,
                'source_ref' => $reference_number !== '' ? $reference_number : null,
                'initial_quantity' => $actual_quantity,
                'received_at' => $received_at,
            ]);

            $post_recon = fifo_reconcile_item_stock($pdo, $item_id, $incoming_branch_id);
            if (!$post_recon['is_reconciled']) {
                throw new Exception('Post-receipt FIFO parity reconciliation failed: ' . implode('; ', $post_recon['reconciliation_errors']));
            }
        }

        // 6. Admin Discrepancy Notification
        if ((int) $actual_quantity !== (int) $incoming['expected_quantity']) {
            if (app_table_exists('transfer_notifications')) {
                $admin_stmt = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
                $admin_users = $admin_stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($admin_users)) {
                    $branch_stmt = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
                    $branch_stmt->execute([$incoming_branch_id]);
                    $raw_branch_name = $branch_stmt->fetchColumn() ?: ('Branch #' . $incoming_branch_id);
                    $branch_display_name = function_exists('app_branch_label') ? app_branch_label($raw_branch_name, 'Branch') : $raw_branch_name;

                    $expected_qty = (int) $incoming['expected_quantity'];
                    $actual_qty = (int) $actual_quantity;
                    $diff = $actual_qty - $expected_qty;
                    $abs_diff = abs($diff);
                    $unit_word = ($abs_diff === 1) ? 'unit' : 'units';
                    $diff_text = ($diff < 0) ? "{$abs_diff} {$unit_word} short" : "{$abs_diff} {$unit_word} over";

                    $notif_title = 'Stock Arrival Discrepancy';
                    $notif_item_name = trim((string) ($incoming['item_name'] ?? 'Inventory Item'));
                    $notif_message = sprintf(
                        '%s — Expected %d %s, received %d %s at %s (%s).',
                        $notif_item_name,
                        $expected_qty,
                        ($expected_qty === 1 ? 'unit' : 'units'),
                        $actual_qty,
                        ($actual_qty === 1 ? 'unit' : 'units'),
                        $branch_display_name,
                        $diff_text
                    );
                    $notif_url = '/hwtires/admin/tire-inventory/transactions.php';

                    $notif_insert = $pdo->prepare("
                        INSERT INTO transfer_notifications
                            (branch_id, user_id, transfer_request_id, title, message, type, action_url, is_read, created_at)
                        VALUES (?, ?, NULL, ?, ?, 'warning', ?, 0, NOW())
                    ");

                    foreach ($admin_users as $admin) {
                        $notif_insert->execute([
                            $incoming_branch_id,
                            (int) $admin['id'],
                            $notif_title,
                            $notif_message,
                            $notif_url
                        ]);
                    }
                } else {
                    error_log('Discrepancy notification: No active admin users found to notify for incoming stock id ' . $incoming_id);
                }
            }
        }

        // 7. Audit logs
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
            'transaction_id' => $transaction_id,
        ]);

        $pdo->commit();

        $success_msg = 'Stock arrival confirmed successfully (' . $actual_quantity . ' unit(s) added to inventory).';
        inventory_api_finish(true, $success_msg, 200, [
            'id' => $item_id,
            'incoming_id' => $incoming_id,
            'received_quantity' => $actual_quantity,
            'new_quantity' => $new_quantity,
        ]);
    } catch (Throwable $e) {
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

if ($action === 'update_warranty_terms') {
    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        if (($user['role'] ?? '') !== 'admin') {
            throw new Exception('Only administrators can update product warranty terms.');
        }

        $item_id = intval($_POST['item_id'] ?? 0);
        if ($item_id <= 0) {
            throw new Exception('Invalid inventory item ID.');
        }

        $stmt = $pdo->prepare("
            SELECT id, branch_id, item_name, category, brand, model, size, sku, has_warranty, warranty_duration, warranty_unit, warranty_coverage, warranty_conditions
            FROM inventory_items
            WHERE id = ?
        ");
        $stmt->execute([$item_id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            throw new Exception('Inventory item not found.');
        }

        if (!has_branch_access((int) $item['branch_id'])) {
            throw new Exception('Unauthorized branch access.');
        }

        $raw_has_warranty = trim((string) ($_POST['has_warranty'] ?? ''));
        $has_warranty = null;
        $warranty_duration = null;
        $warranty_unit = null;
        $warranty_coverage = null;
        $warranty_conditions = null;

        if ($raw_has_warranty === '1') {
            $has_warranty = 1;
            $warranty_duration = inventory_api_clean_int($_POST['warranty_duration'] ?? '', 'Warranty duration', 1, 120);
            $raw_unit = strtolower(trim((string) ($_POST['warranty_unit'] ?? '')));
            if (!in_array($raw_unit, ['days', 'months', 'years'], true)) {
                throw new Exception('Please select a valid warranty unit (days, months, or years).');
            }
            $warranty_unit = $raw_unit;
            $warranty_coverage = inventory_api_clean_text($_POST['warranty_coverage'] ?? '', 'Warranty coverage', 255, true);
            $warranty_conditions = inventory_api_clean_text($_POST['warranty_conditions'] ?? '', 'Warranty conditions and exclusions', 2000, false) ?: null;
        } elseif ($raw_has_warranty === '0') {
            $has_warranty = 0;
        } else {
            // Null / Not Specified
            $has_warranty = null;
        }

        // Backward compatibility check for unmigrated database
        if (!app_column_exists('inventory_items', 'has_warranty')) {
            throw new Exception('Warranty schema is not yet available in the database.');
        }

        $pdo->beginTransaction();

        $update_stmt = $pdo->prepare("
            UPDATE inventory_items
            SET has_warranty = ?,
                warranty_duration = ?,
                warranty_unit = ?,
                warranty_coverage = ?,
                warranty_conditions = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $update_stmt->execute([
            $has_warranty,
            $warranty_duration,
            $warranty_unit,
            $warranty_coverage,
            $warranty_conditions,
            $item_id,
        ]);

        log_audit('inventory_items', 'update_warranty', $item_id, [
            'has_warranty' => $item['has_warranty'] ?? null,
            'warranty_duration' => $item['warranty_duration'] ?? null,
            'warranty_unit' => $item['warranty_unit'] ?? null,
            'warranty_coverage' => $item['warranty_coverage'] ?? null,
            'warranty_conditions' => $item['warranty_conditions'] ?? null,
        ], [
            'has_warranty' => $has_warranty,
            'warranty_duration' => $warranty_duration,
            'warranty_unit' => $warranty_unit,
            'warranty_coverage' => $warranty_coverage,
            'warranty_conditions' => $warranty_conditions,
        ]);

        $pdo->commit();

        inventory_api_finish(true, 'Warranty terms updated successfully.', 200, ['id' => $item_id]);
    } catch (Exception $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Update warranty terms error: ' . $e->getMessage());
        inventory_api_finish(false, $e->getMessage(), 400);
    }
}

inventory_api_finish(false, 'Invalid action', 400);
?>
