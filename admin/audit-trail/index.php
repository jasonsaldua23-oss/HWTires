<?php
/**
 * Admin Audit Trail Viewer
 * Strictly Read-Only Audit Log Inspection
 */

require_once __DIR__ . '/../../includes/config.php';
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_name(SESSION_NAME);
    session_start();
}

// Strict Authentication & Admin Authorization
if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$user = app_get_session_user();
if (($user['role'] ?? '') !== 'admin') {
    redirect('/hwtires/' . ($user['role'] ?? '') . '/index.php');
}

$page_title = 'Audit Trail';

// Timezone Conversion Helper: UTC database timestamp -> Asia/Manila (PHT) display
if (!function_exists('audit_format_datetime_pht')) {
    function audit_format_datetime_pht($utc_datetime_str, $format = 'M j, Y h:i:s A') {
        if (empty($utc_datetime_str)) {
            return '-';
        }
        try {
            $dt = new DateTime($utc_datetime_str, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone('Asia/Manila'));
            return $dt->format($format);
        } catch (Exception $e) {
            return $utc_datetime_str;
        }
    }
}

if (!function_exists('audit_format_date_pht')) {
    function audit_format_date_pht($utc_datetime_str) {
        return audit_format_datetime_pht($utc_datetime_str, 'M j, Y');
    }
}

if (!function_exists('audit_format_time_pht')) {
    function audit_format_time_pht($utc_datetime_str) {
        return audit_format_datetime_pht($utc_datetime_str, 'h:i:s A');
    }
}

// Fetch all branches (including inactive/historical) for branch resolution
$all_branches_raw = $pdo->query("SELECT id, name, location FROM branches ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$branch_map = [
    0 => 'System-wide / All Branches',
];
foreach ($all_branches_raw as $b) {
    $branch_map[(int)$b['id']] = $b['name'] ?: ('Branch #' . $b['id']);
}

// Helper: Human-readable table / module name
if (!function_exists('audit_table_label')) {
    function audit_table_label($table_name) {
        $labels = [
            'job_orders' => 'Job Orders',
            'inventory_items' => 'Inventory',
            'quotations' => 'Quotations',
            'customers' => 'Customers',
            'vehicles' => 'Vehicles',
            'users' => 'Users',
            'branches' => 'Branches',
            'service_catalog' => 'Services',
            'inter_branch_transfer_requests' => 'Branch Transfers',
            'technicians' => 'Technicians',
            'customer_branch_records' => 'Customer Branch',
            'system_settings' => 'Settings',
        ];
        return $labels[$table_name] ?? ucwords(str_replace('_', ' ', (string) $table_name));
    }
}

// Helper: Human-readable action label
if (!function_exists('audit_action_label')) {
    function audit_action_label($action) {
        $action_lower = strtolower(trim((string) $action));
        $labels = [
            'login' => 'Login',
            'logout' => 'Logout',
            'create' => 'Create',
            'update' => 'Update',
            'status_update' => 'Status Update',
            'update_status' => 'Status Update',
            'stock_in' => 'Stock In',
            'stock_out' => 'Stock Out',
            'stock_adjustment' => 'Stock Adjustment',
            'inventory_transfer' => 'Transfer',
            'job_order_stock_out' => 'Stock Out',
            'archive' => 'Archive',
            'unarchive' => 'Restore',
            'restore' => 'Restore',
            'reactivate' => 'Reactivate',
            'deactivate' => 'Deactivate',
            'edit_services' => 'Edit Services',
            'update_roster' => 'Update Roster',
            'delete' => 'Delete',
            'delete_permanent' => 'Permanent Delete',
            'merge_duplicates' => 'Merge Duplicates',
            'profile_update' => 'Profile Update',
            'password_reset_admin' => 'Password Reset by Admin',
            'password_change_first_login' => 'First Login Password Change',
            'password_change_profile' => 'Password Change',
        ];
        return $labels[$action_lower] ?? ucwords(str_replace('_', ' ', (string) $action));
    }
}

// Canonical Action Groups configuration for deduplicated filtering
$canonical_action_groups = [
    'stock_out' => ['stock_out', 'job_order_stock_out'],
    'status_update' => ['status_update', 'update_status'],
    'restore' => ['restore', 'unarchive'],
];

$raw_to_canonical_action = [
    'stock_out' => 'stock_out',
    'job_order_stock_out' => 'stock_out',
    'status_update' => 'status_update',
    'update_status' => 'status_update',
    'restore' => 'restore',
    'unarchive' => 'restore',
    'reactivate' => 'reactivate',
];

// Helper: Human-readable attribute key label
if (!function_exists('audit_field_label')) {
    function audit_field_label($key) {
        $labels = [
            'role' => 'Role',
            'branch_id' => 'Branch',
            'job_order_id' => 'Job Order #',
            'quotation_id' => 'Quotation #',
            'quotation_item_id' => 'Quotation Item #',
            'customer_id' => 'Customer #',
            'vehicle_id' => 'Vehicle #',
            'branch_supervisor' => 'Branch Supervisor',
            'contact_number' => 'Contact Number',
            'has_inventory' => 'Has Inventory',
            'status' => 'Status',
            'quantity' => 'Quantity',
            'reorder_level' => 'Reorder Level',
            'unit_price' => 'Unit Price',
            'selling_price' => 'Selling Price',
            'plate_number' => 'Plate Number',
            'item_name' => 'Item Name',
            'service_name' => 'Service Name',
            'base_price' => 'Base Price',
            'added_quantity' => 'Added Quantity',
            'removed_quantity' => 'Removed Quantity',
            'reason_type' => 'Reason',
            'archive_reason' => 'Archive Reason',
            'assigned_technician_id' => 'Technician #',
            'assigned_technician_name' => 'Technician Name',
            'job_number' => 'Job Number',
            'quotation_number' => 'Quotation Number',
            'request_number' => 'Request Number',
            'shipping_date' => 'Shipping Date',
            'received_date' => 'Received Date',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
            'technicians' => 'Technicians',
        ];
        return $labels[$key] ?? ucwords(str_replace('_', ' ', (string) $key));
    }
}

// Helper: Normalize technician roster from diverse historical audit shapes
if (!function_exists('audit_extract_technicians')) {
    function audit_extract_technicians($data) {
        if (empty($data) || !is_array($data)) {
            return [];
        }

        // Case 1: Associative container with 'technicians' key (e.g. {"branch_id": 30002, "technicians": ["Dave Moreno", ...]})
        if (isset($data['technicians']) && is_array($data['technicians'])) {
            $result = [];
            foreach ($data['technicians'] as $item) {
                if (is_array($item)) {
                    $result[] = [
                        'id' => $item['id'] ?? null,
                        'name' => trim((string)($item['name'] ?? $item['technician_name'] ?? ''))
                    ];
                } elseif (is_scalar($item)) {
                    $result[] = [
                        'id' => null,
                        'name' => trim((string)$item)
                    ];
                }
            }
            return array_values(array_filter($result, fn($t) => $t['name'] !== ''));
        }

        // Case 2: Indexed list of technician objects (e.g. [{"id": 30004, "name": "Dave Moreno"}, ...])
        $result = [];
        foreach ($data as $k => $item) {
            if (is_array($item) && (!empty($item['name']) || !empty($item['technician_name']))) {
                $result[] = [
                    'id' => $item['id'] ?? null,
                    'name' => trim((string)($item['name'] ?? $item['technician_name'] ?? ''))
                ];
            } elseif (is_scalar($item) && is_numeric($k)) {
                $result[] = [
                    'id' => null,
                    'name' => trim((string)$item)
                ];
            }
        }
        return array_values(array_filter($result, fn($t) => $t['name'] !== ''));
    }
}

// Helper: Human-readable attribute value formatter (safe for arrays, objects, scalars, nulls, booleans)
if (!function_exists('audit_format_field_value')) {
    function audit_format_field_value($key, $value, $branch_map = [], $jo_map = [], $item_map = []) {
        if ($value === null || $value === '') {
            return '-';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            // Check if it is a list of object items (e.g. technicians or sub-records)
            $is_list_of_objects = false;
            foreach ($value as $item) {
                if (is_array($item)) {
                    $is_list_of_objects = true;
                    break;
                }
            }

            if ($is_list_of_objects) {
                $formatted_items = [];
                foreach ($value as $item) {
                    if (is_array($item)) {
                        $name = $item['name'] ?? $item['title'] ?? $item['item_name'] ?? $item['service_name'] ?? null;
                        $id = $item['id'] ?? null;
                        if ($name !== null) {
                            $clean_name = function_exists('app_display_item_name') ? app_display_item_name($name) : $name;
                            $formatted_items[] = (string)$clean_name . ($id ? ' (#' . (int)$id . ')' : '');
                        } else {
                            $sub_parts = [];
                            foreach ($item as $sub_k => $sub_v) {
                                if (is_scalar($sub_v)) {
                                    $sub_parts[] = audit_field_label($sub_k) . ': ' . $sub_v;
                                }
                            }
                            $formatted_items[] = implode(', ', $sub_parts);
                        }
                    } elseif (is_scalar($item)) {
                        $formatted_items[] = (string)$item;
                    }
                }
                return !empty($formatted_items) ? implode(', ', $formatted_items) : '-';
            }

            // Simple indexed list of scalars
            $is_assoc = (array_keys($value) !== range(0, count($value) - 1));
            if (!$is_assoc) {
                $formatted_scalars = [];
                foreach ($value as $item) {
                    if (is_scalar($item)) {
                        $formatted_scalars[] = (string)$item;
                    }
                }
                return !empty($formatted_scalars) ? implode(', ', $formatted_scalars) : '-';
            }

            // Associative array: Key: Value
            $formatted_pairs = [];
            foreach ($value as $sub_k => $sub_v) {
                $sub_lbl = audit_field_label($sub_k);
                $sub_val = audit_format_field_value($sub_k, $sub_v, $branch_map, $jo_map, $item_map);
                $formatted_pairs[] = $sub_lbl . ': ' . $sub_val;
            }
            return !empty($formatted_pairs) ? implode('; ', $formatted_pairs) : '-';
        }

        $key_lower = strtolower((string)$key);

        if ($key_lower === 'branch_id') {
            $b_id = (int)$value;
            return $branch_map[$b_id] ?? ('Branch #' . $b_id);
        }

        if ($key_lower === 'job_order_id') {
            $j_id = (int)$value;
            return $jo_map[$j_id] ?? ('#' . $j_id);
        }

        if (in_array($key_lower, ['item_id', 'inventory_item_id'], true)) {
            $i_id = (int)$value;
            return $item_map[$i_id] ?? ('#' . $i_id);
        }

        if ($key_lower === 'item_name' && is_string($value)) {
            return function_exists('app_display_item_name') ? app_display_item_name($value) : $value;
        }

        if ($key_lower === 'role') {
            $r_str = strtolower((string)$value);
            if ($r_str === 'admin') return 'Admin';
            if ($r_str === 'front-desk') return 'Front Desk';
            return ucwords(str_replace(['_', '-'], ' ', $r_str));
        }

        if ($key_lower === 'has_inventory') {
            return ((int)$value === 1 || $value === true || $value === '1') ? 'Yes' : 'No';
        }

        if ($key_lower === 'status') {
            return ucwords(str_replace(['_', '-'], ' ', (string)$value));
        }

        if (in_array($key_lower, ['quotation_id', 'quotation_item_id', 'customer_id', 'vehicle_id', 'id'], true) && is_numeric($value)) {
            return '#' . (int)$value;
        }

        if (in_array($key_lower, ['unit_price', 'selling_price', 'base_price', 'total_amount'], true) && is_numeric($value)) {
            return '₱' . number_format((float)$value, 2);
        }

        return (string)$value;
    }
}

// Helper: Action badge styling
if (!function_exists('audit_action_badge')) {
    function audit_action_badge($action) {
        $action_lower = strtolower(trim((string) $action));
        $style = 'background: #e2e8f0; color: #334155; border: 1px solid #cbd5e1;';
        $icon = 'fas fa-circle-info';

        if ($action_lower === 'login') {
            $style = 'background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0;';
            $icon = 'fas fa-right-to-bracket';
        } elseif ($action_lower === 'logout') {
            $style = 'background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;';
            $icon = 'fas fa-arrow-right-from-bracket';
        } elseif ($action_lower === 'create') {
            $style = 'background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe;';
            $icon = 'fas fa-plus';
        } elseif (in_array($action_lower, ['update', 'status_update', 'update_status', 'edit_services', 'update_roster', 'profile_update'], true)) {
            $style = 'background: #f0fdfa; color: #0f766e; border: 1px solid #99f6e4;';
            $icon = 'fas fa-pen-to-square';
        } elseif ($action_lower === 'stock_in') {
            $style = 'background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0;';
            $icon = 'fas fa-boxes-stacked';
        } elseif (in_array($action_lower, ['stock_out', 'job_order_stock_out'], true)) {
            $style = 'background: #fffbeb; color: #92400e; border: 1px solid #fde68a;';
            $icon = 'fas fa-box-open';
        } elseif (in_array($action_lower, ['archive', 'deactivate', 'delete', 'delete_permanent'], true)) {
            $style = 'background: #fef2f2; color: #991b1b; border: 1px solid #fecaca;';
            $icon = 'fas fa-box-archive';
        } elseif (in_array($action_lower, ['restore', 'unarchive', 'reactivate'], true)) {
            $style = 'background: #f5f3ff; color: #5b21b6; border: 1px solid #ddd6fe;';
            $icon = 'fas fa-rotate-left';
        }

        $label = audit_action_label($action);
        return '<span style="display: inline-flex; align-items: center; gap: 5px; padding: 3px 8px; border-radius: 9999px; font-size: 0.78rem; font-weight: 600; ' . $style . '"><i class="' . $icon . '"></i> ' . esc_html($label) . '</span>';
    }
}

// Helper: Role badge styling
if (!function_exists('audit_role_badge')) {
    function audit_role_badge($role) {
        $role_lower = strtolower(trim((string) $role));
        if ($role_lower === 'admin') {
            return '<span class="badge bg-primary" style="font-size: 0.72rem;">Admin</span>';
        } elseif ($role_lower === 'front-desk') {
            return '<span class="badge bg-info text-dark" style="font-size: 0.72rem;">Front Desk</span>';
        }
        return '<span class="badge bg-secondary" style="font-size: 0.72rem;">' . esc_html($role ?: 'System') . '</span>';
    }
}

// Helper: Changed-fields-only summary (eliminates false transitions like active -> active, and safely normalizes rosters)
if (!function_exists('audit_changes_summary')) {
    function audit_changes_summary($old_json, $new_json, $action, $branch_map = [], $table_name = '', $record_id = 0, $item_map = [], $jo_map = []) {
        $action_lower = strtolower(trim((string) $action));
        $table_lower = strtolower(trim((string) $table_name));
        $old_data = !empty($old_json) ? json_decode($old_json, true) : null;
        $new_data = !empty($new_json) ? json_decode($new_json, true) : null;

        if ($action_lower === 'login') {
            $role_str = !empty($new_data['role']) ? audit_format_field_value('role', $new_data['role'], $branch_map, $jo_map, $item_map) : 'User';
            $branch_str = '';
            if (isset($new_data['branch_id'])) {
                $b_id = (int)$new_data['branch_id'];
                if ($b_id > 0 && !empty($branch_map[$b_id])) {
                    $branch_str = ' • ' . $branch_map[$b_id];
                }
            }
            return 'Logged in (' . htmlspecialchars($role_str) . htmlspecialchars($branch_str) . ')';
        }

        if ($action_lower === 'logout') {
            return 'Logged out from session';
        }

        // Dedicated human-readable summary for Job Order Stock Out
        if ($action_lower === 'job_order_stock_out' || (!empty($new_data['job_order_id']) && isset($new_data['quantity']))) {
            $item_id = (int) $record_id;
            $item_label = !empty($item_map[$item_id]) ? $item_map[$item_id] : ('Item #' . $item_id);
            $qty = (int) ($new_data['quantity'] ?? 1);
            $jo_id = (int) ($new_data['job_order_id'] ?? 0);
            $jo_ref = !empty($jo_map[$jo_id]) ? $jo_map[$jo_id] : ('#' . $jo_id);

            return htmlspecialchars($item_label) . ' &mdash; <strong>' . $qty . ' unit' . ($qty > 1 ? 's' : '') . '</strong> used for ' . htmlspecialchars($jo_ref);
        }

        // Special handling for Technician Roster updates
        if ($action_lower === 'update_roster' || $table_lower === 'technicians' || (is_array($new_data) && isset($new_data['technicians']))) {
            $old_techs = audit_extract_technicians($old_data);
            $new_techs = audit_extract_technicians($new_data);

            $old_names = array_map(fn($t) => $t['name'], $old_techs);
            $new_names = array_map(fn($t) => $t['name'], $new_techs);

            if (empty($old_names) && !empty($new_names)) {
                return 'Roster set: ' . htmlspecialchars(implode(', ', array_slice($new_names, 0, 3))) . (count($new_names) > 3 ? ' <span class="text-muted">(+' . (count($new_names) - 3) . ' more)</span>' : '');
            }

            $added = array_values(array_diff($new_names, $old_names));
            $removed = array_values(array_diff($old_names, $new_names));

            if (empty($added) && empty($removed)) {
                return 'Roster unchanged (' . count($new_names) . ' technicians)';
            }

            $parts = [];
            if (!empty($added)) {
                $parts[] = 'Technicians Added: <span class="badge bg-success bg-opacity-10 text-success border border-success">' . htmlspecialchars(implode(', ', array_slice($added, 0, 2))) . (count($added) > 2 ? ' +' . (count($added) - 2) : '') . '</span>';
            }
            if (!empty($removed)) {
                $parts[] = 'Technicians Removed: <span class="badge bg-danger bg-opacity-10 text-danger border border-danger">' . htmlspecialchars(implode(', ', array_slice($removed, 0, 2))) . (count($removed) > 2 ? ' +' . (count($removed) - 2) : '') . '</span>';
            }

            return implode(' • ', $parts);
        }

        // Action is create with no old values
        if (empty($old_data) && !empty($new_data)) {
            if (!empty($new_data['name'])) {
                return 'Created: ' . htmlspecialchars((string)$new_data['name']);
            }
            if (!empty($new_data['item_name'])) {
                $clean_item = function_exists('app_display_item_name') ? app_display_item_name((string)$new_data['item_name']) : (string)$new_data['item_name'];
                return 'Created item: ' . htmlspecialchars($clean_item);
            }
            if (!empty($new_data['job_number'])) {
                return 'Created Job Order: ' . htmlspecialchars((string)$new_data['job_number']);
            }
            return 'Initial state / New record';
        }

        // Compare old vs new to find ONLY genuinely changed keys
        if (is_array($old_data) && is_array($new_data)) {
            $changed_diffs = [];
            $ignored_meta = ['updated_at', 'created_at', 'id'];

            foreach ($new_data as $k => $new_val) {
                if (in_array($k, $ignored_meta, true)) {
                    continue;
                }
                $old_val = $old_data[$k] ?? null;

                // Safe difference check that NEVER casts arrays to string
                $is_different = false;
                if (is_array($old_val) || is_array($new_val)) {
                    $is_different = (json_encode($old_val) !== json_encode($new_val));
                } else {
                    $is_different = ($old_val !== $new_val && (string)$old_val !== (string)$new_val);
                }

                if ($is_different) {
                    $lbl = audit_field_label($k);
                    $old_fmt = audit_format_field_value($k, $old_val, $branch_map, $jo_map, $item_map);
                    $new_fmt = audit_format_field_value($k, $new_val, $branch_map, $jo_map, $item_map);
                    $changed_diffs[] = htmlspecialchars($lbl) . ': <span class="badge bg-light text-dark border">' . htmlspecialchars($old_fmt) . '</span> &rarr; <span class="badge bg-primary">' . htmlspecialchars($new_fmt) . '</span>';
                }
            }

            if (!empty($changed_diffs)) {
                return implode(' • ', array_slice($changed_diffs, 0, 2)) . (count($changed_diffs) > 2 ? ' <span class="text-muted">(+' . (count($changed_diffs) - 2) . ' more)</span>' : '');
            }

            return 'Record updated (no key value changes)';
        }

        if (!empty($new_data['added_quantity'])) {
            return 'Stocked in +' . (int)$new_data['added_quantity'] . ' units';
        }

        if (!empty($new_data['removed_quantity'])) {
            return 'Stocked out -' . (int)$new_data['removed_quantity'] . ' units';
        }

        return '-';
    }
}

// Helper: URL builder preserving active filter parameters
if (!function_exists('audit_filter_url')) {
    function audit_filter_url($params = []) {
        $current = [
            'search' => $_GET['search'] ?? '',
            'module' => $_GET['module'] ?? '',
            'action' => $_GET['action'] ?? '',
            'user_id' => $_GET['user_id'] ?? '',
            'start_date' => $_GET['start_date'] ?? '',
            'end_date' => $_GET['end_date'] ?? '',
            'per_page' => $_GET['per_page'] ?? 20,
            'page' => $_GET['page'] ?? 1,
        ];

        $merged = array_merge($current, $params);
        $clean = [];
        foreach ($merged as $k => $v) {
            $v_str = trim((string) $v);
            if ($v_str !== '' && $v_str !== 'all' && ($k !== 'page' || (int)$v > 1) && ($k !== 'per_page' || (int)$v !== 20)) {
                $clean[$k] = $v_str;
            }
        }

        return '/hwtires/admin/audit-trail/' . (!empty($clean) ? '?' . http_build_query($clean) : '');
    }
}

// Capture & sanitize filter inputs
$search_query = trim((string) ($_GET['search'] ?? ''));
$module_filter = trim((string) ($_GET['module'] ?? ''));
$action_filter = trim((string) ($_GET['action'] ?? ''));
$user_filter = trim((string) ($_GET['user_id'] ?? ''));
$start_date = trim((string) ($_GET['start_date'] ?? ''));
$end_date = trim((string) ($_GET['end_date'] ?? ''));

$per_page = (int) ($_GET['per_page'] ?? 20);
if (!in_array($per_page, [20, 50, 100], true)) {
    $per_page = 20;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

// Validate dates format (YYYY-MM-DD)
if ($start_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
    $start_date = '';
}
if ($end_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    $end_date = '';
}

// Fetch filter option dropdowns
$filter_users = $pdo->query("SELECT id, name, role FROM users ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$filter_modules = $pdo->query("SELECT DISTINCT table_name FROM audit_logs WHERE table_name IS NOT NULL AND table_name <> '' ORDER BY table_name ASC")->fetchAll(PDO::FETCH_COLUMN);

// Pre-load clean inventory item names map using app_display_item_name()
$all_items_raw = $pdo->query("SELECT id, item_name, category FROM inventory_items")->fetchAll(PDO::FETCH_ASSOC);
$item_map = [];
foreach ($all_items_raw as $it) {
    $item_map[(int)$it['id']] = function_exists('app_display_item_name') ? app_display_item_name($it['item_name'], $it['category'] ?? null) : $it['item_name'];
}

// Build deduplicated canonical Action filter options list
$raw_actions = $pdo->query("SELECT DISTINCT action FROM audit_logs WHERE action IS NOT NULL AND action <> '' ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);
$filter_actions = [];
foreach ($raw_actions as $raw_act) {
    $raw_lower = strtolower(trim((string)$raw_act));
    $canonical_key = $raw_to_canonical_action[$raw_lower] ?? $raw_lower;
    if (!isset($filter_actions[$canonical_key])) {
        $filter_actions[$canonical_key] = audit_action_label($canonical_key);
    }
}
asort($filter_actions);

// Build WHERE SQL with prepared parameters
$where = [];
$params = [];

if ($search_query !== '') {
    $search_int = is_numeric($search_query) ? (int)$search_query : null;
    $wildcard = '%' . $search_query . '%';

    // Comprehensive search conditions: general audit fields + Job Order reference lookup via EXISTS (Direct + Option B Related Stock-Out)
    $search_conds = [
        "a.action LIKE :s_act",
        "a.table_name LIKE :s_tbl",
        "a.ip_address LIKE :s_ip",
        "u.name LIKE :s_usr",
        "u.email LIKE :s_eml",
        "a.old_values LIKE :s_old",
        "a.new_values LIKE :s_new",
        "(a.table_name = 'job_orders' AND EXISTS (SELECT 1 FROM job_orders jo WHERE jo.id = a.record_id AND jo.job_number LIKE :s_jo1))",
        "(a.table_name = 'inventory_items' AND a.action = 'job_order_stock_out' AND EXISTS (SELECT 1 FROM job_orders jo WHERE jo.job_number LIKE :s_jo2 AND (JSON_EXTRACT(a.new_values, '$.job_order_id') = jo.id OR JSON_EXTRACT(a.old_values, '$.job_order_id') = jo.id)))"
    ];

    $params[':s_act'] = $wildcard;
    $params[':s_tbl'] = $wildcard;
    $params[':s_ip'] = $wildcard;
    $params[':s_usr'] = $wildcard;
    $params[':s_eml'] = $wildcard;
    $params[':s_old'] = $wildcard;
    $params[':s_new'] = $wildcard;
    $params[':s_jo1'] = $wildcard;
    $params[':s_jo2'] = $wildcard;

    if ($search_int !== null) {
        $search_conds[] = "a.record_id = :search_int";
        $search_conds[] = "a.id = :search_int_id";
        $params[':search_int'] = $search_int;
        $params[':search_int_id'] = $search_int;
    }

    $where[] = '(' . implode(' OR ', $search_conds) . ')';
}

if ($module_filter !== '' && $module_filter !== 'all') {
    $where[] = "a.table_name = :filter_module";
    $params[':filter_module'] = $module_filter;
}

if ($action_filter !== '' && $action_filter !== 'all') {
    $action_lower = strtolower($action_filter);
    if (isset($canonical_action_groups[$action_lower])) {
        $placeholders = [];
        foreach ($canonical_action_groups[$action_lower] as $idx => $act_name) {
            $p_key = ':filter_action_' . $idx;
            $placeholders[] = $p_key;
            $params[$p_key] = $act_name;
        }
        $where[] = "a.action IN (" . implode(', ', $placeholders) . ")";
    } else {
        $where[] = "a.action = :filter_action";
        $params[':filter_action'] = $action_filter;
    }
}

if ($user_filter !== '' && $user_filter !== 'all') {
    $where[] = "a.user_id = :filter_user_id";
    $params[':filter_user_id'] = (int)$user_filter;
}

// Timezone-safe date boundaries: Convert Asia/Manila calendar date to UTC boundaries for SQL
if ($start_date !== '') {
    try {
        $dt_start = new DateTime($start_date . ' 00:00:00', new DateTimeZone('Asia/Manila'));
        $dt_start->setTimezone(new DateTimeZone('UTC'));
        $where[] = "a.created_at >= :start_date_utc";
        $params[':start_date_utc'] = $dt_start->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        $where[] = "DATE(a.created_at) >= :start_date_fallback";
        $params[':start_date_fallback'] = $start_date;
    }
}

if ($end_date !== '') {
    try {
        $dt_end = new DateTime($end_date . ' 23:59:59', new DateTimeZone('Asia/Manila'));
        $dt_end->setTimezone(new DateTimeZone('UTC'));
        $where[] = "a.created_at <= :end_date_utc";
        $params[':end_date_utc'] = $dt_end->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        $where[] = "DATE(a.created_at) <= :end_date_fallback";
        $params[':end_date_fallback'] = $end_date;
    }
}

$where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// 1. Get total record count matching filters
$count_sql = "SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id $where_clause";
$count_stmt = $pdo->prepare($count_sql);
foreach ($params as $key => $val) {
    $count_stmt->bindValue($key, $val);
}
$count_stmt->execute();
$total_records = (int) $count_stmt->fetchColumn();

// 2. Fetch paginated records
$data_sql = "
    SELECT a.*, u.name AS user_name, u.email AS user_email, u.role AS user_role, b.name AS user_branch_name
    FROM audit_logs a
    LEFT JOIN users u ON u.id = a.user_id
    LEFT JOIN branches b ON b.id = u.branch_id
    $where_clause
    ORDER BY a.created_at DESC, a.id DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($data_sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$audit_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Batch-resolve Job Order numbers for all records on the current page
$needed_jo_ids = [];
foreach ($audit_records as $rec) {
    if ($rec['table_name'] === 'job_orders' && !empty($rec['record_id'])) {
        $needed_jo_ids[] = (int)$rec['record_id'];
    }
    $nv = !empty($rec['new_values']) ? json_decode($rec['new_values'], true) : null;
    if (!empty($nv['job_order_id'])) {
        $needed_jo_ids[] = (int)$nv['job_order_id'];
    }
    $ov = !empty($rec['old_values']) ? json_decode($rec['old_values'], true) : null;
    if (!empty($ov['job_order_id'])) {
        $needed_jo_ids[] = (int)$ov['job_order_id'];
    }
}

$jo_map = [];
if (!empty($needed_jo_ids)) {
    $unique_jo_ids = array_values(array_unique(array_filter($needed_jo_ids)));
    $in_clause = implode(',', array_fill(0, count($unique_jo_ids), '?'));
    $jo_stmt = $pdo->prepare("SELECT id, job_number FROM job_orders WHERE id IN ($in_clause)");
    $jo_stmt->execute($unique_jo_ids);
    foreach ($jo_stmt->fetchAll(PDO::FETCH_ASSOC) as $j_row) {
        $jo_map[(int)$j_row['id']] = $j_row['job_number'];
    }
}

$total_pages = max(1, (int) ceil($total_records / $per_page));
$showing_from = $total_records > 0 ? $offset + 1 : 0;
$showing_to = min($offset + $per_page, $total_records);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>

<style>
/* Suppress global typeahead panel specifically on the Audit Trail search input */
.hw-search-suggestions {
    display: none !important;
}
</style>

<div class="container-fluid px-3 py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1 fw-bold text-dark"><i class="fas fa-shield-halved text-primary me-2"></i>Audit Trail</h1>
            <p class="text-muted mb-0">System-wide audit trail recording user actions, operational modifications, and security events.</p>
        </div>
        <div>
            <span class="badge bg-light text-dark border px-3 py-2 fs-6">
                <i class="fas fa-database me-1 text-secondary"></i> Total Records: <strong><?php echo number_format($total_records); ?></strong>
            </span>
        </div>
    </div>

    <!-- Search & Filters Card (Aligned 2-Row Layout) -->
    <div class="card border-0 shadow-sm rounded-3 mb-4">
        <div class="card-body p-3 p-md-4">
            <form method="get" action="/hwtires/admin/audit-trail/">
                <input type="hidden" name="per_page" value="<?php echo (int) $per_page; ?>">

                <!-- Row 1: Primary Dropdown & Date Filters (Module, Action, User, Start Date, End Date, Filter, Reset) -->
                <div class="row g-2 align-items-end">
                    <!-- Module / Table filter -->
                    <div class="col-12 col-sm-6 col-md-4 col-lg-2">
                        <label class="form-label small fw-semibold text-secondary mb-1">Module</label>
                        <select name="module" class="form-select">
                            <option value="all">All Modules</option>
                            <?php foreach ($filter_modules as $mod): ?>
                                <option value="<?php echo esc_attr($mod); ?>" <?php echo $module_filter === $mod ? 'selected' : ''; ?>>
                                    <?php echo esc_html(audit_table_label($mod)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Action filter (Deduplicated Canonical Options) -->
                    <div class="col-12 col-sm-6 col-md-4 col-lg-2">
                        <label class="form-label small fw-semibold text-secondary mb-1">Action</label>
                        <select name="action" class="form-select">
                            <option value="all">All Actions</option>
                            <?php foreach ($filter_actions as $act_key => $act_lbl): ?>
                                <option value="<?php echo esc_attr($act_key); ?>" <?php echo strtolower($action_filter) === strtolower($act_key) ? 'selected' : ''; ?>>
                                    <?php echo esc_html($act_lbl); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- User filter -->
                    <div class="col-12 col-sm-6 col-md-4 col-lg-2">
                        <label class="form-label small fw-semibold text-secondary mb-1">User</label>
                        <select name="user_id" class="form-select">
                            <option value="all">All Users</option>
                            <?php foreach ($filter_users as $u): ?>
                                <option value="<?php echo (int)$u['id']; ?>" <?php echo (string)$user_filter === (string)$u['id'] ? 'selected' : ''; ?>>
                                    <?php echo esc_html($u['name']); ?> (<?php echo esc_html(ucfirst($u['role'])); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Date Range: Start -->
                    <div class="col-6 col-sm-3 col-md-3 col-lg-2">
                        <label class="form-label small fw-semibold text-secondary mb-1">Start Date</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo esc_attr($start_date); ?>">
                    </div>

                    <!-- Date Range: End -->
                    <div class="col-6 col-sm-3 col-md-3 col-lg-2">
                        <label class="form-label small fw-semibold text-secondary mb-1">End Date</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo esc_attr($end_date); ?>">
                    </div>

                    <!-- Action Buttons: Filter & Reset -->
                    <div class="col-12 col-md-auto d-flex gap-2 ms-lg-auto">
                        <button type="submit" class="btn btn-primary px-3 text-nowrap">
                            <i class="fas fa-filter me-1"></i> Filter
                        </button>
                        <a href="/hwtires/admin/audit-trail/" class="btn btn-outline-secondary px-3 text-nowrap" title="Reset all filters">
                            <i class="fas fa-rotate-left me-1"></i> Reset
                        </a>
                    </div>
                </div>

                <!-- Row 2: Status Text on Left & Right-Aligned Search Box with Visible Button -->
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 pt-3 border-top mt-3">
                    <div class="text-secondary small d-flex align-items-center flex-wrap gap-2">
                        <?php 
                        $has_filters = ($module_filter !== '' && $module_filter !== 'all') 
                                    || ($action_filter !== '' && $action_filter !== 'all') 
                                    || ($user_filter !== '' && $user_filter !== 'all') 
                                    || $start_date !== '' 
                                    || $end_date !== '';
                        $has_search = ($search_query !== '');
                        ?>
                        <?php if ($has_filters || $has_search): ?>
                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary">
                                <i class="fas fa-filter me-1"></i>Filters Active
                            </span>
                            <?php if ($has_search): ?>
                                <span class="badge bg-light text-dark border">
                                    Search: "<strong><?php echo esc_html($search_query); ?></strong>"
                                </span>
                            <?php endif; ?>
                            <?php if ($action_filter !== '' && $action_filter !== 'all'): ?>
                                <span class="badge bg-light text-dark border">
                                    Action: <strong><?php echo esc_html(audit_action_label($action_filter)); ?></strong>
                                </span>
                            <?php endif; ?>
                            <?php if ($module_filter !== '' && $module_filter !== 'all'): ?>
                                <span class="badge bg-light text-dark border">
                                    Module: <strong><?php echo esc_html(audit_table_label($module_filter)); ?></strong>
                                </span>
                            <?php endif; ?>
                            <span class="text-muted ms-1">Matching records: <strong><?php echo number_format($total_records); ?></strong></span>
                        <?php else: ?>
                            <span class="text-muted"><i class="fas fa-info-circle me-1"></i> Showing all system audit records</span>
                        <?php endif; ?>
                    </div>

                    <!-- Right-Aligned Search Group -->
                    <div class="d-flex align-items-center gap-2" style="max-width: 440px; width: 100%;">
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0"><i class="fas fa-search text-muted"></i></span>
                            <input type="text"
                                   id="auditSearchInput"
                                   name="search"
                                   class="form-control bg-light border-start-0"
                                   placeholder="Search user, action, JO reference, ID..."
                                   value="<?php echo esc_attr($search_query); ?>"
                                   autocomplete="off"
                                   data-no-autocomplete="true">
                        </div>
                        <button type="submit" class="btn btn-primary px-3 text-nowrap">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Audit Records Table Card -->
    <div class="card border-0 shadow-sm rounded-3">
        <!-- Card Header / Toolbar -->
        <div class="card-header bg-white py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2 border-bottom">
            <div class="text-secondary small">
                Showing <strong><?php echo (int) $showing_from; ?>-<?php echo (int) $showing_to; ?></strong> of <strong><?php echo number_format($total_records); ?></strong> audit records
            </div>
            <div class="d-flex align-items-center gap-2">
                <label class="small text-secondary fw-semibold text-nowrap mb-0">Rows per page:</label>
                <form method="get" action="/hwtires/admin/audit-trail/" class="d-inline-block">
                    <?php if ($search_query !== ''): ?><input type="hidden" name="search" value="<?php echo esc_attr($search_query); ?>"><?php endif; ?>
                    <?php if ($module_filter !== '' && $module_filter !== 'all'): ?><input type="hidden" name="module" value="<?php echo esc_attr($module_filter); ?>"><?php endif; ?>
                    <?php if ($action_filter !== '' && $action_filter !== 'all'): ?><input type="hidden" name="action" value="<?php echo esc_attr($action_filter); ?>"><?php endif; ?>
                    <?php if ($user_filter !== '' && $user_filter !== 'all'): ?><input type="hidden" name="user_id" value="<?php echo esc_attr($user_filter); ?>"><?php endif; ?>
                    <?php if ($start_date !== ''): ?><input type="hidden" name="start_date" value="<?php echo esc_attr($start_date); ?>"><?php endif; ?>
                    <?php if ($end_date !== ''): ?><input type="hidden" name="end_date" value="<?php echo esc_attr($end_date); ?>"><?php endif; ?>
                    <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="20" <?php echo $per_page === 20 ? 'selected' : ''; ?>>20</option>
                        <option value="50" <?php echo $per_page === 50 ? 'selected' : ''; ?>>50</option>
                        <option value="100" <?php echo $per_page === 100 ? 'selected' : ''; ?>>100</option>
                    </select>
                </form>
            </div>
        </div>

        <!-- Table View -->
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size: 0.9rem;">
                <thead class="table-light">
                    <tr>
                        <th style="width: 175px;" class="ps-4">Date & Time (PHT)</th>
                        <th style="width: 190px;">User</th>
                        <th style="width: 140px;">Action</th>
                        <th style="width: 150px;">Module</th>
                        <th style="width: 110px;">Record ID</th>
                        <th>Summary of Changes</th>
                        <th style="width: 90px;" class="text-center pe-4">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($audit_records)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="fas fa-inbox fa-3x mb-3 d-block text-secondary opacity-50"></i>
                                <span class="fw-semibold fs-6">No audit records found matching the selected filters.</span>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($audit_records as $rec): ?>
                            <?php
                            $date_formatted = audit_format_date_pht($rec['created_at']);
                            $time_formatted = audit_format_time_pht($rec['created_at']);
                            $datetime_full = audit_format_datetime_pht($rec['created_at']);
                            ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-semibold text-dark"><?php echo esc_html($date_formatted); ?></div>
                                    <div class="small text-muted" style="font-size: 0.78rem;"><?php echo esc_html($time_formatted); ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($rec['user_name'])): ?>
                                        <div class="fw-semibold text-dark"><?php echo esc_html($rec['user_name']); ?></div>
                                        <div class="d-flex align-items-center gap-1 mt-1">
                                            <?php echo audit_role_badge($rec['user_role']); ?>
                                            <?php if (!empty($rec['user_branch_name'])): ?>
                                                <span class="small text-muted" style="font-size: 0.75rem;">• <?php echo esc_html($rec['user_branch_name']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted fst-italic">System / Anonymous</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo audit_action_badge($rec['action']); ?>
                                </td>
                                <td>
                                    <span class="fw-semibold text-dark"><?php echo esc_html(audit_table_label($rec['table_name'])); ?></span>
                                </td>
                                <td>
                                    <?php if (!empty($rec['record_id'])): ?>
                                        <span class="badge bg-light text-dark border font-monospace">#<?php echo (int) $rec['record_id']; ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="text-secondary small">
                                        <?php echo audit_changes_summary($rec['old_values'], $rec['new_values'], $rec['action'], $branch_map, $rec['table_name'], $rec['record_id'] ?? 0, $item_map, $jo_map); ?>
                                    </div>
                                </td>
                                <td class="text-center pe-4">
                                    <!-- View button rendered consistently on 100% of audit rows -->
                                    <button type="button"
                                            class="btn btn-sm btn-outline-secondary py-1 px-2 text-nowrap"
                                            onclick="openAuditDetailsModal(<?php echo (int) $rec['id']; ?>)"
                                            title="View audit record details">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <!-- Hidden structured data payload for modal -->
                                    <script id="audit-payload-<?php echo (int) $rec['id']; ?>" type="application/json">
                                        <?php
                                        $modal_new = !empty($rec['new_values']) ? json_decode($rec['new_values'], true) : null;
                                        $modal_old = !empty($rec['old_values']) ? json_decode($rec['old_values'], true) : null;

                                        // For job_order_stock_out, include clean Inventory Item name for clear display
                                        if ($rec['action'] === 'job_order_stock_out' && !empty($rec['record_id'])) {
                                            $item_id = (int)$rec['record_id'];
                                            if (!empty($item_map[$item_id])) {
                                                if (is_array($modal_new)) {
                                                    $modal_new = array_merge(['inventory_item' => $item_map[$item_id]], $modal_new);
                                                }
                                            }
                                        }

                                        echo json_encode([
                                            'id' => (int) $rec['id'],
                                            'created_at' => $datetime_full,
                                            'user_name' => $rec['user_name'] ?: 'System',
                                            'user_role' => $rec['user_role'] ?: '-',
                                            'action' => audit_action_label($rec['action']),
                                            'table_name' => audit_table_label($rec['table_name']),
                                            'record_id' => $rec['record_id'] ? '#' . (int)$rec['record_id'] : '-',
                                            'ip_address' => $rec['ip_address'] ?: 'Unknown',
                                            'old_values' => $modal_old,
                                            'new_values' => $modal_new,
                                        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                                        ?>
                                    </script>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Controls -->
        <?php if ($total_pages > 1): ?>
            <div class="card-footer bg-white py-3 px-4 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="small text-muted">
                    Page <strong><?php echo (int) $page; ?></strong> of <strong><?php echo (int) $total_pages; ?></strong>
                </div>
                <nav aria-label="Audit log navigation">
                    <ul class="pagination pagination-sm mb-0">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?php echo esc_attr(audit_filter_url(['page' => 1])); ?>" aria-label="First">First</a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="<?php echo esc_attr(audit_filter_url(['page' => $page - 1])); ?>" aria-label="Previous">Prev</a>
                            </li>
                        <?php endif; ?>

                        <?php
                        $start_p = max(1, $page - 2);
                        $end_p = min($total_pages, $page + 2);
                        for ($p = $start_p; $p <= $end_p; $p++):
                        ?>
                            <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="<?php echo esc_attr(audit_filter_url(['page' => $p])); ?>"><?php echo (int) $p; ?></a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?php echo esc_attr(audit_filter_url(['page' => $page + 1])); ?>" aria-label="Next">Next</a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="<?php echo esc_attr(audit_filter_url(['page' => $total_pages])); ?>" aria-label="Last">Last</a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal for Viewing Before / After Details -->
<div class="modal fade" id="auditDetailsModal" tabindex="-1" aria-labelledby="auditDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-light py-3 px-4">
                <h5 class="modal-title fs-6 fw-bold text-dark mb-0" id="auditDetailsModalLabel">
                    <i class="fas fa-shield-halved text-primary me-2"></i>Audit Log Record Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <!-- Metadata Info Grid -->
                <div class="row g-3 p-3 bg-light rounded-3 mb-4 border">
                    <div class="col-6 col-md-3">
                        <div class="text-secondary small fw-semibold">Date & Time (PHT)</div>
                        <div class="fw-semibold text-dark small" id="modal-date">-</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="text-secondary small fw-semibold">User</div>
                        <div class="fw-semibold text-dark small" id="modal-user">-</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="text-secondary small fw-semibold">Action & Module</div>
                        <div class="fw-semibold text-dark small" id="modal-action-module">-</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="text-secondary small fw-semibold">Record ID & IP</div>
                        <div class="fw-semibold text-dark small" id="modal-record-ip">-</div>
                    </div>
                </div>

                <!-- Before & After Value Comparison Cards -->
                <div class="row g-3">
                    <!-- Before Values Card -->
                    <div class="col-12 col-md-6">
                        <div class="card h-100 border rounded-3 shadow-none">
                            <div class="card-header bg-white py-2 px-3 fw-semibold small text-danger border-bottom d-flex align-items-center justify-content-between">
                                <span><i class="fas fa-clock-rotate-left me-1"></i> Before Values (Old)</span>
                            </div>
                            <div class="card-body p-3" id="modal-old-container" style="max-height: 380px; overflow-y: auto;">
                                <!-- Dynamic rendered key-value rows -->
                            </div>
                        </div>
                    </div>

                    <!-- After Values Card -->
                    <div class="col-12 col-md-6">
                        <div class="card h-100 border rounded-3 shadow-none">
                            <div class="card-header bg-white py-2 px-3 fw-semibold small text-success border-bottom d-flex align-items-center justify-content-between">
                                <span><i class="fas fa-check-circle me-1"></i> After Values (New)</span>
                            </div>
                            <div class="card-body p-3" id="modal-new-container" style="max-height: 380px; overflow-y: auto;">
                                <!-- Dynamic rendered key-value rows -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light py-2 px-4">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
// Client-side dictionaries from PHP
const AUDIT_BRANCH_MAP = <?php echo json_encode($branch_map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const AUDIT_JO_MAP = <?php echo json_encode($jo_map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const AUDIT_ITEM_MAP = <?php echo json_encode($item_map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

const AUDIT_LABEL_MAP = {
    'role': 'Role',
    'branch_id': 'Branch',
    'job_order_id': 'Job Order',
    'quotation_id': 'Quotation #',
    'quotation_item_id': 'Quotation Item #',
    'customer_id': 'Customer #',
    'vehicle_id': 'Vehicle #',
    'branch_supervisor': 'Branch Supervisor',
    'contact_number': 'Contact Number',
    'has_inventory': 'Has Inventory',
    'status': 'Status',
    'quantity': 'Quantity',
    'reorder_level': 'Reorder Level',
    'unit_price': 'Unit Price',
    'selling_price': 'Selling Price',
    'plate_number': 'Plate Number',
    'inventory_item': 'Inventory Item',
    'item_name': 'Item Name',
    'service_name': 'Service Name',
    'base_price': 'Base Price',
    'added_quantity': 'Added Quantity',
    'removed_quantity': 'Removed Quantity',
    'reason_type': 'Reason',
    'archive_reason': 'Archive Reason',
    'job_number': 'Job Number',
    'quotation_number': 'Quotation Number',
    'request_number': 'Request Number',
    'shipping_date': 'Shipping Date',
    'received_date': 'Received Date',
    'created_at': 'Created At',
    'updated_at': 'Updated At',
    'technicians': 'Technicians'
};

function formatAuditFieldLabel(key) {
    if (AUDIT_LABEL_MAP[key]) return AUDIT_LABEL_MAP[key];
    return key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function formatAuditFieldValue(key, val) {
    if (val === null || val === undefined || val === '') {
        return '<span class="text-muted fst-italic">-</span>';
    }
    if (typeof val === 'boolean') {
        return val ? 'Yes' : 'No';
    }
    if (typeof val === 'object') {
        if (Array.isArray(val)) {
            // Check if items are objects with name/id
            const isObjectList = val.some(item => item && typeof item === 'object');
            if (isObjectList) {
                return val.map(item => {
                    if (item && typeof item === 'object') {
                        const name = item.name || item.title || item.item_name || 'Item';
                        const idStr = item.id ? ' (#' + escapeHtml(item.id) + ')' : '';
                        return escapeHtml(name) + idStr;
                    }
                    return escapeHtml(String(item));
                }).join(', ');
            }
            return val.map(v => escapeHtml(String(v))).join(', ');
        }
        // Associative object
        if (val.name || val.title) {
            return escapeHtml(val.name || val.title) + (val.id ? ' (#' + escapeHtml(val.id) + ')' : '');
        }
        return Object.keys(val).map(k => escapeHtml(formatAuditFieldLabel(k)) + ': ' + escapeHtml(String(val[k]))).join('; ');
    }

    const keyLower = key.toLowerCase();
    if (keyLower === 'branch_id') {
        const bId = parseInt(val, 10);
        return AUDIT_BRANCH_MAP[bId] || ('Branch #' + bId);
    }
    if (keyLower === 'job_order_id') {
        const jId = parseInt(val, 10);
        return escapeHtml(AUDIT_JO_MAP[jId] || ('#' + jId));
    }
    if (['item_id', 'inventory_item_id'].includes(keyLower)) {
        const iId = parseInt(val, 10);
        return escapeHtml(AUDIT_ITEM_MAP[iId] || ('#' + iId));
    }
    if (keyLower === 'inventory_item' || keyLower === 'item_name') {
        return escapeHtml(String(val));
    }
    if (keyLower === 'role') {
        const rStr = String(val).toLowerCase();
        if (rStr === 'admin') return 'Admin';
        if (rStr === 'front-desk') return 'Front Desk';
        return rStr.charAt(0).toUpperCase() + rStr.slice(1);
    }
    if (keyLower === 'has_inventory') {
        return (val === 1 || val === '1' || val === true) ? 'Yes' : 'No';
    }
    if (keyLower === 'status') {
        return '<span class="badge bg-light text-dark border">' + escapeHtml(String(val).replace(/[-_]/g, ' ').replace(/\b\w/g, l => l.toUpperCase())) + '</span>';
    }
    if (['quotation_id', 'quotation_item_id', 'customer_id', 'vehicle_id', 'id'].includes(keyLower) && !isNaN(val)) {
        return '#' + val;
    }
    if (['unit_price', 'selling_price', 'base_price', 'total_amount'].includes(keyLower) && !isNaN(val)) {
        return '₱' + Number(val).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    return escapeHtml(String(val));
}

function renderAuditObject(dataObj, compareObj, isNewCard) {
    if (!dataObj || typeof dataObj !== 'object' || Object.keys(dataObj).length === 0) {
        return '<div class="text-muted fst-italic p-3 text-center">' + (isNewCard ? 'No new values recorded' : 'No previous values recorded') + '</div>';
    }

    // Special Case: dataObj is an indexed array of objects (like historical roster snapshots)
    if (Array.isArray(dataObj)) {
        const isListOfObjects = dataObj.some(item => item && typeof item === 'object');
        if (isListOfObjects) {
            let html = '<div class="list-group list-group-flush">';
            html += '<div class="list-group-item px-3 py-2 bg-light fw-bold small text-secondary">Technicians / Items List</div>';
            dataObj.forEach((item, idx) => {
                if (item && typeof item === 'object') {
                    const name = item.name || item.title || item.item_name || ('Item #' + (idx + 1));
                    const idBadge = item.id ? '<span class="badge bg-light text-dark border font-monospace ms-2">#' + escapeHtml(item.id) + '</span>' : '';
                    html += `
                        <div class="list-group-item px-3 py-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="small text-dark fw-medium">${escapeHtml(name)}</span>
                                ${idBadge}
                            </div>
                        </div>
                    `;
                } else {
                    html += `<div class="list-group-item px-3 py-2 small text-dark">${escapeHtml(String(item))}</div>`;
                }
            });
            html += '</div>';
            return html;
        }
    }

    let html = '<div class="list-group list-group-flush">';
    const keys = Object.keys(dataObj);

    keys.forEach(k => {
        const val = dataObj[k];
        const compVal = compareObj ? compareObj[k] : undefined;
        let isChanged = false;

        if (compareObj && compVal !== undefined) {
            if (typeof val === 'object' || typeof compVal === 'object') {
                isChanged = (JSON.stringify(val) !== JSON.stringify(compVal));
            } else {
                isChanged = (compVal !== val && String(compVal) !== String(val));
            }
        }

        const rowClass = isChanged ? 'bg-warning bg-opacity-10 border-start border-3 border-warning' : '';
        const label = formatAuditFieldLabel(k);
        const formattedVal = formatAuditFieldValue(k, val);

        html += `
            <div class="list-group-item px-3 py-2 ${rowClass}">
                <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                    <span class="small fw-semibold text-secondary">${escapeHtml(label)}</span>
                    <span class="small text-dark fw-medium text-end">${formattedVal}</span>
                </div>
            </div>
        `;
    });

    html += '</div>';
    return html;
}

function openAuditDetailsModal(auditId) {
    const payloadEl = document.getElementById('audit-payload-' + auditId);
    if (!payloadEl) return;

    try {
        const data = JSON.parse(payloadEl.textContent);
        document.getElementById('modal-date').textContent = data.created_at || '-';
        document.getElementById('modal-user').textContent = (data.user_name || 'System') + ' (' + (data.user_role || '-') + ')';
        document.getElementById('modal-action-module').textContent = (data.action || '-') + ' / ' + (data.table_name || '-');
        document.getElementById('modal-record-ip').textContent = (data.record_id || '-') + ' • IP: ' + (data.ip_address || '-');

        const oldContainer = document.getElementById('modal-old-container');
        const newContainer = document.getElementById('modal-new-container');

        oldContainer.innerHTML = renderAuditObject(data.old_values, data.new_values, false);
        newContainer.innerHTML = renderAuditObject(data.new_values, data.old_values, true);

        const modal = new bootstrap.Modal(document.getElementById('auditDetailsModal'));
        modal.show();
    } catch (e) {
        console.error('Failed to parse audit details payload:', e);
    }
}
</script>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
