<?php
/**
 * HWTIRES — FIFO Inventory Management Helper
 * Panel Revision 3: First-In, First-Out (FIFO) Inventory Issuance
 *
 * This isolated shared helper provides reusable, atomic FIFO inventory operations:
 * - Deterministic batch selection (legacy first, then oldest receipt date, then batch ID)
 * - Strict positive integer quantity validation within signed 32-bit INT bounds (1 to 2147483647)
 * - Pre-issuance locking and reconciliation verification (fifo_verify_pre_issuance)
 * - Concurrency-guarded batch deductions with accurate active/exhausted state transitions
 * - Multi-batch allocation preparation and recording
 * - Reversal of previous allocations with deterministic LIFO restoration and reactivation (fifo_reverse_allocation)
 * - Strict parent transaction linkage and validation (stock_out, recount_down adjustment, damage)
 * - Stock reconciliation checks (aggregate inventory_items vs. inventory_batches)
 * - Anomaly detection for hidden, negative, or inconsistently marked batch stock
 * - Caller-owned transaction preservation
 * - Database-backed item and branch ownership validation on batch creation
 * - Idempotency safeguards against duplicate allocations and duplicate batches
 *
 * SAFETY & TRANSACTION CONTRACT:
 * - Mutation and locking helpers REQUIRE an active caller-owned PDO transaction ($pdo->inTransaction() === true).
 * - Helpers DO NOT initiate independent transactions ($pdo->beginTransaction()).
 * - Helpers DO NOT commit ($pdo->commit()).
 * - Helpers DO NOT roll back ($pdo->rollBack()); all exceptions propagate
 *   directly to the caller, which must roll back the complete stock movement.
 * - Warranty snapshots remain exclusively in inventory_transactions (Revision 4).
 * - All CHECK constraint rules and bounds are validated at PHP runtime due to TiDB configuration.
 */

// ============================================================================
// Exception Hierarchy
// ============================================================================

if (!class_exists('FifoException')) {
    class FifoException extends Exception {}
}

if (!class_exists('FifoValidationException')) {
    class FifoValidationException extends FifoException {}
}

if (!class_exists('FifoTransactionException')) {
    class FifoTransactionException extends FifoException {}
}

if (!class_exists('FifoInsufficientStockException')) {
    class FifoInsufficientStockException extends FifoException {}
}

if (!class_exists('FifoDuplicateAllocationException')) {
    class FifoDuplicateAllocationException extends FifoException {}
}

if (!class_exists('FifoDuplicateBatchException')) {
    class FifoDuplicateBatchException extends FifoException {}
}

if (!class_exists('FifoConcurrencyException')) {
    class FifoConcurrencyException extends FifoException {}
}

if (!class_exists('FifoAnomalyException')) {
    class FifoAnomalyException extends FifoException {}
}

// ============================================================================
// Validation Helpers
// ============================================================================

if (!function_exists('fifo_validate_positive_integer')) {
    /**
     * Validates that a value is a strict positive whole integer within signed 32-bit database INT bounds:
     * 1 through 2147483647 (0x7FFFFFFF).
     *
     * Rejects floats, negative numbers, zero, null, non-numeric strings,
     * and overlarge numeric strings before PHP integer casting can overflow or saturate.
     *
     * @param mixed $value
     * @param string $field_name
     * @return int
     * @throws FifoValidationException
     */
    function fifo_validate_positive_integer($value, string $field_name = 'Quantity'): int {
        if ($value === null || $value === '') {
            throw new FifoValidationException("{$field_name} is required.");
        }

        // Database signed 32-bit INT maximum boundary (MySQL / TiDB)
        $max_signed_int = 2147483647;

        if (is_int($value)) {
            if ($value <= 0) {
                throw new FifoValidationException("{$field_name} must be greater than zero. Received: {$value}");
            }
            if ($value > $max_signed_int) {
                throw new FifoValidationException("{$field_name} exceeds the maximum allowable integer ({$max_signed_int}). Received: {$value}");
            }
            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if (!preg_match('/^[1-9]\d*$/', $trimmed)) {
                throw new FifoValidationException("{$field_name} must be a positive whole number. Received: '{$value}'");
            }

            // Protect against 64-bit PHP string-to-int overflow / 32-bit database INT truncation
            if (strlen($trimmed) > 10 || (strlen($trimmed) === 10 && strcmp($trimmed, (string) $max_signed_int) > 0)) {
                throw new FifoValidationException("{$field_name} exceeds the maximum allowable integer ({$max_signed_int}). Received: '{$value}'");
            }

            return (int) $trimmed;
        }

        throw new FifoValidationException("{$field_name} must be a valid integer. Invalid type provided.");
    }
}

// ============================================================================
// Balance & Reconciliation Helpers
// ============================================================================

if (!function_exists('fifo_get_item_batch_balance')) {
    /**
     * Returns the total sum of remaining quantities across active batches
     * for a given item and branch.
     *
     * SAFETY CHECK:
     * When $detect_anomalies is true (default), detects if any batches have
     * remaining_quantity > 0 but status != 'active'. If found, throws FifoAnomalyException
     * to prevent available stock from being silently hidden by incorrect statuses.
     *
     * @param PDO $pdo
     * @param int $item_id
     * @param int $branch_id
     * @param bool $detect_anomalies Whether to throw on hidden positive stock
     * @return int
     * @throws FifoValidationException
     * @throws FifoAnomalyException
     */
    function fifo_get_item_batch_balance(PDO $pdo, int $item_id, int $branch_id, bool $detect_anomalies = true): int {
        if ($item_id <= 0 || $branch_id <= 0) {
            throw new FifoValidationException("Invalid item ID ({$item_id}) or branch ID ({$branch_id}).");
        }

        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN status = 'active' THEN remaining_quantity ELSE 0 END), 0) AS active_remaining,
                COALESCE(SUM(CASE WHEN status <> 'active' AND remaining_quantity > 0 THEN remaining_quantity ELSE 0 END), 0) AS hidden_positive_remaining,
                COUNT(CASE WHEN status <> 'active' AND remaining_quantity > 0 THEN 1 END) AS hidden_batch_count
            FROM inventory_batches
            WHERE item_id = ?
              AND branch_id = ?
        ");
        $stmt->execute([$item_id, $branch_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $active_remaining = (int) ($row['active_remaining'] ?? 0);
        $hidden_positive = (int) ($row['hidden_positive_remaining'] ?? 0);
        $hidden_count = (int) ($row['hidden_batch_count'] ?? 0);

        if ($detect_anomalies && $hidden_count > 0) {
            throw new FifoAnomalyException(
                "Reconciliation safety error: {$hidden_count} batch(es) for item #{$item_id} at branch #{$branch_id} " .
                "contain positive remaining quantity ({$hidden_positive} unit(s)) but status is not 'active'. " .
                "Stock cannot be determined safely without resolving batch status inconsistencies."
            );
        }

        return $active_remaining;
    }
}

if (!function_exists('fifo_reconcile_item_stock')) {
    /**
     * Reconciles aggregate stock in inventory_items with batch balances
     * in inventory_batches for a specific item and branch.
     *
     * INVARIANT & STATUS SAFETY:
     * - A missing inventory item reports is_reconciled = false with an explicit error.
     * - Validates active status, NULL quantity, and negative quantity in inventory_items.
     * - Detects batches with remaining_quantity > initial_quantity (bounds violation).
     * - Detects batches with remaining_quantity > 0 where status != 'active' (hidden stock).
     * - Detects batches with remaining_quantity <= 0 where status == 'active' (stale active).
     * - Detects batches with remaining_quantity < 0 (negative quantity violation).
     * - Reports anomalies and reconciliation errors without mutating historical database records.
     *
     * @param PDO $pdo
     * @param int $item_id
     * @param int $branch_id
     * @return array Reconciliation summary with detailed anomaly breakdown
     * @throws FifoValidationException
     */
    function fifo_reconcile_item_stock(PDO $pdo, int $item_id, int $branch_id): array {
        if ($item_id <= 0 || $branch_id <= 0) {
            throw new FifoValidationException("Invalid item ID ({$item_id}) or branch ID ({$branch_id}) for reconciliation.");
        }

        $reconciliation_errors = [];

        // Fetch aggregate quantity and status from inventory_items
        $item_stmt = $pdo->prepare("
            SELECT id, item_name, branch_id, quantity, status
            FROM inventory_items
            WHERE id = ? AND branch_id = ?
            LIMIT 1
        ");
        $item_stmt->execute([$item_id, $branch_id]);
        $item = $item_stmt->fetch(PDO::FETCH_ASSOC);

        $item_found = !empty($item);
        if (!$item_found) {
            $reconciliation_errors[] = "Inventory item #{$item_id} at branch #{$branch_id} does not exist in inventory_items.";
            $aggregate_quantity = 0;
            $item_status = null;
        } else {
            $item_status = (string) ($item['status'] ?? '');
            if ($item_status !== 'active') {
                $reconciliation_errors[] = "Inventory item #{$item_id} is not active (status: '{$item_status}').";
            }

            if (!array_key_exists('quantity', $item) || $item['quantity'] === null) {
                $reconciliation_errors[] = "Inventory item #{$item_id} has NULL quantity in inventory_items.";
                $aggregate_quantity = 0;
            } elseif ((int) $item['quantity'] < 0) {
                $reconciliation_errors[] = "Inventory item #{$item_id} has negative quantity ({$item['quantity']}) in inventory_items.";
                $aggregate_quantity = (int) $item['quantity'];
            } else {
                $aggregate_quantity = (int) $item['quantity'];
            }
        }

        // Fetch batch balance and status distribution
        $batch_stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(remaining_quantity), 0) AS total_physical_remaining,
                COALESCE(SUM(CASE WHEN status = 'active' THEN remaining_quantity ELSE 0 END), 0) AS active_remaining,
                COALESCE(SUM(CASE WHEN status <> 'active' AND remaining_quantity > 0 THEN remaining_quantity ELSE 0 END), 0) AS hidden_positive_remaining,
                COUNT(*) AS total_batches,
                COUNT(CASE WHEN status = 'active' THEN 1 END) AS active_batches,
                COUNT(CASE WHEN is_legacy = 1 AND status = 'active' THEN 1 END) AS legacy_active_batches,
                COUNT(CASE WHEN status <> 'active' AND remaining_quantity > 0 THEN 1 END) AS hidden_positive_batch_count,
                COUNT(CASE WHEN status = 'active' AND remaining_quantity <= 0 THEN 1 END) AS active_zero_batch_count,
                COUNT(CASE WHEN remaining_quantity < 0 THEN 1 END) AS negative_batch_count,
                COUNT(CASE WHEN remaining_quantity > initial_quantity THEN 1 END) AS exceeding_initial_count
            FROM inventory_batches
            WHERE item_id = ? AND branch_id = ?
        ");
        $batch_stmt->execute([$item_id, $branch_id]);
        $batch_stats = $batch_stmt->fetch(PDO::FETCH_ASSOC);

        $total_physical_remaining = (int) ($batch_stats['total_physical_remaining'] ?? 0);
        $active_remaining = (int) ($batch_stats['active_remaining'] ?? 0);
        $hidden_positive_remaining = (int) ($batch_stats['hidden_positive_remaining'] ?? 0);
        $hidden_positive_count = (int) ($batch_stats['hidden_positive_batch_count'] ?? 0);
        $active_zero_count = (int) ($batch_stats['active_zero_batch_count'] ?? 0);
        $negative_count = (int) ($batch_stats['negative_batch_count'] ?? 0);
        $exceeding_initial_count = (int) ($batch_stats['exceeding_initial_count'] ?? 0);

        $anomalies_detected = (
            $hidden_positive_count > 0 ||
            $active_zero_count > 0 ||
            $negative_count > 0 ||
            $exceeding_initial_count > 0
        );

        if ($exceeding_initial_count > 0) {
            $reconciliation_errors[] = "Found {$exceeding_initial_count} batch(es) where remaining_quantity exceeds initial_quantity.";
        }
        if ($hidden_positive_count > 0) {
            $reconciliation_errors[] = "Found {$hidden_positive_count} non-active batch(es) with positive remaining quantity ({$hidden_positive_remaining} unit(s)).";
        }
        if ($active_zero_count > 0) {
            $reconciliation_errors[] = "Found {$active_zero_count} active batch(es) with remaining quantity <= 0.";
        }
        if ($negative_count > 0) {
            $reconciliation_errors[] = "Found {$negative_count} batch(es) with negative remaining quantity.";
        }

        $anomalous_batches = [];
        if ($anomalies_detected) {
            $anomaly_stmt = $pdo->prepare("
                SELECT id, batch_code, status, remaining_quantity, initial_quantity, is_legacy, source_type, source_id
                FROM inventory_batches
                WHERE item_id = ?
                  AND branch_id = ?
                  AND (
                      (status <> 'active' AND remaining_quantity > 0)
                      OR (status = 'active' AND remaining_quantity <= 0)
                      OR (remaining_quantity < 0)
                      OR (remaining_quantity > initial_quantity)
                  )
                ORDER BY id ASC
            ");
            $anomaly_stmt->execute([$item_id, $branch_id]);
            $anomalous_batches = $anomaly_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $difference = $active_remaining - $aggregate_quantity;
        if ($difference !== 0) {
            $reconciliation_errors[] = "Quantity mismatch: active batch stock ({$active_remaining}) does not match aggregate stock ({$aggregate_quantity}). Difference: {$difference}.";
        }

        // Must exist, have no validation errors, have no anomalies, and have zero difference
        $is_reconciled = ($item_found && empty($reconciliation_errors) && !$anomalies_detected && $difference === 0);

        return [
            'item_id' => $item_id,
            'branch_id' => $branch_id,
            'item_found' => $item_found,
            'item_name' => $item['item_name'] ?? null,
            'item_status' => $item_status,
            'aggregate_quantity' => $aggregate_quantity,
            'batch_quantity' => $active_remaining,
            'hidden_quantity' => $hidden_positive_remaining,
            'total_physical_batch_quantity' => $total_physical_remaining,
            'difference' => $difference,
            'is_reconciled' => $is_reconciled,
            'anomalies_detected' => $anomalies_detected,
            'reconciliation_errors' => $reconciliation_errors,
            'anomalous_batches' => $anomalous_batches,
            'total_batches' => (int) ($batch_stats['total_batches'] ?? 0),
            'active_batches' => (int) ($batch_stats['active_batches'] ?? 0),
            'legacy_active_batches' => (int) ($batch_stats['legacy_active_batches'] ?? 0),
            'hidden_positive_batch_count' => $hidden_positive_count,
            'active_zero_batch_count' => $active_zero_count,
            'negative_batch_count' => $negative_count,
            'exceeding_initial_count' => $exceeding_initial_count,
        ];
    }
}

// ============================================================================
// Pre-Issuance Locking & Reconciliation Contract (Task 2)
// ============================================================================

if (!function_exists('fifo_verify_pre_issuance')) {
    /**
     * Reusable, read-only pre-issuance validation and locking contract.
     *
     * Pre-condition gate to be called at the beginning of an issuance workflow
     * BEFORE any aggregate stock update or batch allocation.
     *
     * CONTRACT:
     * - Requires an active caller-owned PDO transaction ($pdo->inTransaction() === true).
     * - Locks the inventory_items record with SELECT ... FOR UPDATE.
     * - Validates item existence, active status, and branch ownership.
     * - Validates aggregate quantity (rejects null, negative, or insufficient stock).
     * - Locks active FIFO candidate batches with SELECT ... FOR UPDATE.
     * - Detects any status anomalies or hidden positive batch stock.
     * - Verifies that locked aggregate quantity EQUALS available active FIFO batch quantity.
     * - Performs NO stock-changing SQL (read-only locking).
     *
     * @param PDO $pdo
     * @param int $item_id
     * @param int $branch_id
     * @param int $requested_quantity
     * @return array Verified item and pre-issuance state
     * @throws FifoTransactionException
     * @throws FifoValidationException
     * @throws FifoInsufficientStockException
     * @throws FifoAnomalyException
     */
    function fifo_verify_pre_issuance(
        PDO $pdo,
        int $item_id,
        int $branch_id,
        int $requested_quantity
    ): array {
        // Step 1: Transaction Ownership Guard
        if (!$pdo->inTransaction()) {
            throw new FifoTransactionException(
                "A database transaction must be active before calling fifo_verify_pre_issuance(). " .
                "The caller must open and own the transaction to maintain lock consistency."
            );
        }

        // Step 2: Validate Input Parameters with Signed 32-bit INT bounds
        $valid_quantity = fifo_validate_positive_integer($requested_quantity, 'Requested quantity');

        $item_id = (int) $item_id;
        if ($item_id <= 0) {
            throw new FifoValidationException("Invalid item ID: {$item_id}");
        }

        $branch_id = (int) $branch_id;
        if ($branch_id <= 0) {
            throw new FifoValidationException("Invalid branch ID: {$branch_id}");
        }

        // Step 3: Lock and Validate Inventory Item
        $item_lock_stmt = $pdo->prepare("
            SELECT id, branch_id, item_name, quantity, status
            FROM inventory_items
            WHERE id = ?
            FOR UPDATE
        ");
        $item_lock_stmt->execute([$item_id]);
        $item = $item_lock_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            throw new FifoValidationException("Inventory item #{$item_id} does not exist.");
        }

        if ((int) $item['branch_id'] !== $branch_id) {
            throw new FifoValidationException(
                "Branch ownership mismatch: Item #{$item_id} ('{$item['item_name']}') belongs to branch #{$item['branch_id']}, " .
                "not requested branch #{$branch_id}."
            );
        }

        if (($item['status'] ?? '') !== 'active') {
            throw new FifoValidationException(
                "Inventory item #{$item_id} is not active (status: '{$item['status']}')."
            );
        }

        if (!array_key_exists('quantity', $item) || $item['quantity'] === null) {
            throw new FifoValidationException("Inventory item #{$item_id} has NULL quantity in inventory_items.");
        }

        $aggregate_quantity = (int) $item['quantity'];
        if ($aggregate_quantity < 0) {
            throw new FifoValidationException(
                "Inventory item #{$item_id} has negative quantity ({$aggregate_quantity}) in inventory_items."
            );
        }

        if ($aggregate_quantity < $valid_quantity) {
            throw new FifoInsufficientStockException(
                "Insufficient aggregate stock for item #{$item_id} ('{$item['item_name']}'). " .
                "Available: {$aggregate_quantity}, Requested: {$valid_quantity}."
            );
        }

        // Step 4: Lock and Verify Candidate Batches (FOR UPDATE)
        $batches = fifo_find_candidate_batches($pdo, $item_id, $branch_id, true);

        // Step 5: Check for Status and Bounds Anomalies Across All Batches for this Item
        $anomaly_stmt = $pdo->prepare("
            SELECT
                COUNT(CASE WHEN status <> 'active' AND remaining_quantity > 0 THEN 1 END) AS hidden_positive_count,
                COALESCE(SUM(CASE WHEN status <> 'active' AND remaining_quantity > 0 THEN remaining_quantity ELSE 0 END), 0) AS hidden_positive_qty,
                COUNT(CASE WHEN status = 'active' AND remaining_quantity <= 0 THEN 1 END) AS active_zero_count,
                COUNT(CASE WHEN remaining_quantity < 0 THEN 1 END) AS negative_count,
                COUNT(CASE WHEN remaining_quantity > initial_quantity THEN 1 END) AS exceeding_initial_count
            FROM inventory_batches
            WHERE item_id = ? AND branch_id = ?
        ");
        $anomaly_stmt->execute([$item_id, $branch_id]);
        $anomalies = $anomaly_stmt->fetch(PDO::FETCH_ASSOC);

        $hidden_count = (int) ($anomalies['hidden_positive_count'] ?? 0);
        $hidden_qty = (int) ($anomalies['hidden_positive_qty'] ?? 0);
        $active_zero = (int) ($anomalies['active_zero_count'] ?? 0);
        $negative_count = (int) ($anomalies['negative_count'] ?? 0);
        $exceeding_count = (int) ($anomalies['exceeding_initial_count'] ?? 0);

        if ($hidden_count > 0) {
            throw new FifoAnomalyException(
                "Pre-issuance check failed: Item #{$item_id} has {$hidden_count} non-active batch(es) " .
                "with positive remaining quantity ({$hidden_qty} units). Stock is hidden by invalid status."
            );
        }

        if ($active_zero > 0) {
            throw new FifoAnomalyException(
                "Pre-issuance check failed: Item #{$item_id} has {$active_zero} active batch(es) " .
                "with zero or negative remaining quantity. Exhausted batches must transition to status 'exhausted'."
            );
        }

        if ($negative_count > 0) {
            throw new FifoAnomalyException(
                "Pre-issuance check failed: Item #{$item_id} has {$negative_count} batch(es) with negative remaining quantity."
            );
        }

        if ($exceeding_count > 0) {
            throw new FifoAnomalyException(
                "Pre-issuance check failed: Item #{$item_id} has {$exceeding_count} batch(es) where remaining quantity exceeds initial quantity."
            );
        }

        // Step 6: Parity Reconciliation (Aggregate Stock MUST Equal Available Active Batch Stock)
        $active_batch_quantity = array_sum(array_map(function($b) {
            return (int) $b['remaining_quantity'];
        }, $batches));

        if ($aggregate_quantity !== $active_batch_quantity) {
            throw new FifoAnomalyException(
                "Pre-issuance reconciliation mismatch for item #{$item_id} ('{$item['item_name']}'): " .
                "Aggregate quantity ({$aggregate_quantity}) does not match total active batch quantity ({$active_batch_quantity}). " .
                "Issuance halted to prevent desynchronization."
            );
        }

        if ($active_batch_quantity < $valid_quantity) {
            throw new FifoInsufficientStockException(
                "Insufficient batch stock for item #{$item_id} ('{$item['item_name']}'). " .
                "Available in active batches: {$active_batch_quantity}, Requested: {$valid_quantity}."
            );
        }

        // Returns verified pre-issuance state without modifying database records
        return [
            'item_id' => $item_id,
            'branch_id' => $branch_id,
            'item_name' => (string) $item['item_name'],
            'aggregate_quantity' => $aggregate_quantity,
            'active_batch_quantity' => $active_batch_quantity,
            'requested_quantity' => $valid_quantity,
            'remaining_after' => $aggregate_quantity - $valid_quantity,
            'candidate_batch_count' => count($batches),
            'is_pre_issuance_valid' => true,
        ];
    }
}

// ============================================================================
// Batch Candidate Discovery & Preview
// ============================================================================

if (!function_exists('fifo_find_candidate_batches')) {
    /**
     * Retrieves active candidate batches for an item and branch following
     * deterministic FIFO ordering:
     * 1. Legacy batches first (is_legacy DESC: 1 before 0)
     * 2. Oldest actual receipt date (received_at ASC)
     * 3. Batch ID as tie-breaker (id ASC)
     *
     * Utilizes composite index: idx_fifo_candidate (item_id, branch_id, status, is_legacy, received_at, id)
     *
     * @param PDO $pdo
     * @param int $item_id
     * @param int $branch_id
     * @param bool $for_update Whether to lock rows with FOR UPDATE
     * @return array List of candidate batch rows
     */
    function fifo_find_candidate_batches(PDO $pdo, int $item_id, int $branch_id, bool $for_update = false): array {
        $sql = "
            SELECT
                id,
                item_id,
                branch_id,
                batch_code,
                source_type,
                source_id,
                source_ref,
                initial_quantity,
                remaining_quantity,
                received_at,
                is_legacy,
                status
            FROM inventory_batches
            WHERE item_id = ?
              AND branch_id = ?
              AND status = 'active'
              AND remaining_quantity > 0
            ORDER BY
                is_legacy DESC,
                received_at ASC,
                id ASC
        ";

        if ($for_update) {
            $sql .= " FOR UPDATE";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$item_id, $branch_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('fifo_preview_allocation')) {
    /**
     * Performs a non-mutating preview / dry-run of a FIFO allocation.
     * Computes how a requested quantity would be distributed across batches.
     *
     * @param PDO $pdo
     * @param int $item_id
     * @param int $branch_id
     * @param int $quantity
     * @return array Preview breakdown
     * @throws FifoValidationException
     */
    function fifo_preview_allocation(PDO $pdo, int $item_id, int $branch_id, int $quantity): array {
        $valid_quantity = fifo_validate_positive_integer($quantity, 'Requested quantity');

        $batches = fifo_find_candidate_batches($pdo, $item_id, $branch_id, false);

        $remaining_to_allocate = $valid_quantity;
        $allocations = [];
        $total_available = 0;

        foreach ($batches as $batch) {
            $available = (int) $batch['remaining_quantity'];
            $total_available += $available;

            if ($remaining_to_allocate <= 0) {
                continue;
            }

            $deduct = min($available, $remaining_to_allocate);
            $new_remaining = $available - $deduct;
            $new_status = ($new_remaining === 0) ? 'exhausted' : 'active';

            $allocations[] = [
                'batch_id' => (int) $batch['id'],
                'batch_code' => $batch['batch_code'],
                'is_legacy' => (int) $batch['is_legacy'],
                'received_at' => $batch['received_at'],
                'available_before' => $available,
                'allocated_quantity' => $deduct,
                'remaining_after' => $new_remaining,
                'resulting_status' => $new_status,
            ];

            $remaining_to_allocate -= $deduct;
        }

        $can_fulfill = ($remaining_to_allocate === 0);
        $shortage = max(0, $remaining_to_allocate);

        return [
            'item_id' => $item_id,
            'branch_id' => $branch_id,
            'requested_quantity' => $valid_quantity,
            'total_batch_available' => $total_available,
            'can_fulfill' => $can_fulfill,
            'shortage' => $shortage,
            'allocations' => $allocations,
        ];
    }
}

// ============================================================================
// Core FIFO Issuance Allocation (Atomic & Idempotent)
// ============================================================================

if (!function_exists('fifo_allocate_for_transaction')) {
    /**
     * Executes guarded FIFO batch deductions and creates batch allocation records
     * linked to an existing parent inventory transaction.
     *
     * STRICT TRANSACTION OWNERSHIP CONTRACT:
     * - Requires $pdo->inTransaction() to be TRUE before execution.
     * - If no transaction exists, throws FifoTransactionException immediately before any read or write.
     * - Does NOT call beginTransaction(), commit(), or rollBack().
     * - All exceptions propagate to the caller, which must roll back the entire stock movement.
     *
     * PARENT TRANSACTION VALIDATION CONTRACT:
     * 1. Transaction ID must exist in inventory_transactions.
     * 2. Transaction must be an inventory reduction (transaction_type = 'stock_out', 'damage',
     *    or 'adjustment' with reference_type = 'inventory_recount_down').
     * 3. Item ID on parent transaction must match the requested item ID.
     * 4. Quantity on parent transaction must match the requested issued quantity.
     * 5. Inventory item must exist and belong to the supplied branch.
     * 6. No issuance allocations can already exist for this transaction.
     *
     * BATCH DEDUCTION & CONCURRENCY CONTRACT:
     * 1. Reads and locks candidate batches FOR UPDATE within caller's transaction.
     * 2. Calculates deduction and new remaining quantity once in PHP.
     * 3. Determines new status ('active' or 'exhausted') in PHP from calculated quantity.
     * 4. Updates batch using explicit calculated values: SET remaining_quantity = ?, status = ?
     * 5. Includes locked original quantity and status in WHERE clause as concurrency guards.
     * 6. Verifies rowCount() === 1 on every batch update.
     * 7. Enforces that remaining_quantity is never negative.
     * 8. Preserves legacy-first deterministic FIFO order.
     *
     * @param PDO $pdo
     * @param int $transaction_id ID of existing parent inventory_transactions record
     * @param int $item_id ID of inventory item being issued
     * @param int $branch_id Branch ID where stock is located
     * @param int $quantity Positive whole number quantity to issue
     * @param string|null $notes Optional allocation note
     * @return array Created allocation records with batch details
     * @throws FifoTransactionException
     * @throws FifoValidationException
     * @throws FifoDuplicateAllocationException
     * @throws FifoInsufficientStockException
     * @throws FifoConcurrencyException
     * @throws FifoException
     */
    function fifo_allocate_for_transaction(
        PDO $pdo,
        int $transaction_id,
        int $item_id,
        int $branch_id,
        int $quantity,
        ?string $notes = null
    ): array {
        // Step 1: Strict Transaction Ownership Check
        if (!$pdo->inTransaction()) {
            throw new FifoTransactionException(
                "A database transaction must be active before calling fifo_allocate_for_transaction(). " .
                "The caller must open and own the transaction to guarantee atomicity across all inventory and allocation steps."
            );
        }

        // Step 2: Input Parameter Validation with 32-bit INT bounds
        $valid_quantity = fifo_validate_positive_integer($quantity, 'Allocation quantity');

        $transaction_id = (int) $transaction_id;
        if ($transaction_id <= 0) {
            throw new FifoValidationException("Invalid parent transaction ID: {$transaction_id}");
        }

        $item_id = (int) $item_id;
        if ($item_id <= 0) {
            throw new FifoValidationException("Invalid item ID: {$item_id}");
        }

        $branch_id = (int) $branch_id;
        if ($branch_id <= 0) {
            throw new FifoValidationException("Invalid branch ID: {$branch_id}");
        }

        // Step 3: Validate Parent Transaction Identity & Consistency
        // Supports: stock_out, damage, and downward recount adjustments (Task 4)
        $tx_stmt = $pdo->prepare("
            SELECT id, item_id, transaction_type, reference_type, quantity
            FROM inventory_transactions
            WHERE id = ?
            LIMIT 1
        ");
        $tx_stmt->execute([$transaction_id]);
        $parent_tx = $tx_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$parent_tx) {
            throw new FifoValidationException("Parent inventory transaction #{$transaction_id} does not exist.");
        }

        $normalized_type = strtolower(str_replace(' ', '_', (string) $parent_tx['transaction_type']));
        $ref_type = strtolower(trim((string) ($parent_tx['reference_type'] ?? '')));

        $is_reduction_type = ($normalized_type === 'stock_out')
            || ($normalized_type === 'damage')
            || ($normalized_type === 'adjustment' && $ref_type === 'inventory_recount_down');

        if (!$is_reduction_type) {
            throw new FifoValidationException(
                "Parent inventory transaction #{$transaction_id} is not an authorized stock reduction. " .
                "Expected transaction_type 'stock_out', 'damage', or 'adjustment' with reference_type 'inventory_recount_down'. " .
                "Received transaction_type '{$parent_tx['transaction_type']}' and reference_type '{$parent_tx['reference_type']}'."
            );
        }

        if ((int) $parent_tx['item_id'] !== $item_id) {
            throw new FifoValidationException(
                "Parent transaction #{$transaction_id} item mismatch: recorded item #{$parent_tx['item_id']}, requested item #{$item_id}."
            );
        }

        $parent_quantity = (int) $parent_tx['quantity'];
        if ($parent_quantity !== $valid_quantity) {
            throw new FifoValidationException(
                "Parent transaction #{$transaction_id} quantity mismatch: recorded quantity {$parent_quantity}, requested issued quantity {$valid_quantity}."
            );
        }

        // Step 4: Validate Item & Branch Context with Concurrency Lock
        $item_lock_stmt = $pdo->prepare("
            SELECT id, branch_id, quantity, status
            FROM inventory_items
            WHERE id = ?
            FOR UPDATE
        ");
        $item_lock_stmt->execute([$item_id]);
        $locked_item = $item_lock_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$locked_item) {
            throw new FifoValidationException("Inventory item #{$item_id} does not exist.");
        }

        if ((int) $locked_item['branch_id'] !== $branch_id) {
            throw new FifoValidationException(
                "Branch mismatch: Item #{$item_id} belongs to branch #{$locked_item['branch_id']}, but branch #{$branch_id} was requested."
            );
        }

        if (($locked_item['status'] ?? '') !== 'active') {
            throw new FifoValidationException(
                "Inventory item #{$item_id} is not active (status: '{$locked_item['status']}')."
            );
        }

        // Step 5: Idempotency Check — Ensure No Prior Issuance Allocations Exist
        $existing_stmt = $pdo->prepare("
            SELECT id, batch_id, allocated_quantity
            FROM inventory_batch_allocations
            WHERE transaction_id = ?
              AND alloc_type = 'issuance'
        ");
        $existing_stmt->execute([$transaction_id]);
        $existing_allocations = $existing_stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($existing_allocations)) {
            $total_already_allocated = array_sum(array_column($existing_allocations, 'allocated_quantity'));
            throw new FifoDuplicateAllocationException(
                "Transaction #{$transaction_id} already has {$total_already_allocated} unit(s) allocated across " .
                count($existing_allocations) . " batch allocation line(s). Duplicate allocation is strictly prevented."
            );
        }

        // Step 6: Select & Lock Candidate Batches in Deterministic FIFO Order
        $batches = fifo_find_candidate_batches($pdo, $item_id, $branch_id, true);

        $total_batch_available = array_sum(array_map(function($b) {
            return (int) $b['remaining_quantity'];
        }, $batches));

        if ($total_batch_available < $valid_quantity) {
            throw new FifoInsufficientStockException(
                "Insufficient batch stock for item #{$item_id} at branch #{$branch_id}. " .
                "Required: {$valid_quantity}, Available in active batches: {$total_batch_available}."
            );
        }

        // Step 7: Allocate Across Batches (Deterministic Deduction Loop)
        $remaining_needed = $valid_quantity;
        $created_allocations = [];

        // Concurrency-guarded update query using explicit calculated values
        $update_batch_stmt = $pdo->prepare("
            UPDATE inventory_batches
            SET remaining_quantity = ?,
                status = ?
            WHERE id = ?
              AND remaining_quantity = ?
              AND status = ?
        ");

        $insert_alloc_stmt = $pdo->prepare("
            INSERT INTO inventory_batch_allocations
                (transaction_id, batch_id, item_id, branch_id, alloc_type, allocated_quantity, reversal_of_id, notes)
            VALUES
                (?, ?, ?, ?, 'issuance', ?, 0, ?)
        ");

        foreach ($batches as $batch) {
            if ($remaining_needed <= 0) {
                break;
            }

            $batch_id = (int) $batch['id'];
            $original_remaining = (int) $batch['remaining_quantity'];
            $original_status = (string) $batch['status'];

            if ($original_remaining <= 0 || $original_status !== 'active') {
                continue;
            }

            // Calculate deduction in PHP
            $deduct = min($original_remaining, $remaining_needed);
            if ($deduct <= 0) {
                continue;
            }

            // Calculate new remaining quantity in PHP
            $new_remaining = $original_remaining - $deduct;
            if ($new_remaining < 0) {
                throw new FifoValidationException(
                    "Invariant violation: Calculated remaining quantity ({$new_remaining}) cannot be negative for batch #{$batch_id}."
                );
            }

            // Determine new status deterministically in PHP
            $new_status = ($new_remaining === 0) ? 'exhausted' : 'active';

            // Guarded batch update with explicit concurrency guard in WHERE condition
            $update_batch_stmt->execute([
                $new_remaining,
                $new_status,
                $batch_id,
                $original_remaining,
                $original_status,
            ]);

            if ($update_batch_stmt->rowCount() !== 1) {
                throw new FifoConcurrencyException(
                    "Concurrency conflict on batch #{$batch_id} ({$batch['batch_code']}). " .
                    "Expected remaining {$original_remaining} and status '{$original_status}', but row was modified concurrently."
                );
            }

            // Record allocation line
            $insert_alloc_stmt->execute([
                $transaction_id,
                $batch_id,
                $item_id,
                $branch_id,
                $deduct,
                $notes !== null ? substr($notes, 0, 255) : null,
            ]);

            $allocation_id = (int) $pdo->lastInsertId();

            $created_allocations[] = [
                'allocation_id' => $allocation_id,
                'transaction_id' => $transaction_id,
                'batch_id' => $batch_id,
                'batch_code' => $batch['batch_code'],
                'item_id' => $item_id,
                'branch_id' => $branch_id,
                'allocated_quantity' => $deduct,
                'remaining_after' => $new_remaining,
                'batch_status_after' => $new_status,
                'is_legacy' => (int) $batch['is_legacy'],
            ];

            $remaining_needed -= $deduct;
        }

        // Step 8: Verify Complete Allocation Invariant
        if ($remaining_needed !== 0) {
            throw new FifoException(
                "FIFO allocation failed invariant check. Unallocated remainder: {$remaining_needed}."
            );
        }

        // Caller owns transaction commit; do not independently commit
        return $created_allocations;
    }
}

// ============================================================================
// Core FIFO Allocation Reversal (Atomic, Deterministic LIFO & Idempotent)
// ============================================================================

if (!function_exists('fifo_reverse_allocation')) {
    /**
     * Reverses previous FIFO batch allocations linked to an original stock reduction transaction.
     * Restores quantities to the original batches in deterministic LIFO order (most recently
     * issued batch allocation reversed first), reactivates exhausted batches, and records
     * reversal allocation records (alloc_type = 'reversal', reversal_of_id = issuance_allocation.id).
     *
     * STRICT TRANSACTION OWNERSHIP CONTRACT:
     * - Requires $pdo->inTransaction() to be TRUE before execution.
     * - If no transaction exists, throws FifoTransactionException immediately.
     * - Does NOT call beginTransaction(), commit(), or rollBack().
     * - All exceptions propagate to the caller, which must roll back the complete operation.
     *
     * CALLER CONTRACT FOR AGGREGATE INVENTORY SYNCHRONIZATION:
     * - This helper restores batch quantities in inventory_batches and creates allocation rows
     *   in inventory_batch_allocations.
     * - It DOES NOT update inventory_items.quantity.
     * - The caller MUST execute the aggregate stock restoration within the SAME transaction:
     *   UPDATE inventory_items SET quantity = quantity + ? WHERE id = ?
     * - The caller must also insert the reversal transaction in inventory_transactions
     *   prior to calling this helper and pass its ID as $reversal_transaction_id.
     *
     * BUSINESS IDENTITY & REFERENCE VALIDATION:
     * - Validates that reversal and original transactions match by item_id and branch ownership.
     * - For transfers ('inter_branch_transfer'): validates that both transactions reference the
     *   same transfer ID (reference_id).
     * - For quotations ('quotation'): validates that both transactions reference the same quotation ID
     *   (reference_id), and where available, verifies line identity using quotation_item_id.
     * - For job orders ('job_order'): validates that both transactions reference the same job order.
     *
     * ORIGINAL ALLOCATION COMPLETENESS & RECONCILIATION:
     * - Verifies that the sum of original issuance allocation quantities exactly matches the
     *   original transaction's recorded quantity before any restoration occurs.
     * - Validates each original issuance line: must belong to the correct item and branch, and
     *   must have a strictly positive allocated quantity.
     *
     * INVENTORY INTEGRITY SAFEGUARDS:
     * 1. Requires the inventory item to be active (status === 'active') and rejects NULL or negative
     *    aggregate quantities in inventory_items.
     * 2. Verifies that candidate batches being restored belong to the verified item and branch.
     * 3. Enforces that remaining_quantity + restore_qty does not exceed initial_quantity on any batch.
     * 4. Enforces optimistic concurrency locks on batches (WHERE remaining_quantity = ? AND status = ?).
     *
     * REVERSAL ORDERING & LIFO RESTORATION:
     * - Issuance allocations are ordered by id DESC (LIFO restoration).
     * - The most recently issued batch line is restored first.
     * - If partial reversal is requested, earlier batches remain exhausted or partially consumed.
     * - If multiple reversals occur against the same original transaction, previous reversals
     *   are subtracted to determine the net unreversed capacity of each allocation line.
     *
     * IDEMPOTENCY & DUPLICATE REVERSAL PROTECTION:
     * - Helper-Level: Rejects duplicate processing if $reversal_transaction_id has existing reversal allocations.
     * - Database-Level: Composite unique index uq_alloc_line (transaction_id, batch_id, alloc_type, reversal_of_id)
     *   prevents duplicate allocation inserts for the same transaction and allocation line.
     * - Caller-Level Obligation: The unique index protects against re-using the same transaction record.
     *   However, if a business workflow generates a *new* reversal transaction ID on an accidental repeat request
     *   (e.g. double-clicking "Confirm Return"), helper-level checks alone cannot prevent re-reversal if unreversed
     *   capacity remains on the original issuance. Therefore, callers MUST implement application-level idempotency
     *   (such as checking transfer status, [RETURNED] note markers, or quotation archive status) before generating
     *   new transaction IDs.
     *
     * @param PDO $pdo
     * @param int $reversal_transaction_id ID of existing parent reversal transaction (stock_in or adjustment)
     * @param int $original_stock_out_transaction_id ID of original stock reduction transaction (stock_out, damage, recount_down)
     * @param int|null $max_quantity_to_reverse Optional maximum quantity to reverse; if null, defaults to reversal transaction quantity
     * @param string|null $notes Optional reversal note
     * @return array Created reversal allocation records with batch details
     * @throws FifoTransactionException
     * @throws FifoValidationException
     * @throws FifoDuplicateAllocationException
     * @throws FifoConcurrencyException
     * @throws FifoAnomalyException
     * @throws FifoException
     */
    function fifo_reverse_allocation(
        PDO $pdo,
        int $reversal_transaction_id,
        int $original_stock_out_transaction_id,
        ?int $max_quantity_to_reverse = null,
        ?string $notes = null
    ): array {
        // Step 1: Strict Transaction Ownership Check
        if (!$pdo->inTransaction()) {
            throw new FifoTransactionException(
                "A database transaction must be active before calling fifo_reverse_allocation(). " .
                "The caller must open and own the transaction to guarantee atomicity across all inventory and reversal steps."
            );
        }

        // Step 2: Input Parameter Validation
        $reversal_transaction_id = (int) $reversal_transaction_id;
        if ($reversal_transaction_id <= 0) {
            throw new FifoValidationException("Invalid reversal transaction ID: {$reversal_transaction_id}");
        }

        $original_stock_out_transaction_id = (int) $original_stock_out_transaction_id;
        if ($original_stock_out_transaction_id <= 0) {
            throw new FifoValidationException("Invalid original stock-out transaction ID: {$original_stock_out_transaction_id}");
        }

        $valid_max_quantity = null;
        if ($max_quantity_to_reverse !== null) {
            $valid_max_quantity = fifo_validate_positive_integer($max_quantity_to_reverse, 'Max quantity to reverse');
        }

        // Step 3: Validate Original Reduction Transaction
        $orig_stmt = $pdo->prepare("
            SELECT *
            FROM inventory_transactions
            WHERE id = ?
            LIMIT 1
        ");
        $orig_stmt->execute([$original_stock_out_transaction_id]);
        $orig_tx = $orig_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$orig_tx) {
            throw new FifoValidationException("Original inventory transaction #{$original_stock_out_transaction_id} does not exist.");
        }

        $orig_type = strtolower(str_replace(' ', '_', (string) $orig_tx['transaction_type']));
        $orig_ref = strtolower(trim((string) ($orig_tx['reference_type'] ?? '')));

        $is_orig_reduction = ($orig_type === 'stock_out')
            || ($orig_type === 'damage')
            || ($orig_type === 'adjustment' && $orig_ref === 'inventory_recount_down');

        if (!$is_orig_reduction) {
            throw new FifoValidationException(
                "Original transaction #{$original_stock_out_transaction_id} is not an authorized stock reduction. " .
                "Expected transaction_type 'stock_out', 'damage', or 'adjustment' with reference_type 'inventory_recount_down'. " .
                "Received transaction_type '{$orig_tx['transaction_type']}' and reference_type '{$orig_tx['reference_type']}'."
            );
        }

        $orig_quantity = fifo_validate_positive_integer($orig_tx['quantity'], "Original transaction #{$original_stock_out_transaction_id} quantity");
        $item_id = (int) $orig_tx['item_id'];

        // Step 4: Validate Reversal Transaction
        $rev_stmt = $pdo->prepare("
            SELECT *
            FROM inventory_transactions
            WHERE id = ?
            LIMIT 1
        ");
        $rev_stmt->execute([$reversal_transaction_id]);
        $reversal_tx = $rev_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$reversal_tx) {
            throw new FifoValidationException("Reversal inventory transaction #{$reversal_transaction_id} does not exist.");
        }

        $reversal_type = strtolower(str_replace(' ', '_', (string) $reversal_tx['transaction_type']));
        $is_reversal_valid = ($reversal_type === 'stock_in')
            || ($reversal_type === 'adjustment');

        if (!$is_reversal_valid) {
            throw new FifoValidationException(
                "Reversal inventory transaction #{$reversal_transaction_id} is not an authorized stock restoration. " .
                "Expected transaction_type 'stock_in' or 'adjustment'. " .
                "Received transaction_type '{$reversal_tx['transaction_type']}'."
            );
        }

        $reversal_quantity = fifo_validate_positive_integer($reversal_tx['quantity'], "Reversal transaction #{$reversal_transaction_id} quantity");

        if ((int) $reversal_tx['item_id'] !== $item_id) {
            throw new FifoValidationException(
                "Item mismatch between original transaction #{$original_stock_out_transaction_id} (item #{$item_id}) " .
                "and reversal transaction #{$reversal_transaction_id} (item #{$reversal_tx['item_id']})."
            );
        }

        if ($valid_max_quantity !== null && $valid_max_quantity !== $reversal_quantity) {
            throw new FifoValidationException(
                "Requested max reversal quantity ({$valid_max_quantity}) does not match reversal transaction #{$reversal_transaction_id} recorded quantity ({$reversal_quantity})."
            );
        }

        $target_reversal_quantity = $reversal_quantity;

        // Step 5: Validate Reversal Business Identity & Workflow Consistency
        $rev_ref = strtolower(trim((string) ($reversal_tx['reference_type'] ?? '')));
        $orig_ref_id = isset($orig_tx['reference_id']) && $orig_tx['reference_id'] !== null ? (int) $orig_tx['reference_id'] : null;
        $rev_ref_id = isset($reversal_tx['reference_id']) && $reversal_tx['reference_id'] !== null ? (int) $reversal_tx['reference_id'] : null;

        // Transfers: strictly require same transfer reference
        if ($orig_ref === 'inter_branch_transfer' || $rev_ref === 'inter_branch_transfer') {
            if ($orig_ref !== $rev_ref) {
                throw new FifoValidationException(
                    "Business reference type mismatch for transfer reversal: original transaction is '{$orig_ref}', reversal transaction is '{$rev_ref}'."
                );
            }
            if ($orig_ref_id === null || $orig_ref_id <= 0 || $rev_ref_id === null || $rev_ref_id <= 0 || $orig_ref_id !== $rev_ref_id) {
                throw new FifoValidationException(
                    "Transfer reference ID mismatch: original transaction references transfer #{$orig_ref_id}, reversal transaction references transfer #{$rev_ref_id}."
                );
            }
        }

        // Quotations: strictly require same quotation reference and line item when present
        if ($orig_ref === 'quotation' || $rev_ref === 'quotation') {
            if ($orig_ref !== $rev_ref) {
                throw new FifoValidationException(
                    "Business reference type mismatch for quotation reversal: original transaction is '{$orig_ref}', reversal transaction is '{$rev_ref}'."
                );
            }
            if ($orig_ref_id === null || $orig_ref_id <= 0 || $rev_ref_id === null || $rev_ref_id <= 0 || $orig_ref_id !== $rev_ref_id) {
                throw new FifoValidationException(
                    "Quotation reference ID mismatch: original transaction references quotation #{$orig_ref_id}, reversal transaction references quotation #{$rev_ref_id}."
                );
            }

            // Distinguish quotation lines using quotation_item_id where present in the schema
            $orig_q_item = isset($orig_tx['quotation_item_id']) && $orig_tx['quotation_item_id'] !== null ? (int) $orig_tx['quotation_item_id'] : null;
            $rev_q_item = isset($reversal_tx['quotation_item_id']) && $reversal_tx['quotation_item_id'] !== null ? (int) $reversal_tx['quotation_item_id'] : null;

            if ($orig_q_item !== null && $rev_q_item !== null && $orig_q_item > 0 && $rev_q_item > 0) {
                if ($orig_q_item !== $rev_q_item) {
                    throw new FifoValidationException(
                        "Quotation line item mismatch: original transaction references quotation_item #{$orig_q_item}, " .
                        "reversal transaction references quotation_item #{$rev_q_item}."
                    );
                }
            }
        }

        // Job orders: validate reference when both belong to job order workflow
        if ($orig_ref === 'job_order' || $rev_ref === 'job_order') {
            if ($orig_ref !== $rev_ref) {
                throw new FifoValidationException(
                    "Business reference type mismatch for job order reversal: original is '{$orig_ref}', reversal is '{$rev_ref}'."
                );
            }
            if ($orig_ref_id !== null && $rev_ref_id !== null && $orig_ref_id > 0 && $rev_ref_id > 0 && $orig_ref_id !== $rev_ref_id) {
                throw new FifoValidationException(
                    "Job order reference ID mismatch: original references job order #{$orig_ref_id}, reversal references job order #{$rev_ref_id}."
                );
            }
        }

        // Step 6: Lock Inventory Item FOR UPDATE & Validate Item / Branch Integrity
        $item_stmt = $pdo->prepare("
            SELECT id, item_name, branch_id, quantity, status
            FROM inventory_items
            WHERE id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $item_stmt->execute([$item_id]);
        $item_row = $item_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item_row) {
            throw new FifoValidationException("Inventory item #{$item_id} does not exist in inventory_items.");
        }

        // Verify inventory item is active
        $item_status = (string) ($item_row['status'] ?? '');
        if ($item_status !== 'active') {
            throw new FifoValidationException(
                "Inventory item #{$item_id} ('{$item_row['item_name']}') is not active (status: '{$item_status}'). Reversals cannot restore stock to inactive items."
            );
        }

        // Reject NULL or negative aggregate stock
        if (!array_key_exists('quantity', $item_row) || $item_row['quantity'] === null) {
            throw new FifoValidationException("Inventory item #{$item_id} has NULL quantity in inventory_items.");
        }

        $aggregate_quantity = (int) $item_row['quantity'];
        if ($aggregate_quantity < 0) {
            throw new FifoValidationException(
                "Inventory item #{$item_id} has negative aggregate quantity ({$aggregate_quantity}) in inventory_items."
            );
        }

        $branch_id = (int) $item_row['branch_id'];
        if ($branch_id <= 0) {
            throw new FifoValidationException("Invalid branch ID ({$branch_id}) associated with item #{$item_id}.");
        }

        // Step 7: Idempotency & Duplicate Reversal Check
        $dup_stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM inventory_batch_allocations
            WHERE transaction_id = ?
              AND alloc_type = 'reversal'
        ");
        $dup_stmt->execute([$reversal_transaction_id]);
        $already_reversed_count = (int) $dup_stmt->fetchColumn();

        if ($already_reversed_count > 0) {
            throw new FifoDuplicateAllocationException(
                "Reversal transaction #{$reversal_transaction_id} has already been processed with {$already_reversed_count} reversal allocation line(s). Duplicate reversal is strictly prevented."
            );
        }

        // Step 8: Query Original Issuance Allocations and Verify Completeness
        $alloc_stmt = $pdo->prepare("
            SELECT
                a.id AS allocation_id,
                a.transaction_id,
                a.batch_id,
                a.item_id,
                a.branch_id,
                a.allocated_quantity AS original_allocated_quantity,
                COALESCE(SUM(r.allocated_quantity), 0) AS previously_reversed_quantity
            FROM inventory_batch_allocations a
            LEFT JOIN inventory_batch_allocations r
                ON r.alloc_type = 'reversal' AND r.reversal_of_id = a.id
            WHERE a.transaction_id = ?
              AND a.alloc_type = 'issuance'
            GROUP BY a.id, a.transaction_id, a.batch_id, a.item_id, a.branch_id, a.allocated_quantity
            ORDER BY a.id DESC
        ");
        $alloc_stmt->execute([$original_stock_out_transaction_id]);
        $issuance_lines = $alloc_stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($issuance_lines)) {
            throw new FifoValidationException(
                "Original transaction #{$original_stock_out_transaction_id} has no FIFO issuance allocations recorded. Cannot reverse."
            );
        }

        $total_original_allocated = 0;
        $total_previously_reversed = 0;
        $candidates = [];

        foreach ($issuance_lines as $line) {
            $alloc_id = (int) $line['allocation_id'];
            $orig_allocated = (int) $line['original_allocated_quantity'];
            $prev_reversed = (int) $line['previously_reversed_quantity'];
            $line_item_id = (int) $line['item_id'];
            $line_branch_id = (int) $line['branch_id'];

            // Reject non-positive or invalid allocation quantities
            if ($orig_allocated <= 0) {
                throw new FifoAnomalyException(
                    "Issuance allocation line #{$alloc_id} has invalid non-positive allocated quantity ({$orig_allocated})."
                );
            }

            // Validate that every original allocation belongs to the correct item and branch
            if ($line_item_id !== $item_id) {
                throw new FifoAnomalyException(
                    "Issuance allocation line #{$alloc_id} item mismatch: recorded item #{$line_item_id}, expected item #{$item_id}."
                );
            }

            if ($line_branch_id !== $branch_id) {
                throw new FifoAnomalyException(
                    "Issuance allocation line #{$alloc_id} branch mismatch: recorded branch #{$line_branch_id}, expected branch #{$branch_id}."
                );
            }

            if ($prev_reversed < 0) {
                throw new FifoAnomalyException("Allocation line #{$alloc_id} has negative previously reversed quantity ({$prev_reversed}).");
            }

            if ($prev_reversed > $orig_allocated) {
                throw new FifoAnomalyException(
                    "Allocation line #{$alloc_id} has previously reversed quantity ({$prev_reversed}) exceeding original allocated quantity ({$orig_allocated})."
                );
            }

            $unreversed = $orig_allocated - $prev_reversed;

            $total_original_allocated += $orig_allocated;
            $total_previously_reversed += $prev_reversed;

            if ($unreversed > 0) {
                $candidates[] = [
                    'allocation_id' => $alloc_id,
                    'batch_id' => (int) $line['batch_id'],
                    'item_id' => $line_item_id,
                    'branch_id' => $line_branch_id,
                    'original_allocated_quantity' => $orig_allocated,
                    'previously_reversed_quantity' => $prev_reversed,
                    'unreversed_quantity' => $unreversed,
                ];
            }
        }

        // Verify original allocation completeness: sum of allocations MUST equal original transaction quantity
        if ($total_original_allocated !== $orig_quantity) {
            throw new FifoAnomalyException(
                "Original allocation completeness check failed for transaction #{$original_stock_out_transaction_id}: " .
                "Sum of issuance allocations ({$total_original_allocated}) does not match recorded transaction quantity ({$orig_quantity}). " .
                "Reversal halted to prevent corrupt allocation states."
            );
        }

        $total_available_to_reverse = array_sum(array_column($candidates, 'unreversed_quantity'));

        if ($target_reversal_quantity > $total_available_to_reverse) {
            throw new FifoValidationException(
                "Requested reversal quantity ({$target_reversal_quantity}) exceeds available unreversed quantity ({$total_available_to_reverse}) " .
                "for original transaction #{$original_stock_out_transaction_id} (original allocated: {$total_original_allocated}, already reversed: {$total_previously_reversed})."
            );
        }

        // Step 9: Execute Restoration Loop in LIFO Order
        $remaining_needed = $target_reversal_quantity;
        $created_reversals = [];

        $batch_stmt = $pdo->prepare("
            SELECT id, item_id, branch_id, batch_code, initial_quantity, remaining_quantity, status
            FROM inventory_batches
            WHERE id = ?
            LIMIT 1
            FOR UPDATE
        ");

        $update_batch_stmt = $pdo->prepare("
            UPDATE inventory_batches
            SET remaining_quantity = ?,
                status = 'active'
            WHERE id = ?
              AND remaining_quantity = ?
              AND status = ?
        ");

        $insert_alloc_stmt = $pdo->prepare("
            INSERT INTO inventory_batch_allocations (
                transaction_id, batch_id, item_id, branch_id, alloc_type, allocated_quantity, reversal_of_id, notes
            ) VALUES (
                ?, ?, ?, ?, 'reversal', ?, ?, ?
            )
        ");

        foreach ($candidates as $cand) {
            if ($remaining_needed <= 0) {
                break;
            }

            $restore_qty = min($cand['unreversed_quantity'], $remaining_needed);
            if ($restore_qty <= 0) {
                continue;
            }

            $batch_id = $cand['batch_id'];
            $alloc_id = $cand['allocation_id'];

            // Lock and verify current batch state
            $batch_stmt->execute([$batch_id]);
            $batch = $batch_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$batch) {
                throw new FifoValidationException("Batch #{$batch_id} associated with allocation #{$alloc_id} does not exist.");
            }

            // Verify the batch being restored belongs to the verified inventory item and branch
            if ((int) $batch['item_id'] !== $item_id) {
                throw new FifoAnomalyException(
                    "Batch #{$batch_id} item mismatch: batch belongs to item #{$batch['item_id']}, expected item #{$item_id}."
                );
            }

            if ((int) $batch['branch_id'] !== $branch_id) {
                throw new FifoAnomalyException(
                    "Batch #{$batch_id} branch mismatch: batch belongs to branch #{$batch['branch_id']}, expected branch #{$branch_id}."
                );
            }

            $cur_remaining = (int) $batch['remaining_quantity'];
            $cur_status = (string) $batch['status'];
            $initial_qty = (int) $batch['initial_quantity'];

            // Invariant: batch status must be active or exhausted
            if (!in_array($cur_status, ['active', 'exhausted'], true)) {
                throw new FifoValidationException(
                    "Batch #{$batch_id} ({$batch['batch_code']}) has invalid status '{$cur_status}' and cannot receive restored stock."
                );
            }

            // Invariant: restoration cannot exceed initial batch quantity
            $new_remaining = $cur_remaining + $restore_qty;
            if ($new_remaining > $initial_qty) {
                throw new FifoValidationException(
                    "Restoring {$restore_qty} unit(s) to batch #{$batch_id} ({$batch['batch_code']}) would result in remaining quantity " .
                    "{$new_remaining}, exceeding initial quantity {$initial_qty}."
                );
            }

            // Concurrency-guarded optimistic update with rowCount() verification
            $update_batch_stmt->execute([
                $new_remaining,
                $batch_id,
                $cur_remaining,
                $cur_status,
            ]);

            if ($update_batch_stmt->rowCount() !== 1) {
                throw new FifoConcurrencyException(
                    "Concurrency conflict on batch #{$batch_id} ({$batch['batch_code']}). " .
                    "Expected remaining {$cur_remaining} and status '{$cur_status}', but row was modified concurrently."
                );
            }

            // Insert reversal allocation line
            $alloc_note = $notes !== null ? substr($notes, 0, 255) : "Reversal of allocation #{$alloc_id}";
            $insert_alloc_stmt->execute([
                $reversal_transaction_id,
                $batch_id,
                $cand['item_id'],
                $cand['branch_id'],
                $restore_qty,
                $alloc_id,
                $alloc_note,
            ]);

            $reversal_alloc_id = (int) $pdo->lastInsertId();

            $created_reversals[] = [
                'reversal_allocation_id' => $reversal_alloc_id,
                'reversal_transaction_id' => $reversal_transaction_id,
                'original_transaction_id' => $original_stock_out_transaction_id,
                'reversal_of_id' => $alloc_id,
                'batch_id' => $batch_id,
                'batch_code' => $batch['batch_code'],
                'item_id' => $cand['item_id'],
                'branch_id' => $cand['branch_id'],
                'restored_quantity' => $restore_qty,
                'batch_remaining_before' => $cur_remaining,
                'batch_remaining_after' => $new_remaining,
                'batch_status_before' => $cur_status,
                'batch_status_after' => 'active',
            ];

            $remaining_needed -= $restore_qty;
        }

        // Step 10: Invariant Checks
        if ($remaining_needed !== 0) {
            throw new FifoAnomalyException(
                "FIFO reversal failed invariant check. Unrestored remainder: {$remaining_needed}."
            );
        }

        $total_restored = array_sum(array_column($created_reversals, 'restored_quantity'));
        if ($total_restored !== $target_reversal_quantity) {
            throw new FifoAnomalyException(
                "FIFO reversal total mismatch: restored {$total_restored} unit(s), expected {$target_reversal_quantity} unit(s)."
            );
        }

        // Caller owns transaction commit; do not independently commit
        return $created_reversals;
    }
}

// ============================================================================
// Batch Creation Helper (For Opening Balances and Inbound Stock)
// ============================================================================

if (!function_exists('fifo_generate_batch_code')) {
    /**
     * Generates a deterministic, collision-resistant unique batch code.
     * Format: BAT-{branch_id}-{item_id}-{YYYYMMDD}-{source_code}-{source_id}-{seq}-{rand6}
     * Maximum length is ~36 characters, well within varchar(64).
     *
     * @param int $branch_id
     * @param int $item_id
     * @param string $source_type
     * @param int $source_id
     * @param int $source_sequence
     * @param string|null $date Y-m-d format, defaults to today
     * @return string
     */
    function fifo_generate_batch_code(
        int $branch_id,
        int $item_id,
        string $source_type,
        int $source_id,
        int $source_sequence = 1,
        ?string $date = null
    ): string {
        $date_part = $date ? date('Ymd', strtotime($date)) : date('Ymd');

        $type_map = [
            'legacy_opening' => 'LEG',
            'manual_stock_in' => 'MIN',
            'incoming_stock' => 'INC',
            'transfer_receipt' => 'TRF',
            'adjustment_positive' => 'ADJ',
        ];
        $source_code = $type_map[$source_type] ?? 'BAT';

        $rand_hex = strtoupper(bin2hex(random_bytes(3))); // 6 hex characters

        return sprintf(
            "BAT-%d-%d-%s-%s-%d-%d-%s",
            $branch_id,
            $item_id,
            $date_part,
            $source_code,
            $source_id,
            $source_sequence,
            $rand_hex
        );
    }
}

if (!function_exists('fifo_create_batch')) {
    /**
     * Creates a new inventory batch record with full runtime validation.
     *
     * STRICT TRANSACTION OWNERSHIP CONTRACT:
     * - Requires $pdo->inTransaction() to be TRUE before execution.
     * - If no transaction exists, throws FifoTransactionException.
     * - Does NOT call beginTransaction(), commit(), or rollBack().
     *
     * ITEM AND BRANCH OWNERSHIP CONTRACT:
     * - Validates against inventory_items that item exists and belongs to branch_id.
     * - Strictly prevents accidental cross-branch batch creation.
     * - Verifies that the inventory item is active.
     *
     * IDEMPOTENCY & UNIQUENESS SAFEGUARDS:
     * - Enforces UNIQUE KEY uq_batch_source (source_type, source_id, source_sequence).
     * - Checks for prior existence before insertion to prevent duplicate stock creation.
     * - Enforces collision checks on batch_code (uq_batch_code).
     *
     * SOURCE DIFFERENTIATION & RUNTIME CHECK INVARIANTS:
     * - Enforces initial_quantity > 0 within 32-bit INT range and remaining_quantity == initial_quantity.
     * - Distinguishes source types:
     *   - 'legacy_opening': sets is_legacy = 1, populates converted_at, source_id must equal item_id.
     *   - 'manual_stock_in'|'incoming_stock'|'transfer_receipt'|'adjustment_positive':
     *     sets is_legacy = 0, converted_at = null, populates received_at.
     * - Initial status is strictly 'active'.
     *
     * @param PDO $pdo
     * @param array $batch_data [
     *     'item_id' => int,
     *     'branch_id' => int,
     *     'source_type' => string ('legacy_opening'|'manual_stock_in'|'incoming_stock'|'transfer_receipt'|'adjustment_positive'),
     *     'source_id' => int,
     *     'source_sequence' => int (optional, default 1),
     *     'source_ref' => string|null (optional),
     *     'initial_quantity' => int,
     *     'received_at' => string|null (Y-m-d H:i:s, optional),
     *     'converted_at' => string|null (Y-m-d H:i:s, optional),
     *     'batch_code' => string|null (optional, auto-generated if omitted),
     * ]
     * @return array Created batch row
     * @throws FifoTransactionException
     * @throws FifoValidationException
     * @throws FifoDuplicateBatchException
     * @throws FifoConcurrencyException
     * @throws Exception
     */
    function fifo_create_batch(PDO $pdo, array $batch_data): array {
        // Step 1: Strict Transaction Ownership Check
        if (!$pdo->inTransaction()) {
            throw new FifoTransactionException(
                "A database transaction must be active before calling fifo_create_batch(). " .
                "The caller must open and own the transaction to guarantee atomicity."
            );
        }

        // Step 2: Validate Parameters with 32-bit INT bounds
        $item_id = (int) ($batch_data['item_id'] ?? 0);
        $branch_id = (int) ($batch_data['branch_id'] ?? 0);
        $source_type = (string) ($batch_data['source_type'] ?? '');
        $source_id = (int) ($batch_data['source_id'] ?? 0);
        $source_sequence = (int) ($batch_data['source_sequence'] ?? 1);
        $source_ref = !empty($batch_data['source_ref']) ? substr((string) $batch_data['source_ref'], 0, 100) : null;
        $initial_quantity = fifo_validate_positive_integer($batch_data['initial_quantity'] ?? 0, 'Initial quantity');

        if ($item_id <= 0 || $branch_id <= 0) {
            throw new FifoValidationException("Invalid item_id ({$item_id}) or branch_id ({$branch_id}).");
        }

        if ($source_id <= 0) {
            throw new FifoValidationException("Invalid source_id ({$source_id}) for batch creation.");
        }

        if ($source_sequence <= 0) {
            $source_sequence = 1;
        }

        $allowed_sources = [
            'legacy_opening',
            'manual_stock_in',
            'incoming_stock',
            'transfer_receipt',
            'adjustment_positive',
        ];
        if (!in_array($source_type, $allowed_sources, true)) {
            throw new FifoValidationException("Invalid source_type '{$source_type}'. Allowed: " . implode(', ', $allowed_sources));
        }

        // Step 3: Database-Backed Item and Branch Ownership Validation
        // Establishes that the item actually exists and belongs to the supplied branch within caller transaction
        $item_check_stmt = $pdo->prepare("
            SELECT id, branch_id, status, item_name
            FROM inventory_items
            WHERE id = ?
            FOR UPDATE
        ");
        $item_check_stmt->execute([$item_id]);
        $item_row = $item_check_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item_row) {
            throw new FifoValidationException("Inventory item #{$item_id} does not exist in inventory_items.");
        }

        if ((int) $item_row['branch_id'] !== $branch_id) {
            throw new FifoValidationException(
                "Branch ownership mismatch: Inventory item #{$item_id} ('{$item_row['item_name']}') belongs to branch #{$item_row['branch_id']}, " .
                "not branch #{$branch_id}. Cross-branch batch creation is strictly prohibited."
            );
        }

        if (($item_row['status'] ?? '') !== 'active') {
            throw new FifoValidationException(
                "Inventory item #{$item_id} is not active (status: '{$item_row['status']}'). Cannot create batch for non-active item."
            );
        }

        // Step 4: Distinguish Source Types and Assign Invariants
        if ($source_type === 'legacy_opening') {
            if ($source_id !== $item_id) {
                throw new FifoValidationException(
                    "For legacy opening batches, source_id ({$source_id}) must match item_id ({$item_id})."
                );
            }
            $is_legacy = 1;
            $converted_at = !empty($batch_data['converted_at']) ? $batch_data['converted_at'] : date('Y-m-d H:i:s');
            $received_at = !empty($batch_data['received_at']) ? $batch_data['received_at'] : null;
        } else {
            $is_legacy = 0;
            $converted_at = null;
            $received_at = !empty($batch_data['received_at']) ? $batch_data['received_at'] : date('Y-m-d H:i:s');
        }

        // Step 5: Idempotency Check — Prevent Duplicate Source Processing
        $dup_check = $pdo->prepare("
            SELECT id, batch_code, remaining_quantity, initial_quantity, status
            FROM inventory_batches
            WHERE source_type = ?
              AND source_id = ?
              AND source_sequence = ?
            LIMIT 1
        ");
        $dup_check->execute([$source_type, $source_id, $source_sequence]);
        $existing = $dup_check->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            throw new FifoDuplicateBatchException(
                "Duplicate batch creation prevented: A batch already exists for source '{$source_type}', " .
                "source ID {$source_id}, sequence {$source_sequence} (Batch #{$existing['id']}, code: {$existing['batch_code']})."
            );
        }

        // Step 6: Batch Code Collision Guard
        $batch_code = !empty($batch_data['batch_code'])
            ? trim((string) $batch_data['batch_code'])
            : fifo_generate_batch_code($branch_id, $item_id, $source_type, $source_id, $source_sequence, $received_at ?: date('Y-m-d'));

        $code_check = $pdo->prepare("SELECT id FROM inventory_batches WHERE batch_code = ? LIMIT 1");
        $code_check->execute([$batch_code]);
        if ($code_check->fetch()) {
            if (!empty($batch_data['batch_code'])) {
                throw new FifoConcurrencyException("Supplied batch code '{$batch_code}' already exists.");
            }

            // Retry auto-generation with fresh entropy
            $resolved = false;
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $batch_code = fifo_generate_batch_code($branch_id, $item_id, $source_type, $source_id, $source_sequence, $received_at ?: date('Y-m-d'));
                $code_check->execute([$batch_code]);
                if (!$code_check->fetch()) {
                    $resolved = true;
                    break;
                }
            }
            if (!$resolved) {
                throw new FifoConcurrencyException("Failed to generate a collision-free batch code after multiple attempts.");
            }
        }

        // Step 7: Insert Batch Record with status 'active' and remaining == initial
        $stmt = $pdo->prepare("
            INSERT INTO inventory_batches (
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
                status
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active'
            )
        ");

        $stmt->execute([
            $item_id,
            $branch_id,
            $batch_code,
            $source_type,
            $source_id,
            $source_sequence,
            $source_ref,
            $initial_quantity,
            $initial_quantity, // Runtime check invariant: remaining == initial at creation
            $received_at,
            $converted_at,
            $is_legacy,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new FifoConcurrencyException("Failed to insert inventory batch record for item #{$item_id}.");
        }

        $batch_id = (int) $pdo->lastInsertId();

        // Caller owns transaction commit; do not independently commit
        return [
            'id' => $batch_id,
            'item_id' => $item_id,
            'branch_id' => $branch_id,
            'batch_code' => $batch_code,
            'source_type' => $source_type,
            'source_id' => $source_id,
            'source_sequence' => $source_sequence,
            'source_ref' => $source_ref,
            'initial_quantity' => $initial_quantity,
            'remaining_quantity' => $initial_quantity,
            'received_at' => $received_at,
            'converted_at' => $converted_at,
            'is_legacy' => $is_legacy,
            'status' => 'active',
        ];
    }
}

// ============================================================================
// Query & Audit Helpers
// ============================================================================

if (!function_exists('fifo_get_allocations_by_transaction')) {
    /**
     * Retrieves all allocation records linked to a specific parent transaction.
     *
     * @param PDO $pdo
     * @param int $transaction_id
     * @return array
     */
    function fifo_get_allocations_by_transaction(PDO $pdo, int $transaction_id): array {
        $stmt = $pdo->prepare("
            SELECT
                a.id AS allocation_id,
                a.transaction_id,
                a.batch_id,
                b.batch_code,
                b.is_legacy,
                a.item_id,
                a.branch_id,
                a.alloc_type,
                a.allocated_quantity,
                a.allocated_at,
                a.reversal_of_id,
                a.notes
            FROM inventory_batch_allocations a
            INNER JOIN inventory_batches b ON b.id = a.batch_id
            WHERE a.transaction_id = ?
            ORDER BY a.id ASC
        ");
        $stmt->execute([$transaction_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('fifo_get_batch_details')) {
    /**
     * Retrieves complete details of a single inventory batch.
     *
     * @param PDO $pdo
     * @param int $batch_id
     * @return array|null
     */
    function fifo_get_batch_details(PDO $pdo, int $batch_id): ?array {
        $stmt = $pdo->prepare("
            SELECT *
            FROM inventory_batches
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$batch_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
