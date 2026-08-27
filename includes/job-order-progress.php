<?php
/**
 * Job order progress helpers.
 *
 * A job order is considered 100% complete when every service/item line from
 * its quotation has been checked as done.
 */

if (!function_exists('job_progress_ensure_schema')) {
    function job_progress_ensure_schema(PDO $pdo) {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS job_order_progress (
                id INT PRIMARY KEY AUTO_INCREMENT,
                job_order_id INT NOT NULL,
                quotation_item_id INT NULL,
                task_key VARCHAR(120) NOT NULL,
                task_name VARCHAR(255) NOT NULL,
                task_type VARCHAR(30) DEFAULT 'service',
                quantity INT DEFAULT 1,
                is_done TINYINT(1) NOT NULL DEFAULT 0,
                completed_at DATETIME NULL,
                updated_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_job_progress_task (job_order_id, task_key),
                INDEX idx_job_progress_job (job_order_id),
                INDEX idx_job_progress_done (is_done),
                CONSTRAINT fk_job_progress_job
                    FOREIGN KEY (job_order_id) REFERENCES job_orders(id) ON DELETE CASCADE,
                CONSTRAINT fk_job_progress_item
                    FOREIGN KEY (quotation_item_id) REFERENCES quotation_items(id) ON DELETE SET NULL,
                CONSTRAINT fk_job_progress_user
                    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $ensured = true;
    }
}

if (!function_exists('job_progress_task_key')) {
    function job_progress_task_key($line) {
        if (!empty($line['id'])) {
            return 'qi:' . (int) $line['id'];
        }

        $name = strtolower(trim((string) ($line['item_name'] ?? 'service request')));
        return 'custom:' . sha1($name);
    }
}

if (!function_exists('job_progress_item_inventory_meta')) {
    function job_progress_item_inventory_meta(array $line) {
        $notes = trim((string) ($line['notes'] ?? ''));
        if ($notes === '' || $notes[0] !== '{') {
            return [];
        }

        $decoded = json_decode($notes, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('job_progress_get_transfer_states_for_job')) {
    function job_progress_get_transfer_states_for_job(PDO $pdo, $job_order_id) {
        job_progress_ensure_schema($pdo);

        $job_order_id = (int) $job_order_id;
        if ($job_order_id <= 0) {
            return [];
        }

        $job_stmt = $pdo->prepare("SELECT id, quotation_id, branch_id FROM job_orders WHERE id = ? LIMIT 1");
        $job_stmt->execute([$job_order_id]);
        $job = $job_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job || empty($job['quotation_id'])) {
            return [];
        }

        $tasks_stmt = $pdo->prepare("\n            SELECT jp.task_key, qi.id AS quotation_item_id, qi.item_name, qi.item_type, qi.source, qi.notes\n            FROM job_order_progress jp\n            INNER JOIN quotation_items qi ON qi.id = jp.quotation_item_id\n            WHERE jp.job_order_id = ?\n            ORDER BY jp.id ASC\n        ");
        $tasks_stmt->execute([$job_order_id]);

        $states = [];
        $job_branch_id = (int) ($job['branch_id'] ?? 0);
        foreach ($tasks_stmt->fetchAll(PDO::FETCH_ASSOC) as $task) {
            $task_key = (string) ($task['task_key'] ?? '');
            if ($task_key === '') {
                continue;
            }

            $meta = job_progress_item_inventory_meta($task);
            $inventory_item_id = (int) ($meta['inventory_item_id'] ?? 0);
            $inventory_branch_id = (int) ($meta['inventory_branch_id'] ?? 0);
            $requires_transfer = strtolower((string) ($task['source'] ?? '')) === 'other_branch'
                || ($inventory_branch_id > 0 && $inventory_branch_id !== $job_branch_id);

            $state = [
                'requires_transfer' => $requires_transfer,
                'is_ready' => true,
                'status' => 'ready',
                'label' => 'Ready',
                'message' => '',
                'branch_name' => '',
            ];

            if ($requires_transfer) {
                if ($inventory_item_id <= 0) {
                    $state['is_ready'] = false;
                    $state['status'] = 'not-sent';
                    $state['label'] = 'Waiting for transfer';
                    $state['message'] = 'This item has not been linked to an inventory transfer yet.';
                    $states[$task_key] = $state;
                    continue;
                }

                $transfer_stmt = $pdo->prepare("\n                    SELECT tr.status, tr.request_number, tr.donor_branch_id, tr.requesting_branch_id, b.name AS donor_branch_name\n                    FROM inter_branch_transfer_requests tr\n                    LEFT JOIN branches b ON b.id = tr.donor_branch_id\n                    WHERE tr.quotation_id = ?\n                      AND tr.item_id = ?\n                      AND tr.requesting_branch_id = ?\n                      AND tr.donor_branch_id = ?\n                    ORDER BY tr.id DESC\n                    LIMIT 1\n                ");
                $transfer_stmt->execute([
                    (int) $job['quotation_id'],
                    $inventory_item_id,
                    $job_branch_id,
                    $inventory_branch_id,
                ]);
                $transfer = $transfer_stmt->fetch(PDO::FETCH_ASSOC);

                $branch_name = $transfer['donor_branch_name'] ?? ('Branch ' . $inventory_branch_id);
                $status = strtolower((string) ($transfer['status'] ?? 'missing'));

                if ($status === 'received') {
                    $state['status'] = 'transferred';
                    $state['label'] = 'Transferred';
                    $state['message'] = 'Transferred from ' . $branch_name;
                    $state['branch_name'] = $branch_name;
                } else {
                    $state['is_ready'] = false;
                    $state['status'] = $status === 'missing' ? 'not-sent' : $status;
                    $state['label'] = 'Waiting for transfer';
                    $state['message'] = $status === 'missing'
                        ? 'This item has not been sent from ' . $branch_name . ' yet.'
                        : 'Waiting for transfer from ' . $branch_name . '.';
                    $state['branch_name'] = $branch_name;
                }
            }

            $states[$task_key] = $state;
        }

        return $states;
    }
}

if (!function_exists('job_progress_sync')) {
    function job_progress_sync(PDO $pdo, $job_order_id, $mark_completed_tasks = true) {
        job_progress_ensure_schema($pdo);

        $job_order_id = (int) $job_order_id;
        if ($job_order_id <= 0) {
            return;
        }

        $job_stmt = $pdo->prepare("SELECT id, quotation_id, status FROM job_orders WHERE id = ? LIMIT 1");
        $job_stmt->execute([$job_order_id]);
        $job = $job_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            return;
        }

        $lines = [];
        if (!empty($job['quotation_id'])) {
            $line_stmt = $pdo->prepare("
                SELECT id, item_name, item_type, quantity
                FROM quotation_items
                WHERE quotation_id = ?
                ORDER BY id ASC
            ");
            $line_stmt->execute([(int) $job['quotation_id']]);
            $lines = $line_stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (empty($lines)) {
            $lines[] = [
                'id' => null,
                'item_name' => 'Service Request',
                'item_type' => 'service',
                'quantity' => 1,
            ];
        }

        $valid_keys = [];
        $insert_stmt = $pdo->prepare("
            INSERT INTO job_order_progress (
                job_order_id, quotation_item_id, task_key, task_name, task_type, quantity
            ) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                quotation_item_id = VALUES(quotation_item_id),
                task_name = VALUES(task_name),
                task_type = VALUES(task_type),
                quantity = VALUES(quantity),
                updated_at = NOW()
        ");

        foreach ($lines as $line) {
            $task_key = job_progress_task_key($line);
            $valid_keys[] = $task_key;
            $insert_stmt->execute([
                $job_order_id,
                !empty($line['id']) ? (int) $line['id'] : null,
                $task_key,
                trim((string) ($line['item_name'] ?? 'Service Request')) ?: 'Service Request',
                trim((string) ($line['item_type'] ?? 'service')) ?: 'service',
                max(1, (int) ($line['quantity'] ?? 1)),
            ]);
        }

        if (!empty($valid_keys)) {
            $placeholders = implode(',', array_fill(0, count($valid_keys), '?'));
            $delete_params = array_merge([$job_order_id], $valid_keys);
            $delete_stmt = $pdo->prepare("
                DELETE FROM job_order_progress
                WHERE job_order_id = ?
                  AND task_key NOT IN ($placeholders)
            ");
            $delete_stmt->execute($delete_params);
        }

        if ($mark_completed_tasks && ($job['status'] ?? '') === 'completed') {
            $done_stmt = $pdo->prepare("
                UPDATE job_order_progress
                SET is_done = 1,
                    completed_at = COALESCE(completed_at, NOW())
                WHERE job_order_id = ?
            ");
            $done_stmt->execute([$job_order_id]);
        }
    }
}

if (!function_exists('job_progress_sync_many')) {
    function job_progress_sync_many(PDO $pdo, array $job_order_ids) {
        $job_order_ids = array_values(array_unique(array_filter(array_map('intval', $job_order_ids))));
        foreach ($job_order_ids as $job_order_id) {
            job_progress_sync($pdo, $job_order_id);
        }
    }
}

if (!function_exists('job_progress_summary_from_tasks')) {
    function job_progress_summary_from_tasks(array $tasks) {
        $total = count($tasks);
        $done = 0;

        foreach ($tasks as $task) {
            if (!empty($task['is_done'])) {
                $done++;
            }
        }

        $percent = $total > 0 ? (int) round(($done / $total) * 100) : 0;

        return [
            'tasks' => $tasks,
            'total' => $total,
            'done' => $done,
            'percent' => max(0, min(100, $percent)),
        ];
    }
}

if (!function_exists('job_progress_status_from_percent')) {
    function job_progress_status_from_percent($percent) {
        $percent = (int) $percent;
        if ($percent >= 100) {
            return 'completed';
        }

        if ($percent > 0) {
            return 'in-progress';
        }

        return 'waiting';
    }
}

if (!function_exists('job_progress_get_for_jobs')) {
    function job_progress_get_for_jobs(PDO $pdo, array $job_orders) {
        $job_order_ids = [];
        foreach ($job_orders as $job) {
            $job_order_ids[] = is_array($job) ? (int) ($job['id'] ?? 0) : (int) $job;
        }
        $job_order_ids = array_values(array_unique(array_filter($job_order_ids)));

        if (empty($job_order_ids)) {
            return [];
        }

        job_progress_sync_many($pdo, $job_order_ids);

        $placeholders = implode(',', array_fill(0, count($job_order_ids), '?'));
        $stmt = $pdo->prepare("
            SELECT *
            FROM job_order_progress
            WHERE job_order_id IN ($placeholders)
            ORDER BY job_order_id ASC, id ASC
        ");
        $stmt->execute($job_order_ids);

        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $task) {
            $grouped[(int) $task['job_order_id']][] = $task;
        }

        $summaries = [];
        foreach ($job_order_ids as $job_order_id) {
            $summaries[$job_order_id] = job_progress_summary_from_tasks($grouped[$job_order_id] ?? []);
        }

        return $summaries;
    }
}

if (!function_exists('job_progress_get_for_job')) {
    function job_progress_get_for_job(PDO $pdo, $job_order_id) {
        $summaries = job_progress_get_for_jobs($pdo, [(int) $job_order_id]);
        return $summaries[(int) $job_order_id] ?? job_progress_summary_from_tasks([]);
    }
}

if (!function_exists('job_progress_get_for_job_unsynced')) {
    function job_progress_get_for_job_unsynced(PDO $pdo, $job_order_id) {
        job_progress_ensure_schema($pdo);

        $job_order_id = (int) $job_order_id;
        if ($job_order_id <= 0) {
            return job_progress_summary_from_tasks([]);
        }

        $stmt = $pdo->prepare("
            SELECT *
            FROM job_order_progress
            WHERE job_order_id = ?
            ORDER BY id ASC
        ");
        $stmt->execute([$job_order_id]);

        return job_progress_summary_from_tasks($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}

if (!function_exists('job_progress_update_task')) {
    function job_progress_update_task(PDO $pdo, $job_order_id, $task_key, $is_done, $updated_by = null) {
        job_progress_sync($pdo, $job_order_id, (bool) $is_done);

        $job_order_id = (int) $job_order_id;
        $task_key = trim((string) $task_key);
        if ($job_order_id <= 0 || $task_key === '') {
            throw new Exception('Invalid job progress task.');
        }

        $task_stmt = $pdo->prepare("
            SELECT *
            FROM job_order_progress
            WHERE job_order_id = ?
              AND task_key = ?
            LIMIT 1
        ");
        $task_stmt->execute([$job_order_id, $task_key]);
        $task = $task_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$task) {
            throw new Exception('Progress task not found.');
        }

        $update_stmt = $pdo->prepare("
            UPDATE job_order_progress
            SET is_done = ?,
                completed_at = CASE WHEN ? = 1 THEN COALESCE(completed_at, NOW()) ELSE NULL END,
                updated_by = ?,
                updated_at = NOW()
            WHERE job_order_id = ?
              AND task_key = ?
        ");
        $done_value = $is_done ? 1 : 0;
        $update_stmt->execute([
            $done_value,
            $done_value,
            $updated_by !== null ? (int) $updated_by : null,
            $job_order_id,
            $task_key,
        ]);

        $summary = job_progress_get_for_job_unsynced($pdo, $job_order_id);
        $summary['recommended_status'] = job_progress_status_from_percent($summary['percent']);

        return $summary;
    }
}
?>
