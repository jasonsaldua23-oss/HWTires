<?php
/**
 * Inter-Branch Transfer Request API
 * Handles creation, approval, and management of inter-branch transfer requests
 */

if (!defined('INCLUDE_GUARD')) {
    define('INCLUDE_GUARD', true);
    require_once __DIR__ . '/../includes/config.php';
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(SESSION_NAME);
    session_start();
}

header('Content-Type: application/json');

$response = ['success' => false, 'message' => '', 'data' => null];

if (!function_exists('transfer_log_inventory_transaction')) {
    function transfer_log_inventory_transaction(PDO $pdo, array $user, $item_id, $transaction_type, $quantity, $transfer_id, $notes, array $tags = []) {
        $columns = ['item_id', 'transaction_type', 'quantity', 'reference_type', 'reference_id', 'notes', 'created_by'];
        $values = [
            $item_id,
            $transaction_type,
            $quantity,
            'inter_branch_transfer',
            $transfer_id,
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

        $stmt = $pdo->prepare("
            INSERT INTO inventory_transactions (" . implode(', ', $columns) . ")
            VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ")
        ");
        $stmt->execute($values);
    }
}

try {
    // Verify user is authenticated
    $user = app_get_session_user();
    if (!$user) {
        http_response_code(401);
        $response['message'] = 'Unauthorized';
        echo json_encode($response);
        exit;
    }

    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    switch ($action) {
        case 'check_availability':
            /**
             * Check if item is available in other branches
             * POST: item_id, requesting_branch_id, quantity_needed
             */
            $item_id = (int) ($_POST['item_id'] ?? 0);
            $requesting_branch_id = (int) ($_POST['requesting_branch_id'] ?? 0);
            $quantity_needed = (int) ($_POST['quantity_needed'] ?? 1);

            if (!$item_id || !$requesting_branch_id) {
                throw new Exception('Invalid parameters');
            }

            // Get item details from requesting branch
            $stmt = $pdo->prepare("
                SELECT ii.item_name, ii.quantity, ii.reorder_level
                FROM inventory_items ii
                WHERE ii.id = ? AND ii.branch_id = ?
            ");
            $stmt->execute([$item_id, $requesting_branch_id]);
            $requesting_item = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$requesting_item) {
                throw new Exception('Item not found in requesting branch');
            }

            // Check availability in other branches
            $stmt = $pdo->prepare("
                SELECT ii.branch_id, b.name as branch_name, ii.quantity,
                       ii.reorder_level, ii.unit_price
                FROM inventory_items ii
                JOIN branches b ON ii.branch_id = b.id
                WHERE ii.item_name = ? AND ii.branch_id != ?
                AND ii.quantity > ii.reorder_level
                AND ii.status = 'active'
                ORDER BY ii.quantity DESC
            ");
            $stmt->execute([$requesting_item['item_name'], $requesting_branch_id]);
            $available_branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $response['success'] = true;
            $response['data'] = [
                'item_id' => $item_id,
                'item_name' => $requesting_item['item_name'],
                'requesting_quantity' => $quantity_needed,
                'available_branches' => $available_branches
            ];
            break;

        case 'create_request':
            /**
             * Create inter-branch transfer request
             * POST: item_id, requesting_branch_id, donor_branch_id, quantity, quotation_id, customer_id, reason, priority
             */
            $item_id = (int) ($_POST['item_id'] ?? 0);
            $requesting_branch_id = (int) ($_POST['requesting_branch_id'] ?? 0);
            $donor_branch_id = (int) ($_POST['donor_branch_id'] ?? 0);
            $quantity = (int) ($_POST['quantity'] ?? 0);
            $quotation_id = (int) ($_POST['quotation_id'] ?? 0);
            $customer_id = (int) ($_POST['customer_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            $priority = $_POST['priority'] ?? 'medium';

            if (!$item_id || !$requesting_branch_id || !$donor_branch_id || $quantity <= 0) {
                throw new Exception('Invalid parameters');
            }

            // Service-only branches can request items; only the donor branch must keep inventory.
            $stmt = $pdo->prepare("SELECT id, name, has_inventory FROM branches WHERE id = ? AND status = 'active'");
            $stmt->execute([$requesting_branch_id]);
            $req_branch = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$req_branch) {
                throw new Exception('Requesting branch was not found');
            }

            // Verify donor branch has inventory enabled
            $stmt = $pdo->prepare("SELECT id, name, has_inventory FROM branches WHERE id = ? AND status = 'active'");
            $stmt->execute([$donor_branch_id]);
            $donor_branch = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$donor_branch || !$donor_branch['has_inventory']) {
                throw new Exception('Donor branch does not have inventory enabled');
            }

            // Verify item exists in donor branch with sufficient quantity
            $stmt = $pdo->prepare("
                SELECT item_name, quantity FROM inventory_items
                WHERE id = ? AND branch_id = ? AND status = 'active'
            ");
            $stmt->execute([$item_id, $donor_branch_id]);
            $donor_item = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$donor_item) {
                throw new Exception('Item not found in donor branch');
            }

            if ($donor_item['quantity'] < $quantity) {
                throw new Exception('Insufficient quantity in donor branch');
            }

            // Generate request number
            $date = date('Ymd');
            $stmt = $pdo->query("
                SELECT COUNT(*) as count
                FROM inter_branch_transfer_requests
                WHERE request_number LIKE 'TXF-$date-%'
            ");
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] + 1;
            $request_number = sprintf('TXF-%s-%04d', $date, $count);

            // Begin transaction
            $pdo->beginTransaction();

            // Create transfer request
            $stmt = $pdo->prepare("
                INSERT INTO inter_branch_transfer_requests
                (request_number, requesting_branch_id, donor_branch_id, item_id, item_name,
                 requested_quantity, reason, priority, quotation_id, customer_id, requested_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $request_number,
                $requesting_branch_id,
                $donor_branch_id,
                $item_id,
                $donor_item['item_name'],
                $quantity,
                $reason ?: 'Customer order',
                $priority,
                $quotation_id ?: null,
                $customer_id ?: null,
                $user['id']
            ]);

            $transfer_id = $pdo->lastInsertId();

            // Create notification for donor branch
            $donor_users = $pdo->prepare("
                SELECT id, role FROM users WHERE branch_id = ? AND status = 'active'
            ");
            $donor_users->execute([$donor_branch_id]);
            $donor_user_list = $donor_users->fetchAll(PDO::FETCH_ASSOC);

            $notification_stmt = $pdo->prepare("
                INSERT INTO transfer_notifications
                (branch_id, user_id, transfer_request_id, title, message, type, action_url)
                VALUES (?, ?, ?, ?, ?, 'warning', ?)
            ");

            $title = "Transfer Request: {$donor_item['item_name']}";
            $message = ($req_branch['name'] ?? 'Another branch') . " requests {$quantity}x {$donor_item['item_name']} " .
                       "(Priority: " . strtoupper($priority) . ")";

            foreach ($donor_user_list as $u) {
                $action_url = ($u['role'] ?? '') === 'admin'
                    ? "/hwtires/admin/transfers/?request={$transfer_id}"
                    : "/hwtires/front-desk/tire-inventory/?transfer_request={$transfer_id}#requested-items";

                $notification_stmt->execute([
                    $donor_branch_id,
                    $u['id'],
                    $transfer_id,
                    $title,
                    $message,
                    $action_url
                ]);
            }

            // Create notifications for requesting branch too
            $req_users = $pdo->prepare("
                SELECT id, role FROM users WHERE branch_id = ? AND status = 'active'
            ");
            $req_users->execute([$requesting_branch_id]);
            $req_user_list = $req_users->fetchAll(PDO::FETCH_ASSOC);

            $message_req = "Your transfer request #{$request_number} has been created. " .
                           "Waiting for approval from the donor branch.";

            foreach ($req_user_list as $u) {
                $requesting_action_url = ($u['role'] ?? '') === 'admin'
                    ? "/hwtires/admin/transfers/?request={$transfer_id}"
                    : "/hwtires/front-desk/tire-inventory/?transfer_request={$transfer_id}";

                $notification_stmt->execute([
                    $requesting_branch_id,
                    $u['id'],
                    $transfer_id,
                    "Transfer Request Created",
                    $message_req,
                    $requesting_action_url
                ]);
            }

            // Log the action
            log_audit('inter_branch_transfer_requests', 'CREATE', $transfer_id,
                      null, ['status' => 'pending', 'quantity' => $quantity]);

            $pdo->commit();

            $response['success'] = true;
            $response['message'] = "Transfer request #{$request_number} created successfully";
            $response['data'] = ['transfer_id' => $transfer_id, 'request_number' => $request_number];
            break;

        case 'approve_request':
            /**
             * Approve transfer request (by donor branch)
             * POST: transfer_id, approved_quantity
             */
            $transfer_id = (int) ($_POST['transfer_id'] ?? 0);
            $approved_qty = (int) ($_POST['approved_quantity'] ?? 0);

            if (!$transfer_id || $approved_qty <= 0) {
                throw new Exception('Invalid parameters');
            }

            // Get transfer request
            $stmt = $pdo->prepare("
                SELECT * FROM inter_branch_transfer_requests WHERE id = ?
            ");
            $stmt->execute([$transfer_id]);
            $transfer = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$transfer) {
                throw new Exception('Transfer request not found');
            }

            // Verify user is from donor branch
            if ($user['branch_id'] != $transfer['donor_branch_id'] && $user['role'] !== 'admin') {
                throw new Exception('Only donor branch can approve transfers');
            }

            if ($approved_qty > $transfer['requested_quantity']) {
                throw new Exception('Approved quantity cannot exceed requested quantity');
            }

            // Update transfer request
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE inter_branch_transfer_requests
                SET approved_quantity = ?, status = 'approved', approved_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$approved_qty, $user['id'], $transfer_id]);

            // Notify both branches
            $title = "Transfer Approved: {$transfer['item_name']}";
            $message = "Transfer request #{$transfer['request_number']} approved for {$approved_qty} units";

            $update_notif = $pdo->prepare("
                INSERT INTO transfer_notifications
                (branch_id, user_id, transfer_request_id, title, message, type, action_url)
                VALUES (?, ?, ?, ?, ?, 'success', ?)
            ");

            foreach ([$transfer['requesting_branch_id'], $transfer['donor_branch_id']] as $b_id) {
                $branch_users = $pdo->prepare("
                    SELECT id FROM users WHERE branch_id = ? AND status = 'active'
                ");
                $branch_users->execute([$b_id]);

                foreach ($branch_users->fetchAll(PDO::FETCH_ASSOC) as $u) {
                    $update_notif->execute([
                        $b_id,
                        $u['id'],
                        $transfer_id,
                        $title,
                        $message,
                        "/hwtires/admin/transfers/?request={$transfer_id}"
                    ]);
                }
            }

            log_audit('inter_branch_transfer_requests', 'UPDATE', $transfer_id,
                      ['approved_quantity' => 0], ['approved_quantity' => $approved_qty]);

            $pdo->commit();

            $response['success'] = true;
            $response['message'] = 'Transfer request approved';
            $response['data'] = ['transfer_id' => $transfer_id, 'status' => 'approved'];
            break;

        case 'complete_transfer':
        case 'ship_transfer':
            /**
             * Mark transfer as shipped by the donor branch.
             * Deducts stock from donor branch and sets status to 'shipped' (in transit).
             * Receiver stock is NOT added until receiver explicitly accepts.
             * POST: transfer_id
             */
            $transfer_id = (int) ($_POST['transfer_id'] ?? 0);

            if (!$transfer_id) {
                throw new Exception('Invalid transfer ID');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT
                    tr.*,
                    donor_item.quantity AS donor_quantity,
                    donor_item.item_name AS donor_item_name,
                    donor_item.category AS donor_category,
                    donor_item.brand AS donor_brand,
                    donor_item.size AS donor_size,
                    donor_item.description AS donor_description,
                    donor_item.sku AS donor_sku,
                    donor_item.reorder_level AS donor_reorder_level,
                    donor_item.unit_price AS donor_unit_price,
                    rb.name AS requesting_branch_name,
                    rb.has_inventory AS requesting_has_inventory,
                    db.name AS donor_branch_name,
                    c.name AS customer_name,
                    q.quotation_number,
                    q.vehicle_id,
                    jo.id AS job_order_id
                FROM inter_branch_transfer_requests tr
                INNER JOIN inventory_items donor_item ON donor_item.id = tr.item_id
                INNER JOIN branches rb ON rb.id = tr.requesting_branch_id
                INNER JOIN branches db ON db.id = tr.donor_branch_id
                LEFT JOIN customers c ON c.id = tr.customer_id
                LEFT JOIN quotations q ON q.id = tr.quotation_id
                LEFT JOIN job_orders jo ON jo.quotation_id = tr.quotation_id
                WHERE tr.id = ?
                FOR UPDATE
            ");
            $stmt->execute([$transfer_id]);
            $transfer = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$transfer) {
                throw new Exception('Transfer request not found');
            }

            $is_admin = in_array($user['role'] ?? '', ['admin', 'owner', 'admin_owner'], true);
            if (!$is_admin && (int) ($user['branch_id'] ?? 0) !== (int) $transfer['donor_branch_id']) {
                throw new Exception('Only the donor branch can mark this request as transferred');
            }

            $old_status = strtolower($transfer['status'] ?? 'pending');
            if ($old_status === 'shipped') {
                $pdo->commit();
                $response['success'] = true;
                $response['message'] = 'Transfer was already marked as shipped';
                $response['data'] = ['transfer_id' => $transfer_id, 'status' => 'shipped'];
                break;
            }

            if ($old_status === 'received') {
                $pdo->commit();
                $response['success'] = true;
                $response['message'] = 'Transfer was already completed';
                $response['data'] = ['transfer_id' => $transfer_id, 'status' => 'received'];
                break;
            }

            if (!in_array($old_status, ['pending', 'approved'], true)) {
                throw new Exception('This transfer request can no longer be shipped');
            }

            $transfer_qty = (int) ($transfer['approved_quantity'] ?? 0);
            if ($transfer_qty <= 0) {
                $transfer_qty = (int) ($transfer['requested_quantity'] ?? 0);
            }

            if ($transfer_qty <= 0) {
                throw new Exception('Transfer quantity is invalid');
            }

            if ((int) $transfer['donor_quantity'] < $transfer_qty) {
                throw new Exception('Not enough stock in the donor branch to complete this transfer');
            }

            // Deduct from donor branch inventory
            $stmt = $pdo->prepare("
                UPDATE inventory_items
                SET quantity = quantity - ?
                WHERE id = ? AND branch_id = ?
            ");
            $stmt->execute([
                $transfer_qty,
                $transfer['item_id'],
                $transfer['donor_branch_id']
            ]);

            $transfer_tags = [
                'customer_id' => $transfer['customer_id'] ?? null,
                'vehicle_id' => $transfer['vehicle_id'] ?? null,
                'job_order_id' => $transfer['job_order_id'] ?? null,
                'quotation_id' => $transfer['quotation_id'] ?? null,
            ];

            // Create stock_out transaction for donor branch
            transfer_log_inventory_transaction($pdo, $user,
                $transfer['item_id'],
                'stock_out',
                $transfer_qty,
                $transfer_id,
                "Item request {$transfer['request_number']} transferred to {$transfer['requesting_branch_name']}",
                $transfer_tags
            );

            // Update transfer status to shipped (in transit)
            $stmt = $pdo->prepare("
                UPDATE inter_branch_transfer_requests
                SET approved_quantity = ?,
                    approved_by = COALESCE(approved_by, ?),
                    status = 'shipped',
                    shipping_date = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$transfer_qty, $user['id'], $transfer_id]);

            $notification_stmt = $pdo->prepare("
                INSERT INTO transfer_notifications
                (branch_id, user_id, transfer_request_id, title, message, type, action_url)
                VALUES (?, ?, ?, ?, ?, 'info', ?)
            ");
            $notify_users_stmt = $pdo->prepare("
                SELECT id, branch_id
                FROM users
                WHERE branch_id IN (?, ?)
                  AND status = 'active'
            ");
            $notify_users_stmt->execute([
                $transfer['requesting_branch_id'],
                $transfer['donor_branch_id']
            ]);

            $notify_title = "Branch Transfer In Transit";
            $notify_message = "{$transfer['item_name']} (Qty: {$transfer_qty}) was shipped from {$transfer['donor_branch_name']} to {$transfer['requesting_branch_name']}. Please receive it when it arrives.";
            $receiver_url = "/hwtires/front-desk/tire-inventory/?transfer_request={$transfer_id}#incoming-branch-transfers";

            foreach ($notify_users_stmt->fetchAll(PDO::FETCH_ASSOC) as $notify_user) {
                $notification_stmt->execute([
                    $notify_user['branch_id'],
                    $notify_user['id'],
                    $transfer_id,
                    $notify_title,
                    $notify_message,
                    $receiver_url
                ]);
            }

            log_audit('inter_branch_transfer_requests', 'UPDATE', $transfer_id,
                      ['status' => $old_status], ['status' => 'shipped', 'quantity' => $transfer_qty]);

            $pdo->commit();

            $response['success'] = true;
            $response['message'] = "Transfer marked as shipped. Receiver must confirm delivery.";
            $response['data'] = ['transfer_id' => $transfer_id, 'status' => 'shipped'];
            break;

        case 'accept_transfer':
            /**
             * Accept transfer by the receiving branch.
             * Adds stock to the receiving branch and sets status to 'received'.
             * POST: transfer_id
             */
            $transfer_id = (int) ($_POST['transfer_id'] ?? 0);

            if (!$transfer_id) {
                throw new Exception('Invalid transfer ID');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT
                    tr.*,
                    donor_item.item_name AS donor_item_name,
                    donor_item.category AS donor_category,
                    donor_item.brand AS donor_brand,
                    donor_item.size AS donor_size,
                    donor_item.description AS donor_description,
                    donor_item.sku AS donor_sku,
                    donor_item.reorder_level AS donor_reorder_level,
                    donor_item.unit_price AS donor_unit_price,
                    rb.name AS requesting_branch_name,
                    rb.has_inventory AS requesting_has_inventory,
                    db.name AS donor_branch_name,
                    c.name AS customer_name,
                    q.quotation_number,
                    q.vehicle_id,
                    jo.id AS job_order_id
                FROM inter_branch_transfer_requests tr
                INNER JOIN inventory_items donor_item ON donor_item.id = tr.item_id
                INNER JOIN branches rb ON rb.id = tr.requesting_branch_id
                INNER JOIN branches db ON db.id = tr.donor_branch_id
                LEFT JOIN customers c ON c.id = tr.customer_id
                LEFT JOIN quotations q ON q.id = tr.quotation_id
                LEFT JOIN job_orders jo ON jo.quotation_id = tr.quotation_id
                WHERE tr.id = ?
                FOR UPDATE
            ");
            $stmt->execute([$transfer_id]);
            $transfer = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$transfer) {
                throw new Exception('Transfer request not found');
            }

            $is_admin = in_array($user['role'] ?? '', ['admin', 'owner', 'admin_owner'], true);
            $user_branch_id = (int) ($user['branch_id'] ?? 0);

            if (!$is_admin && $user_branch_id !== (int) $transfer['requesting_branch_id']) {
                throw new Exception('Only the receiving branch can accept this transfer');
            }

            if (!$is_admin && $user_branch_id === (int) $transfer['donor_branch_id']) {
                throw new Exception('Donor branch cannot accept its own transfer');
            }

            $current_status = strtolower($transfer['status'] ?? 'pending');
            if ($current_status === 'received') {
                $pdo->commit();
                $response['success'] = true;
                $response['message'] = 'Transfer was already accepted and received';
                $response['data'] = ['transfer_id' => $transfer_id, 'status' => 'received'];
                break;
            }

            if ($current_status !== 'shipped') {
                throw new Exception('Transfer must be shipped before it can be accepted');
            }

            $transfer_qty = (int) ($transfer['approved_quantity'] ?? 0);
            if ($transfer_qty <= 0) {
                $transfer_qty = (int) ($transfer['requested_quantity'] ?? 0);
            }

            $transfer_tags = [
                'customer_id' => $transfer['customer_id'] ?? null,
                'vehicle_id' => $transfer['vehicle_id'] ?? null,
                'job_order_id' => $transfer['job_order_id'] ?? null,
                'quotation_id' => $transfer['quotation_id'] ?? null,
            ];

            // If the requesting branch has inventory, add stock
            if ((int) ($transfer['requesting_has_inventory'] ?? 0) === 1) {
                $destination_stmt = $pdo->prepare("
                    SELECT id
                    FROM inventory_items
                    WHERE branch_id = ?
                      AND item_name = ?
                      AND COALESCE(category, '') = COALESCE(?, '')
                      AND COALESCE(brand, '') = COALESCE(?, '')
                      AND COALESCE(size, '') = COALESCE(?, '')
                      AND status = 'active'
                    LIMIT 1
                    FOR UPDATE
                ");
                $destination_stmt->execute([
                    $transfer['requesting_branch_id'],
                    $transfer['donor_item_name'],
                    $transfer['donor_category'],
                    $transfer['donor_brand'],
                    $transfer['donor_size']
                ]);
                $destination_item_id = (int) ($destination_stmt->fetchColumn() ?: 0);

                if ($destination_item_id > 0) {
                    $stock_in_stmt = $pdo->prepare("
                        UPDATE inventory_items
                        SET quantity = quantity + ?
                        WHERE id = ? AND branch_id = ?
                    ");
                    $stock_in_stmt->execute([
                        $transfer_qty,
                        $destination_item_id,
                        $transfer['requesting_branch_id']
                    ]);
                } else {
                    $insert_item_stmt = $pdo->prepare("
                        INSERT INTO inventory_items
                        (branch_id, item_name, category, brand, size, description, quantity, reorder_level, unit_price, status, last_restock_date)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', CURDATE())
                    ");
                    $insert_item_stmt->execute([
                        $transfer['requesting_branch_id'],
                        $transfer['donor_item_name'],
                        $transfer['donor_category'],
                        $transfer['donor_brand'],
                        $transfer['donor_size'],
                        $transfer['donor_description'],
                        $transfer_qty,
                        $transfer['donor_reorder_level'],
                        $transfer['donor_unit_price']
                    ]);
                    $destination_item_id = (int) $pdo->lastInsertId();
                }

                transfer_log_inventory_transaction($pdo, $user,
                    $destination_item_id,
                    'stock_in',
                    $transfer_qty,
                    $transfer_id,
                    "Item request {$transfer['request_number']} received from {$transfer['donor_branch_name']}",
                    $transfer_tags
                );
            }

            // Update status to received
            $stmt = $pdo->prepare("
                UPDATE inter_branch_transfer_requests
                SET status = 'received',
                    received_date = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$transfer_id]);

            // Notify donor branch that the transfer has been received and accepted
            $donor_notif_stmt = $pdo->prepare("
                INSERT INTO transfer_notifications
                (branch_id, user_id, transfer_request_id, title, message, type, action_url)
                VALUES (?, ?, ?, ?, ?, 'success', ?)
            ");
            $donor_users_stmt = $pdo->prepare("
                SELECT id, branch_id
                FROM users
                WHERE branch_id = ?
                  AND status = 'active'
            ");
            $donor_users_stmt->execute([(int) $transfer['donor_branch_id']]);
            $received_title = "Branch Transfer Received";
            $received_message = "{$transfer['donor_item_name']} (Qty: {$transfer_qty}) has been received by {$transfer['requesting_branch_name']}.";
            $donor_action_url = "/hwtires/front-desk/tire-inventory/?transfer_request={$transfer_id}#requested-items";

            foreach ($donor_users_stmt->fetchAll(PDO::FETCH_ASSOC) as $donor_user) {
                $donor_notif_stmt->execute([
                    $donor_user['branch_id'],
                    $donor_user['id'],
                    $transfer_id,
                    $received_title,
                    $received_message,
                    $donor_action_url
                ]);
            }

            log_audit('inter_branch_transfer_requests', 'UPDATE', $transfer_id,
                      ['status' => 'shipped'], ['status' => 'received', 'quantity' => $transfer_qty]);

            $pdo->commit();

            $response['success'] = true;
            $response['message'] = "Transfer accepted. {$transfer_qty} unit(s) added to inventory.";
            $response['data'] = ['transfer_id' => $transfer_id, 'status' => 'received'];
            break;

        case 'reject_transfer':
            /**
             * Reject transfer by the receiving branch.
             * Sets status to 'cancelled' with [RETURN_PENDING] note.
             * Does NOT restore donor inventory yet (goods in transit back).
             * POST: transfer_id, reason
             */
            $transfer_id = (int) ($_POST['transfer_id'] ?? 0);
            $rejection_reason = trim((string) ($_POST['reason'] ?? ''));

            if (!$transfer_id) {
                throw new Exception('Invalid transfer ID');
            }

            if ($rejection_reason === '') {
                throw new Exception('A reason is required when rejecting an incoming transfer');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT tr.*, rb.name AS requesting_branch_name, db.name AS donor_branch_name
                FROM inter_branch_transfer_requests tr
                INNER JOIN branches rb ON rb.id = tr.requesting_branch_id
                INNER JOIN branches db ON db.id = tr.donor_branch_id
                WHERE tr.id = ?
                FOR UPDATE
            ");
            $stmt->execute([$transfer_id]);
            $transfer = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$transfer) {
                throw new Exception('Transfer request not found');
            }

            $is_admin = in_array($user['role'] ?? '', ['admin', 'owner', 'admin_owner'], true);
            $user_branch_id = (int) ($user['branch_id'] ?? 0);

            if (!$is_admin && $user_branch_id !== (int) $transfer['requesting_branch_id']) {
                throw new Exception('Only the receiving branch can reject this transfer');
            }

            if (!$is_admin && $user_branch_id === (int) $transfer['donor_branch_id']) {
                throw new Exception('Donor branch cannot reject its own transfer');
            }

            $current_status = strtolower($transfer['status'] ?? 'pending');
            if ($current_status !== 'shipped') {
                throw new Exception('Only shipped transfers in transit can be rejected');
            }

            $existing_notes = trim((string) ($transfer['notes'] ?? ''));
            $reject_note = "[RETURN_PENDING] Rejected by " . ($user['username'] ?? 'Front Desk') . " (" . $transfer['requesting_branch_name'] . ") on " . date('Y-m-d H:i:s') . ". Reason: " . $rejection_reason;
            $new_notes = $existing_notes !== '' ? $existing_notes . "\n" . $reject_note : $reject_note;

            $stmt = $pdo->prepare("
                UPDATE inter_branch_transfer_requests
                SET status = 'cancelled',
                    notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$new_notes, $transfer_id]);

            log_audit('inter_branch_transfer_requests', 'UPDATE', $transfer_id,
                      ['status' => 'shipped'], ['status' => 'cancelled', 'reason' => $rejection_reason]);

            $pdo->commit();

            $response['success'] = true;
            $response['message'] = "Transfer rejected. Donor branch has been notified of pending return.";
            $response['data'] = ['transfer_id' => $transfer_id, 'status' => 'cancelled'];
            break;

        case 'reject_before_shipment':
            /**
             * Admin rejects a pending or approved transfer request before shipment due to insufficient stock.
             * 
             * Enforces:
             * 1. Admin authorization only.
             * 2. Status must be 'pending' or 'approved'.
             * 3. Current donor inventory quantity must be strictly less than the requested transfer quantity.
             * 4. Rejection reason is mandatory.
             * 5. Zero stock movement (0 donor deduction, 0 receiver addition, 0 inventory transactions).
             * 6. Notification sent to requesting branch staff.
             * 7. Audit log written to audit_logs.
             * 
             * POST: transfer_id, reason
             */
            $transfer_id = (int) ($_POST['transfer_id'] ?? 0);
            $rejection_reason = trim((string) ($_POST['reason'] ?? ''));

            if (!$transfer_id) {
                throw new Exception('Invalid transfer ID');
            }

            // 1. Authorization: Admin only
            $is_admin = in_array($user['role'] ?? '', ['admin', 'owner', 'admin_owner'], true);
            if (!$is_admin) {
                throw new Exception('Only administrators can reject transfer requests before shipment');
            }

            // 4. Mandatory reason
            if ($rejection_reason === '') {
                throw new Exception('A reason is required when rejecting a transfer request');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT
                    tr.*,
                    COALESCE(donor_item.quantity, 0) AS donor_quantity,
                    COALESCE(donor_item.item_name, tr.item_name) AS donor_item_name,
                    rb.name AS requesting_branch_name,
                    db.name AS donor_branch_name
                FROM inter_branch_transfer_requests tr
                LEFT JOIN inventory_items donor_item ON donor_item.id = tr.item_id AND donor_item.branch_id = tr.donor_branch_id
                INNER JOIN branches rb ON rb.id = tr.requesting_branch_id
                INNER JOIN branches db ON db.id = tr.donor_branch_id
                WHERE tr.id = ?
                FOR UPDATE
            ");
            $stmt->execute([$transfer_id]);
            $transfer = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$transfer) {
                throw new Exception('Transfer request not found');
            }

            // 2. Allowed transfer states: pending or approved
            $current_status = strtolower($transfer['status'] ?? 'pending');
            if (!in_array($current_status, ['pending', 'approved'], true)) {
                throw new Exception("This transfer request cannot be rejected before shipment because its status is '{$current_status}'");
            }

            $transfer_qty = (int) ($transfer['approved_quantity'] ?? 0);
            if ($transfer_qty <= 0) {
                $transfer_qty = (int) ($transfer['requested_quantity'] ?? 0);
            }
            if ($transfer_qty <= 0) {
                $transfer_qty = 1;
            }

            $donor_available = (int) ($transfer['donor_quantity'] ?? 0);

            // 3. Server-side insufficient stock validation
            if ($donor_available >= $transfer_qty) {
                throw new Exception("Cannot reject for insufficient stock: donor branch has {$donor_available} unit(s) available for requested {$transfer_qty} unit(s)");
            }

            // 5. Compose note (pre-shipment cancellation, no [RETURN_PENDING])
            $admin_name = $user['name'] ?? $user['username'] ?? 'Admin';
            $existing_notes = trim((string) ($transfer['notes'] ?? ''));
            $reject_note = "[REJECTED_PRE_SHIPMENT] Rejected by Admin ({$admin_name}) on " . date('Y-m-d H:i:s') . ". Available: {$donor_available}, Requested: {$transfer_qty}. Reason: {$rejection_reason}";
            $new_notes = $existing_notes !== '' ? $existing_notes . "\n" . $reject_note : $reject_note;

            // 6. Update status to 'cancelled' with notes (NO inventory modification)
            $stmt = $pdo->prepare("
                UPDATE inter_branch_transfer_requests
                SET status = 'cancelled',
                    notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$new_notes, $transfer_id]);

            // 7. Send notification to requesting branch users
            $notification_stmt = $pdo->prepare("
                INSERT INTO transfer_notifications
                (branch_id, user_id, transfer_request_id, title, message, type, action_url)
                VALUES (?, ?, ?, ?, ?, 'warning', ?)
            ");

            $req_users_stmt = $pdo->prepare("
                SELECT id, role FROM users
                WHERE branch_id = ? AND status = 'active'
            ");
            $req_users_stmt->execute([$transfer['requesting_branch_id']]);

            $notif_title = "Transfer Request Rejected — Insufficient Stock";
            $notif_message = "Transfer request #{$transfer['request_number']} for {$transfer['item_name']} was rejected by Admin due to insufficient stock at {$transfer['donor_branch_name']} (Available: {$donor_available}, Requested: {$transfer_qty}). Reason: {$rejection_reason}";

            foreach ($req_users_stmt->fetchAll(PDO::FETCH_ASSOC) as $req_user) {
                $req_url = ($req_user['role'] ?? '') === 'admin'
                    ? "/hwtires/admin/transfers/?request={$transfer_id}"
                    : "/hwtires/front-desk/tire-inventory/?transfer_request={$transfer_id}#requested-items";

                $notification_stmt->execute([
                    $transfer['requesting_branch_id'],
                    $req_user['id'],
                    $transfer_id,
                    $notif_title,
                    $notif_message,
                    $req_url
                ]);
            }

            // 8. Audit logging
            log_audit(
                'inter_branch_transfer_requests',
                'UPDATE',
                $transfer_id,
                ['status' => $current_status],
                [
                    'status' => 'cancelled',
                    'rejection_type' => 'pre_shipment_insufficient_stock',
                    'reason' => $rejection_reason,
                    'requested_quantity' => $transfer_qty,
                    'available_quantity' => $donor_available,
                ]
            );

            $pdo->commit();

            $response['success'] = true;
            $response['message'] = "Transfer request #{$transfer['request_number']} rejected due to insufficient stock.";
            $response['data'] = ['transfer_id' => $transfer_id, 'status' => 'cancelled'];
            break;

        case 'confirm_return':
            /**
             * Confirm physical return by donor branch.
             * Restores stock to donor branch once goods physically arrive back.
             * POST: transfer_id
             */
            $transfer_id = (int) ($_POST['transfer_id'] ?? 0);

            if (!$transfer_id) {
                throw new Exception('Invalid transfer ID');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT tr.*, rb.name AS requesting_branch_name, db.name AS donor_branch_name
                FROM inter_branch_transfer_requests tr
                INNER JOIN branches rb ON rb.id = tr.requesting_branch_id
                INNER JOIN branches db ON db.id = tr.donor_branch_id
                WHERE tr.id = ?
                FOR UPDATE
            ");
            $stmt->execute([$transfer_id]);
            $transfer = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$transfer) {
                throw new Exception('Transfer request not found');
            }

            $is_admin = in_array($user['role'] ?? '', ['admin', 'owner', 'admin_owner'], true);
            $user_branch_id = (int) ($user['branch_id'] ?? 0);

            if (!$is_admin && $user_branch_id !== (int) $transfer['donor_branch_id']) {
                throw new Exception('Only the donor branch can confirm receipt of returned stock');
            }

            if (!$is_admin && $user_branch_id === (int) $transfer['requesting_branch_id']) {
                throw new Exception('Receiving branch cannot confirm return for the donor branch');
            }

            $notes = (string) ($transfer['notes'] ?? '');
            if (strpos($notes, '[RETURN_PENDING]') === false) {
                throw new Exception('This transfer does not have a pending return to confirm');
            }

            if (strpos($notes, '[RETURNED]') !== false) {
                $pdo->commit();
                $response['success'] = true;
                $response['message'] = 'Return was already confirmed and stock restored';
                $response['data'] = ['transfer_id' => $transfer_id, 'status' => 'cancelled'];
                break;
            }

            $transfer_qty = (int) ($transfer['approved_quantity'] ?? 0);
            if ($transfer_qty <= 0) {
                $transfer_qty = (int) ($transfer['requested_quantity'] ?? 0);
            }

            // Restore donor stock
            $stmt = $pdo->prepare("
                UPDATE inventory_items
                SET quantity = quantity + ?
                WHERE id = ? AND branch_id = ?
            ");
            $stmt->execute([
                $transfer_qty,
                $transfer['item_id'],
                $transfer['donor_branch_id']
            ]);

            // Log stock in restoration transaction
            transfer_log_inventory_transaction($pdo, $user,
                $transfer['item_id'],
                'stock_in',
                $transfer_qty,
                $transfer_id,
                "Returned transfer stock confirmed from {$transfer['requesting_branch_name']}"
            );

            $return_note = "[RETURNED] Physical return confirmed by " . ($user['username'] ?? 'Storekeeper') . " on " . date('Y-m-d H:i:s');
            $new_notes = $notes . "\n" . $return_note;

            $stmt = $pdo->prepare("
                UPDATE inter_branch_transfer_requests
                SET notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$new_notes, $transfer_id]);

            log_audit('inter_branch_transfer_requests', 'UPDATE', $transfer_id,
                      ['notes' => $notes], ['notes' => $new_notes, 'restored_quantity' => $transfer_qty]);

            $pdo->commit();

            $response['success'] = true;
            $response['message'] = "Physical return confirmed. {$transfer_qty} unit(s) restored to donor inventory.";
            $response['data'] = ['transfer_id' => $transfer_id, 'status' => 'cancelled'];
            break;

        default:
            throw new Exception('Invalid action: ' . $action);
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
