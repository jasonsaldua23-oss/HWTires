<?php
/**
 * Reports & Analytics Dashboard
 */

require_once '../../includes/config.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$user = app_get_session_user();

if ($user['role'] !== 'admin') {
    redirect('/hwtires/' . $user['role'] . '/index.php');
}

$page_title = 'Reports & Analytics';

if (!function_exists('reports_branch_label')) {
    function reports_branch_label($branch_name) {
        return app_branch_label($branch_name, 'Branch');
    }
}

if (!function_exists('reports_money')) {
    function reports_money($amount, $decimals = 0) {
        return '&#8369;' . number_format((float) $amount, $decimals);
    }
}

if (!function_exists('reports_csv_money')) {
    function reports_csv_money($amount) {
        return 'PHP ' . number_format((float) $amount, 2);
    }
}

if (!function_exists('reports_valid_date')) {
    function reports_valid_date($date, $fallback) {
        $parsed = DateTime::createFromFormat('Y-m-d', (string) $date);
        return $parsed && $parsed->format('Y-m-d') === $date ? $date : $fallback;
    }
}

if (!function_exists('reports_short_date')) {
    function reports_short_date($date) {
        return !empty($date) ? date('Y-m-d', strtotime($date)) : '-';
    }
}

if (!function_exists('reports_inventory_vehicle_label')) {
    function reports_inventory_vehicle_label(array $row) {
        $plate = trim((string) ($row['tagged_plate_number'] ?? ''));
        $details = trim((string) ($row['tagged_vehicle_make'] ?? '') . ' ' . (string) ($row['tagged_vehicle_model'] ?? ''));

        if (!empty($row['tagged_vehicle_year'])) {
            $details = trim((string) $row['tagged_vehicle_year'] . ' ' . $details);
        }

        if ($plate !== '' && $details !== '') {
            return $plate . ' - ' . $details;
        }

        return $plate !== '' ? $plate : $details;
    }
}

if (!function_exists('reports_status_label')) {
    function reports_status_label($status) {
        $labels = [
            'waiting' => 'Pending',
            'pending' => 'Pending',
            'in-progress' => 'In Progress',
            'completed' => 'Completed',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
        ];

        $key = strtolower((string) $status);
        return $labels[$key] ?? ucwords(str_replace(['-', '_'], ' ', $key));
    }
}

if (!function_exists('reports_status_class')) {
    function reports_status_class($status) {
        $status = strtolower((string) $status);
        $allowed = ['waiting', 'pending', 'in-progress', 'completed', 'approved', 'rejected'];
        return in_array($status, $allowed, true) ? $status : 'pending';
    }
}

if (!function_exists('reports_category_label')) {
    function reports_category_label($category) {
        return [
            'tire' => 'Tire',
            'part' => 'Part',
            'accessory' => 'Accessory',
        ][strtolower((string) $category)] ?? 'Item';
    }
}

if (!function_exists('reports_category_group_label')) {
    function reports_category_group_label($category) {
        return [
            'tire' => 'Tires',
            'part' => 'Parts',
            'accessory' => 'Accessories',
        ][strtolower((string) $category)] ?? 'Items';
    }
}

if (!function_exists('reports_empty_inventory_categories')) {
    function reports_empty_inventory_categories() {
        return [
            'tire' => ['category' => 'tire', 'label' => 'Tires', 'stock_in_units' => 0, 'stock_out_units' => 0, 'stock_out_value' => 0, 'net_units' => 0],
            'accessory' => ['category' => 'accessory', 'label' => 'Accessories', 'stock_in_units' => 0, 'stock_out_units' => 0, 'stock_out_value' => 0, 'net_units' => 0],
            'part' => ['category' => 'part', 'label' => 'Parts', 'stock_in_units' => 0, 'stock_out_units' => 0, 'stock_out_value' => 0, 'net_units' => 0],
        ];
    }
}

if (!function_exists('reports_filter_url')) {
    function reports_filter_url($branch_id, $date_from, $date_to, $extra = []) {
        $params = [
            'branch' => $branch_id ?: '',
            'date_from' => $date_from,
            'date_to' => $date_to,
        ];

        $params = array_merge($params, $extra);
        if ($params['branch'] === '') {
            unset($params['branch']);
        }

        return '?' . http_build_query($params);
    }
}

if (!function_exists('reports_detail_url')) {
    function reports_detail_url($report, $branch_id, $date_from, $date_to, $status, $search, $extra = []) {
        return reports_filter_url($branch_id, $date_from, $date_to, array_merge([
            'report' => $report,
            'status' => $status,
            'search' => $search,
        ], $extra));
    }
}

if (!function_exists('reports_record_url')) {
    function reports_record_url($type, $id) {
        $id = (int) $id;
        if ($id <= 0) {
            return '';
        }

        $routes = [
            'customer' => '/hwtires/admin/customers/profile.php?id=',
            'vehicle' => '/hwtires/admin/vehicles/profile.php?id=',
            'job' => '/hwtires/admin/job-orders/view.php?id=',
            'quotation' => '/hwtires/admin/quotations/view.php?id=',
        ];

        return isset($routes[$type]) ? $routes[$type] . $id : '';
    }
}

if (!function_exists('reports_record_link')) {
    function reports_record_link($url, $label, $class = 'reports-record-link') {
        $label = trim((string) $label);
        if ($label === '') {
            return '-';
        }

        if (trim((string) $url) === '') {
            return esc_html($label);
        }

        return '<a class="' . esc_attr($class) . '" href="' . esc_attr($url) . '">' . esc_html($label) . '</a>';
    }
}

if (!function_exists('reports_vehicle_label')) {
    function reports_vehicle_label(array $row) {
        $plate = trim((string) ($row['plate_number'] ?? ''));
        $details = trim((string) ($row['vehicle_year'] ?? '') . ' ' . (string) ($row['vehicle_make'] ?? '') . ' ' . (string) ($row['vehicle_model'] ?? ''));

        if ($plate !== '' && $details !== '') {
            return $plate . ' - ' . $details;
        }

        return $plate !== '' ? $plate : ($details !== '' ? $details : '-');
    }
}

if (!function_exists('reports_product_detail_text')) {
    function reports_product_detail_text(array $row) {
        $parts = [];

        $serial = trim((string) ($row['serial_number'] ?? $row['sku'] ?? ''));
        if ($serial !== '') {
            $parts[] = 'Serial/SKU: ' . $serial;
        }

        if (!empty($row['size'])) {
            $parts[] = 'Size: ' . $row['size'];
        }

        $model = trim((string) ($row['inventory_model'] ?? $row['brand'] ?? ''));
        if ($model !== '') {
            $parts[] = 'Model: ' . $model;
        }

        if (!empty($row['manufacturing_date'])) {
            $parts[] = 'Mfg: ' . reports_short_date($row['manufacturing_date']);
        }

        return !empty($parts) ? implode(' | ', $parts) : '-';
    }
}

if (!function_exists('reports_movement_type_label')) {
    function reports_movement_type_label($type) {
        $labels = [
            'stock_in' => 'Stock In',
            'stock_out' => 'Stock Out',
            'adjustment' => 'Adjustment',
            'damage' => 'Damage',
        ];

        return $labels[strtolower((string) $type)] ?? reports_status_label($type);
    }
}

if (!function_exists('reports_archive_type_label')) {
    function reports_archive_type_label($type) {
        $labels = [
            'customer' => 'Customer',
            'vehicle' => 'Vehicle',
            'service_operation' => 'Service Operation',
            'job_order' => 'Job Order',
            'inventory_item' => 'Inventory Item',
        ];

        return $labels[strtolower((string) $type)] ?? reports_status_label($type);
    }
}

if (!function_exists('reports_archive_record_url')) {
    function reports_archive_record_url(array $row) {
        $type = strtolower((string) ($row['archive_type'] ?? ''));
        $record_id = (int) ($row['record_id'] ?? 0);

        if ($type === 'customer') {
            return reports_record_url('customer', $record_id);
        }
        if ($type === 'vehicle') {
            return reports_record_url('vehicle', $record_id);
        }
        if ($type === 'service_operation') {
            return reports_record_url('quotation', $record_id);
        }
        if ($type === 'job_order') {
            return reports_record_url('job', $record_id);
        }
        if ($type === 'inventory_item') {
            return '/hwtires/admin/tire-inventory/?status=inactive#inventory-records';
        }

        return '';
    }
}

if (!function_exists('reports_percent')) {
    function reports_percent($value, $max) {
        if ($value <= 0) {
            return 0;
        }

        if ($max <= 0) {
            return 0;
        }

        return max(4, min(92, round(((float) $value / (float) $max) * 100, 2)));
    }
}

if (!function_exists('reports_nice_axis_max')) {
    function reports_nice_axis_max($value, $steps = 5) {
        $value = max(1, (float) $value);
        $raw_max = $value * 1.18;
        $raw_step = $raw_max / max(1, (int) $steps);
        $magnitude = pow(10, floor(log10($raw_step)));
        $normalized = $raw_step / $magnitude;

        if ($normalized <= 1) {
            $nice_step = 1 * $magnitude;
        } elseif ($normalized <= 2) {
            $nice_step = 2 * $magnitude;
        } elseif ($normalized <= 5) {
            $nice_step = 5 * $magnitude;
        } else {
            $nice_step = 10 * $magnitude;
        }

        return max($nice_step, ceil($raw_max / $nice_step) * $nice_step);
    }
}

if (!function_exists('reports_axis_labels')) {
    function reports_axis_labels($max, $steps = 5) {
        $labels = [];
        $step = $max / max(1, (int) $steps);

        for ($i = $steps; $i >= 0; $i--) {
            $value = $step * $i;
            $labels[] = abs($value - round($value)) < 0.01
                ? number_format((int) round($value))
                : number_format($value, 1);
        }

        return $labels;
    }
}

if (!function_exists('reports_branch_chart_color')) {
    function reports_branch_chart_color($index) {
        $colors = ['#64748b', '#0f766e', '#475467', '#285f9f', '#8a95a5', '#115e59', '#344054'];
        return $colors[$index % count($colors)];
    }
}

if (!function_exists('reports_send_csv')) {
    function reports_send_csv($filters, $summary, $branch_performance, $job_stats, $quote_stats, $recent_quotations, $recent_jobs, $inventory_summary, $inventory_branch_movement, $inventory_category_movement, $inventory_top_items, $inventory_tagged_stock_outs, $detailed_services = [], $customer_vehicle_report = []) {
        header('Content-Type: text/csv; charset=utf-8');
        $filename_label = strtolower(($filters['report_label'] ?? 'reports-analytics') . '-' . ($filters['branch_label'] ?? 'all-branches'));
        $filename_label = trim((string) preg_replace('/[^a-z0-9]+/', '-', $filename_label), '-');
        header('Content-Disposition: attachment; filename="' . ($filename_label ?: 'reports-analytics') . '-' . ($filters['date_from'] ?? date('Y-m-d')) . '-to-' . ($filters['date_to'] ?? date('Y-m-d')) . '.csv"');

        $out = fopen('php://output', 'w');

        fputcsv($out, ['Reports & Analytics']);
        fputcsv($out, ['Branch', $filters['branch_label']]);
        fputcsv($out, ['Date From', $filters['date_from']]);
        fputcsv($out, ['Date To', $filters['date_to']]);
        fputcsv($out, []);

        fputcsv($out, ['Summary']);
        fputcsv($out, ['Total Revenue', reports_csv_money($summary['revenue'])]);
        fputcsv($out, ['Total Job Orders', $summary['total_jobs']]);
        fputcsv($out, ['Completed Jobs', $summary['completed_jobs']]);
        fputcsv($out, ['Total Service Operations', $summary['total_quotations']]);
        fputcsv($out, []);

        fputcsv($out, ['Detailed Services']);
        fputcsv($out, ['Date', 'Branch', 'Customer', 'Vehicle', 'Service/s', 'Job Order', 'Quotation', 'Status', 'Amount']);
        foreach ($detailed_services as $service) {
            fputcsv($out, [
                reports_short_date($service['service_date'] ?? ''),
                reports_branch_label($service['branch_name'] ?? ''),
                $service['customer_name'] ?? '-',
                reports_vehicle_label($service),
                $service['service_names'] ?? '-',
                $service['job_number'] ?? '-',
                $service['quotation_number'] ?? '-',
                reports_status_label($service['report_status'] ?? ''),
                reports_csv_money($service['total_amount'] ?? 0),
            ]);
        }
        fputcsv($out, []);

        fputcsv($out, ['Branch Performance']);
        fputcsv($out, ['Branch', 'Job Orders', 'Service Operations', 'Revenue']);
        foreach ($branch_performance as $branch) {
            fputcsv($out, [
                reports_branch_label($branch['name']),
                $branch['job_orders'],
                $branch['quotations'],
                reports_csv_money($branch['revenue']),
            ]);
        }
        fputcsv($out, []);

        fputcsv($out, ['Job Order Status Distribution']);
        fputcsv($out, ['Waiting/Pending', $job_stats['waiting'] ?? 0]);
        fputcsv($out, ['In Progress', $job_stats['in_progress'] ?? 0]);
        fputcsv($out, ['Completed', $job_stats['completed'] ?? 0]);
        fputcsv($out, []);

        fputcsv($out, ['Service Operation Status Distribution']);
        fputcsv($out, ['Pending', $quote_stats['pending'] ?? 0]);
        fputcsv($out, ['Approved', $quote_stats['approved'] ?? 0]);
        fputcsv($out, ['Rejected', $quote_stats['rejected'] ?? 0]);
        fputcsv($out, []);

        fputcsv($out, ['Inventory Movement']);
        fputcsv($out, ['Stock In Units', $inventory_summary['stock_in_units'] ?? 0]);
        fputcsv($out, ['Stock Out Units', $inventory_summary['stock_out_units'] ?? 0]);
        fputcsv($out, ['Net Movement', $inventory_summary['net_units'] ?? 0]);
        fputcsv($out, ['Stock Out Value', reports_csv_money($inventory_summary['stock_out_value'] ?? 0)]);
        fputcsv($out, []);

        fputcsv($out, ['Inventory Movement by Category']);
        fputcsv($out, ['Category', 'Stock In', 'Stock Out', 'Net Movement', 'Stock Out Value']);
        foreach ($inventory_category_movement as $category) {
            fputcsv($out, [
                $category['label'],
                $category['stock_in_units'],
                $category['stock_out_units'],
                $category['net_units'],
                reports_csv_money($category['stock_out_value']),
            ]);
        }
        fputcsv($out, []);

        fputcsv($out, ['Inventory Movement by Branch']);
        fputcsv($out, ['Branch', 'Stock In', 'Stock Out', 'Net Movement', 'Stock Out Value']);
        foreach ($inventory_branch_movement as $branch) {
            fputcsv($out, [
                reports_branch_label($branch['name']),
                $branch['stock_in_units'],
                $branch['stock_out_units'],
                $branch['net_units'],
                reports_csv_money($branch['stock_out_value']),
            ]);
        }
        fputcsv($out, []);

        fputcsv($out, ['Top Inventory Movement Items']);
        fputcsv($out, ['Item', 'Category', 'Branch', 'Stock In', 'Stock Out', 'Last Movement']);
        foreach ($inventory_top_items as $item) {
            fputcsv($out, [
                app_display_item_name($item['item_name'], $item['category'] ?? null),
                reports_category_label($item['category']),
                reports_branch_label($item['branch_name']),
                $item['stock_in_units'],
                $item['stock_out_units'],
                reports_short_date($item['last_movement']),
            ]);
        }
        fputcsv($out, []);

        fputcsv($out, ['Inventory Sales Detail']);
        fputcsv($out, ['Date', 'Branch', 'Item', 'Category', 'Item Details', 'Qty', 'Customer', 'Vehicle', 'Reference', 'Sales Value', 'Notes']);
        foreach ($inventory_tagged_stock_outs as $movement) {
            $references = array_filter([
                $movement['tagged_quotation_number'] ?? '',
                $movement['tagged_job_number'] ?? '',
            ]);
            $tagged_customer_name = trim((string) ($movement['tagged_customer_name'] ?? ''));
            if ($tagged_customer_name === '') {
                $tagged_customer_name = app_inventory_transaction_tag_empty_label($movement['reference_type'] ?? '', $movement['transaction_type'] ?? '');
            }

            fputcsv($out, [
                reports_short_date($movement['created_at'] ?? ''),
                reports_branch_label($movement['branch_name'] ?? ''),
                app_display_item_name($movement['item_name'] ?? '-', $movement['category'] ?? null),
                reports_category_label($movement['category'] ?? ''),
                reports_product_detail_text($movement),
                $movement['quantity'] ?? 0,
                $tagged_customer_name,
                reports_inventory_vehicle_label($movement) ?: '-',
                !empty($references) ? implode(' / ', $references) : '-',
                reports_csv_money(((float) ($movement['unit_price'] ?? 0)) * abs((int) ($movement['quantity'] ?? 0))),
                $movement['notes'] ?? '',
            ]);
        }
        fputcsv($out, []);

        fputcsv($out, ['Customer & Vehicle Report']);
        fputcsv($out, ['Vehicle', 'Current Owner', 'Contact', 'Branch', 'Last Visited Branch', 'Services', 'Inventory Sales', 'Sales Value', 'Last Visit']);
        foreach ($customer_vehicle_report as $record) {
            fputcsv($out, [
                reports_vehicle_label($record),
                $record['customer_name'] ?? '-',
                $record['customer_phone'] ?? '-',
                reports_branch_label($record['branch_name'] ?? ''),
                reports_branch_label($record['last_visited_branch'] ?? ''),
                $record['service_count'] ?? 0,
                $record['items_given_count'] ?? 0,
                reports_csv_money($record['sales_value'] ?? 0),
                reports_short_date($record['last_visit_date'] ?? ''),
            ]);
        }
        fputcsv($out, []);

        fputcsv($out, ['Recent Service Operations']);
        fputcsv($out, ['Customer', 'Branch', 'Date', 'Status', 'Amount']);
        foreach ($recent_quotations as $quotation) {
            fputcsv($out, [
                $quotation['customer_name'] ?? '-',
                reports_branch_label($quotation['branch_name'] ?? ''),
                reports_short_date($quotation['quotation_date'] ?? ''),
                reports_status_label($quotation['status'] ?? ''),
                reports_csv_money($quotation['total_amount'] ?? 0),
            ]);
        }
        fputcsv($out, []);

        fputcsv($out, ['Recent Job Orders']);
        fputcsv($out, ['Customer', 'Branch', 'Date', 'Technician', 'Status']);
        foreach ($recent_jobs as $job) {
            fputcsv($out, [
                $job['customer_name'] ?? '-',
                reports_branch_label($job['branch_name'] ?? ''),
                reports_short_date($job['job_date'] ?? ''),
                $job['assigned_technician_name'] ?? '-',
                reports_status_label($job['status'] ?? ''),
            ]);
        }

        fclose($out);
        exit;
    }
}

if (!function_exists('reports_send_detail_csv')) {
    function reports_send_detail_csv(array $filters, $report_tab, array $active_report, array $detail_records) {
        $filename_label = strtolower(($active_report['label'] ?? 'Report') . '-' . ($filters['branch_label'] ?? 'All Branches'));
        $filename_label = trim((string) preg_replace('/[^a-z0-9]+/', '-', $filename_label), '-');

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . ($filename_label ?: 'reports') . '-' . ($filters['date_from'] ?? date('Y-m-d')) . '-to-' . ($filters['date_to'] ?? date('Y-m-d')) . '.csv"');

        $out = fopen('php://output', 'w');

        fputcsv($out, ['Highway Tires Admin Report']);
        fputcsv($out, ['Report Type', $active_report['label'] ?? 'Report']);
        fputcsv($out, ['Branch', $filters['branch_label'] ?? 'All Branches']);
        fputcsv($out, ['Date From', $filters['date_from'] ?? '-']);
        fputcsv($out, ['Date To', $filters['date_to'] ?? '-']);
        fputcsv($out, ['Status', $filters['status_label'] ?? 'All Records']);
        fputcsv($out, ['Search', $filters['search'] ?? '']);
        fputcsv($out, []);

        if ($report_tab === 'vehicle_history') {
            fputcsv($out, ['Vehicle', 'Current Owner', 'Contact', 'Home Branch', 'Last Visited Branch', 'Ownership', 'Previous Owner(s)', 'Services', 'Inventory Sales', 'Sales Value', 'Last Mileage', 'Last Visit']);
            foreach ($detail_records as $record) {
                $owner_count = max(1, (int) ($record['owner_count'] ?? 1));
                fputcsv($out, [
                    reports_vehicle_label($record),
                    $record['customer_name'] ?? '-',
                    $record['customer_phone'] ?? '-',
                    reports_branch_label($record['branch_name'] ?? ''),
                    reports_branch_label($record['last_visited_branch'] ?? ''),
                    $owner_count . ' owner' . ($owner_count === 1 ? '' : 's'),
                    $record['previous_owner_names'] ?? '-',
                    $record['service_count'] ?? 0,
                    $record['items_given_count'] ?? 0,
                    reports_csv_money($record['sales_value'] ?? 0),
                    !empty($record['last_mileage']) ? number_format((int) $record['last_mileage']) . ' km' : '-',
                    reports_short_date($record['last_visit_date'] ?? ''),
                ]);
            }
        } elseif ($report_tab === 'stock_movement') {
            fputcsv($out, ['Date', 'Branch', 'Type', 'Item', 'Category', 'Item Details', 'Qty', 'Customer', 'Vehicle', 'Reference', 'Entered By', 'Notes']);
            foreach ($detail_records as $movement) {
                $references = array_filter([
                    $movement['tagged_quotation_number'] ?? '',
                    $movement['tagged_job_number'] ?? '',
                ]);
                $tagged_customer_name = trim((string) ($movement['tagged_customer_name'] ?? ''));
                if ($tagged_customer_name === '') {
                    $tagged_customer_name = app_inventory_transaction_tag_empty_label($movement['reference_type'] ?? '', $movement['transaction_type'] ?? '');
                }

                fputcsv($out, [
                    reports_short_date($movement['created_at'] ?? ''),
                    reports_branch_label($movement['branch_name'] ?? ''),
                    reports_movement_type_label($movement['transaction_type'] ?? ''),
                    app_display_item_name($movement['item_name'] ?? '-', $movement['category'] ?? null),
                    reports_category_label($movement['category'] ?? ''),
                    reports_product_detail_text($movement),
                    abs((int) ($movement['quantity'] ?? 0)),
                    $tagged_customer_name,
                    reports_inventory_vehicle_label($movement) ?: '-',
                    !empty($references) ? implode(' / ', $references) : '-',
                    $movement['entered_by_name'] ?? 'System',
                    $movement['notes'] ?? '',
                ]);
            }
        } elseif ($report_tab === 'archives') {
            fputcsv($out, ['Archived Date', 'Branch', 'Record Type', 'Record', 'Customer/Owner', 'Vehicle', 'Archived By', 'Reason', 'Original Status']);
            foreach ($detail_records as $record) {
                fputcsv($out, [
                    app_format_datetime_pht($record['archived_at'] ?? '', 'Y-m-d'),
                    reports_branch_label($record['branch_name'] ?? ''),
                    reports_archive_type_label($record['archive_type'] ?? ''),
                    $record['record_label'] ?? '-',
                    $record['customer_name'] ?? '-',
                    reports_vehicle_label($record),
                    $record['archived_by_name'] ?? '-',
                    $record['archive_reason'] ?? '-',
                    reports_status_label($record['original_status'] ?? ''),
                ]);
            }
        } elseif ($report_tab === 'items') {
            fputcsv($out, ['Date', 'Branch', 'Item', 'Category', 'Item Details', 'Qty', 'Sales Value', 'Customer', 'Contact', 'Vehicle', 'Job Order', 'Service Operation', 'Notes']);
            foreach ($detail_records as $movement) {
                $tagged_customer_name = trim((string) ($movement['tagged_customer_name'] ?? ''));
                if ($tagged_customer_name === '') {
                    $tagged_customer_name = app_inventory_transaction_tag_empty_label($movement['reference_type'] ?? '', $movement['transaction_type'] ?? '');
                }

                fputcsv($out, [
                    reports_short_date($movement['created_at'] ?? ''),
                    reports_branch_label($movement['branch_name'] ?? ''),
                    app_display_item_name($movement['item_name'] ?? '-', $movement['category'] ?? null),
                    reports_category_label($movement['category'] ?? ''),
                    reports_product_detail_text($movement),
                    abs((int) ($movement['quantity'] ?? 0)),
                    reports_csv_money(((float) ($movement['unit_price'] ?? 0)) * abs((int) ($movement['quantity'] ?? 0))),
                    $tagged_customer_name,
                    $movement['tagged_customer_phone'] ?? '',
                    reports_inventory_vehicle_label($movement) ?: '-',
                    $movement['tagged_job_number'] ?? '-',
                    $movement['tagged_quotation_number'] ?? '-',
                    $movement['notes'] ?? '',
                ]);
            }
        } elseif ($report_tab === 'vehicles') {
            fputcsv($out, ['Vehicle', 'Current Owner', 'Contact', 'Added/Home Branch', 'Last Visited Branch', 'Services', 'Inventory Sales', 'Sales Value', 'Last Visit']);
            foreach ($detail_records as $record) {
                fputcsv($out, [
                    reports_vehicle_label($record),
                    $record['customer_name'] ?? '-',
                    $record['customer_phone'] ?? '-',
                    reports_branch_label($record['branch_name'] ?? ''),
                    reports_branch_label($record['last_visited_branch'] ?? ''),
                    $record['service_count'] ?? 0,
                    $record['items_given_count'] ?? 0,
                    reports_csv_money($record['sales_value'] ?? 0),
                    reports_short_date($record['last_visit_date'] ?? ''),
                ]);
            }
        } else {
            fputcsv($out, ['Date', 'Branch', 'Customer', 'Contact', 'Vehicle', 'Services Availed', 'Job Order', 'Service Operation', 'Status', 'Amount']);
            foreach ($detail_records as $service) {
                fputcsv($out, [
                    reports_short_date($service['service_date'] ?? ''),
                    reports_branch_label($service['branch_name'] ?? ''),
                    $service['customer_name'] ?? '-',
                    $service['customer_phone'] ?? '-',
                    reports_vehicle_label($service),
                    $service['service_names'] ?? '-',
                    $service['job_number'] ?? '-',
                    $service['quotation_number'] ?? '-',
                    reports_status_label($service['report_status'] ?? ''),
                    reports_csv_money($service['total_amount'] ?? 0),
                ]);
            }
        }

        fclose($out);
        exit;
    }
}

try {
    $report_date_bounds = $pdo->query("
        SELECT MIN(report_date) AS first_date,
               MAX(report_date) AS last_date
        FROM (
            SELECT DATE(created_at) AS report_date
            FROM inventory_transactions
            WHERE created_at IS NOT NULL
              AND COALESCE(reference_type, '') <> 'opening_balance'

            UNION ALL

            SELECT job_date AS report_date
            FROM job_orders
            WHERE job_date IS NOT NULL

            UNION ALL

            SELECT quotation_date AS report_date
            FROM quotations
            WHERE quotation_date IS NOT NULL

            UNION ALL

            SELECT service_date AS report_date
            FROM service_history
            WHERE service_date IS NOT NULL

            UNION ALL

            SELECT visit_date AS report_date
            FROM customer_visits
            WHERE visit_date IS NOT NULL
        ) report_dates
        WHERE report_date IS NOT NULL
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $report_date_bounds = [];
}

$default_from = !empty($report_date_bounds['first_date']) ? date('Y-m-d', strtotime($report_date_bounds['first_date'])) : date('Y-m-d');
$pht_now = new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE));
$default_to = $pht_now->format('Y-m-d');
if ($default_from > $default_to) {
    $default_from = $default_to;
}

$date_from = reports_valid_date($_GET['date_from'] ?? $default_from, $default_from);
$date_to = reports_valid_date($_GET['date_to'] ?? $default_to, $default_to);

if ($date_from > $date_to) {
    [$date_from, $date_to] = [$date_to, $date_from];
}

$branch_filter = trim($_GET['branch'] ?? '');
$branch_filter = $branch_filter !== '' ? intval($branch_filter) : '';
if ($branch_filter !== '' && $branch_filter <= 0) {
    $branch_filter = '';
}

$branches = $pdo->query("SELECT id, name, has_inventory FROM branches WHERE status = 'active' ORDER BY name ASC")->fetchAll();
$branch_options = [];
foreach ($branches as $branch) {
    $branch_options[(int) $branch['id']] = $branch;
}

if ($branch_filter !== '' && !isset($branch_options[$branch_filter])) {
    $branch_filter = '';
}

$valid_report_tabs = ['services', 'items', 'vehicles', 'vehicle_history', 'stock_movement', 'archives'];
$report_tab = strtolower(trim($_GET['report'] ?? 'services'));
if (!in_array($report_tab, $valid_report_tabs, true)) {
    $report_tab = 'services';
}

$status_filter = strtolower(trim($_GET['status'] ?? 'all'));
if ($status_filter === '') {
    $status_filter = 'all';
}

$search_filter = trim($_GET['search'] ?? '');
if (function_exists('mb_substr')) {
    $search_filter = mb_substr($search_filter, 0, 100);
} else {
    $search_filter = substr($search_filter, 0, 100);
}

$page_sizes = [10, 20, 50];
$detail_per_page = (int) ($_GET['per_page'] ?? 10);
if (!in_array($detail_per_page, $page_sizes, true)) {
    $detail_per_page = 10;
}
$detail_page = max(1, (int) ($_GET['page'] ?? 1));
$is_export_request = isset($_GET['export']) && $_GET['export'] === 'csv';

$visible_branches = array_values(array_filter($branches, static function ($branch) use ($branch_filter) {
    return $branch_filter === '' || (int) $branch['id'] === $branch_filter;
}));

$branch_label = $branch_filter !== '' ? reports_branch_label($branch_options[$branch_filter]['name'] ?? '') : 'All Branches';
$date_params = [$date_from, $date_to];
$job_params = $date_params;
$quote_params = $date_params;
$branch_sql = '';

if ($branch_filter !== '') {
    $branch_sql = ' AND branch_id = ?';
    $job_params[] = $branch_filter;
    $quote_params[] = $branch_filter;
}

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_jobs,
        COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed,
        COUNT(CASE WHEN status = 'in-progress' THEN 1 END) AS in_progress,
        COUNT(CASE WHEN status IN ('waiting', 'pending') THEN 1 END) AS waiting
    FROM job_orders
    WHERE status <> 'cancelled'
      AND job_date BETWEEN ? AND ?" . $branch_sql
);
$stmt->execute($job_params);
$job_stats = $stmt->fetch() ?: [];

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_quotes,
        COUNT(CASE WHEN status = 'approved' THEN 1 END) AS approved,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending,
        COUNT(CASE WHEN status = 'rejected' THEN 1 END) AS rejected,
        COALESCE(SUM(total_amount), 0) AS total_value,
        COALESCE(SUM(CASE WHEN status = 'approved' THEN total_amount ELSE 0 END), 0) AS approved_value
    FROM quotations
    WHERE quotation_date BETWEEN ? AND ?" . $branch_sql
);
$stmt->execute($quote_params);
$quote_stats = $stmt->fetch() ?: [];

$branch_performance = [];
foreach ($visible_branches as $branch) {
    $branch_performance[(int) $branch['id']] = [
        'id' => (int) $branch['id'],
        'name' => $branch['name'],
        'job_orders' => 0,
        'quotations' => 0,
        'revenue' => 0,
    ];
}

$stmt = $pdo->prepare("
    SELECT branch_id, COUNT(*) AS total
    FROM job_orders
    WHERE status <> 'cancelled'
      AND job_date BETWEEN ? AND ?" . $branch_sql . "
    GROUP BY branch_id
");
$stmt->execute($job_params);
foreach ($stmt->fetchAll() as $row) {
    $branch_id = (int) $row['branch_id'];
    if (isset($branch_performance[$branch_id])) {
        $branch_performance[$branch_id]['job_orders'] = (int) $row['total'];
    }
}

$stmt = $pdo->prepare("
    SELECT branch_id,
           COUNT(*) AS total,
           COALESCE(SUM(CASE WHEN status = 'approved' THEN total_amount ELSE 0 END), 0) AS revenue
    FROM quotations
    WHERE quotation_date BETWEEN ? AND ?" . $branch_sql . "
    GROUP BY branch_id
");
$stmt->execute($quote_params);
foreach ($stmt->fetchAll() as $row) {
    $branch_id = (int) $row['branch_id'];
    if (isset($branch_performance[$branch_id])) {
        $branch_performance[$branch_id]['quotations'] = (int) $row['total'];
        $branch_performance[$branch_id]['revenue'] = (float) $row['revenue'];
    }
}

$branch_performance = array_values($branch_performance);
$max_branch_count = 1;
$max_revenue = 1;
foreach ($branch_performance as $branch) {
    $max_branch_count = max($max_branch_count, (int) $branch['job_orders'], (int) $branch['quotations']);
    $max_revenue = max($max_revenue, (float) $branch['revenue']);
}
$revenue_axis_max_thousands = reports_nice_axis_max($max_revenue / 1000, 5);
$revenue_axis_max = $revenue_axis_max_thousands * 1000;
$revenue_axis_labels = reports_axis_labels($revenue_axis_max_thousands, 5);

$recent_params = $date_params;
$recent_branch_sql = '';
if ($branch_filter !== '') {
    $recent_branch_sql = ' AND q.branch_id = ?';
    $recent_params[] = $branch_filter;
}

$stmt = $pdo->prepare("
    SELECT q.*, c.name AS customer_name, b.name AS branch_name
    FROM quotations q
    LEFT JOIN customers c ON q.customer_id = c.id
    LEFT JOIN branches b ON q.branch_id = b.id
    WHERE q.quotation_date BETWEEN ? AND ?" . $recent_branch_sql . "
    ORDER BY q.created_at DESC, q.id DESC
    LIMIT 5
");
$stmt->execute($recent_params);
$recent_quotations = $stmt->fetchAll();

$recent_job_params = $date_params;
$recent_job_branch_sql = '';
if ($branch_filter !== '') {
    $recent_job_branch_sql = ' AND jo.branch_id = ?';
    $recent_job_params[] = $branch_filter;
}

$stmt = $pdo->prepare("
    SELECT jo.*, c.name AS customer_name, b.name AS branch_name
    FROM job_orders jo
    LEFT JOIN customers c ON jo.customer_id = c.id
    LEFT JOIN branches b ON jo.branch_id = b.id
    WHERE jo.status <> 'cancelled'
      AND jo.job_date BETWEEN ? AND ?" . $recent_job_branch_sql . "
    ORDER BY jo.created_at DESC, jo.id DESC
    LIMIT 5
");
$stmt->execute($recent_job_params);
$recent_jobs = $stmt->fetchAll();

$inventory_params = [$date_from, $date_to];
$inventory_branch_sql = '';
if ($branch_filter !== '') {
    $inventory_branch_sql = ' AND i.branch_id = ?';
    $inventory_params[] = $branch_filter;
}

$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_in' THEN ABS(t.quantity) ELSE 0 END), 0) AS stock_in_units,
        COALESCE(SUM(CASE WHEN LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out' THEN ABS(t.quantity) ELSE 0 END), 0) AS stock_out_units,
        COALESCE(SUM(CASE WHEN LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_in' THEN ABS(t.quantity) * i.unit_price ELSE 0 END), 0) AS stock_in_value,
        COALESCE(SUM(CASE WHEN LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out' THEN ABS(t.quantity) * i.unit_price ELSE 0 END), 0) AS stock_out_value,
        COUNT(*) AS movement_count
    FROM inventory_transactions t
    INNER JOIN inventory_items i ON i.id = t.item_id
    WHERE DATE(t.created_at) BETWEEN ? AND ?
      AND COALESCE(t.reference_type, '') <> 'opening_balance'" . $inventory_branch_sql . "
");
$stmt->execute($inventory_params);
$inventory_summary = $stmt->fetch() ?: [];
$inventory_summary = [
    'stock_in_units' => (int) ($inventory_summary['stock_in_units'] ?? 0),
    'stock_out_units' => (int) ($inventory_summary['stock_out_units'] ?? 0),
    'stock_in_value' => (float) ($inventory_summary['stock_in_value'] ?? 0),
    'stock_out_value' => (float) ($inventory_summary['stock_out_value'] ?? 0),
    'movement_count' => (int) ($inventory_summary['movement_count'] ?? 0),
];
$inventory_summary['net_units'] = $inventory_summary['stock_in_units'] - $inventory_summary['stock_out_units'];

$inventory_visible_branches = array_values(array_filter($visible_branches, static function ($branch) use ($branch_filter) {
    return $branch_filter !== '' || (int) ($branch['has_inventory'] ?? 0) === 1;
}));

$inventory_branch_movement = [];
foreach ($inventory_visible_branches as $branch) {
    $inventory_branch_movement[(int) $branch['id']] = [
        'id' => (int) $branch['id'],
        'name' => $branch['name'],
        'stock_in_units' => 0,
        'stock_out_units' => 0,
        'stock_out_value' => 0,
        'net_units' => 0,
    ];
}

$stmt = $pdo->prepare("
    SELECT
        i.branch_id,
        b.name AS branch_name,
        COALESCE(SUM(CASE WHEN LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_in' THEN ABS(t.quantity) ELSE 0 END), 0) AS stock_in_units,
        COALESCE(SUM(CASE WHEN LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out' THEN ABS(t.quantity) ELSE 0 END), 0) AS stock_out_units,
        COALESCE(SUM(CASE WHEN LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out' THEN ABS(t.quantity) * i.unit_price ELSE 0 END), 0) AS stock_out_value
    FROM inventory_transactions t
    INNER JOIN inventory_items i ON i.id = t.item_id
    LEFT JOIN branches b ON b.id = i.branch_id
    WHERE DATE(t.created_at) BETWEEN ? AND ?
      AND COALESCE(t.reference_type, '') <> 'opening_balance'" . $inventory_branch_sql . "
    GROUP BY i.branch_id, b.name
");
$stmt->execute($inventory_params);
foreach ($stmt->fetchAll() as $row) {
    $branch_id = (int) $row['branch_id'];
    if (!isset($inventory_branch_movement[$branch_id])) {
        $inventory_branch_movement[$branch_id] = [
            'id' => $branch_id,
            'name' => $row['branch_name'] ?? 'Branch',
            'stock_in_units' => 0,
            'stock_out_units' => 0,
            'stock_out_value' => 0,
            'net_units' => 0,
        ];
    }

    $inventory_branch_movement[$branch_id]['stock_in_units'] = (int) $row['stock_in_units'];
    $inventory_branch_movement[$branch_id]['stock_out_units'] = (int) $row['stock_out_units'];
    $inventory_branch_movement[$branch_id]['stock_out_value'] = (float) $row['stock_out_value'];
    $inventory_branch_movement[$branch_id]['net_units'] = (int) $row['stock_in_units'] - (int) $row['stock_out_units'];
}
$inventory_branch_movement = array_values($inventory_branch_movement);
$max_inventory_units = 1;
foreach ($inventory_branch_movement as $branch) {
    $max_inventory_units = max($max_inventory_units, (int) $branch['stock_in_units'], (int) $branch['stock_out_units']);
}

$inventory_category_movement = reports_empty_inventory_categories();
$stmt = $pdo->prepare("
    SELECT
        i.category,
        COALESCE(SUM(CASE WHEN LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_in' THEN ABS(t.quantity) ELSE 0 END), 0) AS stock_in_units,
        COALESCE(SUM(CASE WHEN LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out' THEN ABS(t.quantity) ELSE 0 END), 0) AS stock_out_units,
        COALESCE(SUM(CASE WHEN LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out' THEN ABS(t.quantity) * i.unit_price ELSE 0 END), 0) AS stock_out_value
    FROM inventory_transactions t
    INNER JOIN inventory_items i ON i.id = t.item_id
    WHERE DATE(t.created_at) BETWEEN ? AND ?
      AND COALESCE(t.reference_type, '') <> 'opening_balance'" . $inventory_branch_sql . "
    GROUP BY i.category
");
$stmt->execute($inventory_params);
foreach ($stmt->fetchAll() as $row) {
    $category = strtolower((string) ($row['category'] ?? 'part'));
    if (!isset($inventory_category_movement[$category])) {
        $category = 'part';
    }

    $inventory_category_movement[$category]['stock_in_units'] = (int) $row['stock_in_units'];
    $inventory_category_movement[$category]['stock_out_units'] = (int) $row['stock_out_units'];
    $inventory_category_movement[$category]['stock_out_value'] = (float) $row['stock_out_value'];
    $inventory_category_movement[$category]['net_units'] = (int) $row['stock_in_units'] - (int) $row['stock_out_units'];
}
$inventory_category_movement = array_values($inventory_category_movement);
$max_inventory_category_units = 1;
foreach ($inventory_category_movement as $category) {
    $max_inventory_category_units = max($max_inventory_category_units, (int) $category['stock_in_units'], (int) $category['stock_out_units']);
}

$stmt = $pdo->prepare("
    SELECT
        i.id,
        i.item_name,
        i.category,
        i.brand,
        i.size,
        b.name AS branch_name,
        COALESCE(SUM(CASE WHEN LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_in' THEN ABS(t.quantity) ELSE 0 END), 0) AS stock_in_units,
        COALESCE(SUM(CASE WHEN LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out' THEN ABS(t.quantity) ELSE 0 END), 0) AS stock_out_units,
        COALESCE(SUM(ABS(t.quantity)), 0) AS total_movement,
        MAX(t.created_at) AS last_movement
    FROM inventory_transactions t
    INNER JOIN inventory_items i ON i.id = t.item_id
    LEFT JOIN branches b ON b.id = i.branch_id
    WHERE DATE(t.created_at) BETWEEN ? AND ?
      AND COALESCE(t.reference_type, '') <> 'opening_balance'" . $inventory_branch_sql . "
    GROUP BY i.id, i.item_name, i.category, i.brand, i.size, b.name
    ORDER BY total_movement DESC, last_movement DESC
    LIMIT 8
");
$stmt->execute($inventory_params);
$inventory_top_items = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT
        t.id,
        t.created_at,
        t.quantity,
        t.notes,
        t.reference_type,
        i.item_name,
        i.category,
        i.brand,
        i.size,
        i.unit_price,
        b.name AS branch_name,
        tagged_customer.name AS tagged_customer_name,
        tagged_customer.phone_mobile AS tagged_customer_phone,
        tagged_vehicle.plate_number AS tagged_plate_number,
        tagged_vehicle.make AS tagged_vehicle_make,
        tagged_vehicle.model AS tagged_vehicle_model,
        tagged_vehicle.year AS tagged_vehicle_year,
        tagged_job.job_number AS tagged_job_number,
        tagged_quotation.quotation_number AS tagged_quotation_number
    FROM inventory_transactions t
    INNER JOIN inventory_items i ON i.id = t.item_id
    LEFT JOIN branches b ON b.id = i.branch_id
    LEFT JOIN customers tagged_customer ON tagged_customer.id = t.customer_id
    LEFT JOIN vehicles tagged_vehicle ON tagged_vehicle.id = t.vehicle_id
    LEFT JOIN job_orders tagged_job ON tagged_job.id = t.job_order_id
    LEFT JOIN quotations tagged_quotation ON tagged_quotation.id = t.quotation_id
    WHERE LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out'
      AND DATE(t.created_at) BETWEEN ? AND ?
      AND COALESCE(t.reference_type, '') <> 'opening_balance'" . $inventory_branch_sql . "
    ORDER BY t.created_at DESC, t.id DESC
    LIMIT 12
");
$stmt->execute($inventory_params);
$inventory_tagged_stock_outs = $stmt->fetchAll();

$service_status_options = [
    'all' => 'All Statuses',
    'pending' => 'Pending',
    'in-progress' => 'In Progress',
    'completed' => 'Completed',
    'rejected' => 'Rejected Service Operation',
];
$item_status_options = [
    'all' => 'All Sales',
    'tagged' => 'Customer Tagged',
    'untagged' => 'Walk-in / Not Tagged',
];
$vehicle_status_options = [
    'all' => 'All Records',
    'active' => 'Active Vehicles',
    'inactive' => 'Inactive Vehicles',
    'with_services' => 'With Services',
    'with_items' => 'With Items',
];
$vehicle_history_status_options = [
    'all' => 'All Vehicles',
    'with_previous_owner' => 'With Previous Owner',
    'single_owner' => 'Single Owner',
    'with_services' => 'With Services',
    'with_items' => 'With Items',
];
$stock_movement_status_options = [
    'all' => 'All Movements',
    'stock_in' => 'Stock In',
    'stock_out' => 'Stock Out',
    'adjustment' => 'Adjustment',
    'damage' => 'Damage',
    'tagged' => 'Customer Tagged',
];
$archive_status_options = [
    'all' => 'All Archived',
    'customers' => 'Customers',
    'vehicles' => 'Vehicles',
    'service_operations' => 'Service Operations',
    'job_orders' => 'Job Orders',
    'inventory_items' => 'Inventory Items',
];
switch ($report_tab) {
    case 'items':
        $active_status_options = $item_status_options;
        break;
    case 'vehicles':
        $active_status_options = $vehicle_status_options;
        break;
    case 'vehicle_history':
        $active_status_options = $vehicle_history_status_options;
        break;
    case 'stock_movement':
        $active_status_options = $stock_movement_status_options;
        break;
    case 'archives':
        $active_status_options = $archive_status_options;
        break;
    default:
        $active_status_options = $service_status_options;
        break;
}
if (!array_key_exists($status_filter, $active_status_options)) {
    $status_filter = 'all';
}

$detailed_services = [];
$customer_vehicle_report = [];
$vehicle_history_report = [];
$stock_movement_report = [];
$archive_report = [];
$detail_records = [];
$detail_total_records = 0;

$services_base_params = [$date_from, $date_to];
$services_branch_sql = '';
if ($branch_filter !== '') {
    $services_branch_sql = ' AND q.branch_id = ?';
    $services_base_params[] = $branch_filter;
}

$services_where = [];
$services_params = $services_base_params;
if ($status_filter !== 'all' && $report_tab === 'services') {
    if ($status_filter === 'pending') {
        $services_where[] = "(COALESCE(jo.status, q.status) IN ('pending', 'waiting'))";
    } else {
        $services_where[] = 'COALESCE(jo.status, q.status) = ?';
        $services_params[] = $status_filter;
    }
}

if ($search_filter !== '') {
    foreach (app_search_terms($search_filter) as $term) {
        $services_where[] = "(
            c.name LIKE ?
            OR c.phone_mobile LIKE ?
            OR c.contact LIKE ?
            OR v.plate_number LIKE ?
            OR v.make LIKE ?
            OR v.model LIKE ?
            OR q.quotation_number LIKE ?
            OR jo.job_number LIKE ?
            OR q.notes LIKE ?
            OR service_rollup.service_names LIKE ?
        )";
        $like = '%' . $term . '%';
        $services_params = array_merge($services_params, array_fill(0, 10, $like));
    }
}
$services_extra_sql = !empty($services_where) ? ' AND ' . implode(' AND ', $services_where) : '';

$services_query = "
    FROM quotations q
    LEFT JOIN job_orders jo ON jo.quotation_id = q.id AND jo.status NOT IN ('archived', 'cancelled')
    LEFT JOIN customers c ON c.id = q.customer_id
    LEFT JOIN vehicles v ON v.id = q.vehicle_id
    LEFT JOIN branches b ON b.id = q.branch_id
    LEFT JOIN (
        SELECT quotation_id, GROUP_CONCAT(item_name ORDER BY id SEPARATOR ', ') AS service_names
        FROM quotation_items
        WHERE item_type = 'service'
        GROUP BY quotation_id
    ) service_rollup ON service_rollup.quotation_id = q.id
    WHERE q.quotation_date BETWEEN ? AND ?" . $services_branch_sql . $services_extra_sql . "
";
$services_count_stmt = $pdo->prepare("SELECT COUNT(*) AS total " . $services_query);
$services_count_stmt->execute($services_params);
$services_total = (int) ($services_count_stmt->fetch()['total'] ?? 0);
if ($report_tab === 'services') {
    $detail_page = min($detail_page, max(1, (int) ceil($services_total / $detail_per_page)));
}

$services_limit = ($is_export_request && $report_tab === 'services') ? max(1, $services_total) : ($report_tab === 'services' ? $detail_per_page : 30);
$services_offset = ($is_export_request && $report_tab === 'services') ? 0 : ($report_tab === 'services' ? (($detail_page - 1) * $detail_per_page) : 0);
$services_stmt = $pdo->prepare("
    SELECT
        q.id AS quotation_id,
        q.quotation_number,
        q.quotation_date AS service_date,
        q.total_amount,
        q.status AS quotation_status,
        jo.id AS job_order_id,
        jo.job_number,
        jo.status AS job_status,
        COALESCE(jo.status, q.status) AS report_status,
        c.id AS customer_id,
        c.name AS customer_name,
        COALESCE(NULLIF(c.phone_mobile, ''), NULLIF(c.contact, ''), '') AS customer_phone,
        v.id AS vehicle_id,
        v.plate_number,
        v.make AS vehicle_make,
        v.model AS vehicle_model,
        v.year AS vehicle_year,
        b.name AS branch_name,
        COALESCE(service_rollup.service_names, 'General service') AS service_names
    " . $services_query . "
    ORDER BY q.quotation_date DESC, q.created_at DESC, q.id DESC
    LIMIT $services_limit OFFSET $services_offset
");
$services_stmt->execute($services_params);
$detailed_services = $services_stmt->fetchAll();

$inventory_item_select_extra = '';
$inventory_item_group_extra = '';
if (app_column_exists('inventory_items', 'model')) {
    $inventory_item_select_extra .= ', i.model AS inventory_model';
    $inventory_item_group_extra .= ', i.model';
}
if (app_column_exists('inventory_items', 'serial_number')) {
    $inventory_item_select_extra .= ', i.serial_number';
    $inventory_item_group_extra .= ', i.serial_number';
}
if (app_column_exists('inventory_items', 'manufacturing_date')) {
    $inventory_item_select_extra .= ', i.manufacturing_date';
    $inventory_item_group_extra .= ', i.manufacturing_date';
}

$item_where = [
    "LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out'",
    "DATE(t.created_at) BETWEEN ? AND ?",
    "COALESCE(t.reference_type, '') <> 'opening_balance'",
];
$item_params = [$date_from, $date_to];
if ($branch_filter !== '') {
    $item_where[] = 'i.branch_id = ?';
    $item_params[] = $branch_filter;
}
if ($status_filter === 'tagged' && $report_tab === 'items') {
    $item_where[] = '(t.customer_id IS NOT NULL OR t.vehicle_id IS NOT NULL)';
} elseif ($status_filter === 'untagged' && $report_tab === 'items') {
    $item_where[] = 't.customer_id IS NULL AND t.vehicle_id IS NULL';
}
if ($search_filter !== '') {
    $item_search_columns = [
        'i.item_name',
        'i.brand',
        'i.size',
        'i.sku',
        'i.description',
        'i.category',
        'b.name',
        'tagged_customer.name',
        'tagged_customer.phone_mobile',
        'tagged_vehicle.plate_number',
        'tagged_vehicle.make',
        'tagged_vehicle.model',
        'tagged_job.job_number',
        'tagged_quotation.quotation_number',
        't.notes',
    ];

    if (app_column_exists('inventory_items', 'model')) {
        $item_search_columns[] = 'i.model';
    }
    if (app_column_exists('inventory_items', 'serial_number')) {
        $item_search_columns[] = 'i.serial_number';
    }

    foreach (app_search_terms($search_filter) as $term) {
        $item_where[] = '(' . implode(' OR ', array_map(static function ($column) {
            return $column . ' LIKE ?';
        }, $item_search_columns)) . ')';
        $like = '%' . $term . '%';
        $item_params = array_merge($item_params, array_fill(0, count($item_search_columns), $like));
    }
}
$item_where_sql = implode(' AND ', $item_where);

$items_count_stmt = $pdo->prepare("
    SELECT COUNT(*) AS total
    FROM inventory_transactions t
    INNER JOIN inventory_items i ON i.id = t.item_id
    LEFT JOIN branches b ON b.id = i.branch_id
    LEFT JOIN customers tagged_customer ON tagged_customer.id = t.customer_id
    LEFT JOIN vehicles tagged_vehicle ON tagged_vehicle.id = t.vehicle_id
    LEFT JOIN job_orders tagged_job ON tagged_job.id = t.job_order_id
    LEFT JOIN quotations tagged_quotation ON tagged_quotation.id = t.quotation_id
    WHERE $item_where_sql
");
$items_count_stmt->execute($item_params);
$items_total = (int) ($items_count_stmt->fetch()['total'] ?? 0);
if ($report_tab === 'items') {
    $detail_page = min($detail_page, max(1, (int) ceil($items_total / $detail_per_page)));
}

$items_limit = ($is_export_request && $report_tab === 'items') ? max(1, $items_total) : ($report_tab === 'items' ? $detail_per_page : 30);
$items_offset = ($is_export_request && $report_tab === 'items') ? 0 : ($report_tab === 'items' ? (($detail_page - 1) * $detail_per_page) : 0);
$items_stmt = $pdo->prepare("
    SELECT
        t.id,
        t.created_at,
        t.quantity,
        t.notes,
        t.reference_type,
        t.customer_id AS tagged_customer_id,
        t.vehicle_id AS tagged_vehicle_id,
        t.job_order_id AS tagged_job_order_id,
        t.quotation_id AS tagged_quotation_id,
        i.item_name,
        i.category,
        i.brand,
        i.size,
        i.description,
        i.sku,
        i.unit_price
        $inventory_item_select_extra,
        b.name AS branch_name,
        tagged_customer.name AS tagged_customer_name,
        tagged_customer.phone_mobile AS tagged_customer_phone,
        tagged_vehicle.plate_number AS tagged_plate_number,
        tagged_vehicle.make AS tagged_vehicle_make,
        tagged_vehicle.model AS tagged_vehicle_model,
        tagged_vehicle.year AS tagged_vehicle_year,
        tagged_job.job_number AS tagged_job_number,
        tagged_quotation.quotation_number AS tagged_quotation_number
    FROM inventory_transactions t
    INNER JOIN inventory_items i ON i.id = t.item_id
    LEFT JOIN branches b ON b.id = i.branch_id
    LEFT JOIN customers tagged_customer ON tagged_customer.id = t.customer_id
    LEFT JOIN vehicles tagged_vehicle ON tagged_vehicle.id = t.vehicle_id
    LEFT JOIN job_orders tagged_job ON tagged_job.id = t.job_order_id
    LEFT JOIN quotations tagged_quotation ON tagged_quotation.id = t.quotation_id
    WHERE $item_where_sql
    ORDER BY t.created_at DESC, t.id DESC
    LIMIT $items_limit OFFSET $items_offset
");
$items_stmt->execute($item_params);
$inventory_tagged_stock_outs = $items_stmt->fetchAll();

$vehicle_base_params = [$date_from, $date_to, $date_from, $date_to, $date_from, $date_to, $date_from, $date_to];
$vehicle_branch_condition = '';
if ($branch_filter !== '') {
    $vehicle_branch_condition = ' AND v.branch_id = ?';
    $vehicle_base_params[] = $branch_filter;
}
$vehicle_filters = [];
if ($status_filter === 'active' && $report_tab === 'vehicles') {
    $vehicle_filters[] = "vehicle_status = 'active'";
} elseif ($status_filter === 'inactive' && $report_tab === 'vehicles') {
    $vehicle_filters[] = "vehicle_status = 'inactive'";
} elseif ($status_filter === 'with_services' && $report_tab === 'vehicles') {
    $vehicle_filters[] = 'service_count > 0';
} elseif ($status_filter === 'with_items' && $report_tab === 'vehicles') {
    $vehicle_filters[] = 'items_given_count > 0';
}

if ($search_filter !== '') {
    foreach (app_search_terms($search_filter) as $term) {
        $vehicle_filters[] = "(
            customer_name LIKE ?
            OR customer_phone LIKE ?
            OR plate_number LIKE ?
            OR vehicle_make LIKE ?
            OR vehicle_model LIKE ?
            OR branch_name LIKE ?
            OR last_visited_branch LIKE ?
        )";
        $like = '%' . $term . '%';
        $vehicle_base_params = array_merge($vehicle_base_params, array_fill(0, 7, $like));
    }
}
$vehicle_filter_sql = !empty($vehicle_filters) ? ' AND ' . implode(' AND ', $vehicle_filters) : '';

$vehicle_report_query = "
    FROM (
        SELECT
            v.id AS vehicle_id,
            v.status AS vehicle_status,
            v.plate_number,
            v.make AS vehicle_make,
            v.model AS vehicle_model,
            v.year AS vehicle_year,
            c.id AS customer_id,
            c.name AS customer_name,
            COALESCE(NULLIF(c.phone_mobile, ''), NULLIF(c.contact, ''), '') AS customer_phone,
            b.name AS branch_name,
            COALESCE((
                SELECT b_recent.name
                FROM (
                    SELECT jo.vehicle_id, jo.branch_id, jo.job_date AS visited_at, jo.id AS row_id
                    FROM job_orders jo
                    WHERE jo.status <> 'cancelled'
                    UNION ALL
                    SELECT q.vehicle_id, q.branch_id, q.quotation_date AS visited_at, q.id AS row_id
                    FROM quotations q
                ) recent
                INNER JOIN branches b_recent ON b_recent.id = recent.branch_id
                WHERE recent.vehicle_id = v.id
                ORDER BY recent.visited_at DESC, recent.row_id DESC
                LIMIT 1
            ), b.name) AS last_visited_branch,
            COALESCE(service_counts.service_count, 0) AS service_count,
            COALESCE(item_counts.items_given_count, 0) AS items_given_count,
            COALESCE(sales_counts.sales_value, 0) AS sales_value,
            GREATEST(
                COALESCE(service_counts.last_service_date, '1000-01-01'),
                COALESCE(item_counts.last_item_date, '1000-01-01'),
                COALESCE(q_counts.last_quotation_date, '1000-01-01')
            ) AS last_visit_date
        FROM vehicles v
        INNER JOIN customers c ON c.id = v.customer_id
        LEFT JOIN branches b ON b.id = v.branch_id
        LEFT JOIN (
            SELECT vehicle_id, COUNT(*) AS service_count, MAX(job_date) AS last_service_date
            FROM job_orders
            WHERE status <> 'cancelled'
              AND job_date BETWEEN ? AND ?
            GROUP BY vehicle_id
        ) service_counts ON service_counts.vehicle_id = v.id
        LEFT JOIN (
            SELECT t.vehicle_id, COUNT(*) AS items_given_count, MAX(DATE(t.created_at)) AS last_item_date
            FROM inventory_transactions t
            WHERE LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out'
              AND t.vehicle_id IS NOT NULL
              AND DATE(t.created_at) BETWEEN ? AND ?
            GROUP BY t.vehicle_id
        ) item_counts ON item_counts.vehicle_id = v.id
        LEFT JOIN (
            SELECT vehicle_id, COALESCE(SUM(total_amount), 0) AS sales_value, MAX(quotation_date) AS last_quotation_date
            FROM quotations
            WHERE quotation_date BETWEEN ? AND ?
            GROUP BY vehicle_id
        ) sales_counts ON sales_counts.vehicle_id = v.id
        LEFT JOIN (
            SELECT vehicle_id, MAX(quotation_date) AS last_quotation_date
            FROM quotations
            WHERE quotation_date BETWEEN ? AND ?
            GROUP BY vehicle_id
        ) q_counts ON q_counts.vehicle_id = v.id
        WHERE 1 = 1" . $vehicle_branch_condition . "
    ) vehicle_report
    WHERE last_visit_date <> '1000-01-01'" . $vehicle_filter_sql . "
";

$vehicle_count_stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM (SELECT * " . $vehicle_report_query . ") counted_vehicle_report");
$vehicle_count_stmt->execute($vehicle_base_params);
$vehicles_total = (int) ($vehicle_count_stmt->fetch()['total'] ?? 0);
if ($report_tab === 'vehicles') {
    $detail_page = min($detail_page, max(1, (int) ceil($vehicles_total / $detail_per_page)));
}

$vehicles_limit = ($is_export_request && $report_tab === 'vehicles') ? max(1, $vehicles_total) : ($report_tab === 'vehicles' ? $detail_per_page : 30);
$vehicles_offset = ($is_export_request && $report_tab === 'vehicles') ? 0 : ($report_tab === 'vehicles' ? (($detail_page - 1) * $detail_per_page) : 0);
$vehicle_stmt = $pdo->prepare("
    SELECT *
    " . $vehicle_report_query . "
    ORDER BY last_visit_date DESC, customer_name ASC
    LIMIT $vehicles_limit OFFSET $vehicles_offset
");
$vehicle_stmt->execute($vehicle_base_params);
$customer_vehicle_report = $vehicle_stmt->fetchAll();

$vehicle_history_total = 0;
if ($report_tab === 'vehicle_history') {
    $has_ownership_history = app_table_exists('vehicle_ownership_history');
    $ownership_select = $has_ownership_history
        ? "COALESCE(ownership.owner_count, 1) AS owner_count,
            COALESCE(ownership.previous_owner_names, '') AS previous_owner_names,
            ownership.first_owned_from"
        : "1 AS owner_count,
            '' AS previous_owner_names,
            DATE(v.created_at) AS first_owned_from";
    $ownership_join = $has_ownership_history
        ? "LEFT JOIN (
            SELECT
                h.vehicle_id,
                COUNT(*) AS owner_count,
                GROUP_CONCAT(CASE WHEN h.is_current = 0 THEN owner_customer.name END ORDER BY h.owned_from SEPARATOR ', ') AS previous_owner_names,
                MIN(h.owned_from) AS first_owned_from
            FROM vehicle_ownership_history h
            LEFT JOIN customers owner_customer ON owner_customer.id = h.customer_id
            GROUP BY h.vehicle_id
        ) ownership ON ownership.vehicle_id = v.id"
        : '';

    $vehicle_history_params = [$date_from, $date_to, $date_from, $date_to, $date_from, $date_to, $date_from, $date_to];
    $vehicle_history_branch_condition = '';
    if ($branch_filter !== '') {
        $vehicle_history_branch_condition = ' AND v.branch_id = ?';
        $vehicle_history_params[] = $branch_filter;
    }

    $vehicle_history_filters = [];
    if ($status_filter === 'with_previous_owner') {
        $vehicle_history_filters[] = 'owner_count > 1';
    } elseif ($status_filter === 'single_owner') {
        $vehicle_history_filters[] = 'owner_count <= 1';
    } elseif ($status_filter === 'with_services') {
        $vehicle_history_filters[] = 'service_count > 0';
    } elseif ($status_filter === 'with_items') {
        $vehicle_history_filters[] = 'items_given_count > 0';
    }

    if ($search_filter !== '') {
        foreach (app_search_terms($search_filter) as $term) {
            $vehicle_history_filters[] = "(
                customer_name LIKE ?
                OR customer_phone LIKE ?
                OR plate_number LIKE ?
                OR vehicle_make LIKE ?
                OR vehicle_model LIKE ?
                OR branch_name LIKE ?
                OR last_visited_branch LIKE ?
                OR previous_owner_names LIKE ?
            )";
            $like = '%' . $term . '%';
            $vehicle_history_params = array_merge($vehicle_history_params, array_fill(0, 8, $like));
        }
    }

    $vehicle_history_filter_sql = !empty($vehicle_history_filters) ? ' AND ' . implode(' AND ', $vehicle_history_filters) : '';
    $vehicle_history_report_query = "
        FROM (
            SELECT
                v.id AS vehicle_id,
                v.status AS vehicle_status,
                v.plate_number,
                v.make AS vehicle_make,
                v.model AS vehicle_model,
                v.year AS vehicle_year,
                v.last_mileage,
                c.id AS customer_id,
                c.name AS customer_name,
                COALESCE(NULLIF(c.phone_mobile, ''), NULLIF(c.contact, ''), '') AS customer_phone,
                b.name AS branch_name,
                COALESCE((
                    SELECT b_recent.name
                    FROM (
                        SELECT jo.vehicle_id, jo.branch_id, jo.job_date AS visited_at, jo.id AS row_id
                        FROM job_orders jo
                        WHERE jo.status <> 'cancelled'
                        UNION ALL
                        SELECT q.vehicle_id, q.branch_id, q.quotation_date AS visited_at, q.id AS row_id
                        FROM quotations q
                    ) recent
                    INNER JOIN branches b_recent ON b_recent.id = recent.branch_id
                    WHERE recent.vehicle_id = v.id
                    ORDER BY recent.visited_at DESC, recent.row_id DESC
                    LIMIT 1
                ), b.name) AS last_visited_branch,
                COALESCE(service_counts.service_count, 0) AS service_count,
                COALESCE(item_counts.items_given_count, 0) AS items_given_count,
                COALESCE(sales_counts.sales_value, 0) AS sales_value,
                " . $ownership_select . ",
                GREATEST(
                    COALESCE(service_counts.last_service_date, '1000-01-01'),
                    COALESCE(item_counts.last_item_date, '1000-01-01'),
                    COALESCE(q_counts.last_quotation_date, '1000-01-01')
                ) AS last_visit_date
            FROM vehicles v
            INNER JOIN customers c ON c.id = v.customer_id
            LEFT JOIN branches b ON b.id = v.branch_id
            LEFT JOIN (
                SELECT vehicle_id, COUNT(*) AS service_count, MAX(job_date) AS last_service_date
                FROM job_orders
                WHERE status <> 'cancelled'
                  AND job_date BETWEEN ? AND ?
                GROUP BY vehicle_id
            ) service_counts ON service_counts.vehicle_id = v.id
            LEFT JOIN (
                SELECT t.vehicle_id, COUNT(*) AS items_given_count, MAX(DATE(t.created_at)) AS last_item_date
                FROM inventory_transactions t
                WHERE LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out'
                  AND t.vehicle_id IS NOT NULL
                  AND DATE(t.created_at) BETWEEN ? AND ?
                GROUP BY t.vehicle_id
            ) item_counts ON item_counts.vehicle_id = v.id
            LEFT JOIN (
                SELECT vehicle_id, COALESCE(SUM(total_amount), 0) AS sales_value, MAX(quotation_date) AS last_quotation_date
                FROM quotations
                WHERE quotation_date BETWEEN ? AND ?
                GROUP BY vehicle_id
            ) sales_counts ON sales_counts.vehicle_id = v.id
            LEFT JOIN (
                SELECT vehicle_id, MAX(quotation_date) AS last_quotation_date
                FROM quotations
                WHERE quotation_date BETWEEN ? AND ?
                GROUP BY vehicle_id
            ) q_counts ON q_counts.vehicle_id = v.id
            " . $ownership_join . "
            WHERE 1 = 1" . $vehicle_history_branch_condition . "
        ) vehicle_history
        WHERE last_visit_date <> '1000-01-01'" . $vehicle_history_filter_sql . "
    ";

    $vehicle_history_count_stmt = $pdo->prepare("SELECT COUNT(*) AS total " . $vehicle_history_report_query);
    $vehicle_history_count_stmt->execute($vehicle_history_params);
    $vehicle_history_total = (int) ($vehicle_history_count_stmt->fetch()['total'] ?? 0);
    $detail_page = min($detail_page, max(1, (int) ceil($vehicle_history_total / $detail_per_page)));

    $vehicle_history_limit = $is_export_request ? max(1, $vehicle_history_total) : $detail_per_page;
    $vehicle_history_offset = $is_export_request ? 0 : (($detail_page - 1) * $detail_per_page);
    $vehicle_history_stmt = $pdo->prepare("
        SELECT *
        " . $vehicle_history_report_query . "
        ORDER BY last_visit_date DESC, customer_name ASC
        LIMIT $vehicle_history_limit OFFSET $vehicle_history_offset
    ");
    $vehicle_history_stmt->execute($vehicle_history_params);
    $vehicle_history_report = $vehicle_history_stmt->fetchAll();
}

$stock_movement_total = 0;
if ($report_tab === 'stock_movement') {
    $movement_where = [
        'DATE(t.created_at) BETWEEN ? AND ?',
        "COALESCE(t.reference_type, '') <> 'opening_balance'",
    ];
    $movement_params = [$date_from, $date_to];
    if ($branch_filter !== '') {
        $movement_where[] = 'i.branch_id = ?';
        $movement_params[] = $branch_filter;
    }
    if (in_array($status_filter, ['stock_in', 'stock_out', 'adjustment', 'damage'], true)) {
        $movement_where[] = "LOWER(REPLACE(t.transaction_type, ' ', '_')) = ?";
        $movement_params[] = $status_filter;
    } elseif ($status_filter === 'tagged') {
        $movement_where[] = '(t.customer_id IS NOT NULL OR t.vehicle_id IS NOT NULL)';
    }
    if ($search_filter !== '') {
        $movement_search_columns = [
            'i.item_name',
            'i.brand',
            'i.size',
            'i.sku',
            'i.description',
            'i.category',
            'b.name',
            'tagged_customer.name',
            'tagged_customer.phone_mobile',
            'tagged_vehicle.plate_number',
            'tagged_vehicle.make',
            'tagged_vehicle.model',
            'tagged_job.job_number',
            'tagged_quotation.quotation_number',
            'entered_user.name',
            't.notes',
        ];
        if (app_column_exists('inventory_items', 'model')) {
            $movement_search_columns[] = 'i.model';
        }
        if (app_column_exists('inventory_items', 'serial_number')) {
            $movement_search_columns[] = 'i.serial_number';
        }

        foreach (app_search_terms($search_filter) as $term) {
            $movement_where[] = '(' . implode(' OR ', array_map(static function ($column) {
                return $column . ' LIKE ?';
            }, $movement_search_columns)) . ')';
            $like = '%' . $term . '%';
            $movement_params = array_merge($movement_params, array_fill(0, count($movement_search_columns), $like));
        }
    }
    $movement_where_sql = implode(' AND ', $movement_where);

    $movement_count_stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM inventory_transactions t
        INNER JOIN inventory_items i ON i.id = t.item_id
        LEFT JOIN branches b ON b.id = i.branch_id
        LEFT JOIN customers tagged_customer ON tagged_customer.id = t.customer_id
        LEFT JOIN vehicles tagged_vehicle ON tagged_vehicle.id = t.vehicle_id
        LEFT JOIN job_orders tagged_job ON tagged_job.id = COALESCE(t.job_order_id, CASE WHEN t.reference_type = 'job_order' THEN t.reference_id ELSE NULL END)
        LEFT JOIN quotations tagged_quotation ON tagged_quotation.id = COALESCE(t.quotation_id, CASE WHEN t.reference_type = 'quotation' THEN t.reference_id ELSE NULL END)
        LEFT JOIN users entered_user ON entered_user.id = t.created_by
        WHERE $movement_where_sql
    ");
    $movement_count_stmt->execute($movement_params);
    $stock_movement_total = (int) ($movement_count_stmt->fetch()['total'] ?? 0);
    $detail_page = min($detail_page, max(1, (int) ceil($stock_movement_total / $detail_per_page)));

    $movement_limit = $is_export_request ? max(1, $stock_movement_total) : $detail_per_page;
    $movement_offset = $is_export_request ? 0 : (($detail_page - 1) * $detail_per_page);
    $movement_stmt = $pdo->prepare("
        SELECT
            t.id,
            t.created_at,
            t.transaction_type,
            t.quantity,
            t.notes,
            t.reference_type,
            t.customer_id AS tagged_customer_id,
            t.vehicle_id AS tagged_vehicle_id,
            t.job_order_id AS tagged_job_order_id,
            t.quotation_id AS tagged_quotation_id,
            i.item_name,
            i.category,
            i.brand,
            i.size,
            i.description,
            i.sku,
            i.unit_price
            $inventory_item_select_extra,
            b.name AS branch_name,
            tagged_customer.name AS tagged_customer_name,
            tagged_customer.phone_mobile AS tagged_customer_phone,
            tagged_vehicle.plate_number AS tagged_plate_number,
            tagged_vehicle.make AS tagged_vehicle_make,
            tagged_vehicle.model AS tagged_vehicle_model,
            tagged_vehicle.year AS tagged_vehicle_year,
            tagged_job.id AS resolved_job_order_id,
            tagged_job.job_number AS tagged_job_number,
            tagged_quotation.id AS resolved_quotation_id,
            tagged_quotation.quotation_number AS tagged_quotation_number,
            COALESCE(entered_user.name, 'System') AS entered_by_name
        FROM inventory_transactions t
        INNER JOIN inventory_items i ON i.id = t.item_id
        LEFT JOIN branches b ON b.id = i.branch_id
        LEFT JOIN customers tagged_customer ON tagged_customer.id = t.customer_id
        LEFT JOIN vehicles tagged_vehicle ON tagged_vehicle.id = t.vehicle_id
        LEFT JOIN job_orders tagged_job ON tagged_job.id = COALESCE(t.job_order_id, CASE WHEN t.reference_type = 'job_order' THEN t.reference_id ELSE NULL END)
        LEFT JOIN quotations tagged_quotation ON tagged_quotation.id = COALESCE(t.quotation_id, CASE WHEN t.reference_type = 'quotation' THEN t.reference_id ELSE NULL END)
        LEFT JOIN users entered_user ON entered_user.id = t.created_by
        WHERE $movement_where_sql
        ORDER BY t.created_at DESC, t.id DESC
        LIMIT $movement_limit OFFSET $movement_offset
    ");
    $movement_stmt->execute($movement_params);
    $stock_movement_report = $movement_stmt->fetchAll();
}

$archives_total = 0;
if ($report_tab === 'archives') {
    $archive_parts = [];
    $archive_params = [];
    $archive_branch_customer = $branch_filter !== '' ? ' AND c.branch_id = ?' : '';
    $archive_branch_vehicle = $branch_filter !== '' ? ' AND v.branch_id = ?' : '';
    $archive_branch_quotation = $branch_filter !== '' ? ' AND q.branch_id = ?' : '';
    $archive_branch_job = $branch_filter !== '' ? ' AND jo.branch_id = ?' : '';
    $archive_branch_item = $branch_filter !== '' ? ' AND i.branch_id = ?' : '';

    $archive_parts[] = "
        SELECT
            'customer' AS archive_type,
            c.id AS record_id,
            c.name AS record_label,
            c.status AS original_status,
            b.name AS branch_name,
            COALESCE(c.archived_at, c.updated_at, c.created_at) AS archived_at,
            archived_user.name AS archived_by_name,
            c.archive_reason,
            c.id AS customer_id,
            c.name AS customer_name,
            NULL AS vehicle_id,
            NULL AS plate_number,
            NULL AS vehicle_make,
            NULL AS vehicle_model,
            NULL AS vehicle_year
        FROM customers c
        LEFT JOIN branches b ON b.id = c.branch_id
        LEFT JOIN users archived_user ON archived_user.id = c.archived_by
        WHERE (c.archived_at IS NOT NULL OR c.status = 'archived')
          AND DATE(COALESCE(c.archived_at, c.updated_at, c.created_at)) BETWEEN ? AND ?" . $archive_branch_customer;
    $archive_params = array_merge($archive_params, [$date_from, $date_to]);
    if ($branch_filter !== '') {
        $archive_params[] = $branch_filter;
    }

    $archive_parts[] = "
        SELECT
            'vehicle' AS archive_type,
            v.id AS record_id,
            COALESCE(NULLIF(v.plate_number, ''), CONCAT_WS(' ', v.year, v.make, v.model)) AS record_label,
            v.status AS original_status,
            b.name AS branch_name,
            COALESCE(v.archived_at, v.updated_at, v.created_at) AS archived_at,
            archived_user.name AS archived_by_name,
            v.archive_reason,
            c.id AS customer_id,
            c.name AS customer_name,
            v.id AS vehicle_id,
            v.plate_number,
            v.make AS vehicle_make,
            v.model AS vehicle_model,
            v.year AS vehicle_year
        FROM vehicles v
        LEFT JOIN customers c ON c.id = v.customer_id
        LEFT JOIN branches b ON b.id = v.branch_id
        LEFT JOIN users archived_user ON archived_user.id = v.archived_by
        WHERE (v.archived_at IS NOT NULL OR v.status = 'archived')
          AND DATE(COALESCE(v.archived_at, v.updated_at, v.created_at)) BETWEEN ? AND ?" . $archive_branch_vehicle;
    $archive_params = array_merge($archive_params, [$date_from, $date_to]);
    if ($branch_filter !== '') {
        $archive_params[] = $branch_filter;
    }

    $archive_parts[] = "
        SELECT
            'service_operation' AS archive_type,
            q.id AS record_id,
            q.quotation_number AS record_label,
            q.status AS original_status,
            b.name AS branch_name,
            COALESCE(q.archived_at, q.updated_at, q.created_at) AS archived_at,
            archived_user.name AS archived_by_name,
            q.archive_reason,
            c.id AS customer_id,
            c.name AS customer_name,
            v.id AS vehicle_id,
            v.plate_number,
            v.make AS vehicle_make,
            v.model AS vehicle_model,
            v.year AS vehicle_year
        FROM quotations q
        LEFT JOIN customers c ON c.id = q.customer_id
        LEFT JOIN vehicles v ON v.id = q.vehicle_id
        LEFT JOIN branches b ON b.id = q.branch_id
        LEFT JOIN users archived_user ON archived_user.id = q.archived_by
        WHERE (q.archived_at IS NOT NULL OR q.status = 'archived')
          AND DATE(COALESCE(q.archived_at, q.updated_at, q.created_at)) BETWEEN ? AND ?" . $archive_branch_quotation;
    $archive_params = array_merge($archive_params, [$date_from, $date_to]);
    if ($branch_filter !== '') {
        $archive_params[] = $branch_filter;
    }

    $archive_parts[] = "
        SELECT
            'job_order' AS archive_type,
            jo.id AS record_id,
            jo.job_number AS record_label,
            jo.status AS original_status,
            b.name AS branch_name,
            COALESCE(jo.archived_at, jo.updated_at, jo.created_at) AS archived_at,
            archived_user.name AS archived_by_name,
            jo.archive_reason,
            c.id AS customer_id,
            c.name AS customer_name,
            v.id AS vehicle_id,
            v.plate_number,
            v.make AS vehicle_make,
            v.model AS vehicle_model,
            v.year AS vehicle_year
        FROM job_orders jo
        LEFT JOIN customers c ON c.id = jo.customer_id
        LEFT JOIN vehicles v ON v.id = jo.vehicle_id
        LEFT JOIN branches b ON b.id = jo.branch_id
        LEFT JOIN users archived_user ON archived_user.id = jo.archived_by
        WHERE (jo.archived_at IS NOT NULL OR jo.status = 'archived')
          AND DATE(COALESCE(jo.archived_at, jo.updated_at, jo.created_at)) BETWEEN ? AND ?" . $archive_branch_job;
    $archive_params = array_merge($archive_params, [$date_from, $date_to]);
    if ($branch_filter !== '') {
        $archive_params[] = $branch_filter;
    }

    $archive_parts[] = "
        SELECT
            'inventory_item' AS archive_type,
            i.id AS record_id,
            i.item_name AS record_label,
            i.status AS original_status,
            b.name AS branch_name,
            COALESCE(i.archived_at, i.updated_at, i.created_at) AS archived_at,
            archived_user.name AS archived_by_name,
            i.archive_reason,
            NULL AS customer_id,
            NULL AS customer_name,
            NULL AS vehicle_id,
            NULL AS plate_number,
            NULL AS vehicle_make,
            NULL AS vehicle_model,
            NULL AS vehicle_year
        FROM inventory_items i
        LEFT JOIN branches b ON b.id = i.branch_id
        LEFT JOIN users archived_user ON archived_user.id = i.archived_by
        WHERE (i.archived_at IS NOT NULL OR i.status = 'inactive')
          AND DATE(COALESCE(i.archived_at, i.updated_at, i.created_at)) BETWEEN ? AND ?" . $archive_branch_item;
    $archive_params = array_merge($archive_params, [$date_from, $date_to]);
    if ($branch_filter !== '') {
        $archive_params[] = $branch_filter;
    }

    $archive_filters = [];
    $archive_type_filter_map = [
        'customers' => 'customer',
        'vehicles' => 'vehicle',
        'service_operations' => 'service_operation',
        'job_orders' => 'job_order',
        'inventory_items' => 'inventory_item',
    ];
    if (isset($archive_type_filter_map[$status_filter])) {
        $archive_filters[] = 'archive_type = ?';
        $archive_params[] = $archive_type_filter_map[$status_filter];
    }
    if ($search_filter !== '') {
        foreach (app_search_terms($search_filter) as $term) {
            $archive_filters[] = "(
                record_label LIKE ?
                OR customer_name LIKE ?
                OR plate_number LIKE ?
                OR vehicle_make LIKE ?
                OR vehicle_model LIKE ?
                OR branch_name LIKE ?
                OR archived_by_name LIKE ?
                OR archive_reason LIKE ?
            )";
            $like = '%' . $term . '%';
            $archive_params = array_merge($archive_params, array_fill(0, 8, $like));
        }
    }
    $archive_filter_sql = !empty($archive_filters) ? ' AND ' . implode(' AND ', $archive_filters) : '';
    $archive_report_query = "
        FROM (" . implode(" UNION ALL ", $archive_parts) . ") archived_records
        WHERE 1 = 1" . $archive_filter_sql . "
    ";

    $archive_count_stmt = $pdo->prepare("SELECT COUNT(*) AS total " . $archive_report_query);
    $archive_count_stmt->execute($archive_params);
    $archives_total = (int) ($archive_count_stmt->fetch()['total'] ?? 0);
    $detail_page = min($detail_page, max(1, (int) ceil($archives_total / $detail_per_page)));

    $archive_limit = $is_export_request ? max(1, $archives_total) : $detail_per_page;
    $archive_offset = $is_export_request ? 0 : (($detail_page - 1) * $detail_per_page);
    $archive_stmt = $pdo->prepare("
        SELECT *
        " . $archive_report_query . "
        ORDER BY archived_at DESC, archive_type ASC, record_label ASC
        LIMIT $archive_limit OFFSET $archive_offset
    ");
    $archive_stmt->execute($archive_params);
    $archive_report = $archive_stmt->fetchAll();
}

if ($report_tab === 'services') {
    $detail_total_records = $services_total;
    $detail_records = $detailed_services;
} elseif ($report_tab === 'items') {
    $detail_total_records = $items_total;
    $detail_records = $inventory_tagged_stock_outs;
} elseif ($report_tab === 'vehicle_history') {
    $detail_total_records = $vehicle_history_total;
    $detail_records = $vehicle_history_report;
} elseif ($report_tab === 'stock_movement') {
    $detail_total_records = $stock_movement_total;
    $detail_records = $stock_movement_report;
} elseif ($report_tab === 'archives') {
    $detail_total_records = $archives_total;
    $detail_records = $archive_report;
} else {
    $detail_total_records = $vehicles_total;
    $detail_records = $customer_vehicle_report;
}

$detail_total_pages = max(1, (int) ceil($detail_total_records / $detail_per_page));
$detail_page = min($detail_page, $detail_total_pages);
$detail_showing_from = $detail_total_records > 0 ? (($detail_page - 1) * $detail_per_page) + 1 : 0;
$detail_showing_to = min($detail_showing_from + $detail_per_page - 1, $detail_total_records);

$report_tab_options = [
    'services' => ['label' => 'Services', 'icon' => 'fas fa-wrench', 'noun' => 'service records'],
    'items' => ['label' => 'Inventory Sales', 'icon' => 'fas fa-receipt', 'noun' => 'inventory sale records'],
    'vehicles' => ['label' => 'Customer & Vehicle', 'icon' => 'fas fa-car', 'noun' => 'vehicle records'],
    'vehicle_history' => ['label' => 'Vehicle History', 'icon' => 'fas fa-clock-rotate-left', 'noun' => 'vehicle history records'],
    'stock_movement' => ['label' => 'Stock Movement', 'icon' => 'fas fa-right-left', 'noun' => 'stock movement records'],
    'archives' => ['label' => 'Archived Records', 'icon' => 'fas fa-box-archive', 'noun' => 'archived records'],
];
$active_report = $report_tab_options[$report_tab];

$summary = [
    'revenue' => (float) ($quote_stats['approved_value'] ?? 0),
    'total_jobs' => (int) ($job_stats['total_jobs'] ?? 0),
    'completed_jobs' => (int) ($job_stats['completed'] ?? 0),
    'total_quotations' => (int) ($quote_stats['total_quotes'] ?? 0),
];

$active_status_label = $active_status_options[$status_filter] ?? 'All Records';
$report_range_label = format_date($date_from, 'M d, Y') . ' - ' . format_date($date_to, 'M d, Y');
$range_anchor = new DateTimeImmutable($default_to, new DateTimeZone(APP_TIMEZONE));
$this_month = $range_anchor;
$last_month = $range_anchor;
$this_year = $range_anchor;
$last_year = $range_anchor;
$last_month = $range_anchor->modify('first day of previous month');
$last_year = $range_anchor->modify('-1 year');
$quick_report_ranges = [
    [
        'label' => 'All History',
        'date_from' => $default_from,
        'date_to' => $default_to,
    ],
    [
        'label' => 'This Month',
        'date_from' => $this_month->modify('first day of this month')->format('Y-m-d'),
        'date_to' => $this_month->modify('last day of this month')->format('Y-m-d'),
    ],
    [
        'label' => 'Last Month',
        'date_from' => $last_month->format('Y-m-d'),
        'date_to' => $last_month->modify('last day of this month')->format('Y-m-d'),
    ],
    [
        'label' => 'This Year',
        'date_from' => $this_year->format('Y') . '-01-01',
        'date_to' => $this_year->format('Y') . '-12-31',
    ],
    [
        'label' => 'Last Year',
        'date_from' => $last_year->format('Y') . '-01-01',
        'date_to' => $last_year->format('Y') . '-12-31',
    ],
];
$quick_report_range_value = 'custom';
foreach ($quick_report_ranges as $range_key => $range) {
    if ($date_from === $range['date_from'] && $date_to === $range['date_to']) {
        $quick_report_range_value = (string) $range_key;
        break;
    }
}

if ($is_export_request) {
    reports_send_detail_csv(
        [
            'branch_label' => $branch_label,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'status_label' => $active_status_label,
            'search' => $search_filter,
        ],
        $report_tab,
        $active_report,
        $detail_records
    );
}

$pie_segments = [
    ['label' => 'Completed', 'value' => (int) ($job_stats['completed'] ?? 0), 'color' => '#64748b', 'class' => 'completed'],
    ['label' => 'In Progress', 'value' => (int) ($job_stats['in_progress'] ?? 0), 'color' => '#0f766e', 'class' => 'in-progress'],
    ['label' => 'Pending', 'value' => (int) ($job_stats['waiting'] ?? 0), 'color' => '#b7791f', 'class' => 'pending'],
];

$pie_total = array_sum(array_column($pie_segments, 'value'));
$pie_gradient_parts = [];
$pie_cursor = 0;

if ($pie_total > 0) {
    foreach ($pie_segments as $segment) {
        if ($segment['value'] <= 0) {
            continue;
        }

        $slice = ($segment['value'] / $pie_total) * 360;
        $pie_gradient_parts[] = $segment['color'] . ' ' . round($pie_cursor, 2) . 'deg ' . round($pie_cursor + $slice, 2) . 'deg';
        $pie_cursor += $slice;
    }
}

$pie_background = $pie_total > 0 ? 'conic-gradient(' . implode(', ', $pie_gradient_parts) . ')' : '#eef2f7';
// Calculate Average Job Order Turnaround Time (Elapsed time from created_at to actual_end_time for completed jobs)
$tat_params = [$date_from, $date_to];
$tat_branch_sql = '';
if ($branch_filter !== '') {
    $tat_branch_sql = ' AND jo.branch_id = ?';
    $tat_params[] = (int) $branch_filter;
}

$tat_overall_stmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS total_completed,
        AVG(TIMESTAMPDIFF(MINUTE, jo.created_at, jo.actual_end_time)) AS avg_tat_minutes
    FROM job_orders jo
    WHERE (jo.status = 'completed' OR jo.status = 'archived')
      AND jo.actual_end_time IS NOT NULL
      AND jo.actual_end_time >= jo.created_at
      AND DATE(COALESCE(jo.job_date, jo.created_at)) BETWEEN ? AND ?
      $tat_branch_sql
");
$tat_overall_stmt->execute($tat_params);
$tat_overall = $tat_overall_stmt->fetch(PDO::FETCH_ASSOC);
$overall_avg_tat_minutes = (float) ($tat_overall['avg_tat_minutes'] ?? 0);
$overall_completed_count = (int) ($tat_overall['total_completed'] ?? 0);

// Branch TAT Breakdown
$tat_branch_stmt = $pdo->prepare("
    SELECT 
        b.id,
        b.name,
        COUNT(jo.id) AS completed_count,
        AVG(TIMESTAMPDIFF(MINUTE, jo.created_at, jo.actual_end_time)) AS avg_tat_minutes
    FROM branches b
    LEFT JOIN job_orders jo ON jo.branch_id = b.id
        AND (jo.status = 'completed' OR jo.status = 'archived')
        AND jo.actual_end_time IS NOT NULL
        AND jo.actual_end_time >= jo.created_at
        AND DATE(COALESCE(jo.job_date, jo.created_at)) BETWEEN ? AND ?
    WHERE b.status = 'active'
    GROUP BY b.id, b.name
    ORDER BY b.id
");
$tat_branch_stmt->execute([$date_from, $date_to]);
$tat_branch_breakdown = $tat_branch_stmt->fetchAll(PDO::FETCH_ASSOC);

// Technician TAT Breakdown
$tat_tech_stmt = $pdo->prepare("
    SELECT 
        jo.id,
        jo.branch_id,
        b.name AS branch_name,
        jo.assigned_technician_id,
        jo.assigned_technician_name,
        TIMESTAMPDIFF(MINUTE, jo.created_at, jo.actual_end_time) AS tat_minutes
    FROM job_orders jo
    LEFT JOIN branches b ON b.id = jo.branch_id
    WHERE (jo.status = 'completed' OR jo.status = 'archived')
      AND jo.actual_end_time IS NOT NULL
      AND jo.actual_end_time >= jo.created_at
      AND DATE(COALESCE(jo.job_date, jo.created_at)) BETWEEN ? AND ?
      $tat_branch_sql
");
$tat_tech_stmt->execute($tat_params);
$tat_tech_rows = $tat_tech_stmt->fetchAll(PDO::FETCH_ASSOC);

// Map technicians by ID for fallback if name is empty
$tech_id_lookup = [];
$tech_id_stmt = $pdo->query("SELECT id, name FROM technicians");
if ($tech_id_stmt) {
    foreach ($tech_id_stmt->fetchAll(PDO::FETCH_ASSOC) as $t_row) {
        $tech_id_lookup[(int) $t_row['id']] = $t_row['name'];
    }
}

$tat_tech_map = [];
foreach ($tat_tech_rows as $row) {
    $tat_mins = (float) $row['tat_minutes'];
    $b_id = (int) $row['branch_id'];
    $b_name = $row['branch_name'] ?? ('Branch #' . $b_id);

    $matched_names = [];
    if (!empty($row['assigned_technician_name'])) {
        foreach (explode(',', $row['assigned_technician_name']) as $t_name) {
            $t_name = trim($t_name);
            if ($t_name !== '') {
                $matched_names[$t_name] = true;
            }
        }
    }

    if (empty($matched_names) && !empty($row['assigned_technician_id'])) {
        $t_id = (int) $row['assigned_technician_id'];
        if (!empty($tech_id_lookup[$t_id])) {
            $matched_names[$tech_id_lookup[$t_id]] = true;
        }
    }

    // Each unique technician on this Job Order gets counted exactly once
    foreach (array_keys($matched_names) as $tech_name) {
        $key = $tech_name . '|' . $b_id;
        if (!isset($tat_tech_map[$key])) {
            $tat_tech_map[$key] = [
                'name' => $tech_name,
                'branch_id' => $b_id,
                'branch_name' => $b_name,
                'completed_count' => 0,
                'total_tat_minutes' => 0,
                'avg_tat_minutes' => 0,
            ];
        }
        $tat_tech_map[$key]['completed_count']++;
        $tat_tech_map[$key]['total_tat_minutes'] += $tat_mins;
    }
}

foreach ($tat_tech_map as &$tech_item) {
    $tech_item['avg_tat_minutes'] = $tech_item['completed_count'] > 0
        ? ($tech_item['total_tat_minutes'] / $tech_item['completed_count'])
        : 0;
}
unset($tech_item);

$tat_technician_breakdown = array_values($tat_tech_map);
usort($tat_technician_breakdown, function ($a, $b) {
    if ($b['completed_count'] !== $a['completed_count']) {
        return $b['completed_count'] - $a['completed_count'];
    }
    return $a['avg_tat_minutes'] <=> $b['avg_tat_minutes'];
});

// Technician TAT Pagination
$tat_tech_page = max(1, (int) ($_GET['tat_tech_page'] ?? 1));
$tat_tech_per_page = (int) ($_GET['tat_tech_per_page'] ?? 10);
if (!in_array($tat_tech_per_page, [10, 20, 50], true)) {
    $tat_tech_per_page = 10;
}

$tat_tech_total_records = count($tat_technician_breakdown);
$tat_tech_total_pages = max(1, (int) ceil($tat_tech_total_records / $tat_tech_per_page));
$tat_tech_page = min($tat_tech_page, $tat_tech_total_pages);
$tat_tech_offset = ($tat_tech_page - 1) * $tat_tech_per_page;
$tat_tech_paged = array_slice($tat_technician_breakdown, $tat_tech_offset, $tat_tech_per_page);
$tat_tech_showing_from = $tat_tech_total_records > 0 ? $tat_tech_offset + 1 : 0;
$tat_tech_showing_to = min($tat_tech_offset + $tat_tech_per_page, $tat_tech_total_records);

if (!function_exists('reports_tat_tech_url')) {
    function reports_tat_tech_url($page, $per_page = null) {
        global $report_tab, $branch_filter, $date_from, $date_to, $status_filter, $search_filter, $detail_per_page, $detail_page, $tat_tech_per_page;
        $pp = $per_page !== null ? (int) $per_page : $tat_tech_per_page;
        $extra = [
            'per_page' => $detail_per_page,
            'page' => $detail_page,
            'tat_tech_page' => (int) $page,
            'tat_tech_per_page' => $pp,
        ];
        return reports_detail_url($report_tab, $branch_filter, $date_from, $date_to, $status_filter, $search_filter, $extra) . '#technician-tat-section';
    }
}

if (!function_exists('reports_format_tat_minutes')) {
    function reports_format_tat_minutes($minutes) {
        $mins = (int) round((float) $minutes);
        if ($mins <= 0) {
            return '0 mins';
        }
        $hours = (int) floor($mins / 60);
        $rem_mins = $mins % 60;
        if ($hours > 0) {
            return $hours . ' hr' . ($hours > 1 ? 's' : '') . ($rem_mins > 0 ? ' ' . $rem_mins . ' min' . ($rem_mins > 1 ? 's' : '') : '');
        }
        return $rem_mins . ' min' . ($rem_mins > 1 ? 's' : '');
    }
}
$export_url = reports_detail_url($report_tab, $branch_filter, $date_from, $date_to, $status_filter, $search_filter, [
    'per_page' => $detail_per_page,
    'export' => 'csv',
]);
$reset_url = reports_detail_url($report_tab, '', $default_from, $default_to, 'all', '');
?>

<?php require_once '../../includes/header.php'; ?>
<?php require_once '../../includes/sidebar.php'; ?>

<main class="reports-page">
    <header class="reports-hero">
        <div>
            <h1>Reports &amp; Analytics</h1>
            <p><?php echo esc_html($branch_label); ?> performance and activity records</p>
        </div>
        <div class="reports-hero-actions no-print">
            <a href="<?php echo esc_attr($export_url); ?>" class="reports-action-btn reports-export-btn">
                <i class="fas fa-download"></i>
                <span>Export CSV</span>
            </a>
            <button type="button" class="reports-action-btn reports-print-btn" onclick="window.print()">
                <i class="fas fa-print"></i>
                <span>Print Report</span>
            </button>
        </div>
    </header>

    <section class="reports-filter-card">
        <h2>Filters</h2>
        <form method="GET" class="reports-filter-form reports-detail-filter-form">
            <label class="reports-type-filter">
                <span>Report</span>
                <select name="report" onchange="this.form.submit()">
                    <?php foreach ($report_tab_options as $tab_value => $tab_option): ?>
                        <option value="<?php echo esc_attr($tab_value); ?>" <?php echo $report_tab === $tab_value ? 'selected' : ''; ?>>
                            <?php echo esc_html($tab_option['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="reports-branch-filter">
                <span>Branch</span>
                <select name="branch" onchange="this.form.submit()">
                    <option value="">All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <?php $option_label = reports_branch_label($branch['name']); ?>
                        <option value="<?php echo (int) $branch['id']; ?>" <?php echo $branch_filter === (int) $branch['id'] ? 'selected' : ''; ?>>
                            <?php echo esc_html($option_label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="reports-status-filter">
                <span><?php echo $report_tab === 'services' ? 'Report Status' : 'Status'; ?></span>
                <select name="status" onchange="this.form.submit()">
                    <?php foreach ($active_status_options as $status_value => $status_label): ?>
                        <option value="<?php echo esc_attr($status_value); ?>" <?php echo $status_filter === $status_value ? 'selected' : ''; ?>>
                            <?php echo esc_html($status_label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="reports-quick-range-filter no-print">
                <span>Quick Range</span>
                <select name="quick_range" class="reports-quick-range-select" onchange="reportsSyncQuickRange(this)">
                    <option value="custom" data-from="<?php echo esc_attr($date_from); ?>" data-to="<?php echo esc_attr($date_to); ?>" <?php echo $quick_report_range_value === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                    <?php foreach ($quick_report_ranges as $range_key => $range): ?>
                        <option value="<?php echo esc_attr((string) $range_key); ?>"
                                data-from="<?php echo esc_attr($range['date_from']); ?>"
                                data-to="<?php echo esc_attr($range['date_to']); ?>"
                                <?php echo $quick_report_range_value === (string) $range_key ? 'selected' : ''; ?>>
                            <?php echo esc_html($range['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="reports-date-from-filter" data-range-custom <?php echo $quick_report_range_value !== 'custom' ? 'hidden' : ''; ?>>
                <span>Date From</span>
                <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>">
            </label>
            <label class="reports-date-to-filter" data-range-custom <?php echo $quick_report_range_value !== 'custom' ? 'hidden' : ''; ?>>
                <span>Date To</span>
                <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>">
            </label>
            <button type="submit" class="reports-filter-apply">Apply</button>
            <label class="reports-search-filter">
                <span>Search</span>
                <input type="search" name="search" value="<?php echo esc_attr($search_filter); ?>" placeholder="Customer, plate, item, service, reference...">
            </label>
            <button type="submit" class="reports-search-submit">Search</button>
            <input type="hidden" name="per_page" value="<?php echo (int) $detail_per_page; ?>">
        </form>
        <script>
        function reportsSyncQuickRange(select) {
            const form = select.form;
            const option = select.options[select.selectedIndex];
            const isCustom = select.value === 'custom';
            const customLabels = form.querySelectorAll('[data-range-custom]');

            customLabels.forEach(function(el) {
                el.hidden = !isCustom;
            });

            if (isCustom) {
                const fromInput = form.querySelector('[name=date_from]');
                if (fromInput) {
                    fromInput.focus();
                }
            } else {
                if (option && option.dataset && option.dataset.from && option.dataset.to) {
                    const fromInput = form.querySelector('[name=date_from]');
                    const toInput = form.querySelector('[name=date_to]');
                    if (fromInput) fromInput.value = option.dataset.from;
                    if (toInput) toInput.value = option.dataset.to;
                    form.submit();
                }
            }
        }
        </script>
        <div class="reports-filter-summary">
            <span><strong>Report</strong> <?php echo esc_html($active_report['label']); ?></span>
            <span><strong>Branch</strong> <?php echo esc_html($branch_label); ?></span>
            <span><strong>Range</strong> <?php echo esc_html($report_range_label); ?></span>
            <span><strong>Status</strong> <?php echo esc_html($active_status_label); ?></span>
            <?php if ($search_filter !== ''): ?>
                <span><strong>Search</strong> <?php echo esc_html($search_filter); ?></span>
            <?php endif; ?>
        </div>
    </section>

    <section class="reports-detail-tabs" aria-label="Detailed report type">
        <?php foreach ($report_tab_options as $tab_value => $tab_option): ?>
            <a class="reports-detail-tab <?php echo $report_tab === $tab_value ? 'active' : ''; ?>"
               href="<?php echo esc_attr(reports_detail_url($tab_value, $branch_filter, $date_from, $date_to, 'all', $search_filter)); ?>#detailed-report">
                <i class="<?php echo esc_attr($tab_option['icon']); ?>"></i>
                <span><?php echo esc_html($tab_option['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </section>

    <section class="reports-summary-grid" aria-label="Report summary">
        <article class="reports-summary-card">
            <div>
                <span>Total Revenue</span>
                <strong><?php echo reports_money($summary['revenue']); ?></strong>
            </div>
            <span class="reports-summary-icon icon-green"><i class="fas fa-chart-column"></i></span>
        </article>
        <article class="reports-summary-card">
            <div>
                <span>Total Job Orders</span>
                <strong><?php echo (int) $summary['total_jobs']; ?></strong>
            </div>
            <span class="reports-summary-icon icon-cyan"><i class="far fa-file-lines"></i></span>
        </article>
        <article class="reports-summary-card">
            <div>
                <span>Completed Jobs</span>
                <strong><?php echo (int) $summary['completed_jobs']; ?></strong>
            </div>
            <span class="reports-summary-icon icon-purple"><i class="fas fa-chart-simple"></i></span>
        </article>
        <article class="reports-summary-card">
            <div>
                <span>Total Service Operations</span>
                <strong><?php echo (int) $summary['total_quotations']; ?></strong>
            </div>
            <span class="reports-summary-icon icon-orange"><i class="far fa-file-lines"></i></span>
        </article>
    </section>

    <section class="reports-panel reports-detail-panel reports-primary-detail-panel" id="detailed-report">
        <div class="reports-panel-title-row">
            <div>
                <h2><?php echo esc_html($active_report['label']); ?> Report</h2>
                <p>
                    Showing <?php echo (int) $detail_showing_from; ?>-<?php echo (int) $detail_showing_to; ?>
                    of <?php echo (int) $detail_total_records; ?> <?php echo esc_html($active_report['noun']); ?>
                </p>
            </div>
            <form class="records-page-size-form reports-page-size-form" method="get" action="./#detailed-report">
                <input type="hidden" name="report" value="<?php echo esc_attr($report_tab); ?>">
                <?php if ($branch_filter !== ''): ?>
                    <input type="hidden" name="branch" value="<?php echo (int) $branch_filter; ?>">
                <?php endif; ?>
                <input type="hidden" name="date_from" value="<?php echo esc_attr($date_from); ?>">
                <input type="hidden" name="date_to" value="<?php echo esc_attr($date_to); ?>">
                <?php if ($status_filter !== 'all'): ?>
                    <input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>">
                <?php endif; ?>
                <?php if ($search_filter !== ''): ?>
                    <input type="hidden" name="search" value="<?php echo esc_attr($search_filter); ?>">
                <?php endif; ?>
                <label>
                    <span>Rows per page</span>
                    <select name="per_page" onchange="this.form.submit()">
                        <?php foreach ($page_sizes as $size): ?>
                            <option value="<?php echo (int) $size; ?>" <?php echo $detail_per_page === $size ? 'selected' : ''; ?>>
                                <?php echo (int) $size; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>
        </div>
        <div class="reports-detail-table-wrap">
            <?php if ($report_tab === 'services'): ?>
                <table class="reports-detail-table reports-primary-detail-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Branch</th>
                            <th>Customer / Vehicle</th>
                            <th>Services Availed</th>
                            <th>Reference</th>
                            <th>Status</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($detail_records)): ?>
                            <tr>
                                <td colspan="7" class="reports-empty-state">No service records found for this filter.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($detail_records as $service): ?>
                                <?php
                                $status_class = reports_status_class($service['report_status'] ?? 'pending');
                                $vehicle_label = reports_vehicle_label($service);
                                $customer_url = reports_record_url('customer', $service['customer_id'] ?? 0);
                                $vehicle_url = reports_record_url('vehicle', $service['vehicle_id'] ?? 0);
                                $reference_links = [];
                                if (trim((string) ($service['job_number'] ?? '')) !== '') {
                                    $reference_links[] = reports_record_link(
                                        reports_record_url('job', $service['job_order_id'] ?? 0),
                                        $service['job_number'],
                                        'reports-reference-link'
                                    );
                                }
                                if (trim((string) ($service['quotation_number'] ?? '')) !== '') {
                                    $reference_links[] = reports_record_link(
                                        reports_record_url('quotation', $service['quotation_id'] ?? 0),
                                        $service['quotation_number'],
                                        'reports-reference-link'
                                    );
                                }
                                ?>
                                <tr>
                                    <td><?php echo esc_html(format_date($service['service_date'] ?? '', 'M d, Y')); ?></td>
                                    <td><?php echo esc_html(reports_branch_label($service['branch_name'] ?? '')); ?></td>
                                    <td>
                                        <strong><?php echo reports_record_link($customer_url, $service['customer_name'] ?? '-'); ?></strong>
                                        <small><?php echo reports_record_link($vehicle_url, $vehicle_label, 'reports-record-link reports-muted-link'); ?></small>
                                    </td>
                                    <td><?php echo esc_html($service['service_names'] ?? 'General service'); ?></td>
                                    <td>
                                        <?php if (!empty($reference_links)): ?>
                                            <span class="reports-reference-links"><?php echo implode(' ', $reference_links); ?></span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="reports-status-pill status-<?php echo esc_attr($status_class); ?>">
                                            <?php echo esc_html(reports_status_label($service['report_status'] ?? '')); ?>
                                        </span>
                                    </td>
                                    <td><strong><?php echo reports_money($service['total_amount'] ?? 0); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php elseif ($report_tab === 'items'): ?>
                <table class="reports-detail-table reports-primary-detail-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Branch</th>
                            <th>Item</th>
                            <th>Item Details</th>
                            <th>Qty / Sales Value</th>
                            <th>Customer</th>
                            <th>Vehicle</th>
                            <th>Reference</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($detail_records)): ?>
                            <tr>
                                <td colspan="8" class="reports-empty-state">No inventory sales found for this filter.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($detail_records as $movement): ?>
                                <?php
                                $vehicle_label = reports_inventory_vehicle_label($movement);
                                $movement_value = ((float) ($movement['unit_price'] ?? 0)) * abs((int) ($movement['quantity'] ?? 0));
                                $tagged_customer_name = trim((string) ($movement['tagged_customer_name'] ?? ''));
                                $is_customer_tagged = $tagged_customer_name !== '';
                                if (!$is_customer_tagged) {
                                    $tagged_customer_name = app_inventory_transaction_tag_empty_label($movement['reference_type'] ?? '', $movement['transaction_type'] ?? '');
                                }
                                $tagged_customer_url = reports_record_url('customer', $movement['tagged_customer_id'] ?? 0);
                                $tagged_vehicle_url = reports_record_url('vehicle', $movement['tagged_vehicle_id'] ?? 0);
                                $movement_reference_links = [];
                                if (trim((string) ($movement['tagged_job_number'] ?? '')) !== '') {
                                    $movement_reference_links[] = reports_record_link(
                                        reports_record_url('job', $movement['tagged_job_order_id'] ?? 0),
                                        $movement['tagged_job_number'],
                                        'reports-reference-link'
                                    );
                                }
                                if (trim((string) ($movement['tagged_quotation_number'] ?? '')) !== '') {
                                    $movement_reference_links[] = reports_record_link(
                                        reports_record_url('quotation', $movement['tagged_quotation_id'] ?? 0),
                                        $movement['tagged_quotation_number'],
                                        'reports-reference-link'
                                    );
                                }
                                ?>
                                <tr>
                                    <td><?php echo esc_html(format_date($movement['created_at'] ?? '', 'M d, Y')); ?></td>
                                    <td><?php echo esc_html(reports_branch_label($movement['branch_name'] ?? '')); ?></td>
                                    <td>
                                        <strong><?php echo esc_html(app_display_item_name($movement['item_name'] ?? '-', $movement['category'] ?? null)); ?></strong>
                                        <small><?php echo esc_html(reports_category_label($movement['category'] ?? '')); ?></small>
                                    </td>
                                    <td><?php echo esc_html(reports_product_detail_text($movement)); ?></td>
                                    <td>
                                        <strong><?php echo number_format(abs((int) ($movement['quantity'] ?? 0))); ?> pcs</strong>
                                        <small><?php echo reports_money($movement_value); ?></small>
                                    </td>
                                    <td>
                                        <strong><?php echo $is_customer_tagged ? reports_record_link($tagged_customer_url, $tagged_customer_name) : esc_html($tagged_customer_name); ?></strong>
                                        <?php if ($is_customer_tagged && !empty($movement['tagged_customer_phone'])): ?>
                                            <small><?php echo esc_html($movement['tagged_customer_phone']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $vehicle_label !== '' ? reports_record_link($tagged_vehicle_url, $vehicle_label, 'reports-record-link reports-muted-link') : '-'; ?></td>
                                    <td>
                                        <?php if (!empty($movement_reference_links)): ?>
                                            <span class="reports-reference-links"><?php echo implode(' ', $movement_reference_links); ?></span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php elseif ($report_tab === 'vehicle_history'): ?>
                <table class="reports-detail-table reports-primary-detail-table">
                    <thead>
                        <tr>
                            <th>Vehicle</th>
                            <th>Current Owner</th>
                            <th>Home Branch</th>
                            <th>Last Visited Branch</th>
                            <th>Ownership</th>
                            <th>Activity</th>
                            <th>Sales Value</th>
                            <th>Last Mileage</th>
                            <th>Last Visit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($detail_records)): ?>
                            <tr>
                                <td colspan="9" class="reports-empty-state">No vehicle history records found for this filter.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($detail_records as $record): ?>
                                <?php
                                $customer_url = reports_record_url('customer', $record['customer_id'] ?? 0);
                                $vehicle_url = reports_record_url('vehicle', $record['vehicle_id'] ?? 0);
                                $owner_count = max(1, (int) ($record['owner_count'] ?? 1));
                                $previous_owners = trim((string) ($record['previous_owner_names'] ?? ''));
                                ?>
                                <tr>
                                    <td><strong><?php echo reports_record_link($vehicle_url, reports_vehicle_label($record)); ?></strong></td>
                                    <td>
                                        <strong><?php echo reports_record_link($customer_url, $record['customer_name'] ?? '-'); ?></strong>
                                        <?php if (!empty($record['customer_phone'])): ?>
                                            <small><?php echo esc_html($record['customer_phone']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html(reports_branch_label($record['branch_name'] ?? '')); ?></td>
                                    <td><?php echo esc_html(reports_branch_label($record['last_visited_branch'] ?? '')); ?></td>
                                    <td>
                                        <strong><?php echo number_format($owner_count); ?> owner<?php echo $owner_count === 1 ? '' : 's'; ?></strong>
                                        <?php if ($previous_owners !== ''): ?>
                                            <small>Previous: <?php echo esc_html($previous_owners); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo number_format((int) ($record['service_count'] ?? 0)); ?> services</strong>
                                        <small><?php echo number_format((int) ($record['items_given_count'] ?? 0)); ?> inventory sales</small>
                                    </td>
                                    <td><strong><?php echo reports_money($record['sales_value'] ?? 0); ?></strong></td>
                                    <td><?php echo !empty($record['last_mileage']) ? esc_html(number_format((int) $record['last_mileage']) . ' km') : '-'; ?></td>
                                    <td><?php echo esc_html(reports_short_date($record['last_visit_date'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php elseif ($report_tab === 'stock_movement'): ?>
                <table class="reports-detail-table reports-primary-detail-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Branch</th>
                            <th>Type</th>
                            <th>Item</th>
                            <th>Item Details</th>
                            <th>Qty</th>
                            <th>Tagged To</th>
                            <th>Reference</th>
                            <th>Entered By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($detail_records)): ?>
                            <tr>
                                <td colspan="9" class="reports-empty-state">No stock movement records found for this filter.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($detail_records as $movement): ?>
                                <?php
                                $vehicle_label = reports_inventory_vehicle_label($movement);
                                $tagged_customer_name = trim((string) ($movement['tagged_customer_name'] ?? ''));
                                $is_customer_tagged = $tagged_customer_name !== '';
                                if (!$is_customer_tagged) {
                                    $tagged_customer_name = app_inventory_transaction_tag_empty_label($movement['reference_type'] ?? '', $movement['transaction_type'] ?? '');
                                }
                                $tagged_customer_url = reports_record_url('customer', $movement['tagged_customer_id'] ?? 0);
                                $tagged_vehicle_url = reports_record_url('vehicle', $movement['tagged_vehicle_id'] ?? 0);
                                $movement_reference_links = [];
                                if (trim((string) ($movement['tagged_job_number'] ?? '')) !== '') {
                                    $movement_reference_links[] = reports_record_link(
                                        reports_record_url('job', $movement['resolved_job_order_id'] ?? $movement['tagged_job_order_id'] ?? 0),
                                        $movement['tagged_job_number'],
                                        'reports-reference-link'
                                    );
                                }
                                if (trim((string) ($movement['tagged_quotation_number'] ?? '')) !== '') {
                                    $movement_reference_links[] = reports_record_link(
                                        reports_record_url('quotation', $movement['resolved_quotation_id'] ?? $movement['tagged_quotation_id'] ?? 0),
                                        $movement['tagged_quotation_number'],
                                        'reports-reference-link'
                                    );
                                }
                                ?>
                                <tr>
                                    <td><?php echo esc_html(format_date($movement['created_at'] ?? '', 'M d, Y')); ?></td>
                                    <td><?php echo esc_html(reports_branch_label($movement['branch_name'] ?? '')); ?></td>
                                    <td><span class="reports-count-pill"><?php echo esc_html(reports_movement_type_label($movement['transaction_type'] ?? '')); ?></span></td>
                                    <td>
                                        <strong><?php echo esc_html(app_display_item_name($movement['item_name'] ?? '-', $movement['category'] ?? null)); ?></strong>
                                        <small><?php echo esc_html(reports_category_label($movement['category'] ?? '')); ?></small>
                                    </td>
                                    <td><?php echo esc_html(reports_product_detail_text($movement)); ?></td>
                                    <td><strong><?php echo number_format(abs((int) ($movement['quantity'] ?? 0))); ?> pcs</strong></td>
                                    <td>
                                        <strong><?php echo $is_customer_tagged ? reports_record_link($tagged_customer_url, $tagged_customer_name) : esc_html($tagged_customer_name); ?></strong>
                                        <?php if ($vehicle_label !== ''): ?>
                                            <small><?php echo reports_record_link($tagged_vehicle_url, $vehicle_label, 'reports-record-link reports-muted-link'); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($movement_reference_links)): ?>
                                            <span class="reports-reference-links"><?php echo implode(' ', $movement_reference_links); ?></span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($movement['entered_by_name'] ?? 'System'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php elseif ($report_tab === 'archives'): ?>
                <table class="reports-detail-table reports-primary-detail-table">
                    <thead>
                        <tr>
                            <th>Archived Date</th>
                            <th>Branch</th>
                            <th>Record Type</th>
                            <th>Record</th>
                            <th>Customer / Owner</th>
                            <th>Vehicle</th>
                            <th>Archived By</th>
                            <th>Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($detail_records)): ?>
                            <tr>
                                <td colspan="8" class="reports-empty-state">No archived records found for this filter.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($detail_records as $record): ?>
                                <?php
                                $archive_url = reports_archive_record_url($record);
                                $customer_url = reports_record_url('customer', $record['customer_id'] ?? 0);
                                $vehicle_url = reports_record_url('vehicle', $record['vehicle_id'] ?? 0);
                                $vehicle_label = reports_vehicle_label($record);
                                ?>
                                <tr>
                                    <td><?php echo esc_html(app_format_datetime_pht($record['archived_at'] ?? '', 'M d, Y')); ?></td>
                                    <td><?php echo esc_html(reports_branch_label($record['branch_name'] ?? '')); ?></td>
                                    <td><span class="reports-count-pill"><?php echo esc_html(reports_archive_type_label($record['archive_type'] ?? '')); ?></span></td>
                                    <td>
                                        <strong><?php echo reports_record_link($archive_url, $record['record_label'] ?? '-'); ?></strong>
                                        <small><?php echo esc_html(reports_status_label($record['original_status'] ?? '')); ?></small>
                                    </td>
                                    <td><?php echo !empty($record['customer_name']) ? reports_record_link($customer_url, $record['customer_name']) : '-'; ?></td>
                                    <td><?php echo $vehicle_label !== '-' ? reports_record_link($vehicle_url, $vehicle_label, 'reports-record-link reports-muted-link') : '-'; ?></td>
                                    <td><?php echo esc_html($record['archived_by_name'] ?? '-'); ?></td>
                                    <td><?php echo esc_html(trim((string) ($record['archive_reason'] ?? '')) !== '' ? $record['archive_reason'] : '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <table class="reports-detail-table reports-primary-detail-table">
                    <thead>
                        <tr>
                            <th>Vehicle</th>
                            <th>Current Owner</th>
                            <th>Branch</th>
                            <th>Last Visited Branch</th>
                            <th>Services</th>
                            <th>Inventory Sales</th>
                            <th>Sales Value</th>
                            <th>Last Visit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($detail_records)): ?>
                            <tr>
                                <td colspan="8" class="reports-empty-state">No customer and vehicle records found for this filter.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($detail_records as $record): ?>
                                <?php
                                $customer_url = reports_record_url('customer', $record['customer_id'] ?? 0);
                                $vehicle_url = reports_record_url('vehicle', $record['vehicle_id'] ?? 0);
                                ?>
                                <tr>
                                    <td><strong><?php echo reports_record_link($vehicle_url, reports_vehicle_label($record)); ?></strong></td>
                                    <td>
                                        <strong><?php echo reports_record_link($customer_url, $record['customer_name'] ?? '-'); ?></strong>
                                        <?php if (!empty($record['customer_phone'])): ?>
                                            <small><?php echo esc_html($record['customer_phone']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html(reports_branch_label($record['branch_name'] ?? '')); ?></td>
                                    <td><?php echo esc_html(reports_branch_label($record['last_visited_branch'] ?? '')); ?></td>
                                    <td><span class="reports-count-pill"><?php echo number_format((int) ($record['service_count'] ?? 0)); ?></span></td>
                                    <td><span class="reports-count-pill"><?php echo number_format((int) ($record['items_given_count'] ?? 0)); ?></span></td>
                                    <td><strong><?php echo reports_money($record['sales_value'] ?? 0); ?></strong></td>
                                    <td><?php echo esc_html(reports_short_date($record['last_visit_date'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php if ($detail_total_pages > 1): ?>
            <nav class="records-pagination reports-detail-pagination" aria-label="Detailed report pages">
                <ul class="pagination justify-content-center">
                    <?php if ($detail_page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo esc_attr(reports_detail_url($report_tab, $branch_filter, $date_from, $date_to, $status_filter, $search_filter, ['per_page' => $detail_per_page, 'page' => 1])); ?>#detailed-report">First</a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo esc_attr(reports_detail_url($report_tab, $branch_filter, $date_from, $date_to, $status_filter, $search_filter, ['per_page' => $detail_per_page, 'page' => $detail_page - 1])); ?>#detailed-report">Previous</a>
                        </li>
                    <?php endif; ?>
                    <?php for ($i = max(1, $detail_page - 2); $i <= min($detail_total_pages, $detail_page + 2); $i++): ?>
                        <li class="page-item <?php echo $i === $detail_page ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo esc_attr(reports_detail_url($report_tab, $branch_filter, $date_from, $date_to, $status_filter, $search_filter, ['per_page' => $detail_per_page, 'page' => $i])); ?>#detailed-report"><?php echo (int) $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    <?php if ($detail_page < $detail_total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo esc_attr(reports_detail_url($report_tab, $branch_filter, $date_from, $date_to, $status_filter, $search_filter, ['per_page' => $detail_per_page, 'page' => $detail_page + 1])); ?>#detailed-report">Next</a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo esc_attr(reports_detail_url($report_tab, $branch_filter, $date_from, $date_to, $status_filter, $search_filter, ['per_page' => $detail_per_page, 'page' => $detail_total_pages])); ?>#detailed-report">Last</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </section>

    <?php if ($report_tab === 'services'): ?>
        <details class="reports-support-details reports-service-analytics" id="performance-analytics">
            <summary class="reports-support-summary">
                <span class="reports-support-title"><i class="fas fa-chart-line"></i><strong>Performance Analytics</strong></span>
                <span class="reports-support-count">4 analytics views</span>
            </summary>
            <div class="reports-support-body">
    <section class="reports-chart-grid">
        <article class="reports-panel reports-performance-panel">
            <h2>Branch Performance</h2>
            <div class="reports-chart-details">
                <span>Scale max: <?php echo number_format((int) $max_branch_count); ?></span>
                <span>Shows job orders vs. service operations</span>
            </div>
            <div class="reports-combo-chart">
                <div class="reports-y-axis">
                    <span><?php echo (int) $max_branch_count; ?></span>
                    <span><?php echo number_format($max_branch_count * 0.75, 2); ?></span>
                    <span><?php echo number_format($max_branch_count * 0.5, 2); ?></span>
                    <span><?php echo number_format($max_branch_count * 0.25, 2); ?></span>
                    <span>0</span>
                </div>
                <div class="reports-plot">
                    <div class="reports-plot-grid"></div>
                    <div class="reports-bar-groups">
                        <?php foreach ($branch_performance as $branch): ?>
                            <div class="reports-bar-group">
                                <div class="reports-bars">
                                    <span class="bar-jobs" style="height: <?php echo reports_percent($branch['job_orders'], $max_branch_count); ?>%">
                                        <b><?php echo number_format((int) $branch['job_orders']); ?></b>
                                    </span>
                                    <span class="bar-quotes" style="height: <?php echo reports_percent($branch['quotations'], $max_branch_count); ?>%">
                                        <b><?php echo number_format((int) $branch['quotations']); ?></b>
                                    </span>
                                </div>
                                <p><?php echo esc_html(reports_branch_label($branch['name'])); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="reports-chart-legend">
                <span><i class="legend-jobs"></i> Job Orders</span>
                <span><i class="legend-quotes"></i> Service Operations</span>
            </div>
        </article>

        <article class="reports-panel reports-status-panel">
            <h2>Job Order Status Distribution</h2>
            <div class="reports-pie-area">
                <div class="reports-pie" style="background: <?php echo esc_attr($pie_background); ?>"></div>
                <div class="reports-pie-labels">
                    <?php foreach ($pie_segments as $segment): ?>
                        <?php if ($segment['value'] <= 0 && $pie_total > 0) continue; ?>
                        <span class="pie-label-<?php echo esc_attr($segment['class']); ?>">
                            <?php echo esc_html($segment['label']); ?>: <?php echo (int) $segment['value']; ?>
                        </span>
                    <?php endforeach; ?>
                    <?php if ($pie_total === 0): ?>
                        <span class="pie-label-empty">No job orders</span>
                    <?php endif; ?>
                </div>
            </div>
        </article>
    </section>

    <section class="reports-panel reports-revenue-panel">
        <div class="reports-panel-title-row">
            <div>
                <h2>Revenue by Branch</h2>
                <p>Amounts are shown in thousands of pesos for the selected date range.</p>
            </div>
        </div>
        <div class="reports-revenue-chart">
            <div class="reports-y-axis">
                <?php foreach ($revenue_axis_labels as $label): ?>
                    <span><?php echo esc_html($label); ?></span>
                <?php endforeach; ?>
            </div>
            <div class="reports-plot">
                <div class="reports-plot-grid"></div>
                <div class="reports-revenue-bars">
                    <?php foreach ($branch_performance as $index => $branch): ?>
                        <?php
                        $branch_color = reports_branch_chart_color($index);
                        $revenue_thousands = (float) $branch['revenue'] / 1000;
                        $bar_height = reports_percent($branch['revenue'], $revenue_axis_max);
                        ?>
                        <div class="reports-revenue-group">
                            <span style="height: <?php echo $bar_height; ?>%; background: <?php echo esc_attr($branch_color); ?>;"
                                  title="<?php echo esc_attr(reports_branch_label($branch['name']) . ': PHP ' . number_format((float) $branch['revenue'], 2)); ?>">
                                <b><?php echo esc_html(number_format($revenue_thousands, 0)); ?></b>
                            </span>
                            <p><?php echo esc_html(reports_branch_label($branch['name'])); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="reports-chart-legend">
            <?php foreach ($branch_performance as $index => $branch): ?>
                <span>
                    <i style="background: <?php echo esc_attr(reports_branch_chart_color($index)); ?>"></i>
                    <?php echo esc_html(reports_branch_label($branch['name'])); ?>
                </span>
            <?php endforeach; ?>
            <span class="reports-axis-note">Y-axis: revenue in thousands of pesos</span>
        </div>
    </section>

    <section class="reports-panel reports-tat-panel" style="margin-top: 18px;">
        <div class="reports-panel-title-row">
            <div>
                <h2>Job Order Turnaround Time (Creation to Completion)</h2>
                <p>Elapsed time from Job Order creation until Job Order completion (completed and archived jobs only).</p>
            </div>
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 14px; margin-top: 14px;">
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; display: flex; align-items: center; gap: 14px;">
                <div style="width: 44px; height: 44px; border-radius: 8px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0;">
                    <i class="fas fa-stopwatch"></i>
                </div>
                <div>
                    <span style="font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b; letter-spacing: 0.5px;">Overall Average TAT</span>
                    <strong style="display: block; font-size: 18px; font-weight: 700; color: #0f172a; margin-top: 2px;">
                        <?php echo esc_html(reports_format_tat_minutes($overall_avg_tat_minutes)); ?>
                    </strong>
                    <small style="color: #64748b; font-size: 11.5px;"><?php echo number_format($overall_completed_count); ?> completed job orders</small>
                </div>
            </div>
            <?php foreach ($tat_branch_breakdown as $b_tat): ?>
                <?php
                $b_avg = (float) ($b_tat['avg_tat_minutes'] ?? 0);
                $b_cnt = (int) ($b_tat['completed_count'] ?? 0);
                ?>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; display: flex; align-items: center; gap: 14px;">
                    <div style="width: 44px; height: 44px; border-radius: 8px; background: #f1f5f9; color: #475569; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0;">
                        <i class="fas fa-building"></i>
                    </div>
                    <div>
                        <span style="font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b; letter-spacing: 0.5px;"><?php echo esc_html(reports_branch_label($b_tat['name'])); ?></span>
                        <strong style="display: block; font-size: 17px; font-weight: 700; color: #0f172a; margin-top: 2px;">
                            <?php echo esc_html(reports_format_tat_minutes($b_avg)); ?>
                        </strong>
                        <small style="color: #64748b; font-size: 11.5px;"><?php echo number_format($b_cnt); ?> completed jobs</small>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div style="margin-top: 20px;" id="technician-tat-section">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 12px;">
                <div>
                    <h3 style="font-size: 14px; font-weight: 700; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-users-gear text-secondary"></i> Technician Turnaround Time Performance
                    </h3>
                    <?php if ($tat_tech_total_records > 0): ?>
                        <small style="color: #64748b; font-size: 12px; margin-top: 2px; display: block;">
                            Showing <?php echo number_format($tat_tech_showing_from); ?>–<?php echo number_format($tat_tech_showing_to); ?> of <?php echo number_format($tat_tech_total_records); ?> technicians
                        </small>
                    <?php endif; ?>
                </div>
                <?php if ($tat_tech_total_records > 10): ?>
                    <div style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: #64748b;">
                        <span>Rows per page:</span>
                        <?php foreach ([10, 20, 50] as $r_opt): ?>
                            <?php if ($tat_tech_per_page === $r_opt): ?>
                                <strong style="padding: 2px 8px; background: #0284c7; color: #fff; border-radius: 4px;"><?php echo $r_opt; ?></strong>
                            <?php else: ?>
                                <a href="<?php echo esc_attr(reports_tat_tech_url(1, $r_opt)); ?>" style="padding: 2px 8px; background: #f1f5f9; color: #334155; border-radius: 4px; text-decoration: none; border: 1px solid #e2e8f0;"><?php echo $r_opt; ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (empty($tat_tech_paged)): ?>
                <div style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; padding: 14px; text-align: center; color: #64748b; font-size: 13px;">
                    No technician turnaround records found for the selected filters.
                </div>
            <?php else: ?>
                <div style="border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; background: #fff;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                        <thead>
                            <tr style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; color: #475569; text-align: left;">
                                <th style="padding: 10px 14px; font-weight: 600;">Technician</th>
                                <th style="padding: 10px 14px; font-weight: 600;">Branch</th>
                                <th style="padding: 10px 14px; font-weight: 600; text-align: center;">Completed Jobs</th>
                                <th style="padding: 10px 14px; font-weight: 600; text-align: right;">Average Turnaround Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tat_tech_paged as $t_idx => $tech): ?>
                                <tr style="border-bottom: 1px solid #f1f5f9; <?php echo $t_idx % 2 === 1 ? 'background: #fafbfc;' : ''; ?>">
                                    <td style="padding: 10px 14px; font-weight: 600; color: #0f172a;">
                                        <i class="fas fa-user-gear text-muted me-2" style="font-size: 11px;"></i><?php echo esc_html($tech['name']); ?>
                                    </td>
                                    <td style="padding: 10px 14px; color: #475569;">
                                        <?php echo esc_html(reports_branch_label($tech['branch_name'])); ?>
                                    </td>
                                    <td style="padding: 10px 14px; text-align: center;">
                                        <span style="display: inline-block; padding: 2px 8px; border-radius: 12px; background: #f1f5f9; color: #334155; font-size: 12px; font-weight: 600; font-family: monospace;">
                                            <?php echo number_format((int) $tech['completed_count']); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 10px 14px; text-align: right; font-weight: 700; color: #0f172a;">
                                        <?php echo esc_html(reports_format_tat_minutes($tech['avg_tat_minutes'])); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($tat_tech_total_pages > 1): ?>
                    <nav class="records-pagination mt-3" aria-label="Technician Turnaround Time pages">
                        <ul class="pagination justify-content-center mb-0" style="gap: 4px;">
                            <?php if ($tat_tech_page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?php echo esc_attr(reports_tat_tech_url($tat_tech_page - 1)); ?>">Previous</a>
                                </li>
                            <?php else: ?>
                                <li class="page-item disabled">
                                    <span class="page-link">Previous</span>
                                </li>
                            <?php endif; ?>

                            <?php for ($p = 1; $p <= $tat_tech_total_pages; $p++): ?>
                                <li class="page-item <?php echo $p === $tat_tech_page ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo esc_attr(reports_tat_tech_url($p)); ?>"><?php echo $p; ?></a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($tat_tech_page < $tat_tech_total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?php echo esc_attr(reports_tat_tech_url($tat_tech_page + 1)); ?>">Next</a>
                                </li>
                            <?php else: ?>
                                <li class="page-item disabled">
                                    <span class="page-link">Next</span>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
            </div>
        </details>
    <?php endif; ?>

    <?php if ($report_tab === 'items'): ?>
        <details class="reports-support-details reports-inventory-analytics" id="inventory-analytics">
            <summary class="reports-support-summary">
                <span class="reports-support-title"><i class="fas fa-boxes-stacked"></i><strong>Inventory Movement Analytics</strong></span>
                <span class="reports-support-count"><?php echo number_format(count($inventory_tagged_stock_outs)); ?> stock-out rows</span>
            </summary>
            <div class="reports-support-body reports-inventory-support-body">
    <section class="reports-inventory-summary-grid" aria-label="Inventory movement summary">
        <article class="reports-summary-card">
            <div>
                <span>Stock In</span>
                <strong><?php echo number_format((int) $inventory_summary['stock_in_units']); ?></strong>
            </div>
            <span class="reports-summary-icon icon-green"><i class="fas fa-arrow-down"></i></span>
        </article>
        <article class="reports-summary-card">
            <div>
                <span>Stock Out</span>
                <strong><?php echo number_format((int) $inventory_summary['stock_out_units']); ?></strong>
            </div>
            <span class="reports-summary-icon icon-orange"><i class="fas fa-arrow-up"></i></span>
        </article>
        <article class="reports-summary-card">
            <div>
                <span>Net Movement</span>
                <strong><?php echo number_format((int) $inventory_summary['net_units']); ?></strong>
            </div>
            <span class="reports-summary-icon icon-cyan"><i class="fas fa-right-left"></i></span>
        </article>
        <article class="reports-summary-card">
            <div>
                <span>Stock Out Value</span>
                <strong><?php echo reports_money($inventory_summary['stock_out_value']); ?></strong>
            </div>
            <span class="reports-summary-icon icon-purple"><i class="fas fa-boxes-stacked"></i></span>
        </article>
    </section>

    <section class="reports-chart-grid">
        <article class="reports-panel reports-inventory-panel">
            <h2>Inventory Movement by Category</h2>
            <div class="reports-chart-details">
                <span>Scale: units moved</span>
                <span>Highest bar: <?php echo number_format((int) $max_inventory_category_units); ?> units</span>
                <span>Stock In = units added</span>
                <span>Stock Out = units used, removed, or transferred out</span>
            </div>
            <div class="reports-combo-chart reports-inventory-chart">
                <div class="reports-y-axis">
                    <span><?php echo (int) $max_inventory_category_units; ?></span>
                    <span><?php echo number_format($max_inventory_category_units * 0.75, 2); ?></span>
                    <span><?php echo number_format($max_inventory_category_units * 0.5, 2); ?></span>
                    <span><?php echo number_format($max_inventory_category_units * 0.25, 2); ?></span>
                    <span>0</span>
                </div>
                <div class="reports-plot">
                    <div class="reports-plot-grid"></div>
                    <div class="reports-bar-groups">
                        <?php foreach ($inventory_category_movement as $category): ?>
                            <div class="reports-bar-group">
                                <div class="reports-bars">
                                    <span class="bar-stock-in" title="<?php echo esc_attr($category['label'] . ' stock in: ' . number_format((int) $category['stock_in_units']) . ' units'); ?>" style="height: <?php echo reports_percent($category['stock_in_units'], $max_inventory_category_units); ?>%">
                                        <b><?php echo number_format((int) $category['stock_in_units']); ?></b>
                                    </span>
                                    <span class="bar-stock-out" title="<?php echo esc_attr($category['label'] . ' stock out: ' . number_format((int) $category['stock_out_units']) . ' units'); ?>" style="height: <?php echo reports_percent($category['stock_out_units'], $max_inventory_category_units); ?>%">
                                        <b><?php echo number_format((int) $category['stock_out_units']); ?></b>
                                    </span>
                                </div>
                                <p><?php echo esc_html($category['label']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="reports-chart-legend">
                <span><i class="legend-stock-in"></i> Stock In: added to inventory</span>
                <span><i class="legend-stock-out"></i> Stock Out: used, removed, or transferred out</span>
            </div>
            <div class="inventory-category-breakdown compact">
                <?php foreach ($inventory_category_movement as $category): ?>
                    <div class="inventory-category-stat">
                        <strong><?php echo esc_html($category['label']); ?></strong>
                        <span>In <?php echo number_format((int) $category['stock_in_units']); ?> / Out <?php echo number_format((int) $category['stock_out_units']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="reports-panel">
            <h2>Top Inventory Movement</h2>
            <div class="reports-inventory-list">
                <?php if (empty($inventory_top_items)): ?>
                    <div class="reports-empty-state">No inventory movements found for this filter.</div>
                <?php else: ?>
                    <?php foreach ($inventory_top_items as $item): ?>
                        <div class="reports-inventory-item">
                            <div>
                                <h3><?php echo esc_html(app_display_item_name($item['item_name'], $item['category'] ?? null)); ?></h3>
                                <p>
                                    <?php echo esc_html(reports_branch_label($item['branch_name'] ?? '')); ?>
                                    <?php if (!empty($item['brand'])): ?>
                                        &bull; <?php echo esc_html($item['brand']); ?>
                                    <?php endif; ?>
                                    <?php if (!empty($item['size'])): ?>
                                        &bull; <?php echo esc_html($item['size']); ?>
                                    <?php endif; ?>
                                </p>
                                <span class="reports-category category-<?php echo esc_attr($item['category']); ?>">
                                    <?php echo esc_html(reports_category_label($item['category'])); ?>
                                </span>
                            </div>
                            <div class="reports-inventory-counts">
                                <span class="movement-in">In <?php echo number_format((int) $item['stock_in_units']); ?></span>
                                <span class="movement-out">Out <?php echo number_format((int) $item['stock_out_units']); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>
    </section>

    <section class="reports-panel reports-detail-panel">
        <div class="reports-panel-title-row">
            <h2>Inventory Sales Detail</h2>
            <span>Customer tagged sales</span>
        </div>
        <div class="reports-detail-table-wrap">
            <table class="reports-detail-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Branch</th>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Customer</th>
                        <th>Vehicle</th>
                        <th>Reference</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($inventory_tagged_stock_outs)): ?>
                        <tr>
                            <td colspan="7" class="reports-empty-state">No inventory sales found for this filter.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($inventory_tagged_stock_outs as $movement): ?>
                            <?php
                            $vehicle_label = reports_inventory_vehicle_label($movement);
                            $item_meta = trim((string) ($movement['brand'] ?? '') . (!empty($movement['size']) ? ' (' . $movement['size'] . ')' : ''));
                            $tagged_customer_name = trim((string) ($movement['tagged_customer_name'] ?? ''));
                            $is_customer_tagged = $tagged_customer_name !== '';
                            if ($tagged_customer_name === '') {
                                $tagged_customer_name = app_inventory_transaction_tag_empty_label($movement['reference_type'] ?? '', $movement['transaction_type'] ?? '');
                            }
                            $tagged_customer_url = reports_record_url('customer', $movement['tagged_customer_id'] ?? 0);
                            $tagged_vehicle_url = reports_record_url('vehicle', $movement['tagged_vehicle_id'] ?? 0);
                            $movement_reference_links = [];
                            if (trim((string) ($movement['tagged_job_number'] ?? '')) !== '') {
                                $movement_reference_links[] = reports_record_link(
                                    reports_record_url('job', $movement['tagged_job_order_id'] ?? 0),
                                    $movement['tagged_job_number'],
                                    'reports-reference-link'
                                );
                            }
                            if (trim((string) ($movement['tagged_quotation_number'] ?? '')) !== '') {
                                $movement_reference_links[] = reports_record_link(
                                    reports_record_url('quotation', $movement['tagged_quotation_id'] ?? 0),
                                    $movement['tagged_quotation_number'],
                                    'reports-reference-link'
                                );
                            }
                            ?>
                            <tr>
                                <td><?php echo esc_html(format_date($movement['created_at'] ?? '', 'M d, Y')); ?></td>
                                <td><?php echo esc_html(reports_branch_label($movement['branch_name'] ?? '')); ?></td>
                                <td>
                                    <strong><?php echo esc_html(app_display_item_name($movement['item_name'] ?? '-', $movement['category'] ?? null)); ?></strong>
                                    <?php if ($item_meta !== ''): ?>
                                        <small><?php echo esc_html($item_meta); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo number_format(abs((int) ($movement['quantity'] ?? 0))); ?></td>
                                <td><?php echo $is_customer_tagged ? reports_record_link($tagged_customer_url, $tagged_customer_name) : esc_html($tagged_customer_name); ?></td>
                                <td><?php echo $vehicle_label !== '' ? reports_record_link($tagged_vehicle_url, $vehicle_label, 'reports-record-link reports-muted-link') : '-'; ?></td>
                                <td>
                                    <?php if (!empty($movement_reference_links)): ?>
                                        <span class="reports-reference-links"><?php echo implode(' ', $movement_reference_links); ?></span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
            </div>
        </details>

    <?php endif; ?>

    <?php if ($report_tab === 'services'): ?>
        <?php $admin_recent_count = count($recent_quotations) + count($recent_jobs); ?>
        <details class="reports-support-details reports-recent-activity" id="recent-activity">
            <summary class="reports-support-summary">
                <span class="reports-support-title"><i class="fas fa-clock-rotate-left"></i><strong>Recent Activity</strong></span>
                <span class="reports-support-count"><?php echo number_format($admin_recent_count); ?> records</span>
            </summary>
            <div class="reports-support-body">
    <section class="reports-recent-grid">
        <article class="reports-panel">
            <h2>Recent Service Operations</h2>
            <div class="reports-recent-list">
                <?php if (empty($recent_quotations)): ?>
                    <div class="reports-empty-state">No service operations found for this filter.</div>
                <?php else: ?>
                    <?php foreach ($recent_quotations as $quotation): ?>
                        <?php $status_class = reports_status_class($quotation['status'] ?? 'pending'); ?>
                        <div class="reports-recent-card">
                            <div>
                                <h3><?php echo esc_html($quotation['customer_name'] ?? '-'); ?></h3>
                                <p><?php echo esc_html(reports_branch_label($quotation['branch_name'] ?? '')); ?></p>
                            </div>
                            <div>
                                <span class="reports-status-pill status-<?php echo esc_attr($status_class); ?>">
                                    <?php echo esc_html(reports_status_label($quotation['status'] ?? '')); ?>
                                </span>
                                <strong><?php echo reports_money($quotation['total_amount'] ?? 0); ?></strong>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>

        <article class="reports-panel">
            <h2>Recent Job Orders</h2>
            <div class="reports-recent-list">
                <?php if (empty($recent_jobs)): ?>
                    <div class="reports-empty-state">No job orders found for this filter.</div>
                <?php else: ?>
                    <?php foreach ($recent_jobs as $job): ?>
                        <?php $status_class = reports_status_class($job['status'] ?? 'waiting'); ?>
                        <div class="reports-recent-card">
                            <div>
                                <h3><?php echo esc_html($job['customer_name'] ?? '-'); ?></h3>
                                <p><?php echo esc_html(reports_branch_label($job['branch_name'] ?? '')); ?></p>
                            </div>
                            <div>
                                <span class="reports-status-pill status-<?php echo esc_attr($status_class); ?>">
                                    <?php echo esc_html(reports_status_label($job['status'] ?? '')); ?>
                                </span>
                                <strong><?php echo esc_html(reports_short_date($job['job_date'] ?? '')); ?></strong>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>
    </section>
            </div>
        </details>
    <?php endif; ?>
</main>

<?php require_once '../../includes/footer.php'; ?>
