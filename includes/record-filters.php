<?php
/**
 * Shared record date filter helpers.
 */

if (!function_exists('record_date_filter_current')) {
    function record_date_filter_current($default_scope = 'all') {
        $allowed = ['recent', 'day', 'week', 'month', 'year', 'range', 'all'];
        $scope = $_GET['date_scope'] ?? $default_scope;
        if (!in_array($scope, $allowed, true)) {
            $scope = $default_scope;
        }

        $today = date('Y-m-d');
        $current_week = date('o-\WW');
        $current_month = date('Y-m');
        $current_year = (int) date('Y');

        $day = trim((string) ($_GET['date_day'] ?? $today));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $day = $today;
        }

        $week = trim((string) ($_GET['date_week'] ?? $current_week));
        if (!preg_match('/^\d{4}-W\d{2}$/', $week)) {
            $week = $current_week;
        }

        $month = trim((string) ($_GET['date_month'] ?? $current_month));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = $current_month;
        }

        $year = (int) ($_GET['date_year'] ?? $current_year);
        if ($year < 2020 || $year > 2100) {
            $year = $current_year;
        }

        $from = trim((string) ($_GET['date_from'] ?? $day));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = $day;
        }

        $to = trim((string) ($_GET['date_to'] ?? $from));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = $from;
        }

        if (strtotime($from) > strtotime($to)) {
            [$from, $to] = [$to, $from];
        }

        return [
            'scope' => $scope,
            'day' => $day,
            'week' => $week,
            'month' => $month,
            'year' => $year,
            'from' => $from,
            'to' => $to,
        ];
    }
}

if (!function_exists('record_archive_filter_options')) {
    function record_archive_filter_options() {
        return [
            'active' => 'Active Records',
            'archived' => 'Archived Records',
            'all' => 'All Records',
        ];
    }
}

if (!function_exists('record_archive_filter_current')) {
    function record_archive_filter_current($value = null) {
        $filter = strtolower(trim((string) ($value ?? ($_GET['records'] ?? 'active'))));
        return array_key_exists($filter, record_archive_filter_options()) ? $filter : 'active';
    }
}

if (!function_exists('record_archive_filter_query_param')) {
    function record_archive_filter_query_param($filter) {
        $filter = record_archive_filter_current($filter);
        return $filter === 'active' ? [] : ['records' => $filter];
    }
}

if (!function_exists('record_date_filter_query_params')) {
    function record_date_filter_query_params(array $filter) {
        $scope = $filter['scope'] ?? 'all';
        $params = ['date_scope' => $scope];

        if ($scope === 'day') {
            $params['date_day'] = $filter['day'] ?? date('Y-m-d');
        } elseif ($scope === 'week') {
            $params['date_week'] = $filter['week'] ?? date('o-\WW');
        } elseif ($scope === 'month') {
            $params['date_month'] = $filter['month'] ?? date('Y-m');
        } elseif ($scope === 'year') {
            $params['date_year'] = (int) ($filter['year'] ?? date('Y'));
        } elseif ($scope === 'range') {
            $params['date_from'] = $filter['from'] ?? date('Y-m-d');
            $params['date_to'] = $filter['to'] ?? ($filter['from'] ?? date('Y-m-d'));
        } elseif ($scope === 'all') {
            $params = ['date_scope' => 'all'];
        }

        return $params;
    }
}

if (!function_exists('record_date_filter_query_string')) {
    function record_date_filter_query_string(array $filter, $prefix = '&') {
        $query = http_build_query(record_date_filter_query_params($filter));
        return $query === '' ? '' : $prefix . $query;
    }
}

if (!function_exists('record_date_filter_valid_date')) {
    function record_date_filter_valid_date($date, $fallback = null) {
        $date = trim((string) $date);
        $fallback = $fallback ?: date('Y-m-d');
        $parsed = DateTime::createFromFormat('Y-m-d', $date);

        return $parsed && $parsed->format('Y-m-d') === $date ? $date : $fallback;
    }
}

if (!function_exists('record_date_filter_range')) {
    function record_date_filter_range(array $filter) {
        $scope = $filter['scope'] ?? 'all';
        $today = date('Y-m-d');

        if ($scope === 'all') {
            return ['from' => null, 'to' => null];
        }

        if ($scope === 'day') {
            $day = record_date_filter_valid_date($filter['day'] ?? $today, $today);
            return ['from' => $day, 'to' => $day];
        }

        if ($scope === 'week') {
            [$year, $week] = explode('-W', $filter['week'] ?? date('o-\WW'));
            $start = new DateTime();
            $start->setISODate((int) $year, (int) $week, 1);
            $end = clone $start;
            $end->modify('+6 days');
            return ['from' => $start->format('Y-m-d'), 'to' => $end->format('Y-m-d')];
        }

        if ($scope === 'month') {
            $month = preg_match('/^\d{4}-\d{2}$/', (string) ($filter['month'] ?? ''))
                ? (string) $filter['month']
                : date('Y-m');
            return [
                'from' => date('Y-m-01', strtotime($month . '-01')),
                'to' => date('Y-m-t', strtotime($month . '-01')),
            ];
        }

        if ($scope === 'year') {
            $year = (int) ($filter['year'] ?? date('Y'));
            $year = max(2020, min(2100, $year));
            return ['from' => $year . '-01-01', 'to' => $year . '-12-31'];
        }

        if ($scope === 'range') {
            $from = record_date_filter_valid_date($filter['from'] ?? $today, $today);
            $to = record_date_filter_valid_date($filter['to'] ?? $from, $from);
            if (strtotime($from) > strtotime($to)) {
                [$from, $to] = [$to, $from];
            }

            return ['from' => $from, 'to' => $to];
        }

        $start = new DateTime('monday this week');
        $end = clone $start;
        $end->modify('+6 days');
        return ['from' => $start->format('Y-m-d'), 'to' => $end->format('Y-m-d')];
    }
}

if (!function_exists('record_date_range_label')) {
    function record_date_range_label($from, $to, $all = false) {
        if ($all) {
            return 'All records';
        }

        $from = record_date_filter_valid_date($from);
        $to = record_date_filter_valid_date($to, $from);
        if (strtotime($from) > strtotime($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($from === $to) {
            return date('F j, Y', strtotime($from));
        }

        return date('M j, Y', strtotime($from)) . ' - ' . date('M j, Y', strtotime($to));
    }
}

if (!function_exists('record_date_filter_condition')) {
    function record_date_filter_condition($column, array $filter, array &$params, $column_type = 'event_timestamp') {
        $scope = $filter['scope'] ?? 'all';

        if ($scope === 'all') {
            return '';
        }

        $manila = new DateTimeZone('Asia/Manila');
        $utc = new DateTimeZone('UTC');

        // Check if column is an event timestamp (stored in UTC) or a DATE-only column
        if ($column_type === 'event_timestamp') {
            if ($scope === 'day') {
                $day = record_date_filter_valid_date($filter['day'] ?? date('Y-m-d'));
                $start = new DateTimeImmutable($day . ' 00:00:00', $manila);
                $params[] = $start->setTimezone($utc)->format('Y-m-d H:i:s');
                $params[] = $start->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
                return "$column >= ? AND $column < ?";
            }

            if ($scope === 'week') {
                $week_str = $filter['week'] ?? date('o-\WW');
                if (strpos($week_str, '-W') !== false) {
                    [$year, $week] = explode('-W', $week_str);
                } else {
                    $year = date('o');
                    $week = date('W');
                }
                $start = (new DateTimeImmutable('now', $manila))
                    ->setISODate((int) $year, (int) $week, 1)
                    ->setTime(0, 0, 0);
                $params[] = $start->setTimezone($utc)->format('Y-m-d H:i:s');
                $params[] = $start->modify('+7 days')->setTimezone($utc)->format('Y-m-d H:i:s');
                return "$column >= ? AND $column < ?";
            }

            if ($scope === 'month') {
                $month = preg_match('/^\d{4}-\d{2}$/', (string) ($filter['month'] ?? ''))
                    ? (string) $filter['month']
                    : date('Y-m');
                $start = new DateTimeImmutable($month . '-01 00:00:00', $manila);
                $params[] = $start->setTimezone($utc)->format('Y-m-d H:i:s');
                $params[] = $start->modify('+1 month')->setTimezone($utc)->format('Y-m-d H:i:s');
                return "$column >= ? AND $column < ?";
            }

            if ($scope === 'year') {
                $year = (int) ($filter['year'] ?? date('Y'));
                $year = max(2020, min(2100, $year));
                $start = new DateTimeImmutable($year . '-01-01 00:00:00', $manila);
                $params[] = $start->setTimezone($utc)->format('Y-m-d H:i:s');
                $params[] = $start->modify('+1 year')->setTimezone($utc)->format('Y-m-d H:i:s');
                return "$column >= ? AND $column < ?";
            }

            if ($scope === 'range') {
                $from = record_date_filter_valid_date($filter['from'] ?? date('Y-m-d'));
                $to = record_date_filter_valid_date($filter['to'] ?? $from, $from);
                if (strtotime($from) > strtotime($to)) {
                    [$from, $to] = [$to, $from];
                }
                $start = new DateTimeImmutable($from . ' 00:00:00', $manila);
                $end = new DateTimeImmutable($to . ' 00:00:00', $manila);
                $params[] = $start->setTimezone($utc)->format('Y-m-d H:i:s');
                $params[] = $end->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
                return "$column >= ? AND $column < ?";
            }

            $start = new DateTimeImmutable('monday this week 00:00:00', $manila);
            $params[] = $start->setTimezone($utc)->format('Y-m-d H:i:s');
            $params[] = $start->modify('+7 days')->setTimezone($utc)->format('Y-m-d H:i:s');
            return "$column >= ? AND $column < ?";
        } else {
            // Pure DATE column mode (e.g. comparing YYYY-MM-DD directly)
            if ($scope === 'day') {
                $params[] = record_date_filter_valid_date($filter['day'] ?? date('Y-m-d'));
                return "$column = ?";
            }

            if ($scope === 'week') {
                $week_str = $filter['week'] ?? date('o-\WW');
                if (strpos($week_str, '-W') !== false) {
                    [$year, $week] = explode('-W', $week_str);
                } else {
                    $year = date('o');
                    $week = date('W');
                }
                $start = (new DateTimeImmutable('now', $manila))
                    ->setISODate((int) $year, (int) $week, 1)
                    ->setTime(0, 0, 0);
                $params[] = $start->format('Y-m-d');
                $params[] = $start->modify('+6 days')->format('Y-m-d');
                return "$column BETWEEN ? AND ?";
            }

            if ($scope === 'month') {
                $month = preg_match('/^\d{4}-\d{2}$/', (string) ($filter['month'] ?? ''))
                    ? (string) $filter['month']
                    : date('Y-m');
                $start = new DateTimeImmutable($month . '-01', $manila);
                $params[] = $start->format('Y-m-d');
                $params[] = $start->format('Y-m-t');
                return "$column BETWEEN ? AND ?";
            }

            if ($scope === 'year') {
                $year = (int) ($filter['year'] ?? date('Y'));
                $year = max(2020, min(2100, $year));
                $params[] = "$year-01-01";
                $params[] = "$year-12-31";
                return "$column BETWEEN ? AND ?";
            }

            if ($scope === 'range') {
                $from = record_date_filter_valid_date($filter['from'] ?? date('Y-m-d'));
                $to = record_date_filter_valid_date($filter['to'] ?? $from, $from);
                if (strtotime($from) > strtotime($to)) {
                    [$from, $to] = [$to, $from];
                }
                $params[] = $from;
                $params[] = $to;
                return "$column BETWEEN ? AND ?";
            }

            $start = new DateTimeImmutable('monday this week', $manila);
            $params[] = $start->format('Y-m-d');
            $params[] = $start->modify('+6 days')->format('Y-m-d');
            return "$column BETWEEN ? AND ?";
        }
    }
}

if (!function_exists('record_activity_datetime_expr')) {
    function record_activity_datetime_expr($date_expr, $created_expr, $updated_expr = null) {
        $fallback = "'1970-01-01 00:00:00'";
        $created_part = "COALESCE($created_expr, $fallback)";
        $date_part = trim((string) $date_expr) !== ''
            ? "COALESCE(CAST($date_expr AS DATETIME), $created_expr, $fallback)"
            : $created_part;
        $updated_part = trim((string) $updated_expr) !== ''
            ? "COALESCE($updated_expr, $created_expr, $fallback)"
            : $created_part;

        return "GREATEST($updated_part, $created_part, $date_part)";
    }
}

if (!function_exists('record_business_datetime_expr')) {
    function record_business_datetime_expr($date_expr, $created_expr = null) {
        $fallback = "'1970-01-01 00:00:00'";
        $created_part = trim((string) $created_expr) !== ''
            ? "COALESCE($created_expr, $fallback)"
            : $fallback;

        return trim((string) $date_expr) !== ''
            ? "COALESCE(CAST($date_expr AS DATETIME), $created_part)"
            : $created_part;
    }
}

if (!function_exists('record_date_filter_label')) {
    function record_date_filter_label(array $filter) {
        $scope = $filter['scope'] ?? 'all';

        if ($scope === 'all') {
            return 'All records';
        }

        if ($scope === 'day') {
            return date('F j, Y', strtotime($filter['day']));
        }

        if ($scope === 'week') {
            [$year, $week] = explode('-W', $filter['week']);
            $start = new DateTime();
            $start->setISODate((int) $year, (int) $week, 1);
            $end = clone $start;
            $end->modify('+6 days');
            return $start->format('M j') . ' - ' . $end->format('M j, Y');
        }

        if ($scope === 'month') {
            return date('F Y', strtotime(($filter['month'] ?? date('Y-m')) . '-01'));
        }

        if ($scope === 'year') {
            return (string) (int) ($filter['year'] ?? date('Y'));
        }

        if ($scope === 'range') {
            $from = $filter['from'] ?? date('Y-m-d');
            $to = $filter['to'] ?? $from;
            if ($from === $to) {
                return date('F j, Y', strtotime($from));
            }
            return date('M j, Y', strtotime($from)) . ' - ' . date('M j, Y', strtotime($to));
        }

        return 'Current week';
    }
}

if (!function_exists('record_date_filter_heading')) {
    function record_date_filter_heading($base_label, array $filter) {
        $scope = $filter['scope'] ?? 'all';
        if ($scope === 'recent') {
            return 'Current Week ' . $base_label;
        }

        if ($scope === 'all') {
            return 'All ' . $base_label;
        }

        return $base_label . ' - ' . record_date_filter_label($filter);
    }
}

if (!function_exists('record_date_filter_hidden_inputs')) {
    function record_date_filter_hidden_inputs(array $fields) {
        foreach ($fields as $name => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            echo '<input type="hidden" name="' . esc_attr((string) $name) . '" value="' . esc_attr((string) $value) . '">' . "\n";
        }
    }
}

if (!function_exists('record_date_filter_controls')) {
    function record_date_filter_controls(array $filter, array $hidden_fields = [], $anchor = '') {
        static $script_printed = false;
        $current_path = $_SERVER['PHP_SELF'] ?? './';
        $action = $current_path . ($anchor !== '' ? '#' . ltrim($anchor, '#') : '');
        $scope = $filter['scope'] ?? 'all';
        ?>
        <form method="GET" action="<?php echo esc_attr($action); ?>" class="records-date-filter" data-record-date-filter>
            <?php record_date_filter_hidden_inputs($hidden_fields); ?>
            <label>
                <span>Records</span>
                <select name="date_scope" class="records-date-scope" aria-label="Select record period">
                    <option value="all" <?php echo $scope === 'all' ? 'selected' : ''; ?>>All Records</option>
                    <option value="recent" <?php echo $scope === 'recent' ? 'selected' : ''; ?>>Current Week</option>
                    <option value="day" <?php echo $scope === 'day' ? 'selected' : ''; ?>>Day</option>
                    <option value="week" <?php echo $scope === 'week' ? 'selected' : ''; ?>>Week</option>
                    <option value="month" <?php echo $scope === 'month' ? 'selected' : ''; ?>>Month</option>
                    <option value="year" <?php echo $scope === 'year' ? 'selected' : ''; ?>>Year</option>
                    <option value="range" <?php echo $scope === 'range' ? 'selected' : ''; ?>>Date Range</option>
                </select>
            </label>
            <label data-date-input="day">
                <span>Day</span>
                <input type="date" name="date_day" value="<?php echo esc_attr($filter['day']); ?>">
            </label>
            <label data-date-input="week">
                <span>Week</span>
                <input type="week" name="date_week" value="<?php echo esc_attr($filter['week']); ?>">
            </label>
            <label data-date-input="month">
                <span>Month</span>
                <input type="month" name="date_month" value="<?php echo esc_attr($filter['month']); ?>">
            </label>
            <label data-date-input="year">
                <span>Year</span>
                <input type="number" name="date_year" min="2020" max="2100" value="<?php echo (int) $filter['year']; ?>">
            </label>
            <label data-date-input="range">
                <span>From</span>
                <input type="date" name="date_from" value="<?php echo esc_attr($filter['from']); ?>">
            </label>
            <label data-date-input="range">
                <span>To</span>
                <input type="date" name="date_to" value="<?php echo esc_attr($filter['to']); ?>">
            </label>
            <button type="submit">Apply</button>
        </form>
        <?php
        record_date_filter_script();
    }
}

if (!function_exists('record_date_filter_script')) {
    function record_date_filter_script() {
        static $script_printed = false;
        if ($script_printed) {
            return;
        }
        $script_printed = true;
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('[data-record-date-filter]').forEach(function(form) {
                const select = form.querySelector('.records-date-scope');
                const inputs = form.querySelectorAll('[data-date-input]');

                function syncDateInputs() {
                    const scope = select ? select.value : 'all';
                    inputs.forEach(function(wrapper) {
                        const active = wrapper.dataset.dateInput === scope;
                        wrapper.hidden = !active;
                        wrapper.querySelectorAll('input').forEach(function(input) {
                            input.disabled = !active;
                        });
                    });
                }

                if (select) {
                    select.addEventListener('change', syncDateInputs);
                }
                syncDateInputs();
            });
        });
        </script>
        <?php
    }
}

