<?php
/**
 * Handle editing services on a quotation (admin only)
 */

require_once '../../includes/config.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$user = app_get_session_user();
// Only front-desk users should be able to edit service lines here
if (($user['role'] ?? '') !== 'front-desk') {
    set_flash_message('You do not have permission to edit quotations.', 'danger');
    redirect('/hwtires/front-desk/quotations/');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/hwtires/admin/quotations/');
}

$csrf = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($csrf)) {
    set_flash_message('Invalid CSRF token.', 'danger');
    redirect('/hwtires/admin/quotations/');
}

if (!function_exists('quotation_edit_log_inventory_transaction')) {
    function quotation_edit_log_inventory_transaction(PDO $pdo, $item_id, $transaction_type, $quantity, $reference_type, $reference_id, $notes, $created_by, array $tags = []) {
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

$quotation_id = (int) ($_POST['quotation_id'] ?? 0);
$keep_item_ids = $_POST['keep_item_ids'] ?? [];
$keep_item_ids = array_values(array_filter(array_map('intval', (array) $keep_item_ids), function ($v) { return $v > 0; }));

if ($quotation_id <= 0) {
    set_flash_message('Invalid quotation selected.', 'danger');
    redirect('/hwtires/front-desk/quotations/');
}

try {
    $pdo->beginTransaction();

    $q_stmt = $pdo->prepare("SELECT * FROM quotations WHERE id = ? LIMIT 1");
    $q_stmt->execute([$quotation_id]);
    $quotation = $q_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quotation) {
        throw new Exception('Quotation not found.');
    }

    // Ensure front-desk user belongs to the same branch as the quotation
    enforce_branch_record_ownership($quotation['branch_id'] ?? 0);

    // Check if a job order has already been created for this quotation
    $job_stmt = $pdo->prepare("SELECT id, job_number FROM job_orders WHERE quotation_id = ? AND status <> 'cancelled' LIMIT 1");
    $job_stmt->execute([$quotation_id]);
    $linked_job = $job_stmt->fetch(PDO::FETCH_ASSOC);
    if ($linked_job) {
        throw new Exception('This quotation can no longer be edited because a Job Order has already been created.');
    }

    $items_stmt = $pdo->prepare("SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY id");
    $items_stmt->execute([$quotation_id]);
    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Snapshot of old items for audit and potential inventory reversal
    $old_services = [];
    $old_items = [];
    $all_item_ids = [];
    foreach ($items as $it) {
        $old_items[] = $it;
        $all_item_ids[] = (int) $it['id'];
        if (($it['item_type'] ?? '') === 'service') {
            $old_services[] = $it;
        }
    }

    // Validate submitted keep_item_ids against actual items belonging to this quotation
    $valid_keep_item_ids = array_values(array_intersect($all_item_ids, $keep_item_ids));
    if (empty($valid_keep_item_ids)) {
        throw new Exception('At least one service or item must remain in the quotation.');
    }
    $keep_item_ids = $valid_keep_item_ids;

    // Determine which item IDs to delete (any items not kept)
    $to_delete = array_diff($all_item_ids, $keep_item_ids);

    if (!empty($to_delete)) {
        // Delete selected items
        $placeholders = implode(', ', array_fill(0, count($to_delete), '?'));
        $del_stmt = $pdo->prepare("DELETE FROM quotation_items WHERE quotation_id = ? AND id IN ($placeholders)");
        $del_stmt->execute(array_merge([$quotation_id], $to_delete));
    }

    // Persist edits for kept items: quantities and notes
    $posted_quantities = $_POST['quantity'] ?? [];
    $posted_notes = $_POST['notes'] ?? [];
    if (!empty($keep_item_ids)) {
        $upd_stmt = $pdo->prepare("UPDATE quotation_items SET quantity = ?, notes = ? WHERE id = ? AND quotation_id = ?");
        foreach ($keep_item_ids as $kid) {
            $qty = max(1, (int) ($posted_quantities[$kid] ?? 1));
            $note = trim((string) ($posted_notes[$kid] ?? ''));
            $upd_stmt->execute([$qty, $note, $kid, $quotation_id]);
        }
    }

    // Re-fetch items for new snapshot
    $items_stmt->execute([$quotation_id]);
    $new_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Recalculate costs: parts, tires, labor
    $parts_cost = 0.0;
    $tires_cost = 0.0;
    $remaining_service_items = [];
    foreach ($new_items as $ni) {
        $type = strtolower((string) ($ni['item_type'] ?? ''));
        $quantity = max(1, (int) ($ni['quantity'] ?? 1));
        $unit_price = (float) ($ni['unit_price'] ?? 0);
        if ($type === 'part') {
            $parts_cost += $quantity * $unit_price;
        } elseif ($type === 'tire') {
            $tires_cost += $quantity * $unit_price;
        } elseif ($type === 'service') {
            $remaining_service_items[] = $ni;
        }
    }

    // Attempt to compute labor_cost from service_catalog by matching names
    $labor_cost = 0.0;
    $found_labor_count = 0;
    $original_service_count = count($old_services);
    foreach ($remaining_service_items as $srv) {
        $svc_stmt = $pdo->prepare("SELECT labor_cost, price FROM service_catalog WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $svc_stmt->execute([$srv['item_name']]);
        $svc_row = $svc_stmt->fetch(PDO::FETCH_ASSOC);
        if ($svc_row) {
            $line_labor = (float) ($svc_row['labor_cost'] ?? $svc_row['price'] ?? 0);
            $labor_cost += $line_labor;
            $found_labor_count++;
        }
    }

    $remaining_service_count = count($remaining_service_items);
    if ($remaining_service_count > 0 && $found_labor_count !== $remaining_service_count) {
        // Fallback: scale original labor_cost by ratio of remaining count
        $labor_cost = (float) ($quotation['labor_cost'] ?? 0) * ($remaining_service_count / max(1, $original_service_count ?: 1));
    }

    // Service operations do not add tax in this system.
    $total_amount = $labor_cost + $parts_cost + $tires_cost;

    // Update quotation costs and timestamp
    $update_stmt = $pdo->prepare("UPDATE quotations SET labor_cost = ?, parts_cost = ?, tires_cost = ?, tax_amount = 0, total_amount = ?, updated_at = NOW() WHERE id = ?");
    $update_stmt->execute([$labor_cost, $parts_cost, $tires_cost, $total_amount, $quotation_id]);

    // Inventory adjustments: if there are existing stock_out transactions for this quotation,
    // reverse them for old items and reapply for new items to match current quotation.
    $stockout_check = $pdo->prepare("SELECT COUNT(*) FROM inventory_transactions WHERE reference_type = 'quotation' AND reference_id = ? AND LOWER(REPLACE(transaction_type, ' ', '_')) = 'stock_out'");
    $stockout_check->execute([$quotation_id]);
    $stockout_count = (int) $stockout_check->fetchColumn();

    if ($stockout_count > 0) {
        // Reverse old stock outs
        foreach ($old_items as $oit) {
            $otype = strtolower((string) ($oit['item_type'] ?? ''));
            $source = strtolower((string) ($oit['source'] ?? ''));
            if (!in_array($otype, ['part', 'tire'], true) || in_array($source, ['external', 'customer_supplied'], true)) {
                continue;
            }

            $meta = [];
            $notes = trim((string) ($oit['notes'] ?? ''));
            if ($notes !== '' && $notes[0] === '{') {
                $decoded = json_decode($notes, true);
                if (is_array($decoded)) $meta = $decoded;
            }

            $inventory_item_id = (int) ($meta['inventory_item_id'] ?? 0);
            if ($inventory_item_id <= 0) continue;

            $qty = max(1, (int) ($oit['quantity'] ?? 1));
            // Increase inventory back
            $pdo->prepare("UPDATE inventory_items SET quantity = quantity + ? WHERE id = ?")->execute([$qty, $inventory_item_id]);
            // Insert stock_in transaction note
            quotation_edit_log_inventory_transaction($pdo,
                $inventory_item_id,
                'stock_in',
                $qty,
                'quotation',
                $quotation_id,
                'Quotation edit restore: ' . ($quotation['quotation_number'] ?? ('QT#' . $quotation_id)),
                $user['id'] ?? null,
                [
                    'customer_id' => $quotation['customer_id'] ?? null,
                    'vehicle_id' => $quotation['vehicle_id'] ?? null,
                    'quotation_id' => $quotation_id,
                    'quotation_item_id' => $oit['id'] ?? null,
                ]
            );
        }

        // Apply stock outs for remaining items
        foreach ($new_items as $nit) {
            $ntype = strtolower((string) ($nit['item_type'] ?? ''));
            $source = strtolower((string) ($nit['source'] ?? ''));
            if (!in_array($ntype, ['part', 'tire'], true) || in_array($source, ['external', 'customer_supplied'], true)) {
                continue;
            }

            $meta = [];
            $notes = trim((string) ($nit['notes'] ?? ''));
            if ($notes !== '' && $notes[0] === '{') {
                $decoded = json_decode($notes, true);
                if (is_array($decoded)) $meta = $decoded;
            }

            $inventory_item_id = (int) ($meta['inventory_item_id'] ?? 0);
            if ($inventory_item_id <= 0) continue;

            $qty = max(1, (int) ($nit['quantity'] ?? 1));

            // Lock and check availability
            $lock_stmt = $pdo->prepare("SELECT i.id, i.item_name, i.quantity FROM inventory_items i WHERE i.id = ? LIMIT 1 FOR UPDATE");
            $lock_stmt->execute([$inventory_item_id]);
            $inv = $lock_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$inv) throw new Exception('Inventory item not found for ' . ($nit['item_name'] ?? 'item'));
            $available = (int) ($inv['quantity'] ?? 0);
            if ($available < $qty) throw new Exception('Insufficient stock for ' . ($inv['item_name'] ?? 'item') . '. Available: ' . $available . ', required: ' . $qty);

            $pdo->prepare("UPDATE inventory_items SET quantity = quantity - ? WHERE id = ?")->execute([$qty, $inventory_item_id]);
            quotation_edit_log_inventory_transaction($pdo,
                $inventory_item_id,
                'stock_out',
                $qty,
                'quotation',
                $quotation_id,
                'Quotation edit applied: ' . ($quotation['quotation_number'] ?? ('QT#' . $quotation_id)),
                $user['id'] ?? null,
                [
                    'customer_id' => $quotation['customer_id'] ?? null,
                    'vehicle_id' => $quotation['vehicle_id'] ?? null,
                    'quotation_id' => $quotation_id,
                    'quotation_item_id' => $nit['id'] ?? null,
                ]
            );
        }
    }

    $pdo->commit();

    // Log audit with old vs new service lists
    $old_snapshot = array_map(function ($i) { return ['id' => (int) $i['id'], 'name' => $i['item_name'] ?? '']; }, $old_services);
    $new_snapshot = array_values(array_map(function ($i) { return ['id' => (int) $i['id'], 'name' => $i['item_name'] ?? '']; }, array_filter($new_items, function ($i) { return ($i['item_type'] ?? '') === 'service'; })));

    log_audit('quotations', 'edit_services', $quotation_id, $old_snapshot, $new_snapshot);

    set_flash_message('Service lines updated successfully. Edit saved at ' . date('M d, Y H:i A'), 'success');
    redirect('/hwtires/front-desk/quotations/view.php?id=' . $quotation_id);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Quotation edit error: ' . $e->getMessage());
    set_flash_message($e->getMessage(), 'danger');
    redirect('/hwtires/front-desk/quotations/view.php?id=' . $quotation_id);
}

