<?php
/**
 * Shared customer + vehicle history helpers.
 */

if (!function_exists('cv_records_branch_label')) {
    function cv_records_branch_label($name) {
        return app_branch_label($name, '-');
    }
}

if (!function_exists('cv_records_branch_class')) {
    function cv_records_branch_class($branch_id) {
        $branch_id = intval($branch_id);
        return $branch_id > 0 ? 'customer-branch-' . ((($branch_id - 1) % 3) + 1) : 'customer-branch-empty';
    }
}

if (!function_exists('cv_records_plate_branch_class')) {
    function cv_records_plate_branch_class($branch_id) {
        $branch_id = intval($branch_id);
        return $branch_id > 0 ? 'vehicle-plate-branch-' . ((($branch_id - 1) % 3) + 1) : 'vehicle-plate-branch-empty';
    }
}

if (!function_exists('cv_records_vehicle_name')) {
    function cv_records_vehicle_name($vehicle, $include_plate = false) {
        $name = trim(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? ''));
        if ($name === '') {
            $name = 'Vehicle';
        }

        if ($include_plate && !empty($vehicle['plate_number'])) {
            $name .= ' (' . $vehicle['plate_number'] . ')';
        }

        return $name;
    }
}

if (!function_exists('cv_records_short_date')) {
    function cv_records_short_date($date) {
        return !empty($date) ? date('Y-m-d', strtotime($date)) : '-';
    }
}

if (!function_exists('cv_records_money')) {
    function cv_records_money($amount) {
        return '&#8369;' . number_format((float) $amount, 0);
    }
}

if (!function_exists('cv_records_line_total')) {
    function cv_records_line_total($item) {
        if (isset($item['line_total'])) {
            return (float) $item['line_total'];
        }

        return (float) ($item['quantity'] ?? 1) * (float) ($item['unit_price'] ?? 0);
    }
}

if (!function_exists('cv_records_group_availed_items')) {
    function cv_records_group_availed_items(array $items) {
        $groups = [];

        foreach ($items as $index => $item) {
            $quotation_number = trim((string) ($item['quotation_number'] ?? ''));
            $quotation_id = (int) ($item['quotation_id'] ?? 0);
            $group_key = $quotation_number !== ''
                ? 'quote-number:' . strtolower($quotation_number)
                : ($quotation_id > 0 ? 'quote-id:' . $quotation_id : 'item:' . $index);
            $item_type = $item['item_type'] ?? 'item';
            $source_label = cv_records_item_source_label($item['source'] ?? '', $item_type);
            $type_label = cv_records_item_type_label($item_type);

            if (!isset($groups[$group_key])) {
                $groups[$group_key] = [
                    'vehicle_id' => (int) ($item['vehicle_id'] ?? 0),
                    'branch_id' => (int) ($item['branch_id'] ?? 0),
                    'record_date' => $item['record_date'] ?? '',
                    'customer_name' => $item['customer_name'] ?? '-',
                    'branch_name' => $item['branch_name'] ?? '',
                    'quotation_number' => $quotation_number,
                    'quotation_status' => $item['quotation_status'] ?? '',
                    'items' => [],
                    'source_labels' => [],
                    'type_labels' => [],
                    'status_labels' => [],
                    'total' => 0.0,
                ];
            }

            $groups[$group_key]['items'][] = $item;
            $groups[$group_key]['source_labels'][$source_label] = true;
            $groups[$group_key]['type_labels'][$type_label] = true;
            $status_key = cv_records_operation_status_normalize($item['quotation_status'] ?? '');
            if ($status_key !== 'no-service') {
                $groups[$group_key]['status_labels'][$status_key] = cv_records_operation_status_label($status_key);
            }
            $groups[$group_key]['total'] += cv_records_line_total($item);
        }

        return array_values($groups);
    }
}

if (!function_exists('cv_records_item_type_label')) {
    function cv_records_item_type_label($type) {
        $type = strtolower((string) $type);
        return [
            'service' => 'Service',
            'part' => 'Part',
            'tire' => 'Tire',
            'accessory' => 'Accessory',
        ][$type] ?? 'Item';
    }
}

if (!function_exists('cv_records_item_source_label')) {
    function cv_records_item_source_label($source, $item_type) {
        $source = strtolower((string) $source);
        $item_type = strtolower((string) $item_type);

        if ($item_type === 'service') {
            return 'Service Operation';
        }

        return [
            'own_inventory' => 'Stock Out - Own Inventory',
            'other_branch' => 'Stock Out - Other Branch',
            'external' => 'External Item',
            'customer_supplied' => 'Customer Supplied',
        ][$source] ?? 'Item';
    }
}

if (!function_exists('cv_records_services')) {
    function cv_records_services($value) {
        if (empty($value)) {
            return [];
        }

        $parts = preg_split('/[,;\r\n]+/', $value);
        $parts = array_map('trim', $parts);
        return array_values(array_filter($parts, function ($part) {
            return $part !== '';
        }));
    }
}

if (!function_exists('cv_records_operation_status_normalize')) {
    function cv_records_operation_status_normalize($status) {
        $status = strtolower(trim((string) $status));

        if ($status === '' || $status === 'none') {
            return 'no-service';
        }

        if (in_array($status, ['waiting', 'pending'], true)) {
            return 'pending';
        }

        if ($status === 'approved') {
            return 'approved';
        }

        if (in_array($status, ['in-progress', 'ongoing'], true)) {
            return 'ongoing';
        }

        if ($status === 'completed') {
            return 'completed';
        }

        if ($status === 'rejected') {
            return 'rejected';
        }

        if ($status === 'cancelled') {
            return 'no-service';
        }

        return preg_replace('/[^a-z0-9-]+/', '-', $status) ?: 'no-service';
    }
}

if (!function_exists('cv_records_operation_status_label')) {
    function cv_records_operation_status_label($status) {
        $status = cv_records_operation_status_normalize($status);
        $labels = [
            'pending' => 'Pending',
            'approved' => 'Approved',
            'ongoing' => 'Ongoing',
            'completed' => 'Completed',
            'rejected' => 'Rejected',
            'no-service' => 'No Service',
        ];

        return $labels[$status] ?? ucwords(str_replace('-', ' ', $status));
    }
}

if (!function_exists('cv_records_operation_status_class')) {
    function cv_records_operation_status_class($status) {
        return cv_records_operation_status_normalize($status);
    }
}

if (!function_exists('cv_records_status_filter_options')) {
    function cv_records_status_filter_options() {
        return [
            'all' => 'All Service Statuses',
            'pending' => 'Pending',
            'ongoing' => 'Ongoing',
            'completed' => 'Completed',
            'no-service' => 'No Service',
        ];
    }
}

if (!function_exists('cv_records_status_filter_current')) {
    function cv_records_status_filter_current($value = null) {
        $status = strtolower(trim((string) ($value ?? ($_GET['status'] ?? 'all'))));
        if ($status === '' || $status === 'all') {
            return 'all';
        }

        $status = cv_records_service_status_normalize($status);
        return array_key_exists($status, cv_records_status_filter_options()) ? $status : 'all';
    }
}

if (!function_exists('cv_records_operation_filter_options')) {
    function cv_records_operation_filter_options() {
        return [
            'all' => 'All Service Operations',
            'pending' => 'Pending',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'no-service' => 'No Operation',
        ];
    }
}

if (!function_exists('cv_records_operation_filter_current')) {
    function cv_records_operation_filter_current($value = null) {
        $status = strtolower(trim((string) ($value ?? ($_GET['operation_status'] ?? 'all'))));
        if ($status === '' || $status === 'all') {
            return 'all';
        }

        $status = cv_records_operation_status_normalize($status);
        return array_key_exists($status, cv_records_operation_filter_options()) ? $status : 'all';
    }
}

if (!function_exists('cv_records_service_status_normalize')) {
    function cv_records_service_status_normalize($status) {
        $status = strtolower(trim((string) $status));
        $status = str_replace('_', '-', $status);

        if (in_array($status, ['waiting', 'pending'], true)) {
            return 'pending';
        }

        if (in_array($status, ['in-progress', 'ongoing'], true)) {
            return 'ongoing';
        }

        if ($status === 'completed') {
            return 'completed';
        }

        return 'no-service';
    }
}

if (!function_exists('cv_records_service_status_label')) {
    function cv_records_service_status_label($status) {
        $status = cv_records_service_status_normalize($status);
        $labels = [
            'pending' => 'Pending',
            'ongoing' => 'Ongoing',
            'completed' => 'Completed',
            'no-service' => 'No Service',
        ];

        return $labels[$status] ?? ucwords(str_replace('-', ' ', $status));
    }
}

if (!function_exists('cv_records_service_status_class')) {
    function cv_records_service_status_class($status) {
        return cv_records_service_status_normalize($status);
    }
}

if (!function_exists('cv_records_activity_source_label')) {
    function cv_records_activity_source_label($source) {
        $source = strtolower(trim((string) $source));
        return [
            'service_history' => 'Latest service history',
            'job_order' => 'Latest job order',
            'quotation' => 'Latest service operation',
            'vehicle_record' => 'Vehicle record branch',
        ][$source] ?? 'Latest record';
    }
}

if (!function_exists('cv_records_load_latest_branch_activity')) {
    function cv_records_load_latest_branch_activity(PDO $pdo, array $vehicle_ids, $include_archived = false) {
        $vehicle_ids = array_values(array_unique(array_filter(array_map('intval', $vehicle_ids))));
        $branch_by_vehicle = [];

        if (empty($vehicle_ids)) {
            return $branch_by_vehicle;
        }

        $placeholders = implode(',', array_fill(0, count($vehicle_ids), '?'));
        $status_job_sql = $include_archived ? " AND jo.status <> 'cancelled'" : " AND jo.status NOT IN ('archived', 'cancelled')";
        $status_quote_sql = $include_archived ? '' : " AND q.status <> 'archived'";
        $activity_params = array_merge($vehicle_ids, $vehicle_ids, $vehicle_ids);

        $activity_stmt = $pdo->prepare("
            SELECT vehicle_id, branch_id, activity_source, visited_at
            FROM (
                SELECT sh.vehicle_id,
                       sh.branch_id,
                       'service_history' AS activity_source,
                       CAST(CONCAT(sh.service_date, ' ', COALESCE(TIME(sh.created_at), '00:00:00')) AS DATETIME) AS visited_at,
                       sh.id AS source_id,
                       1 AS source_rank
                FROM service_history sh
                WHERE sh.vehicle_id IN ($placeholders)
                  AND sh.branch_id IS NOT NULL

                UNION ALL

                SELECT jo.vehicle_id,
                       jo.branch_id,
                       'job_order' AS activity_source,
                       CAST(CONCAT(jo.job_date, ' ', COALESCE(TIME(jo.updated_at), TIME(jo.created_at), '00:00:00')) AS DATETIME) AS visited_at,
                       jo.id AS source_id,
                       2 AS source_rank
                FROM job_orders jo
                WHERE jo.vehicle_id IN ($placeholders)
                  AND jo.branch_id IS NOT NULL
                  $status_job_sql

                UNION ALL

                SELECT q.vehicle_id,
                       q.branch_id,
                       'quotation' AS activity_source,
                       CAST(CONCAT(q.quotation_date, ' ', COALESCE(TIME(q.updated_at), TIME(q.created_at), '00:00:00')) AS DATETIME) AS visited_at,
                       q.id AS source_id,
                       3 AS source_rank
                FROM quotations q
                WHERE q.vehicle_id IN ($placeholders)
                  AND q.branch_id IS NOT NULL
                  $status_quote_sql
            ) vehicle_branch_activity
            ORDER BY vehicle_id ASC, visited_at DESC, source_rank ASC, source_id DESC
        ");
        $activity_stmt->execute($activity_params);

        foreach ($activity_stmt->fetchAll() as $activity) {
            $vehicle_id = (int) ($activity['vehicle_id'] ?? 0);
            if ($vehicle_id <= 0 || isset($branch_by_vehicle[$vehicle_id])) {
                continue;
            }

            $branch_by_vehicle[$vehicle_id] = [
                'branch_id' => (int) ($activity['branch_id'] ?? 0),
                'source' => (string) ($activity['activity_source'] ?? ''),
                'source_label' => cv_records_activity_source_label($activity['activity_source'] ?? ''),
                'visited_at' => $activity['visited_at'] ?? null,
            ];
        }

        return $branch_by_vehicle;
    }
}

if (!function_exists('cv_records_customer_date_condition')) {
    function cv_records_customer_date_condition(array $filter, $branch_filter, array &$params) {
        $checks = [];
        $branch_id = $branch_filter !== '' ? (int) $branch_filter : 0;

        $customer_params = [];
        $customer_condition = record_date_filter_condition(record_business_datetime_expr('c.created_at', 'c.created_at'), $filter, $customer_params);
        if ($customer_condition !== '') {
            $checks[] = "($customer_condition)";
            $params = array_merge($params, $customer_params);
        }

        $sources = [
            [
                'table' => 'customer_branch_records',
                'alias' => 'cbr_date',
                'date_expr' => record_business_datetime_expr('cbr_date.created_at', 'cbr_date.created_at'),
                'extra' => "cbr_date.status = 'active'",
                'branch_column' => 'branch_id',
            ],
            [
                'table' => 'quotations',
                'alias' => 'q_date',
                'date_expr' => record_business_datetime_expr('q_date.quotation_date', 'q_date.created_at'),
                'extra' => "q_date.status <> 'archived'",
                'branch_column' => 'branch_id',
            ],
            [
                'table' => 'job_orders',
                'alias' => 'jo_date',
                'date_expr' => record_business_datetime_expr('jo_date.job_date', 'jo_date.created_at'),
                'extra' => "jo_date.status NOT IN ('archived', 'cancelled')",
                'branch_column' => 'branch_id',
            ],
            [
                'table' => 'service_history',
                'alias' => 'sh_date',
                'date_expr' => record_business_datetime_expr('sh_date.service_date', 'sh_date.created_at'),
                'extra' => '1=1',
                'branch_column' => 'branch_id',
            ],
            [
                'table' => 'customer_visits',
                'alias' => 'cv_date',
                'date_expr' => record_business_datetime_expr('cv_date.visit_date', 'cv_date.created_at'),
                'extra' => '1=1',
                'branch_column' => 'branch_id',
            ],
            [
                'table' => 'vehicles',
                'alias' => 'v_date',
                'date_expr' => record_business_datetime_expr('v_date.last_service_date', 'v_date.created_at'),
                'extra' => "v_date.status = 'active'",
                'branch_column' => 'branch_id',
            ],
        ];

        foreach ($sources as $source) {
            $source_params = [];
            $source_condition = record_date_filter_condition($source['date_expr'], $filter, $source_params);
            if ($source_condition === '') {
                continue;
            }

            $alias = $source['alias'];
            $sql = "EXISTS (
                SELECT 1
                FROM {$source['table']} $alias
                WHERE $alias.customer_id = c.id
                  AND {$source['extra']}";

            if ($branch_id > 0) {
                $sql .= " AND $alias.{$source['branch_column']} = ?";
                $params[] = $branch_id;
            }

            $sql .= " AND $source_condition
            )";

            $checks[] = $sql;
            $params = array_merge($params, $source_params);
        }

        return empty($checks) ? '' : '(' . implode(' OR ', $checks) . ')';
    }
}

if (!function_exists('cv_records_load_latest_operation_summary')) {
    function cv_records_load_latest_operation_summary(PDO $pdo, array $vehicle_ids) {
        $vehicle_ids = array_values(array_unique(array_filter(array_map('intval', $vehicle_ids))));
        $summary_by_vehicle = [];

        if (empty($vehicle_ids)) {
            return $summary_by_vehicle;
        }

        $placeholders = implode(',', array_fill(0, count($vehicle_ids), '?'));
        $params = array_merge($vehicle_ids, $vehicle_ids, $vehicle_ids);
        $stmt = $pdo->prepare("
            SELECT vehicle_id, operation_status, service_job_id, service_job_branch_id
            FROM (
                SELECT jo.vehicle_id,
                       CASE
                           WHEN jo.status IN ('waiting', 'pending') THEN 'pending'
                           WHEN jo.status = 'in-progress' THEN 'ongoing'
                           WHEN jo.status = 'completed' THEN 'completed'
                           ELSE COALESCE(jo.status, 'pending')
                       END AS operation_status,
                       jo.id AS service_job_id,
                       jo.branch_id AS service_job_branch_id,
                       CAST(CONCAT(jo.job_date, ' ', COALESCE(TIME(jo.updated_at), TIME(jo.created_at), '00:00:00')) AS DATETIME) AS activity_at,
                       jo.id AS source_id,
                       1 AS source_rank
                FROM job_orders jo
                WHERE jo.vehicle_id IN ($placeholders)
                  AND jo.status NOT IN ('archived', 'cancelled')

                UNION ALL

                SELECT q.vehicle_id,
                       CASE
                           WHEN q.status = 'pending' THEN 'pending'
                           WHEN q.status = 'approved' THEN 'approved'
                           WHEN q.status = 'rejected' THEN 'rejected'
                           ELSE COALESCE(q.status, 'pending')
                       END AS operation_status,
                       NULL AS service_job_id,
                       NULL AS service_job_branch_id,
                       CAST(CONCAT(q.quotation_date, ' ', COALESCE(TIME(q.updated_at), TIME(q.created_at), '00:00:00')) AS DATETIME) AS activity_at,
                       q.id AS source_id,
                       2 AS source_rank
                FROM quotations q
                WHERE q.vehicle_id IN ($placeholders)
                  AND q.status <> 'archived'

                UNION ALL

                SELECT sh.vehicle_id,
                       'completed' AS operation_status,
                       NULL AS service_job_id,
                       NULL AS service_job_branch_id,
                       CAST(CONCAT(sh.service_date, ' ', COALESCE(TIME(sh.created_at), '00:00:00')) AS DATETIME) AS activity_at,
                       sh.id AS source_id,
                       3 AS source_rank
                FROM service_history sh
                WHERE sh.vehicle_id IN ($placeholders)
            ) vehicle_operation_activity
            ORDER BY vehicle_id ASC, activity_at DESC, source_rank ASC, source_id DESC
        ");
        $stmt->execute($params);

        foreach ($stmt->fetchAll() as $row) {
            $vehicle_id = (int) ($row['vehicle_id'] ?? 0);
            if ($vehicle_id > 0 && !isset($summary_by_vehicle[$vehicle_id])) {
                $summary_by_vehicle[$vehicle_id] = [
                    'status' => cv_records_operation_status_normalize($row['operation_status'] ?? ''),
                    'job_id' => (int) ($row['service_job_id'] ?? 0),
                    'job_branch_id' => (int) ($row['service_job_branch_id'] ?? 0),
                ];
            }
        }

        return $summary_by_vehicle;
    }
}

if (!function_exists('cv_records_load_latest_service_operation_summary')) {
    function cv_records_load_latest_service_operation_summary(PDO $pdo, array $vehicle_ids) {
        $vehicle_ids = array_values(array_unique(array_filter(array_map('intval', $vehicle_ids))));
        $summary_by_vehicle = [];

        if (empty($vehicle_ids)) {
            return $summary_by_vehicle;
        }

        $placeholders = implode(',', array_fill(0, count($vehicle_ids), '?'));
        $params = array_merge($vehicle_ids, $vehicle_ids);
        $stmt = $pdo->prepare("
            SELECT vehicle_id, quotation_id, quotation_branch_id, quotation_status
            FROM (
                SELECT q.vehicle_id,
                       q.id AS quotation_id,
                       q.branch_id AS quotation_branch_id,
                       CASE
                           WHEN q.status = 'pending' THEN 'pending'
                           WHEN q.status = 'approved' THEN 'approved'
                           WHEN q.status = 'rejected' THEN 'rejected'
                           ELSE COALESCE(q.status, 'pending')
                       END AS quotation_status,
                       CAST(CONCAT(q.quotation_date, ' ', COALESCE(TIME(q.updated_at), TIME(q.created_at), '00:00:00')) AS DATETIME) AS activity_at,
                       q.id AS source_id,
                       1 AS source_rank
                FROM quotations q
                WHERE q.vehicle_id IN ($placeholders)
                  AND q.status <> 'archived'

                UNION ALL

                SELECT jo.vehicle_id,
                       q.id AS quotation_id,
                       COALESCE(q.branch_id, jo.branch_id) AS quotation_branch_id,
                       CASE
                           WHEN q.status = 'rejected' THEN 'rejected'
                           WHEN q.status = 'pending' THEN 'pending'
                           ELSE 'approved'
                       END AS quotation_status,
                       CAST(CONCAT(jo.job_date, ' ', COALESCE(TIME(jo.updated_at), TIME(jo.created_at), '00:00:00')) AS DATETIME) AS activity_at,
                       q.id AS source_id,
                       2 AS source_rank
                FROM job_orders jo
                INNER JOIN quotations q ON jo.quotation_id = q.id
                WHERE jo.vehicle_id IN ($placeholders)
                  AND jo.status NOT IN ('archived', 'cancelled')
            ) vehicle_quote_activity
            ORDER BY vehicle_id ASC, activity_at DESC, source_rank ASC, source_id DESC
        ");
        $stmt->execute($params);

        foreach ($stmt->fetchAll() as $row) {
            $vehicle_id = (int) ($row['vehicle_id'] ?? 0);
            if ($vehicle_id > 0 && !isset($summary_by_vehicle[$vehicle_id])) {
                $summary_by_vehicle[$vehicle_id] = [
                    'status' => cv_records_operation_status_normalize($row['quotation_status'] ?? ''),
                    'quotation_id' => (int) ($row['quotation_id'] ?? 0),
                    'quotation_branch_id' => (int) ($row['quotation_branch_id'] ?? 0),
                ];
            }
        }

        return $summary_by_vehicle;
    }
}

if (!function_exists('cv_records_load_latest_service_status_summary')) {
    function cv_records_load_latest_service_status_summary(PDO $pdo, array $vehicle_ids) {
        $vehicle_ids = array_values(array_unique(array_filter(array_map('intval', $vehicle_ids))));
        $summary_by_vehicle = [];

        if (empty($vehicle_ids)) {
            return $summary_by_vehicle;
        }

        $placeholders = implode(',', array_fill(0, count($vehicle_ids), '?'));
        $params = array_merge($vehicle_ids, $vehicle_ids);
        $stmt = $pdo->prepare("
            SELECT vehicle_id, service_status, service_job_id, service_job_branch_id
            FROM (
                SELECT jo.vehicle_id,
                       CASE
                           WHEN jo.status IN ('waiting', 'pending') THEN 'pending'
                           WHEN jo.status = 'in-progress' THEN 'ongoing'
                           WHEN jo.status = 'completed' THEN 'completed'
                           ELSE COALESCE(jo.status, 'pending')
                       END AS service_status,
                       jo.id AS service_job_id,
                       jo.branch_id AS service_job_branch_id,
                       CAST(CONCAT(jo.job_date, ' ', COALESCE(TIME(jo.updated_at), TIME(jo.created_at), '00:00:00')) AS DATETIME) AS activity_at,
                       jo.id AS source_id,
                       CASE
                           WHEN jo.status = 'in-progress' THEN 1
                           WHEN jo.status IN ('waiting', 'pending') THEN 2
                           WHEN jo.status = 'completed' THEN 3
                           ELSE 4
                       END AS priority_rank
                FROM job_orders jo
                WHERE jo.vehicle_id IN ($placeholders)
                  AND jo.status NOT IN ('archived', 'cancelled')

                UNION ALL

                SELECT sh.vehicle_id,
                       'completed' AS service_status,
                       COALESCE(sh.job_order_id, 0) AS service_job_id,
                       sh.branch_id AS service_job_branch_id,
                       CAST(CONCAT(sh.service_date, ' ', COALESCE(TIME(sh.created_at), '00:00:00')) AS DATETIME) AS activity_at,
                       sh.id AS source_id,
                       5 AS priority_rank
                FROM service_history sh
                WHERE sh.vehicle_id IN ($placeholders)
            ) vehicle_service_activity
            ORDER BY vehicle_id ASC, priority_rank ASC, activity_at DESC, source_id DESC
        ");
        $stmt->execute($params);

        foreach ($stmt->fetchAll() as $row) {
            $vehicle_id = (int) ($row['vehicle_id'] ?? 0);
            if ($vehicle_id > 0 && !isset($summary_by_vehicle[$vehicle_id])) {
                $summary_by_vehicle[$vehicle_id] = [
                    'status' => cv_records_service_status_normalize($row['service_status'] ?? ''),
                    'job_id' => (int) ($row['service_job_id'] ?? 0),
                    'job_branch_id' => (int) ($row['service_job_branch_id'] ?? 0),
                ];
            }
        }

        return $summary_by_vehicle;
    }
}

if (!function_exists('cv_records_align_service_status_with_operation')) {
    function cv_records_align_service_status_with_operation(PDO $pdo, array $operation_summary_by_vehicle, array $fallback_status_by_vehicle) {
        $quotation_ids = [];
        foreach ($operation_summary_by_vehicle as $operation_summary) {
            $quotation_id = (int) ($operation_summary['quotation_id'] ?? 0);
            if ($quotation_id > 0) {
                $quotation_ids[] = $quotation_id;
            }
        }

        $status_by_quotation = [];
        $quotation_ids = array_values(array_unique($quotation_ids));
        if (!empty($quotation_ids)) {
            $placeholders = implode(',', array_fill(0, count($quotation_ids), '?'));
            $params = array_merge($quotation_ids, $quotation_ids);
            $stmt = $pdo->prepare("
                SELECT quotation_id, service_status, service_job_id, service_job_branch_id
                FROM (
                    SELECT jo.quotation_id,
                           CASE
                               WHEN jo.status IN ('waiting', 'pending') THEN 'pending'
                               WHEN jo.status = 'in-progress' THEN 'ongoing'
                               WHEN jo.status = 'completed' THEN 'completed'
                               ELSE COALESCE(jo.status, 'pending')
                           END AS service_status,
                           jo.id AS service_job_id,
                           jo.branch_id AS service_job_branch_id,
                           CAST(CONCAT(jo.job_date, ' ', COALESCE(TIME(jo.updated_at), TIME(jo.created_at), '00:00:00')) AS DATETIME) AS activity_at,
                           jo.id AS source_id,
                           CASE
                               WHEN jo.status = 'in-progress' THEN 1
                               WHEN jo.status IN ('waiting', 'pending') THEN 2
                               WHEN jo.status = 'completed' THEN 3
                               ELSE 4
                           END AS priority_rank
                    FROM job_orders jo
                    WHERE jo.quotation_id IN ($placeholders)
                      AND jo.status NOT IN ('archived', 'cancelled')

                    UNION ALL

                    SELECT sh.quotation_id,
                           'completed' AS service_status,
                           COALESCE(sh.job_order_id, 0) AS service_job_id,
                           sh.branch_id AS service_job_branch_id,
                           CAST(CONCAT(sh.service_date, ' ', COALESCE(TIME(sh.created_at), '00:00:00')) AS DATETIME) AS activity_at,
                           sh.id AS source_id,
                           5 AS priority_rank
                    FROM service_history sh
                    WHERE sh.quotation_id IN ($placeholders)
                ) linked_service_activity
                ORDER BY quotation_id ASC, priority_rank ASC, activity_at DESC, source_id DESC
            ");
            $stmt->execute($params);

            foreach ($stmt->fetchAll() as $row) {
                $quotation_id = (int) ($row['quotation_id'] ?? 0);
                if ($quotation_id > 0 && !isset($status_by_quotation[$quotation_id])) {
                    $status_by_quotation[$quotation_id] = [
                        'status' => cv_records_service_status_normalize($row['service_status'] ?? ''),
                        'job_id' => (int) ($row['service_job_id'] ?? 0),
                        'job_branch_id' => (int) ($row['service_job_branch_id'] ?? 0),
                    ];
                }
            }
        }

        $aligned_status_by_vehicle = [];
        $all_keys = array_unique(array_merge(array_keys($fallback_status_by_vehicle), array_keys($operation_summary_by_vehicle)));

        foreach ($all_keys as $vehicle_id) {
            $vehicle_id = (int) $vehicle_id;
            if ($vehicle_id <= 0) {
                continue;
            }

            $fallback = $fallback_status_by_vehicle[$vehicle_id] ?? [
                'status' => 'no-service',
                'job_id' => 0,
                'job_branch_id' => 0
            ];
            $fallback_status = cv_records_service_status_normalize($fallback['status'] ?? 'no-service');
            $fallback_job_id = (int) ($fallback['job_id'] ?? 0);
            $fallback_branch_id = (int) ($fallback['job_branch_id'] ?? 0);

            $op = $operation_summary_by_vehicle[$vehicle_id] ?? null;
            $op_status = $op ? cv_records_operation_status_normalize($op['status'] ?? '') : 'no-service';
            $quotation_id = (int) ($op['quotation_id'] ?? 0);
            $quotation_branch_id = (int) ($op['quotation_branch_id'] ?? $fallback_branch_id);

            // Priority 1: Direct link from the latest operation / quotation to its Job Order or Service History
            if ($quotation_id > 0 && isset($status_by_quotation[$quotation_id])) {
                $linked = $status_by_quotation[$quotation_id];
                $linked_status = cv_records_service_status_normalize($linked['status'] ?? '');
                if ($linked_status !== 'no-service') {
                    $aligned_status_by_vehicle[$vehicle_id] = [
                        'status' => $linked_status,
                        'job_id' => (int) ($linked['job_id'] ?? 0),
                        'job_branch_id' => (int) ($linked['job_branch_id'] ?? $quotation_branch_id),
                    ];
                    continue;
                }
            }

            // Priority 2: Latest operation is Rejected -> current cycle has no service
            if ($op_status === 'rejected') {
                $aligned_status_by_vehicle[$vehicle_id] = [
                    'status' => 'no-service',
                    'job_id' => 0,
                    'job_branch_id' => $quotation_branch_id,
                ];
                continue;
            }

            // Priority 3: Latest operation is Approved or Pending without Job Order yet -> cycle status is Pending
            if ($op_status === 'approved' || $op_status === 'pending') {
                $aligned_status_by_vehicle[$vehicle_id] = [
                    'status' => 'pending',
                    'job_id' => 0,
                    'job_branch_id' => $quotation_branch_id,
                ];
                continue;
            }

            // Priority 4: Standalone active Job Order on vehicle (e.g. if not linked to any quotation)
            if (in_array($fallback_status, ['ongoing', 'pending'], true) && $fallback_job_id > 0) {
                $aligned_status_by_vehicle[$vehicle_id] = [
                    'status' => $fallback_status,
                    'job_id' => $fallback_job_id,
                    'job_branch_id' => $fallback_branch_id,
                ];
                continue;
            }

            // Priority 5: Default / No Operation -> No Service
            $aligned_status_by_vehicle[$vehicle_id] = [
                'status' => 'no-service',
                'job_id' => 0,
                'job_branch_id' => $quotation_branch_id ?: $fallback_branch_id,
            ];
        }

        return $aligned_status_by_vehicle;
    }
}

if (!function_exists('cv_records_load_latest_operation_status')) {
    function cv_records_load_latest_operation_status(PDO $pdo, array $vehicle_ids) {
        $summary_by_vehicle = cv_records_load_latest_operation_summary($pdo, $vehicle_ids);
        $status_by_vehicle = [];

        foreach ($summary_by_vehicle as $vehicle_id => $summary) {
            $status_by_vehicle[$vehicle_id] = $summary['status'] ?? 'no-service';
        }

        return $status_by_vehicle;
    }
}

if (!function_exists('cv_records_job_status_label')) {
    function cv_records_job_status_label($status) {
        $status = (string) $status;
        if (in_array($status, ['waiting', 'pending'], true)) {
            return 'Waiting';
        }

        return ucwords(str_replace('-', ' ', $status));
    }
}

if (!function_exists('cv_records_job_order_number')) {
    function cv_records_job_order_number($job) {
        if (!empty($job['job_number'])) {
            return $job['job_number'];
        }

        return 'JO' . str_pad((string) ($job['id'] ?? 0), 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('cv_records_job_vehicle_label')) {
    function cv_records_job_vehicle_label($job, $include_plate = true) {
        $vehicle = trim(($job['vehicle_make'] ?? '') . ' ' . ($job['vehicle_model'] ?? ''));
        if ($vehicle === '') {
            $vehicle = 'Vehicle';
        }

        if ($include_plate && !empty($job['plate_number'])) {
            $vehicle .= ' (' . $job['plate_number'] . ')';
        }

        return $vehicle;
    }
}

if (!function_exists('cv_records_job_display_date')) {
    function cv_records_job_display_date($date) {
        return !empty($date) ? date('Y-m-d', strtotime($date)) : '-';
    }
}

if (!function_exists('cv_records_load_job_order_details')) {
    function cv_records_load_job_order_details(PDO $pdo, array $job_ids) {
        require_once __DIR__ . '/job-order-progress.php';

        $job_ids = array_values(array_unique(array_filter(array_map('intval', $job_ids))));
        if (empty($job_ids)) {
            return [[], [], []];
        }

        $placeholders = implode(',', array_fill(0, count($job_ids), '?'));
        $job_stmt = $pdo->prepare("
            SELECT jo.*,
                   c.name AS customer_name,
                   c.phone_mobile AS customer_phone,
                   c.contact AS customer_contact,
                   v.make AS vehicle_make,
                   v.model AS vehicle_model,
                   v.year AS vehicle_year,
                   v.plate_number,
                   b.name AS branch_name,
                   u.name AS created_by_name,
                   q.quotation_number,
                   q.inspection_complaint,
                   q.inspection_findings,
                   q.inspection_recommendations,
                   q.inspection_mileage
            FROM job_orders jo
            LEFT JOIN quotations q ON q.id = jo.quotation_id
            LEFT JOIN customers c ON c.id = jo.customer_id
            LEFT JOIN vehicles v ON v.id = jo.vehicle_id
            LEFT JOIN branches b ON b.id = jo.branch_id
            LEFT JOIN users u ON u.id = jo.created_by
            WHERE jo.id IN ($placeholders)
              AND jo.status <> 'cancelled'
            ORDER BY jo.job_date DESC, jo.id DESC
        ");
        $job_stmt->execute($job_ids);

        $jobs_by_id = [];
        $quotation_ids = [];
        foreach ($job_stmt->fetchAll(PDO::FETCH_ASSOC) as $job) {
            $job_id = (int) ($job['id'] ?? 0);
            if ($job_id <= 0) {
                continue;
            }

            $jobs_by_id[$job_id] = $job;
            if (!empty($job['quotation_id'])) {
                $quotation_ids[] = (int) $job['quotation_id'];
            }
        }

        $services_by_quotation = [];
        $quotation_ids = array_values(array_unique(array_filter($quotation_ids)));
        if (!empty($quotation_ids)) {
            $quotation_placeholders = implode(',', array_fill(0, count($quotation_ids), '?'));
            $items_stmt = $pdo->prepare("
                SELECT quotation_id, item_name, item_type
                FROM quotation_items
                WHERE quotation_id IN ($quotation_placeholders)
                ORDER BY quotation_id ASC, id ASC
            ");
            $items_stmt->execute($quotation_ids);

            foreach ($items_stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $services_by_quotation[(int) ($item['quotation_id'] ?? 0)][] = app_display_item_name($item['item_name'], $item['item_type'] ?? null);
            }
        }

        $progress_by_job = [];
        if (function_exists('app_table_exists') && app_table_exists('job_order_progress')) {
            $progress_stmt = $pdo->prepare("
                SELECT *
                FROM job_order_progress
                WHERE job_order_id IN ($placeholders)
                ORDER BY job_order_id ASC, id ASC
            ");
            $progress_stmt->execute($job_ids);

            $tasks_by_job = [];
            foreach ($progress_stmt->fetchAll(PDO::FETCH_ASSOC) as $task) {
                $tasks_by_job[(int) ($task['job_order_id'] ?? 0)][] = $task;
            }

            foreach ($job_ids as $job_id) {
                $progress_by_job[$job_id] = job_progress_summary_from_tasks($tasks_by_job[$job_id] ?? []);
            }
        }

        foreach ($job_ids as $job_id) {
            if (isset($progress_by_job[$job_id])) {
                continue;
            }

            $progress_by_job[$job_id] = [
                'tasks' => [],
                'total' => 0,
                'done' => 0,
                'percent' => 0,
            ];
        }

        return [$jobs_by_id, $services_by_quotation, $progress_by_job];
    }
}

if (!function_exists('cv_records_render_job_order_details_modal')) {
    function cv_records_render_job_order_details_modal(array $job, array $services = [], array $progress = [], $modal_prefix = 'customerJobDetailsModal') {
        $job_id = (int) ($job['id'] ?? 0);
        if ($job_id <= 0) {
            return;
        }

        $modal_id = $modal_prefix . $job_id;
        $job_number = cv_records_job_order_number($job);
        $status = $job['status'] ?? 'waiting';
        $display_date = cv_records_job_display_date($job['job_date'] ?? $job['created_at'] ?? '');
        $phone = ($job['customer_phone'] ?? '') ?: ($job['customer_contact'] ?? '');
        $technician = trim((string) ($job['assigned_technician_name'] ?? '')) ?: 'Unassigned';
        $note = function_exists('app_format_record_notes')
            ? app_format_record_notes($job['notes'] ?? '')
            : trim((string) ($job['notes'] ?? ''));
        $progress_percent = (int) ($progress['percent'] ?? 0);
        ?>
        <div class="modal fade job-details-modal" id="<?php echo esc_attr($modal_id); ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h2>Job Order Details</h2>
                            <p>Job Order ID: <?php echo esc_html($job_number); ?></p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <section class="job-detail-summary">
                            <div>
                                <span>Customer</span>
                                <strong><?php echo esc_html($job['customer_name'] ?? 'Customer'); ?></strong>
                                <p><?php echo esc_html($phone ?: '-'); ?></p>
                            </div>
                            <div>
                                <span>Vehicle</span>
                                <strong><?php echo esc_html(cv_records_job_vehicle_label($job, false)); ?></strong>
                                <p><?php echo esc_html($job['plate_number'] ?: '-'); ?></p>
                            </div>
                        </section>

                        <section class="job-detail-grid">
                            <div>
                                <span>Branch</span>
                                <strong><?php echo esc_html(app_branch_label($job['branch_name'] ?? '', 'Branch')); ?></strong>
                            </div>
                            <div>
                                <span>Date Created</span>
                                <strong><?php echo esc_html($display_date); ?></strong>
                            </div>
                            <div>
                                <span>Status</span>
                                <strong>
                                    <span class="job-status-pill status-<?php echo esc_attr($status); ?>">
                                        <?php echo esc_html(cv_records_job_status_label($status)); ?>
                                    </span>
                                </strong>
                            </div>
                            <div>
                                <span>Assigned To</span>
                                <strong><?php echo esc_html($technician); ?></strong>
                            </div>
                            <?php if (!empty($job['estimated_duration'])): ?>
                                <div>
                                    <span>Estimated Duration</span>
                                    <strong><?php echo esc_html($job['estimated_duration']); ?></strong>
                                </div>
                            <?php endif; ?>
                            <div>
                                <span>Service Operation</span>
                                <strong><?php echo esc_html($job['quotation_number'] ?: '-'); ?></strong>
                            </div>
                            <div>
                                <span>Sales in Charge</span>
                                <strong><?php echo esc_html($job['created_by_name'] ?: '-'); ?></strong>
                            </div>
                        </section>

                        <section class="job-detail-section service-progress-readonly">
                            <h3>Service Progress</h3>
                            <div class="service-progress-track" aria-label="Job order progress">
                                <span style="width: <?php echo $progress_percent; ?>%;"></span>
                            </div>
                            <p class="service-progress-copy">
                                <strong><?php echo $progress_percent; ?>%</strong> completed
                                <span>(<?php echo (int) ($progress['done'] ?? 0); ?>/<?php echo (int) ($progress['total'] ?? 0); ?> done)</span>
                            </p>
                            <div class="service-task-list service-task-list-modal" aria-label="Read only job order checklist">
                                <?php foreach (($progress['tasks'] ?? []) as $task): ?>
                                    <?php
                                    $task_done = !empty($task['is_done']);
                                    $task_quantity = max(1, (int) ($task['quantity'] ?? 1));
                                    ?>
                                    <label class="service-task-check <?php echo $task_done ? 'is-done' : ''; ?>">
                                        <input type="checkbox" <?php echo $task_done ? 'checked' : ''; ?> disabled>
                                        <span>
                                            <?php echo esc_html($task['task_name']); ?>
                                            <?php if ($task_quantity > 1): ?>
                                                <small>x<?php echo $task_quantity; ?></small>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </section>

                        <?php
                        $has_inspection = trim((string) ($job['inspection_complaint'] ?? '')) !== ''
                            || trim((string) ($job['inspection_findings'] ?? '')) !== ''
                            || trim((string) ($job['inspection_recommendations'] ?? '')) !== ''
                            || !empty($job['inspection_mileage']);
                        ?>
                        <?php if ($has_inspection): ?>
                            <section class="job-detail-section job-inspection-section">
                                <h3>Service Inspection</h3>
                                <div class="quotation-inspection-record">
                                    <?php if (!empty($job['inspection_mileage'])): ?>
                                        <div>
                                            <span>Current Mileage</span>
                                            <strong><?php echo number_format((int) $job['inspection_mileage']); ?> km</strong>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (trim((string) ($job['inspection_complaint'] ?? '')) !== ''): ?>
                                        <div>
                                            <span>Customer Concern</span>
                                            <p><?php echo nl2br(esc_html($job['inspection_complaint'])); ?></p>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (trim((string) ($job['inspection_findings'] ?? '')) !== ''): ?>
                                        <div>
                                            <span>Inspection Findings</span>
                                            <p><?php echo nl2br(esc_html($job['inspection_findings'])); ?></p>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (trim((string) ($job['inspection_recommendations'] ?? '')) !== ''): ?>
                                        <div>
                                            <span>Recommended Action</span>
                                            <p><?php echo nl2br(esc_html($job['inspection_recommendations'])); ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </section>
                        <?php endif; ?>

                        <section class="job-detail-section">
                            <h3>Services Requested</h3>
                            <?php if (empty($services)): ?>
                                <p class="job-detail-muted">No services listed</p>
                            <?php else: ?>
                                <div class="job-service-chip-list">
                                    <?php foreach ($services as $service): ?>
                                        <span><?php echo esc_html($service); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>

                        <section class="job-detail-section">
                            <h3>Notes</h3>
                            <p><?php echo esc_html($note !== '' ? $note : 'No notes added'); ?></p>
                        </section>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="job-modal-close-btn" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

if (!function_exists('cv_records_load')) {
    function cv_records_load(PDO $pdo, array $customer_ids, $include_archived = false) {
        $customer_ids = array_values(array_unique(array_filter(array_map('intval', $customer_ids))));
        $vehicles_by_customer = [];
        $service_history_by_vehicle = [];
        $ownership_history_by_vehicle = [];
        $availed_items_by_vehicle = [];

        if (empty($customer_ids)) {
            return [$vehicles_by_customer, $service_history_by_vehicle, $ownership_history_by_vehicle, $availed_items_by_vehicle];
        }

        $customer_placeholders = implode(',', array_fill(0, count($customer_ids), '?'));
        $vehicle_status_sql = $include_archived ? '' : "AND v.status = 'active'";
        $vehicle_stmt = $pdo->prepare("
            SELECT v.*, c.name AS customer_name, b.name AS vehicle_branch_name
            FROM vehicles v
            INNER JOIN customers c ON c.id = v.customer_id
            LEFT JOIN branches b ON b.id = v.branch_id
            WHERE v.customer_id IN ($customer_placeholders)
              $vehicle_status_sql
              AND TRIM(CONCAT(COALESCE(v.make, ''), ' ', COALESCE(v.model, ''))) <> ''
              AND COALESCE(NULLIF(TRIM(v.plate_number), ''), '-') <> '-'
            ORDER BY v.customer_id ASC,
                     COALESCE(v.last_service_date, v.updated_at, v.created_at) DESC,
                     v.id DESC
        ");
        $vehicle_stmt->execute($customer_ids);
        $vehicles = $vehicle_stmt->fetchAll();

        $vehicle_ids = [];
        foreach ($vehicles as $vehicle) {
            $customer_id = intval($vehicle['customer_id']);
            $vehicle_id = intval($vehicle['id']);
            $vehicles_by_customer[$customer_id][] = $vehicle;
            $vehicle_ids[] = $vehicle_id;
        }

        $vehicle_ids = array_values(array_unique($vehicle_ids));
        if (empty($vehicle_ids)) {
            return [$vehicles_by_customer, $service_history_by_vehicle, $ownership_history_by_vehicle, $availed_items_by_vehicle];
        }

        $vehicle_placeholders = implode(',', array_fill(0, count($vehicle_ids), '?'));
        $history_stmt = $pdo->prepare("
            SELECT sh.*, b.name AS branch_name
            FROM service_history sh
            LEFT JOIN branches b ON b.id = sh.branch_id
            WHERE sh.vehicle_id IN ($vehicle_placeholders)
            ORDER BY sh.service_date DESC, sh.id DESC
        ");
        $history_stmt->execute($vehicle_ids);

        foreach ($history_stmt->fetchAll() as $entry) {
            $service_history_by_vehicle[intval($entry['vehicle_id'])][] = $entry;
        }

        if (function_exists('app_table_exists') && app_table_exists('vehicle_ownership_history')) {
            try {
                $ownership_stmt = $pdo->prepare("
                    SELECT
                        h.*,
                        c.name AS owner_name,
                        c.phone_mobile,
                        c.contact,
                        c.email
                    FROM vehicle_ownership_history h
                    INNER JOIN customers c ON c.id = h.customer_id
                    WHERE h.vehicle_id IN ($vehicle_placeholders)
                    ORDER BY h.vehicle_id ASC,
                             h.is_current DESC,
                             COALESCE(h.owned_until, '9999-12-31') DESC,
                             COALESCE(h.owned_from, DATE(h.created_at)) DESC,
                             h.id DESC
                ");
                $ownership_stmt->execute($vehicle_ids);

                foreach ($ownership_stmt->fetchAll() as $entry) {
                    $ownership_history_by_vehicle[intval($entry['vehicle_id'])][] = $entry;
                }
            } catch (Exception $e) {
                $ownership_history_by_vehicle = [];
            }
        }

        try {
            $quote_status_sql = $include_archived ? '' : "AND q.status <> 'archived'";
            $items_stmt = $pdo->prepare("
                SELECT
                    q.vehicle_id,
                    q.customer_id,
                    q.id AS quotation_id,
                    q.quotation_number,
                    q.quotation_date AS record_date,
                    q.status AS quotation_status,
                    q.branch_id,
                    c.name AS customer_name,
                    b.name AS branch_name,
                    qi.id AS quotation_item_id,
                    qi.item_name,
                    qi.category,
                    qi.item_type,
                    qi.quantity,
                    qi.unit_price,
                    COALESCE(qi.subtotal, qi.quantity * qi.unit_price) AS line_total,
                    qi.source
                FROM quotations q
                INNER JOIN quotation_items qi ON qi.quotation_id = q.id
                LEFT JOIN customers c ON c.id = q.customer_id
                LEFT JOIN branches b ON b.id = q.branch_id
                WHERE q.vehicle_id IN ($vehicle_placeholders)
                  $quote_status_sql
                ORDER BY q.vehicle_id ASC, q.quotation_date DESC, q.created_at DESC, qi.id ASC
            ");
            $items_stmt->execute($vehicle_ids);

            foreach ($items_stmt->fetchAll() as $item) {
                $availed_items_by_vehicle[intval($item['vehicle_id'])][] = $item;
            }
        } catch (Exception $e) {
            $availed_items_by_vehicle = [];
        }

        return [$vehicles_by_customer, $service_history_by_vehicle, $ownership_history_by_vehicle, $availed_items_by_vehicle];
    }
}

if (!function_exists('cv_records_render_history_modal')) {
    function cv_records_render_history_modal($customer, array $vehicles_by_customer, array $service_history_by_vehicle, array $options = []) {
        $customer_id = intval($customer['id'] ?? 0);
        $vehicles = $vehicles_by_customer[$customer_id] ?? [];
        $first_vehicle = $vehicles[0] ?? null;
        $modal_id = ($options['modal_prefix'] ?? 'customerVehicleModal') . $customer_id;
        $allow_add_vehicle = !empty($options['allow_add_vehicle']);
        $allow_start_service = !empty($options['allow_start_service_operation']);
        $start_service_base_url = trim((string) ($options['start_service_base_url'] ?? '/hwtires/front-desk/quotations/create.php'));
        $allow_delete_vehicle = !empty($options['allow_delete_vehicle']);
        $delete_vehicle_branch_id = (int) ($options['delete_vehicle_branch_id'] ?? 0);
        $delete_vehicle_requires_branch_match = array_key_exists('delete_vehicle_requires_branch_match', $options)
            ? !empty($options['delete_vehicle_requires_branch_match'])
            : true;
        $show_delete_vehicle_action = $allow_delete_vehicle && (!$delete_vehicle_requires_branch_match || $delete_vehicle_branch_id > 0);
        $delete_vehicle_action = trim((string) ($options['delete_vehicle_action'] ?? '/hwtires/api/vehicles-api.php'));
        $delete_vehicle_redirect = (string) ($options['redirect'] ?? '');
        $delete_vehicle_csrf = $allow_delete_vehicle ? generate_csrf_token() : '';
        $ownership_history_by_vehicle = is_array($options['ownership_history_by_vehicle'] ?? null) ? $options['ownership_history_by_vehicle'] : [];
        $availed_items_by_vehicle = is_array($options['availed_items_by_vehicle'] ?? null) ? $options['availed_items_by_vehicle'] : [];
        $session_user = function_exists('app_get_session_user') ? app_get_session_user() : [];
        $vehicle_profile_role = trim((string) ($options['vehicle_profile_role'] ?? ($session_user['role'] ?? 'front-desk')));
        if (!in_array($vehicle_profile_role, ['admin', 'front-desk'], true)) {
            $vehicle_profile_role = 'front-desk';
        }
        $vehicle_profile_base_url = trim((string) ($options['vehicle_profile_base_url'] ?? ('/hwtires/' . $vehicle_profile_role . '/vehicles/profile.php')));
        $branch_options = [];
        $option_branches = $options['branches'] ?? [];

        foreach ($option_branches as $branch) {
            $branch_id = intval($branch['id'] ?? 0);
            if ($branch_id > 0) {
                $branch_name = cv_records_branch_label($branch['name'] ?? ('Branch ' . $branch_id));
                $branch_options[$branch_id] = $branch_name !== '' ? $branch_name : ('Branch ' . $branch_id);
            }
        }

        if (empty($branch_options)) {
            foreach ($vehicles as $vehicle) {
                $histories = $service_history_by_vehicle[intval($vehicle['id'])] ?? [];
                foreach ($histories as $entry) {
                    $branch_id = intval($entry['branch_id'] ?? 0);
                    if ($branch_id <= 0 || isset($branch_options[$branch_id])) {
                        continue;
                    }

                    $branch_name = cv_records_branch_label($entry['branch_name'] ?? ('Branch ' . $branch_id));
                    $branch_options[$branch_id] = $branch_name !== '' ? $branch_name : ('Branch ' . $branch_id);
                }
            }

            asort($branch_options);
        }

        $default_branch_filter = intval($options['default_branch_id'] ?? 0);
        if ($default_branch_filter > 0 && !isset($branch_options[$default_branch_filter])) {
            foreach ($vehicles as $vehicle) {
                $histories = $service_history_by_vehicle[intval($vehicle['id'])] ?? [];
                foreach ($histories as $entry) {
                    if (intval($entry['branch_id'] ?? 0) !== $default_branch_filter) {
                        continue;
                    }

                    $branch_name = cv_records_branch_label($entry['branch_name'] ?? ('Branch ' . $default_branch_filter));
                    $branch_options[$default_branch_filter] = $branch_name !== '' ? $branch_name : ('Branch ' . $default_branch_filter);
                    break 2;
                }
            }
        }

        if ($default_branch_filter > 0 && empty($branch_options[$default_branch_filter])) {
            $default_branch_filter = 0;
        }

        $latest_service_date_by_vehicle = [];
        foreach ($vehicles as $vehicle) {
            $vehicle_id = (int) ($vehicle['id'] ?? 0);
            if ($vehicle_id <= 0) {
                continue;
            }

            foreach (($service_history_by_vehicle[$vehicle_id] ?? []) as $entry) {
                if (!empty($entry['service_date'])) {
                    $latest_service_date_by_vehicle[$vehicle_id] = $entry['service_date'];
                    break;
                }
            }
        }

        $first_vehicle_latest_service_date = $first_vehicle
            ? ($latest_service_date_by_vehicle[(int) ($first_vehicle['id'] ?? 0)] ?? '')
            : '';

        $start_service_url = '';
        if ($allow_start_service && $customer_id > 0 && $start_service_base_url !== '') {
            $start_service_params = ['customer_id' => $customer_id];
            if ($first_vehicle) {
                $start_service_params['vehicle_id'] = (int) ($first_vehicle['id'] ?? 0);
            }
            $start_service_url = $start_service_base_url . '?' . http_build_query($start_service_params);
        }
        ?>
        <div class="modal fade vehicle-history-modal customer-vehicle-history-modal" id="<?php echo esc_attr($modal_id); ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h2>Vehicle Quick History</h2>
                            <p data-history-vehicle-title>
                                <?php echo $first_vehicle ? esc_html(cv_records_vehicle_name($first_vehicle, true)) : 'No vehicle selected'; ?>
                            </p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <!-- Vehicle Title and Branch Header Card (No dropdown) -->
                        <div class="customer-history-vehicle-card">
                            <div class="customer-history-vehicle-info">
                                <h3 class="customer-history-vehicle-title">
                                    <i class="fas fa-car text-primary me-2"></i>
                                    <span data-history-vehicle-display-title><?php echo $first_vehicle ? esc_html(cv_records_vehicle_name($first_vehicle, true)) : 'No vehicle record'; ?></span>
                                </h3>
                                <?php if (!empty($vehicles)): ?>
                                    <?php
                                    $first_vehicle_branch_id = (int) ($first_vehicle['branch_id'] ?? 0);
                                    $first_vehicle_branch_name = cv_records_branch_label($first_vehicle['vehicle_branch_name'] ?? ('Branch ' . $first_vehicle_branch_id));
                                    $first_vehicle_branch_label = $first_vehicle_branch_id > 0 && $first_vehicle_branch_name !== '-' ? $first_vehicle_branch_name : 'Unassigned Branch';
                                    ?>
                                    <span class="customer-history-vehicle-branch-pill <?php echo esc_attr(cv_records_branch_class($first_vehicle_branch_id)); ?>" data-history-vehicle-branch-label>
                                        <?php echo esc_html('Added at ' . $first_vehicle_branch_label); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Hidden select for data binding and JS compatibility -->
                        <select class="customer-history-vehicle-select" data-history-vehicle-select hidden>
                            <?php if (empty($vehicles)): ?>
                                <option value="">No vehicle records</option>
                            <?php else: ?>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <?php
                                    $vehicle_title = cv_records_vehicle_name($vehicle, true);
                                    $vehicle_branch_id = (int) ($vehicle['branch_id'] ?? 0);
                                    $vehicle_branch_name = cv_records_branch_label($vehicle['vehicle_branch_name'] ?? ('Branch ' . $vehicle_branch_id));
                                    $vehicle_branch_label = $vehicle_branch_id > 0 && $vehicle_branch_name !== '-' ? $vehicle_branch_name : 'Unassigned Branch';
                                    $vehicle_owner_name = trim((string) ($vehicle['customer_name'] ?? $customer['name'] ?? ''));
                                    $vehicle_profile_url = $vehicle_profile_base_url . '?id=' . (int) $vehicle['id'];
                                    $vehicle_option_label = $vehicle_title . ' - Current owner: ' . ($vehicle_owner_name !== '' ? $vehicle_owner_name : 'Unassigned');
                                    $vehicle_record_status = strtolower(trim((string) ($vehicle['status'] ?? 'active')));
                                    $vehicle_latest_service_date = $latest_service_date_by_vehicle[(int) ($vehicle['id'] ?? 0)] ?? '';
                                    ?>
                                    <option value="<?php echo (int) $vehicle['id']; ?>"
                                            data-title="<?php echo esc_attr($vehicle_title); ?>"
                                            data-owner-label="<?php echo esc_attr($vehicle_owner_name !== '' ? ('Current owner: ' . $vehicle_owner_name) : 'Current owner: -'); ?>"
                                            data-last-service-label="<?php echo esc_attr($vehicle_latest_service_date !== '' ? ('Last service: ' . cv_records_short_date($vehicle_latest_service_date)) : 'Last service: -'); ?>"
                                            data-branch-label="<?php echo esc_attr('Added at ' . $vehicle_branch_label); ?>"
                                            data-branch-class="<?php echo esc_attr(cv_records_branch_class($vehicle_branch_id)); ?>"
                                            data-profile-url="<?php echo esc_attr($vehicle_profile_url); ?>"
                                            data-vehicle-status="<?php echo esc_attr($vehicle_record_status); ?>"
                                            data-vehicle-branch-id="<?php echo $vehicle_branch_id; ?>">
                                        <?php echo esc_html($vehicle_option_label); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>

                        <!-- Clean, Horizontal Non-Stacking Filter Bar -->
                        <div class="customer-history-filter-bar">
                            <div class="customer-history-filter-item">
                                <label>Branch</label>
                                <select class="customer-history-branch-select" data-history-branch-select>
                                    <option value="">All Branches</option>
                                    <?php foreach ($branch_options as $branch_id => $branch_name): ?>
                                        <option value="<?php echo (int) $branch_id; ?>" <?php echo $default_branch_filter === (int) $branch_id ? 'selected' : ''; ?>>
                                            <?php echo esc_html($branch_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="customer-history-filter-item">
                                <label>Period</label>
                                <select class="customer-history-date-scope" data-history-scope>
                                    <option value="all">All Records</option>
                                    <option value="recent">Current Week</option>
                                    <option value="day">Day</option>
                                    <option value="week">Week</option>
                                    <option value="month">Month</option>
                                    <option value="year">Year</option>
                                    <option value="range">Date Range</option>
                                </select>
                            </div>

                            <div class="customer-history-filter-dates" data-history-date-inputs-wrapper>
                                <input type="date" data-history-date-input="day" hidden>
                                <input type="week" data-history-date-input="week" hidden>
                                <input type="month" data-history-date-input="month" hidden>
                                <input type="number" min="2000" max="2100" placeholder="Year" data-history-date-input="year" hidden>
                                <input type="date" data-history-date-input="from" hidden>
                                <input type="date" data-history-date-input="to" hidden>
                            </div>

                            <button type="button" class="customer-history-apply-btn" data-history-apply>Apply</button>
                        </div>

                        <div class="customer-history-selected-summary" data-history-selected-summary>
                            <?php if ($first_vehicle): ?>
                                <span class="customer-history-summary-pill" data-history-owner-label>
                                    <?php echo esc_html('Current owner: ' . (($first_vehicle['customer_name'] ?? '') ?: ($customer['name'] ?? '-'))); ?>
                                </span>
                            <?php else: ?>
                                <span class="customer-history-summary-pill" data-history-owner-label>Current owner: -</span>
                            <?php endif; ?>
                            <span class="customer-history-summary-pill" data-history-last-service-label <?php echo $first_vehicle ? '' : 'hidden'; ?>>
                                <?php echo esc_html($first_vehicle_latest_service_date !== '' ? ('Last service: ' . cv_records_short_date($first_vehicle_latest_service_date)) : 'Last service: -'); ?>
                            </span>
                        </div>

                        <?php if (empty($vehicles)): ?>
                            <div class="vehicle-history-empty" data-history-empty>No vehicle records found for this customer.</div>
                        <?php else: ?>
                            <div class="customer-history-tabs" role="tablist" aria-label="Vehicle quick history sections">
                                <button type="button" class="customer-history-tab active" data-history-tab="services" aria-selected="true">
                                    <i class="fas fa-history"></i>
                                    <span>Service History</span>
                                </button>
                                <button type="button" class="customer-history-tab" data-history-tab="ownership" aria-selected="false">
                                    <i class="fas fa-users"></i>
                                    <span>Ownership History</span>
                                </button>
                                <button type="button" class="customer-history-tab" data-history-tab="items" aria-selected="false">
                                    <i class="fas fa-box-open"></i>
                                    <span>Service Operations &amp; Items</span>
                                </button>
                                <button type="button" class="customer-history-tab" data-history-tab="details" aria-selected="false">
                                    <i class="fas fa-car"></i>
                                    <span>Vehicle Details</span>
                                </button>
                            </div>

                            <section class="customer-history-tab-panel active" data-history-panel="services">
                                <div class="vehicle-history-list">
                                    <?php foreach ($vehicles as $vehicle): ?>
                                        <?php
                                        $histories = $service_history_by_vehicle[intval($vehicle['id'])] ?? [];
                                        foreach ($histories as $entry):
                                            $service_date = cv_records_short_date($entry['service_date'] ?? '');
                                            $services = cv_records_services($entry['services_description'] ?? 'Service');
                                        ?>
                                            <article class="vehicle-history-entry"
                                                     data-history-entry
                                                     data-vehicle-id="<?php echo (int) $vehicle['id']; ?>"
                                                     data-branch-id="<?php echo (int) ($entry['branch_id'] ?? 0); ?>"
                                                     data-service-date="<?php echo esc_attr($service_date); ?>">
                                                <div class="vehicle-history-top">
                                                    <div>
                                                        <h3><?php echo esc_html($service_date); ?></h3>
                                                        <p><?php echo esc_html(cv_records_branch_label($entry['branch_name'] ?? '')); ?></p>
                                                    </div>
                                                    <div class="vehicle-history-cost">
                                                        <strong><?php echo cv_records_money($entry['total_cost'] ?? 0); ?></strong>
                                                        <span>
                                                            <?php echo !empty($entry['mileage_at_service']) ? number_format((float) $entry['mileage_at_service']) . ' km' : '-'; ?>
                                                        </span>
                                                    </div>
                                                </div>

                                                <h4>Services Performed:</h4>
                                                <div class="vehicle-history-chips">
                                                    <?php if (empty($services)): ?>
                                                        <span>Service</span>
                                                    <?php else: ?>
                                                        <?php foreach ($services as $service): ?>
                                                            <span><?php echo esc_html($service); ?></span>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </div>

                                                <?php if (!empty($entry['notes'])): ?>
                                                    <div class="vehicle-history-notes">
                                                        <strong>Notes:</strong>
                                                        <span><?php echo esc_html(app_format_record_notes($entry['notes'])); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </article>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </div>
                                <div class="vehicle-history-empty" data-history-empty hidden>No service history found for this vehicle, branch, and date filter.</div>
                            </section>

                            <section class="customer-history-tab-panel" data-history-panel="ownership">
                                <div class="customer-ownership-list">
                                    <?php foreach ($vehicles as $vehicle): ?>
                                        <?php
                                        $vehicle_id = (int) $vehicle['id'];
                                        $ownership_entries = $ownership_history_by_vehicle[$vehicle_id] ?? [];
                                        if (empty($ownership_entries)) {
                                            $ownership_entries = [[
                                                'vehicle_id' => $vehicle_id,
                                                'owner_name' => ($vehicle['customer_name'] ?? $customer['name'] ?? '-'),
                                                'owned_from' => $vehicle['created_at'] ?? null,
                                                'owned_until' => null,
                                                'is_current' => 1,
                                                'transfer_notes' => 'Current owner from vehicle record',
                                            ]];
                                        }
                                        foreach ($ownership_entries as $ownership):
                                            $owned_from = cv_records_short_date($ownership['owned_from'] ?? '');
                                            $owned_until = !empty($ownership['owned_until']) ? cv_records_short_date($ownership['owned_until']) : 'Present';
                                            $is_current_owner = (int) ($ownership['is_current'] ?? 0) === 1;
                                        ?>
                                            <article class="customer-ownership-entry"
                                                     data-ownership-entry
                                                     data-vehicle-id="<?php echo $vehicle_id; ?>">
                                                <span class="customer-ownership-marker <?php echo $is_current_owner ? 'current' : ''; ?>"></span>
                                                <div>
                                                    <div class="customer-ownership-top">
                                                        <h3><?php echo esc_html($ownership['owner_name'] ?? '-'); ?></h3>
                                                        <span class="customer-history-status-pill <?php echo $is_current_owner ? 'status-current' : 'status-previous'; ?>">
                                                            <?php echo $is_current_owner ? 'Current Owner' : 'Previous Owner'; ?>
                                                        </span>
                                                    </div>
                                                    <p><?php echo esc_html($owned_from . ' to ' . $owned_until); ?></p>
                                                    <?php if (!empty($ownership['transfer_notes'])): ?>
                                                        <small><?php echo esc_html($ownership['transfer_notes']); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </article>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </div>
                                <div class="vehicle-history-empty" data-ownership-empty hidden>No ownership history found for this vehicle.</div>
                            </section>

                            <section class="customer-history-tab-panel" data-history-panel="items">
                                <div class="customer-items-list">
                                    <?php foreach ($vehicles as $vehicle): ?>
                                        <?php
                                        $vehicle_id = (int) $vehicle['id'];
                                        $availed_items = $availed_items_by_vehicle[$vehicle_id] ?? [];
                                        $availed_item_groups = cv_records_group_availed_items($availed_items);
                                        foreach ($availed_item_groups as $item_group):
                                            $record_date = cv_records_short_date($item_group['record_date'] ?? '');
                                            $group_items = $item_group['items'] ?? [];
                                            $quotation_number = trim((string) ($item_group['quotation_number'] ?? ''));
                                            $source_labels = array_keys($item_group['source_labels'] ?? []);
                                            $type_labels = array_keys($item_group['type_labels'] ?? []);
                                            $status_labels = $item_group['status_labels'] ?? [];
                                            $status_keys = array_keys($status_labels);
                                            $group_status_key = count($status_keys) === 1 ? $status_keys[0] : '';
                                            $group_status_label = count($status_labels) === 1 ? reset($status_labels) : '';
                                            $group_source_label = count($source_labels) === 1 ? $source_labels[0] : 'Service Operations & Items';
                                            $group_type_label = count($type_labels) === 1 ? $type_labels[0] : 'Mixed Items';
                                            $group_title = $quotation_number !== '' ? 'Service Operation ' . $quotation_number : 'Sales Record';
                                        ?>
                                            <article class="customer-item-entry customer-item-group-entry"
                                                     data-item-entry
                                                     data-vehicle-id="<?php echo $vehicle_id; ?>"
                                                     data-branch-id="<?php echo (int) ($item_group['branch_id'] ?? 0); ?>"
                                                     data-record-date="<?php echo esc_attr($record_date); ?>">
                                                <div class="customer-item-main customer-item-group-main">
                                                    <div class="customer-item-group-head">
                                                        <div>
                                                            <h3><?php echo esc_html($group_title); ?></h3>
                                                            <p>
                                                                <?php echo esc_html($record_date); ?>
                                                                &bull; <?php echo esc_html($item_group['customer_name'] ?? '-'); ?>
                                                                &bull; <?php echo esc_html(cv_records_branch_label($item_group['branch_name'] ?? '')); ?>
                                                            </p>
                                                            <small><?php echo count($group_items); ?> <?php echo count($group_items) === 1 ? 'item' : 'items'; ?> under this reference</small>
                                                        </div>
                                                        <span class="customer-history-status-pill">
                                                            <?php echo esc_html($group_source_label); ?>
                                                        </span>
                                                    </div>

                                                    <div class="customer-item-lines">
                                                        <?php foreach ($group_items as $item): ?>
                                                            <?php
                                                            $item_type = $item['item_type'] ?? 'item';
                                                            $line_quantity = max(1, (int) ($item['quantity'] ?? 1));
                                                            ?>
                                                            <div class="customer-item-line">
                                                                <div>
                                                                    <strong><?php echo esc_html(app_display_item_name($item['item_name'] ?? 'Item', $item['item_type'] ?? null)); ?></strong>
                                                                    <span><?php echo esc_html(cv_records_item_type_label($item_type)); ?></span>
                                                                </div>
                                                                <div class="customer-item-line-price">
                                                                    <span>x<?php echo $line_quantity; ?></span>
                                                                    <strong><?php echo cv_records_money(cv_records_line_total($item)); ?></strong>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                                <div class="customer-item-side customer-item-group-side">
                                                    <?php if ($group_status_label !== ''): ?>
                                                        <span class="customer-history-status-pill status-<?php echo esc_attr($group_status_key); ?>">
                                                            <?php echo esc_html($group_status_label); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="customer-history-status-pill">
                                                            <?php echo esc_html($group_type_label); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <strong><?php echo count($group_items); ?> <?php echo count($group_items) === 1 ? 'line' : 'lines'; ?></strong>
                                                    <span><?php echo cv_records_money($item_group['total'] ?? 0); ?></span>
                                                </div>
                                            </article>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </div>
                                <div class="vehicle-history-empty" data-items-empty hidden>No service operation or item records found for this vehicle, branch, and date filter.</div>
                            </section>

                            <section class="customer-history-tab-panel" data-history-panel="details">
                                <div class="customer-vehicle-detail-grid">
                                    <?php foreach ($vehicles as $vehicle): ?>
                                        <?php $vehicle_id = (int) $vehicle['id']; ?>
                                        <article class="customer-vehicle-detail-card" data-vehicle-detail data-vehicle-id="<?php echo $vehicle_id; ?>">
                                            <div>
                                                <span>Current Owner</span>
                                                <strong><?php echo esc_html(($vehicle['customer_name'] ?? '') ?: ($customer['name'] ?? '-')); ?></strong>
                                            </div>
                                            <div>
                                                <span>Plate Number</span>
                                                <strong><?php echo esc_html($vehicle['plate_number'] ?? '-'); ?></strong>
                                            </div>
                                            <div>
                                                <span>Make / Model</span>
                                                <strong><?php echo esc_html(trim(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? '')) ?: '-'); ?></strong>
                                            </div>
                                            <div>
                                                <span>Year / Color</span>
                                                <strong><?php echo esc_html(trim(($vehicle['year'] ?? '-') . ' / ' . (($vehicle['color'] ?? '') ?: '-'))); ?></strong>
                                            </div>
                                            <div>
                                                <span>Last Mileage</span>
                                                <strong><?php echo !empty($vehicle['last_mileage']) ? number_format((float) $vehicle['last_mileage']) . ' km' : '-'; ?></strong>
                                            </div>
                                            <div>
                                                <span>Last Service</span>
                                                <strong><?php echo esc_html(cv_records_short_date($latest_service_date_by_vehicle[$vehicle_id] ?? '')); ?></strong>
                                            </div>
                                            <div>
                                                <span>Status</span>
                                                <strong><?php echo esc_html(ucfirst($vehicle['status'] ?? 'active')); ?></strong>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                                <div class="vehicle-history-empty" data-details-empty hidden>No vehicle details found.</div>
                            </section>
                        <?php endif; ?>
                    </div>

                    <div class="modal-footer">
                        <?php if ($first_vehicle): ?>
                            <a href="<?php echo esc_attr($vehicle_profile_base_url . '?id=' . (int) ($first_vehicle['id'] ?? 0)); ?>"
                               class="customer-history-open-profile"
                               data-history-open-profile
                               data-base-url="<?php echo esc_attr($vehicle_profile_base_url); ?>">
                                <i class="fas fa-up-right-from-square"></i>
                                <span>Open Vehicle Profile</span>
                            </a>
                        <?php endif; ?>
                        <?php if ($start_service_url !== ''): ?>
                            <a href="<?php echo esc_attr($start_service_url); ?>"
                               class="customer-history-start-service"
                               data-history-start-service
                               data-base-url="<?php echo esc_attr($start_service_base_url); ?>"
                               data-customer-id="<?php echo $customer_id; ?>">
                                <i class="fas fa-plus"></i>
                                <span>Start Service Operation</span>
                            </a>
                        <?php endif; ?>
                        <button type="button" class="vehicle-history-close" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

if (!function_exists('cv_records_render_history_script')) {
    function cv_records_render_history_script() {
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            function asDate(value) {
                if (!value || value === '-') return null;
                const parsed = new Date(value + 'T00:00:00');
                return Number.isNaN(parsed.getTime()) ? null : parsed;
            }

            function startOfCurrentWeek() {
                const today = new Date();
                const day = (today.getDay() + 6) % 7;
                const start = new Date(today);
                start.setHours(0, 0, 0, 0);
                start.setDate(today.getDate() - day);
                return start;
            }

            function endOfDate(date) {
                const end = new Date(date);
                end.setHours(23, 59, 59, 999);
                return end;
            }

            function weekRange(value) {
                const match = String(value || '').match(/^(\d{4})-W(\d{2})$/);
                if (!match) return null;
                const year = parseInt(match[1], 10);
                const week = parseInt(match[2], 10);
                const jan4 = new Date(year, 0, 4);
                const jan4Day = (jan4.getDay() + 6) % 7;
                const start = new Date(jan4);
                start.setDate(jan4.getDate() - jan4Day + ((week - 1) * 7));
                start.setHours(0, 0, 0, 0);
                const end = new Date(start);
                end.setDate(start.getDate() + 6);
                return [start, endOfDate(end)];
            }

            document.querySelectorAll('.customer-vehicle-history-modal').forEach(function(modal) {
                const vehicleSelect = modal.querySelector('[data-history-vehicle-select]');
                const branchSelect = modal.querySelector('[data-history-branch-select]');
                const title = modal.querySelector('[data-history-vehicle-title]');
                const vehicleBranchLabel = modal.querySelector('[data-history-vehicle-branch-label]');
                const ownerLabel = modal.querySelector('[data-history-owner-label]');
                const lastServiceLabel = modal.querySelector('[data-history-last-service-label]');
                const openProfile = modal.querySelector('[data-history-open-profile]');
                const startService = modal.querySelector('[data-history-start-service]');
                const deleteVehicleForm = modal.querySelector('[data-history-delete-vehicle-form]');
                const deleteVehicleId = modal.querySelector('[data-history-delete-vehicle-id]');
                const scope = modal.querySelector('[data-history-scope]');
                const apply = modal.querySelector('[data-history-apply]');
                const tabs = Array.from(modal.querySelectorAll('[data-history-tab]'));
                const panels = Array.from(modal.querySelectorAll('[data-history-panel]'));
                const entries = Array.from(modal.querySelectorAll('[data-history-entry]'));
                const empty = modal.querySelector('[data-history-empty]');
                const ownershipEntries = Array.from(modal.querySelectorAll('[data-ownership-entry]'));
                const ownershipEmpty = modal.querySelector('[data-ownership-empty]');
                const itemEntries = Array.from(modal.querySelectorAll('[data-item-entry]'));
                const itemsEmpty = modal.querySelector('[data-items-empty]');
                const detailCards = Array.from(modal.querySelectorAll('[data-vehicle-detail]'));
                const detailsEmpty = modal.querySelector('[data-details-empty]');
                const inputs = Array.from(modal.querySelectorAll('[data-history-date-input]'));

                function input(name) {
                    return modal.querySelector('[data-history-date-input="' + name + '"]');
                }

                function syncDateInputs() {
                    const selectedScope = scope ? scope.value : 'all';
                    inputs.forEach(function(field) {
                        const type = field.getAttribute('data-history-date-input');
                        const visible = type === selectedScope || (selectedScope === 'range' && (type === 'from' || type === 'to'));
                        field.hidden = !visible;
                    });
                }

                function dateMatches(dateValue) {
                    const selectedScope = scope ? scope.value : 'all';
                    if (selectedScope === 'all') return true;

                    const date = asDate(dateValue);
                    if (!date) return false;

                    if (selectedScope === 'recent') {
                        const start = startOfCurrentWeek();
                        const end = endOfDate(new Date());
                        return date >= start && date <= end;
                    }

                    if (selectedScope === 'day') {
                        const selected = asDate(input('day') ? input('day').value : '');
                        return selected ? date.toDateString() === selected.toDateString() : true;
                    }

                    if (selectedScope === 'week') {
                        const bounds = weekRange(input('week') ? input('week').value : '');
                        return bounds ? date >= bounds[0] && date <= bounds[1] : true;
                    }

                    if (selectedScope === 'month') {
                        const value = input('month') ? input('month').value : '';
                        return value ? dateValue.slice(0, 7) === value : true;
                    }

                    if (selectedScope === 'year') {
                        const value = input('year') ? input('year').value : '';
                        return value ? dateValue.slice(0, 4) === String(value) : true;
                    }

                    if (selectedScope === 'range') {
                        const from = asDate(input('from') ? input('from').value : '');
                        const to = asDate(input('to') ? input('to').value : '');
                        if (from && date < from) return false;
                        if (to && date > endOfDate(to)) return false;
                        return true;
                    }

                    return true;
                }

                function applyFilter() {
                    const selectedVehicle = vehicleSelect ? vehicleSelect.value : '';
                    const selectedBranch = branchSelect ? branchSelect.value : '';
                    let visibleCount = 0;
                    let ownershipCount = 0;
                    let itemCount = 0;
                    let detailCount = 0;

                    if (vehicleSelect && title) {
                        const option = vehicleSelect.selectedOptions[0];
                        const displayTitle = modal.querySelector('[data-history-vehicle-display-title]');
                        if (displayTitle && option) {
                            displayTitle.textContent = option.dataset.title || option.textContent.trim();
                        }
                        title.textContent = option ? (option.dataset.title || option.textContent.trim()) : 'No vehicle selected';

                        if (ownerLabel) {
                            ownerLabel.textContent = option ? (option.dataset.ownerLabel || 'Current owner: -') : 'Current owner: -';
                        }

                        if (lastServiceLabel) {
                            lastServiceLabel.textContent = option ? (option.dataset.lastServiceLabel || 'Last service: -') : 'Last service: -';
                            lastServiceLabel.hidden = !option;
                        }

                        if (vehicleBranchLabel) {
                            vehicleBranchLabel.textContent = option ? (option.dataset.branchLabel || '') : '';
                            vehicleBranchLabel.className = 'customer-history-vehicle-branch-pill ' + (option ? (option.dataset.branchClass || 'customer-branch-empty') : 'customer-branch-empty');
                            vehicleBranchLabel.hidden = !option || !option.dataset.branchLabel;
                        }

                        if (openProfile) {
                            const profileUrl = option ? (option.dataset.profileUrl || '') : '';
                            if (profileUrl) {
                                openProfile.href = profileUrl;
                                openProfile.hidden = false;
                            } else {
                                openProfile.hidden = true;
                            }
                        }

                        if (startService) {
                            const vehicleStatus = option ? (option.dataset.vehicleStatus || 'active') : 'active';
                            const url = new URL(startService.dataset.baseUrl || startService.getAttribute('href'), window.location.origin);
                            url.searchParams.set('customer_id', startService.dataset.customerId || '');
                            if (option && option.value) {
                                url.searchParams.set('vehicle_id', option.value);
                            } else {
                                url.searchParams.delete('vehicle_id');
                            }
                            startService.href = url.pathname + url.search;
                            startService.hidden = vehicleStatus === 'inactive';
                        }

                        if (deleteVehicleForm) {
                            const ownerBranchId = deleteVehicleForm.dataset.branchId || '';
                            const vehicleBranchId = option ? (option.dataset.vehicleBranchId || '') : '';
                            const vehicleStatus = option ? (option.dataset.vehicleStatus || 'active') : 'active';
                            const requiresBranchMatch = deleteVehicleForm.dataset.requiresBranchMatch !== '0';
                            const canDelete = !!(option && option.value && vehicleStatus !== 'inactive' && (!requiresBranchMatch || (ownerBranchId && vehicleBranchId === ownerBranchId)));

                            deleteVehicleForm.hidden = !canDelete;
                            if (deleteVehicleId) {
                                deleteVehicleId.value = canDelete ? option.value : '';
                            }
                        }
                    }

                    entries.forEach(function(entry) {
                        const vehicleMatches = !selectedVehicle || entry.dataset.vehicleId === selectedVehicle;
                        const branchMatches = !selectedBranch || entry.dataset.branchId === selectedBranch;
                        const visible = vehicleMatches && branchMatches && dateMatches(entry.dataset.serviceDate || '');
                        entry.hidden = !visible;
                        if (visible) visibleCount++;
                    });

                    if (empty) {
                        empty.hidden = visibleCount > 0;
                    }

                    ownershipEntries.forEach(function(entry) {
                        const vehicleMatches = !selectedVehicle || entry.dataset.vehicleId === selectedVehicle;
                        entry.hidden = !vehicleMatches;
                        if (vehicleMatches) ownershipCount++;
                    });

                    if (ownershipEmpty) {
                        ownershipEmpty.hidden = ownershipCount > 0;
                    }

                    itemEntries.forEach(function(entry) {
                        const vehicleMatches = !selectedVehicle || entry.dataset.vehicleId === selectedVehicle;
                        const branchMatches = !selectedBranch || entry.dataset.branchId === selectedBranch;
                        const visible = vehicleMatches && branchMatches && dateMatches(entry.dataset.recordDate || '');
                        entry.hidden = !visible;
                        if (visible) itemCount++;
                    });

                    if (itemsEmpty) {
                        itemsEmpty.hidden = itemCount > 0;
                    }

                    detailCards.forEach(function(card) {
                        const vehicleMatches = !selectedVehicle || card.dataset.vehicleId === selectedVehicle;
                        card.hidden = !vehicleMatches;
                        if (vehicleMatches) detailCount++;
                    });

                    if (detailsEmpty) {
                        detailsEmpty.hidden = detailCount > 0;
                    }
                }

                tabs.forEach(function(tab) {
                    tab.addEventListener('click', function() {
                        const target = tab.dataset.historyTab || 'services';
                        tabs.forEach(function(item) {
                            const active = item === tab;
                            item.classList.toggle('active', active);
                            item.setAttribute('aria-selected', active ? 'true' : 'false');
                        });
                        panels.forEach(function(panel) {
                            panel.classList.toggle('active', panel.dataset.historyPanel === target);
                        });
                    });
                });

                if (scope) {
                    scope.addEventListener('change', function() {
                        syncDateInputs();
                        applyFilter();
                    });
                }

                if (vehicleSelect) {
                    vehicleSelect.addEventListener('change', applyFilter);
                }

                if (branchSelect) {
                    branchSelect.addEventListener('change', applyFilter);
                }

                if (apply) {
                    apply.addEventListener('click', applyFilter);
                }

                inputs.forEach(function(field) {
                    field.addEventListener('change', applyFilter);
                });

                modal.addEventListener('show.bs.modal', function(event) {
                    const requestedVehicleId = event.relatedTarget ? (event.relatedTarget.dataset.historyVehicleId || '') : '';
                    if (requestedVehicleId && vehicleSelect) {
                        vehicleSelect.value = requestedVehicleId;
                    }
                });

                modal.addEventListener('shown.bs.modal', function() {
                    syncDateInputs();
                    applyFilter();
                });

                syncDateInputs();
                applyFilter();
            });
        });
        </script>
        <?php
    }
}
