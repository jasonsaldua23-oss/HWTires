<?php
/**
 * Service Status API Handler
 * Handles status updates and transitions
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/job-order-inventory.php';
require_once __DIR__ . '/../includes/job-order-progress.php';
session_name(SESSION_NAME);
session_start();

// Check authentication
if (!is_logged_in()) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$user = app_get_session_user();
$action = $_POST['action'] ?? $_GET['action'] ?? null;

if (!function_exists('service_status_sync_completed_job_records')) {
    function service_status_sync_completed_job_records(PDO $pdo, $job_order_id, array $user) {
        $sms_result = null;

        try {
            $details_stmt = $pdo->prepare("
                SELECT jo.*, q.total_amount AS quotation_total, v.last_mileage
                FROM job_orders jo
                LEFT JOIN quotations q ON q.id = jo.quotation_id
                LEFT JOIN vehicles v ON v.id = jo.vehicle_id
                WHERE jo.id = ?
            ");
            $details_stmt->execute([$job_order_id]);
            $completed_job = $details_stmt->fetch();

            if (!$completed_job) {
                throw new Exception('Completed job order not found.');
            }

            $service_names = [];
            if (!empty($completed_job['quotation_id'])) {
                $items_stmt = $pdo->prepare("SELECT item_name FROM quotation_items WHERE quotation_id = ? ORDER BY id ASC");
                $items_stmt->execute([(int) $completed_job['quotation_id']]);
                $service_names = array_column($items_stmt->fetchAll(), 'item_name');
            }

            $services_description = !empty($service_names) ? implode(', ', $service_names) : 'Service completed';
            $history_date = !empty($completed_job['job_date']) ? $completed_job['job_date'] : date('Y-m-d');
            $total_cost = (float) ($completed_job['quotation_total'] ?? 0);
            $mileage = !empty($completed_job['last_mileage']) ? (int) $completed_job['last_mileage'] : null;
            $history_notes = app_compose_record_notes(
                $user['name'] ?? 'Front Desk',
                $completed_job['assigned_technician_name'] ?? '',
                $completed_job['notes'] ?? ''
            );

            $history_stmt = $pdo->prepare("SELECT id FROM service_history WHERE job_order_id = ? LIMIT 1");
            $history_stmt->execute([$job_order_id]);
            $history_id = $history_stmt->fetchColumn();

            if ($history_id) {
                $save_history = $pdo->prepare("
                    UPDATE service_history
                    SET customer_id = ?, vehicle_id = ?, branch_id = ?, service_date = ?,
                        services_description = ?, total_cost = ?, mileage_at_service = ?,
                        quotation_id = ?, notes = ?
                    WHERE id = ?
                ");
                $save_history->execute([
                    (int) $completed_job['customer_id'],
                    !empty($completed_job['vehicle_id']) ? (int) $completed_job['vehicle_id'] : null,
                    (int) $completed_job['branch_id'],
                    $history_date,
                    $services_description,
                    $total_cost,
                    $mileage,
                    !empty($completed_job['quotation_id']) ? (int) $completed_job['quotation_id'] : null,
                    $history_notes,
                    (int) $history_id,
                ]);
            } else {
                $save_history = $pdo->prepare("
                    INSERT INTO service_history (
                        customer_id, vehicle_id, branch_id, service_date, services_description,
                        total_cost, mileage_at_service, job_order_id, quotation_id, notes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $save_history->execute([
                    (int) $completed_job['customer_id'],
                    !empty($completed_job['vehicle_id']) ? (int) $completed_job['vehicle_id'] : null,
                    (int) $completed_job['branch_id'],
                    $history_date,
                    $services_description,
                    $total_cost,
                    $mileage,
                    $job_order_id,
                    !empty($completed_job['quotation_id']) ? (int) $completed_job['quotation_id'] : null,
                    $history_notes,
                ]);
            }

            if (!empty($completed_job['vehicle_id'])) {
                $vehicle_stmt = $pdo->prepare("UPDATE vehicles SET last_service_date = ? WHERE id = ?");
                $vehicle_stmt->execute([$history_date, (int) $completed_job['vehicle_id']]);
            }

            app_touch_customer_branch_record($completed_job['customer_id'], $completed_job['branch_id'], $user['id'] ?? null);
        } catch (Exception $history_error) {
            error_log('Service history sync error: ' . $history_error->getMessage());
        }

        try {
            $sms_result = sms_queue_completed_job_pickup($pdo, $job_order_id, $user['id'] ?? null);
        } catch (Exception $sms_error) {
            error_log('Pickup SMS queue error: ' . $sms_error->getMessage());
            $sms_result = [
                'queued' => false,
                'status' => 'failed',
                'message' => 'Pickup SMS could not be queued.',
            ];
        }

        return $sms_result;
    }
}

if (!function_exists('service_status_reopen_completed_job_records')) {
    function service_status_reopen_completed_job_records(PDO $pdo, $job_order_id) {
        $job_order_id = (int) $job_order_id;
        if ($job_order_id <= 0) {
            return;
        }

        try {
            $history_stmt = $pdo->prepare("DELETE FROM service_history WHERE job_order_id = ?");
            $history_stmt->execute([$job_order_id]);
        } catch (Exception $history_error) {
            error_log('Service history reopen cleanup error: ' . $history_error->getMessage());
        }

        try {
            $sms_stmt = $pdo->prepare("
                UPDATE sms_outbox
                SET status = 'cancelled',
                    error_message = 'Cancelled because the job progress was reopened.',
                    updated_at = NOW()
                WHERE job_order_id = ?
                  AND status IN ('queued', 'failed')
            ");
            $sms_stmt->execute([$job_order_id]);
        } catch (Exception $sms_error) {
            error_log('Pickup SMS reopen cleanup error: ' . $sms_error->getMessage());
        }
    }
}

if (!function_exists('service_status_apply_job_status')) {
    function service_status_apply_job_status(PDO $pdo, $job_order_id, $status, array $user, array $options = []) {
        $job_order_id = (int) $job_order_id;
        $status = trim((string) $status);

        if ($job_order_id <= 0) {
            throw new Exception('Invalid job order ID');
        }

        // Job/service progress cannot be rejected or cancelled; those outcomes belong to quotations.
        $valid_statuses = ['waiting', 'in-progress', 'completed'];
        if (!in_array($status, $valid_statuses, true)) {
            throw new Exception('Invalid status');
        }

        $stmt = $pdo->prepare("SELECT * FROM job_orders WHERE id = ?");
        $stmt->execute([$job_order_id]);
        $job_order = $stmt->fetch();

        if (!$job_order) {
            throw new Exception('Job order not found');
        }

        if (!has_branch_access($job_order['branch_id'])) {
            throw new Exception('Unauthorized access');
        }

        $was_completed = ($job_order['status'] ?? '') === 'completed';
        $allow_completed_reopen = !empty($options['allow_completed_reopen']);

        if ($was_completed && $status !== 'completed' && !$allow_completed_reopen) {
            throw new Exception('Completed job orders cannot be moved back from this screen.');
        }

        $skip_inventory_consumption = !empty($options['skip_inventory_consumption']);
        if (in_array($status, ['in-progress', 'completed'], true) && !$skip_inventory_consumption) {
            job_order_apply_inventory_consumption($pdo, $job_order_id, $user['id'] ?? null);
        }

        if ($status === 'completed') {
            job_progress_sync($pdo, $job_order_id);
            $mark_done = $pdo->prepare("
                UPDATE job_order_progress
                SET is_done = 1,
                    completed_at = COALESCE(completed_at, NOW()),
                    updated_by = COALESCE(updated_by, ?)
                WHERE job_order_id = ?
            ");
            $mark_done->execute([$user['id'] ?? null, $job_order_id]);
        } elseif ($status === 'waiting') {
            job_progress_sync($pdo, $job_order_id);
            $mark_waiting = $pdo->prepare("
                UPDATE job_order_progress
                SET is_done = 0,
                    completed_at = NULL,
                    updated_by = ?
                WHERE job_order_id = ?
            ");
            $mark_waiting->execute([$user['id'] ?? null, $job_order_id]);
        }

        $updates = ['status = ?', 'updated_at = NOW()'];
        $update_params = [$status];

        if ($status === 'in-progress' && job_orders_column_exists('actual_start_time')) {
            $updates[] = 'actual_start_time = COALESCE(actual_start_time, NOW())';
        }

        if ($was_completed && $status !== 'completed' && $allow_completed_reopen) {
            if (job_orders_column_exists('actual_end_time')) {
                $updates[] = 'actual_end_time = NULL';
            }
        }

        if ($status === 'completed') {
            if (job_orders_column_exists('actual_start_time')) {
                $updates[] = 'actual_start_time = COALESCE(actual_start_time, NOW())';
            }

            if (job_orders_column_exists('actual_end_time')) {
                $updates[] = 'actual_end_time = COALESCE(actual_end_time, NOW())';
            }
        }

        $update_params[] = $job_order_id;
        $update_stmt = $pdo->prepare('UPDATE job_orders SET ' . implode(', ', $updates) . ' WHERE id = ?');
        $update_stmt->execute($update_params);

        $sms_result = null;
        if ($status === 'completed') {
            $sms_result = service_status_sync_completed_job_records($pdo, $job_order_id, $user);
        } elseif ($was_completed && $allow_completed_reopen) {
            service_status_reopen_completed_job_records($pdo, $job_order_id);
        }

        log_audit('job_orders', 'status_update', $job_order_id,
            ['status' => $job_order['status']],
            ['status' => $status]
        );

        return [
            'job_order' => $job_order,
            'status' => $status,
            'sms_result' => $sms_result,
        ];
    }
}

// Handle Update Job Status
if ($action === 'update_job_status') {
    try {
        enforce_modify_permission();

        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        $job_order_id = intval($_POST['job_order_id'] ?? 0);
        $status = trim($_POST['status'] ?? '');

        if ($job_order_id <= 0) {
            throw new Exception('Invalid job order ID');
        }

        $result = service_status_apply_job_status($pdo, $job_order_id, $status, $user);
        $sms_result = $result['sms_result'] ?? null;

        $flash_message = 'Job status updated successfully';
        if ($status === 'completed' && is_array($sms_result) && !empty($sms_result['message'])) {
            $flash_message .= '. ' . $sms_result['message'];
        }

        set_flash_message($flash_message, 'success');
        http_response_code(200);

        if (!empty($_POST['redirect'])) {
            redirect($_POST['redirect']);
        }

        die(json_encode(['success' => true, 'message' => 'Job status updated successfully']));

    } catch (Exception $e) {
        error_log('Update job status error: ' . $e->getMessage());
        set_flash_message($e->getMessage(), 'error');

        if (!empty($_POST['redirect'])) {
            redirect($_POST['redirect']);
        }

        http_response_code(400);
        die(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
}

// Handle task-level progress update
if ($action === 'update_job_progress') {
    header('Content-Type: application/json');

    try {
        enforce_modify_permission();

        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        $job_order_id = (int) ($_POST['job_order_id'] ?? 0);
        $task_key = trim((string) ($_POST['task_key'] ?? ''));
        $is_done = (int) ($_POST['is_done'] ?? 0) === 1;

        if ($job_order_id <= 0 || $task_key === '') {
            throw new Exception('Invalid progress update.');
        }

        $job_stmt = $pdo->prepare("SELECT * FROM job_orders WHERE id = ? LIMIT 1");
        $job_stmt->execute([$job_order_id]);
        $job_order = $job_stmt->fetch();
        if (!$job_order) {
            throw new Exception('Job order not found.');
        }

        if (!has_branch_access($job_order['branch_id'])) {
            throw new Exception('Unauthorized access');
        }

        $was_completed_for_progress = ($job_order['status'] ?? '') === 'completed';
        $transfer_states = job_progress_get_transfer_states_for_job($pdo, $job_order_id);
        $task_state = $transfer_states[$task_key] ?? null;
        if ($is_done && is_array($task_state) && empty($task_state['is_ready'])) {
            throw new Exception($task_state['message'] ?: 'This item has not been sent from the branch yet.');
        }

        $inventory_result = null;
        if ($is_done) {
            $inventory_result = job_order_apply_inventory_consumption_for_task($pdo, $job_order_id, $task_key, $user['id'] ?? null);
        }

        $summary = job_progress_update_task($pdo, $job_order_id, $task_key, $is_done, $user['id'] ?? null);
        $recommended_status = $summary['recommended_status'];

        if ($was_completed_for_progress && !$is_done && $recommended_status !== 'completed') {
            $recommended_status = 'in-progress';
        }

        $status_result = service_status_apply_job_status($pdo, $job_order_id, $recommended_status, $user, [
            'allow_completed_reopen' => $was_completed_for_progress && !$is_done,
            'skip_inventory_consumption' => true,
        ]);

        $summary = ($was_completed_for_progress && !$is_done)
            ? job_progress_get_for_job_unsynced($pdo, $job_order_id)
            : job_progress_get_for_job($pdo, $job_order_id);

        $summary['status'] = $status_result['status'];
        $summary['status_label'] = $status_result['status'] === 'in-progress'
            ? 'In Progress'
            : ucwords(str_replace('-', ' ', $status_result['status']));

        http_response_code(200);
        die(json_encode([
            'success' => true,
            'message' => 'Job progress updated.',
            'data' => $summary,
            'inventory' => $inventory_result,
            'sms' => $status_result['sms_result'] ?? null,
        ]));
    } catch (Exception $e) {
        error_log('Update job progress error: ' . $e->getMessage());
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
}

// Handle Get Status Summary
if ($action === 'get_summary') {
    try {
        $branch_id = intval($_GET['branch_id'] ?? $user['branch_id']);
        if ($branch_id <= 0) {
            throw new Exception('Invalid branch');
        }

        if (!has_branch_access($branch_id)) {
            throw new Exception('Unauthorized access to this branch');
        }

        // Get job counts by status
        $query = "
            SELECT CASE WHEN status = 'pending' THEN 'waiting' ELSE status END AS status, COUNT(*) as count
            FROM job_orders
            WHERE branch_id = ?
              AND status IN ('waiting', 'pending', 'in-progress', 'completed')
            GROUP BY CASE WHEN status = 'pending' THEN 'waiting' ELSE status END
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$branch_id]);
        $summary = [];

        foreach ($stmt->fetchAll() as $row) {
            $summary[$row['status']] = $row['count'];
        }

        http_response_code(200);
        die(json_encode(['success' => true, 'data' => $summary]));

    } catch (Exception $e) {
        error_log('Get summary error: ' . $e->getMessage());
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
}

// Invalid action
http_response_code(400);
die(json_encode(['success' => false, 'message' => 'Invalid action']));
?>
