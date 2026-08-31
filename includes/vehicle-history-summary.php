<?php
/**
 * Shared Vehicle History Summary report for admin and front-desk users.
 */

require_once __DIR__ . '/line-item-display.php';

$user = isset($user) && is_array($user) ? $user : app_get_session_user();
$history_role = ($user['role'] ?? '') === 'admin' ? 'admin' : 'front-desk';
$vehicle_id = max(0, intval($_GET['id'] ?? 0));

if ($vehicle_id <= 0) {
    redirect('/hwtires/' . $history_role . '/vehicles/');
}

if (!function_exists('vehicle_history_summary_date')) {
    function vehicle_history_summary_date($date, $format = 'M d, Y') {
        if (empty($date) || $date === '0000-00-00') {
            return '-';
        }

        $timestamp = strtotime((string) $date);
        return $timestamp ? date($format, $timestamp) : '-';
    }
}

if (!function_exists('vehicle_history_summary_time')) {
    function vehicle_history_summary_time($time) {
        if (empty($time)) {
            return '';
        }

        $timestamp = strtotime((string) $time);
        return $timestamp ? date('H:i', $timestamp) : '';
    }
}

if (!function_exists('vehicle_history_summary_time_range')) {
    function vehicle_history_summary_time_range($start, $end) {
        $start = vehicle_history_summary_time($start);
        $end = vehicle_history_summary_time($end);

        if ($start !== '' && $end !== '') {
            return $start . ' - ' . $end;
        }

        return $start !== '' ? $start : ($end !== '' ? $end : '-');
    }
}

if (!function_exists('vehicle_history_summary_branch_label')) {
    function vehicle_history_summary_branch_label($branch_name, $fallback = 'No branch') {
        return function_exists('app_branch_label')
            ? app_branch_label($branch_name, $fallback)
            : (trim((string) $branch_name) !== '' ? trim((string) $branch_name) : $fallback);
    }
}

if (!function_exists('vehicle_history_summary_vehicle_name')) {
    function vehicle_history_summary_vehicle_name(array $vehicle) {
        $name = trim((string) (($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? '')));
        return $name !== '' ? $name : 'Vehicle';
    }
}

if (!function_exists('vehicle_history_summary_status_label')) {
    function vehicle_history_summary_status_label($status) {
        $status = trim((string) $status);
        return $status !== '' ? ucwords(str_replace(['-', '_'], ' ', $status)) : '-';
    }
}

if (!function_exists('vehicle_history_summary_status_class')) {
    function vehicle_history_summary_status_class($status) {
        $status = strtolower(trim((string) $status));

        if (in_array($status, ['completed', 'approved', 'active', 'tagged'], true)) {
            return 'success';
        }

        if (in_array($status, ['pending', 'waiting'], true)) {
            return 'warning';
        }

        if (in_array($status, ['in-progress', 'ongoing'], true)) {
            return 'info';
        }

        if (in_array($status, ['rejected', 'cancelled'], true)) {
            return 'danger';
        }

        return 'neutral';
    }
}

if (!function_exists('vehicle_history_summary_split_lines')) {
    function vehicle_history_summary_split_lines($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:;;|\n|,)\s*/', $value, -1, PREG_SPLIT_NO_EMPTY);
        $lines = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '' && !in_array($part, $lines, true)) {
                $lines[] = $part;
            }
        }

        return $lines;
    }
}

if (!function_exists('vehicle_history_summary_push_unique')) {
    function vehicle_history_summary_push_unique(array &$values, $value) {
        $value = trim((string) $value);
        if ($value !== '' && !in_array($value, $values, true)) {
            $values[] = $value;
        }
    }
}

if (!function_exists('vehicle_history_summary_compact_list')) {
    function vehicle_history_summary_compact_list(array $lines, $limit = 3, $empty = '-') {
        $lines = array_values(array_filter(array_map('trim', $lines)));
        if (empty($lines)) {
            return $empty;
        }

        if (count($lines) <= $limit) {
            return implode('; ', $lines);
        }

        return implode('; ', array_slice($lines, 0, $limit)) . ' +' . (count($lines) - $limit) . ' more';
    }
}

if (!function_exists('vehicle_history_summary_item_line')) {
    function vehicle_history_summary_item_line(array $item, array $inventory_items_by_id) {
        $name = trim((string) ($item['item_name'] ?? 'Item'));
        $quantity = max(1, (int) ($item['quantity'] ?? 1));
        return ($name !== '' ? $name : 'Item') . ' (' . $quantity . 'x)';
    }
}

if (!function_exists('vehicle_history_summary_item_summaries')) {
    function vehicle_history_summary_item_summaries(array $items_by_quotation, array $inventory_items_by_id) {
        $summaries = [];

        foreach ($items_by_quotation as $quotation_id => $items) {
            $summary = [
                'services' => [],
                'products' => [],
                'product_rows' => [],
            ];

            foreach ($items as $item) {
                $name = trim((string) ($item['item_name'] ?? ''));
                if (function_exists('app_display_item_name')) {
                    $name = app_display_item_name($name);
                }
                if ($name === '') {
                    $name = 'Item';
                }

                if (app_line_item_is_service($item)) {
                    vehicle_history_summary_push_unique($summary['services'], $name);
                    continue;
                }

                $quantity = max(1, (int) ($item['quantity'] ?? 1));
                $details = app_line_item_detail_text($item, $inventory_items_by_id, ['include_type' => false]);
                $line = $name . ' (' . $quantity . 'x)';
                vehicle_history_summary_push_unique($summary['products'], $line);
                $summary['product_rows'][] = [
                    'name' => $name,
                    'details' => $details,
                    'category' => app_line_item_type_label($item),
                    'quantity' => $quantity,
                    'unit_price' => (float) ($item['unit_price'] ?? 0),
                    'line' => $line,
                ];
            }

            $summaries[(int) $quotation_id] = $summary;
        }

        return $summaries;
    }
}

if (!function_exists('vehicle_history_summary_merge_inventory_transactions')) {
    function vehicle_history_summary_merge_inventory_transactions(array &$summary, array $transactions) {
        if (empty($transactions)) {
            return;
        }

        foreach ($transactions as $transaction) {
            // Exclude inter-branch transfers from customer vehicle history
            $ref_type = strtolower(trim((string) ($transaction['reference_type'] ?? '')));
            if (in_array($ref_type, ['inter_branch_transfer', 'transfer'], true)) {
                continue;
            }

            $name = trim((string) ($transaction['item_name'] ?? 'Inventory Item'));
            $name = $name !== '' ? $name : 'Inventory Item';

            $quantity = max(1, (int) ($transaction['quantity'] ?? 1));
            $details = function_exists('app_inventory_transaction_detail_text')
                ? app_inventory_transaction_detail_text($transaction)
                : '';

            // Check if this item is already recorded in product_rows
            $matched_index = -1;
            foreach (($summary['product_rows'] ?? []) as $idx => $existing_row) {
                $exist_name = strtolower(trim((string) ($existing_row['name'] ?? '')));
                $curr_name = strtolower(trim($name));
                if ($exist_name === $curr_name || strpos($exist_name, $curr_name) !== false || strpos($curr_name, $exist_name) !== false) {
                    $matched_index = $idx;
                    break;
                }
            }

            if ($matched_index >= 0) {
                if (empty($summary['product_rows'][$matched_index]['details']) && $details !== '') {
                    $summary['product_rows'][$matched_index]['details'] = $details;
                }
                continue;
            }

            $line = $name . ' (' . $quantity . 'x)';
            vehicle_history_summary_push_unique($summary['products'], $line);
            $summary['product_rows'][] = [
                'name' => $name,
                'details' => $details,
                'category' => trim((string) ($transaction['category'] ?? 'Inventory')),
                'quantity' => $quantity,
                'unit_price' => (float) ($transaction['unit_price'] ?? 0),
                'line' => $line,
            ];
        }
    }
}

if (!function_exists('vehicle_history_summary_amount')) {
    function vehicle_history_summary_amount(array $values) {
        foreach ($values as $value) {
            $amount = (float) ($value ?? 0);
            if ($amount > 0) {
                return $amount;
            }
        }

        return 0.0;
    }
}

if (!function_exists('vehicle_history_summary_reference')) {
    function vehicle_history_summary_reference($prefix, $id) {
        $id = intval($id);
        return $id > 0 ? $prefix . str_pad((string) $id, 6, '0', STR_PAD_LEFT) : '-';
    }
}

$vehicle = null;
$ownership_history = [];
$quotations = [];
$job_orders = [];
$service_history = [];
$timeline_rows = [];
$product_rows = [];
$branch_summary = [];
$total_recorded = 0.0;
$product_quantity_total = 0;

try {
    $vehicle_stmt = $pdo->prepare("
        SELECT v.*, c.name AS customer_name, c.phone_mobile, c.contact, c.address, c.city,
               vb.name AS vehicle_branch_name
        FROM vehicles v
        LEFT JOIN customers c ON v.customer_id = c.id
        LEFT JOIN branches vb ON vb.id = v.branch_id
        WHERE v.id = ?
    ");
    $vehicle_stmt->execute([$vehicle_id]);
    $vehicle = $vehicle_stmt->fetch();

    if (!$vehicle) {
        redirect('/hwtires/' . $history_role . '/vehicles/');
    }

    if (app_table_exists('vehicle_ownership_history')) {
        $ownership_stmt = $pdo->prepare("
            SELECT h.*, c.name AS owner_name, c.phone_mobile, c.contact
            FROM vehicle_ownership_history h
            INNER JOIN customers c ON c.id = h.customer_id
            WHERE h.vehicle_id = ?
            ORDER BY h.is_current DESC,
                     COALESCE(h.owned_until, '9999-12-31') DESC,
                     h.owned_from DESC,
                     h.id DESC
        ");
        $ownership_stmt->execute([$vehicle_id]);
        $ownership_history = $ownership_stmt->fetchAll();
    }

    if (empty($ownership_history)) {
        $ownership_history[] = [
            'owner_name' => $vehicle['customer_name'] ?? '-',
            'phone_mobile' => $vehicle['phone_mobile'] ?? '',
            'contact' => $vehicle['contact'] ?? '',
            'owned_from' => $vehicle['created_at'] ?? null,
            'owned_until' => null,
            'is_current' => 1,
            'transfer_notes' => 'Current owner from vehicle record',
        ];
    }

    $include_archived_records = strtolower((string) ($vehicle['status'] ?? 'active')) === 'inactive';
    $quotation_status_sql = $include_archived_records ? '' : "AND q.status <> 'archived'";
    $job_status_sql = $include_archived_records ? "AND jo.status <> 'cancelled'" : "AND jo.status NOT IN ('archived', 'cancelled')";

    $quotation_stmt = $pdo->prepare("
        SELECT q.*, b.name AS branch_name
        FROM quotations q
        LEFT JOIN branches b ON b.id = q.branch_id
        WHERE q.vehicle_id = ?
          $quotation_status_sql
        ORDER BY q.quotation_date DESC, q.created_at DESC, q.id DESC
    ");
    $quotation_stmt->execute([$vehicle_id]);
    $quotations = $quotation_stmt->fetchAll();

    $job_stmt = $pdo->prepare("
        SELECT jo.*, q.quotation_number, q.total_amount AS quotation_total,
               b.name AS branch_name
        FROM job_orders jo
        LEFT JOIN quotations q ON q.id = jo.quotation_id
        LEFT JOIN branches b ON b.id = jo.branch_id
        WHERE jo.vehicle_id = ?
          $job_status_sql
        ORDER BY jo.job_date DESC, jo.created_at DESC, jo.id DESC
    ");
    $job_stmt->execute([$vehicle_id]);
    $job_orders = $job_stmt->fetchAll();

    $history_stmt = $pdo->prepare("
        SELECT sh.*, b.name AS branch_name
        FROM service_history sh
        LEFT JOIN branches b ON b.id = sh.branch_id
        WHERE sh.vehicle_id = ?
        ORDER BY sh.service_date DESC, sh.created_at DESC, sh.id DESC
    ");
    $history_stmt->execute([$vehicle_id]);
    $service_history = $history_stmt->fetchAll();

    $quotation_ids = [];
    foreach ($quotations as $quotation) {
        $quotation_ids[(int) $quotation['id']] = (int) $quotation['id'];
    }
    foreach ($job_orders as $job_order) {
        $quotation_id = (int) ($job_order['quotation_id'] ?? 0);
        if ($quotation_id > 0) {
            $quotation_ids[$quotation_id] = $quotation_id;
        }
    }
    foreach ($service_history as $history) {
        $quotation_id = (int) ($history['quotation_id'] ?? 0);
        if ($quotation_id > 0) {
            $quotation_ids[$quotation_id] = $quotation_id;
        }
    }

    $items_by_quotation = [];
    $all_items = [];
    if (!empty($quotation_ids) && app_table_exists('quotation_items')) {
        $placeholders = implode(',', array_fill(0, count($quotation_ids), '?'));
        $items_stmt = $pdo->prepare("
            SELECT *
            FROM quotation_items
            WHERE quotation_id IN ($placeholders)
            ORDER BY quotation_id ASC, id ASC
        ");
        $items_stmt->execute(array_values($quotation_ids));
        $all_items = $items_stmt->fetchAll();

        foreach ($all_items as $item) {
            $quotation_id = (int) ($item['quotation_id'] ?? 0);
            if ($quotation_id <= 0) {
                continue;
            }

            $items_by_quotation[$quotation_id][] = $item;
        }
    }

    $inventory_items_by_id = app_line_item_load_inventory_items($pdo, $all_items);
    $item_summaries = vehicle_history_summary_item_summaries($items_by_quotation, $inventory_items_by_id);
    $empty_item_summary = [
        'services' => [],
        'products' => [],
        'product_rows' => [],
    ];

    $quotations_by_id = [];
    foreach ($quotations as $quotation) {
        $quotations_by_id[(int) ($quotation['id'] ?? 0)] = $quotation;
    }

    $jobs_by_id = [];
    $jobs_by_quotation = [];
    foreach ($job_orders as $job_order) {
        $job_id = (int) ($job_order['id'] ?? 0);
        $quotation_id = (int) ($job_order['quotation_id'] ?? 0);
        if ($job_id > 0) {
            $jobs_by_id[$job_id] = $job_order;
        }
        if ($quotation_id > 0) {
            $jobs_by_quotation[$quotation_id][] = $job_order;
        }
    }

    if (function_exists('app_line_item_load_linked_inventory_transactions')) {
        foreach (array_values($quotation_ids) as $quotation_id) {
            $quotation_id = (int) $quotation_id;
            if ($quotation_id <= 0) {
                continue;
            }

            if (!isset($item_summaries[$quotation_id])) {
                $item_summaries[$quotation_id] = $empty_item_summary;
            }

            $linked_job_id = 0;
            if (!empty($jobs_by_quotation[$quotation_id])) {
                $linked_job_id = (int) ($jobs_by_quotation[$quotation_id][0]['id'] ?? 0);
            }

            $linked_inventory = app_line_item_load_linked_inventory_transactions($pdo, [
                'quotation_id' => $quotation_id,
                'job_order_id' => $linked_job_id,
                'vehicle_id' => $vehicle_id,
            ]);
            vehicle_history_summary_merge_inventory_transactions($item_summaries[$quotation_id], $linked_inventory);
        }
    }

    $used_job_ids = [];
    $used_quotation_ids = [];

    $build_row = static function ($source_type, $history, $job_order, $quotation, $item_summary) {
        $history = is_array($history) ? $history : null;
        $job_order = is_array($job_order) ? $job_order : null;
        $quotation = is_array($quotation) ? $quotation : null;

        $date = $history['service_date'] ?? ($job_order['job_date'] ?? ($quotation['quotation_date'] ?? ''));
        $created_at = $history['created_at'] ?? ($job_order['created_at'] ?? ($quotation['created_at'] ?? ''));
        $branch_name = $history['branch_name'] ?? ($job_order['branch_name'] ?? ($quotation['branch_name'] ?? ''));
        $status = $history ? 'completed' : ($quotation['status'] ?? ($job_order['status'] ?? ''));
        $services = $item_summary['services'] ?? [];
        if (empty($services) && $history) {
            $services = vehicle_history_summary_split_lines($history['services_description'] ?? '');
        }
        if (empty($services)) {
            $services = ['Service operation'];
        }

        $amount = vehicle_history_summary_amount([
            $history['total_cost'] ?? 0,
            $quotation['total_amount'] ?? 0,
            $job_order['quotation_total'] ?? 0,
        ]);

        $notes = app_format_record_notes(
            $history['notes'] ?? ($job_order['notes'] ?? ($quotation['notes'] ?? ''))
        );
        $mileage = $history['mileage_at_service'] ?? ($quotation['inspection_mileage'] ?? null);
        $technician = trim((string) ($job_order['assigned_technician_name'] ?? ''));
        $time_range = $job_order
            ? vehicle_history_summary_time_range($job_order['scheduled_start_time'] ?? '', $job_order['scheduled_end_time'] ?? '')
            : '-';

        return [
            'source_type' => $source_type,
            'sort_key' => trim((string) $date . ' ' . (string) $created_at),
            'date' => $date,
            'branch' => vehicle_history_summary_branch_label($branch_name, 'No branch'),
            'service_ref' => $history ? vehicle_history_summary_reference('SH', $history['id'] ?? 0) : '-',
            'operation_ref' => $quotation['quotation_number'] ?? '-',
            'job_ref' => $job_order['job_number'] ?? '-',
            'services' => $services,
            'products' => ($history || (($job_order['status'] ?? '') === 'completed')) ? ($item_summary['products'] ?? []) : [],
            'product_rows' => ($history || (($job_order['status'] ?? '') === 'completed')) ? ($item_summary['product_rows'] ?? []) : [],
            'status' => $status,
            'amount' => $amount,
            'mileage' => !empty($mileage) ? number_format((int) $mileage) . ' km' : '-',
            'technician' => $technician !== '' ? $technician : '-',
            'time_range' => $time_range,
            'notes' => $notes !== '' ? $notes : '-',
        ];
    };

    foreach ($service_history as $history) {
        $quotation_id = (int) ($history['quotation_id'] ?? 0);
        $job_id = (int) ($history['job_order_id'] ?? 0);
        $quotation = $quotation_id > 0 ? ($quotations_by_id[$quotation_id] ?? null) : null;
        $job_order = $job_id > 0
            ? ($jobs_by_id[$job_id] ?? null)
            : ($quotation_id > 0 && !empty($jobs_by_quotation[$quotation_id]) ? $jobs_by_quotation[$quotation_id][0] : null);
        $item_summary = $quotation_id > 0 ? ($item_summaries[$quotation_id] ?? $empty_item_summary) : $empty_item_summary;

        $timeline_rows[] = $build_row('Service History', $history, $job_order, $quotation, $item_summary);

        if ($quotation_id > 0) {
            $used_quotation_ids[$quotation_id] = true;
        }
        if ($job_order && !empty($job_order['id'])) {
            $used_job_ids[(int) $job_order['id']] = true;
        }
    }

    foreach ($job_orders as $job_order) {
        $job_id = (int) ($job_order['id'] ?? 0);
        if ($job_id > 0 && isset($used_job_ids[$job_id])) {
            continue;
        }

        $quotation_id = (int) ($job_order['quotation_id'] ?? 0);
        $quotation = $quotation_id > 0 ? ($quotations_by_id[$quotation_id] ?? null) : null;
        $item_summary = $quotation_id > 0 ? ($item_summaries[$quotation_id] ?? $empty_item_summary) : $empty_item_summary;

        $timeline_rows[] = $build_row('Job Order', null, $job_order, $quotation, $item_summary);
        if ($quotation_id > 0) {
            $used_quotation_ids[$quotation_id] = true;
        }
    }

    foreach ($quotations as $quotation) {
        $quotation_id = (int) ($quotation['id'] ?? 0);
        if ($quotation_id > 0 && isset($used_quotation_ids[$quotation_id])) {
            continue;
        }

        $item_summary = $quotation_id > 0 ? ($item_summaries[$quotation_id] ?? $empty_item_summary) : $empty_item_summary;
        $timeline_rows[] = $build_row('Service Operation', null, null, $quotation, $item_summary);
    }

    usort($timeline_rows, static function ($a, $b) {
        $a_time = strtotime($a['sort_key'] ?? '') ?: 0;
        $b_time = strtotime($b['sort_key'] ?? '') ?: 0;
        return $b_time <=> $a_time;
    });

    foreach ($timeline_rows as $row) {
        $total_recorded += (float) ($row['amount'] ?? 0);
        $branch_name = $row['branch'] ?? 'No branch';
        if (!isset($branch_summary[$branch_name])) {
            $branch_summary[$branch_name] = [
                'visits' => 0,
                'amount' => 0.0,
            ];
        }
        $branch_summary[$branch_name]['visits']++;
        $branch_summary[$branch_name]['amount'] += (float) ($row['amount'] ?? 0);

        foreach (($row['product_rows'] ?? []) as $product_row) {
            $product_quantity_total += (int) ($product_row['quantity'] ?? 0);
            $product_rows[] = array_merge($product_row, [
                'date' => $row['date'] ?? '',
                'branch' => $row['branch'] ?? '-',
                'record' => ($row['job_ref'] ?? '-') !== '-' ? $row['job_ref'] : ($row['service_ref'] ?? '-'),
            ]);
        }
    }

    ksort($branch_summary);
} catch (Exception $e) {
    error_log('Vehicle history summary error: ' . $e->getMessage());
    set_flash_message('Error loading vehicle history summary', 'error');
    redirect('/hwtires/' . $history_role . '/vehicles/');
}

$vehicle_name = vehicle_history_summary_vehicle_name($vehicle);
$current_owner = $ownership_history[0]['owner_name'] ?? ($vehicle['customer_name'] ?? '-');
$customer_phone = trim((string) (($vehicle['phone_mobile'] ?? '') ?: ($vehicle['contact'] ?? '')));
$customer_phone = $customer_phone !== '' ? $customer_phone : '-';
$customer_address = trim((string) (($vehicle['address'] ?? '') . (!empty($vehicle['city']) ? ', ' . $vehicle['city'] : '')));
$customer_address = $customer_address !== '' ? $customer_address : '-';
$vehicle_branch = vehicle_history_summary_branch_label($vehicle['vehicle_branch_name'] ?? '', 'No branch assigned');
$last_service_date = !empty($service_history) ? ($service_history[0]['service_date'] ?? '') : '';
$report_generated = date('M d, Y H:i');
?>

<?php require_once __DIR__ . '/header.php'; ?>
<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="vehicle-summary-page">
    <section class="vehicle-summary-hero">
        <div>
            <h1><i class="fas fa-file-lines"></i> Vehicle History Summary</h1>
            <p>
                <span><?php echo esc_html($vehicle_name); ?></span>
                <?php if (!empty($vehicle['plate_number'])): ?>
                    <span><?php echo esc_html($vehicle['plate_number']); ?></span>
                <?php endif; ?>
                <span><?php echo esc_html($current_owner); ?></span>
                <span>All branches</span>
            </p>
        </div>
        <div class="vehicle-summary-actions no-print">
            <a href="/hwtires/<?php echo esc_attr($history_role); ?>/vehicles/profile.php?id=<?php echo (int) $vehicle_id; ?>" class="vehicle-summary-btn secondary">
                <i class="fas fa-arrow-left"></i>
                <span>Back to Profile</span>
            </a>
            <button type="button" class="vehicle-summary-btn primary" onclick="window.print()">
                <i class="fas fa-print"></i>
                <span>Print Report</span>
            </button>
        </div>
    </section>

    <div class="vehicle-summary-notice" role="status">
        <i class="fas fa-circle-info"></i>
        <span>This report summarizes centralized vehicle records across all branches. Record updates remain with the owning branch.</span>
    </div>

    <section class="vehicle-summary-kpis" aria-label="Vehicle history totals">
        <article>
            <span>Operation Records</span>
            <strong><?php echo number_format(count($timeline_rows)); ?></strong>
        </article>
        <article>
            <span>Job Orders</span>
            <strong><?php echo number_format(count($job_orders)); ?></strong>
        </article>
        <article>
            <span>Products Used</span>
            <strong><?php echo number_format($product_quantity_total); ?></strong>
        </article>
        <article>
            <span>Total Recorded</span>
            <strong><?php echo esc_html(format_currency($total_recorded)); ?></strong>
        </article>
    </section>

    <section class="vehicle-summary-grid">
        <article class="vehicle-summary-panel">
            <div class="vehicle-summary-panel-head">
                <h2><i class="fas fa-user"></i> Customer & Vehicle</h2>
            </div>
            <div class="vehicle-summary-info-grid">
                <div>
                    <span>Current Owner</span>
                    <strong><?php echo esc_html($current_owner); ?></strong>
                </div>
                <div>
                    <span>Phone</span>
                    <strong><?php echo esc_html($customer_phone); ?></strong>
                </div>
                <div>
                    <span>Address</span>
                    <strong><?php echo esc_html($customer_address); ?></strong>
                </div>
                <div>
                    <span>Make / Model</span>
                    <strong><?php echo esc_html($vehicle_name); ?></strong>
                </div>
                <div>
                    <span>Plate Number</span>
                    <strong><?php echo esc_html($vehicle['plate_number'] ?? '-'); ?></strong>
                </div>
                <div>
                    <span>Year</span>
                    <strong><?php echo esc_html($vehicle['year'] ?? '-'); ?></strong>
                </div>
                <div>
                    <span>Home Branch</span>
                    <strong><?php echo esc_html($vehicle_branch); ?></strong>
                </div>
            </div>
        </article>

        <article class="vehicle-summary-panel">
            <div class="vehicle-summary-panel-head">
                <h2><i class="fas fa-users"></i> Ownership History</h2>
                <span><?php echo number_format(count($ownership_history)); ?> owner<?php echo count($ownership_history) === 1 ? '' : 's'; ?></span>
            </div>
            <div class="vehicle-summary-owner-list">
                <?php foreach ($ownership_history as $ownership): ?>
                    <article class="<?php echo !empty($ownership['is_current']) ? 'current' : ''; ?>">
                        <div>
                            <strong><?php echo esc_html($ownership['owner_name'] ?? '-'); ?></strong>
                            <span>
                                <?php echo esc_html(vehicle_history_summary_date($ownership['owned_from'] ?? '')); ?>
                                -
                                <?php echo esc_html(!empty($ownership['owned_until']) ? vehicle_history_summary_date($ownership['owned_until']) : 'Present'); ?>
                            </span>
                        </div>
                        <?php if (!empty($ownership['is_current'])): ?>
                            <em>Current</em>
                        <?php endif; ?>
                        <?php if (!empty($ownership['transfer_notes'])): ?>
                            <p><?php echo esc_html($ownership['transfer_notes']); ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </article>
    </section>

    <section class="vehicle-summary-panel">
        <div class="vehicle-summary-panel-head">
            <h2><i class="fas fa-list-check"></i> Service Operation Timeline</h2>
            <span><?php echo number_format(count($timeline_rows)); ?> record<?php echo count($timeline_rows) === 1 ? '' : 's'; ?></span>
        </div>
        <?php if (empty($timeline_rows)): ?>
            <div class="vehicle-summary-empty">No service operation records found for this vehicle.</div>
        <?php else: ?>
            <div class="vehicle-summary-table-wrap">
                <table class="vehicle-summary-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Branch</th>
                            <th>Records</th>
                            <th>Services</th>
                            <th>Items Used</th>
                            <th>Status</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($timeline_rows as $row): ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html(vehicle_history_summary_date($row['date'] ?? '')); ?></strong>
                                    <span><?php echo esc_html($row['mileage'] ?? '-'); ?></span>
                                </td>
                                <td><span class="branch-badge"><?php echo esc_html($row['branch'] ?? '-'); ?></span></td>
                                <td>
                                    <div class="vehicle-summary-record-stack">
                                        <?php if (($row['service_ref'] ?? '-') !== '-'): ?>
                                            <span><b>Service History</b><?php echo esc_html($row['service_ref']); ?></span>
                                        <?php endif; ?>
                                        <?php if (($row['job_ref'] ?? '-') !== '-'): ?>
                                            <span><b>Job Order</b><?php echo esc_html($row['job_ref']); ?></span>
                                        <?php endif; ?>
                                        <?php if (($row['operation_ref'] ?? '-') !== '-'): ?>
                                            <span><b>Service Operation</b><?php echo esc_html($row['operation_ref']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <ul class="vehicle-summary-lines">
                                        <?php foreach (($row['services'] ?? []) as $service): ?>
                                            <li><?php echo esc_html($service); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <p class="vehicle-summary-subline">
                                        Technician: <?php echo esc_html($row['technician'] ?? '-'); ?> | Time: <?php echo esc_html($row['time_range'] ?? '-'); ?>
                                    </p>
                                </td>
                                <td>
                                    <?php
                                    $display_items = !empty($row['product_rows']) ? $row['product_rows'] : [];
                                    if (empty($display_items) && !empty($row['products'])) {
                                        foreach ($row['products'] as $p) {
                                            $display_items[] = ['name' => $p, 'quantity' => 1, 'details' => ''];
                                        }
                                    }
                                    ?>
                                    <?php if (empty($display_items)): ?>
                                        <span class="vehicle-summary-muted">No inventory product used</span>
                                    <?php else: ?>
                                        <div class="vehicle-summary-item-stack">
                                            <?php foreach ($display_items as $prod):
                                                $p_name = $prod['name'] ?? 'Product';
                                                $p_qty = max(1, (int) ($prod['quantity'] ?? 1));
                                                $p_details = trim((string) ($prod['details'] ?? ''));
                                            ?>
                                                <div class="vehicle-summary-item-badge-card">
                                                    <div class="vehicle-summary-item-header">
                                                        <strong class="vehicle-summary-item-title"><?php echo esc_html($p_name); ?></strong>
                                                        <span class="vehicle-summary-item-qty"><?php echo $p_qty; ?>x</span>
                                                    </div>
                                                    <?php if ($p_details !== ''): ?>
                                                        <div class="vehicle-summary-item-specs">
                                                            <?php
                                                            $spec_tokens = array_map('trim', explode('|', $p_details));
                                                            foreach ($spec_tokens as $token):
                                                                if ($token === '') continue;
                                                            ?>
                                                                <span class="vehicle-summary-spec-chip"><?php echo esc_html($token); ?></span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="vehicle-summary-status status-<?php echo esc_attr(vehicle_history_summary_status_class($row['status'] ?? '')); ?>">
                                        <?php echo esc_html(vehicle_history_summary_status_label($row['status'] ?? '')); ?>
                                    </span>
                                </td>
                                <td class="text-end"><?php echo esc_html(format_currency((float) ($row['amount'] ?? 0))); ?></td>
                            </tr>
                            <?php if (($row['notes'] ?? '-') !== '-'): ?>
                                <tr class="vehicle-summary-notes-row">
                                    <td></td>
                                    <td colspan="6">
                                        <strong>Notes:</strong> <?php echo esc_html($row['notes']); ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="vehicle-summary-grid">
        <article class="vehicle-summary-panel">
            <div class="vehicle-summary-panel-head">
                <h2><i class="fas fa-box"></i> Inventory Products Used</h2>
                <span><?php echo number_format(count($product_rows)); ?> line<?php echo count($product_rows) === 1 ? '' : 's'; ?></span>
            </div>
            <?php if (empty($product_rows)): ?>
                <div class="vehicle-summary-empty">No inventory products were attached to this vehicle history yet.</div>
            <?php else: ?>
                <div class="vehicle-summary-table-wrap compact">
                    <table class="vehicle-summary-table compact">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Item Details</th>
                                <th>Branch</th>
                                <th>Related Record</th>
                                <th class="text-end">Qty</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($product_rows as $product): ?>
                                <tr>
                                    <td><?php echo esc_html(vehicle_history_summary_date($product['date'] ?? '')); ?></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 2px;">
                                            <?php if (!empty($product['category'])): ?>
                                                <span class="badge bg-light text-dark border" style="font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; padding: 2px 5px; border-radius: 4px;"><?php echo esc_html($product['category']); ?></span>
                                            <?php endif; ?>
                                            <strong style="color: #0f172a; font-size: 13.5px;"><?php echo esc_html(app_display_item_name($product['name'] ?? '-')); ?></strong>
                                        </div>
                                        <?php if (!empty($product['details'])): ?>
                                            <span style="display: block; font-size: 12px; color: #64748b; line-height: 1.35;"><?php echo esc_html($product['details']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($product['branch'] ?? '-'); ?></td>
                                    <td><?php echo esc_html($product['record'] ?? '-'); ?></td>
                                    <td class="text-end"><?php echo number_format((int) ($product['quantity'] ?? 0)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </article>

        <article class="vehicle-summary-panel">
            <div class="vehicle-summary-panel-head">
                <h2><i class="fas fa-code-branch"></i> Branch Summary</h2>
            </div>
            <?php if (empty($branch_summary)): ?>
                <div class="vehicle-summary-empty">No branch visits recorded yet.</div>
            <?php else: ?>
                <div class="vehicle-summary-table-wrap compact">
                    <table class="vehicle-summary-table compact">
                        <thead>
                            <tr>
                                <th>Branch</th>
                                <th class="text-end">Operations</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($branch_summary as $branch_name => $branch): ?>
                                <tr>
                                    <td><?php echo esc_html($branch_name); ?></td>
                                    <td class="text-end"><?php echo number_format((int) ($branch['visits'] ?? 0)); ?></td>
                                    <td class="text-end"><?php echo esc_html(format_currency((float) ($branch['amount'] ?? 0))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </article>
    </section>

    <footer class="vehicle-summary-print-footer">
        Generated <?php echo esc_html($report_generated); ?> | Last service: <?php echo esc_html(vehicle_history_summary_date($last_service_date)); ?>
    </footer>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
