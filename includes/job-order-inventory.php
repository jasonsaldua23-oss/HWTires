<?php
/**
 * Job order inventory consumption helpers.
 */

if (!function_exists('job_order_inventory_line_meta')) {
    function job_order_inventory_line_meta($line) {
        $notes = trim((string) ($line['notes'] ?? ''));
        if ($notes === '' || $notes[0] !== '{') {
            return [];
        }

        $decoded = json_decode($notes, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('job_order_resolve_inventory_item')) {
    function job_order_resolve_inventory_item(PDO $pdo, $line, $job_branch_id) {
        $item_type = strtolower((string) ($line['item_type'] ?? ''));
        $source = strtolower((string) ($line['source'] ?? ''));

        if (!in_array($item_type, ['part', 'tire'], true)) {
            return null;
        }

        if (in_array($source, ['external', 'customer_supplied'], true)) {
            return null;
        }

        $meta = job_order_inventory_line_meta($line);
        $inventory_item_id = intval($meta['inventory_item_id'] ?? 0);
        $inventory_branch_id = intval($meta['inventory_branch_id'] ?? 0);
        $item_name = trim((string) ($line['item_name'] ?? ''));

        if ($inventory_item_id > 0) {
            $stmt = $pdo->prepare("
                SELECT i.*
                FROM inventory_items i
                INNER JOIN branches b ON b.id = i.branch_id
                WHERE i.id = ?
                  AND i.status = 'active'
                  AND b.status = 'active'
                  AND b.has_inventory = 1
                LIMIT 1
            ");
            $stmt->execute([$inventory_item_id]);
            $item = $stmt->fetch();
            if ($item) {
                return $item;
            }
        }

        if ($item_name === '') {
            return null;
        }

        if ($inventory_branch_id <= 0 && $source === 'own_inventory') {
            $inventory_branch_id = intval($job_branch_id);
        }

        $params = [$item_name];
        $branch_clause = '';
        if ($inventory_branch_id > 0) {
            $branch_clause = 'AND i.branch_id = ?';
            $params[] = $inventory_branch_id;
        } elseif ($source === 'other_branch') {
            $branch_clause = 'AND i.branch_id <> ?';
            $params[] = intval($job_branch_id);
        }

        $stmt = $pdo->prepare("
            SELECT i.*
            FROM inventory_items i
            INNER JOIN branches b ON b.id = i.branch_id
            WHERE i.item_name = ?
              $branch_clause
              AND i.status = 'active'
              AND b.status = 'active'
              AND b.has_inventory = 1
            ORDER BY i.quantity DESC, i.id ASC
            LIMIT 1
        ");
        $stmt->execute($params);

        return $stmt->fetch() ?: null;
    }
}

if (!function_exists('job_order_resolve_local_inventory_item_by_name')) {
    function job_order_resolve_local_inventory_item_by_name(PDO $pdo, array $line, $job_branch_id) {
        $job_branch_id = (int) $job_branch_id;
        $item_name = trim((string) ($line['item_name'] ?? ''));
        if ($job_branch_id <= 0 || $item_name === '') {
            return null;
        }

        $category = trim((string) ($line['category'] ?? ''));
        $stmt = $pdo->prepare("
            SELECT i.*
            FROM inventory_items i
            INNER JOIN branches b ON b.id = i.branch_id
            WHERE i.branch_id = ?
              AND i.item_name = ?
              AND i.status = 'active'
              AND b.status = 'active'
              AND b.has_inventory = 1
            ORDER BY
              CASE WHEN ? <> '' AND COALESCE(i.category, '') = ? THEN 0 ELSE 1 END,
              i.quantity DESC,
              i.id ASC
            LIMIT 1
        ");
        $stmt->execute([$job_branch_id, $item_name, $category, $category]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('job_order_resolve_received_transfer_inventory_item')) {
    function job_order_resolve_received_transfer_inventory_item(PDO $pdo, array $job, array $line) {
        $source = strtolower((string) ($line['source'] ?? ''));
        if ($source !== 'other_branch') {
            return null;
        }

        $meta = job_order_inventory_line_meta($line);
        $donor_item_id = (int) ($meta['inventory_item_id'] ?? 0);
        $donor_branch_id = (int) ($meta['inventory_branch_id'] ?? 0);
        $job_branch_id = (int) ($job['branch_id'] ?? 0);
        $quotation_id = (int) ($job['quotation_id'] ?? 0);

        if ($donor_item_id <= 0 || $donor_branch_id <= 0 || $job_branch_id <= 0 || $quotation_id <= 0) {
            return null;
        }

        $transfer_stmt = $pdo->prepare("
            SELECT
                tr.status,
                rb.has_inventory AS requesting_has_inventory,
                donor_item.item_name,
                donor_item.category,
                donor_item.brand,
                donor_item.size
            FROM inter_branch_transfer_requests tr
            INNER JOIN branches rb ON rb.id = tr.requesting_branch_id
            INNER JOIN inventory_items donor_item ON donor_item.id = tr.item_id
            WHERE tr.quotation_id = ?
              AND tr.item_id = ?
              AND tr.requesting_branch_id = ?
              AND tr.donor_branch_id = ?
            ORDER BY tr.id DESC
            LIMIT 1
        ");
        $transfer_stmt->execute([$quotation_id, $donor_item_id, $job_branch_id, $donor_branch_id]);
        $transfer = $transfer_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$transfer || strtolower((string) ($transfer['status'] ?? '')) !== 'received') {
            return null;
        }

        if ((int) ($transfer['requesting_has_inventory'] ?? 0) !== 1) {
            return ['already_consumed_by_transfer' => true];
        }

        $destination_stmt = $pdo->prepare("
            SELECT i.*
            FROM inventory_items i
            INNER JOIN branches b ON b.id = i.branch_id
            WHERE i.branch_id = ?
              AND i.item_name = ?
              AND COALESCE(i.category, '') = COALESCE(?, '')
              AND COALESCE(i.brand, '') = COALESCE(?, '')
              AND COALESCE(i.size, '') = COALESCE(?, '')
              AND i.status = 'active'
              AND b.status = 'active'
              AND b.has_inventory = 1
            ORDER BY i.id DESC
            LIMIT 1
        ");
        $destination_stmt->execute([
            $job_branch_id,
            $transfer['item_name'],
            $transfer['category'],
            $transfer['brand'],
            $transfer['size'],
        ]);

        return $destination_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('job_order_apply_inventory_line_consumption')) {
    function job_order_apply_inventory_line_consumption(PDO $pdo, array $job, array $line, $created_by = null) {
        $job_order_id = (int) ($job['id'] ?? 0);
        if ($job_order_id <= 0) {
            throw new Exception('Invalid job order for inventory usage');
        }

        $item_type = strtolower((string) ($line['item_type'] ?? ''));
        $source = strtolower((string) ($line['source'] ?? ''));

        $job_branch_id = (int) ($job['branch_id'] ?? 0);
        $inventory_item = null;
        $can_use_linked_inventory = in_array($item_type, ['part', 'tire'], true)
            && !in_array($source, ['external', 'customer_supplied'], true);

        if ($can_use_linked_inventory && $source === 'other_branch') {
            $inventory_item = job_order_resolve_received_transfer_inventory_item($pdo, $job, $line);
            if (is_array($inventory_item) && !empty($inventory_item['already_consumed_by_transfer'])) {
                return ['applied' => false, 'count' => 0, 'message' => 'Transferred item was already deducted from donor inventory'];
            }
        } elseif ($source === 'other_branch') {
            $inventory_item = job_order_resolve_received_transfer_inventory_item($pdo, $job, $line);
            if (is_array($inventory_item) && !empty($inventory_item['already_consumed_by_transfer'])) {
                return ['applied' => false, 'count' => 0, 'message' => 'Transferred item was already deducted from donor inventory'];
            }
        } elseif ($can_use_linked_inventory) {
            $inventory_item = job_order_resolve_inventory_item($pdo, $line, $job_branch_id);
        }

        if (empty($inventory_item) && $source !== 'other_branch') {
            $inventory_item = job_order_resolve_local_inventory_item_by_name($pdo, $line, $job_branch_id);
        }

        if (empty($inventory_item)) {
            $message = $can_use_linked_inventory
                ? 'No matching inventory item was deducted'
                : 'Task does not use inventory';
            return ['applied' => false, 'count' => 0, 'message' => $message];
        }

        $quantity = max(1, (int) ($line['quantity'] ?? 1));
        $quotation_item_id = (int) ($line['id'] ?? 0);
        $inventory_item_id = (int) ($inventory_item['id'] ?? 0);

        $deducted_params = [$inventory_item_id, $job_order_id];
        $item_reference_clause = '';
        if ($quotation_item_id > 0) {
            $item_reference_clause = " OR (reference_type = 'job_order_item' AND reference_id = ?)";
            $deducted_params[] = $quotation_item_id;
        }

        $deducted_stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM inventory_transactions
            WHERE LOWER(REPLACE(transaction_type, ' ', '_')) = 'stock_out'
              AND item_id = ?
              AND (
                  (reference_type = 'job_order' AND reference_id = ?)
                  $item_reference_clause
              )
        ");
        $deducted_stmt->execute($deducted_params);
        if ((int) $deducted_stmt->fetchColumn() > 0) {
            return ['applied' => false, 'count' => 0, 'message' => 'Inventory already deducted for this task'];
        }

        $lock_stmt = $pdo->prepare("SELECT id, item_name, quantity FROM inventory_items WHERE id = ? FOR UPDATE");
        $lock_stmt->execute([$inventory_item_id]);
        $locked_item = $lock_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$locked_item) {
            throw new Exception('Inventory item no longer exists: ' . ($line['item_name'] ?? 'Item'));
        }

        if ((int) $locked_item['quantity'] < $quantity) {
            throw new Exception('Insufficient stock for ' . $locked_item['item_name'] . '. Current: ' . (int) $locked_item['quantity']);
        }

        $update_stmt = $pdo->prepare("UPDATE inventory_items SET quantity = quantity - ? WHERE id = ?");
        $update_stmt->execute([$quantity, (int) $locked_item['id']]);

        $reference_type = $quotation_item_id > 0 ? 'job_order_item' : 'job_order';
        $reference_id = $quotation_item_id > 0 ? $quotation_item_id : $job_order_id;

        $transaction_columns = [
            'item_id',
            'transaction_type',
            'quantity',
            'reference_type',
            'reference_id',
            'notes',
            'created_by',
        ];
        $transaction_values = [
            (int) $locked_item['id'],
            'stock_out',
            $quantity,
            $reference_type,
            $reference_id,
            'Stock used for job order task: ' . ($job['job_number'] ?? ('JO #' . $job_order_id)),
            $created_by,
        ];

        $transaction_tags = [
            'customer_id' => $job['customer_id'] ?? null,
            'vehicle_id' => $job['vehicle_id'] ?? null,
            'job_order_id' => $job_order_id,
            'quotation_id' => $job['quotation_id'] ?? null,
            'quotation_item_id' => $quotation_item_id ?: null,
        ];

        foreach ($transaction_tags as $tag_column => $tag_value) {
            if (!app_column_exists('inventory_transactions', $tag_column)) {
                continue;
            }

            $tag_value = $tag_value !== null ? intval($tag_value) : null;
            $transaction_columns[] = $tag_column;
            $transaction_values[] = $tag_value && $tag_value > 0 ? $tag_value : null;
        }

        $transaction_stmt = $pdo->prepare("
            INSERT INTO inventory_transactions (" . implode(', ', $transaction_columns) . ")
            VALUES (" . implode(', ', array_fill(0, count($transaction_columns), '?')) . ")
        ");
        $transaction_stmt->execute($transaction_values);

        log_audit('inventory_items', 'job_order_stock_out', (int) $locked_item['id'], null, [
            'job_order_id' => $job_order_id,
            'quotation_item_id' => $quotation_item_id ?: null,
            'quantity' => $quantity,
        ]);

        return ['applied' => true, 'count' => 1, 'message' => 'Inventory deducted for job order task'];
    }
}

if (!function_exists('job_order_apply_inventory_consumption_for_task')) {
    function job_order_apply_inventory_consumption_for_task(PDO $pdo, $job_order_id, $task_key, $created_by = null) {
        $job_order_id = (int) $job_order_id;
        $task_key = trim((string) $task_key);

        if ($job_order_id <= 0 || $task_key === '') {
            throw new Exception('Invalid job order task for inventory usage');
        }

        $job_stmt = $pdo->prepare("SELECT * FROM job_orders WHERE id = ?");
        $job_stmt->execute([$job_order_id]);
        $job = $job_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job || empty($job['quotation_id'])) {
            return ['applied' => false, 'count' => 0, 'message' => 'No quotation item to deduct'];
        }

        $line = null;
        try {
            $task_stmt = $pdo->prepare("
                SELECT qi.*
                FROM job_order_progress jp
                INNER JOIN quotation_items qi ON qi.id = jp.quotation_item_id
                WHERE jp.job_order_id = ?
                  AND jp.task_key = ?
                LIMIT 1
            ");
            $task_stmt->execute([$job_order_id, $task_key]);
            $line = $task_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {
            $line = null;
        }

        if (!$line && preg_match('/^qi:(\d+)$/', $task_key, $matches)) {
            $line_stmt = $pdo->prepare("
                SELECT *
                FROM quotation_items
                WHERE id = ?
                  AND quotation_id = ?
                LIMIT 1
            ");
            $line_stmt->execute([(int) $matches[1], (int) $job['quotation_id']]);
            $line = $line_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if (!$line) {
            return ['applied' => false, 'count' => 0, 'message' => 'No quotation item to deduct'];
        }

        $started_transaction = !$pdo->inTransaction();
        if ($started_transaction) {
            $pdo->beginTransaction();
        }

        try {
            $result = job_order_apply_inventory_line_consumption($pdo, $job, $line, $created_by);

            if ($started_transaction && $pdo->inTransaction()) {
                $pdo->commit();
            }

            return $result;
        } catch (Exception $e) {
            if ($started_transaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }
}

if (!function_exists('job_order_apply_inventory_consumption')) {
    function job_order_apply_inventory_consumption(PDO $pdo, $job_order_id, $created_by = null) {
        $job_order_id = intval($job_order_id);
        if ($job_order_id <= 0) {
            throw new Exception('Invalid job order for inventory usage');
        }

        $job_stmt = $pdo->prepare("SELECT * FROM job_orders WHERE id = ?");
        $job_stmt->execute([$job_order_id]);
        $job = $job_stmt->fetch();
        if (!$job || empty($job['quotation_id'])) {
            return ['applied' => false, 'count' => 0, 'message' => 'No quotation items to deduct'];
        }

        $line_stmt = $pdo->prepare("
            SELECT *
            FROM quotation_items
            WHERE quotation_id = ?
              AND item_type IN ('part', 'tire')
              AND source NOT IN ('external', 'customer_supplied')
            ORDER BY id ASC
        ");
        $line_stmt->execute([(int) $job['quotation_id']]);
        $lines = $line_stmt->fetchAll();

        if (empty($lines)) {
            return ['applied' => false, 'count' => 0, 'message' => 'No inventory-backed items to deduct'];
        }

        $started_transaction = !$pdo->inTransaction();
        if ($started_transaction) {
            $pdo->beginTransaction();
        }

        try {
            $applied_count = 0;

            foreach ($lines as $line) {
                $result = job_order_apply_inventory_line_consumption($pdo, $job, $line, $created_by);
                $applied_count += (int) ($result['count'] ?? 0);
            }

            if ($started_transaction && $pdo->inTransaction()) {
                $pdo->commit();
            }

            if ($applied_count > 0) {
                log_audit('inventory_items', 'job_order_stock_out', $job_order_id, null, [
                    'job_order_id' => $job_order_id,
                    'items_deducted' => $applied_count,
                ]);
            }

            return [
                'applied' => $applied_count > 0,
                'count' => $applied_count,
                'message' => $applied_count > 0 ? 'Inventory deducted for job order' : 'No matching inventory items were deducted',
            ];
        } catch (Exception $e) {
            if ($started_transaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }
}
?>
