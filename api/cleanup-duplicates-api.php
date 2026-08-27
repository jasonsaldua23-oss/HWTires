<?php
/**
 * Customer Duplicate Cleanup API
 * Handles merging of duplicate customer records
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
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Admin access required']));
}

$action = $_POST['action'] ?? null;

// Handle Merge Action
if ($action === 'merge') {
    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        $customer_ids_json = $_POST['customer_ids'] ?? '[]';
        $customer_ids = json_decode($customer_ids_json, true);

        if (!is_array($customer_ids) || count($customer_ids) < 2) {
            throw new Exception('Invalid customer ID list');
        }

        // Get the selected primary customer ID from POST
        $primary_customer_id = null;
        foreach ($customer_ids as $id) {
            if (isset($_POST['primary_customer_id']) && (int)$_POST['primary_customer_id'] == (int)$id) {
                $primary_customer_id = (int) $id;
                break;
            }
        }

        // If primary not explicitly set, use the first one (safest option)
        if (!$primary_customer_id) {
            $primary_customer_id = (int) reset($customer_ids);
        }

        // Get duplicate IDs (all except primary)
        $duplicate_ids = [];
        foreach ($customer_ids as $id) {
            if ((int)$id !== $primary_customer_id) {
                $duplicate_ids[] = (int)$id;
            }
        }

        if (empty($duplicate_ids)) {
            throw new Exception('No duplicate records to merge');
        }

        // Verify all customers exist
        $all_ids = array_merge([$primary_customer_id], $duplicate_ids);
        $placeholders = implode(',', array_fill(0, count($all_ids), '?'));
        $verify_stmt = $pdo->prepare("SELECT id FROM customers WHERE id IN ($placeholders)");
        $verify_stmt->execute($all_ids);
        if ($verify_stmt->rowCount() !== count($all_ids)) {
            throw new Exception('Some customer records not found');
        }

        // Start transaction
        $pdo->beginTransaction();

        $stats = [
            'primary_id' => $primary_customer_id,
            'duplicates_deleted' => 0,
            'quotations_moved' => 0,
            'job_orders_moved' => 0,
            'service_histories_moved' => 0,
            'vehicles_moved' => 0,
            'customer_visits_moved' => 0,
        ];

        // Loop through each duplicate and move all their records to primary
        foreach ($duplicate_ids as $dup_id) {
            // 1. Move quotations
            $stmt = $pdo->prepare("UPDATE quotations SET customer_id = ? WHERE customer_id = ?");
            $stmt->execute([$primary_customer_id, $dup_id]);
            $stats['quotations_moved'] += $stmt->rowCount();

            // 2. Move job orders
            $stmt = $pdo->prepare("UPDATE job_orders SET customer_id = ? WHERE customer_id = ?");
            $stmt->execute([$primary_customer_id, $dup_id]);
            $stats['job_orders_moved'] += $stmt->rowCount();

            // 3. Move service history
            $stmt = $pdo->prepare("UPDATE service_history SET customer_id = ? WHERE customer_id = ?");
            $stmt->execute([$primary_customer_id, $dup_id]);
            $stats['service_histories_moved'] += $stmt->rowCount();

            // 4. Move vehicles
            $stmt = $pdo->prepare("UPDATE vehicles SET customer_id = ? WHERE customer_id = ?");
            $stmt->execute([$primary_customer_id, $dup_id]);
            $stats['vehicles_moved'] += $stmt->rowCount();

            // 5. Move customer visits
            $stmt = $pdo->prepare("UPDATE customer_visits SET customer_id = ? WHERE customer_id = ?");
            $stmt->execute([$primary_customer_id, $dup_id]);
            $stats['customer_visits_moved'] += $stmt->rowCount();

            // 6. Handle customer_branch_records
            // Get all branches from this duplicate
            $branch_stmt = $pdo->prepare("SELECT branch_id FROM customer_branch_records WHERE customer_id = ?");
            $branch_stmt->execute([$dup_id]);
            $dup_branches = array_column($branch_stmt->fetchAll(), 'branch_id');

            foreach ($dup_branches as $branch_id) {
                // Check if primary already has a record for this branch
                $check_stmt = $pdo->prepare("SELECT id FROM customer_branch_records WHERE customer_id = ? AND branch_id = ?");
                $check_stmt->execute([$primary_customer_id, $branch_id]);

                if (!$check_stmt->fetch()) {
                    // Move this branch record to primary
                    $move_stmt = $pdo->prepare("UPDATE customer_branch_records SET customer_id = ? WHERE customer_id = ? AND branch_id = ? LIMIT 1");
                    $move_stmt->execute([$primary_customer_id, $dup_id, $branch_id]);
                }
            }

            // Delete any remaining customer_branch_records for this duplicate
            $del_cbr = $pdo->prepare("DELETE FROM customer_branch_records WHERE customer_id = ?");
            $del_cbr->execute([$dup_id]);

            // 7. Finally delete the duplicate customer
            $del_stmt = $pdo->prepare("DELETE FROM customers WHERE id = ?");
            $del_stmt->execute([$dup_id]);
            $stats['duplicates_deleted']++;
        }

        // Commit transaction
        $pdo->commit();

        // Log audit
        log_audit('customers', 'merge_duplicates', $primary_customer_id, null, [
            'primary_id' => $primary_customer_id,
            'merged_ids' => $duplicate_ids,
            'stats' => $stats
        ]);

        $total_moved = $stats['quotations_moved'] + $stats['job_orders_moved'] + $stats['service_histories_moved'];
        $success_message = 'Duplicates merged successfully! ' .
            $stats['duplicates_deleted'] . ' record(s) deleted, ' .
            $total_moved . ' total records reassigned.';

        set_flash_message($success_message, 'success');

        if (!empty($_POST['redirect'])) {
            redirect($_POST['redirect']);
        } else {
            redirect('/hwtires/admin/utilities/cleanup-duplicates.php');
        }

    } catch (Exception $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Merge duplicates error: ' . $e->getMessage());
        set_flash_message($e->getMessage(), 'error');

        if (!empty($_POST['redirect'])) {
            redirect($_POST['redirect']);
        }

        http_response_code(400);
        die(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
}

// Default response
http_response_code(400);
die(json_encode(['success' => false, 'message' => 'Invalid action']));
?>
