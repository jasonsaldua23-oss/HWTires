<?php
/**
 * Inventory forecasting and decision support helpers.
 */

if (!function_exists('forecast_money')) {
    function forecast_money($amount, $decimals = 0) {
        return '&#8369;' . number_format((float) $amount, $decimals);
    }
}

if (!function_exists('forecast_branch_label')) {
    function forecast_branch_label($branch_name) {
        return app_branch_label($branch_name, 'Branch');
    }
}

if (!function_exists('forecast_category_label')) {
    function forecast_category_label($category, $plural = false) {
        $labels = [
            'tire' => $plural ? 'Tires' : 'Tire',
            'accessory' => $plural ? 'Accessories' : 'Accessory',
            'part' => $plural ? 'Parts' : 'Part',
        ];

        return $labels[$category] ?? ($plural ? 'All Items' : 'Item');
    }
}

if (!function_exists('forecast_status_label')) {
    function forecast_status_label($status) {
        return [
            'critical' => 'Critical',
            'warning' => 'Warning',
            'watch' => 'Watch',
            'good' => 'Good',
        ][$status] ?? 'Good';
    }
}

if (!function_exists('forecast_view_label')) {
    function forecast_view_label($view) {
        return [
            'weekly' => 'Weekly Forecast',
            'monthly' => 'Monthly Forecast',
            'items' => 'Item Forecast',
        ][$view] ?? 'Weekly Forecast';
    }
}

if (!function_exists('forecast_duration_human')) {
    function forecast_duration_human($duration_in_weeks, $current_stock = null) {
        if ($current_stock !== null && (int) $current_stock <= 0) {
            return 'Out of stock';
        }
        if ($duration_in_weeks === null || $duration_in_weeks === '' || !is_numeric($duration_in_weeks)) {
            return 'No movement data';
        }
        $weeks = (float) $duration_in_weeks;
        if ($weeks <= 0) {
            return 'Out of stock';
        }
        if ($weeks < 2.0) {
            $days = max(1, (int) round($weeks * 7));
            return $days === 1 ? '1 day left' : $days . ' days left';
        }
        return number_format($weeks, 1) . ' weeks left';
    }
}

if (!function_exists('forecast_duration_human_months')) {
    function forecast_duration_human_months($duration_in_months, $current_stock = null) {
        if ($current_stock !== null && (int) $current_stock <= 0) {
            return 'Out of stock';
        }
        if ($duration_in_months === null || $duration_in_months === '' || !is_numeric($duration_in_months)) {
            return 'No movement data';
        }
        $months = (float) $duration_in_months;
        if ($months <= 0) {
            return 'Out of stock';
        }
        if ($months < 0.5) {
            $days = max(1, (int) round($months * 30));
            return $days === 1 ? '1 day left' : $days . ' days left';
        }
        if ($months < 2.0) {
            $weeks = max(1, (int) round($months * 4.3));
            return $weeks === 1 ? '1 week left' : $weeks . ' weeks left';
        }
        return number_format($months, 1) . ' months left';
    }
}

if (!function_exists('forecast_filter_url')) {
    function forecast_filter_url($category, $branch, $status, $search = '', $per_page = null, $page = null, $year = 'latest', $view = 'weekly', $sort = 'urgency', $brand = '', $size = '') {
        $query = [];

        if ($category !== 'all' && $category !== '') {
            $query['category'] = $category;
        }

        $brand = trim((string) $brand);
        if ($brand !== '') {
            $query['brand'] = $brand;
        }

        $size = trim((string) $size);
        if ($size !== '') {
            $query['size'] = $size;
        }

        if ($branch !== 'all' && $branch !== '') {
            $query['branch'] = $branch;
        }

        if ($status !== 'all' && $status !== '') {
            $query['status'] = $status;
        }

        $sort = strtolower(trim((string) $sort));
        if ($sort !== '' && $sort !== 'urgency') {
            $query['sort'] = $sort;
        }

        $year = trim((string) $year);
        if ($year !== '' && $year !== 'latest') {
            $query['year'] = $year;
        }

        $view = strtolower(trim((string) $view));
        if (in_array($view, ['monthly', 'items'], true)) {
            $query['view'] = $view;
        }

        $search = trim((string) $search);
        if ($search !== '') {
            $query['search'] = $search;
        }

        $per_page = (int) $per_page;
        if (in_array($per_page, [10, 20, 50], true)) {
            $query['per_page'] = $per_page;
        }

        $page = (int) $page;
        if ($page > 1) {
            $query['page'] = $page;
        }

        return empty($query) ? './' : '?' . http_build_query($query);
    }
}

if (!function_exists('forecast_load_transaction_years')) {
    function forecast_load_transaction_years(PDO $pdo, array $allowed_branch_ids) {
        $allowed_branch_ids = array_values(array_unique(array_map('intval', $allowed_branch_ids)));
        if (empty($allowed_branch_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($allowed_branch_ids), '?'));
        $stmt = $pdo->prepare("
            SELECT DISTINCT YEAR(t.created_at) AS forecast_year
            FROM inventory_transactions t
            INNER JOIN inventory_items i ON i.id = t.item_id
            WHERE LOWER(REPLACE(t.transaction_type, ' ', '_')) = 'stock_out'
              AND (t.reference_type IS NULL OR t.reference_type <> 'branch_transfer')
              AND i.branch_id IN ($placeholders)
            ORDER BY forecast_year DESC
        ");
        $stmt->execute($allowed_branch_ids);

        return array_values(array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }
}

if (!function_exists('forecast_load_inventory_branches')) {
    function forecast_load_inventory_branches(PDO $pdo, array $user = null) {
        $query = "SELECT id, name FROM branches WHERE status = 'active' AND has_inventory = 1";
        $params = [];

        if ($user && ($user['role'] ?? '') !== 'admin') {
            $query .= " AND id = ?";
            $params[] = (int) ($user['branch_id'] ?? 0);
        }

        $query .= " ORDER BY id";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}

if (!function_exists('forecast_normalized_key')) {
    function forecast_normalized_key($item) {
        $category = strtolower((string) ($item['category'] ?? ''));

        if ($category === 'tire' && !empty($item['size'])) {
            return 'tire:' . strtolower(trim((string) $item['size']));
        }

        return $category . ':' . preg_replace('/\s+/', ' ', strtolower(trim((string) ($item['item_name'] ?? ''))));
    }
}

if (!function_exists('forecast_build_inventory_dss')) {
    function forecast_build_inventory_dss(PDO $pdo, array $options = []) {
        $category_filter = strtolower(trim($options['category'] ?? 'all'));
        $brand_filter = trim((string) ($options['brand'] ?? ''));
        $size_filter = trim((string) ($options['size'] ?? ''));
        $branch_filter = trim((string) ($options['branch'] ?? 'all'));
        $status_filter = strtolower(trim($options['status'] ?? 'all'));
        $search_filter = trim((string) ($options['search'] ?? ''));
        $year_filter = trim((string) ($options['year'] ?? 'latest'));
        $view_filter = strtolower(trim((string) ($options['view'] ?? 'weekly')));
        $valid_sorts = ['urgency', 'demand', 'growth', 'stock_asc', 'name'];
        $sort_filter = strtolower(trim((string) ($options['sort'] ?? 'urgency')));
        if (!in_array($sort_filter, $valid_sorts, true)) {
            $sort_filter = 'urgency';
        }
        $allowed_branch_ids = array_values(array_unique(array_map('intval', $options['allowed_branch_ids'] ?? [])));

        if (function_exists('mb_substr')) {
            $search_filter = mb_substr($search_filter, 0, 100);
        } else {
            $search_filter = substr($search_filter, 0, 100);
        }

        if (!in_array($category_filter, ['all', 'tire', 'accessory', 'part'], true)) {
            $category_filter = 'all';
        }

        if (!in_array($status_filter, ['all', 'critical', 'warning', 'watch', 'good'], true)) {
            $status_filter = 'all';
        }

        if (!in_array($view_filter, ['weekly', 'monthly', 'items'], true)) {
            $view_filter = 'weekly';
        }

        if ($branch_filter === '') {
            $branch_filter = 'all';
        }

        if ($year_filter === '' || $year_filter === 'all') {
            $year_filter = 'latest';
        }

        if ($year_filter !== 'latest') {
            $year_candidate = (int) $year_filter;
            $year_filter = ($year_candidate >= 2020 && $year_candidate <= 2100) ? (string) $year_candidate : 'latest';
        }

        if ($branch_filter !== 'all') {
            $branch_candidate = (int) $branch_filter;
            $branch_filter = in_array($branch_candidate, $allowed_branch_ids, true) ? (string) $branch_candidate : 'all';
        }

        $where = ["i.status = 'active'"];
        $params = [];

        if (empty($allowed_branch_ids)) {
            $where[] = '1 = 0';
        } elseif ($branch_filter !== 'all') {
            $where[] = 'i.branch_id = ?';
            $params[] = (int) $branch_filter;
        } else {
            $where[] = 'i.branch_id IN (' . implode(',', array_fill(0, count($allowed_branch_ids), '?')) . ')';
            $params = array_merge($params, $allowed_branch_ids);
        }

        if ($category_filter !== 'all') {
            $where[] = 'i.category = ?';
            $params[] = $category_filter;
        }

        if ($brand_filter !== '') {
            $where[] = 'i.brand = ?';
            $params[] = $brand_filter;
        }

        if ($size_filter !== '') {
            $where[] = '(i.size = ? OR i.item_name LIKE ?)';
            $params[] = $size_filter;
            $params[] = '%' . $size_filter . '%';
        }

        if ($search_filter !== '') {
            foreach (app_search_terms($search_filter) as $term) {
                $where[] = "(
                    i.item_name LIKE ?
                    OR i.brand LIKE ?
                    OR i.size LIKE ?
                    OR i.sku LIKE ?
                    OR i.description LIKE ?
                    OR i.category LIKE ?
                    OR b.name LIKE ?
                )";
                $search_like = '%' . $term . '%';
                $params = array_merge($params, array_fill(0, 7, $search_like));
            }
        }

        $stmt = $pdo->prepare("
            SELECT i.*, b.name AS branch_name
            FROM inventory_items i
            LEFT JOIN branches b ON b.id = i.branch_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY i.branch_id ASC, FIELD(i.category, 'tire', 'accessory', 'part'), i.item_name ASC
        ");
        $stmt->execute($params);
        $items = $stmt->fetchAll();

        $item_ids = array_map(static function ($item) {
            return (int) $item['id'];
        }, $items);

        $history_by_item = [];
        $analysis_date = date('Y-m-d H:i:s');
        if (!empty($item_ids)) {
            $history_placeholders = implode(',', array_fill(0, count($item_ids), '?'));
            if ($year_filter !== 'latest') {
                $anchor_stmt = $pdo->prepare("
                    SELECT COALESCE(MAX(created_at), NOW()) AS analysis_date
                    FROM inventory_transactions
                    WHERE LOWER(REPLACE(transaction_type, ' ', '_')) = 'stock_out'
                      AND (reference_type IS NULL OR reference_type <> 'branch_transfer')
                      AND item_id IN ($history_placeholders)
                      AND YEAR(created_at) = ?
                ");
                $anchor_stmt->execute(array_merge($item_ids, [(int) $year_filter]));
            } else {
                $anchor_stmt = $pdo->prepare("
                    SELECT COALESCE(MAX(created_at), NOW()) AS analysis_date
                    FROM inventory_transactions
                    WHERE LOWER(REPLACE(transaction_type, ' ', '_')) = 'stock_out'
                      AND (reference_type IS NULL OR reference_type <> 'branch_transfer')
                      AND item_id IN ($history_placeholders)
                ");
                $anchor_stmt->execute($item_ids);
            }
            $analysis_date = $anchor_stmt->fetchColumn() ?: date('Y-m-d H:i:s');

            $history_stmt = $pdo->prepare("
                SELECT
                    item_id,
                    SUM(CASE WHEN LOWER(REPLACE(transaction_type, ' ', '_')) = 'stock_out' AND (reference_type IS NULL OR reference_type <> 'branch_transfer') AND created_at > DATE_SUB(?, INTERVAL 30 DAY) AND created_at <= ? THEN ABS(quantity) ELSE 0 END) AS recent_out,
                    SUM(CASE WHEN LOWER(REPLACE(transaction_type, ' ', '_')) = 'stock_out' AND (reference_type IS NULL OR reference_type <> 'branch_transfer') AND created_at > DATE_SUB(?, INTERVAL 60 DAY) AND created_at <= DATE_SUB(?, INTERVAL 30 DAY) THEN ABS(quantity) ELSE 0 END) AS previous_out,
                    SUM(CASE WHEN LOWER(REPLACE(transaction_type, ' ', '_')) = 'stock_out' AND (reference_type IS NULL OR reference_type <> 'branch_transfer') AND created_at > DATE_SUB(?, INTERVAL 90 DAY) AND created_at <= ? THEN ABS(quantity) ELSE 0 END) AS basis_out,
                    SUM(CASE WHEN LOWER(REPLACE(transaction_type, ' ', '_')) = 'stock_in' AND created_at > DATE_SUB(?, INTERVAL 90 DAY) AND created_at <= ? THEN ABS(quantity) ELSE 0 END) AS basis_in,
                    COUNT(CASE WHEN LOWER(REPLACE(transaction_type, ' ', '_')) = 'stock_out' AND (reference_type IS NULL OR reference_type <> 'branch_transfer') AND created_at > DATE_SUB(?, INTERVAL 90 DAY) AND created_at <= ? THEN 1 END) AS basis_transactions
                FROM inventory_transactions
                WHERE item_id IN ($history_placeholders)
                GROUP BY item_id
            ");
            $history_stmt->execute(array_merge(
                [
                    $analysis_date,
                    $analysis_date,
                    $analysis_date,
                    $analysis_date,
                    $analysis_date,
                    $analysis_date,
                    $analysis_date,
                    $analysis_date,
                    $analysis_date,
                    $analysis_date,
                ],
                $item_ids
            ));

            foreach ($history_stmt->fetchAll() as $history) {
                $history_by_item[(int) $history['item_id']] = $history;
            }
        }

        $forecast_items = [];
        $base_items = [];
        $summary = [
            'critical' => 0,
            'warning' => 0,
            'watch' => 0,
            'good' => 0,
            'total' => 0,
            'reorder_value' => 0,
            'growing' => 0,
            'declining' => 0,
            'no_history' => 0,
            'weekly_reorder_units' => 0,
            'monthly_reorder_units' => 0,
            'monthly_demand_units' => 0,
            'recent_out_units' => 0,
            'previous_out_units' => 0,
            'basis_out_units' => 0,
            'transfer_matches' => 0,
        ];

        foreach ($items as $item) {
            $item_id = (int) $item['id'];
            $history = $history_by_item[$item_id] ?? [
                'recent_out' => 0,
                'previous_out' => 0,
                'basis_out' => 0,
                'basis_in' => 0,
                'basis_transactions' => 0,
            ];
            $recent_out = (float) ($history['recent_out'] ?? 0);
            $previous_out = (float) ($history['previous_out'] ?? 0);
            $basis_out = (float) ($history['basis_out'] ?? 0);
            $basis_in = (float) ($history['basis_in'] ?? 0);
            $weekly_usage = round($basis_out / (90 / 7), 1);
            $previous_weekly = round($previous_out / (30 / 7), 1);
            $monthly_usage = round($basis_out / 3, 1);
            $current_stock = (int) ($item['quantity'] ?? 0);
            $reorder_level = max(0, (int) ($item['reorder_level'] ?? 0));
            $unit_price = (float) ($item['unit_price'] ?? 0);
            $stock_duration = $weekly_usage > 0 ? round($current_stock / $weekly_usage, 1) : null;
            $months_duration = $monthly_usage > 0 ? round($current_stock / $monthly_usage, 1) : null;

            if ($weekly_usage > 0 && $stock_duration < 2) {
                $status = 'critical';
            } elseif ($current_stock <= $reorder_level) {
                $status = 'warning';
            } elseif ($weekly_usage > 0 && $stock_duration < 4) {
                $status = 'watch';
            } else {
                $status = 'good';
            }

            if ($previous_out > 0) {
                $trend_percent = (int) round((($recent_out - $previous_out) / $previous_out) * 100);
            } elseif ($recent_out > 0) {
                $trend_percent = 100;
            } else {
                $trend_percent = 0;
            }

            $coverage_weeks = $status === 'critical' ? 3 : ($status === 'warning' ? 2 : 0);
            $coverage_target = $weekly_usage > 0 ? (int) ceil($weekly_usage * $coverage_weeks) : $reorder_level;
            $recommended_order = max(0, $coverage_target - $current_stock, $reorder_level - $current_stock);

            if ($status === 'good' || $status === 'watch') {
                $recommended_order = max(0, $reorder_level - $current_stock);
            }

            $order_value = $recommended_order * $unit_price;
            $monthly_coverage_months = $status === 'critical' ? 1.0 : ($status === 'warning' ? 0.75 : 0.5);
            $monthly_target = $monthly_usage > 0 ? (int) ceil($monthly_usage * $monthly_coverage_months) : $reorder_level;
            $recommended_monthly_order = max(0, $monthly_target - $current_stock, $reorder_level - $current_stock);

            if ($status === 'good' || $status === 'watch') {
                $recommended_monthly_order = max(0, $reorder_level - $current_stock);
            }

            $projected_stock_30 = max(0, $current_stock - (int) ceil($monthly_usage));
            $projected_stock_60 = max(0, $current_stock - (int) ceil($monthly_usage * 2));

            $duration_human = forecast_duration_human($stock_duration, $current_stock);
            $months_duration_human = forecast_duration_human_months($months_duration, $current_stock);

            if ($current_stock <= 0) {
                $projected_stock_text = 'Out of stock';
            } elseif ($stock_duration !== null && $stock_duration < 1.0) {
                $days_left = max(1, (int) round($stock_duration * 7));
                $projected_stock_text = 'Depleted in ~' . $days_left . ($days_left === 1 ? ' day' : ' days');
            } elseif ($projected_stock_30 <= 0) {
                $projected_stock_text = 'Depleted in < 30 days';
            } else {
                $projected_stock_text = $projected_stock_30 . ' units (30d) / ' . $projected_stock_60 . ' units (60d)';
            }

            if ($weekly_usage > 0 && $stock_duration !== null) {
                $forecast_reason = 'Based on about ' . number_format($weekly_usage, 1) . ' units/week from the last 90 days.';
            } elseif ($current_stock <= $reorder_level) {
                $forecast_reason = 'No recent usage, but stock is at or below reorder level.';
            } else {
                $forecast_reason = 'No recent stock-out movement in the selected period.';
            }

            $forecast = [
                'item' => $item,
                'key' => forecast_normalized_key($item),
                'status' => $status,
                'weekly_usage' => $weekly_usage,
                'previous_weekly_usage' => $previous_weekly,
                'monthly_usage' => $monthly_usage,
                'stock_duration' => $stock_duration,
                'months_duration' => $months_duration,
                'duration_human' => $duration_human,
                'months_duration_human' => $months_duration_human,
                'trend_percent' => $trend_percent,
                'trend_direction' => $trend_percent > 0 ? 'up' : ($trend_percent < 0 ? 'down' : 'flat'),
                'recommended_order' => $recommended_order,
                'recommended_monthly_order' => $recommended_monthly_order,
                'order_value' => $order_value,
                'monthly_order_value' => $recommended_monthly_order * $unit_price,
                'recent_out' => $recent_out,
                'previous_out' => $previous_out,
                'basis_out' => $basis_out,
                'basis_in' => $basis_in,
                'projected_stock_30' => $projected_stock_30,
                'projected_stock_60' => $projected_stock_60,
                'projected_stock_text' => $projected_stock_text,
                'forecast_reason' => $forecast_reason,
                'has_history' => (int) ($history['basis_transactions'] ?? 0) > 0,
            ];

            $base_items[] = $forecast;
            $summary[$status]++;
            $summary['total']++;

            if (in_array($status, ['critical', 'warning'], true)) {
                $summary['reorder_value'] += $order_value;
            }

            $summary['weekly_reorder_units'] += (int) $recommended_order;
            $summary['monthly_reorder_units'] += (int) $recommended_monthly_order;
            $summary['monthly_demand_units'] += $monthly_usage;
            $summary['recent_out_units'] += $recent_out;
            $summary['previous_out_units'] += $previous_out;
            $summary['basis_out_units'] += $basis_out;

            if ($trend_percent > 5 && $weekly_usage > 0) {
                $summary['growing']++;
            } elseif ($trend_percent < -5 && $weekly_usage > 0) {
                $summary['declining']++;
            }

            if (!$forecast['has_history']) {
                $summary['no_history']++;
            }

            if ($status_filter === 'all' || $status_filter === $status) {
                $forecast_items[] = $forecast;
            }
        }

        $summary['monthly_growth_percent'] = $summary['previous_out_units'] > 0
            ? (int) round((($summary['recent_out_units'] - $summary['previous_out_units']) / $summary['previous_out_units']) * 100)
            : ($summary['recent_out_units'] > 0 ? 100 : 0);
        $summary['monthly_demand_units'] = (int) ceil($summary['monthly_demand_units']);
        $summary['basis_out_units'] = (int) ceil($summary['basis_out_units']);

        if ($sort_filter === 'demand') {
            $sorter = static function ($a, $b) {
                $a_demand = (float) ($a['weekly_usage'] ?? 0);
                $b_demand = (float) ($b['weekly_usage'] ?? 0);
                if ($a_demand !== $b_demand) {
                    return $b_demand <=> $a_demand; // Highest demand first
                }
                return strcasecmp($a['item']['item_name'] ?? '', $b['item']['item_name'] ?? '');
            };
        } elseif ($sort_filter === 'growth') {
            $sorter = static function ($a, $b) {
                $a_growth = (int) ($a['trend_percent'] ?? 0);
                $b_growth = (int) ($b['trend_percent'] ?? 0);
                if ($a_growth !== $b_growth) {
                    return $b_growth <=> $a_growth; // Highest growth first
                }
                $a_demand = (float) ($a['weekly_usage'] ?? 0);
                $b_demand = (float) ($b['weekly_usage'] ?? 0);
                if ($a_demand !== $b_demand) {
                    return $b_demand <=> $a_demand;
                }
                return strcasecmp($a['item']['item_name'] ?? '', $b['item']['item_name'] ?? '');
            };
        } elseif ($sort_filter === 'stock_asc') {
            $sorter = static function ($a, $b) {
                $a_stock = (int) ($a['item']['quantity'] ?? 0);
                $b_stock = (int) ($b['item']['quantity'] ?? 0);
                if ($a_stock !== $b_stock) {
                    return $a_stock <=> $b_stock; // Lowest stock first
                }
                return strcasecmp($a['item']['item_name'] ?? '', $b['item']['item_name'] ?? '');
            };
        } elseif ($sort_filter === 'name') {
            $sorter = static function ($a, $b) {
                return strcasecmp($a['item']['item_name'] ?? '', $b['item']['item_name'] ?? '');
            };
        } else {
            $sorter = static function ($a, $b) {
                $rank = ['critical' => 0, 'warning' => 1, 'watch' => 2, 'good' => 3];
                $status_diff = ($rank[$a['status']] ?? 4) <=> ($rank[$b['status']] ?? 4);
                if ($status_diff !== 0) {
                    return $status_diff;
                }

                $a_duration = $a['stock_duration'] ?? 999999;
                $b_duration = $b['stock_duration'] ?? 999999;
                if ($a_duration !== $b_duration) {
                    return $a_duration <=> $b_duration;
                }

                return strcasecmp($a['item']['item_name'] ?? '', $b['item']['item_name'] ?? '');
            };
        }

        $urgency_sorter = static function ($a, $b) {
            $rank = ['critical' => 0, 'warning' => 1, 'watch' => 2, 'good' => 3];
            $status_diff = ($rank[$a['status']] ?? 4) <=> ($rank[$b['status']] ?? 4);
            if ($status_diff !== 0) {
                return $status_diff;
            }

            $a_duration = $a['stock_duration'] ?? 999999;
            $b_duration = $b['stock_duration'] ?? 999999;
            if ($a_duration !== $b_duration) {
                return $a_duration <=> $b_duration;
            }

            return strcasecmp($a['item']['item_name'] ?? '', $b['item']['item_name'] ?? '');
        };

        usort($forecast_items, $sorter);
        usort($base_items, $urgency_sorter);

        $high_priority = array_values(array_filter($base_items, static function ($forecast) {
            return $forecast['status'] === 'critical' && $forecast['recommended_order'] > 0;
        }));
        $medium_priority = array_values(array_filter($base_items, static function ($forecast) {
            return $forecast['status'] === 'warning' && $forecast['recommended_order'] > 0;
        }));

        $groups = [];
        foreach ($base_items as $forecast) {
            $groups[$forecast['key']][] = $forecast;
        }

        $transfers = [];
        foreach ($base_items as $shortage) {
            if (!in_array($shortage['status'], ['critical', 'warning'], true) || $shortage['recommended_order'] <= 0) {
                continue;
            }

            $same_items = $groups[$shortage['key']] ?? [];
            $best_donor = null;
            $best_surplus = 0;

            foreach ($same_items as $candidate) {
                if ((int) $candidate['item']['branch_id'] === (int) $shortage['item']['branch_id']) {
                    continue;
                }

                $candidate_weekly = (float) $candidate['weekly_usage'];
                $candidate_floor = max(
                    (int) ($candidate['item']['reorder_level'] ?? 0),
                    $candidate_weekly > 0 ? (int) ceil($candidate_weekly * 3) : 0
                );
                $candidate_surplus = (int) ($candidate['item']['quantity'] ?? 0) - $candidate_floor;

                if ($candidate_surplus > $best_surplus) {
                    $best_surplus = $candidate_surplus;
                    $best_donor = $candidate;
                }
            }

            if ($best_donor && $best_surplus > 0) {
                $quantity = min((int) $shortage['recommended_order'], $best_surplus);

                if ($quantity > 0) {
                    $transfers[] = [
                        'priority' => $shortage['status'] === 'critical' ? 'high' : 'medium',
                        'item' => $shortage['item'],
                        'from' => $best_donor,
                        'to' => $shortage,
                        'quantity' => $quantity,
                    ];
                }
            }
        }

        usort($transfers, static function ($a, $b) {
            $rank = ['high' => 0, 'medium' => 1];
            $priority_diff = ($rank[$a['priority']] ?? 2) <=> ($rank[$b['priority']] ?? 2);
            if ($priority_diff !== 0) {
                return $priority_diff;
            }

            $a_duration = $a['to']['stock_duration'] ?? 999999;
            $b_duration = $b['to']['stock_duration'] ?? 999999;

            return $a_duration <=> $b_duration;
        });

        $summary['transfer_matches'] = count($transfers);

        return [
            'filters' => [
                'category' => $category_filter,
                'branch' => $branch_filter,
                'status' => $status_filter,
                'sort' => $sort_filter,
                'search' => $search_filter,
                'year' => $year_filter,
                'view' => $view_filter,
                'analysis_date' => $analysis_date,
            ],
            'summary' => $summary,
            'items' => $forecast_items,
            'all_items' => $base_items,
            'decision_support' => [
                'high_priority' => array_slice($high_priority, 0, 6),
                'medium_priority' => array_slice($medium_priority, 0, 6),
                'insights' => [
                    'growing' => $summary['growing'],
                    'declining' => $summary['declining'],
                    'optimal' => $summary['good'],
                    'no_history' => $summary['no_history'],
                ],
                'transfers' => array_slice($transfers, 0, 6),
            ],
        ];
    }
}

if (!function_exists('forecast_csv_safe')) {
    function forecast_csv_safe($value) {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_numeric($value)) {
            return $value;
        }
        $str = (string) $value;
        if (isset($str[0]) && in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $str;
        }
        return $str;
    }
}

if (!function_exists('forecast_export_url')) {
    function forecast_export_url($category, $branch, $status, $search = '', $year = 'latest', $view = 'weekly', $sort = 'urgency', $brand = '', $size = '') {
        $params = [
            'action' => 'export_recommendations',
        ];

        if ($category !== 'all' && $category !== '') {
            $params['category'] = $category;
        }

        $brand = trim((string) $brand);
        if ($brand !== '') {
            $params['brand'] = $brand;
        }

        $size = trim((string) $size);
        if ($size !== '') {
            $params['size'] = $size;
        }

        if ($branch !== 'all' && $branch !== '') {
            $params['branch'] = $branch;
        }

        if ($status !== 'all' && $status !== '') {
            $params['status'] = $status;
        }

        $sort = strtolower(trim((string) $sort));
        if ($sort !== '' && $sort !== 'urgency') {
            $params['sort'] = $sort;
        }

        $year = trim((string) $year);
        if ($year !== '' && $year !== 'latest') {
            $params['year'] = $year;
        }

        $view = strtolower(trim((string) $view));
        if (in_array($view, ['monthly', 'items'], true)) {
            $params['view'] = $view;
        }

        $search = trim((string) $search);
        if ($search !== '') {
            $params['search'] = $search;
        }

        return '/hwtires/api/forecasting-api.php?' . http_build_query($params);
    }
}

if (!function_exists('forecast_send_csv')) {
    function forecast_send_csv(array $forecast, $output_target = 'php://output') {
        $filters = $forecast['filters'] ?? [];
        $items = $forecast['items'] ?? [];
        $view_filter = strtolower(trim((string) ($filters['view'] ?? 'weekly')));
        $branch_filter = $filters['branch'] ?? 'all';

        $filename_parts = ['forecasting_recommendations'];
        if ($branch_filter !== 'all') {
            $filename_parts[] = 'branch_' . $branch_filter;
        }
        $filename_parts[] = $view_filter;
        $filename_parts[] = date('Y-m-d');
        $filename = implode('_', $filename_parts) . '.csv';

        if (is_resource($output_target)) {
            $out = $output_target;
        } else {
            if ($output_target === 'php://output' && !headers_sent()) {
                if (ob_get_level()) {
                    ob_end_clean();
                }

                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Pragma: no-cache');
                header('Expires: 0');
            }

            $out = fopen($output_target, 'w');
        }

        // Output UTF-8 Byte Order Mark (BOM) for Excel
        fwrite($out, "\xEF\xBB\xBF");

        // First row contains column headers
        fputcsv($out, [
            'Forecast Status',
            'Item Name',
            'SKU',
            'Category',
            'Brand',
            'Size / Spec',
            'Branch',
            'Current Stock',
            'Reorder Level',
            'Weekly Demand',
            'Monthly Demand',
            'Demand Trend',
            'Stock Runway',
            'Projected Stock',
            'Recommended Order',
            'Unit Price',
            'Estimated Order Value',
            'Suggested Action',
            'Forecast Basis / Reason',
        ]);

        foreach ($items as $item) {
            $inv = $item['item'] ?? [];
            $status = $item['status'] ?? 'good';
            $trend = (int) ($item['trend_percent'] ?? 0);
            $trend_str = ($trend > 0 ? '+' : '') . $trend . '%';

            $runway = ($view_filter === 'monthly')
                ? ($item['months_duration_human'] ?? '')
                : ($item['duration_human'] ?? '');

            $rec_order = ($view_filter === 'monthly')
                ? (int) ($item['recommended_monthly_order'] ?? 0)
                : (int) ($item['recommended_order'] ?? 0);

            $unit_price = (float) ($inv['unit_price'] ?? 0);
            $est_value = ($view_filter === 'monthly')
                ? (float) ($item['monthly_order_value'] ?? 0)
                : (float) ($item['order_value'] ?? 0);

            $suggested_action = '';
            if ($view_filter === 'monthly') {
                $suggested_action = $rec_order > 0 ? 'Procure ' . $rec_order . ' units' : 'Monitor';
            } elseif ($view_filter === 'items') {
                $suggested_action = $rec_order > 0 ? 'Stock in ' . $rec_order . ' units' : 'Monitor';
            } else {
                $suggested_action = $rec_order > 0 ? 'Order ' . $rec_order . ' units' : 'Optimal level';
            }

            fputcsv($out, [
                forecast_csv_safe(forecast_status_label($status)),
                forecast_csv_safe(app_display_item_name($inv['item_name'] ?? '', $inv['category'] ?? null)),
                forecast_csv_safe($inv['sku'] ?: '-'),
                forecast_csv_safe(forecast_category_label($inv['category'] ?? '')),
                forecast_csv_safe($inv['brand'] ?: 'Unbranded'),
                forecast_csv_safe($inv['size'] ?: '-'),
                forecast_csv_safe(forecast_branch_label($inv['branch_name'] ?? '')),
                (int) ($inv['quantity'] ?? 0),
                (int) ($inv['reorder_level'] ?? 0),
                number_format((float) ($item['weekly_usage'] ?? 0), 1, '.', ''),
                number_format((float) ($item['monthly_usage'] ?? 0), 1, '.', ''),
                forecast_csv_safe($trend_str),
                forecast_csv_safe($runway),
                forecast_csv_safe($item['projected_stock_text'] ?? ''),
                $rec_order,
                number_format($unit_price, 2, '.', ''),
                number_format($est_value, 2, '.', ''),
                forecast_csv_safe($suggested_action),
                forecast_csv_safe($item['forecast_reason'] ?? ''),
            ]);
        }

        if (!is_resource($output_target)) {
            fclose($out);
        }
    }
}
?>
