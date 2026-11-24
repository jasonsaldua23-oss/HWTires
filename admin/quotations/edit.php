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

    // Safeguard: Verify no historical quotation-level stock-out transactions exist.
    // In standard HWTIRES workflows, quotation creation and editing do not deduct inventory;
    // material consumption is strictly owned by Job Orders.
    // If historical stock-out records exist, automatic restoration and re-deduction are prohibited
    // to prevent inventory corruption and FIFO batch disparity. Manual reconciliation is required.
    $stockout_check = $pdo->prepare("
        SELECT id
        FROM inventory_transactions
        WHERE reference_type = 'quotation'
          AND reference_id = ?
          AND LOWER(REPLACE(transaction_type, ' ', '_')) = 'stock_out'
        LIMIT 1
        FOR UPDATE
    ");
    $stockout_check->execute([$quotation_id]);
    $has_stock_out = (bool) $stockout_check->fetchColumn();

    if ($has_stock_out) {
        throw new Exception(
            "Cannot edit quotation #{$quotation_id}: Historical quotation-level stock-out transactions exist. " .
            "Automatic inventory adjustment during quotation edit is disabled to prevent inventory discrepancies. Manual inventory reconciliation is required."
        );
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

