<?php
/**
 * Shared display helpers for service operation, job order, and receipt line items.
 */

if (!function_exists('app_line_item_meta')) {
    function app_line_item_meta($item) {
        $notes = trim((string) ($item['notes'] ?? ''));
        if ($notes === '' || $notes[0] !== '{') {
            return [];
        }

        $decoded = json_decode($notes, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('app_line_item_plain_note')) {
    function app_line_item_plain_note($item) {
        $notes = trim((string) ($item['notes'] ?? ''));
        if ($notes === '' || $notes[0] === '{') {
            return '';
        }

        return $notes;
    }
}

if (!function_exists('app_line_item_is_service')) {
    function app_line_item_is_service($item) {
        return strtolower(trim((string) ($item['item_type'] ?? ''))) === 'service';
    }
}

if (!function_exists('app_line_item_type_label')) {
    function app_line_item_type_label($item, $fallback = 'Service') {
        $item_type = trim((string) ($item['item_type'] ?? ''));
        if ($item_type === '') {
            return $fallback;
        }

        return ucwords(str_replace(['-', '_'], ' ', $item_type));
    }
}

if (!function_exists('app_line_item_source_label')) {
    function app_line_item_source_label($source) {
        $source = trim((string) $source);
        if ($source === '') {
            return '';
        }

        return ucwords(str_replace(['-', '_'], ' ', $source));
    }
}

if (!function_exists('app_line_item_load_inventory_items')) {
    function app_line_item_load_inventory_items(PDO $pdo, array $items) {
        if (empty($items) || !function_exists('app_table_exists') || !app_table_exists('inventory_items')) {
            return [];
        }

        $inventory_item_ids = [];
        foreach ($items as $item) {
            $meta = app_line_item_meta($item);
            $inventory_item_id = (int) ($meta['inventory_item_id'] ?? 0);
            if ($inventory_item_id > 0) {
                $inventory_item_ids[$inventory_item_id] = $inventory_item_id;
            }
        }

        if (empty($inventory_item_ids)) {
            return [];
        }

        $inventory_columns = ['i.id', 'i.branch_id', 'b.name AS branch_name'];
        foreach (['item_name', 'category', 'brand', 'model', 'size', 'sku', 'serial_number', 'manufacturing_date'] as $column) {
            if (!function_exists('app_column_exists') || app_column_exists('inventory_items', $column)) {
                $inventory_columns[] = 'i.' . $column;
            }
        }

        $placeholders = implode(',', array_fill(0, count($inventory_item_ids), '?'));
        $stmt = $pdo->prepare("
            SELECT " . implode(', ', $inventory_columns) . "
            FROM inventory_items i
            LEFT JOIN branches b ON b.id = i.branch_id
            WHERE i.id IN ($placeholders)
        ");
        $stmt->execute(array_values($inventory_item_ids));

        $inventory_items_by_id = [];
        foreach ($stmt->fetchAll() as $inventory_item) {
            $inventory_items_by_id[(int) $inventory_item['id']] = $inventory_item;
        }

        return $inventory_items_by_id;
    }
}

if (!function_exists('app_line_item_product_details')) {
    function app_line_item_product_details($item, array $inventory_items_by_id, array $options = []) {
        $meta = app_line_item_meta($item);
        $inventory_item_id = (int) ($meta['inventory_item_id'] ?? 0);
        $inventory_item = $inventory_item_id > 0 ? ($inventory_items_by_id[$inventory_item_id] ?? []) : [];
        $item_type = strtolower(trim((string) ($item['item_type'] ?? '')));
        $include_type = (bool) ($options['include_type'] ?? false);
        $include_source = (bool) ($options['include_source'] ?? false);
        $details = [];

        if ($include_type) {
            $details[] = ['label' => 'Type', 'value' => app_line_item_type_label($item)];
        }

        $fields = [
            'brand' => 'Brand',
            'model' => 'Model / Kind',
            'size' => $item_type === 'tire' ? 'Tire Size' : 'Size / Fitment',
            'sku' => 'SKU',
            'serial_number' => 'Serial',
        ];

        foreach ($fields as $field => $label) {
            $value = trim((string) ($inventory_item[$field] ?? ''));
            if ($value !== '') {
                $details[] = ['label' => $label, 'value' => $value];
            }
        }

        if (!empty($inventory_item['manufacturing_date']) && $inventory_item['manufacturing_date'] !== '0000-00-00') {
            $details[] = ['label' => 'Mfg Date', 'value' => date('M d, Y', strtotime($inventory_item['manufacturing_date']))];
        }

        if ($include_source) {
            $source_label = app_line_item_source_label($item['source'] ?? '');
            if ($source_label !== '') {
                $details[] = ['label' => 'Source', 'value' => $source_label];
            }
        }

        $line_note = app_line_item_plain_note($item);
        if ($line_note !== '') {
            $details[] = ['label' => 'Note', 'value' => $line_note];
        }

        return $details;
    }
}

if (!function_exists('app_line_item_description_html')) {
    function app_line_item_description_html($item, array $inventory_items_by_id, array $options = []) {
        $name = trim((string) ($item['item_name'] ?? ''));
        $name = $name !== '' ? $name : '-';
        $details = app_line_item_product_details($item, $inventory_items_by_id, $options);

        $html = '<div class="record-line-item">';
        $html .= '<strong>' . esc_html($name) . '</strong>';

        if (!empty($details)) {
            $html .= '<div class="record-line-item-meta">';
            foreach ($details as $detail) {
                $html .= '<span><b>' . esc_html($detail['label']) . ':</b> ' . esc_html($detail['value']) . '</span>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('app_line_item_detail_text')) {
    function app_line_item_detail_text($item, array $inventory_items_by_id, array $options = []) {
        $details = app_line_item_product_details($item, $inventory_items_by_id, $options);
        if (empty($details)) {
            return '';
        }

        return implode(' | ', array_map(static function ($detail) {
            return $detail['label'] . ': ' . $detail['value'];
        }, $details));
    }
}

if (!function_exists('app_inventory_item_select_expr')) {
    function app_inventory_item_select_expr(string $table, string $alias, string $column, string $fallback): string {
        return app_column_exists($table, $column) ? "{$alias}.{$column}" : $fallback;
    }
}

if (!function_exists('app_line_item_load_linked_inventory_transactions')) {
    function app_line_item_load_linked_inventory_transactions(PDO $pdo, array $filters): array {
        if (!app_table_exists('inventory_transactions') || !app_table_exists('inventory_items')) {
            return [];
        }

        if (!app_column_exists('inventory_transactions', 'item_id') || !app_column_exists('inventory_transactions', 'quantity')) {
            return [];
        }

        $quotation_id = (int) ($filters['quotation_id'] ?? 0);
        $job_order_id = (int) ($filters['job_order_id'] ?? 0);
        $vehicle_id = (int) ($filters['vehicle_id'] ?? 0);

        $has_reference = app_column_exists('inventory_transactions', 'reference_type')
            && app_column_exists('inventory_transactions', 'reference_id');
        $has_quote_tag = app_column_exists('inventory_transactions', 'quotation_id');
        $has_job_tag = app_column_exists('inventory_transactions', 'job_order_id');
        $has_vehicle_tag = app_column_exists('inventory_transactions', 'vehicle_id');
        $has_quote_item_tag = app_column_exists('inventory_transactions', 'quotation_item_id');

        $conditions = [];
        $params = [];

        if ($quotation_id > 0) {
            if ($has_quote_tag) {
                $conditions[] = 't.quotation_id = ?';
                $params[] = $quotation_id;
            }

            if ($has_job_tag && app_table_exists('job_orders') && app_column_exists('job_orders', 'quotation_id')) {
                $conditions[] = 't.job_order_id IN (SELECT id FROM job_orders WHERE quotation_id = ?)';
                $params[] = $quotation_id;
            }

            if ($has_quote_item_tag && app_table_exists('quotation_items') && app_column_exists('quotation_items', 'quotation_id')) {
                $conditions[] = 't.quotation_item_id IN (SELECT id FROM quotation_items WHERE quotation_id = ?)';
                $params[] = $quotation_id;
            }

            if ($has_reference) {
                $conditions[] = "(t.reference_type = 'quotation' AND t.reference_id = ?)";
                $params[] = $quotation_id;

                if (app_table_exists('quotation_items') && app_column_exists('quotation_items', 'quotation_id')) {
                    $conditions[] = "(t.reference_type = 'job_order_item' AND t.reference_id IN (SELECT id FROM quotation_items WHERE quotation_id = ?))";
                    $params[] = $quotation_id;
                }
            }
        }

        if ($job_order_id > 0) {
            if ($has_job_tag) {
                $conditions[] = 't.job_order_id = ?';
                $params[] = $job_order_id;
            }

            if ($has_reference) {
                $conditions[] = "(t.reference_type = 'job_order' AND t.reference_id = ?)";
                $params[] = $job_order_id;
            }
        }

        if (empty($conditions) && $vehicle_id > 0 && $has_vehicle_tag) {
            $conditions[] = 't.vehicle_id = ?';
            $params[] = $vehicle_id;
        }

        if (empty($conditions)) {
            return [];
        }

        $where = ['(' . implode(' OR ', $conditions) . ')'];
        if (app_column_exists('inventory_transactions', 'transaction_type')) {
            $where[] = "LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out'";
        }
        if (app_column_exists('inventory_transactions', 'reference_type')) {
            $where[] = "(t.reference_type IS NULL OR LOWER(REPLACE(t.reference_type, ' ', '_')) NOT IN ('inter_branch_transfer', 'transfer'))";
        }

        $item_name_expr = app_inventory_item_select_expr('inventory_items', 'i', 'item_name', "'Inventory Item'");
        $category_expr = app_inventory_item_select_expr('inventory_items', 'i', 'category', "''");
        $brand_expr = app_inventory_item_select_expr('inventory_items', 'i', 'brand', "''");
        $model_expr = app_inventory_item_select_expr('inventory_items', 'i', 'model', "''");
        $size_expr = app_inventory_item_select_expr('inventory_items', 'i', 'size', "''");
        $sku_expr = app_inventory_item_select_expr('inventory_items', 'i', 'sku', "''");
        $serial_expr = app_inventory_item_select_expr('inventory_items', 'i', 'serial_number', "''");
        $mfg_expr = app_inventory_item_select_expr('inventory_items', 'i', 'manufacturing_date', "NULL");
        $unit_price_expr = app_inventory_item_select_expr('inventory_items', 'i', 'unit_price', '0');
        $created_expr = app_inventory_item_select_expr('inventory_transactions', 't', 'created_at', 'NULL');
        $transaction_type_expr = app_inventory_item_select_expr('inventory_transactions', 't', 'transaction_type', "''");
        $reference_type_expr = app_inventory_item_select_expr('inventory_transactions', 't', 'reference_type', "''");
        $reference_id_expr = app_inventory_item_select_expr('inventory_transactions', 't', 'reference_id', 'NULL');
        $quotation_id_expr = app_inventory_item_select_expr('inventory_transactions', 't', 'quotation_id', 'NULL');
        $job_order_id_expr = app_inventory_item_select_expr('inventory_transactions', 't', 'job_order_id', 'NULL');
        $quotation_item_id_expr = app_inventory_item_select_expr('inventory_transactions', 't', 'quotation_item_id', 'NULL');
        $notes_expr = app_inventory_item_select_expr('inventory_transactions', 't', 'notes', "''");

        $branch_join = '';
        $branch_name_expr = "''";
        if (app_table_exists('branches') && app_column_exists('inventory_items', 'branch_id') && app_column_exists('branches', 'name')) {
            $branch_join = ' LEFT JOIN branches b ON b.id = i.branch_id';
            $branch_name_expr = 'b.name';
        }

        $quotation_join = '';
        $quotation_number_expr = "''";
        if ($has_quote_tag && app_table_exists('quotations') && app_column_exists('quotations', 'quotation_number')) {
            $quotation_join = ' LEFT JOIN quotations q ON q.id = t.quotation_id';
            $quotation_number_expr = 'q.quotation_number';
        }

        $job_join = '';
        $job_number_expr = "''";
        if ($has_job_tag && app_table_exists('job_orders') && app_column_exists('job_orders', 'job_number')) {
            $job_join = ' LEFT JOIN job_orders jo ON jo.id = t.job_order_id';
            $job_number_expr = 'jo.job_number';
        }

        $sql = "
            SELECT
                t.id,
                {$created_expr} AS created_at,
                DATE({$created_expr}) AS transaction_date,
                {$transaction_type_expr} AS transaction_type,
                ABS(t.quantity) AS quantity,
                {$unit_price_expr} AS unit_price,
                ABS(t.quantity) * {$unit_price_expr} AS line_total,
                {$item_name_expr} AS item_name,
                {$category_expr} AS category,
                {$brand_expr} AS brand,
                {$model_expr} AS model,
                {$size_expr} AS size,
                {$sku_expr} AS sku,
                {$serial_expr} AS serial_number,
                {$mfg_expr} AS manufacturing_date,
                {$branch_name_expr} AS branch_name,
                {$notes_expr} AS notes,
                {$reference_type_expr} AS reference_type,
                {$reference_id_expr} AS reference_id,
                {$quotation_id_expr} AS quotation_id,
                {$job_order_id_expr} AS job_order_id,
                {$quotation_item_id_expr} AS quotation_item_id,
                {$quotation_number_expr} AS quotation_number,
                {$job_number_expr} AS job_number
            FROM inventory_transactions t
            INNER JOIN inventory_items i ON i.id = t.item_id
            {$branch_join}
            {$quotation_join}
            {$job_join}
            WHERE " . implode(' AND ', $where) . "
            ORDER BY {$created_expr} ASC, t.id ASC
        ";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            return [];
        }
    }
}

if (!function_exists('app_inventory_transaction_display_name')) {
    function app_inventory_transaction_display_name(array $row): string {
        $name = trim((string) ($row['item_name'] ?? 'Inventory Item'));
        $size = trim((string) ($row['size'] ?? ''));

        if ($size !== '' && stripos($name, $size) === false) {
            $name .= ' - ' . $size;
        }

        return $name !== '' ? $name : 'Inventory Item';
    }
}

if (!function_exists('app_inventory_transaction_detail_text')) {
    function app_inventory_transaction_detail_text(array $row): string {
        $details = [];
        $fields = [
            'brand' => 'Brand',
            'model' => 'Model',
            'size' => 'Size',
            'sku' => 'SKU',
            'serial_number' => 'Serial',
        ];

        foreach ($fields as $field => $label) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') {
                $details[] = $label . ': ' . $value;
            }
        }

        $mfg = trim((string) ($row['manufacturing_date'] ?? ''));
        if ($mfg !== '' && $mfg !== '0000-00-00') {
            $timestamp = strtotime($mfg);
            $details[] = 'Mfg: ' . ($timestamp ? date('M d, Y', $timestamp) : $mfg);
        }

        return implode(' | ', $details);
    }
}

if (!function_exists('app_inventory_transaction_quantity_text')) {
    function app_inventory_transaction_quantity_text($quantity): string {
        $quantity = (float) $quantity;
        return floor($quantity) === $quantity ? number_format($quantity, 0) : number_format($quantity, 2);
    }
}

if (!function_exists('app_inventory_transaction_rows_total')) {
    function app_inventory_transaction_rows_total(array $rows): float {
        $total = 0.0;
        foreach ($rows as $row) {
            $total += (float) ($row['line_total'] ?? 0);
        }
        return $total;
    }
}

if (!function_exists('app_inventory_transaction_table_html')) {
    function app_inventory_transaction_table_html(array $rows, array $options = []): string {
        if (empty($rows)) {
            return '';
        }

        $title = (string) ($options['title'] ?? 'Products / Inventory Used');
        $subtitle = (string) ($options['subtitle'] ?? 'Stock-out products linked to this record.');
        $container = (string) ($options['container'] ?? 'div');
        $total = app_inventory_transaction_rows_total($rows);
        $is_panel = $container === 'section';
        $tag = $is_panel ? 'section' : 'div';
        $class = $is_panel ? 'job-detail-panel linked-inventory-products' : 'linked-inventory-products mt-4';

        $html = '<' . $tag . ' class="' . esc_attr($class) . '">';
        if ($is_panel) {
            $html .= '<div class="job-detail-panel-head"><h2><i class="fas fa-box-open"></i> ' . esc_html($title) . '</h2>';
            $html .= '<span class="detail-muted">' . esc_html(count($rows) . ' product' . (count($rows) === 1 ? '' : 's')) . '</span></div>';
        } else {
            $html .= '<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">';
            $html .= '<div><h6 class="text-muted mb-1">' . esc_html(strtoupper($title)) . '</h6>';
            if ($subtitle !== '') {
                $html .= '<div class="record-line-item-meta">' . esc_html($subtitle) . '</div>';
            }
            $html .= '</div><strong>' . esc_html(format_currency($total)) . '</strong></div>';
        }

        if ($is_panel && $subtitle !== '') {
            $html .= '<div class="job-detail-panel-body pt-0"><p class="detail-muted mb-3">' . esc_html($subtitle) . '</p>';
        }

        $html .= '<div class="table-responsive">';
        $html .= '<table class="table table-bordered table-sm mb-0 service-operation-items-table linked-inventory-table">';
        $html .= '<thead><tr>';
        $html .= '<th>Product</th><th>Item Details</th><th class="text-end">Qty</th><th class="text-end">Unit Price</th><th class="text-end">Total</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $name = app_inventory_transaction_display_name($row);
            $details = app_inventory_transaction_detail_text($row);
            $category = trim((string) ($row['category'] ?? ''));
            $qty = app_inventory_transaction_quantity_text($row['quantity'] ?? 0);
            $unit_price = format_currency((float) ($row['unit_price'] ?? 0));
            $line_total = format_currency((float) ($row['line_total'] ?? 0));

            $html .= '<tr>';
            $html .= '<td><strong>' . esc_html($name) . '</strong>';
            if ($category !== '') {
                $html .= '<div class="record-line-item-meta">' . esc_html(ucfirst($category)) . '</div>';
            }
            $html .= '</td>';
            $html .= '<td>' . ($details !== '' ? esc_html($details) : '-') . '</td>';
            $html .= '<td class="text-end">' . esc_html($qty) . '</td>';
            $html .= '<td class="text-end">' . esc_html($unit_price) . '</td>';
            $html .= '<td class="text-end"><strong>' . esc_html($line_total) . '</strong></td>';
            $html .= '</tr>';
        }

        $html .= '</tbody>';
        $html .= '<tfoot><tr><th colspan="4" class="text-end">Inventory Issued Value:</th><th class="text-end">' . esc_html(format_currency($total)) . '</th></tr></tfoot>';
        $html .= '</table></div>';

        if ($is_panel) {
            $html .= '</div>';
        }

        $html .= '</' . $tag . '>';
        return $html;
    }
}
