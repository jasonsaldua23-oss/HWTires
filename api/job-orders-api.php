<?php
/**
 * Job Orders API Handler
 * Handles CRUD operations for job orders
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/job-order-inventory.php';
session_name(SESSION_NAME);
session_start();

// Check authentication
if (!is_logged_in()) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$user = app_get_session_user();
$action = $_POST['action'] ?? $_GET['action'] ?? null;

if (!function_exists('job_order_clean_technician_names')) {
    function job_order_clean_technician_names($value) {
        if (is_array($value)) {
            $value = implode(',', array_map(static function ($item) {
                return trim((string) $item);
            }, $value));
        }

        $parts = preg_split('/[,;\r\n]+/', (string) $value);
        $parts = array_map('trim', $parts ?: []);
        $parts = array_values(array_unique(array_filter($parts, function ($part) {
            return $part !== '';
        })));

        return implode(', ', $parts);
    }
}

// Handle Create Job Order from Quotation
if ($action === 'create') {
    try {
        enforce_modify_permission();

        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        $quotation_id = intval($_POST['quotation_id'] ?? 0);
        $customer_id = intval($_POST['customer_id'] ?? 0);
        $vehicle_id = intval($_POST['vehicle_id'] ?? 0);
        $branch_id = intval($_POST['branch_id'] ?? ($user['branch_id'] ?? 0));
        $assigned_technician_name = job_order_clean_technician_names($_POST['assigned_technician_name'] ?? '');
        $status = trim($_POST['status'] ?? 'waiting');
        $job_date = trim($_POST['job_date'] ?? date('Y-m-d'));
        $scheduled_start_time = trim($_POST['scheduled_start_time'] ?? '');
        $scheduled_end_time = trim($_POST['scheduled_end_time'] ?? '');
        $scheduled_end_date = trim($_POST['scheduled_end_date'] ?? '');
        $estimated_duration = trim($_POST['estimated_duration'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        $valid_create_statuses = ['waiting', 'in-progress'];
        if (!in_array($status, $valid_create_statuses, true)) {
            $status = 'waiting';
        }

        $quotation = null;
        if ($quotation_id > 0) {
            // Fetch quotation details
            $stmt = $pdo->prepare("SELECT * FROM quotations WHERE id = ?");
            $stmt->execute([$quotation_id]);
            $quotation = $stmt->fetch();

            if (!$quotation) {
                throw new Exception('Quotation not found');
            }

            if (($quotation['status'] ?? '') !== 'approved') {
                throw new Exception('Only approved quotations can be converted to job orders');
            }

            // Check if a job order has already been created for this quotation
            $existing_job_stmt = $pdo->prepare("SELECT id, job_number FROM job_orders WHERE quotation_id = ? AND status <> 'cancelled' LIMIT 1");
            $existing_job_stmt->execute([$quotation_id]);
            $existing_job = $existing_job_stmt->fetch();
            if ($existing_job) {
                throw new Exception('A job order (' . ($existing_job['job_number'] ?? '#' . $existing_job['id']) . ') has already been created for this service operation.');
            }

            // Check authorization (admin or branch manager)
            if (!has_branch_access($quotation['branch_id'])) {
                throw new Exception('Unauthorized access to this quotation');
            }

            $customer_id = intval($quotation['customer_id']);
            $vehicle_id = intval($quotation['vehicle_id'] ?? 0);
            $branch_id = intval($quotation['branch_id']);

            if (empty($notes)) {
                $notes = trim($quotation['notes'] ?? '');
            }
        }

        $notes = app_compose_record_notes($user['name'] ?? 'Front Desk', $assigned_technician_name, $notes);

        if ($customer_id <= 0) {
            throw new Exception('Customer is required');
        }

        if ($branch_id <= 0) {
            throw new Exception('Branch is required');
        }

        if ($scheduled_end_date === '' && $job_date !== '') {
            $scheduled_end_date = $job_date;
        }

        if ($scheduled_end_date !== '' && $job_date !== '' && $scheduled_end_date < $job_date) {
            throw new Exception('Expected completion date cannot be earlier than the job date');
        }

        if (!has_branch_access($branch_id)) {
            throw new Exception('Unauthorized access to this branch');
        }

        // Generate job number
        $date_prefix = date('Ymd');
        $last_job = $pdo->query("SELECT job_number FROM job_orders WHERE job_number LIKE 'JO-$date_prefix-%' ORDER BY id DESC LIMIT 1")->fetch();
        $next_num = $last_job ? intval(substr($last_job['job_number'], -4)) + 1 : 1;
        $job_number = "JO-$date_prefix-" . str_pad($next_num, 4, '0', STR_PAD_LEFT);

        $fields = [
            'quotation_id' => $quotation_id ?: null,
            'customer_id' => $customer_id,
            'vehicle_id' => $vehicle_id ?: null,
            'branch_id' => $branch_id,
            'job_number' => $job_number,
            'assigned_technician_name' => $assigned_technician_name !== '' ? $assigned_technician_name : null,
            'status' => $status,
            'notes' => $notes,
            'created_by' => $user['id'],
        ];

        if (job_orders_column_exists('job_date')) {
            $fields['job_date'] = $job_date ?: date('Y-m-d');
        }

        if (job_orders_column_exists('scheduled_start_time')) {
            $fields['scheduled_start_time'] = $scheduled_start_time !== '' ? $scheduled_start_time : null;
        }

        if (job_orders_column_exists('scheduled_end_time')) {
            $fields['scheduled_end_time'] = $scheduled_end_time !== '' ? $scheduled_end_time : null;
        }

        if (job_orders_column_exists('scheduled_end_date')) {
            $fields['scheduled_end_date'] = $scheduled_end_date !== '' ? $scheduled_end_date : null;
        }

        if (job_orders_column_exists('estimated_duration')) {
            $fields['estimated_duration'] = $estimated_duration !== '' ? $estimated_duration : null;
        }

        $fields['created_at'] = 'NOW()';
        $fields['updated_at'] = 'NOW()';

        $columns = [];
        $placeholders = [];
        $values = [];

        foreach ($fields as $column => $value) {
            $columns[] = $column;

            if ($value === 'NOW()') {
                $placeholders[] = 'NOW()';
            } else {
                $placeholders[] = '?';
                $values[] = $value;
            }
        }

        // Create job order
        $stmt = $pdo->prepare(
            'INSERT INTO job_orders (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')'
        );

        $stmt->execute($values);

        $job_order_id = $pdo->lastInsertId();
        app_touch_customer_branch_record($customer_id, $branch_id, $user['id'] ?? null);

        if ($status === 'in-progress') {
            job_order_apply_inventory_consumption($pdo, $job_order_id, $user['id'] ?? null);
        }

        try {
            $visit_stmt = $pdo->prepare("
                INSERT INTO customer_visits (customer_id, branch_id, visit_type, notes, created_by)
                VALUES (?, ?, 'job_order', ?, ?)
            ");
            $visit_stmt->execute([$customer_id, $branch_id, 'Job order ' . $job_number, $user['id'] ?? null]);
        } catch (Exception $visit_error) {
            error_log('Create job order customer visit error: ' . $visit_error->getMessage());
        }

        // Update quotation status to 'approved'
        if ($quotation_id > 0) {
            $update_stmt = $pdo->prepare("UPDATE quotations SET status = 'approved' WHERE id = ?");
            $update_stmt->execute([$quotation_id]);
        }

        // Log audit
        log_audit('job_orders', 'create', $job_order_id, null, [
            'quotation_id' => $quotation_id,
            'job_number' => $job_number,
            'status' => $status
        ]);

        set_flash_message('Job order created successfully', 'success');
        http_response_code(200);

        if (!empty($_POST['redirect'])) {
            redirect($_POST['redirect']);
        }

        die(json_encode(['success' => true, 'message' => 'Job order created successfully', 'id' => $job_order_id]));

    } catch (Exception $e) {
        error_log('Create job order error: ' . $e->getMessage());
        set_flash_message($e->getMessage(), 'error');

        if (!empty($_POST['redirect'])) {
            redirect($_POST['redirect']);
        }

        http_response_code(400);
        die(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
}

// Handle Update Job Order Status
if ($action === 'update_status') {
    try {
        enforce_modify_permission();

        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        $job_order_id = intval($_POST['id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        $assigned_technician_name = job_order_clean_technician_names($_POST['assigned_technician_name'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($job_order_id <= 0) {
            throw new Exception('Invalid job order ID');
        }

        // Job orders only track service progress. Quotation rejection is handled in quotations.
        $valid_statuses = ['waiting', 'in-progress', 'completed'];
        if (!in_array($status, $valid_statuses)) {
            throw new Exception('Invalid status');
        }

        // Fetch current job order
        $stmt = $pdo->prepare("SELECT * FROM job_orders WHERE id = ?");
        $stmt->execute([$job_order_id]);
        $job_order = $stmt->fetch();

        if (!$job_order) {
            throw new Exception('Job order not found');
        }

        // Check authorization
        if (!has_branch_access($job_order['branch_id'])) {
            throw new Exception('Unauthorized access');
        }

        $notes = app_compose_record_notes($user['name'] ?? 'Front Desk', $assigned_technician_name ?: ($job_order['assigned_technician_name'] ?? ''), $notes);

        if ($status === 'in-progress') {
            job_order_apply_inventory_consumption($pdo, $job_order_id, $user['id'] ?? null);
        }

        // Update job order
        $stmt = $pdo->prepare("
            UPDATE job_orders
            SET status = ?, assigned_technician_name = COALESCE(NULLIF(?, ''), assigned_technician_name), notes = ?, updated_at = NOW()
            WHERE id = ?
        ");

        $stmt->execute([$status, $assigned_technician_name, $notes, $job_order_id]);

        $sms_result = null;
        if ($status === 'completed') {
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
        }

        // Log audit
        log_audit('job_orders', 'update', $job_order_id,
            ['status' => $job_order['status']],
            ['status' => $status, 'assigned_technician_name' => $assigned_technician_name]
        );

        $flash_message = 'Job order updated successfully';
        if ($status === 'completed' && is_array($sms_result) && !empty($sms_result['message'])) {
            $flash_message .= '. ' . $sms_result['message'];
        }

        set_flash_message($flash_message, 'success');
        http_response_code(200);

        if (!empty($_POST['redirect'])) {
            redirect($_POST['redirect']);
        }

        die(json_encode(['success' => true, 'message' => 'Job order updated successfully']));

    } catch (Exception $e) {
        error_log('Update job order error: ' . $e->getMessage());
        set_flash_message($e->getMessage(), 'error');

        if (!empty($_POST['redirect'])) {
            redirect($_POST['redirect']);
        }

        http_response_code(400);
        die(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
}

// Handle Archive Job Order
if ($action === 'delete' || $action === 'archive') {
    try {
        enforce_modify_permission();

        $is_post_archive = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        $csrf_token = $is_post_archive ? ($_POST['csrf_token'] ?? '') : ($_GET['csrf_token'] ?? '');
        if (!verify_csrf_token($csrf_token)) {
            throw new Exception('Invalid security token');
        }

        $job_order_id = $is_post_archive
            ? intval($_POST['id'] ?? $_POST['job_order_id'] ?? 0)
            : intval($_GET['id'] ?? $_GET['job_order_id'] ?? 0);

        if ($job_order_id <= 0) {
            throw new Exception('Invalid job order ID');
        }

        // Fetch job order
        $stmt = $pdo->prepare("SELECT * FROM job_orders WHERE id = ?");
        $stmt->execute([$job_order_id]);
        $job_order = $stmt->fetch();

        if (!$job_order) {
            throw new Exception('Job order not found');
        }

        // Check authorization
        if (!has_branch_access($job_order['branch_id'])) {
            throw new Exception('Unauthorized access');
        }

        // Only allow archiving if status is 'waiting'
        if ($job_order['status'] !== 'waiting') {
            throw new Exception('Can only archive job orders with waiting status');
        }

        $archive_set = ["status = 'archived'"];
        $archive_values = [];
        app_archive_metadata_update('job_orders', $archive_set, $archive_values, 'Job order archived');
        if (app_column_exists('job_orders', 'updated_at')) {
            $archive_set[] = 'updated_at = NOW()';
        }
        $archive_values[] = $job_order_id;

        $archive_stmt = $pdo->prepare('UPDATE job_orders SET ' . implode(', ', $archive_set) . ' WHERE id = ?');
        $archive_stmt->execute($archive_values);

        // Log audit
        log_audit('job_orders', 'archive', $job_order_id, $job_order, [
            'status' => 'archived',
            'records_preserved' => true
        ]);

        set_flash_message('Job order archived successfully', 'success');

    } catch (Exception $e) {
        error_log('Archive job order error: ' . $e->getMessage());
        set_flash_message($e->getMessage(), 'error');
    }

    redirect($_POST['redirect'] ?? $_SERVER['HTTP_REFERER'] ?? '/hwtires/admin/job-orders/');
}

// Invalid action
http_response_code(400);
die(json_encode(['success' => false, 'message' => 'Invalid action']));
?>
