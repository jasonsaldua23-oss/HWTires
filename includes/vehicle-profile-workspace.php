<?php
/**
 * Shared Vehicle Profile workspace for admin and front-desk users.
 */

require_once __DIR__ . '/record-filters.php';
require_once __DIR__ . '/line-item-display.php';

$user = app_get_session_user();
if (!is_array($user)) {
    redirect('/hwtires/index.php');
}

$vehicle_id = intval($_GET['id'] ?? 0);
$page_title = 'Vehicle Profile';

if ($vehicle_id <= 0) {
    redirect('../');
}

if (!function_exists('vehicle_profile_branch_label')) {
    function vehicle_profile_branch_label($name, $fallback = '-') {
        return app_branch_label($name, $fallback);
    }
}

if (!function_exists('vehicle_profile_status_label')) {
    function vehicle_profile_status_label($status) {
        $status = trim((string) $status);
        return $status === '' ? '-' : ucwords(str_replace(['-', '_'], ' ', $status));
    }
}

if (!function_exists('vehicle_profile_status_class')) {
    function vehicle_profile_status_class($status) {
        $status = strtolower(trim((string) $status));

        if (in_array($status, ['approved', 'completed', 'active', 'tagged'], true)) {
            return 'success';
        }

        if (in_array($status, ['pending', 'waiting'], true)) {
            return 'warning';
        }

        if (in_array($status, ['in-progress', 'stock-in', 'stock_in', 'adjustment'], true)) {
            return 'info';
        }

        if (in_array($status, ['rejected', 'cancelled', 'damage', 'archived'], true)) {
            return 'danger';
        }

        return 'neutral';
    }
}

if (!function_exists('vehicle_profile_type_label')) {
    function vehicle_profile_type_label($type) {
        $labels = [
            'event' => 'Service Visit',
            'quotation' => 'Service Operation',
            'job' => 'Job Order',
            'history' => 'Service History',
            'item' => 'Items Used/Sold',
        ];

        return $labels[$type] ?? 'Record';
    }
}

if (!function_exists('vehicle_profile_short_date')) {
    function vehicle_profile_short_date($date) {
        return !empty($date) ? date('M d, Y', strtotime($date)) : '-';
    }
}

if (!function_exists('vehicle_profile_money')) {
    function vehicle_profile_money($amount) {
        $amount = (float) $amount;
        return $amount > 0 ? format_currency($amount) : '-';
    }
}

if (!function_exists('vehicle_profile_split_lines')) {
    function vehicle_profile_split_lines($lines) {
        $lines = trim((string) $lines);
        if ($lines === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(';;', $lines))));
    }
}

if (!function_exists('vehicle_profile_filter_url')) {
    function vehicle_profile_filter_url($vehicle_id, $tab, $branch_filter, $status_filter, $search_filter, $page, array $date_filter) {
        $query = ['id' => (int) $vehicle_id];

        if ($tab !== 'overview') {
            $query['tab'] = $tab;
        }

        if ($branch_filter !== '') {
            $query['branch'] = (int) $branch_filter;
        }

        if ($status_filter !== 'all') {
            $query['status'] = $status_filter;
        }

        $search_filter = trim((string) $search_filter);
        if ($search_filter !== '') {
            $query['search'] = $search_filter;
        }

        if ((int) $page > 1) {
            $query['page'] = (int) $page;
        }

        if (($date_filter['scope'] ?? 'all') !== 'all') {
            $query = array_merge($query, record_date_filter_query_params($date_filter));
        }

        return '?' . http_build_query($query);
    }
}

if (!function_exists('vehicle_profile_record_url')) {
    function vehicle_profile_record_url($role, array $record) {
        $role = $role === 'admin' ? 'admin' : 'front-desk';
        $type = $record['record_type'] ?? '';
        $primary_type = $record['primary_type'] ?? '';

        if ($type === 'item') {
            return '';
        }

        if ($type === 'event') {
            return '';
        }

        if ($type === 'history') {
            return '';
        }

        if ($primary_type === 'job' && !empty($record['job_order_id'])) {
            return '/hwtires/' . $role . '/job-orders/view.php?id=' . (int) $record['job_order_id'];
        }

        if ($primary_type === 'quotation' && !empty($record['quotation_id'])) {
            return '/hwtires/' . $role . '/quotations/view.php?id=' . (int) $record['quotation_id'];
        }

        if ($type === 'quotation' && !empty($record['quotation_id'])) {
            return '/hwtires/' . $role . '/quotations/view.php?id=' . (int) $record['quotation_id'];
        }

        if ($type === 'job' && !empty($record['job_order_id'])) {
            return '/hwtires/' . $role . '/job-orders/view.php?id=' . (int) $record['job_order_id'];
        }

        if (!empty($record['job_order_id'])) {
            return '/hwtires/' . $role . '/job-orders/view.php?id=' . (int) $record['job_order_id'];
        }

        if (!empty($record['quotation_id'])) {
            return '/hwtires/' . $role . '/quotations/view.php?id=' . (int) $record['quotation_id'];
        }

        return '';
    }
}

if (!function_exists('vehicle_profile_quantity_from_item_lines')) {
    function vehicle_profile_quantity_from_item_lines(array $items, $meta) {
        $quantity = 0.0;

        foreach ($items as $item) {
            if (preg_match('/\((\d+(?:\.\d+)?)x\)\s*$/i', (string) $item, $matches)) {
                $quantity += (float) $matches[1];
            }
        }

        if ($quantity > 0) {
            return $quantity;
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s+stock\s+out/i', (string) $meta, $matches)) {
            return (float) $matches[1];
        }

        return 0.0;
    }
}

if (!function_exists('vehicle_profile_quantity_label')) {
    function vehicle_profile_quantity_label($quantity) {
        $quantity = (float) $quantity;
        if ($quantity <= 0) {
            return '-';
        }

        $display = abs($quantity - round($quantity)) < 0.001
            ? number_format($quantity, 0)
            : rtrim(rtrim(number_format($quantity, 2), '0'), '.');

        return $display . ' pc' . (abs($quantity - 1.0) < 0.001 ? '' : 's');
    }
}

if (!function_exists('vehicle_profile_inventory_source_links')) {
    function vehicle_profile_inventory_source_links($role, array $record, array $linked_records) {
        if (!empty($linked_records)) {
            return $linked_records;
        }

        $base_role = $role === 'admin' ? 'admin' : 'front-desk';
        $quotation_id = (int) ($record['quotation_id'] ?? 0);
        $job_order_id = (int) ($record['job_order_id'] ?? 0);

        if ($quotation_id > 0) {
            $linked_records[] = [
                'label' => 'Service Operation',
                'url' => '/hwtires/' . $base_role . '/quotations/view.php?id=' . $quotation_id,
            ];
        }

        if ($job_order_id > 0) {
            $linked_records[] = [
                'label' => 'Job Order',
                'url' => '/hwtires/' . $base_role . '/job-orders/view.php?id=' . $job_order_id,
            ];
        }

        return $linked_records;
    }
}

if (!function_exists('vehicle_profile_inventory_detail_fields')) {
    function vehicle_profile_inventory_detail_fields(array $record, array $items, $inventory_amount, array $linked_records) {
        $summary = trim((string) ($record['summary'] ?? ''));
        $meta = trim((string) ($record['meta_text'] ?? ''));
        $quantity = vehicle_profile_quantity_from_item_lines($items, $meta);
        $inventory_amount = max(0.0, (float) $inventory_amount);
        $unit_price = count($items) > 1
            ? 'Mixed prices'
            : (($quantity > 0 && $inventory_amount > 0) ? vehicle_profile_money($inventory_amount / $quantity) : '-');

        $source_labels = [];
        foreach ($linked_records as $linked_record) {
            $label = trim((string) ($linked_record['label'] ?? ''));
            if ($label !== '') {
                $source_labels[] = $label;
            }
        }
        $source_label = empty($source_labels)
            ? 'No linked source record'
            : implode(' / ', array_values(array_unique($source_labels)));

        return [
            ['label' => 'Product', 'value' => $summary !== '' ? $summary : ($items[0] ?? '-')],
            ['label' => 'Quantity', 'value' => vehicle_profile_quantity_label($quantity)],
            ['label' => 'Unit Price', 'value' => $unit_price],
            ['label' => 'Issued Value', 'value' => vehicle_profile_money($inventory_amount)],
            ['label' => 'Source Record', 'value' => $source_label],
            ['label' => 'Transaction Details', 'value' => $meta !== '' ? $meta : '-'],
        ];
    }
}

if (!function_exists('vehicle_profile_record_payload')) {
    function vehicle_profile_record_payload($role, array $record) {
        $items = vehicle_profile_split_lines($record['item_lines'] ?? '');
        $notes = app_format_record_notes($record['notes'] ?? '');
        $linked_records = is_array($record['linked_records'] ?? null) ? $record['linked_records'] : [];
        $record_type = $record['record_type'] ?? '';
        $is_inventory_record = $record_type === 'item';
        $empty_items_label = $is_inventory_record
            ? 'No item detail recorded'
            : 'No inventory product used for this visit';
        $record_amount = max(0.0, (float) ($record['amount'] ?? 0));
        $linked_inventory_amount = max(0.0, (float) ($record['linked_inventory_amount'] ?? 0));

        if ($is_inventory_record) {
            $service_amount = max(0.0, (float) ($record['service_amount'] ?? 0));
            $inventory_amount = max(0.0, (float) ($record['inventory_amount'] ?? 0));
            if ($inventory_amount <= 0) {
                $inventory_amount = $record_amount;
            }
        } else {
            $service_amount = max(0.0, (float) ($record['service_amount'] ?? 0));
            if ($service_amount <= 0) {
                $service_amount = $record_amount;
            }

            $inventory_amount = max(0.0, (float) ($record['inventory_amount'] ?? 0));
            if ($inventory_amount <= 0 && $linked_inventory_amount > 0) {
                $inventory_amount = $linked_inventory_amount;
            }
        }

        $visit_total = max(0.0, (float) ($record['visit_total'] ?? 0));
        if ($visit_total <= 0) {
            $visit_total = $service_amount > 0 ? $service_amount : $inventory_amount;
        }
        if ($visit_total <= 0) {
            $visit_total = $record_amount;
        }

        $display_amount = $service_amount > 0 ? $service_amount : ($record_amount > 0 ? $record_amount : $inventory_amount);
        $record_count = (int) ($record['record_count'] ?? count($linked_records));
        $source_types = is_array($record['source_types'] ?? null) ? $record['source_types'] : [];
        $linked_records = $is_inventory_record
            ? vehicle_profile_inventory_source_links($role, $record, $linked_records)
            : $linked_records;
        $inventory_fields = $is_inventory_record
            ? vehicle_profile_inventory_detail_fields($record, $items, $inventory_amount, $linked_records)
            : [];

        return [
            'type' => vehicle_profile_type_label($record_type),
            'title' => trim((string) ($record['record_number'] ?? 'Record')),
            'summary' => trim((string) ($record['summary'] ?? '')),
            'date' => vehicle_profile_short_date($record['record_date'] ?? ''),
            'branch' => vehicle_profile_branch_label($record['branch_name'] ?? ''),
            'status' => vehicle_profile_status_label($record['record_status'] ?? ''),
            'amount' => vehicle_profile_money($display_amount),
            'service_amount' => vehicle_profile_money($service_amount),
            'inventory_amount' => vehicle_profile_money($inventory_amount),
            'visit_total' => vehicle_profile_money($visit_total),
            'meta' => trim((string) ($record['meta_text'] ?? '')),
            'notes' => $notes,
            'items' => $items,
            'is_inventory' => $is_inventory_record,
            'items_title' => $is_inventory_record ? 'Inventory Product' : 'Items Used',
            'inventory_fields' => $inventory_fields,
            'empty_items_label' => $empty_items_label,
            'linked_records' => $linked_records,
            'record_count' => $record_count,
            'source_types' => $source_types,
            'url' => vehicle_profile_record_url($role, $record),
        ];
    }
}

if (!function_exists('vehicle_profile_unique_push')) {
    function vehicle_profile_unique_push(array &$values, $value) {
        $value = trim((string) $value);
        if ($value !== '' && !in_array($value, $values, true)) {
            $values[] = $value;
        }
    }
}

if (!function_exists('vehicle_profile_unique_push_item')) {
    function vehicle_profile_unique_push_item(array &$items, $item_line) {
        $item_line = trim((string) $item_line);
        if ($item_line === '') {
            return;
        }

        $clean_candidate = preg_replace('/\s*-\s*[^\(]+\s*\(/', ' (', $item_line);
        $candidate_base = strtolower(trim(explode(' (', $clean_candidate)[0]));

        foreach ($items as $idx => $existing) {
            $clean_exist = preg_replace('/\s*-\s*[^\(]+\s*\(/', ' (', $existing);
            $exist_base = strtolower(trim(explode(' (', $clean_exist)[0]));

            if ($exist_base === $candidate_base || strpos($exist_base, $candidate_base) !== false || strpos($candidate_base, $exist_base) !== false) {
                if (strlen($item_line) > strlen($existing)) {
                    $items[$idx] = $item_line;
                }
                return;
            }
        }

        $items[] = $item_line;
    }
}

if (!function_exists('vehicle_profile_item_line')) {
    function vehicle_profile_item_line(array $item, array $inventory_items_by_id) {
        $name = trim((string) ($item['item_name'] ?? 'Item'));
        $quantity = max(1, (int) ($item['quantity'] ?? 1));
        $unit_price = (float) ($item['unit_price'] ?? 0);
        $line_total = (float) ($item['subtotal'] ?? 0);

        $line = $name !== '' ? $name : 'Item';
        $line .= ' (' . $quantity . 'x)';

        if ($line_total <= 0 && $unit_price > 0) {
            $line_total = $unit_price * $quantity;
        }

        if ($line_total > 0) {
            $line .= ' - ' . vehicle_profile_money($line_total);
        }

        return $line;
    }
}

if (!function_exists('vehicle_profile_item_line_with_amount')) {
    function vehicle_profile_item_line_with_amount($line, $amount) {
        $line = trim((string) $line);
        $amount = (float) $amount;

        if ($line === '' || $amount <= 0) {
            return $line;
        }

        if (preg_match('/\s-\s[^\d-]*\d[\d,]*(?:\.\d{2})?$/', $line)) {
            return $line;
        }

        return $line . ' - ' . vehicle_profile_money($amount);
    }
}

if (!function_exists('vehicle_profile_item_display_parts')) {
    function vehicle_profile_item_display_parts($line) {
        $line = trim((string) $line);
        $parts = [
            'label' => $line,
            'price' => '',
        ];

        if ($line === '') {
            return $parts;
        }

        $separator = ' - ';
        $position = strrpos($line, $separator);
        if ($position === false) {
            return $parts;
        }

        $possible_price = trim(substr($line, $position + strlen($separator)));
        if ($possible_price !== '' && preg_match('/^[^\d-]*\d[\d,]*(?:\.\d{2})?$/', $possible_price)) {
            $parts['label'] = trim(substr($line, 0, $position));
            $parts['price'] = $possible_price;
        }

        return $parts;
    }
}

if (!function_exists('vehicle_profile_item_line_html')) {
    function vehicle_profile_item_line_html($line, $muted = false) {
        $parts = vehicle_profile_item_display_parts($line);
        $label = $parts['label'] !== '' ? $parts['label'] : '-';
        $classes = ['vehicle-workspace-item-line'];

        if ($muted) {
            $classes[] = 'is-muted';
        }

        if ($parts['price'] !== '') {
            $classes[] = 'has-price';
        }

        $html = '<li class="' . esc_attr(implode(' ', $classes)) . '">';
        $html .= '<span class="vehicle-workspace-item-label">' . esc_html($label) . '</span>';

        if ($parts['price'] !== '') {
            $html .= '<strong class="vehicle-workspace-item-price">' . esc_html($parts['price']) . '</strong>';
        }

        $html .= '</li>';

        return $html;
    }
}

if (!function_exists('vehicle_profile_compact_list')) {
    function vehicle_profile_compact_list(array $lines, $empty = '-') {
        $lines = array_values(array_filter(array_map('trim', $lines)));
        if (empty($lines)) {
            return $empty;
        }

        if (count($lines) <= 2) {
            return implode('; ', $lines);
        }

        return $lines[0] . '; ' . $lines[1] . ' +' . (count($lines) - 2) . ' more';
    }
}

if (!function_exists('vehicle_profile_load_item_summaries')) {
    function vehicle_profile_load_item_summaries(PDO $pdo, array $quotation_ids) {
        $quotation_ids = array_values(array_unique(array_filter(array_map('intval', $quotation_ids))));
        if (empty($quotation_ids) || !app_table_exists('quotation_items')) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($quotation_ids), '?'));
        $stmt = $pdo->prepare("
            SELECT *
            FROM quotation_items
            WHERE quotation_id IN ($placeholders)
            ORDER BY quotation_id ASC, id ASC
        ");
        $stmt->execute($quotation_ids);
        $items = $stmt->fetchAll();
        $inventory_items_by_id = app_line_item_load_inventory_items($pdo, $items);
        $summaries = [];

        foreach ($items as $item) {
            $quotation_id = (int) ($item['quotation_id'] ?? 0);
            if ($quotation_id <= 0) {
                continue;
            }

            if (!isset($summaries[$quotation_id])) {
                $summaries[$quotation_id] = [
                    'services' => [],
                    'items' => [],
                    'search' => [],
                ];
            }

            $name = trim((string) ($item['item_name'] ?? ''));
            if ($name !== '') {
                $summaries[$quotation_id]['search'][] = $name;
            }

            if (app_line_item_is_service($item)) {
                vehicle_profile_unique_push($summaries[$quotation_id]['services'], $name);
            } else {
                vehicle_profile_unique_push($summaries[$quotation_id]['items'], vehicle_profile_item_line($item, $inventory_items_by_id));
            }
        }

        return $summaries;
    }
}

if (!function_exists('vehicle_profile_inventory_total_for_vehicle')) {
    function vehicle_profile_inventory_total_for_vehicle(PDO $pdo, $vehicle_id) {
        $vehicle_id = (int) $vehicle_id;

        if ($vehicle_id <= 0
            || !app_table_exists('inventory_transactions')
            || !app_table_exists('inventory_items')
            || !app_column_exists('inventory_transactions', 'item_id')
            || !app_column_exists('inventory_transactions', 'quantity')
            || !app_column_exists('inventory_transactions', 'transaction_type')) {
            return 0.0;
        }

        $conditions = [];
        $params = [];
        $has_reference = app_column_exists('inventory_transactions', 'reference_type')
            && app_column_exists('inventory_transactions', 'reference_id');

        if (app_column_exists('inventory_transactions', 'vehicle_id')) {
            $conditions[] = 't.vehicle_id = ?';
            $params[] = $vehicle_id;
        }

        if (app_column_exists('inventory_transactions', 'quotation_id')
            && app_table_exists('quotations')
            && app_column_exists('quotations', 'vehicle_id')) {
            $conditions[] = 't.quotation_id IN (SELECT q_link.id FROM quotations q_link WHERE q_link.vehicle_id = ?)';
            $params[] = $vehicle_id;

            if ($has_reference) {
                $conditions[] = "(t.reference_type = 'quotation' AND t.reference_id IN (SELECT q_ref.id FROM quotations q_ref WHERE q_ref.vehicle_id = ?))";
                $params[] = $vehicle_id;
            }
        }

        if (app_column_exists('inventory_transactions', 'job_order_id')
            && app_table_exists('job_orders')
            && app_column_exists('job_orders', 'vehicle_id')) {
            $conditions[] = "t.job_order_id IN (SELECT jo_link.id FROM job_orders jo_link WHERE jo_link.vehicle_id = ? AND jo_link.status <> 'cancelled')";
            $params[] = $vehicle_id;

            if ($has_reference) {
                $conditions[] = "(t.reference_type = 'job_order' AND t.reference_id IN (SELECT jo_ref.id FROM job_orders jo_ref WHERE jo_ref.vehicle_id = ? AND jo_ref.status <> 'cancelled'))";
                $params[] = $vehicle_id;
            }
        }

        if ($has_reference
            && app_table_exists('quotation_items')
            && app_column_exists('quotation_items', 'quotation_id')
            && app_table_exists('quotations')
            && app_column_exists('quotations', 'vehicle_id')) {
            $conditions[] = "(
                t.reference_type = 'job_order_item'
                AND t.reference_id IN (
                    SELECT qi_ref.id
                    FROM quotation_items qi_ref
                    INNER JOIN quotations q_item_ref ON q_item_ref.id = qi_ref.quotation_id
                    WHERE q_item_ref.vehicle_id = ?
                )
            )";
            $params[] = $vehicle_id;
        }

        if (empty($conditions)) {
            return 0.0;
        }

        $price_expr = app_column_exists('inventory_items', 'unit_price') ? 'COALESCE(i.unit_price, 0)' : '0';
        $ref_filter = app_column_exists('inventory_transactions', 'reference_type')
            ? "AND (t.reference_type IS NULL OR LOWER(REPLACE(t.reference_type, ' ', '_')) NOT IN ('inter_branch_transfer', 'transfer'))"
            : '';
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(ABS(t.quantity) * $price_expr), 0)
            FROM inventory_transactions t
            INNER JOIN inventory_items i ON i.id = t.item_id
            WHERE LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out'
              $ref_filter
              AND (" . implode(' OR ', $conditions) . ")
        ");
        $stmt->execute($params);

        return (float) $stmt->fetchColumn();
    }
}

if (!function_exists('vehicle_profile_standalone_inventory_total_for_vehicle')) {
    function vehicle_profile_standalone_inventory_total_for_vehicle(PDO $pdo, $vehicle_id) {
        $vehicle_id = (int) $vehicle_id;

        if ($vehicle_id <= 0
            || !app_table_exists('inventory_transactions')
            || !app_table_exists('inventory_items')
            || !app_column_exists('inventory_transactions', 'item_id')
            || !app_column_exists('inventory_transactions', 'quantity')
            || !app_column_exists('inventory_transactions', 'transaction_type')) {
            return 0.0;
        }

        $price_expr = app_column_exists('inventory_items', 'unit_price') ? 'COALESCE(i.unit_price, 0)' : '0';
        $ref_filter = app_column_exists('inventory_transactions', 'reference_type')
            ? "AND (t.reference_type IS NULL OR LOWER(REPLACE(t.reference_type, ' ', '_')) NOT IN ('inter_branch_transfer', 'transfer', 'quotation', 'job_order', 'job_order_item'))"
            : '';
        $quote_filter = app_column_exists('inventory_transactions', 'quotation_id') ? "AND (t.quotation_id IS NULL OR t.quotation_id = 0)" : '';
        $job_filter = app_column_exists('inventory_transactions', 'job_order_id') ? "AND (t.job_order_id IS NULL OR t.job_order_id = 0)" : '';
        $veh_filter = app_column_exists('inventory_transactions', 'vehicle_id') ? "AND t.vehicle_id = ?" : "AND 1=0";

        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(ABS(t.quantity) * $price_expr), 0)
            FROM inventory_transactions t
            INNER JOIN inventory_items i ON i.id = t.item_id
            WHERE LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out'
              $ref_filter
              $quote_filter
              $job_filter
              $veh_filter
        ");
        $stmt->execute([$vehicle_id]);

        return (float) $stmt->fetchColumn();
    }
}

if (!function_exists('vehicle_profile_record_priority')) {
    function vehicle_profile_record_priority($type) {
        $priorities = [
            'quotation' => 40,
            'history' => 30,
            'item' => 20,
            'job' => 10,
        ];

        return $priorities[$type] ?? 0;
    }
}

if (!function_exists('vehicle_profile_linked_record')) {
    function vehicle_profile_linked_record($role, array $record) {
        $label = vehicle_profile_type_label($record['record_type'] ?? '');
        $number = trim((string) ($record['record_number'] ?? ''));

        if ($number !== '') {
            $label .= ' ' . $number;
        }

        return [
            'label' => $label,
            'url' => vehicle_profile_record_url($role, $record),
        ];
    }
}

if (!function_exists('vehicle_profile_event_key')) {
    function vehicle_profile_event_key(array $record, array $job_to_quotation) {
        $record_type = $record['record_type'] ?? '';
        $job_order_id = (int) ($record['job_order_id'] ?? 0);
        $quotation_id = (int) ($record['quotation_id'] ?? 0);

        if ($job_order_id > 0) {
            if ($quotation_id <= 0 && isset($job_to_quotation[$job_order_id])) {
                $quotation_id = (int) $job_to_quotation[$job_order_id];
            }
        }

        $record_date = trim((string) ($record['record_date'] ?? ''));
        $branch_id = (int) ($record['branch_id'] ?? 0);
        if ($record_date !== '' && $branch_id > 0) {
            return 'service-date:' . $record_date . ':branch:' . $branch_id;
        }

        if ($quotation_id > 0) {
            return 'service:' . $quotation_id;
        }

        if ($job_order_id > 0) {
            return 'service-job:' . $job_order_id;
        }

        return ($record['record_type'] ?? 'record') . ':' . (int) ($record['record_id'] ?? 0);
    }
}

if (!function_exists('vehicle_profile_group_records')) {
    function vehicle_profile_group_records($role, array $records) {
        $job_to_quotation = [];
        foreach ($records as $record) {
            $quotation_id = (int) ($record['quotation_id'] ?? 0);
            $job_order_id = (int) ($record['job_order_id'] ?? 0);
            if ($quotation_id > 0 && $job_order_id > 0) {
                $job_to_quotation[$job_order_id] = $quotation_id;
            }
        }

        $events = [];
        foreach ($records as $record) {
            $key = vehicle_profile_event_key($record, $job_to_quotation);
            $record_type = $record['record_type'] ?? '';
            $priority = vehicle_profile_record_priority($record_type);

            if (!isset($events[$key])) {
                $events[$key] = $record;
                $events[$key]['record_type'] = 'event';
                $events[$key]['primary_type'] = $record_type;
                $events[$key]['primary_priority'] = $priority;
                $events[$key]['services_done'] = [];
                $events[$key]['items_used'] = [];
                $events[$key]['linked_records'] = [];
                $events[$key]['notes_collection'] = [];
                $events[$key]['meta_collection'] = [];
                $events[$key]['amount_candidates'] = [];
                $events[$key]['linked_inventory_amount_candidates'] = [];
                $events[$key]['inventory_amount_total'] = 0.0;
                $events[$key]['inventory_amount_keys'] = [];
                $events[$key]['source_type_keys'] = [];
            } elseif ($priority > (int) ($events[$key]['primary_priority'] ?? 0)) {
                $existing = $events[$key];
                $events[$key] = array_merge($record, [
                    'record_type' => 'event',
                    'primary_type' => $record_type,
                    'primary_priority' => $priority,
                    'services_done' => $existing['services_done'],
                    'items_used' => $existing['items_used'],
                    'linked_records' => $existing['linked_records'],
                    'notes_collection' => $existing['notes_collection'],
                    'meta_collection' => $existing['meta_collection'],
                    'amount_candidates' => $existing['amount_candidates'],
                    'linked_inventory_amount_candidates' => $existing['linked_inventory_amount_candidates'],
                    'inventory_amount_total' => $existing['inventory_amount_total'],
                    'inventory_amount_keys' => $existing['inventory_amount_keys'],
                    'source_type_keys' => $existing['source_type_keys'],
                ]);
            }

            if ($record_type !== '') {
                $events[$key]['source_type_keys'][$record_type] = true;
            }

            if (!empty($record['quotation_id']) && empty($events[$key]['quotation_id'])) {
                $events[$key]['quotation_id'] = $record['quotation_id'];
            }

            if (!empty($record['job_order_id']) && empty($events[$key]['job_order_id'])) {
                $events[$key]['job_order_id'] = $record['job_order_id'];
            }

            foreach (vehicle_profile_split_lines($record['service_lines'] ?? '') as $service_line) {
                vehicle_profile_unique_push($events[$key]['services_done'], $service_line);
            }

            $summary = trim((string) ($record['summary'] ?? ''));
            if ($summary !== '' && !in_array($summary, ['Service operation', 'Job order', 'Service history'], true)) {
                vehicle_profile_unique_push($events[$key]['services_done'], $summary);
            }

            foreach (vehicle_profile_split_lines($record['item_lines'] ?? '') as $item_line) {
                vehicle_profile_unique_push_item($events[$key]['items_used'], $item_line);
            }

            $notes = app_format_record_notes($record['notes'] ?? '');
            vehicle_profile_unique_push($events[$key]['notes_collection'], $notes);
            vehicle_profile_unique_push($events[$key]['meta_collection'], $record['meta_text'] ?? '');

            $record_amount = (float) ($record['amount'] ?? 0);
            if ($record_type === 'item') {
                $amount_key = 'item:' . (int) ($record['record_id'] ?? 0);
                if ($amount_key === 'item:0') {
                    $amount_key = implode('|', [
                        $record['record_number'] ?? '',
                        $record['record_date'] ?? '',
                        $record['summary'] ?? '',
                        $record_amount,
                    ]);
                }

                if (!isset($events[$key]['inventory_amount_keys'][$amount_key])) {
                    $events[$key]['inventory_amount_keys'][$amount_key] = true;
                    $events[$key]['inventory_amount_total'] += max(0.0, $record_amount);
                }
            } else {
                $events[$key]['amount_candidates'][] = $record_amount;

                $linked_inventory_amount = (float) ($record['linked_inventory_amount'] ?? 0);
                if ($linked_inventory_amount > 0) {
                    $events[$key]['linked_inventory_amount_candidates'][] = $linked_inventory_amount;
                }
            }

            $linked = vehicle_profile_linked_record($role, $record);
            if ($linked['label'] !== 'Record') {
                $linked_key = $linked['label'] . '|' . $linked['url'];
                $events[$key]['linked_records'][$linked_key] = $linked;
            }
        }

        foreach ($events as &$event) {
            $event['summary'] = vehicle_profile_compact_list($event['services_done'], trim((string) ($event['summary'] ?? 'Service event')));
            $event['item_lines'] = implode(';;', $event['items_used']);
            $event['meta_text'] = vehicle_profile_compact_list($event['meta_collection'], '-');
            $event['notes'] = vehicle_profile_compact_list($event['notes_collection'], '');
            $event['linked_records'] = array_values($event['linked_records']);
            $event['record_count'] = count($event['linked_records']);
            $event['source_types'] = array_keys($event['source_type_keys']);

            $service_amount = !empty($event['amount_candidates'])
                ? max($event['amount_candidates'])
                : max(0.0, (float) ($event['amount'] ?? 0));
            $direct_inventory_amount = (float) ($event['inventory_amount_total'] ?? 0);
            $linked_inventory_amount = !empty($event['linked_inventory_amount_candidates'])
                ? max($event['linked_inventory_amount_candidates'])
                : 0.0;
            $inventory_amount = $direct_inventory_amount > 0
                ? $direct_inventory_amount
                : $linked_inventory_amount;

            $event['service_amount'] = $service_amount;
            $event['inventory_amount'] = $inventory_amount;
            $event['amount'] = $service_amount > 0 ? $service_amount : $inventory_amount;

            unset(
                $event['services_done'],
                $event['items_used'],
                $event['notes_collection'],
                $event['meta_collection'],
                $event['amount_candidates'],
                $event['linked_inventory_amount_candidates'],
                $event['inventory_amount_total'],
                $event['inventory_amount_keys'],
                $event['source_type_keys'],
                $event['primary_priority']
            );
        }
        unset($event);

        usort($events, static function ($a, $b) {
            $date_compare = strcmp((string) ($b['record_date'] ?? ''), (string) ($a['record_date'] ?? ''));
            if ($date_compare !== 0) {
                return $date_compare;
            }

            $created_compare = strcmp((string) ($b['sort_created_at'] ?? ''), (string) ($a['sort_created_at'] ?? ''));
            if ($created_compare !== 0) {
                return $created_compare;
            }

            return (int) ($b['record_id'] ?? 0) <=> (int) ($a['record_id'] ?? 0);
        });

        return $events;
    }
}

if (!function_exists('vehicle_profile_apply_inventory_item_links')) {
    function vehicle_profile_apply_inventory_item_links(array $records) {
        $items_by_job = [];
        $items_by_quotation = [];
        $item_amounts_by_job = [];
        $item_amounts_by_quotation = [];
        $job_to_quotation = [];
        $quotation_to_jobs = [];

        foreach ($records as $record) {
            $job_order_id = (int) ($record['job_order_id'] ?? 0);
            $quotation_id = (int) ($record['quotation_id'] ?? 0);

            if ($job_order_id > 0 && $quotation_id > 0) {
                $job_to_quotation[$job_order_id] = $quotation_id;

                if (!isset($quotation_to_jobs[$quotation_id])) {
                    $quotation_to_jobs[$quotation_id] = [];
                }

                if (!in_array($job_order_id, $quotation_to_jobs[$quotation_id], true)) {
                    $quotation_to_jobs[$quotation_id][] = $job_order_id;
                }
            }
        }

        foreach ($records as $record) {
            if (($record['record_type'] ?? '') !== 'item') {
                continue;
            }

            $item_lines = vehicle_profile_split_lines($record['item_lines'] ?? '');
            if (empty($item_lines)) {
                continue;
            }

            $item_amount = max(0.0, (float) ($record['amount'] ?? 0));
            $item_amount_key = 'item:' . (int) ($record['record_id'] ?? 0);
            if ($item_amount_key === 'item:0') {
                $item_amount_key = implode('|', [
                    $record['record_number'] ?? '',
                    $record['record_date'] ?? '',
                    $record['summary'] ?? '',
                    $item_amount,
                ]);
            }

            $job_order_id = (int) ($record['job_order_id'] ?? 0);
            $quotation_id = (int) ($record['quotation_id'] ?? 0);
            if ($quotation_id <= 0 && $job_order_id > 0 && isset($job_to_quotation[$job_order_id])) {
                $quotation_id = (int) $job_to_quotation[$job_order_id];
            }

            $job_order_ids = $job_order_id > 0 ? [$job_order_id] : [];
            if ($quotation_id > 0 && isset($quotation_to_jobs[$quotation_id])) {
                foreach ($quotation_to_jobs[$quotation_id] as $linked_job_id) {
                    if (!in_array($linked_job_id, $job_order_ids, true)) {
                        $job_order_ids[] = (int) $linked_job_id;
                    }
                }
            }

            foreach ($job_order_ids as $linked_job_id) {
                if (!isset($items_by_job[$linked_job_id])) {
                    $items_by_job[$linked_job_id] = [];
                }
                if (!isset($item_amounts_by_job[$linked_job_id])) {
                    $item_amounts_by_job[$linked_job_id] = [];
                }

                foreach ($item_lines as $item_line) {
                    vehicle_profile_unique_push($items_by_job[$linked_job_id], $item_line);
                }

                $item_amounts_by_job[$linked_job_id][$item_amount_key] = $item_amount;
            }

            if ($quotation_id > 0) {
                if (!isset($items_by_quotation[$quotation_id])) {
                    $items_by_quotation[$quotation_id] = [];
                }
                if (!isset($item_amounts_by_quotation[$quotation_id])) {
                    $item_amounts_by_quotation[$quotation_id] = [];
                }

                foreach ($item_lines as $item_line) {
                    vehicle_profile_unique_push($items_by_quotation[$quotation_id], $item_line);
                }

                $item_amounts_by_quotation[$quotation_id][$item_amount_key] = $item_amount;
            }
        }

        foreach ($records as &$record) {
            if (($record['record_type'] ?? '') === 'item') {
                continue;
            }

            $linked_items = [];
            $linked_item_amounts = [];
            $job_order_id = (int) ($record['job_order_id'] ?? 0);
            $quotation_id = (int) ($record['quotation_id'] ?? 0);
            if ($quotation_id <= 0 && $job_order_id > 0 && isset($job_to_quotation[$job_order_id])) {
                $quotation_id = (int) $job_to_quotation[$job_order_id];
            }

            if ($job_order_id > 0 && isset($items_by_job[$job_order_id])) {
                foreach ($items_by_job[$job_order_id] as $item_line) {
                    vehicle_profile_unique_push($linked_items, $item_line);
                }
            }
            if ($job_order_id > 0 && isset($item_amounts_by_job[$job_order_id])) {
                foreach ($item_amounts_by_job[$job_order_id] as $amount_key => $amount_value) {
                    $linked_item_amounts[$amount_key] = $amount_value;
                }
            }

            if ($quotation_id > 0 && isset($items_by_quotation[$quotation_id])) {
                foreach ($items_by_quotation[$quotation_id] as $item_line) {
                    vehicle_profile_unique_push($linked_items, $item_line);
                }
            }
            if ($quotation_id > 0 && isset($item_amounts_by_quotation[$quotation_id])) {
                foreach ($item_amounts_by_quotation[$quotation_id] as $amount_key => $amount_value) {
                    $linked_item_amounts[$amount_key] = $amount_value;
                }
            }

            if (empty($linked_items)) {
                continue;
            }

            $current_items = vehicle_profile_split_lines($record['item_lines'] ?? '');
            foreach ($linked_items as $item_line) {
                vehicle_profile_unique_push($current_items, $item_line);
            }

            $record['item_lines'] = implode(';;', $current_items);
            $record['item_search_text'] = trim(((string) ($record['item_search_text'] ?? '')) . ' ' . implode(' ', $linked_items));
            if (!empty($linked_item_amounts)) {
                $record['linked_inventory_amount'] = array_sum($linked_item_amounts);
            }
        }
        unset($record);

        return $records;
    }
}

if (!function_exists('vehicle_profile_group_item_sales')) {
    function vehicle_profile_group_item_sales(array $records) {
        $groups = [];

        foreach ($records as $record) {
            $job_order_id = (int) ($record['job_order_id'] ?? 0);
            $quotation_id = (int) ($record['quotation_id'] ?? 0);
            $record_date = (string) ($record['record_date'] ?? '');
            $branch_id = (int) ($record['branch_id'] ?? 0);

            if ($job_order_id > 0) {
                $key = 'job:' . $job_order_id;
            } elseif ($quotation_id > 0) {
                $key = 'quotation:' . $quotation_id;
            } else {
                $key = 'date-branch:' . $record_date . ':' . $branch_id;
            }

            if (!isset($groups[$key])) {
                $groups[$key] = $record;
                $groups[$key]['record_numbers'] = [];
                $groups[$key]['item_entries'] = [];
                $groups[$key]['meta_entries'] = [];
                $groups[$key]['note_entries'] = [];
                $groups[$key]['status_entries'] = [];
                $groups[$key]['amount'] = 0;
                $groups[$key]['record_count'] = 0;
            }

            $groups[$key]['record_count']++;
            $groups[$key]['amount'] += (float) ($record['amount'] ?? 0);

            vehicle_profile_unique_push($groups[$key]['record_numbers'], $record['record_number'] ?? '');

            foreach (vehicle_profile_split_lines($record['item_lines'] ?? '') as $item_line) {
                vehicle_profile_unique_push($groups[$key]['item_entries'], $item_line);
            }

            vehicle_profile_unique_push($groups[$key]['meta_entries'], $record['meta_text'] ?? '');
            vehicle_profile_unique_push($groups[$key]['note_entries'], app_format_record_notes($record['notes'] ?? ''));
            vehicle_profile_unique_push($groups[$key]['status_entries'], vehicle_profile_status_label($record['record_status'] ?? ''));
        }

        foreach ($groups as &$group) {
            $record_count = (int) ($group['record_count'] ?? 0);
            $record_numbers = $group['record_numbers'];
            $item_entries = $group['item_entries'];
            $status_entries = $group['status_entries'];

            if ($record_count > 1 && !empty($record_numbers)) {
                $group['record_number'] = $record_numbers[0] . ' +' . ($record_count - 1) . ' more';
            }

            if ($record_count > 1) {
                $group['summary'] = count($item_entries) . ' inventory products used';
            } elseif (!empty($item_entries)) {
                $group['summary'] = $item_entries[0];
            }

            $group['item_lines'] = implode(';;', $item_entries);
            $group['meta_text'] = vehicle_profile_compact_list($group['meta_entries'], '-');
            $group['notes'] = vehicle_profile_compact_list($group['note_entries'], '');
            $group['item_search_text'] = trim(implode(' ', array_merge(
                $record_numbers,
                $item_entries,
                $group['meta_entries'],
                $group['note_entries']
            )));

            if (count($status_entries) > 1) {
                $group['record_status'] = 'mixed';
            }

            unset(
                $group['record_numbers'],
                $group['item_entries'],
                $group['meta_entries'],
                $group['note_entries'],
                $group['status_entries'],
                $group['record_count']
            );
        }
        unset($group);

        return array_values($groups);
    }
}

$allowed_tabs = [
    'overview' => 'Service Visits',
    'timeline' => 'Visit Timeline',
    'jobs' => 'Job Orders',
    'items' => 'Items Used/Sold',
    'ownership' => 'Ownership',
    'audit' => 'Audit Trail',
];
$active_tab = $_GET['tab'] ?? 'overview';
if (!array_key_exists($active_tab, $allowed_tabs)) {
    $active_tab = 'overview';
}

$allowed_statuses = [
    'all' => 'All Statuses',
    'pending' => 'Pending',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'waiting' => 'Waiting',
    'in-progress' => 'In Progress',
    'completed' => 'Completed',
    'tagged' => 'Tagged',
    'adjustment' => 'Adjustment',
    'damage' => 'Damage',
    'archived' => 'Archived',
];
$status_filter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
if (!array_key_exists($status_filter, $allowed_statuses)) {
    $status_filter = 'all';
}

$branch_filter = trim((string) ($_GET['branch'] ?? ''));
$branch_filter = $branch_filter !== '' ? intval($branch_filter) : '';
if ($branch_filter !== '' && $branch_filter <= 0) {
    $branch_filter = '';
}

$search_filter = trim((string) ($_GET['search'] ?? ''));
if (function_exists('mb_substr')) {
    $search_filter = mb_substr($search_filter, 0, 100);
} else {
    $search_filter = substr($search_filter, 0, 100);
}

$date_filter = record_date_filter_current('all');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;
$role = ($user['role'] ?? '') === 'admin' ? 'admin' : 'front-desk';

try {
    $vehicle_stmt = $pdo->prepare("
        SELECT v.*, c.name AS customer_name, c.phone_mobile, c.email, c.contact, c.address, c.city,
               vb.name AS vehicle_branch_name
        FROM vehicles v
        LEFT JOIN customers c ON v.customer_id = c.id
        LEFT JOIN branches vb ON vb.id = v.branch_id
        WHERE v.id = ?
    ");
    $vehicle_stmt->execute([$vehicle_id]);
    $vehicle = $vehicle_stmt->fetch();

    if (!$vehicle) {
        redirect('../');
    }

    $branches = $pdo->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name ASC")->fetchAll();
    $branch_labels = [];
    foreach ($branches as $branch) {
        $branch_labels[(int) $branch['id']] = vehicle_profile_branch_label($branch['name'] ?? '', 'Branch');
    }

    $selected_branch_label = $branch_filter !== '' ? ($branch_labels[(int) $branch_filter] ?? 'Selected branch') : '';

    $include_archived_records = strtolower((string) ($vehicle['status'] ?? 'active')) === 'inactive';
    $quotation_status_sql = $include_archived_records ? '' : "AND q.status <> 'archived'";
    $job_status_sql = $include_archived_records ? "AND jo.status <> 'cancelled'" : "AND jo.status NOT IN ('archived', 'cancelled')";

    $stats_stmt = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM service_history WHERE vehicle_id = ?) AS service_count,
            (SELECT COUNT(*) FROM quotations WHERE vehicle_id = ? AND status <> 'archived') AS quotation_count,
            (SELECT COUNT(*) FROM job_orders WHERE vehicle_id = ? AND status NOT IN ('archived', 'cancelled')) AS job_count,
            (SELECT COALESCE(SUM(total_cost), 0) FROM service_history WHERE vehicle_id = ?) AS total_spent,
            (SELECT MAX(service_date) FROM service_history WHERE vehicle_id = ?) AS last_service_date,
            (SELECT MAX(mileage_at_service) FROM service_history WHERE vehicle_id = ?) AS last_service_mileage
    ");
    $stats_stmt->execute([$vehicle_id, $vehicle_id, $vehicle_id, $vehicle_id, $vehicle_id, $vehicle_id]);
    $stats = $stats_stmt->fetch() ?: [];
    $service_spent = (float) ($stats['total_spent'] ?? 0);
    $inventory_spent = vehicle_profile_standalone_inventory_total_for_vehicle($pdo, $vehicle_id);
    $stats['service_spent'] = $service_spent;
    $stats['inventory_spent'] = $inventory_spent;
    $stats['total_spent'] = $service_spent + $inventory_spent;

    $last_branch_stmt = $pdo->prepare("
        SELECT x.branch_id, b.name AS branch_name
        FROM (
            SELECT branch_id, service_date AS record_date, created_at, id
            FROM service_history
            WHERE vehicle_id = ?
            UNION ALL
            SELECT branch_id, quotation_date AS record_date, created_at, id
            FROM quotations
            WHERE vehicle_id = ? AND status <> 'archived'
            UNION ALL
            SELECT branch_id, job_date AS record_date, created_at, id
            FROM job_orders
            WHERE vehicle_id = ? AND status NOT IN ('archived', 'cancelled')
        ) x
        LEFT JOIN branches b ON b.id = x.branch_id
        WHERE x.branch_id IS NOT NULL
        ORDER BY x.record_date DESC, x.created_at DESC, x.id DESC
        LIMIT 1
    ");
    $last_branch_stmt->execute([$vehicle_id, $vehicle_id, $vehicle_id]);
    $last_branch = $last_branch_stmt->fetch();
    $last_visited_branch = vehicle_profile_branch_label(
        $last_branch['branch_name'] ?? ($vehicle['vehicle_branch_name'] ?? ''),
        'No branch visit yet'
    );

    $ownership_history = [];
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

    $item_summary_sql = "
        SELECT quotation_id,
               GROUP_CONCAT(item_name ORDER BY id SEPARATOR ', ') AS item_names,
               GROUP_CONCAT(CONCAT(item_name, ' (', COALESCE(quantity, 1), 'x)') ORDER BY id SEPARATOR ';;') AS item_lines
        FROM quotation_items
        GROUP BY quotation_id
    ";
    $inventory_size_expr = app_column_exists('inventory_items', 'size') ? "COALESCE(i.size, '')" : "''";
    $inventory_sku_expr = app_column_exists('inventory_items', 'sku') ? "NULLIF(i.sku, '')" : "NULL";
    $inventory_serial_expr = app_column_exists('inventory_items', 'serial_number') ? "NULLIF(i.serial_number, '')" : "NULL";
    $inventory_price_expr = app_column_exists('inventory_items', 'unit_price') ? 'COALESCE(i.unit_price, 0)' : '0';

    $sources = [];
    $source_params = [];

    $sources[] = "
        SELECT 'quotation' AS record_type,
               q.id AS record_id,
               q.quotation_number AS record_number,
               q.quotation_date AS record_date,
               q.created_at AS sort_created_at,
               q.updated_at AS sort_updated_at,
               q.status AS record_status,
               q.total_amount AS amount,
               q.branch_id,
               b.name AS branch_name,
               COALESCE(NULLIF(qi.item_names, ''), 'Service operation') AS summary,
               CONCAT_WS(' | ',
                   IF(q.inspection_mileage IS NOT NULL AND q.inspection_mileage > 0, CONCAT(FORMAT(q.inspection_mileage, 0), ' km'), NULL),
                   NULLIF(q.inspection_complaint, '')
               ) AS meta_text,
               q.notes,
               qi.item_lines,
               q.id AS quotation_id,
               NULL AS job_order_id
        FROM quotations q
        LEFT JOIN branches b ON b.id = q.branch_id
        LEFT JOIN ($item_summary_sql) qi ON qi.quotation_id = q.id
        WHERE q.vehicle_id = ?
          $quotation_status_sql
    ";
    $source_params[] = $vehicle_id;

    $sources[] = "
        SELECT 'job' AS record_type,
               jo.id AS record_id,
               jo.job_number AS record_number,
               jo.job_date AS record_date,
               jo.created_at AS sort_created_at,
               jo.updated_at AS sort_updated_at,
               jo.status AS record_status,
               COALESCE(q.total_amount, 0) AS amount,
               jo.branch_id,
               b.name AS branch_name,
               COALESCE(NULLIF(qi.item_names, ''), NULLIF(jo.notes, ''), 'Job order') AS summary,
               CONCAT_WS(' | ',
                   NULLIF(jo.assigned_technician_name, ''),
                   IF(jo.scheduled_start_time IS NOT NULL, CONCAT('Scheduled ', TIME_FORMAT(jo.scheduled_start_time, '%H:%i')), NULL)
               ) AS meta_text,
               jo.notes,
               qi.item_lines,
               jo.quotation_id,
               jo.id AS job_order_id
        FROM job_orders jo
        LEFT JOIN quotations q ON q.id = jo.quotation_id
        LEFT JOIN branches b ON b.id = jo.branch_id
        LEFT JOIN ($item_summary_sql) qi ON qi.quotation_id = jo.quotation_id
        WHERE jo.vehicle_id = ?
          $job_status_sql
    ";
    $source_params[] = $vehicle_id;

    $sources[] = "
        SELECT 'history' AS record_type,
               sh.id AS record_id,
               CONCAT('SH', LPAD(sh.id, 6, '0')) AS record_number,
               sh.service_date AS record_date,
               sh.created_at AS sort_created_at,
               sh.created_at AS sort_updated_at,
               'completed' AS record_status,
               COALESCE(sh.total_cost, 0) AS amount,
               sh.branch_id,
               b.name AS branch_name,
               COALESCE(NULLIF(sh.services_description, ''), 'Service history') AS summary,
               IF(sh.mileage_at_service IS NOT NULL AND sh.mileage_at_service > 0, CONCAT(FORMAT(sh.mileage_at_service, 0), ' km'), NULL) AS meta_text,
               sh.notes,
               qi.item_lines,
               sh.quotation_id,
               sh.job_order_id
        FROM service_history sh
        LEFT JOIN branches b ON b.id = sh.branch_id
        LEFT JOIN ($item_summary_sql) qi ON qi.quotation_id = sh.quotation_id
        WHERE sh.vehicle_id = ?
    ";
    $source_params[] = $vehicle_id;

    if (app_table_exists('inventory_transactions')
        && app_table_exists('inventory_items')
        && app_column_exists('inventory_transactions', 'item_id')
        && app_column_exists('inventory_transactions', 'quantity')
        && app_column_exists('inventory_transactions', 'transaction_type')
        && app_column_exists('inventory_transactions', 'quotation_id')
        && app_column_exists('inventory_transactions', 'job_order_id')) {
        $inventory_link_conditions = [];
        $inventory_link_params = [];
        $has_inventory_reference = app_column_exists('inventory_transactions', 'reference_type')
            && app_column_exists('inventory_transactions', 'reference_id');

        if (app_column_exists('inventory_transactions', 'vehicle_id')) {
            $inventory_link_conditions[] = 't.vehicle_id = ?';
            $inventory_link_params[] = $vehicle_id;
        }

        if (app_table_exists('quotations') && app_column_exists('quotations', 'vehicle_id')) {
            $inventory_link_conditions[] = 't.quotation_id IN (SELECT q_link.id FROM quotations q_link WHERE q_link.vehicle_id = ?)';
            $inventory_link_params[] = $vehicle_id;

            if ($has_inventory_reference) {
                $inventory_link_conditions[] = "(t.reference_type = 'quotation' AND t.reference_id IN (SELECT q_ref.id FROM quotations q_ref WHERE q_ref.vehicle_id = ?))";
                $inventory_link_params[] = $vehicle_id;
            }
        }

        if (app_table_exists('job_orders') && app_column_exists('job_orders', 'vehicle_id')) {
            $inventory_link_conditions[] = "t.job_order_id IN (SELECT jo_link.id FROM job_orders jo_link WHERE jo_link.vehicle_id = ? AND jo_link.status <> 'cancelled')";
            $inventory_link_params[] = $vehicle_id;

            if ($has_inventory_reference) {
                $inventory_link_conditions[] = "(t.reference_type = 'job_order' AND t.reference_id IN (SELECT jo_ref.id FROM job_orders jo_ref WHERE jo_ref.vehicle_id = ? AND jo_ref.status <> 'cancelled'))";
                $inventory_link_params[] = $vehicle_id;
            }
        }

        if ($has_inventory_reference
            && app_table_exists('quotation_items')
            && app_column_exists('quotation_items', 'quotation_id')
            && app_table_exists('quotations')
            && app_column_exists('quotations', 'vehicle_id')) {
            $inventory_link_conditions[] = "(
                t.reference_type = 'job_order_item'
                AND t.reference_id IN (
                    SELECT qi_ref.id
                    FROM quotation_items qi_ref
                    INNER JOIN quotations q_item_ref ON q_item_ref.id = qi_ref.quotation_id
                    WHERE q_item_ref.vehicle_id = ?
                )
            )";
            $inventory_link_params[] = $vehicle_id;
        }

        if (!empty($inventory_link_conditions)) {
            $inventory_link_sql = '(' . implode(' OR ', $inventory_link_conditions) . ") AND LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out' AND (t.reference_type IS NULL OR LOWER(REPLACE(t.reference_type, ' ', '_')) NOT IN ('inter_branch_transfer', 'transfer'))";

        $sources[] = "
            SELECT 'item' AS record_type,
                   t.id AS record_id,
                   CONCAT('INV-', LPAD(t.id, 6, '0')) AS record_number,
                   DATE(t.created_at) AS record_date,
                   t.created_at AS sort_created_at,
                   t.created_at AS sort_updated_at,
                   CASE WHEN LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out' THEN 'tagged' ELSE t.transaction_type END AS record_status,
                   ABS(t.quantity) * $inventory_price_expr AS amount,
                   i.branch_id,
                   b.name AS branch_name,
                   CONCAT(
                       i.item_name,
                       CASE WHEN $inventory_size_expr <> '' THEN CONCAT(' - ', $inventory_size_expr) ELSE '' END
                   ) AS summary,
                   CONCAT_WS(' | ',
                       $inventory_sku_expr,
                       $inventory_serial_expr,
                       CONCAT(ABS(t.quantity), ' ', REPLACE(LOWER(REPLACE(t.transaction_type, ' ', '_')), '_', ' '))
                   ) AS meta_text,
                   t.notes,
                   CONCAT(
                       i.item_name,
                       CASE WHEN $inventory_size_expr <> '' THEN CONCAT(' - ', $inventory_size_expr) ELSE '' END,
                       ' (', ABS(t.quantity), 'x)'
                   ) AS item_lines,
                   t.quotation_id,
                   t.job_order_id
            FROM inventory_transactions t
            INNER JOIN inventory_items i ON i.id = t.item_id
            LEFT JOIN branches b ON b.id = i.branch_id
            WHERE $inventory_link_sql
        ";
            $source_params = array_merge($source_params, $inventory_link_params);
        }
    }

    $activity_records = [];
    $total_records = 0;
    $total_pages = 1;

    if ($active_tab !== 'ownership') {
        $is_raw_activity_tab = in_array($active_tab, ['items', 'audit'], true);
        $type_filters = [
            'items' => ['item'],
        ];

        $outer_where = ['1=1'];
        $outer_params = [];

        if ($is_raw_activity_tab && isset($type_filters[$active_tab])) {
            $placeholders = implode(',', array_fill(0, count($type_filters[$active_tab]), '?'));
            $outer_where[] = "record_type IN ($placeholders)";
            $outer_params = array_merge($outer_params, $type_filters[$active_tab]);
        }

        if ($branch_filter !== '') {
            $outer_where[] = 'branch_id = ?';
            $outer_params[] = (int) $branch_filter;
        }

        if ($is_raw_activity_tab && $status_filter !== 'all') {
            $outer_where[] = 'record_status = ?';
            $outer_params[] = $status_filter;
        }

        $date_params = [];
        $date_condition = record_date_filter_condition('record_date', $date_filter, $date_params);
        if ($date_condition !== '') {
            $outer_where[] = $date_condition;
            $outer_params = array_merge($outer_params, $date_params);
        }

        if ($is_raw_activity_tab && $search_filter !== '') {
            foreach (app_search_terms($search_filter) as $term) {
                $outer_where[] = "(
                    record_number LIKE ?
                    OR COALESCE(summary, '') LIKE ?
                    OR COALESCE(meta_text, '') LIKE ?
                    OR COALESCE(notes, '') LIKE ?
                    OR COALESCE(item_lines, '') LIKE ?
                    OR COALESCE(branch_name, '') LIKE ?
                )";
                $outer_params = array_merge($outer_params, array_fill(0, 6, '%' . $term . '%'));
            }
        }

        $activity_from = '(' . implode(' UNION ALL ', $sources) . ') vehicle_activity';
        $activity_where_sql = implode(' AND ', $outer_where);
        $activity_params = array_merge($source_params, $outer_params);

        $activity_stmt = $pdo->prepare("
            SELECT *
            FROM $activity_from
            WHERE $activity_where_sql
            ORDER BY record_date DESC, sort_created_at DESC, record_id DESC
        ");
        $activity_stmt->execute($activity_params);
        $raw_activity_records = $activity_stmt->fetchAll();

        $quotation_ids = [];
        foreach ($raw_activity_records as $record) {
            $quotation_id = (int) ($record['quotation_id'] ?? 0);
            if ($quotation_id > 0) {
                $quotation_ids[$quotation_id] = $quotation_id;
            }
        }

        $item_summaries = vehicle_profile_load_item_summaries($pdo, $quotation_ids);
        foreach ($raw_activity_records as &$record) {
            $quotation_id = (int) ($record['quotation_id'] ?? 0);
            $summary = $quotation_id > 0 ? ($item_summaries[$quotation_id] ?? null) : null;
            $record_type = $record['record_type'] ?? '';
            $transaction_item_lines = trim((string) ($record['item_lines'] ?? ''));

            if ($summary !== null) {
                $record['service_lines'] = implode(';;', $summary['services']);
                $summary_item_lines = implode(';;', $summary['items']);

                if ($record_type === 'item') {
                    $record['item_lines'] = $transaction_item_lines !== ''
                        ? vehicle_profile_item_line_with_amount($transaction_item_lines, $record['amount'] ?? 0)
                        : $summary_item_lines;
                    $record['item_search_text'] = trim(implode(' ', array_merge(
                        [$record['item_lines']],
                        $summary['services'],
                        $summary['items'],
                        $summary['search']
                    )));
                } else {
                    $record['item_lines'] = $summary_item_lines;
                    $record['item_search_text'] = trim(implode(' ', array_merge($summary['services'], $summary['items'], $summary['search'])));

                    if (!empty($summary['services'])) {
                        $record['summary'] = implode(', ', $summary['services']);
                    } elseif (!empty($summary['items'])) {
                        $record['summary'] = 'Items used: ' . vehicle_profile_compact_list($summary['items']);
                    }
                }
            } else {
                $record['service_lines'] = '';
                if ($record_type === 'item') {
                    $record['item_lines'] = vehicle_profile_item_line_with_amount($transaction_item_lines, $record['amount'] ?? 0);
                }
                $record['item_search_text'] = (string) ($record['item_lines'] ?? '');
            }
        }
        unset($record);

        $raw_activity_records = vehicle_profile_apply_inventory_item_links($raw_activity_records);

        if ($active_tab === 'items') {
            $display_records = vehicle_profile_group_item_sales($raw_activity_records);
        } elseif ($active_tab === 'jobs') {
            $display_records = array_values(array_filter($raw_activity_records, static function ($record) {
                return ($record['record_type'] ?? '') === 'job';
            }));
        } elseif ($active_tab === 'audit') {
            $display_records = $raw_activity_records;
        } else {
            // 'overview' (Service Visits) and 'timeline' (Visit Timeline)
            $grouped = vehicle_profile_group_records($role, $raw_activity_records);
            // Service Visits tab should only show actual service visits (QT, SH, JO) and not standalone inventory transactions
            $display_records = array_values(array_filter($grouped, static function ($record) {
                $primary_type = $record['primary_type'] ?? ($record['record_type'] ?? '');
                return $primary_type !== 'item';
            }));
        }

        if ($active_tab === 'timeline') {
            $display_records = array_values(array_filter($display_records, static function ($record) {
                return ($record['record_type'] ?? '') !== 'job';
            }));
        }

        if (!$is_raw_activity_tab && $status_filter !== 'all') {
            $display_records = array_values(array_filter($display_records, static function ($record) use ($status_filter) {
                return strtolower((string) ($record['record_status'] ?? '')) === $status_filter;
            }));
        }

        if (!$is_raw_activity_tab && $search_filter !== '') {
            $terms = app_search_terms($search_filter);
            $display_records = array_values(array_filter($display_records, static function ($record) use ($terms) {
                $linked_text = '';
                foreach (($record['linked_records'] ?? []) as $linked_record) {
                    $linked_text .= ' ' . ($linked_record['label'] ?? '');
                }

                $haystack = strtolower(implode(' ', [
                    $record['record_number'] ?? '',
                    $record['summary'] ?? '',
                    $record['meta_text'] ?? '',
                    $record['notes'] ?? '',
                    $record['item_lines'] ?? '',
                    $record['item_search_text'] ?? '',
                    $record['branch_name'] ?? '',
                    $linked_text,
                ]));

                foreach ($terms as $term) {
                    if (!str_contains($haystack, strtolower($term))) {
                        return false;
                    }
                }

                return true;
            }));
        }

        $total_records = count($display_records);
        $total_pages = max(1, (int) ceil($total_records / $per_page));
        if ($page > $total_pages) {
            $page = $total_pages;
        }
        $offset = ($page - 1) * $per_page;
        $activity_records = array_slice($display_records, $offset, $per_page);
    }
} catch (Exception $e) {
    error_log('Vehicle profile workspace error: ' . $e->getMessage());
    redirect('../');
}

$vehicle_name = trim(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? ''));
$vehicle_name = $vehicle_name !== '' ? $vehicle_name : 'Vehicle';
$condition = strtolower((string) ($vehicle['condition'] ?? 'good'));
$condition_class = vehicle_profile_status_class($condition);
$vehicle_status = strtolower((string) ($vehicle['status'] ?? 'active'));
$vehicle_status_class = vehicle_profile_status_class($vehicle_status);
$clear_date_filter = $date_filter;
$clear_date_filter['scope'] = 'all';

$filter_hidden = [
    'id' => $vehicle_id,
    'tab' => $active_tab !== 'overview' ? $active_tab : '',
    'branch' => $branch_filter !== '' ? $branch_filter : '',
    'status' => $status_filter !== 'all' ? $status_filter : '',
    'search' => $search_filter,
];

$record_sections = [];
if ($active_tab !== 'ownership') {
    if ($active_tab === 'overview') {
        $record_sections = [
            [
                'key' => 'service-visits',
                'title' => 'Service Visits',
                'subtitle' => 'One row per service date, combining service operation, job order, and issued inventory.',
                'empty' => 'No service visit records match the current filters.',
                'records' => $activity_records,
            ],
        ];
    } else {
        $section_subtitles = [
            'timeline' => 'Chronological service dates with full visit details grouped together.',
            'jobs' => 'Work orders and technician progress records for this vehicle.',
            'items' => 'Inventory products sold or used for this vehicle.',
            'audit' => 'Raw source records for checking service history, job orders, service operations, and inventory transactions.',
        ];

        $record_sections = [
            [
                'key' => $active_tab,
                'title' => $allowed_tabs[$active_tab] ?? 'Records',
                'subtitle' => $section_subtitles[$active_tab] ?? 'Records matching the current filters.',
                'empty' => 'No records match the current filters.',
                'records' => $activity_records,
            ],
        ];
    }
}

$has_activity_records = false;
foreach ($record_sections as $record_section) {
    if (!empty($record_section['records'])) {
        $has_activity_records = true;
        break;
    }
}
?>

<?php require_once __DIR__ . '/header.php'; ?>
<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="vehicle-workspace-page">
    <section class="vehicle-workspace-hero">
        <div>
            <h1><i class="fas fa-car-side"></i> Vehicle Profile</h1>
            <p>
                <?php echo esc_html($vehicle_name); ?>
                <?php if (!empty($vehicle['plate_number'])): ?>
                    <span><?php echo esc_html($vehicle['plate_number']); ?></span>
                <?php endif; ?>
                <span><?php echo esc_html($vehicle['customer_name'] ?? '-'); ?></span>
                <span>Last visited: <?php echo esc_html($last_visited_branch); ?></span>
            </p>
        </div>
        <div class="vehicle-workspace-actions">
            <a href="../" class="vehicle-workspace-btn secondary">
                <i class="fas fa-arrow-left"></i>
                <span>Back</span>
            </a>
            <a href="/hwtires/<?php echo esc_attr($role); ?>/vehicles/history-summary.php?id=<?php echo (int) $vehicle_id; ?>" class="vehicle-workspace-btn secondary">
                <i class="fas fa-file-lines"></i>
                <span>Vehicle History Summary</span>
            </a>
            <a href="/hwtires/<?php echo esc_attr($role); ?>/customers/profile.php?id=<?php echo (int) ($vehicle['customer_id'] ?? 0); ?>" class="vehicle-workspace-btn primary">
                <i class="fas fa-user"></i>
                <span>Customer Profile</span>
            </a>
        </div>
    </section>

    <?php
    $flash_message = get_flash_message();
    if ($flash_message):
    ?>
        <div class="alert alert-<?php echo esc_attr($flash_message['type']); ?> alert-dismissible fade show" role="alert">
            <?php echo esc_html($flash_message['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <section class="vehicle-workspace-summary" aria-label="Vehicle summary">
        <article>
            <span>Total Services</span>
            <strong><?php echo number_format((int) ($stats['service_count'] ?? 0)); ?></strong>
        </article>
        <article>
            <span>Job Orders</span>
            <strong><?php echo number_format((int) ($stats['job_count'] ?? 0)); ?></strong>
        </article>
        <article>
            <span>Total Spent</span>
            <strong><?php echo vehicle_profile_money($stats['total_spent'] ?? 0); ?></strong>
        </article>
        <article>
            <span>Last Service</span>
            <strong><?php echo vehicle_profile_short_date($stats['last_service_date'] ?? ''); ?></strong>
        </article>
    </section>

    <nav class="vehicle-workspace-tabs" aria-label="Vehicle profile sections">
        <?php foreach ($allowed_tabs as $tab_key => $tab_label): ?>
            <a href="<?php echo esc_attr(vehicle_profile_filter_url($vehicle_id, $tab_key, $branch_filter, $status_filter, $search_filter, 1, $date_filter)); ?>"
               class="<?php echo $active_tab === $tab_key ? 'active' : ''; ?>">
                <?php echo esc_html($tab_label); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($active_tab !== 'ownership'): ?>
        <section class="vehicle-workspace-filter-card" id="vehicle-profile-records">
            <form method="GET" action="<?php echo esc_attr($_SERVER['PHP_SELF'] ?? 'profile.php'); ?>#vehicle-profile-records" class="vehicle-workspace-filter">
                <input type="hidden" name="id" value="<?php echo (int) $vehicle_id; ?>">
                <?php if ($active_tab !== 'overview'): ?>
                    <input type="hidden" name="tab" value="<?php echo esc_attr($active_tab); ?>">
                <?php endif; ?>
                <?php record_date_filter_hidden_inputs(record_date_filter_query_params($date_filter)); ?>

                <label>
                    <span>Branch</span>
                    <select name="branch">
                        <option value="">All Branches</option>
                        <?php foreach ($branches as $branch): ?>
                            <?php $branch_id_option = (int) $branch['id']; ?>
                            <option value="<?php echo $branch_id_option; ?>" <?php echo $branch_filter === $branch_id_option ? 'selected' : ''; ?>>
                                <?php echo esc_html(vehicle_profile_branch_label($branch['name'] ?? '', 'Branch')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Status</span>
                    <select name="status">
                        <?php foreach ($allowed_statuses as $status_key => $status_label): ?>
                            <option value="<?php echo esc_attr($status_key); ?>" <?php echo $status_filter === $status_key ? 'selected' : ''; ?>>
                                <?php echo esc_html($status_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="vehicle-workspace-search">
                    <span>Search</span>
                    <input type="search" name="search" value="<?php echo esc_attr($search_filter); ?>" placeholder="Record number, service, item, note, branch">
                </label>
                <button type="submit" class="vehicle-workspace-btn primary">Apply</button>
                <?php if ($branch_filter !== '' || $status_filter !== 'all' || $search_filter !== '' || ($date_filter['scope'] ?? 'all') !== 'all'): ?>
                    <a class="vehicle-workspace-clear" href="<?php echo esc_attr(vehicle_profile_filter_url($vehicle_id, $active_tab, '', 'all', '', 1, $clear_date_filter)); ?>#vehicle-profile-records">Clear</a>
                <?php endif; ?>
            </form>

            <?php
            record_date_filter_controls($date_filter, $filter_hidden, 'vehicle-profile-records');
            ?>

            <div class="vehicle-workspace-branch-note" role="status">
                <i class="fas fa-circle-info"></i>
                <?php if ($branch_filter !== ''): ?>
                    <span>You are viewing <?php echo esc_html($selected_branch_label); ?> records. Use branch filter to view all branches.</span>
                <?php else: ?>
                    <span>You are viewing records across all branches. Use branch filter to focus on one branch.</span>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <div class="vehicle-workspace-layout">
        <aside class="vehicle-workspace-side">
            <section class="vehicle-workspace-panel">
                <div class="vehicle-workspace-panel-head">
                    <h2><i class="fas fa-info-circle"></i> Vehicle Information</h2>
                    <?php if ($role === 'front-desk'): ?>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editVehicleModal" style="display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; font-size: 12px; font-weight: 600; border-radius: 6px;">
                            <i class="fas fa-pen-to-square"></i> Edit
                        </button>
                    <?php endif; ?>
                </div>
                <div class="vehicle-workspace-facts">
                    <div>
                        <span>Current Owner</span>
                        <strong><?php echo esc_html($vehicle['customer_name'] ?? '-'); ?></strong>
                    </div>
                    <div>
                        <span>Plate Number</span>
                        <strong><?php echo esc_html($vehicle['plate_number'] ?? '-'); ?></strong>
                    </div>
                    <div>
                        <span>Make / Model</span>
                        <strong><?php echo esc_html($vehicle_name); ?></strong>
                    </div>
                    <div>
                        <span>Year</span>
                        <strong><?php echo !empty($vehicle['year']) ? esc_html($vehicle['year']) : '-'; ?></strong>
                    </div>
                    <div>
                        <span>Current Mileage</span>
                        <strong>
                            <?php
                            $current_mileage_val = !empty($vehicle['last_mileage']) ? (int) $vehicle['last_mileage'] : (!empty($stats['last_service_mileage']) ? (int) $stats['last_service_mileage'] : 0);
                            echo $current_mileage_val > 0 ? number_format($current_mileage_val) . ' km' : '-';
                            ?>
                        </strong>
                    </div>
                    <div>
                        <span>Color</span>
                        <strong><?php echo esc_html($vehicle['color'] ?? '-'); ?></strong>
                    </div>
                    <div>
                        <span>Condition</span>
                        <strong><span class="vehicle-workspace-status status-<?php echo esc_attr($condition_class); ?>"><?php echo esc_html(vehicle_profile_status_label($condition)); ?></span></strong>
                    </div>
                    <div>
                        <span>Status</span>
                        <strong><span class="vehicle-workspace-status status-<?php echo esc_attr($vehicle_status_class); ?>"><?php echo esc_html(vehicle_profile_status_label($vehicle_status)); ?></span></strong>
                    </div>
                </div>
            </section>

            <section class="vehicle-workspace-panel">
                <div class="vehicle-workspace-panel-head">
                    <h2><i class="fas fa-users"></i> Ownership Preview</h2>
                    <span><?php echo count($ownership_history); ?> owner<?php echo count($ownership_history) === 1 ? '' : 's'; ?></span>
                </div>
                <div class="vehicle-workspace-owner-list compact">
                    <?php foreach (array_slice($ownership_history, 0, 3) as $ownership): ?>
                        <article class="<?php echo !empty($ownership['is_current']) ? 'current' : ''; ?>">
                            <strong><?php echo esc_html($ownership['owner_name'] ?? '-'); ?></strong>
                            <span>
                                <?php echo vehicle_profile_short_date($ownership['owned_from'] ?? ''); ?>
                                -
                                <?php echo !empty($ownership['owned_until']) ? vehicle_profile_short_date($ownership['owned_until']) : 'Present'; ?>
                            </span>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        </aside>

        <section class="vehicle-workspace-panel vehicle-workspace-record-panel">
            <?php if ($active_tab === 'ownership'): ?>
                <div class="vehicle-workspace-panel-head">
                    <h2><i class="fas fa-users"></i> Ownership History</h2>
                    <span>Current and previous owners</span>
                </div>
                <div class="vehicle-workspace-owner-list full">
                    <?php foreach ($ownership_history as $ownership): ?>
                        <article class="<?php echo !empty($ownership['is_current']) ? 'current' : ''; ?>">
                            <div>
                                <strong><?php echo esc_html($ownership['owner_name'] ?? '-'); ?></strong>
                                <p><?php echo esc_html(($ownership['phone_mobile'] ?? '') ?: (($ownership['contact'] ?? '') ?: '-')); ?></p>
                            </div>
                            <div>
                                <span>
                                    <?php echo vehicle_profile_short_date($ownership['owned_from'] ?? ''); ?>
                                    -
                                    <?php echo !empty($ownership['owned_until']) ? vehicle_profile_short_date($ownership['owned_until']) : 'Present'; ?>
                                </span>
                                <?php if (!empty($ownership['is_current'])): ?>
                                    <em>Current Owner</em>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($ownership['transfer_notes'])): ?>
                                <p><?php echo esc_html($ownership['transfer_notes']); ?></p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="vehicle-workspace-panel-head">
                    <h2><i class="fas fa-list-check"></i> <?php echo esc_html($allowed_tabs[$active_tab]); ?></h2>
                    <?php
                    $activity_count_label = in_array($active_tab, ['overview', 'timeline'], true) ? 'visits' : 'records';
                    ?>
                    <span>Showing <?php echo count($activity_records); ?> of <?php echo (int) $total_records; ?> <?php echo esc_html($activity_count_label); ?></span>
                </div>

                <?php if (!$has_activity_records): ?>
                    <div class="vehicle-workspace-empty">No records match the current filters.</div>
                <?php else: ?>
                    <div class="vehicle-workspace-record-sections">
                        <?php foreach ($record_sections as $section): ?>
                            <?php
                            $section_records = $section['records'];
                            $section_count = count($section_records);
                            $section_key = preg_replace('/[^a-z0-9_-]+/i', '-', (string) $section['key']);
                            $section_count_label = in_array($active_tab, ['overview', 'timeline'], true) ? 'visit' : 'record';
                            ?>
                            <section class="vehicle-workspace-record-section" data-vehicle-record-section aria-labelledby="vehicle-section-<?php echo esc_attr($section_key); ?>">
                                <div class="vehicle-workspace-section-head">
                                    <div>
                                        <h3 id="vehicle-section-<?php echo esc_attr($section_key); ?>"><?php echo esc_html($section['title']); ?></h3>
                                        <p><?php echo esc_html($section['subtitle']); ?></p>
                                    </div>
                                    <span class="vehicle-workspace-section-count">
                                        <?php echo number_format($section_count); ?> <?php echo esc_html($section_count_label); ?><?php echo $section_count === 1 ? '' : 's'; ?>
                                    </span>
                                </div>

                                <?php if (empty($section_records)): ?>
                                    <div class="vehicle-workspace-section-empty"><?php echo esc_html($section['empty']); ?></div>
                                <?php else: ?>
                                    <?php
                                    $record_heading = 'Record';
                                    if (in_array($active_tab, ['overview', 'timeline'], true)) {
                                        $record_heading = 'Visit';
                                    } elseif ($active_tab === 'jobs') {
                                        $record_heading = 'Job #';
                                    } elseif ($active_tab === 'items') {
                                        $record_heading = 'Sale';
                                    }
                                    $inline_action_label = in_array($active_tab, ['overview', 'timeline'], true)
                                        ? 'View visit details'
                                        : 'View record details';
                                    ?>
                                    <div class="vehicle-workspace-table-wrap">
                                        <table class="vehicle-workspace-table">
                                            <thead>
                                                <tr>
                                                    <th><?php echo esc_html($record_heading); ?></th>
                                                    <th>Date</th>
                                                    <th>Branch</th>
                                                    <th>Items Used</th>
                                                    <th>Status</th>
                                                    <th class="text-end">Amount</th>
                                                    <th class="text-end">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($section_records as $index => $record): ?>
                                                    <?php
                                                    $payload = vehicle_profile_record_payload($role, $record);
                                                    $payload_json = htmlspecialchars(json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
                                                    $record_url = $payload['url'];
                                                    $status_class = vehicle_profile_status_class($record['record_status'] ?? '');
                                                    $preview_items = $payload['items'];
                                                    ?>
                                                    <tr class="vehicle-workspace-record-row"
                                                        data-vehicle-record-row
                                                        data-record='<?php echo $payload_json; ?>'>
                                                        <td>
                                                            <div class="vehicle-workspace-record-main">
                                                                <strong><?php echo esc_html($payload['title']); ?></strong>
                                                                <span><?php echo esc_html($payload['type']); ?> - <?php echo esc_html($payload['summary']); ?></span>
                                                            </div>
                                                        </td>
                                                        <td><?php echo esc_html($payload['date']); ?></td>
                                                        <td><span class="branch-badge"><?php echo esc_html($payload['branch']); ?></span></td>
                                                        <td>
                                                            <div class="vehicle-workspace-item-list-preview">
                                                                <?php if (empty($preview_items)): ?>
                                                                    <span class="muted"><?php echo esc_html($payload['empty_items_label']); ?></span>
                                                                <?php else: ?>
                                                                    <?php foreach (array_slice($preview_items, 0, 2) as $preview_item): ?>
                                                                        <?php $preview_parts = vehicle_profile_item_display_parts($preview_item); ?>
                                                                        <span><?php echo esc_html($preview_parts['label']); ?></span>
                                                                    <?php endforeach; ?>
                                                                    <?php if (count($preview_items) > 2): ?>
                                                                        <em>+<?php echo count($preview_items) - 2; ?> more</em>
                                                                    <?php endif; ?>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                        <td><span class="vehicle-workspace-status status-<?php echo esc_attr($status_class); ?>"><?php echo esc_html($payload['status']); ?></span></td>
                                                        <td class="text-end"><?php echo esc_html($payload['amount']); ?></td>
                                                        <td class="text-end">
                                                            <?php if ($record_url !== ''): ?>
                                                                <a class="vehicle-workspace-icon-btn" href="<?php echo esc_attr($record_url); ?>" aria-label="Open record">
                                                                    <i class="fas fa-eye"></i>
                                                                </a>
                                                            <?php else: ?>
                                                                <button type="button" class="vehicle-workspace-icon-btn" data-vehicle-record-action aria-label="<?php echo esc_attr($inline_action_label); ?>">
                                                                    <i class="fas fa-eye"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <?php $section_first_payload = vehicle_profile_record_payload($role, $section_records[0]); ?>
                                    <div class="vehicle-workspace-detail is-hidden" data-vehicle-record-detail aria-hidden="true">
                                        <div class="vehicle-workspace-detail-main">
                                            <div class="vehicle-workspace-detail-head">
                                                <div>
                                                    <span data-detail-type><?php echo esc_html($section_first_payload['type']); ?></span>
                                                    <h3 data-detail-title><?php echo esc_html($section_first_payload['title']); ?></h3>
                                                    <p data-detail-summary><?php echo esc_html($section_first_payload['summary']); ?></p>
                                                </div>
                                                <strong data-detail-amount><?php echo esc_html($section_first_payload['amount']); ?></strong>
                                            </div>
                                            <dl class="vehicle-workspace-detail-grid">
                                                <div>
                                                    <dt>Date</dt>
                                                    <dd data-detail-date><?php echo esc_html($section_first_payload['date']); ?></dd>
                                                </div>
                                                <div>
                                                    <dt>Branch</dt>
                                                    <dd data-detail-branch><?php echo esc_html($section_first_payload['branch']); ?></dd>
                                                </div>
                                                <div>
                                                    <dt>Status</dt>
                                                    <dd data-detail-status><?php echo esc_html($section_first_payload['status']); ?></dd>
                                                </div>
                                                <div>
                                                    <dt>Details</dt>
                                                    <dd data-detail-meta><?php echo esc_html($section_first_payload['meta'] ?: '-'); ?></dd>
                                                </div>
                                            </dl>
                                            <dl class="vehicle-workspace-detail-grid vehicle-workspace-detail-amounts">
                                                <div>
                                                    <dt>Service Amount</dt>
                                                    <dd data-detail-service-amount><?php echo esc_html($section_first_payload['service_amount']); ?></dd>
                                                </div>
                                                <div>
                                                    <dt>Inventory Issued</dt>
                                                    <dd data-detail-inventory-amount><?php echo esc_html($section_first_payload['inventory_amount']); ?></dd>
                                                </div>
                                                <div>
                                                    <dt>Visit Total</dt>
                                                    <dd data-detail-visit-total><?php echo esc_html($section_first_payload['visit_total']); ?></dd>
                                                </div>
                                            </dl>
                                            <div class="vehicle-workspace-notes">
                                                <span>Notes</span>
                                                <p data-detail-notes><?php echo esc_html($section_first_payload['notes'] ?: '-'); ?></p>
                                            </div>
                                        </div>
                                        <aside class="vehicle-workspace-items">
                                            <section class="vehicle-workspace-detail-box vehicle-workspace-inventory-detail <?php echo empty($section_first_payload['is_inventory']) ? 'is-hidden' : ''; ?>" data-detail-inventory-card>
                                                <h3>Inventory Transaction</h3>
                                                <dl class="vehicle-workspace-inventory-fields" data-detail-inventory-fields>
                                                    <?php if (empty($section_first_payload['inventory_fields'])): ?>
                                                        <div>
                                                            <dt>Details</dt>
                                                            <dd>-</dd>
                                                        </div>
                                                    <?php else: ?>
                                                        <?php foreach ($section_first_payload['inventory_fields'] as $field): ?>
                                                            <div>
                                                                <dt><?php echo esc_html($field['label'] ?? 'Detail'); ?></dt>
                                                                <dd><?php echo esc_html($field['value'] ?? '-'); ?></dd>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </dl>
                                            </section>
                                            <section class="vehicle-workspace-detail-box">
                                                <h3 data-detail-items-title><?php echo esc_html($section_first_payload['items_title']); ?></h3>
                                                <ul data-detail-items>
                                                    <?php if (empty($section_first_payload['items'])): ?>
                                                        <?php echo vehicle_profile_item_line_html($section_first_payload['empty_items_label'], true); ?>
                                                    <?php else: ?>
                                                        <?php foreach ($section_first_payload['items'] as $item_line): ?>
                                                            <?php echo vehicle_profile_item_line_html($item_line); ?>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </ul>
                                            </section>
                                            <section class="vehicle-workspace-detail-box">
                                                <h3>Included Records</h3>
                                                <ul class="vehicle-workspace-linked-records" data-detail-linked-records>
                                                    <?php if (empty($section_first_payload['linked_records'])): ?>
                                                        <li class="is-muted"><span>No linked source record</span></li>
                                                    <?php else: ?>
                                                        <?php foreach ($section_first_payload['linked_records'] as $linked_record): ?>
                                                            <?php
                                                                $linked_label = trim((string) ($linked_record['label'] ?? 'Record'));
                                                                $linked_url = trim((string) ($linked_record['url'] ?? ''));
                                                            ?>
                                                            <li>
                                                                <?php if ($linked_url !== ''): ?>
                                                                    <a href="<?php echo esc_attr($linked_url); ?>"><?php echo esc_html($linked_label); ?></a>
                                                                <?php else: ?>
                                                                    <span><?php echo esc_html($linked_label); ?></span>
                                                                <?php endif; ?>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </ul>
                                            </section>
                                        </aside>
                                    </div>
                                <?php endif; ?>
                            </section>
                        <?php endforeach; ?>
                    </div>

                    <div class="vehicle-workspace-pagination">
                        <span>Page <?php echo (int) $page; ?> of <?php echo (int) $total_pages; ?></span>
                        <div>
                            <?php if ($page > 1): ?>
                                <a href="<?php echo esc_attr(vehicle_profile_filter_url($vehicle_id, $active_tab, $branch_filter, $status_filter, $search_filter, $page - 1, $date_filter)); ?>#vehicle-profile-records">Previous</a>
                            <?php endif; ?>
                            <?php if ($page < $total_pages): ?>
                                <a href="<?php echo esc_attr(vehicle_profile_filter_url($vehicle_id, $active_tab, $branch_filter, $status_filter, $search_filter, $page + 1, $date_filter)); ?>#vehicle-profile-records">Next</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php if ($role === 'front-desk'): ?>
<!-- Edit Vehicle Modal -->
<div class="modal fade" id="editVehicleModal" tabindex="-1" aria-labelledby="editVehicleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="/hwtires/api/vehicles-api.php">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?php echo (int) $vehicle_id; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo esc_attr(generate_csrf_token()); ?>">
                <input type="hidden" name="redirect" value="<?php echo esc_attr($_SERVER['REQUEST_URI'] ?? ''); ?>">

                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold" id="editVehicleModalLabel">
                        <i class="fas fa-pen-to-square me-2"></i> Edit Vehicle Information
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label font-weight-bold">Brand / Make <span class="text-danger">*</span></label>
                            <select name="make" class="form-select vehicle-make-select" data-initial-value="<?php echo esc_attr($vehicle['make'] ?? ''); ?>" required>
                                <option value="<?php echo esc_attr($vehicle['make'] ?? ''); ?>" selected><?php echo esc_html($vehicle['make'] ?? 'Select Make'); ?></option>
                            </select>
                            <input type="text" name="make_custom" class="form-control vehicle-make-custom mt-2" placeholder="Enter custom brand..." style="display: none;">
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label font-weight-bold">Model <span class="text-danger">*</span></label>
                            <select name="model" class="form-select vehicle-model-select" data-initial-value="<?php echo esc_attr($vehicle['model'] ?? ''); ?>" required>
                                <option value="<?php echo esc_attr($vehicle['model'] ?? ''); ?>" selected><?php echo esc_html($vehicle['model'] ?? 'Select Model'); ?></option>
                            </select>
                            <input type="text" name="model_custom" class="form-control vehicle-model-custom mt-2" placeholder="Enter custom model..." style="display: none;">
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label font-weight-bold">Plate Number</label>
                            <input type="text" name="plate_number" class="form-control" value="<?php echo esc_attr($vehicle['plate_number'] ?? ''); ?>" placeholder="ABC-1234">
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label font-weight-bold">Year</label>
                            <input type="number" name="year" class="form-control" value="<?php echo esc_attr($vehicle['year'] ?? ''); ?>" min="1900" max="<?php echo date('Y') + 1; ?>" placeholder="e.g. 2024">
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label font-weight-bold">Current Mileage (km)</label>
                            <input type="number" name="last_mileage" class="form-control" value="<?php echo esc_attr(!empty($vehicle['last_mileage']) ? $vehicle['last_mileage'] : (!empty($stats['last_service_mileage']) ? $stats['last_service_mileage'] : '')); ?>" min="0" placeholder="e.g. 15000">
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label font-weight-bold">Color</label>
                            <input type="text" name="color" class="form-control" value="<?php echo esc_attr($vehicle['color'] ?? ''); ?>" placeholder="e.g. White, Silver, Black">
                        </div>

                        <div class="col-12">
                            <label class="form-label font-weight-bold">Vehicle Condition</label>
                            <select name="condition" class="form-select">
                                <?php foreach (['excellent' => 'Excellent', 'good' => 'Good', 'fair' => 'Fair', 'poor' => 'Poor'] as $cond_key => $cond_label): ?>
                                    <option value="<?php echo $cond_key; ?>" <?php echo strtolower($vehicle['condition'] ?? 'good') === $cond_key ? 'selected' : ''; ?>><?php echo $cond_label; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 font-weight-bold">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sections = document.querySelectorAll('[data-vehicle-record-section]');

    function setText(detail, selector, value) {
        const target = detail.querySelector(selector);
        if (target) {
            target.textContent = value || '-';
        }
    }

    function splitItemDisplay(value) {
        const line = String(value || '').trim();
        const parts = {
            label: line,
            price: ''
        };

        if (!line) {
            return parts;
        }

        const separator = ' - ';
        const position = line.lastIndexOf(separator);
        if (position === -1) {
            return parts;
        }

        const possiblePrice = line.slice(position + separator.length).trim();
        if (/^[^\d-]*\d[\d,]*(?:\.\d{2})?$/.test(possiblePrice)) {
            parts.label = line.slice(0, position).trim();
            parts.price = possiblePrice;
        }

        return parts;
    }

    function appendDetailItem(itemList, value, muted) {
        const parts = splitItemDisplay(value);
        const li = document.createElement('li');
        const label = document.createElement('span');

        li.className = 'vehicle-workspace-item-line';
        if (muted) {
            li.classList.add('is-muted');
        }
        if (parts.price) {
            li.classList.add('has-price');
        }

        label.className = 'vehicle-workspace-item-label';
        label.textContent = parts.label || '-';
        li.appendChild(label);

        if (parts.price) {
            const price = document.createElement('strong');
            price.className = 'vehicle-workspace-item-price';
            price.textContent = parts.price;
            li.appendChild(price);
        }

        itemList.appendChild(li);
    }

    function appendLinkedRecord(list, record) {
        const li = document.createElement('li');
        const labelText = record && record.label ? String(record.label) : 'Record';
        const url = record && record.url ? String(record.url) : '';

        if (url) {
            const link = document.createElement('a');
            link.href = url;
            link.textContent = labelText;
            li.appendChild(link);
        } else {
            const span = document.createElement('span');
            span.textContent = labelText;
            li.appendChild(span);
        }

        list.appendChild(li);
    }

    function appendInventoryField(list, field) {
        const row = document.createElement('div');
        const label = document.createElement('dt');
        const value = document.createElement('dd');

        label.textContent = field && field.label ? String(field.label) : 'Detail';
        value.textContent = field && field.value ? String(field.value) : '-';

        row.appendChild(label);
        row.appendChild(value);
        list.appendChild(row);
    }

    function syncDetail(detail, payload) {
        const isInventory = Boolean(payload.is_inventory);

        detail.classList.toggle('is-inventory-record', isInventory);
        setText(detail, '[data-detail-type]', payload.type);
        setText(detail, '[data-detail-title]', payload.title);
        setText(detail, '[data-detail-summary]', payload.summary);
        setText(detail, '[data-detail-amount]', payload.amount);
        setText(detail, '[data-detail-service-amount]', payload.service_amount);
        setText(detail, '[data-detail-inventory-amount]', payload.inventory_amount);
        setText(detail, '[data-detail-visit-total]', payload.visit_total || payload.amount);
        setText(detail, '[data-detail-date]', payload.date);
        setText(detail, '[data-detail-branch]', payload.branch);
        setText(detail, '[data-detail-status]', payload.status);
        setText(detail, '[data-detail-meta]', payload.meta);
        setText(detail, '[data-detail-notes]', payload.notes);
        setText(detail, '[data-detail-items-title]', payload.items_title || (isInventory ? 'Inventory Product' : 'Items Used'));

        const inventoryCard = detail.querySelector('[data-detail-inventory-card]');
        const inventoryFields = detail.querySelector('[data-detail-inventory-fields]');
        if (inventoryCard && inventoryFields) {
            inventoryFields.innerHTML = '';
            if (isInventory && Array.isArray(payload.inventory_fields) && payload.inventory_fields.length) {
                payload.inventory_fields.forEach(function(field) {
                    appendInventoryField(inventoryFields, field);
                });
                inventoryCard.classList.remove('is-hidden');
            } else {
                inventoryCard.classList.add('is-hidden');
            }
        }

        const itemList = detail.querySelector('[data-detail-items]');
        if (itemList) {
            itemList.innerHTML = '';
            if (Array.isArray(payload.items) && payload.items.length) {
                payload.items.forEach(function(item) {
                    appendDetailItem(itemList, item, false);
                });
            } else {
                appendDetailItem(itemList, payload.empty_items_label || 'No product item recorded for this record', true);
            }
        }

        const linkedList = detail.querySelector('[data-detail-linked-records]');
        if (linkedList) {
            linkedList.innerHTML = '';
            if (Array.isArray(payload.linked_records) && payload.linked_records.length) {
                payload.linked_records.forEach(function(record) {
                    appendLinkedRecord(linkedList, record);
                });
            } else {
                const li = document.createElement('li');
                li.className = 'is-muted';
                const span = document.createElement('span');
                span.textContent = 'No linked source record';
                li.appendChild(span);
                linkedList.appendChild(li);
            }
        }

    }

    sections.forEach(function(section) {
        const rows = section.querySelectorAll('[data-vehicle-record-row]');
        const detail = section.querySelector('[data-vehicle-record-detail]');

        if (!rows.length || !detail) {
            return;
        }

        function focusDetailPanel() {
            detail.classList.add('is-focused');
            if (!detail.hasAttribute('tabindex')) {
                detail.setAttribute('tabindex', '-1');
            }

            detail.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest'
            });

            window.setTimeout(function() {
                detail.focus({ preventScroll: true });
            }, 200);

            window.setTimeout(function() {
                detail.classList.remove('is-focused');
            }, 1400);
        }

        function setRowExpanded(row, expanded) {
            const detailButton = row.querySelector('[data-vehicle-record-action]');
            if (!detailButton) {
                return;
            }

            if (!detailButton.dataset.defaultLabel) {
                detailButton.dataset.defaultLabel = detailButton.getAttribute('aria-label') || 'View record details';
            }

            detailButton.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            detailButton.setAttribute('aria-label', expanded ? 'Hide record details' : detailButton.dataset.defaultLabel);
        }

        function closeDetail() {
            rows.forEach(function(otherRow) {
                otherRow.classList.remove('is-selected');
                setRowExpanded(otherRow, false);
            });

            detail.classList.add('is-hidden');
            detail.setAttribute('aria-hidden', 'true');
        }

        function activateRow(row, focusDetail) {
            try {
                if (row.classList.contains('is-selected') && !detail.classList.contains('is-hidden')) {
                    closeDetail();
                    return;
                }

                const payload = JSON.parse(row.dataset.record || '{}');
                rows.forEach(function(otherRow) {
                    otherRow.classList.remove('is-selected');
                    setRowExpanded(otherRow, false);
                });
                row.classList.add('is-selected');
                setRowExpanded(row, true);
                detail.classList.remove('is-hidden');
                detail.setAttribute('aria-hidden', 'false');
                syncDetail(detail, payload);
                if (focusDetail) {
                    focusDetailPanel();
                }
            } catch (error) {
                return;
            }
        }

        rows.forEach(function(row) {
            const detailButton = row.querySelector('[data-vehicle-record-action]');

            if (detailButton) {
                detailButton.dataset.defaultLabel = detailButton.getAttribute('aria-label') || 'View record details';
                detailButton.setAttribute('aria-expanded', 'false');

                detailButton.addEventListener('click', function(event) {
                    event.preventDefault();
                    event.stopPropagation();
                    activateRow(row, true);
                });
            }

            row.addEventListener('click', function(event) {
                if (event.target.closest('a, button')) {
                    return;
                }

                activateRow(row, false);
            });
        });
    });
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
