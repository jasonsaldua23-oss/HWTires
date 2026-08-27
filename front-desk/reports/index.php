<?php
/**
 * Front Desk Reports
 */

require_once '../../includes/config.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$user = app_get_session_user();

if ($user['role'] !== 'front-desk') {
    redirect('/hwtires/' . $user['role'] . '/');
}

$page_title = 'Reports';
$branch_id = (int) ($user['branch_id'] ?? 0);
$has_inventory_access = can_access_inventory($branch_id);

if (!function_exists('front_reports_money')) {
    function front_reports_money($amount, $decimals = 0) {
        return '&#8369;' . number_format((float) $amount, $decimals);
    }
}

if (!function_exists('front_reports_csv_money')) {
    function front_reports_csv_money($amount) {
        return 'PHP ' . number_format((float) $amount, 2);
    }
}

if (!function_exists('front_reports_valid_date')) {
    function front_reports_valid_date($date, $fallback) {
        $parsed = DateTime::createFromFormat('Y-m-d', (string) $date);
        return $parsed && $parsed->format('Y-m-d') === $date ? $date : $fallback;
    }
}

if (!function_exists('front_reports_short_date')) {
    function front_reports_short_date($date) {
        return !empty($date) ? date('Y-m-d', strtotime($date)) : '-';
    }
}

if (!function_exists('front_reports_inventory_vehicle_label')) {
    function front_reports_inventory_vehicle_label(array $row) {
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

if (!function_exists('front_reports_status_label')) {
    function front_reports_status_label($status) {
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

if (!function_exists('front_reports_status_class')) {
    function front_reports_status_class($status) {
        $status = strtolower((string) $status);
        $allowed = ['waiting', 'pending', 'in-progress', 'completed', 'approved', 'rejected'];
        return in_array($status, $allowed, true) ? $status : 'pending';
    }
}

if (!function_exists('front_reports_category_label')) {
    function front_reports_category_label($category, $plural = false) {
        $labels = [
            'tire' => $plural ? 'Tires' : 'Tire',
            'accessory' => $plural ? 'Accessories' : 'Accessory',
            'part' => $plural ? 'Parts' : 'Part',
        ];

        return $labels[strtolower((string) $category)] ?? ($plural ? 'Items' : 'Item');
    }
}

if (!function_exists('front_reports_detail_url')) {
    function front_reports_detail_url($report, $date_from, $date_to, $status, $search, $extra = []) {
        $params = [
            'report' => $report,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'status' => $status,
            'search' => $search,
        ];

        return '?' . http_build_query(array_merge($params, $extra));
    }
}

if (!function_exists('front_reports_record_url')) {
    function front_reports_record_url($type, $id) {
        $id = (int) $id;
        if ($id <= 0) {
            return '';
        }

        $routes = [
            'customer' => '/hwtires/front-desk/customers/profile.php?id=',
            'vehicle' => '/hwtires/front-desk/vehicles/profile.php?id=',
            'job' => '/hwtires/front-desk/job-orders/view.php?id=',
            'quotation' => '/hwtires/front-desk/quotations/view.php?id=',
        ];

        return isset($routes[$type]) ? $routes[$type] . $id : '';
    }
}

if (!function_exists('front_reports_record_link')) {
    function front_reports_record_link($url, $label, $class = 'reports-record-link') {
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

if (!function_exists('front_reports_vehicle_label')) {
    function front_reports_vehicle_label(array $row) {
        $plate = trim((string) ($row['plate_number'] ?? ''));
        $details = trim((string) ($row['vehicle_year'] ?? '') . ' ' . (string) ($row['vehicle_make'] ?? '') . ' ' . (string) ($row['vehicle_model'] ?? ''));

        if ($plate !== '' && $details !== '') {
            return $plate . ' - ' . $details;
        }

        return $plate !== '' ? $plate : ($details !== '' ? $details : '-');
    }
}

if (!function_exists('front_reports_product_detail_text')) {
    function front_reports_product_detail_text(array $row) {
        $parts = [];

        $brand = trim((string) ($row['brand'] ?? ''));
        if ($brand !== '') {
            $parts[] = 'Brand: ' . $brand;
        }

        $model = trim((string) ($row['inventory_model'] ?? ''));
        if ($model !== '' && strcasecmp($model, $brand) !== 0) {
            $parts[] = 'Model: ' . $model;
        }

        if (!empty($row['size'])) {
            $parts[] = 'Size: ' . $row['size'];
        }

        $serial = trim((string) ($row['serial_number'] ?? $row['sku'] ?? ''));
        if ($serial !== '') {
            $parts[] = 'Serial/SKU: ' . $serial;
        }

        if (!empty($row['manufacturing_date'])) {
            $parts[] = 'Mfg: ' . front_reports_short_date($row['manufacturing_date']);
        }

        return !empty($parts) ? implode(' | ', $parts) : '-';
    }
}

if (!function_exists('front_reports_movement_type_label')) {
    function front_reports_movement_type_label($type) {
        $labels = [
            'stock_in' => 'Stock In',
            'stock_out' => 'Stock Out',
            'adjustment' => 'Adjustment',
            'damage' => 'Damage',
        ];

        return $labels[strtolower((string) $type)] ?? front_reports_status_label($type);
    }
}

if (!function_exists('front_reports_archive_type_label')) {
    function front_reports_archive_type_label($type) {
        $labels = [
            'customer' => 'Customer',
            'vehicle' => 'Vehicle',
            'service_operation' => 'Service Operation',
            'job_order' => 'Job Order',
            'inventory_item' => 'Inventory Item',
        ];

        return $labels[strtolower((string) $type)] ?? front_reports_status_label($type);
    }
}

if (!function_exists('front_reports_archive_record_url')) {
    function front_reports_archive_record_url(array $row) {
        $type = strtolower((string) ($row['archive_type'] ?? ''));
        $record_id = (int) ($row['record_id'] ?? 0);

        if ($type === 'customer') {
            return front_reports_record_url('customer', $record_id);
        }
        if ($type === 'vehicle') {
            return front_reports_record_url('vehicle', $record_id);
        }
        if ($type === 'service_operation') {
            return front_reports_record_url('quotation', $record_id);
        }
        if ($type === 'job_order') {
            return front_reports_record_url('job', $record_id);
        }

        return '';
    }
}

if (!function_exists('front_reports_export_filename')) {
    function front_reports_export_filename($report_label, $branch_label, $date_from, $date_to) {
        $slug = strtolower($report_label . '-' . $branch_label);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim((string) $slug, '-');

        return ($slug !== '' ? $slug : 'front-desk-report') . '-' . $date_from . '-to-' . $date_to . '.csv';
    }
}

if (!function_exists('front_reports_send_detail_csv')) {
    function front_reports_send_detail_csv(array $filters, $report_tab, array $active_report, array $detail_records) {
        $filename = front_reports_export_filename(
            $active_report['label'] ?? 'Report',
            $filters['branch_label'] ?? 'Branch',
            $filters['date_from'] ?? date('Y-m-d'),
            $filters['date_to'] ?? date('Y-m-d')
        );

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');

        fputcsv($out, ['Highway Tires Front Desk Report']);
        fputcsv($out, ['Report Type', $active_report['label'] ?? 'Report']);
        fputcsv($out, ['Branch', $filters['branch_label'] ?? 'Branch']);
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
                    front_reports_vehicle_label($record),
                    $record['customer_name'] ?? '-',
                    $record['customer_phone'] ?? '-',
                    $record['branch_name'] ?? ($filters['branch_label'] ?? 'Branch'),
                    $record['last_visited_branch'] ?? '-',
                    $owner_count . ' owner' . ($owner_count === 1 ? '' : 's'),
                    $record['previous_owner_names'] ?? '-',
                    $record['service_count'] ?? 0,
                    $record['items_given_count'] ?? 0,
                    front_reports_csv_money($record['sales_value'] ?? 0),
                    !empty($record['last_mileage']) ? number_format((int) $record['last_mileage']) . ' km' : '-',
                    front_reports_short_date($record['last_visit_date'] ?? ''),
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
                    front_reports_short_date($movement['created_at'] ?? ''),
                    $filters['branch_label'] ?? 'Branch',
                    front_reports_movement_type_label($movement['transaction_type'] ?? ''),
                    $movement['item_name'] ?? '-',
                    front_reports_category_label($movement['category'] ?? ''),
                    front_reports_product_detail_text($movement),
                    abs((int) ($movement['quantity'] ?? 0)),
                    $tagged_customer_name,
                    front_reports_inventory_vehicle_label($movement) ?: '-',
                    !empty($references) ? implode(' / ', $references) : '-',
                    $movement['entered_by_name'] ?? 'System',
                    $movement['notes'] ?? '',
                ]);
            }
        } elseif ($report_tab === 'archives') {
            fputcsv($out, ['Archived Date', 'Branch', 'Record Type', 'Record', 'Customer/Owner', 'Vehicle', 'Archived By', 'Reason', 'Original Status']);
            foreach ($detail_records as $record) {
                fputcsv($out, [
                    front_reports_short_date($record['archived_at'] ?? ''),
                    $record['branch_name'] ?? ($filters['branch_label'] ?? 'Branch'),
                    front_reports_archive_type_label($record['archive_type'] ?? ''),
                    $record['record_label'] ?? '-',
                    $record['customer_name'] ?? '-',
                    front_reports_vehicle_label($record),
                    $record['archived_by_name'] ?? '-',
                    $record['archive_reason'] ?? '-',
                    front_reports_status_label($record['original_status'] ?? ''),
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
                    front_reports_short_date($movement['created_at'] ?? ''),
                    $filters['branch_label'] ?? 'Branch',
                    $movement['item_name'] ?? '-',
                    front_reports_category_label($movement['category'] ?? ''),
                    front_reports_product_detail_text($movement),
                    abs((int) ($movement['quantity'] ?? 0)),
                    front_reports_csv_money(((float) ($movement['unit_price'] ?? 0)) * abs((int) ($movement['quantity'] ?? 0))),
                    $tagged_customer_name,
                    $movement['tagged_customer_phone'] ?? '',
                    front_reports_inventory_vehicle_label($movement) ?: '-',
                    $movement['tagged_job_number'] ?? '-',
                    $movement['tagged_quotation_number'] ?? '-',
                    $movement['notes'] ?? '',
                ]);
            }
        } elseif ($report_tab === 'vehicles') {
            fputcsv($out, ['Vehicle', 'Current Owner', 'Contact', 'Added/Home Branch', 'Last Visited Branch', 'Services', 'Inventory Sales', 'Sales Value', 'Last Visit']);
            foreach ($detail_records as $record) {
                fputcsv($out, [
                    front_reports_vehicle_label($record),
                    $record['customer_name'] ?? '-',
                    $record['customer_phone'] ?? '-',
                    $record['branch_name'] ?? ($filters['branch_label'] ?? 'Branch'),
                    $record['last_visited_branch'] ?? '-',
                    $record['service_count'] ?? 0,
                    $record['items_given_count'] ?? 0,
                    front_reports_csv_money($record['sales_value'] ?? 0),
                    front_reports_short_date($record['last_visit_date'] ?? ''),
                ]);
            }
        } else {
            fputcsv($out, ['Date', 'Branch', 'Customer', 'Contact', 'Vehicle', 'Services Availed', 'Job Order', 'Service Operation', 'Status', 'Amount']);
            foreach ($detail_records as $service) {
                fputcsv($out, [
                    front_reports_short_date($service['service_date'] ?? ''),
                    $filters['branch_label'] ?? 'Branch',
                    $service['customer_name'] ?? '-',
                    $service['customer_phone'] ?? '-',
                    front_reports_vehicle_label($service),
                    $service['service_names'] ?? '-',
                    $service['job_number'] ?? '-',
                    $service['quotation_number'] ?? '-',
                    front_reports_status_label($service['report_status'] ?? ''),
                    front_reports_csv_money($service['total_amount'] ?? 0),
                ]);
            }
        }

        fclose($out);
        exit;
    }
}

if (!function_exists('front_reports_empty_inventory_categories')) {
    function front_reports_empty_inventory_categories() {
        return [
            'tire' => ['category' => 'tire', 'label' => 'Tires', 'stock_in' => 0, 'stock_out' => 0, 'stock_out_value' => 0, 'net_units' => 0],
            'accessory' => ['category' => 'accessory', 'label' => 'Accessories', 'stock_in' => 0, 'stock_out' => 0, 'stock_out_value' => 0, 'net_units' => 0],
            'part' => ['category' => 'part', 'label' => 'Parts', 'stock_in' => 0, 'stock_out' => 0, 'stock_out_value' => 0, 'net_units' => 0],
        ];
    }
}

if (!function_exists('front_reports_percent')) {
    function front_reports_percent($value, $max) {
        if ($value <= 0 || $max <= 0) {
            return 0;
        }

        return max(4, min(100, round(((float) $value / (float) $max) * 100, 2)));
    }
}

if (!function_exists('front_reports_peak_label')) {
    function front_reports_peak_label(array $periods, $field) {
        $peak_label = '-';
        $peak_value = 0;

        foreach ($periods as $period) {
            $value = (int) ($period[$field] ?? 0);
            if ($value > $peak_value) {
                $peak_value = $value;
                $peak_label = $period['label'] ?? '-';
            }
        }

        return $peak_value > 0 ? $peak_label . ' (' . number_format($peak_value) . ')' : 'No movement';
    }
}

if (!function_exists('front_reports_build_periods')) {
    function front_reports_build_periods($date_from, $date_to, $monthly = false) {
        $periods = [];
        $cursor = new DateTime($monthly ? date('Y-m-01', strtotime($date_from)) : $date_from);
        $end = new DateTime($monthly ? date('Y-m-01', strtotime($date_to)) : $date_to);

        while ($cursor <= $end) {
            $key = $monthly ? $cursor->format('Y-m') : $cursor->format('Y-m-d');
            $periods[$key] = [
                'label' => $monthly ? $cursor->format('M Y') : $cursor->format('M j'),
                'service_operations' => 0,
                'job_orders' => 0,
                'stock_in' => 0,
                'stock_out' => 0,
            ];
            $cursor->modify($monthly ? '+1 month' : '+1 day');
        }

        return $periods;
    }
}

try {
    $branch_stmt = $pdo->prepare("SELECT id, name, location, has_inventory FROM branches WHERE id = ? LIMIT 1");
    $branch_stmt->execute([$branch_id]);
    $branch = $branch_stmt->fetch() ?: ['name' => 'Branch', 'location' => '', 'has_inventory' => 0];
} catch (Exception $e) {
    $branch = ['name' => 'Branch', 'location' => '', 'has_inventory' => 0];
}

try {
    $bounds_stmt = $pdo->prepare("
        SELECT MIN(report_date) AS first_date,
               MAX(report_date) AS last_date
        FROM (
            SELECT job_date AS report_date
            FROM job_orders
            WHERE branch_id = ?
              AND job_date IS NOT NULL

            UNION ALL

            SELECT quotation_date AS report_date
            FROM quotations
            WHERE branch_id = ?
              AND quotation_date IS NOT NULL

            UNION ALL

            SELECT service_date AS report_date
            FROM service_history
            WHERE branch_id = ?
              AND service_date IS NOT NULL

            UNION ALL

            SELECT visit_date AS report_date
            FROM customer_visits
            WHERE branch_id = ?
              AND visit_date IS NOT NULL

            UNION ALL

            SELECT DATE(t.created_at) AS report_date
            FROM inventory_transactions t
            INNER JOIN inventory_items i ON i.id = t.item_id
            WHERE i.branch_id = ?
              AND t.created_at IS NOT NULL
              AND COALESCE(t.reference_type, '') <> 'opening_balance'
        ) report_dates
        WHERE report_date IS NOT NULL
    ");
    $bounds_stmt->execute([$branch_id, $branch_id, $branch_id, $branch_id, $branch_id]);
    $report_date_bounds = $bounds_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $report_date_bounds = [];
}

$default_from = !empty($report_date_bounds['first_date']) ? $report_date_bounds['first_date'] : date('Y-m-d');
$default_to = !empty($report_date_bounds['last_date']) ? $report_date_bounds['last_date'] : $default_from;
$date_from = front_reports_valid_date($_GET['date_from'] ?? $default_from, $default_from);
$date_to = front_reports_valid_date($_GET['date_to'] ?? $default_to, $default_to);

if ($date_from > $date_to) {
    [$date_from, $date_to] = [$date_to, $date_from];
}

$report_tab_options = [
    'services' => ['label' => 'Services', 'icon' => 'fas fa-wrench', 'noun' => 'service records'],
    'vehicles' => ['label' => 'Customer & Vehicle', 'icon' => 'fas fa-car', 'noun' => 'vehicle records'],
    'vehicle_history' => ['label' => 'Vehicle History', 'icon' => 'fas fa-clock-rotate-left', 'noun' => 'vehicle history records'],
    'archives' => ['label' => 'Archived Records', 'icon' => 'fas fa-box-archive', 'noun' => 'archived records'],
];
if ($has_inventory_access) {
    $report_tab_options = [
        'services' => ['label' => 'Services', 'icon' => 'fas fa-wrench', 'noun' => 'service records'],
        'items' => ['label' => 'Inventory Sales', 'icon' => 'fas fa-receipt', 'noun' => 'inventory sale records'],
        'vehicles' => ['label' => 'Customer & Vehicle', 'icon' => 'fas fa-car', 'noun' => 'vehicle records'],
        'vehicle_history' => ['label' => 'Vehicle History', 'icon' => 'fas fa-clock-rotate-left', 'noun' => 'vehicle history records'],
        'stock_movement' => ['label' => 'Stock Movement', 'icon' => 'fas fa-right-left', 'noun' => 'stock movement records'],
        'archives' => ['label' => 'Archived Records', 'icon' => 'fas fa-box-archive', 'noun' => 'archived records'],
    ];
}

$report_tab = strtolower(trim($_GET['report'] ?? 'services'));
if (!array_key_exists($report_tab, $report_tab_options)) {
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

$range_days = max(1, (int) ((strtotime($date_to) - strtotime($date_from)) / 86400) + 1);
$monthly_trend = $range_days > 62;
$period_format = $monthly_trend ? '%Y-%m' : '%Y-%m-%d';
$periods = front_reports_build_periods($date_from, $date_to, $monthly_trend);

$quote_stats = ['total_quotes' => 0, 'approved' => 0, 'pending' => 0, 'rejected' => 0, 'approved_value' => 0];
$job_stats = ['total_jobs' => 0, 'completed' => 0, 'in_progress' => 0, 'waiting' => 0];
$visit_count = 0;
$recent_quotations = [];
$recent_jobs = [];
$inventory_summary = ['stock_in_units' => 0, 'stock_out_units' => 0, 'stock_out_value' => 0, 'net_units' => 0];
$inventory_sales_summary = ['sales_rows' => 0, 'sales_units' => 0, 'sales_value' => 0, 'tagged_rows' => 0, 'walk_in_rows' => 0];
$inventory_top_items = [];
$inventory_tagged_stock_outs = [];
$inventory_category_movement = front_reports_empty_inventory_categories();

try {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_quotes,
            COUNT(CASE WHEN status = 'approved' THEN 1 END) AS approved,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending,
            COUNT(CASE WHEN status = 'rejected' THEN 1 END) AS rejected,
            COALESCE(SUM(CASE WHEN status = 'approved' THEN total_amount ELSE 0 END), 0) AS approved_value
        FROM quotations
        WHERE branch_id = ?
          AND quotation_date BETWEEN ? AND ?
    ");
    $stmt->execute([$branch_id, $date_from, $date_to]);
    $quote_stats = $stmt->fetch() ?: $quote_stats;

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_jobs,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed,
            COUNT(CASE WHEN status = 'in-progress' THEN 1 END) AS in_progress,
            COUNT(CASE WHEN status IN ('waiting', 'pending') THEN 1 END) AS waiting
        FROM job_orders
        WHERE branch_id = ?
          AND status <> 'cancelled'
          AND job_date BETWEEN ? AND ?
    ");
    $stmt->execute([$branch_id, $date_from, $date_to]);
    $job_stats = $stmt->fetch() ?: $job_stats;

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM customer_visits
        WHERE branch_id = ?
          AND visit_date BETWEEN ? AND ?
    ");
    $stmt->execute([$branch_id, $date_from, $date_to]);
    $visit_count = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT period_key, SUM(service_operations) AS service_operations, SUM(job_orders) AS job_orders
        FROM (
            SELECT DATE_FORMAT(quotation_date, '$period_format') AS period_key, COUNT(*) AS service_operations, 0 AS job_orders
            FROM quotations
            WHERE branch_id = ?
              AND quotation_date BETWEEN ? AND ?
            GROUP BY period_key
            UNION ALL
            SELECT DATE_FORMAT(job_date, '$period_format') AS period_key, 0 AS service_operations, COUNT(*) AS job_orders
            FROM job_orders
            WHERE branch_id = ?
              AND status <> 'cancelled'
              AND job_date BETWEEN ? AND ?
            GROUP BY period_key
        ) activity
        GROUP BY period_key
        ORDER BY period_key ASC
    ");
    $stmt->execute([$branch_id, $date_from, $date_to, $branch_id, $date_from, $date_to]);
    foreach ($stmt->fetchAll() as $row) {
        $key = $row['period_key'];
        if (isset($periods[$key])) {
            $periods[$key]['service_operations'] = (int) $row['service_operations'];
            $periods[$key]['job_orders'] = (int) $row['job_orders'];
        }
    }

    $stmt = $pdo->prepare("
        SELECT q.*, c.name AS customer_name, v.make, v.model, v.plate_number
        FROM quotations q
        LEFT JOIN customers c ON c.id = q.customer_id
        LEFT JOIN vehicles v ON v.id = q.vehicle_id
        WHERE q.branch_id = ?
          AND q.quotation_date BETWEEN ? AND ?
        ORDER BY q.created_at DESC, q.id DESC
        LIMIT 5
    ");
    $stmt->execute([$branch_id, $date_from, $date_to]);
    $recent_quotations = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT jo.*, c.name AS customer_name, v.make, v.model, v.plate_number
        FROM job_orders jo
        LEFT JOIN customers c ON c.id = jo.customer_id
        LEFT JOIN vehicles v ON v.id = jo.vehicle_id
        WHERE jo.branch_id = ?
          AND jo.status <> 'cancelled'
          AND jo.job_date BETWEEN ? AND ?
        ORDER BY jo.created_at DESC, jo.id DESC
        LIMIT 5
    ");
    $stmt->execute([$branch_id, $date_from, $date_to]);
    $recent_jobs = $stmt->fetchAll();
} catch (Exception $e) {
    $quote_stats = ['total_quotes' => 0, 'approved' => 0, 'pending' => 0, 'rejected' => 0, 'approved_value' => 0];
    $job_stats = ['total_jobs' => 0, 'completed' => 0, 'in_progress' => 0, 'waiting' => 0];
    $visit_count = 0;
    $recent_quotations = [];
    $recent_jobs = [];
}

if ($has_inventory_access) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_in' THEN ABS(t.quantity) ELSE 0 END), 0) AS stock_in_units,
                COALESCE(SUM(CASE WHEN LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out' THEN ABS(t.quantity) ELSE 0 END), 0) AS stock_out_units,
                COALESCE(SUM(CASE WHEN LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out' THEN ABS(t.quantity) * i.unit_price ELSE 0 END), 0) AS stock_out_value
            FROM inventory_transactions t
            INNER JOIN inventory_items i ON i.id = t.item_id
            WHERE i.branch_id = ?
              AND DATE(t.created_at) BETWEEN ? AND ?
              AND COALESCE(t.reference_type, '') <> 'opening_balance'
        ");
        $stmt->execute([$branch_id, $date_from, $date_to]);
        $summary_row = $stmt->fetch() ?: [];
        $inventory_summary = [
            'stock_in_units' => (int) ($summary_row['stock_in_units'] ?? 0),
            'stock_out_units' => (int) ($summary_row['stock_out_units'] ?? 0),
            'stock_out_value' => (float) ($summary_row['stock_out_value'] ?? 0),
            'net_units' => (int) ($summary_row['stock_in_units'] ?? 0) - (int) ($summary_row['stock_out_units'] ?? 0),
        ];

        $stmt = $pdo->prepare("
            SELECT
                DATE_FORMAT(t.created_at, '$period_format') AS period_key,
                COALESCE(SUM(CASE WHEN LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_in' THEN ABS(t.quantity) ELSE 0 END), 0) AS stock_in,
                COALESCE(SUM(CASE WHEN LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out' THEN ABS(t.quantity) ELSE 0 END), 0) AS stock_out
            FROM inventory_transactions t
            INNER JOIN inventory_items i ON i.id = t.item_id
            WHERE i.branch_id = ?
              AND DATE(t.created_at) BETWEEN ? AND ?
              AND COALESCE(t.reference_type, '') <> 'opening_balance'
            GROUP BY period_key
            ORDER BY period_key ASC
        ");
        $stmt->execute([$branch_id, $date_from, $date_to]);
        foreach ($stmt->fetchAll() as $row) {
            $key = $row['period_key'];
            if (isset($periods[$key])) {
                $periods[$key]['stock_in'] = (int) $row['stock_in'];
                $periods[$key]['stock_out'] = (int) $row['stock_out'];
            }
        }

        $stmt = $pdo->prepare("
            SELECT
                i.category,
                COALESCE(SUM(CASE WHEN LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_in' THEN ABS(t.quantity) ELSE 0 END), 0) AS stock_in,
                COALESCE(SUM(CASE WHEN LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out' THEN ABS(t.quantity) ELSE 0 END), 0) AS stock_out,
                COALESCE(SUM(CASE WHEN LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out' THEN ABS(t.quantity) * i.unit_price ELSE 0 END), 0) AS stock_out_value
            FROM inventory_transactions t
            INNER JOIN inventory_items i ON i.id = t.item_id
            WHERE i.branch_id = ?
              AND DATE(t.created_at) BETWEEN ? AND ?
              AND COALESCE(t.reference_type, '') <> 'opening_balance'
            GROUP BY i.category
            ORDER BY i.category ASC
        ");
        $stmt->execute([$branch_id, $date_from, $date_to]);
        foreach ($stmt->fetchAll() as $row) {
            $category = strtolower((string) ($row['category'] ?? 'part'));
            if (!isset($inventory_category_movement[$category])) {
                $category = 'part';
            }

            $inventory_category_movement[$category]['stock_in'] = (int) $row['stock_in'];
            $inventory_category_movement[$category]['stock_out'] = (int) $row['stock_out'];
            $inventory_category_movement[$category]['stock_out_value'] = (float) $row['stock_out_value'];
            $inventory_category_movement[$category]['net_units'] = (int) $row['stock_in'] - (int) $row['stock_out'];
        }

        $stmt = $pdo->prepare("
            SELECT
                i.item_name,
                i.category,
                i.brand,
                i.size,
                COALESCE(SUM(CASE WHEN LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_in' THEN ABS(t.quantity) ELSE 0 END), 0) AS stock_in_units,
                COALESCE(SUM(CASE WHEN LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out' THEN ABS(t.quantity) ELSE 0 END), 0) AS stock_out_units,
                COALESCE(SUM(ABS(t.quantity)), 0) AS total_movement
            FROM inventory_transactions t
            INNER JOIN inventory_items i ON i.id = t.item_id
            WHERE i.branch_id = ?
              AND DATE(t.created_at) BETWEEN ? AND ?
              AND COALESCE(t.reference_type, '') <> 'opening_balance'
            GROUP BY i.id, i.item_name, i.category, i.brand, i.size
            ORDER BY total_movement DESC, i.item_name ASC
            LIMIT 5
        ");
        $stmt->execute([$branch_id, $date_from, $date_to]);
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
            LEFT JOIN customers tagged_customer ON tagged_customer.id = t.customer_id
            LEFT JOIN vehicles tagged_vehicle ON tagged_vehicle.id = t.vehicle_id
            LEFT JOIN job_orders tagged_job ON tagged_job.id = t.job_order_id
            LEFT JOIN quotations tagged_quotation ON tagged_quotation.id = t.quotation_id
            WHERE i.branch_id = ?
              AND LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out'
              AND DATE(t.created_at) BETWEEN ? AND ?
              AND COALESCE(t.reference_type, '') <> 'opening_balance'
            ORDER BY t.created_at DESC, t.id DESC
            LIMIT 12
        ");
        $stmt->execute([$branch_id, $date_from, $date_to]);
        $inventory_tagged_stock_outs = $stmt->fetchAll();
    } catch (Exception $e) {
        $inventory_summary = ['stock_in_units' => 0, 'stock_out_units' => 0, 'stock_out_value' => 0, 'net_units' => 0];
        $inventory_sales_summary = ['sales_rows' => 0, 'sales_units' => 0, 'sales_value' => 0, 'tagged_rows' => 0, 'walk_in_rows' => 0];
        $inventory_top_items = [];
        $inventory_tagged_stock_outs = [];
        $inventory_category_movement = front_reports_empty_inventory_categories();
    }
}

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
    'with_items' => 'With Inventory Sales',
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
if (!$has_inventory_access) {
    unset($archive_status_options['inventory_items']);
}

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

$services_params = [$branch_id, $date_from, $date_to];
$services_where = [];
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
    LEFT JOIN (
        SELECT quotation_id, GROUP_CONCAT(item_name ORDER BY id SEPARATOR ', ') AS service_names
        FROM quotation_items
        WHERE item_type = 'service'
        GROUP BY quotation_id
    ) service_rollup ON service_rollup.quotation_id = q.id
    WHERE q.branch_id = ?
      AND q.quotation_date BETWEEN ? AND ?" . $services_extra_sql . "
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
        COALESCE(service_rollup.service_names, 'General service') AS service_names
    " . $services_query . "
    ORDER BY q.quotation_date DESC, q.created_at DESC, q.id DESC
    LIMIT $services_limit OFFSET $services_offset
");
$services_stmt->execute($services_params);
$detailed_services = $services_stmt->fetchAll();

$items_total = 0;
if ($has_inventory_access) {
    $inventory_item_select_extra = '';
    if (app_column_exists('inventory_items', 'model')) {
        $inventory_item_select_extra .= ', i.model AS inventory_model';
    }
    if (app_column_exists('inventory_items', 'serial_number')) {
        $inventory_item_select_extra .= ', i.serial_number';
    }
    if (app_column_exists('inventory_items', 'manufacturing_date')) {
        $inventory_item_select_extra .= ', i.manufacturing_date';
    }

    $item_where = [
        "LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out'",
        'i.branch_id = ?',
        'DATE(t.created_at) BETWEEN ? AND ?',
        "COALESCE(t.reference_type, '') NOT IN ('opening_balance', 'branch_transfer', 'inter_branch_transfer')",
    ];
    $item_params = [$branch_id, $date_from, $date_to];
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

    $items_summary_stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS sales_rows,
            COALESCE(SUM(ABS(t.quantity)), 0) AS sales_units,
            COALESCE(SUM(ABS(t.quantity) * i.unit_price), 0) AS sales_value,
            COUNT(CASE WHEN t.customer_id IS NOT NULL OR t.vehicle_id IS NOT NULL THEN 1 END) AS tagged_rows,
            COUNT(CASE WHEN t.customer_id IS NULL AND t.vehicle_id IS NULL THEN 1 END) AS walk_in_rows
        FROM inventory_transactions t
        INNER JOIN inventory_items i ON i.id = t.item_id
        LEFT JOIN customers tagged_customer ON tagged_customer.id = t.customer_id
        LEFT JOIN vehicles tagged_vehicle ON tagged_vehicle.id = t.vehicle_id
        LEFT JOIN job_orders tagged_job ON tagged_job.id = t.job_order_id
        LEFT JOIN quotations tagged_quotation ON tagged_quotation.id = t.quotation_id
        WHERE $item_where_sql
    ");
    $items_summary_stmt->execute($item_params);
    $inventory_sales_row = $items_summary_stmt->fetch() ?: [];
    $inventory_sales_summary = [
        'sales_rows' => (int) ($inventory_sales_row['sales_rows'] ?? 0),
        'sales_units' => (int) ($inventory_sales_row['sales_units'] ?? 0),
        'sales_value' => (float) ($inventory_sales_row['sales_value'] ?? 0),
        'tagged_rows' => (int) ($inventory_sales_row['tagged_rows'] ?? 0),
        'walk_in_rows' => (int) ($inventory_sales_row['walk_in_rows'] ?? 0),
    ];

    $items_count_stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
        FROM inventory_transactions t
        INNER JOIN inventory_items i ON i.id = t.item_id
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
}

$vehicle_base_params = [$date_from, $date_to, $date_from, $date_to, $date_from, $date_to, $date_from, $date_to, $branch_id];
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
            OR last_visited_branch LIKE ?
        )";
        $like = '%' . $term . '%';
        $vehicle_base_params = array_merge($vehicle_base_params, array_fill(0, 6, $like));
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
            COALESCE(last_branch.name, branch_home.name) AS last_visited_branch,
            branch_home.name AS branch_name,
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
        LEFT JOIN branches branch_home ON branch_home.id = v.branch_id
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
        LEFT JOIN branches last_branch ON last_branch.id = (
            SELECT recent.branch_id
            FROM (
                SELECT jo.vehicle_id, jo.branch_id, jo.job_date AS visited_at, jo.id AS row_id
                FROM job_orders jo
                WHERE jo.status <> 'cancelled'
                UNION ALL
                SELECT q.vehicle_id, q.branch_id, q.quotation_date AS visited_at, q.id AS row_id
                FROM quotations q
            ) recent
            WHERE recent.vehicle_id = v.id
            ORDER BY recent.visited_at DESC, recent.row_id DESC
            LIMIT 1
        )
        WHERE v.branch_id = ?
    ) vehicle_report
    WHERE last_visit_date <> '1000-01-01'" . $vehicle_filter_sql . "
";
$vehicle_count_stmt = $pdo->prepare("SELECT COUNT(*) AS total " . $vehicle_report_query);
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

    $vehicle_history_params = [$date_from, $date_to, $date_from, $date_to, $date_from, $date_to, $date_from, $date_to, $branch_id];
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
                COALESCE(last_branch.name, b.name) AS last_visited_branch,
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
            LEFT JOIN branches last_branch ON last_branch.id = (
                SELECT recent.branch_id
                FROM (
                    SELECT jo.vehicle_id, jo.branch_id, jo.job_date AS visited_at, jo.id AS row_id
                    FROM job_orders jo
                    WHERE jo.status <> 'cancelled'
                    UNION ALL
                    SELECT q.vehicle_id, q.branch_id, q.quotation_date AS visited_at, q.id AS row_id
                    FROM quotations q
                ) recent
                WHERE recent.vehicle_id = v.id
                ORDER BY recent.visited_at DESC, recent.row_id DESC
                LIMIT 1
            )
            WHERE v.branch_id = ?
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
if ($has_inventory_access && $report_tab === 'stock_movement') {
    $movement_where = [
        'i.branch_id = ?',
        'DATE(t.created_at) BETWEEN ? AND ?',
        "COALESCE(t.reference_type, '') <> 'opening_balance'",
    ];
    $movement_params = [$branch_id, $date_from, $date_to];
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
        WHERE c.branch_id = ?
          AND (c.archived_at IS NOT NULL OR c.status = 'archived')
          AND DATE(COALESCE(c.archived_at, c.updated_at, c.created_at)) BETWEEN ? AND ?";
    $archive_params = array_merge($archive_params, [$branch_id, $date_from, $date_to]);

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
        WHERE v.branch_id = ?
          AND (v.archived_at IS NOT NULL OR v.status = 'archived')
          AND DATE(COALESCE(v.archived_at, v.updated_at, v.created_at)) BETWEEN ? AND ?";
    $archive_params = array_merge($archive_params, [$branch_id, $date_from, $date_to]);

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
        WHERE q.branch_id = ?
          AND (q.archived_at IS NOT NULL OR q.status = 'archived')
          AND DATE(COALESCE(q.archived_at, q.updated_at, q.created_at)) BETWEEN ? AND ?";
    $archive_params = array_merge($archive_params, [$branch_id, $date_from, $date_to]);

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
        WHERE jo.branch_id = ?
          AND (jo.archived_at IS NOT NULL OR jo.status = 'archived')
          AND DATE(COALESCE(jo.archived_at, jo.updated_at, jo.created_at)) BETWEEN ? AND ?";
    $archive_params = array_merge($archive_params, [$branch_id, $date_from, $date_to]);

    if ($has_inventory_access) {
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
            WHERE i.branch_id = ?
              AND (i.archived_at IS NOT NULL OR i.status = 'archived')
              AND DATE(COALESCE(i.archived_at, i.updated_at, i.created_at)) BETWEEN ? AND ?";
        $archive_params = array_merge($archive_params, [$branch_id, $date_from, $date_to]);
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
$active_report = $report_tab_options[$report_tab];
$branch_label = app_branch_label($branch['name'] ?? 'Branch', 'Branch');
$active_status_label = $active_status_options[$status_filter] ?? 'All Records';
$report_range_label = format_date($date_from, 'M d, Y') . ' - ' . format_date($date_to, 'M d, Y');
$range_anchor = new DateTime($default_to ?: date('Y-m-d'));
$this_month = clone $range_anchor;
$last_month = clone $range_anchor;
$this_year = clone $range_anchor;
$last_year = clone $range_anchor;
$last_month->modify('first day of previous month');
$last_year->modify('-1 year');
$quick_report_ranges = [
    'all' => [
        'label' => 'All History',
        'date_from' => $default_from,
        'date_to' => $default_to,
    ],
    'this_month' => [
        'label' => 'This Month',
        'date_from' => $this_month->modify('first day of this month')->format('Y-m-d'),
        'date_to' => $this_month->modify('last day of this month')->format('Y-m-d'),
    ],
    'last_month' => [
        'label' => 'Last Month',
        'date_from' => $last_month->format('Y-m-d'),
        'date_to' => $last_month->modify('last day of this month')->format('Y-m-d'),
    ],
    'this_year' => [
        'label' => 'This Year',
        'date_from' => $this_year->format('Y') . '-01-01',
        'date_to' => $this_year->format('Y') . '-12-31',
    ],
    'last_year' => [
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
    front_reports_send_detail_csv(
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

$export_url = front_reports_detail_url($report_tab, $date_from, $date_to, $status_filter, $search_filter, [
    'per_page' => $detail_per_page,
    'export' => 'csv',
]);
$reset_url = front_reports_detail_url($report_tab, $default_from, $default_to, 'all', '');

$activity_service_values = array_column($periods, 'service_operations');
$activity_job_values = array_column($periods, 'job_orders');
$activity_max = max(1, max($activity_service_values ?: [0]), max($activity_job_values ?: [0]));
$activity_service_total = array_sum($activity_service_values);
$activity_job_total = array_sum($activity_job_values);
$inventory_stock_in_values = array_column($periods, 'stock_in');
$inventory_stock_out_values = array_column($periods, 'stock_out');
$inventory_max = max(1, max($inventory_stock_in_values ?: [0]), max($inventory_stock_out_values ?: [0]));
$inventory_stock_in_total = array_sum($inventory_stock_in_values);
$inventory_stock_out_total = array_sum($inventory_stock_out_values);
$inventory_category_movement = array_values($inventory_category_movement);
$inventory_category_max = 1;
foreach ($inventory_category_movement as $category) {
    $inventory_category_max = max($inventory_category_max, (int) $category['stock_in'], (int) $category['stock_out']);
}
$label_step = count($periods) > 8 ? (int) ceil(count($periods) / 6) : 1;

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
?>

<?php require_once '../../includes/header.php'; ?>
<?php require_once '../../includes/sidebar.php'; ?>

<main class="reports-page frontdesk-reports-page">
    <header class="reports-hero">
        <div>
            <h1>Reports</h1>
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
        <form method="GET" class="reports-filter-form front-reports-filter-form reports-detail-filter-form">
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
            <button type="submit" class="front-reports-filter-submit">Apply</button>
            <label class="reports-search-filter">
                <span>Search</span>
                <input type="search" name="search" value="<?php echo esc_attr($search_filter); ?>" placeholder="Customer, plate, item, service, reference...">
            </label>
            <button type="submit" class="reports-search-submit front-reports-search-submit">Search</button>
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
               href="<?php echo esc_attr(front_reports_detail_url($tab_value, $date_from, $date_to, 'all', $search_filter)); ?>#detailed-report">
                <i class="<?php echo esc_attr($tab_option['icon']); ?>"></i>
                <span><?php echo esc_html($tab_option['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </section>

    <section class="reports-summary-grid" aria-label="Branch report summary">
        <?php if ($report_tab === 'items'): ?>
            <article class="reports-summary-card">
                <div>
                    <span>Inventory Sales Value</span>
                    <strong><?php echo front_reports_money($inventory_sales_summary['sales_value'] ?? 0); ?></strong>
                </div>
                <span class="reports-summary-icon icon-green"><i class="fas fa-receipt"></i></span>
            </article>
            <article class="reports-summary-card">
                <div>
                    <span>Stock-Out Units Sold</span>
                    <strong><?php echo number_format((int) ($inventory_sales_summary['sales_units'] ?? 0)); ?></strong>
                </div>
                <span class="reports-summary-icon icon-orange"><i class="fas fa-box-open"></i></span>
            </article>
            <article class="reports-summary-card">
                <div>
                    <span>Customer Tagged</span>
                    <strong><?php echo number_format((int) ($inventory_sales_summary['tagged_rows'] ?? 0)); ?></strong>
                </div>
                <span class="reports-summary-icon icon-cyan"><i class="fas fa-user-check"></i></span>
            </article>
            <article class="reports-summary-card">
                <div>
                    <span>Walk-in / Not Tagged</span>
                    <strong><?php echo number_format((int) ($inventory_sales_summary['walk_in_rows'] ?? 0)); ?></strong>
                </div>
                <span class="reports-summary-icon icon-purple"><i class="fas fa-store"></i></span>
            </article>
        <?php else: ?>
            <article class="reports-summary-card">
                <div>
                    <span>Approved Revenue</span>
                    <strong><?php echo front_reports_money($quote_stats['approved_value'] ?? 0); ?></strong>
                </div>
                <span class="reports-summary-icon icon-green"><i class="fas fa-chart-column"></i></span>
            </article>
            <article class="reports-summary-card">
                <div>
                    <span>Service Operations</span>
                    <strong><?php echo number_format((int) ($quote_stats['total_quotes'] ?? 0)); ?></strong>
                </div>
                <span class="reports-summary-icon icon-orange"><i class="far fa-file-lines"></i></span>
            </article>
            <article class="reports-summary-card">
                <div>
                    <span>Job Orders</span>
                    <strong><?php echo number_format((int) ($job_stats['total_jobs'] ?? 0)); ?></strong>
                </div>
                <span class="reports-summary-icon icon-cyan"><i class="fas fa-clipboard-list"></i></span>
            </article>
            <article class="reports-summary-card">
                <div>
                    <span>Customer Visits</span>
                    <strong><?php echo number_format((int) $visit_count); ?></strong>
                </div>
                <span class="reports-summary-icon icon-purple"><i class="fas fa-users"></i></span>
            </article>
        <?php endif; ?>
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
                                <td colspan="6" class="reports-empty-state">No service records found for this filter.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($detail_records as $service): ?>
                                <?php
                                $status_class = front_reports_status_class($service['report_status'] ?? 'pending');
                                $vehicle_label = front_reports_vehicle_label($service);
                                $customer_url = front_reports_record_url('customer', $service['customer_id'] ?? 0);
                                $vehicle_url = front_reports_record_url('vehicle', $service['vehicle_id'] ?? 0);
                                $reference_links = [];
                                if (trim((string) ($service['job_number'] ?? '')) !== '') {
                                    $reference_links[] = front_reports_record_link(
                                        front_reports_record_url('job', $service['job_order_id'] ?? 0),
                                        $service['job_number'],
                                        'reports-reference-link'
                                    );
                                }
                                if (trim((string) ($service['quotation_number'] ?? '')) !== '') {
                                    $reference_links[] = front_reports_record_link(
                                        front_reports_record_url('quotation', $service['quotation_id'] ?? 0),
                                        $service['quotation_number'],
                                        'reports-reference-link'
                                    );
                                }
                                ?>
                                <tr>
                                    <td><?php echo esc_html(format_date($service['service_date'] ?? '', 'M d, Y')); ?></td>
                                    <td>
                                        <strong><?php echo front_reports_record_link($customer_url, $service['customer_name'] ?? '-'); ?></strong>
                                        <small><?php echo front_reports_record_link($vehicle_url, $vehicle_label, 'reports-record-link reports-muted-link'); ?></small>
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
                                            <?php echo esc_html(front_reports_status_label($service['report_status'] ?? '')); ?>
                                        </span>
                                    </td>
                                    <td><strong><?php echo front_reports_money($service['total_amount'] ?? 0); ?></strong></td>
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
                                <td colspan="7" class="reports-empty-state">No inventory sales found for this filter.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($detail_records as $movement): ?>
                                <?php
                                $vehicle_label = front_reports_inventory_vehicle_label($movement);
                                $movement_value = ((float) ($movement['unit_price'] ?? 0)) * abs((int) ($movement['quantity'] ?? 0));
                                $tagged_customer_name = trim((string) ($movement['tagged_customer_name'] ?? ''));
                                $is_customer_tagged = $tagged_customer_name !== '';
                                if (!$is_customer_tagged) {
                                    $tagged_customer_name = app_inventory_transaction_tag_empty_label($movement['reference_type'] ?? '', $movement['transaction_type'] ?? '');
                                }
                                $tagged_customer_url = front_reports_record_url('customer', $movement['tagged_customer_id'] ?? 0);
                                $tagged_vehicle_url = front_reports_record_url('vehicle', $movement['tagged_vehicle_id'] ?? 0);
                                $movement_reference_links = [];
                                if (trim((string) ($movement['tagged_job_number'] ?? '')) !== '') {
                                    $movement_reference_links[] = front_reports_record_link(
                                        front_reports_record_url('job', $movement['tagged_job_order_id'] ?? 0),
                                        $movement['tagged_job_number'],
                                        'reports-reference-link'
                                    );
                                }
                                if (trim((string) ($movement['tagged_quotation_number'] ?? '')) !== '') {
                                    $movement_reference_links[] = front_reports_record_link(
                                        front_reports_record_url('quotation', $movement['tagged_quotation_id'] ?? 0),
                                        $movement['tagged_quotation_number'],
                                        'reports-reference-link'
                                    );
                                }
                                ?>
                                <tr>
                                    <td><?php echo esc_html(format_date($movement['created_at'] ?? '', 'M d, Y')); ?></td>
                                    <td>
                                        <strong><?php echo esc_html($movement['item_name'] ?? '-'); ?></strong>
                                        <small><?php echo esc_html(front_reports_category_label($movement['category'] ?? '')); ?></small>
                                    </td>
                                    <td><?php echo esc_html(front_reports_product_detail_text($movement)); ?></td>
                                    <td>
                                        <strong><?php echo number_format(abs((int) ($movement['quantity'] ?? 0))); ?> pcs</strong>
                                        <small><?php echo front_reports_money($movement_value); ?></small>
                                    </td>
                                    <td>
                                        <strong><?php echo $is_customer_tagged ? front_reports_record_link($tagged_customer_url, $tagged_customer_name) : esc_html($tagged_customer_name); ?></strong>
                                        <?php if ($is_customer_tagged && !empty($movement['tagged_customer_phone'])): ?>
                                            <small><?php echo esc_html($movement['tagged_customer_phone']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $vehicle_label !== '' ? front_reports_record_link($tagged_vehicle_url, $vehicle_label, 'reports-record-link reports-muted-link') : '-'; ?></td>
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
                                $customer_url = front_reports_record_url('customer', $record['customer_id'] ?? 0);
                                $vehicle_url = front_reports_record_url('vehicle', $record['vehicle_id'] ?? 0);
                                $owner_count = max(1, (int) ($record['owner_count'] ?? 1));
                                $previous_owners = trim((string) ($record['previous_owner_names'] ?? ''));
                                ?>
                                <tr>
                                    <td><strong><?php echo front_reports_record_link($vehicle_url, front_reports_vehicle_label($record)); ?></strong></td>
                                    <td>
                                        <strong><?php echo front_reports_record_link($customer_url, $record['customer_name'] ?? '-'); ?></strong>
                                        <?php if (!empty($record['customer_phone'])): ?>
                                            <small><?php echo esc_html($record['customer_phone']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($record['branch_name'] ?? '-'); ?></td>
                                    <td><?php echo esc_html($record['last_visited_branch'] ?? '-'); ?></td>
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
                                    <td><strong><?php echo front_reports_money($record['sales_value'] ?? 0); ?></strong></td>
                                    <td><?php echo !empty($record['last_mileage']) ? esc_html(number_format((int) $record['last_mileage']) . ' km') : '-'; ?></td>
                                    <td><?php echo esc_html(front_reports_short_date($record['last_visit_date'] ?? '')); ?></td>
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
                                $vehicle_label = front_reports_inventory_vehicle_label($movement);
                                $tagged_customer_name = trim((string) ($movement['tagged_customer_name'] ?? ''));
                                $is_customer_tagged = $tagged_customer_name !== '';
                                if (!$is_customer_tagged) {
                                    $tagged_customer_name = app_inventory_transaction_tag_empty_label($movement['reference_type'] ?? '', $movement['transaction_type'] ?? '');
                                }
                                $tagged_customer_url = front_reports_record_url('customer', $movement['tagged_customer_id'] ?? 0);
                                $tagged_vehicle_url = front_reports_record_url('vehicle', $movement['tagged_vehicle_id'] ?? 0);
                                $movement_reference_links = [];
                                if (trim((string) ($movement['tagged_job_number'] ?? '')) !== '') {
                                    $resolved_job_id = (int) ($movement['resolved_job_order_id'] ?? $movement['tagged_job_order_id'] ?? 0);
                                    $movement_reference_links[] = front_reports_record_link(
                                        front_reports_record_url('job', $resolved_job_id),
                                        $movement['tagged_job_number'],
                                        'reports-reference-link'
                                    );
                                }
                                if (trim((string) ($movement['tagged_quotation_number'] ?? '')) !== '') {
                                    $resolved_quotation_id = (int) ($movement['resolved_quotation_id'] ?? $movement['tagged_quotation_id'] ?? 0);
                                    $movement_reference_links[] = front_reports_record_link(
                                        front_reports_record_url('quotation', $resolved_quotation_id),
                                        $movement['tagged_quotation_number'],
                                        'reports-reference-link'
                                    );
                                }
                                ?>
                                <tr>
                                    <td><?php echo esc_html(format_date($movement['created_at'] ?? '', 'M d, Y')); ?></td>
                                    <td><?php echo esc_html($branch_label); ?></td>
                                    <td><span class="reports-count-pill"><?php echo esc_html(front_reports_movement_type_label($movement['transaction_type'] ?? '')); ?></span></td>
                                    <td>
                                        <strong><?php echo esc_html($movement['item_name'] ?? '-'); ?></strong>
                                        <small><?php echo esc_html(front_reports_category_label($movement['category'] ?? '')); ?></small>
                                    </td>
                                    <td><?php echo esc_html(front_reports_product_detail_text($movement)); ?></td>
                                    <td><strong><?php echo number_format(abs((int) ($movement['quantity'] ?? 0))); ?> pcs</strong></td>
                                    <td>
                                        <strong><?php echo $is_customer_tagged ? front_reports_record_link($tagged_customer_url, $tagged_customer_name) : esc_html($tagged_customer_name); ?></strong>
                                        <?php if ($vehicle_label !== ''): ?>
                                            <small><?php echo front_reports_record_link($tagged_vehicle_url, $vehicle_label, 'reports-record-link reports-muted-link'); ?></small>
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
                                $archive_url = front_reports_archive_record_url($record);
                                $customer_url = front_reports_record_url('customer', $record['customer_id'] ?? 0);
                                $vehicle_url = front_reports_record_url('vehicle', $record['vehicle_id'] ?? 0);
                                $vehicle_label = front_reports_vehicle_label($record);
                                ?>
                                <tr>
                                    <td><?php echo esc_html(format_date($record['archived_at'] ?? '', 'M d, Y')); ?></td>
                                    <td><?php echo esc_html($record['branch_name'] ?? $branch_label); ?></td>
                                    <td><span class="reports-count-pill"><?php echo esc_html(front_reports_archive_type_label($record['archive_type'] ?? '')); ?></span></td>
                                    <td>
                                        <strong><?php echo front_reports_record_link($archive_url, $record['record_label'] ?? '-'); ?></strong>
                                        <small><?php echo esc_html(front_reports_status_label($record['original_status'] ?? '')); ?></small>
                                    </td>
                                    <td><?php echo !empty($record['customer_name']) ? front_reports_record_link($customer_url, $record['customer_name']) : '-'; ?></td>
                                    <td><?php echo $vehicle_label !== '-' ? front_reports_record_link($vehicle_url, $vehicle_label, 'reports-record-link reports-muted-link') : '-'; ?></td>
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
                                <td colspan="7" class="reports-empty-state">No customer and vehicle records found for this filter.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($detail_records as $record): ?>
                                <?php
                                $customer_url = front_reports_record_url('customer', $record['customer_id'] ?? 0);
                                $vehicle_url = front_reports_record_url('vehicle', $record['vehicle_id'] ?? 0);
                                ?>
                                <tr>
                                    <td><strong><?php echo front_reports_record_link($vehicle_url, front_reports_vehicle_label($record)); ?></strong></td>
                                    <td>
                                        <strong><?php echo front_reports_record_link($customer_url, $record['customer_name'] ?? '-'); ?></strong>
                                        <?php if (!empty($record['customer_phone'])): ?>
                                            <small><?php echo esc_html($record['customer_phone']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($record['last_visited_branch'] ?? '-'); ?></td>
                                    <td><span class="reports-count-pill"><?php echo number_format((int) ($record['service_count'] ?? 0)); ?></span></td>
                                    <td><span class="reports-count-pill"><?php echo number_format((int) ($record['items_given_count'] ?? 0)); ?></span></td>
                                    <td><strong><?php echo front_reports_money($record['sales_value'] ?? 0); ?></strong></td>
                                    <td><?php echo esc_html(front_reports_short_date($record['last_visit_date'] ?? '')); ?></td>
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
                            <a class="page-link" href="<?php echo esc_attr(front_reports_detail_url($report_tab, $date_from, $date_to, $status_filter, $search_filter, ['per_page' => $detail_per_page, 'page' => 1])); ?>#detailed-report">First</a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo esc_attr(front_reports_detail_url($report_tab, $date_from, $date_to, $status_filter, $search_filter, ['per_page' => $detail_per_page, 'page' => $detail_page - 1])); ?>#detailed-report">Previous</a>
                        </li>
                    <?php endif; ?>
                    <?php for ($i = max(1, $detail_page - 2); $i <= min($detail_total_pages, $detail_page + 2); $i++): ?>
                        <li class="page-item <?php echo $i === $detail_page ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo esc_attr(front_reports_detail_url($report_tab, $date_from, $date_to, $status_filter, $search_filter, ['per_page' => $detail_per_page, 'page' => $i])); ?>#detailed-report"><?php echo (int) $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    <?php if ($detail_page < $detail_total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo esc_attr(front_reports_detail_url($report_tab, $date_from, $date_to, $status_filter, $search_filter, ['per_page' => $detail_per_page, 'page' => $detail_page + 1])); ?>#detailed-report">Next</a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo esc_attr(front_reports_detail_url($report_tab, $date_from, $date_to, $status_filter, $search_filter, ['per_page' => $detail_per_page, 'page' => $detail_total_pages])); ?>#detailed-report">Last</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </section>

    <?php if ($report_tab === 'services'): ?>
        <details class="reports-support-details reports-service-analytics" id="service-analytics">
            <summary class="reports-support-summary">
                <span class="reports-support-title"><i class="fas fa-chart-line"></i><strong>Service Analytics</strong></span>
                <span class="reports-support-count">2 charts</span>
            </summary>
            <div class="reports-support-body">
                <section class="reports-chart-grid">
        <article class="reports-panel">
            <h2>Service Activity Trend</h2>
            <div class="front-report-chart-details">
                <span>Range: <?php echo esc_html(front_reports_short_date($date_from)); ?> to <?php echo esc_html(front_reports_short_date($date_to)); ?></span>
                <span>Scale max: <?php echo number_format((int) $activity_max); ?> records</span>
                <span>Peak service operations: <?php echo esc_html(front_reports_peak_label($periods, 'service_operations')); ?></span>
            </div>
            <div class="front-report-bar-chart" role="img" aria-label="Service operations and job orders by period">
                <div class="front-report-y-axis">
                    <span><?php echo number_format((int) $activity_max); ?></span>
                    <span><?php echo number_format($activity_max / 2, 1); ?></span>
                    <span>0</span>
                </div>
                <div class="front-report-bar-plot">
                    <div class="front-report-grid-lines"></div>
                    <?php foreach ($periods as $period): ?>
                        <div class="front-report-bar-group">
                            <div class="front-report-bar-pair">
                                <span class="front-report-bar bar-service" style="height: <?php echo front_reports_percent($period['service_operations'], $activity_max); ?>%" title="<?php echo esc_attr($period['label'] . ' service operations: ' . $period['service_operations']); ?>">
                                    <b><?php echo (int) $period['service_operations']; ?></b>
                                </span>
                                <span class="front-report-bar bar-job" style="height: <?php echo front_reports_percent($period['job_orders'], $activity_max); ?>%" title="<?php echo esc_attr($period['label'] . ' job orders: ' . $period['job_orders']); ?>">
                                    <b><?php echo (int) $period['job_orders']; ?></b>
                                </span>
                            </div>
                            <p><?php echo esc_html($period['label']); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="reports-chart-legend">
                <span><i class="legend-quotes"></i> Service Operations</span>
                <span><i class="legend-jobs"></i> Job Orders</span>
            </div>
            <p class="front-report-chart-note">
                Total service operations: <?php echo number_format((int) $activity_service_total); ?>.
                Total job orders: <?php echo number_format((int) $activity_job_total); ?>.
            </p>
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
            </div>
        </details>
    <?php endif; ?>

    <?php if ($has_inventory_access && $report_tab === 'items'): ?>
        <details class="reports-support-details reports-inventory-analytics" id="inventory-analytics">
            <summary class="reports-support-summary">
                <span class="reports-support-title"><i class="fas fa-boxes-stacked"></i><strong>Inventory Movement Analytics</strong></span>
                <span class="reports-support-count"><?php echo number_format((int) ($inventory_summary['stock_in_units'] + $inventory_summary['stock_out_units'])); ?> units moved</span>
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
                    <strong><?php echo front_reports_money($inventory_summary['stock_out_value']); ?></strong>
                </div>
                <span class="reports-summary-icon icon-purple"><i class="fas fa-boxes-stacked"></i></span>
            </article>
        </section>

        <section class="reports-chart-grid">
            <article class="reports-panel">
                <h2>Inventory Movement by Category</h2>
                <div class="front-report-chart-details">
                    <span>Range: <?php echo esc_html(front_reports_short_date($date_from)); ?> to <?php echo esc_html(front_reports_short_date($date_to)); ?></span>
                    <span>Scale: units moved</span>
                    <span>Highest bar: <?php echo number_format((int) $inventory_category_max); ?> units</span>
                    <span>Stock In = units added</span>
                    <span>Stock Out = units used, removed, or transferred out</span>
                </div>
                <div class="front-report-bar-chart" role="img" aria-label="Stock in and stock out by inventory category">
                    <div class="front-report-y-axis">
                        <span><?php echo number_format((int) $inventory_category_max); ?></span>
                        <span><?php echo number_format($inventory_category_max / 2, 1); ?></span>
                        <span>0</span>
                    </div>
                    <div class="front-report-bar-plot">
                        <div class="front-report-grid-lines"></div>
                        <?php foreach ($inventory_category_movement as $category): ?>
                            <div class="front-report-bar-group">
                                <div class="front-report-bar-pair">
                                    <span class="front-report-bar bar-stock-in" style="height: <?php echo front_reports_percent($category['stock_in'], $inventory_category_max); ?>%" title="<?php echo esc_attr($category['label'] . ' stock in: ' . number_format((int) $category['stock_in']) . ' units'); ?>">
                                        <b><?php echo number_format((int) $category['stock_in']); ?></b>
                                    </span>
                                    <span class="front-report-bar bar-stock-out" style="height: <?php echo front_reports_percent($category['stock_out'], $inventory_category_max); ?>%" title="<?php echo esc_attr($category['label'] . ' stock out: ' . number_format((int) $category['stock_out']) . ' units'); ?>">
                                        <b><?php echo number_format((int) $category['stock_out']); ?></b>
                                    </span>
                                </div>
                                <p><?php echo esc_html($category['label']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="reports-chart-legend">
                    <span><i class="legend-stock-in"></i> Stock In: added to inventory</span>
                    <span><i class="legend-stock-out"></i> Stock Out: used, removed, or transferred out</span>
                </div>
                <p class="front-report-chart-note">
                    Total stock in: <?php echo number_format((int) $inventory_stock_in_total); ?> units.
                    Total stock out: <?php echo number_format((int) $inventory_stock_out_total); ?> units.
                </p>
                <div class="inventory-category-breakdown compact">
                    <?php foreach ($inventory_category_movement as $category): ?>
                        <div class="inventory-category-stat">
                            <strong><?php echo esc_html($category['label']); ?></strong>
                            <span>In <?php echo number_format((int) $category['stock_in']); ?> / Out <?php echo number_format((int) $category['stock_out']); ?></span>
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
                                    <h3><?php echo esc_html($item['item_name'] ?? '-'); ?></h3>
                                    <p>
                                        <?php echo esc_html($item['brand'] ?? '-'); ?>
                                        <?php if (!empty($item['size'])): ?>
                                            &bull; <?php echo esc_html($item['size']); ?>
                                        <?php endif; ?>
                                    </p>
                                    <span class="reports-category category-<?php echo esc_attr($item['category'] ?? 'part'); ?>">
                                        <?php echo esc_html(front_reports_category_label($item['category'] ?? 'part')); ?>
                                    </span>
                                </div>
                                <div class="reports-inventory-counts">
                                    <span class="movement-in">In <?php echo number_format((int) ($item['stock_in_units'] ?? 0)); ?></span>
                                    <span class="movement-out">Out <?php echo number_format((int) ($item['stock_out_units'] ?? 0)); ?></span>
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

    <?php if ($report_tab === 'services'): ?>
        <?php $front_recent_count = count($recent_quotations) + count($recent_jobs); ?>
        <details class="reports-support-details reports-recent-activity" id="recent-activity">
            <summary class="reports-support-summary">
                <span class="reports-support-title"><i class="fas fa-clock-rotate-left"></i><strong>Recent Activity</strong></span>
                <span class="reports-support-count"><?php echo number_format($front_recent_count); ?> records</span>
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
                        <?php $status_class = front_reports_status_class($quotation['status'] ?? 'pending'); ?>
                        <div class="reports-recent-card">
                            <div>
                                <h3><?php echo esc_html($quotation['customer_name'] ?? '-'); ?></h3>
                                <p>
                                    <?php echo esc_html(trim(($quotation['make'] ?? '') . ' ' . ($quotation['model'] ?? ''))); ?>
                                    <?php if (!empty($quotation['plate_number'])): ?>
                                        (<?php echo esc_html($quotation['plate_number']); ?>)
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div>
                                <span class="reports-status-pill status-<?php echo esc_attr($status_class); ?>">
                                    <?php echo esc_html(front_reports_status_label($quotation['status'] ?? '')); ?>
                                </span>
                                <strong><?php echo front_reports_money($quotation['total_amount'] ?? 0); ?></strong>
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
                        <?php $status_class = front_reports_status_class($job['status'] ?? 'waiting'); ?>
                        <div class="reports-recent-card">
                            <div>
                                <h3><?php echo esc_html($job['customer_name'] ?? '-'); ?></h3>
                                <p>
                                    <?php echo esc_html(trim(($job['make'] ?? '') . ' ' . ($job['model'] ?? ''))); ?>
                                    <?php if (!empty($job['plate_number'])): ?>
                                        (<?php echo esc_html($job['plate_number']); ?>)
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div>
                                <span class="reports-status-pill status-<?php echo esc_attr($status_class); ?>">
                                    <?php echo esc_html(front_reports_status_label($job['status'] ?? '')); ?>
                                </span>
                                <strong><?php echo esc_html(front_reports_short_date($job['job_date'] ?? '')); ?></strong>
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
