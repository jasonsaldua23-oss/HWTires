<?php
/**
 * Quotations API Handler
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

if (!function_exists('quotation_item_inventory_notes')) {
    function quotation_item_inventory_notes($item) {
        $inventory_item_id = intval($item['inventory_item_id'] ?? 0);
        $inventory_branch_id = intval($item['inventory_branch_id'] ?? 0);

        if ($inventory_item_id <= 0 && $inventory_branch_id <= 0) {
            return null;
        }

        return json_encode([
            'inventory_item_id' => $inventory_item_id > 0 ? $inventory_item_id : null,
            'inventory_branch_id' => $inventory_branch_id > 0 ? $inventory_branch_id : null,
        ], JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('quotation_generate_transfer_request_number')) {
    function quotation_generate_transfer_request_number(PDO $pdo) {
        $date = date('Ymd');
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM inter_branch_transfer_requests
            WHERE request_number LIKE ?
        ");
        $stmt->execute(['TXF-' . $date . '-%']);
        $next = ((int) $stmt->fetchColumn()) + 1;

        return sprintf('TXF-%s-%04d', $date, $next);
    }
}

if (!function_exists('quotation_create_item_request')) {
    function quotation_create_item_request(PDO $pdo, array $user, int $quotation_id, string $quotation_number, int $customer_id, int $requesting_branch_id, array $item) {
        $inventory_item_id = (int) ($item['inventory_item_id'] ?? 0);
        $donor_branch_id = (int) ($item['inventory_branch_id'] ?? 0);
        $quantity = max(1, (int) ($item['quantity'] ?? 1));

        if ($inventory_item_id <= 0 || $donor_branch_id <= 0 || $donor_branch_id === $requesting_branch_id) {
            return;
        }

        $branch_stmt = $pdo->prepare("SELECT id, name FROM branches WHERE id = ? AND status = 'active' LIMIT 1");
        $branch_stmt->execute([$requesting_branch_id]);
        $requesting_branch = $branch_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$requesting_branch) {
            return;
        }

        $item_stmt = $pdo->prepare("
            SELECT i.id, i.item_name, i.quantity, i.branch_id, b.name AS branch_name
            FROM inventory_items i
            INNER JOIN branches b ON b.id = i.branch_id
            WHERE i.id = ?
              AND i.branch_id = ?
              AND i.status = 'active'
              AND b.status = 'active'
              AND b.has_inventory = 1
            LIMIT 1
        ");
        $item_stmt->execute([$inventory_item_id, $donor_branch_id]);
        $donor_item = $item_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$donor_item) {
            return;
        }

        $duplicate_stmt = $pdo->prepare("
            SELECT id
            FROM inter_branch_transfer_requests
            WHERE quotation_id = ?
              AND item_id = ?
              AND requesting_branch_id = ?
              AND donor_branch_id = ?
              AND status IN ('pending', 'approved', 'shipped')
            LIMIT 1
        ");
        $duplicate_stmt->execute([$quotation_id, $inventory_item_id, $requesting_branch_id, $donor_branch_id]);

        if ($duplicate_stmt->fetch()) {
            return;
        }

        $request_number = quotation_generate_transfer_request_number($pdo);
        $reason = 'Service operation ' . $quotation_number;
        $notes = 'Requested automatically because the service operation selected this item from ' . ($donor_item['branch_name'] ?? 'another branch') . '.';

        $insert_stmt = $pdo->prepare("
            INSERT INTO inter_branch_transfer_requests (
                request_number, requesting_branch_id, donor_branch_id, item_id, item_name,
                requested_quantity, reason, priority, quotation_id, customer_id, requested_by, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'medium', ?, ?, ?, ?)
        ");
        $insert_stmt->execute([
            $request_number,
            $requesting_branch_id,
            $donor_branch_id,
            $inventory_item_id,
            $donor_item['item_name'],
            $quantity,
            $reason,
            $quotation_id,
            $customer_id ?: null,
            $user['id'] ?? null,
            $notes,
        ]);

        $transfer_id = (int) $pdo->lastInsertId();

        $notification_stmt = $pdo->prepare("
            INSERT INTO transfer_notifications (
                branch_id, user_id, transfer_request_id, title, message, type, action_url
            ) VALUES (?, ?, ?, ?, ?, 'warning', ?)
        ");
        $users_stmt = $pdo->prepare("SELECT id, role FROM users WHERE branch_id = ? AND status = 'active'");
        $users_stmt->execute([$donor_branch_id]);

        $title = 'Service Item Request';
        $message = ($requesting_branch['name'] ?? 'Another branch') . ' requested ' . $quantity . 'x ' . $donor_item['item_name'] . ' for ' . $quotation_number . '.';

        foreach ($users_stmt->fetchAll(PDO::FETCH_ASSOC) as $branch_user) {
            $action_url = ($branch_user['role'] ?? '') === 'admin'
                ? '/hwtires/admin/transfers/?request=' . $transfer_id
                : '/hwtires/front-desk/tire-inventory/?transfer_request=' . $transfer_id . '#requested-items';

            $notification_stmt->execute([
                $donor_branch_id,
                $branch_user['id'],
                $transfer_id,
                $title,
                $message,
                $action_url,
            ]);
        }
    }
}

if (!function_exists('quotation_item_inventory_meta')) {
    function quotation_item_inventory_meta(array $item) {
        $notes = trim((string) ($item['notes'] ?? ''));
        if ($notes === '' || $notes[0] !== '{') {
            return [];
        }

        $decoded = json_decode($notes, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('quotation_log_inventory_transaction')) {
    function quotation_log_inventory_transaction(PDO $pdo, $item_id, $transaction_type, $quantity, $reference_type, $reference_id, $notes, $created_by, array $tags = []) {
        $columns = ['item_id', 'transaction_type', 'quantity', 'reference_type', 'reference_id', 'notes', 'created_by'];
        $values = [$item_id, $transaction_type, $quantity, $reference_type, $reference_id, $notes, $created_by];

        foreach (['customer_id', 'vehicle_id', 'job_order_id', 'quotation_id', 'quotation_item_id'] as $tag_column) {
            if (!array_key_exists($tag_column, $tags) || !app_column_exists('inventory_transactions', $tag_column)) {
                continue;
            }

            $tag_value = $tags[$tag_column] !== null ? intval($tags[$tag_column]) : null;
            $columns[] = $tag_column;
            $values[] = $tag_value && $tag_value > 0 ? $tag_value : null;
        }

        $stmt = $pdo->prepare("
            INSERT INTO inventory_transactions (" . implode(', ', $columns) . ")
            VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ")
        ");
        $stmt->execute($values);
    }
}

if (!function_exists('quotation_apply_inventory_deduction')) {
    function quotation_apply_inventory_deduction(PDO $pdo, array $quotation, array $items) {
        $quotation_id = (int) ($quotation['id'] ?? 0);
        if ($quotation_id <= 0) {
            throw new Exception('Invalid quotation for inventory deduction');
        }

        $deducted_stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM inventory_transactions\n            WHERE reference_type = 'quotation'\n              AND reference_id = ?\n              AND transaction_type = 'stock_out'\n        ");
        $deducted_stmt->execute([$quotation_id]);
        if ((int) $deducted_stmt->fetchColumn() > 0) {
            return 0;
        }

        $applied_count = 0;
        foreach ($items as $item) {
            $item_type = strtolower((string) ($item['item_type'] ?? ''));
            $source = strtolower((string) ($item['source'] ?? ''));

            if (!in_array($item_type, ['part', 'tire'], true) || in_array($source, ['external', 'customer_supplied'], true)) {
                continue;
            }

            $meta = quotation_item_inventory_meta($item);
            $inventory_item_id = (int) ($meta['inventory_item_id'] ?? 0);
            if ($inventory_item_id <= 0) {
                continue;
            }

            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $lock_stmt = $pdo->prepare("\n                SELECT i.id, i.item_name, i.quantity, i.branch_id\n                FROM inventory_items i\n                INNER JOIN branches b ON b.id = i.branch_id\n                WHERE i.id = ?\n                  AND i.status = 'active'\n                  AND b.status = 'active'\n                  AND b.has_inventory = 1\n                LIMIT 1 FOR UPDATE\n            ");
            $lock_stmt->execute([$inventory_item_id]);
            $inventory_item = $lock_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$inventory_item) {
                throw new Exception('Inventory item not found for ' . ($item['item_name'] ?? 'quotation item'));
            }

            $available = (int) ($inventory_item['quantity'] ?? 0);
            if ($available < $quantity) {
                throw new Exception('Insufficient stock for ' . ($inventory_item['item_name'] ?? 'item') . '. Current: ' . $available);
            }

            $update_stmt = $pdo->prepare("UPDATE inventory_items SET quantity = quantity - ? WHERE id = ?");
            $update_stmt->execute([$quantity, (int) $inventory_item['id']]);

            quotation_log_inventory_transaction($pdo,
                (int) $inventory_item['id'],
                'stock_out',
                $quantity,
                'quotation',
                $quotation_id,
                'Quotation approved: ' . ($quotation['quotation_number'] ?? ('QT #' . $quotation_id)),
                $quotation['created_by'] ?? null,
                [
                    'customer_id' => $quotation['customer_id'] ?? null,
                    'vehicle_id' => $quotation['vehicle_id'] ?? null,
                    'quotation_id' => $quotation_id,
                    'quotation_item_id' => $item['id'] ?? null,
                ]
            );

            $applied_count++;
        }

        return $applied_count;
    }
}

if (!function_exists('quotation_reverse_inventory_deduction')) {
    function quotation_reverse_inventory_deduction(PDO $pdo, array $quotation, array $items) {
        $quotation_id = (int) ($quotation['id'] ?? 0);
        if ($quotation_id <= 0) {
            throw new Exception('Invalid quotation for inventory restore');
        }

        $restored_stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM inventory_transactions\n            WHERE reference_type = 'quotation'\n              AND reference_id = ?\n              AND transaction_type = 'stock_in'\n        ");
        $restored_stmt->execute([$quotation_id]);
        if ((int) $restored_stmt->fetchColumn() > 0) {
            return 0;
        }

        $restored_count = 0;
        foreach ($items as $item) {
            $item_type = strtolower((string) ($item['item_type'] ?? ''));
            $source = strtolower((string) ($item['source'] ?? ''));

            if (!in_array($item_type, ['part', 'tire'], true) || in_array($source, ['external', 'customer_supplied'], true)) {
                continue;
            }

            $meta = quotation_item_inventory_meta($item);
            $inventory_item_id = (int) ($meta['inventory_item_id'] ?? 0);
            if ($inventory_item_id <= 0) {
                continue;
            }

            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $lock_stmt = $pdo->prepare("\n                SELECT i.id, i.item_name, i.quantity, i.branch_id\n                FROM inventory_items i\n                INNER JOIN branches b ON b.id = i.branch_id\n                WHERE i.id = ?\n                  AND i.status = 'active'\n                  AND b.status = 'active'\n                  AND b.has_inventory = 1\n                LIMIT 1 FOR UPDATE\n            ");
            $lock_stmt->execute([$inventory_item_id]);
            $inventory_item = $lock_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$inventory_item) {
                continue;
            }

            $update_stmt = $pdo->prepare("UPDATE inventory_items SET quantity = quantity + ? WHERE id = ?");
            $update_stmt->execute([$quantity, (int) $inventory_item['id']]);

            quotation_log_inventory_transaction($pdo,
                (int) $inventory_item['id'],
                'stock_in',
                $quantity,
                'quotation',
                $quotation_id,
                'Quotation archived: ' . ($quotation['quotation_number'] ?? ('QT #' . $quotation_id)),
                $quotation['created_by'] ?? null,
                [
                    'customer_id' => $quotation['customer_id'] ?? null,
                    'vehicle_id' => $quotation['vehicle_id'] ?? null,
                    'quotation_id' => $quotation_id,
                    'quotation_item_id' => $item['id'] ?? null,
                ]
            );

            $restored_count++;
        }

        return $restored_count;
    }
}

// Handle Add Quotation
if ($action === 'create') {
    try {
        enforce_modify_permission();

        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        $customer_id = intval($_POST['customer_id'] ?? 0);
        $vehicle_id = intval($_POST['vehicle_id'] ?? 0);
        $branch_id = intval($_POST['branch_id'] ?? $user['branch_id']);
        $labor_cost = floatval($_POST['labor_cost'] ?? 0);
        $notes = app_compose_record_notes($user['name'] ?? 'Front Desk', '', $_POST['notes'] ?? '');
        $inspection_complaint = trim($_POST['inspection_complaint'] ?? '');
        $inspection_findings = trim($_POST['inspection_findings'] ?? '');
        $inspection_recommendations = trim($_POST['inspection_recommendations'] ?? '');
        $inspection_mileage = trim($_POST['inspection_mileage'] ?? '');
        $inspection_mileage_value = $inspection_mileage !== '' ? max(0, intval($inspection_mileage)) : null;

        if ($customer_id <= 0) throw new Exception('Customer is required');
        if ($branch_id <= 0) throw new Exception('Branch is required');
        if ($labor_cost < 0) throw new Exception('Labor cost cannot be negative');
        if ($inspection_complaint === '' || $inspection_findings === '' || $inspection_recommendations === '') {
            throw new Exception('Service inspection must be completed before creating a quotation');
        }
        enforce_branch_record_ownership($branch_id);

        $customer_stmt = $pdo->prepare("SELECT id FROM customers WHERE id = ? AND status = 'active'");
        $customer_stmt->execute([$customer_id]);
        if (!$customer_stmt->fetch()) {
            throw new Exception('Customer not found');
        }

        if ($vehicle_id > 0) {
            $vehicle_stmt = $pdo->prepare("SELECT id FROM vehicles WHERE id = ? AND customer_id = ? AND status = 'active'");
            $vehicle_stmt->execute([$vehicle_id, $customer_id]);
            if (!$vehicle_stmt->fetch()) {
                throw new Exception('Selected vehicle does not belong to the selected customer');
            }
        }

        $inventory_requests = [];
        if (!empty($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                $item_type = in_array($item['type'] ?? '', ['service', 'part', 'tire'], true) ? $item['type'] : 'part';
                $source = in_array($item['source'] ?? '', ['own_inventory', 'other_branch', 'external', 'customer_supplied'], true)
                    ? $item['source']
                    : 'own_inventory';
                $inventory_item_id = (int) ($item['inventory_item_id'] ?? 0);
                if ($item_type === 'service' || $inventory_item_id <= 0 || in_array($source, ['external', 'customer_supplied'], true)) {
                    continue;
                }

                $requested_quantity = max(1, (int) ($item['quantity'] ?? 1));
                if (!isset($inventory_requests[$inventory_item_id])) {
                    $inventory_requests[$inventory_item_id] = [
                        'name' => trim((string) ($item['name'] ?? 'Inventory item')),
                        'quantity' => 0,
                    ];
                }

                $inventory_requests[$inventory_item_id]['quantity'] += $requested_quantity;
            }
        }

        foreach ($inventory_requests as $inventory_item_id => $request) {
            $inventory_stmt = $pdo->prepare("\n                SELECT i.id, i.item_name, i.quantity, b.status AS branch_status, b.has_inventory\n                FROM inventory_items i\n                INNER JOIN branches b ON b.id = i.branch_id\n                WHERE i.id = ?\n                  AND i.status = 'active'\n                  AND b.status = 'active'\n                  AND b.has_inventory = 1\n                LIMIT 1\n            ");
            $inventory_stmt->execute([(int) $inventory_item_id]);
            $inventory_item = $inventory_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$inventory_item) {
                throw new Exception('Inventory item not found for ' . ($request['name'] ?: 'requested item'));
            }

            $available_quantity = (int) ($inventory_item['quantity'] ?? 0);
            if ($request['quantity'] > $available_quantity) {
                throw new Exception('Insufficient stock for ' . ($inventory_item['item_name'] ?? $request['name']) . '. Available: ' . $available_quantity . ', requested: ' . $request['quantity']);
            }
        }

        // Generate quotation number
        $date_prefix = date('Ymd');
        $last_quote = $pdo->query("SELECT quotation_number FROM quotations WHERE quotation_number LIKE 'QT-$date_prefix-%' ORDER BY id DESC LIMIT 1")->fetch();
        $next_num = $last_quote ? intval(substr($last_quote['quotation_number'], -4)) + 1 : 1;
        $quotation_number = "QT-$date_prefix-" . str_pad($next_num, 4, '0', STR_PAD_LEFT);

        // Calculate total from line items
        $total_cost = $labor_cost;
        $parts_cost = 0;
        $tires_cost = 0;

        if (isset($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                if (empty($item['name']) || !isset($item['quantity']) || !isset($item['unit_price'])) continue;

                $item_type = in_array($item['type'] ?? '', ['service', 'part', 'tire'], true) ? $item['type'] : 'part';
                $quantity = max(1, intval($item['quantity']));
                $unit_price = max(0, floatval($item['unit_price']));
                $item_total = $quantity * $unit_price;
                if ($item_type !== 'service') {
                    $total_cost += $item_total;
                }

                if ($item_type === 'part') $parts_cost += $item_total;
                elseif ($item_type === 'tire') $tires_cost += $item_total;
            }
        }

        $tax_amount = 0;
        $total_with_tax = $total_cost;
        $quotation_date = date('Y-m-d');
        $valid_until = date('Y-m-d', strtotime('+30 days'));

        // Insert quotation
        $stmt = $pdo->prepare("
            INSERT INTO quotations (
                quotation_number, customer_id, vehicle_id, branch_id,
                quotation_date, labor_cost, parts_cost, tires_cost,
                tax_amount, total_amount, status, notes, valid_until,
                inspection_complaint, inspection_findings, inspection_recommendations,
                inspection_mileage, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $quotation_number, $customer_id, $vehicle_id ?: null, $branch_id,
            $quotation_date,
            $labor_cost, $parts_cost, $tires_cost, $tax_amount, $total_with_tax,
            $notes,
            $valid_until,
            $inspection_complaint,
            $inspection_findings,
            $inspection_recommendations,
            $inspection_mileage_value,
            $user['id']
        ]);

        $quotation_id = $pdo->lastInsertId();
        app_touch_customer_branch_record($customer_id, $branch_id, $user['id'] ?? null);

        try {
            $visit_stmt = $pdo->prepare("
                INSERT INTO customer_visits (customer_id, branch_id, visit_type, notes, created_by)
                VALUES (?, ?, 'quotation', ?, ?)
            ");
            $visit_stmt->execute([$customer_id, $branch_id, 'Quotation ' . $quotation_number, $user['id'] ?? null]);
        } catch (Exception $visit_error) {
            error_log('Create quotation customer visit error: ' . $visit_error->getMessage());
        }

        // Insert line items
        if (isset($_POST['items'])) {
            $item_stmt = $pdo->prepare("
                INSERT INTO quotation_items (
                    quotation_id, item_name, category, item_type,
                    quantity, unit_price, source, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $allowed_sources = ['own_inventory', 'other_branch', 'external', 'customer_supplied'];
            foreach ($_POST['items'] as $item) {
                if (empty($item['name'])) continue;
                $raw_cat = strtolower(trim((string) ($item['category'] ?? '')));
                $item_type = in_array($item['type'] ?? '', ['service', 'part', 'tire', 'accessory'], true)
                    ? $item['type']
                    : ($raw_cat === 'accessory' ? 'accessory' : ($raw_cat === 'tire' ? 'tire' : 'part'));
                $source = in_array($item['source'] ?? '', $allowed_sources, true) ? $item['source'] : 'own_inventory';

                $item_stmt->execute([
                    $quotation_id,
                    $item['name'],
                    $item['category'] ?? '',
                    $item_type,
                    max(1, intval($item['quantity'] ?? 1)),
                    max(0, floatval($item['unit_price'] ?? 0)),
                    $source,
                    quotation_item_inventory_notes($item)
                ]);

                if ($source === 'other_branch' && $item_type !== 'service') {
                    try {
                        quotation_create_item_request($pdo, $user, (int) $quotation_id, $quotation_number, $customer_id, $branch_id, $item);
                    } catch (Exception $request_error) {
                        error_log('Create quotation item request error: ' . $request_error->getMessage());
                    }
                }
            }
        }

        log_audit('quotations', 'create', $quotation_id, null, [
            'number' => $quotation_number,
            'customer_id' => $customer_id,
            'total' => $total_with_tax
        ]);

        set_flash_message('Quotation created successfully', 'success');

        if (!empty($_POST['redirect'])) {
            redirect($_POST['redirect']);
        }

        die(json_encode(['success' => true, 'message' => 'Quotation created', 'id' => $quotation_id]));

    } catch (Exception $e) {
        error_log('Create quotation error: ' . $e->getMessage());
        set_flash_message($e->getMessage(), 'error');

        if (!empty($_POST['redirect'])) {
            redirect($_POST['redirect']);
        }

        http_response_code(400);
        die(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
}

// Handle Update Quotation Status
if ($action === 'update_status') {
    try {
        enforce_modify_permission();

        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        $quotation_id = intval($_POST['id'] ?? 0);
        $new_status = trim($_POST['status'] ?? '');

        if ($quotation_id <= 0) throw new Exception('Invalid quotation ID');
        if (!in_array($new_status, ['pending', 'approved', 'rejected'])) {
            throw new Exception('Invalid status');
        }

        // Get old quotation
        $stmt = $pdo->prepare("SELECT * FROM quotations WHERE id = ?");
        $stmt->execute([$quotation_id]);
        $quotation = $stmt->fetch();

        if (!$quotation) throw new Exception('Quotation not found');
        enforce_branch_record_ownership($quotation['branch_id'] ?? 0);

        $pdo->beginTransaction();

        // Update status
        $update_stmt = $pdo->prepare("UPDATE quotations SET status = ? WHERE id = ?");
        $update_stmt->execute([$new_status, $quotation_id]);

        $pdo->commit();

        log_audit('quotations', 'update_status', $quotation_id,
            ['status' => $quotation['status']],
            ['status' => $new_status]
        );

        set_flash_message("Quotation status updated to $new_status", 'success');

        if (!empty($_POST['redirect'])) {
            redirect($_POST['redirect']);
        }

        die(json_encode(['success' => true, 'message' => 'Status updated']));

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Update status error: ' . $e->getMessage());
        set_flash_message($e->getMessage(), 'error');

        if (!empty($_POST['redirect'])) {
            redirect($_POST['redirect']);
        }

        http_response_code(400);
        die(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
}

// Handle Archive Quotation
if ($action === 'delete' || $action === 'archive') {
    try {
        enforce_modify_permission();

        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        $quotation_id = intval($_POST['id'] ?? 0);

        if ($quotation_id <= 0) throw new Exception('Invalid quotation ID');

        $quotation_stmt = $pdo->prepare("SELECT * FROM quotations WHERE id = ?");
        $quotation_stmt->execute([$quotation_id]);
        $quotation = $quotation_stmt->fetch();
        if (!$quotation) {
            throw new Exception('Service operation not found');
        }
        enforce_branch_record_ownership($quotation['branch_id'] ?? 0);

        $job_stmt = $pdo->prepare("SELECT job_number FROM job_orders WHERE quotation_id = ? LIMIT 1");
        $job_stmt->execute([$quotation_id]);
        $linked_job = $job_stmt->fetch();
        if ($linked_job) {
            throw new Exception('This service operation already has a job order and cannot be archived.');
        }

        $history_stmt = $pdo->prepare("SELECT id FROM service_history WHERE quotation_id = ? LIMIT 1");
        $history_stmt->execute([$quotation_id]);
        if ($history_stmt->fetch()) {
            throw new Exception('This service operation already has service history and cannot be archived.');
        }

        $transfer_stmt = $pdo->prepare("
            SELECT request_number, status
            FROM inter_branch_transfer_requests
            WHERE quotation_id = ?
              AND LOWER(status) <> 'pending'
            LIMIT 1
        ");
        $transfer_stmt->execute([$quotation_id]);
        $active_transfer = $transfer_stmt->fetch();
        if ($active_transfer) {
            throw new Exception('This service operation has a transfer request that is already ' . $active_transfer['status'] . ' and cannot be archived.');
        }

        $items_stmt = $pdo->prepare("SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY id ASC");
        $items_stmt->execute([$quotation_id]);
        $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

        $pdo->beginTransaction();

        quotation_reverse_inventory_deduction($pdo, $quotation, $items);

        $transfer_ids_stmt = $pdo->prepare("SELECT id FROM inter_branch_transfer_requests WHERE quotation_id = ?");
        $transfer_ids_stmt->execute([$quotation_id]);
        $transfer_ids = array_map('intval', array_column($transfer_ids_stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));

        if (!empty($transfer_ids)) {
            $placeholders = implode(',', array_fill(0, count($transfer_ids), '?'));
            $pdo->prepare("UPDATE transfer_notifications SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE transfer_request_id IN ($placeholders)")
                ->execute($transfer_ids);
            $pdo->prepare("UPDATE inter_branch_transfer_requests SET status = 'cancelled', notes = CONCAT(COALESCE(notes, ''), CASE WHEN notes IS NULL OR notes = '' THEN '' ELSE '\n' END, 'Cancelled because linked service operation was archived.'), updated_at = NOW() WHERE id IN ($placeholders)")
                ->execute($transfer_ids);
        }

        $archive_set = ["status = 'archived'"];
        $archive_values = [];
        app_archive_metadata_update('quotations', $archive_set, $archive_values, 'Service operation archived');
        if (app_column_exists('quotations', 'updated_at')) {
            $archive_set[] = 'updated_at = NOW()';
        }
        $archive_values[] = $quotation_id;

        $archive_stmt = $pdo->prepare('UPDATE quotations SET ' . implode(', ', $archive_set) . ' WHERE id = ?');
        $archive_stmt->execute($archive_values);

        $pdo->commit();

        log_audit('quotations', 'archive', $quotation_id, [
            'quotation_number' => $quotation['quotation_number'] ?? null,
            'status' => $quotation['status'] ?? null,
            'customer_id' => $quotation['customer_id'] ?? null,
            'vehicle_id' => $quotation['vehicle_id'] ?? null,
            'total_amount' => $quotation['total_amount'] ?? null
        ], [
            'status' => 'archived',
            'quotation_items_preserved' => count($items),
            'transfer_requests_cancelled' => count($transfer_ids),
            'records_preserved' => true
        ]);

        set_flash_message('Service operation archived successfully', 'success');

        if (!empty($_POST['redirect'])) {
            redirect($_POST['redirect']);
        }

        die(json_encode(['success' => true, 'message' => 'Service operation archived']));

    } catch (Exception $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Archive quotation error: ' . $e->getMessage());
        set_flash_message($e->getMessage(), 'error');

        if (!empty($_POST['redirect'])) {
            redirect($_POST['redirect']);
        }

        http_response_code(400);
        die(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
}

// Handle Restore / Unarchive Quotation
if ($action === 'restore') {
    try {
        enforce_modify_permission();

        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        $quotation_id = intval($_POST['id'] ?? 0);
        if ($quotation_id <= 0) throw new Exception('Invalid quotation ID');

        $quotation_stmt = $pdo->prepare("SELECT * FROM quotations WHERE id = ?");
        $quotation_stmt->execute([$quotation_id]);
        $quotation = $quotation_stmt->fetch();
        if (!$quotation) {
            throw new Exception('Service operation not found');
        }
        enforce_branch_record_ownership($quotation['branch_id'] ?? 0);

        $pdo->beginTransaction();

        $restore_set = ["status = 'draft'"];
        $restore_values = [];
        if (app_column_exists('quotations', 'is_archived')) {
            $restore_set[] = 'is_archived = 0';
        }
        if (app_column_exists('quotations', 'archived_at')) {
            $restore_set[] = 'archived_at = NULL';
        }
        if (app_column_exists('quotations', 'archived_by')) {
            $restore_set[] = 'archived_by = NULL';
        }
        if (app_column_exists('quotations', 'updated_at')) {
            $restore_set[] = 'updated_at = NOW()';
        }
        $restore_values[] = $quotation_id;

        $restore_stmt = $pdo->prepare('UPDATE quotations SET ' . implode(', ', $restore_set) . ' WHERE id = ?');
        $restore_stmt->execute($restore_values);

        $pdo->commit();

        log_audit('quotations', 'restore', $quotation_id, [
            'status' => 'archived'
        ], [
            'status' => 'draft',
            'restored' => true
        ]);

        set_flash_message('Service operation unarchived successfully', 'success');

        if (!empty($_POST['redirect'])) {
            redirect($_POST['redirect']);
        }

        die(json_encode(['success' => true, 'message' => 'Service operation unarchived successfully']));

    } catch (Exception $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Restore quotation error: ' . $e->getMessage());
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
