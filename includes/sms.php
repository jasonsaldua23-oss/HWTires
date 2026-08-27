<?php
/**
 * SMS-ready outbox helpers.
 *
 * This stores messages the system should send later through an SMS provider.
 */

if (!function_exists('sms_ensure_outbox_table')) {
    function sms_ensure_outbox_table(PDO $pdo) {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS sms_outbox (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    job_order_id INT NOT NULL,
                    customer_id INT NOT NULL,
                    vehicle_id INT NULL,
                    branch_id INT NOT NULL,
                    recipient_name VARCHAR(150) NOT NULL,
                    recipient_phone VARCHAR(40) NULL,
                    message_body TEXT NOT NULL,
                    status ENUM('queued', 'sent', 'failed', 'cancelled') DEFAULT 'queued',
                    provider VARCHAR(50) NULL,
                    provider_message_id VARCHAR(120) NULL,
                    error_message TEXT NULL,
                    queued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    sent_at DATETIME NULL,
                    created_by INT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_sms_outbox_job_order (job_order_id),
                    INDEX idx_sms_outbox_customer (customer_id),
                    INDEX idx_sms_outbox_branch (branch_id),
                    INDEX idx_sms_outbox_status (status),
                    INDEX idx_sms_outbox_queued_at (queued_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Exception $e) {
            error_log('Unable to create sms_outbox table: ' . $e->getMessage());
        }
    }
}

if (!function_exists('sms_normalize_phone')) {
    function sms_normalize_phone($phone) {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return '';
        }

        return preg_replace('/[^\d+]/', '', $phone);
    }
}

if (!function_exists('sms_completed_job_vehicle_label')) {
    function sms_completed_job_vehicle_label(array $job) {
        $parts = [];
        $make_model = trim(($job['vehicle_make'] ?? '') . ' ' . ($job['vehicle_model'] ?? ''));

        if ($make_model !== '') {
            $parts[] = $make_model;
        } else {
            $parts[] = 'vehicle';
        }

        $plate = trim((string) ($job['plate_number'] ?? ''));
        if ($plate !== '') {
            $parts[] = '(' . $plate . ')';
        }

        return trim(implode(' ', $parts));
    }
}

if (!function_exists('sms_completed_job_services')) {
    function sms_completed_job_services(PDO $pdo, array $job) {
        $services = [];

        if (!empty($job['quotation_id'])) {
            $items_stmt = $pdo->prepare("
                SELECT item_name
                FROM quotation_items
                WHERE quotation_id = ?
                ORDER BY id ASC
            ");
            $items_stmt->execute([(int) $job['quotation_id']]);

            foreach ($items_stmt->fetchAll() as $item) {
                $name = trim((string) ($item['item_name'] ?? ''));
                if ($name !== '') {
                    $services[] = $name;
                }
            }
        }

        if (empty($services)) {
            $history_stmt = $pdo->prepare("
                SELECT services_description
                FROM service_history
                WHERE job_order_id = ?
                ORDER BY service_date DESC, id DESC
                LIMIT 1
            ");
            $history_stmt->execute([(int) $job['id']]);
            $description = trim((string) $history_stmt->fetchColumn());

            if ($description !== '') {
                $services[] = $description;
            }
        }

        return !empty($services) ? implode(', ', $services) : 'service work';
    }
}

if (!function_exists('sms_completed_job_message')) {
    function sms_completed_job_message(array $job, $services_text) {
        $customer_name = trim((string) ($job['customer_name'] ?? 'Customer'));
        $vehicle_label = sms_completed_job_vehicle_label($job);
        $branch_name = trim((string) ($job['branch_name'] ?? 'Highway Tires'));
        $services_text = trim((string) $services_text);

        $message = "Hello {$customer_name}, your {$vehicle_label} service is complete. Services received: {$services_text}. Your vehicle is ready to be picked up at {$branch_name}. Thank you, Highway Tires.";

        return preg_replace('/\s+/', ' ', trim($message));
    }
}

if (!function_exists('sms_queue_completed_job_pickup')) {
    function sms_queue_completed_job_pickup(PDO $pdo, $job_order_id, $created_by = null) {
        $job_order_id = (int) $job_order_id;
        $created_by = $created_by !== null ? (int) $created_by : null;

        if ($job_order_id <= 0) {
            return [
                'queued' => false,
                'status' => 'failed',
                'message' => 'Invalid job order ID.',
            ];
        }

        sms_ensure_outbox_table($pdo);

        $stmt = $pdo->prepare("
            SELECT
                jo.id,
                jo.customer_id,
                jo.vehicle_id,
                jo.branch_id,
                jo.quotation_id,
                jo.status AS job_status,
                c.name AS customer_name,
                c.phone_mobile,
                c.contact,
                c.phone_work,
                v.make AS vehicle_make,
                v.model AS vehicle_model,
                v.plate_number,
                b.name AS branch_name
            FROM job_orders jo
            LEFT JOIN customers c ON c.id = jo.customer_id
            LEFT JOIN vehicles v ON v.id = jo.vehicle_id
            LEFT JOIN branches b ON b.id = jo.branch_id
            WHERE jo.id = ?
            LIMIT 1
        ");
        $stmt->execute([$job_order_id]);
        $job = $stmt->fetch();

        if (!$job) {
            return [
                'queued' => false,
                'status' => 'failed',
                'message' => 'Job order not found.',
            ];
        }

        if (($job['job_status'] ?? '') !== 'completed') {
            return [
                'queued' => false,
                'status' => 'skipped',
                'message' => 'Job order is not completed.',
            ];
        }

        $phone = sms_normalize_phone($job['phone_mobile'] ?? '');
        if ($phone === '') {
            $phone = sms_normalize_phone($job['contact'] ?? '');
        }
        if ($phone === '') {
            $phone = sms_normalize_phone($job['phone_work'] ?? '');
        }

        $services_text = sms_completed_job_services($pdo, $job);
        $message_body = sms_completed_job_message($job, $services_text);
        $outbox_status = $phone !== '' ? 'queued' : 'failed';
        $error_message = $phone !== '' ? null : 'No customer phone number available.';

        $insert_stmt = $pdo->prepare("
            INSERT INTO sms_outbox (
                job_order_id,
                customer_id,
                vehicle_id,
                branch_id,
                recipient_name,
                recipient_phone,
                message_body,
                status,
                error_message,
                queued_at,
                created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE
                customer_id = VALUES(customer_id),
                vehicle_id = VALUES(vehicle_id),
                branch_id = VALUES(branch_id),
                recipient_name = VALUES(recipient_name),
                recipient_phone = VALUES(recipient_phone),
                message_body = VALUES(message_body),
                status = CASE
                    WHEN sms_outbox.status = 'sent' THEN sms_outbox.status
                    ELSE VALUES(status)
                END,
                error_message = CASE
                    WHEN sms_outbox.status = 'sent' THEN sms_outbox.error_message
                    ELSE VALUES(error_message)
                END,
                queued_at = CASE
                    WHEN sms_outbox.status = 'sent' THEN sms_outbox.queued_at
                    ELSE VALUES(queued_at)
                END,
                created_by = VALUES(created_by),
                updated_at = NOW()
        ");

        $insert_stmt->execute([
            $job_order_id,
            (int) $job['customer_id'],
            !empty($job['vehicle_id']) ? (int) $job['vehicle_id'] : null,
            (int) $job['branch_id'],
            $job['customer_name'] ?: 'Customer',
            $phone !== '' ? $phone : null,
            $message_body,
            $outbox_status,
            $error_message,
            $created_by,
        ]);

        return [
            'queued' => $outbox_status === 'queued',
            'status' => $outbox_status,
            'message' => $outbox_status === 'queued'
                ? 'Pickup SMS queued.'
                : 'Pickup SMS message was prepared, but no customer phone number is available.',
            'message_body' => $message_body,
            'recipient_phone' => $phone,
        ];
    }
}
?>
