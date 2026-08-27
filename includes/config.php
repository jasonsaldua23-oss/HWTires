<?php
/**
 * Highway Tires Management System
 * Database Configuration
 */

// Load .env file if it exists
$env_file = __DIR__ . '/../.env';
if (file_exists($env_file)) {
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if (getenv($name) === false) {
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

// Database credentials (with environment variable support for cloud hosting like Render)
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_NAME', getenv('DB_NAME') ?: 'hwtires');
define('DB_PORT', getenv('DB_PORT') ? (int)getenv('DB_PORT') : 3306);

// Application constants
define('APP_NAME', getenv('APP_NAME') ?: 'HW Tires Management');
if (!defined('APP_URL')) {
    $env_app_url = getenv('APP_URL');
    if ($env_app_url !== false) {
        define('APP_URL', rtrim($env_app_url, '/'));
    } else {
        // Auto-detect root vs subfolder
        $doc_root = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : '';
        $app_root = realpath(__DIR__ . '/..');
        define('APP_URL', ($doc_root && $app_root && $doc_root === $app_root) ? '' : '/hwtires');
    }
}
define('APP_TIMEZONE', getenv('APP_TIMEZONE') ?: 'Asia/Manila');

// Session configuration
define('SESSION_TIMEOUT', 3600); // 1 hour in seconds
define('SESSION_NAME', 'hwtires_session');

// Pagination
define('RECORDS_PER_PAGE', 25);

// Database connection (only once)
if (!isset($pdo)) {
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ]
        );
    } catch (PDOException $e) {
        // Log error (in production, log to file instead)
        error_log('Database Connection Error: ' . $e->getMessage());
        die('Database connection failed. Please contact administrator.');
    }
}

/**
 * Function to escape HTML output to prevent XSS
 */
if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Function to escape attribute values
 */
if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Function to escape URLs
 */
if (!function_exists('esc_url')) {
    function esc_url($url) {
        filter_var($url, FILTER_VALIDATE_URL);
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Format branch names consistently for display without changing stored values.
 */
if (!function_exists('app_branch_label')) {
    function app_branch_label($branch_name, $fallback = 'Branch') {
        $label = trim((string) $branch_name);
        if ($label === '') {
            return $fallback;
        }

        $label = preg_replace('/\s*-\s*.*/', '', $label);
        $label = trim((string) $label);

        return $label !== '' ? $label : $fallback;
    }
}

/**
 * Generate CSRF token
 */
if (!function_exists('generate_csrf_token')) {
    function generate_csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

/**
 * Verify CSRF token
 */
if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token($token) {
        return hash_equals($_SESSION['csrf_token'] ?? '', $token);
    }
}

/**
 * Get current user from session
 */
if (!function_exists('app_get_session_user')) {
    function app_get_session_user() {
        if (!isset($_SESSION['user'])) {
            return null;
        }

        $user = $_SESSION['user'];

        // If user is not an array, return null (session corrupted)
        if (!is_array($user)) {
            // Try to recover by unsetting corrupted session
            unset($_SESSION['user']);
            return null;
        }

        return $user;
    }
}

/**
 * Check if user is logged in
 */
if (!function_exists('is_logged_in')) {
    function is_logged_in() {
        $user = app_get_session_user();
        return is_array($user) && !empty($user) && !empty($user['id']);
    }
}

/**
 * Check if user is admin
 */
if (!function_exists('is_admin')) {
    function is_admin() {
        $user = app_get_session_user();
        return $user && $user['role'] === 'admin';
    }
}

/**
 * Check if user has branch access
 */
if (!function_exists('has_branch_access')) {
    function has_branch_access($branch_id) {
        $user = app_get_session_user();
        if (!$user) return false;
        if ($user['role'] === 'admin') return true;
        return $user['branch_id'] == $branch_id;
    }
}

/**
 * Format currency
 */
if (!function_exists('format_currency')) {
    function format_currency($amount) {
        return '₱' . number_format($amount, 2);
    }
}

/**
 * Format date
 */
if (!function_exists('format_date')) {
    function format_date($date, $format = 'M d, Y') {
        if (empty($date)) return '-';
        return date($format, strtotime($date));
    }
}

/**
 * Log user action for audit trail
 */
if (!function_exists('log_audit')) {
    function log_audit($table_name, $action, $record_id, $old_values = null, $new_values = null) {
        global $pdo;
        $user = app_get_session_user();

        try {
            $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $user['id'] ?? null,
                $action,
                $table_name,
                $record_id,
                $old_values ? json_encode($old_values) : null,
                $new_values ? json_encode($new_values) : null,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            error_log('Audit log error: ' . $e->getMessage());
        }
    }
}

/**
 * Redirect to a page
 */
if (!function_exists('redirect')) {
    function redirect($url) {
        if (defined('APP_URL') && APP_URL !== '/hwtires' && strpos($url, '/hwtires/') === 0) {
            $url = APP_URL . substr($url, strlen('/hwtires'));
        }
        header('Location: ' . $url);
        exit;
    }
}

/**
 * Get flash message from session
 */
if (!function_exists('get_flash_message')) {
    function get_flash_message($key = 'message') {
        $message = $_SESSION['flash_' . $key] ?? null;
        unset($_SESSION['flash_' . $key]);
        return $message;
    }
}

/**
 * Set flash message
 */
if (!function_exists('set_flash_message')) {
    function set_flash_message($message, $type = 'info', $key = 'message') {
        $_SESSION['flash_' . $key] = [
            'message' => $message,
            'type' => $type // 'success', 'error', 'warning', 'info'
        ];
    }
}

/**
 * Get branches for user
 */
if (!function_exists('get_user_branches')) {
    function get_user_branches() {
        global $pdo;
        $user = app_get_session_user();

        if ($user['role'] === 'admin') {
            // Admin sees all branches
            return $pdo->query("SELECT * FROM branches WHERE status = 'active' ORDER BY name")->fetchAll();
        } else {
            // Front desk sees only their branch
            $stmt = $pdo->prepare("SELECT * FROM branches WHERE id = ? AND status = 'active'");
            $stmt->execute([$user['branch_id']]);
            return [$stmt->fetch()];
        }
    }
}

/**
 * Get inventory items for branch (if access granted)
 */
if (!function_exists('can_access_inventory')) {
    function can_access_inventory($branch_id) {
        global $pdo;
        $user = app_get_session_user();

        if ($user['role'] === 'admin') {
            return true;
        }

        // Inventory is enabled for all active branches; keep this check for branch ownership.
        $stmt = $pdo->prepare("SELECT has_inventory FROM branches WHERE id = ?");
        $stmt->execute([$branch_id]);
        $branch = $stmt->fetch();

        return $branch && $branch['has_inventory'] && $user['branch_id'] == $branch_id;
    }
}

if (!function_exists('can_modify_records')) {
    function can_modify_records() {
        $user = app_get_session_user();
        return is_array($user) && ($user['role'] ?? '') === 'front-desk';
    }
}

if (!function_exists('enforce_modify_permission')) {
    function enforce_modify_permission() {
        if (can_modify_records()) {
            return;
        }

        throw new Exception('Only front-desk users can modify records');
    }
}

if (!function_exists('enforce_branch_record_ownership')) {
    function enforce_branch_record_ownership($record_branch_id) {
        $user = app_get_session_user();
        $user_branch_id = intval($user['branch_id'] ?? 0);
        $record_branch_id = intval($record_branch_id);

        if ($record_branch_id <= 0 || $user_branch_id <= 0 || $record_branch_id !== $user_branch_id) {
            throw new Exception('You can only modify records from your branch');
        }
    }
}

if (!function_exists('app_customer_branch_record_exists')) {
    function app_customer_branch_record_exists($customer_id, $branch_id, $active_only = true) {
        global $pdo;

        $customer_id = intval($customer_id);
        $branch_id = intval($branch_id);

        if ($customer_id <= 0 || $branch_id <= 0) {
            return false;
        }

        $status_sql = $active_only ? " AND status = 'active'" : '';
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM customer_branch_records WHERE customer_id = ? AND branch_id = ? $status_sql");
        $stmt->execute([$customer_id, $branch_id]);

        return intval($stmt->fetchColumn()) > 0;
    }
}

if (!function_exists('app_touch_customer_branch_record')) {
    function app_touch_customer_branch_record($customer_id, $branch_id, $created_by = null) {
        global $pdo;

        $customer_id = intval($customer_id);
        $branch_id = intval($branch_id);
        $created_by = $created_by !== null ? intval($created_by) : null;

        if ($customer_id <= 0 || $branch_id <= 0) {
            return false;
        }

        if ($created_by !== null && $created_by > 0) {
            try {
                $user_stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
                $user_stmt->execute([$created_by]);
                if (!$user_stmt->fetchColumn()) {
                    $created_by = null;
                }
            } catch (Exception $e) {
                $created_by = null;
            }
        } else {
            $created_by = null;
        }

        $stmt = $pdo->prepare("
            INSERT INTO customer_branch_records (customer_id, branch_id, status, last_visit_at, created_by)
            VALUES (?, ?, 'active', NOW(), ?)
            ON DUPLICATE KEY UPDATE
                status = 'active',
                last_visit_at = NOW(),
                updated_at = NOW()
        ");
        $stmt->execute([$customer_id, $branch_id, $created_by]);

        $branch_set = [];
        $branch_values = [];
        app_restore_metadata_update('customer_branch_records', $branch_set, $branch_values);
        if (!empty($branch_set)) {
            if (app_column_exists('customer_branch_records', 'updated_at')) {
                $branch_set[] = 'updated_at = NOW()';
            }
            $branch_values[] = $customer_id;
            $branch_values[] = $branch_id;
            $branch_stmt = $pdo->prepare('
                UPDATE customer_branch_records
                SET ' . implode(', ', $branch_set) . '
                WHERE customer_id = ? AND branch_id = ?
            ');
            $branch_stmt->execute($branch_values);
        }

        $customer_set = [
            "status = 'active'",
            'branch_id = COALESCE(branch_id, ?)'
        ];
        $customer_values = [$branch_id];
        app_restore_metadata_update('customers', $customer_set, $customer_values);
        if (app_column_exists('customers', 'updated_at')) {
            $customer_set[] = 'updated_at = NOW()';
        }
        $customer_values[] = $customer_id;

        $customer_stmt = $pdo->prepare('
            UPDATE customers
            SET ' . implode(', ', $customer_set) . '
            WHERE id = ?
        ');
        $customer_stmt->execute($customer_values);

        return true;
    }
}

if (!function_exists('app_record_action_user_id')) {
    function app_record_action_user_id() {
        $user = app_get_session_user();
        $user_id = intval($user['id'] ?? 0);

        return $user_id > 0 ? $user_id : null;
    }
}

if (!function_exists('app_archive_reason')) {
    function app_archive_reason($fallback = 'Record archived') {
        $reason = trim((string) ($_POST['archive_reason'] ?? $_POST['reason'] ?? $_GET['archive_reason'] ?? $_GET['reason'] ?? ''));
        if ($reason === '') {
            $reason = trim((string) $fallback);
        }

        if (function_exists('mb_substr')) {
            return mb_substr($reason, 0, 500);
        }

        return substr($reason, 0, 500);
    }
}

if (!function_exists('app_archive_metadata_update')) {
    function app_archive_metadata_update($table, array &$set, array &$values, $reason = 'Record archived') {
        if (app_column_exists($table, 'archived_at')) {
            $set[] = 'archived_at = NOW()';
        }

        if (app_column_exists($table, 'archived_by')) {
            $set[] = 'archived_by = ?';
            $values[] = app_record_action_user_id();
        }

        if (app_column_exists($table, 'archive_reason')) {
            $set[] = 'archive_reason = ?';
            $values[] = app_archive_reason($reason);
        }

        if (app_column_exists($table, 'restored_at')) {
            $set[] = 'restored_at = NULL';
        }

        if (app_column_exists($table, 'restored_by')) {
            $set[] = 'restored_by = NULL';
        }
    }
}

if (!function_exists('app_restore_metadata_update')) {
    function app_restore_metadata_update($table, array &$set, array &$values) {
        if (app_column_exists($table, 'archived_at')) {
            $set[] = 'archived_at = NULL';
        }

        if (app_column_exists($table, 'archived_by')) {
            $set[] = 'archived_by = NULL';
        }

        if (app_column_exists($table, 'archive_reason')) {
            $set[] = 'archive_reason = NULL';
        }

        if (app_column_exists($table, 'restored_at')) {
            $set[] = 'restored_at = NOW()';
        }

        if (app_column_exists($table, 'restored_by')) {
            $set[] = 'restored_by = ?';
            $values[] = app_record_action_user_id();
        }
    }
}

if (!function_exists('enforce_customer_branch_record_ownership')) {
    function enforce_customer_branch_record_ownership($customer_id, $branch_id = null) {
        $user = app_get_session_user();
        $branch_id = $branch_id !== null ? intval($branch_id) : intval($user['branch_id'] ?? 0);

        if (($user['role'] ?? '') === 'admin') {
            return;
        }

        if ($branch_id <= 0 || intval($user['branch_id'] ?? 0) !== $branch_id || !app_customer_branch_record_exists($customer_id, $branch_id, true)) {
            throw new Exception('You can only modify customer records that belong to your branch');
        }
    }
}

if (!function_exists('app_deactivate_customer_branch_record')) {
    function app_deactivate_customer_branch_record($customer_id, $branch_id) {
        global $pdo;

        $customer_id = intval($customer_id);
        $branch_id = intval($branch_id);

        if ($customer_id <= 0 || $branch_id <= 0) {
            return false;
        }

        $set = ["status = 'inactive'"];
        $values = [];
        app_archive_metadata_update('customer_branch_records', $set, $values, 'Customer branch record archived');
        if (app_column_exists('customer_branch_records', 'updated_at')) {
            $set[] = 'updated_at = NOW()';
        }
        $values[] = $customer_id;
        $values[] = $branch_id;

        $stmt = $pdo->prepare('
            UPDATE customer_branch_records
            SET ' . implode(', ', $set) . '
            WHERE customer_id = ? AND branch_id = ?
        ');
        $stmt->execute($values);

        $active_stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM customer_branch_records
            WHERE customer_id = ? AND status = 'active'
        ");
        $active_stmt->execute([$customer_id]);

        if (intval($active_stmt->fetchColumn()) === 0) {
            $customer_set = ["status = 'inactive'"];
            $customer_values = [];
            app_archive_metadata_update('customers', $customer_set, $customer_values, 'Customer archived after all branch records were archived');
            if (app_column_exists('customers', 'updated_at')) {
                $customer_set[] = 'updated_at = NOW()';
            }
            $customer_values[] = $customer_id;

            $customer_stmt = $pdo->prepare('UPDATE customers SET ' . implode(', ', $customer_set) . ' WHERE id = ?');
            $customer_stmt->execute($customer_values);
        }

        return true;
    }
}

if (!function_exists('app_format_record_notes')) {
    function app_format_record_notes($notes) {
        $notes = trim((string) $notes);
        if ($notes === '') {
            return '';
        }

        $sales = '';
        $technician = '';
        $detail_parts = [];

        $parts = preg_split('/\s*;\s*/', $notes);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $part = rtrim($part, '.');

            if (preg_match('/^sales(?:\s+in\s+charge)?\s*:\s*(.+)$/i', $part, $match)) {
                $sales = trim($match[1]);
                continue;
            }

            if (preg_match('/^technicians?\s*(?:assigned)?\s*:\s*(.+)$/i', $part, $match)) {
                $technician = trim($match[1]);
                continue;
            }

            if (preg_match('/^(?:notes?|service\s+notes?|inspection\s+notes?|quotation\s+notes?)\s*:\s*(.+)$/i', $part, $match)) {
                $value = trim($match[1]);
                if (preg_match('/^imported\s+service\s+visit\s+from\s+the\s+customer\s+service\s+record\.?$/i', $value)) {
                    continue;
                }
                if ($value !== '') {
                    $detail_parts[] = $value;
                }
                continue;
            }

            if (preg_match('/^(?:invoice|source|confidence|status|raw|branch)\s*:/i', $part)) {
                continue;
            }

            if (preg_match('/^imported\s+service\s+visit\s+from\s+the\s+customer\s+service\s+record\.?$/i', $part)) {
                continue;
            }

            $detail_parts[] = $part;
        }

        $formatted = [];
        if ($sales !== '') {
            $formatted[] = 'Sales in charge: ' . $sales;
        }
        if ($technician !== '') {
            $formatted[] = 'Technician assigned: ' . $technician;
        }

        $details = trim(implode('; ', array_unique(array_filter($detail_parts))));
        if ($details !== '') {
            $formatted[] = 'Service notes: ' . $details;
        }

        return !empty($formatted) ? implode('; ', $formatted) . '.' : $notes;
    }
}

if (!function_exists('app_compose_record_notes')) {
    function app_compose_record_notes($sales_name, $technician_names = '', $detail = '') {
        $sales_name = trim((string) $sales_name);
        $technician_names = trim((string) $technician_names);
        $detail = trim((string) $detail);

        $detail_parts = [];
        foreach (preg_split('/\s*;\s*/', $detail) as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }

            $part = rtrim($part, '.');

            if (preg_match('/^sales(?:\s+in\s+charge)?\s*:/i', $part) || preg_match('/^technicians?\s*(?:assigned)?\s*:/i', $part)) {
                continue;
            }

            if (preg_match('/^(?:notes?|service\s+notes?|inspection\s+notes?|quotation\s+notes?)\s*:\s*(.+)$/i', $part, $match)) {
                $part = trim($match[1]);
            }

            if (preg_match('/^imported\s+service\s+visit\s+from\s+the\s+customer\s+service\s+record\.?$/i', $part)) {
                continue;
            }

            if ($part !== '') {
                $detail_parts[] = $part;
            }
        }

        $parts = [];
        if ($sales_name !== '') {
            $parts[] = 'Sales in charge: ' . $sales_name;
        }
        if ($technician_names !== '') {
            $parts[] = 'Technician: ' . $technician_names;
        }

        $detail_text = trim(implode('; ', array_unique($detail_parts)));
        if ($detail_text !== '') {
            $parts[] = 'Service notes: ' . $detail_text;
        }

        return !empty($parts) ? implode('; ', $parts) . '.' : '';
    }
}

if (!function_exists('app_normalize_plate_number')) {
    function app_normalize_plate_number($plate_number) {
        $compact = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string) $plate_number)));

        if ($compact === '') {
            return '';
        }

        if (strlen($compact) === 7) {
            return substr($compact, 0, 3) . '-' . substr($compact, 3);
        }

        return strtoupper(trim((string) $plate_number));
    }
}

if (!function_exists('app_is_valid_plate_number')) {
    function app_is_valid_plate_number($plate_number) {
        return preg_match('/^[A-Z]{3}-[0-9]{4}$/', (string) $plate_number) === 1;
    }
}

if (!function_exists('app_find_vehicle_by_plate')) {
    function app_find_vehicle_by_plate($plate_number, $exclude_vehicle_id = 0) {
        global $pdo;

        $plate_number = app_normalize_plate_number($plate_number);
        $exclude_vehicle_id = intval($exclude_vehicle_id);

        if ($plate_number === '') {
            return null;
        }

        $sql = "SELECT * FROM vehicles WHERE plate_number = ?";
        $params = [$plate_number];

        if ($exclude_vehicle_id > 0) {
            $sql .= " AND id <> ?";
            $params[] = $exclude_vehicle_id;
        }

        $sql .= " LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $vehicle = $stmt->fetch();

        return $vehicle ?: null;
    }
}

if (!function_exists('app_vehicle_is_inactive')) {
    function app_vehicle_is_inactive($vehicle) {
        if (!$vehicle || !is_array($vehicle)) {
            return false;
        }

        return strtolower(trim((string) ($vehicle['status'] ?? 'active'))) === 'inactive';
    }
}

if (!function_exists('app_restore_inactive_vehicle')) {
    function app_restore_inactive_vehicle($vehicle_id, array $data) {
        global $pdo;

        $vehicle_id = intval($vehicle_id);
        $customer_id = intval($data['customer_id'] ?? 0);
        $branch_id = intval($data['branch_id'] ?? 0);
        $plate_number = app_normalize_plate_number($data['plate_number'] ?? '');

        if ($vehicle_id <= 0 || $customer_id <= 0 || $branch_id <= 0 || $plate_number === '') {
            throw new Exception('Invalid vehicle restore details');
        }

        $condition = trim((string) ($data['condition'] ?? 'good'));
        if (!in_array($condition, ['excellent', 'good', 'fair', 'poor'], true)) {
            $condition = 'good';
        }

        $year = intval($data['year'] ?? 0);
        $set = [
            'customer_id = ?',
            'branch_id = ?',
            'plate_number = ?',
            '`condition` = ?',
            'make = ?',
            'model = ?',
            'year = ?',
            'color = ?',
            "status = 'active'"
        ];
        $values = [
            $customer_id,
            $branch_id,
            $plate_number,
            $condition,
            trim((string) ($data['make'] ?? '')),
            trim((string) ($data['model'] ?? '')),
            $year > 0 ? $year : null,
            trim((string) ($data['color'] ?? ''))
        ];

        if (array_key_exists('vin', $data)) {
            $set[] = 'vin = ?';
            $values[] = trim((string) $data['vin']);
        }

        if (array_key_exists('last_mileage', $data)) {
            $set[] = 'last_mileage = ?';
            $values[] = intval($data['last_mileage']);
        }

        if (app_column_exists('vehicles', 'created_by_user_id') && array_key_exists('created_by_user_id', $data)) {
            $user_id = $data['created_by_user_id'] !== null ? intval($data['created_by_user_id']) : null;
            $set[] = 'created_by_user_id = ?';
            $values[] = $user_id && $user_id > 0 ? $user_id : null;
        }

        app_restore_metadata_update('vehicles', $set, $values);

        if (app_column_exists('vehicles', 'updated_at')) {
            $set[] = 'updated_at = NOW()';
        }

        $values[] = $vehicle_id;
        $stmt = $pdo->prepare('UPDATE vehicles SET ' . implode(', ', $set) . ' WHERE id = ?');
        $stmt->execute($values);

        return $vehicle_id;
    }
}

if (!function_exists('app_search_terms')) {
    function app_search_terms($search, $max_terms = 6) {
        $search = trim((string) $search);
        if ($search === '') {
            return [];
        }

        $terms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
        $terms = array_values(array_unique(array_filter(array_map('trim', $terms ?: []))));
        return array_slice($terms, 0, max(1, (int) $max_terms));
    }
}

if (!function_exists('app_column_exists')) {
    function app_column_exists($table, $column, $refresh = false) {
        global $pdo;

        if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $table) || !preg_match('/^[A-Za-z0-9_]+$/', (string) $column)) {
            return false;
        }

        static $cache = [];
        $cache_key = $table . '.' . $column;

        if (!$refresh && array_key_exists($cache_key, $cache)) {
            return $cache[$cache_key];
        }

        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                  AND COLUMN_NAME = ?
            ");
            $stmt->execute([$table, $column]);
            $cache[$cache_key] = intval($stmt->fetchColumn()) > 0;
        } catch (Exception $e) {
            $cache[$cache_key] = false;
        }

        return $cache[$cache_key];
    }
}

if (!function_exists('app_table_exists')) {
    function app_table_exists($table) {
        global $pdo;

        if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $table)) {
            return false;
        }

        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
            ");
            $stmt->execute([$table]);
            $cache[$table] = intval($stmt->fetchColumn()) > 0;
        } catch (Exception $e) {
            $cache[$table] = false;
        }

        return $cache[$table];
    }
}

if (!function_exists('app_index_exists')) {
    function app_index_exists($table, $index, $refresh = false) {
        global $pdo;

        if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $table) || !preg_match('/^[A-Za-z0-9_]+$/', (string) $index)) {
            return false;
        }

        static $cache = [];
        $cache_key = $table . '.' . $index;

        if (!$refresh && array_key_exists($cache_key, $cache)) {
            return $cache[$cache_key];
        }

        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                  AND INDEX_NAME = ?
            ");
            $stmt->execute([$table, $index]);
            $cache[$cache_key] = intval($stmt->fetchColumn()) > 0;
        } catch (Exception $e) {
            $cache[$cache_key] = false;
        }

        return $cache[$cache_key];
    }
}

if (!function_exists('app_quote_identifier')) {
    function app_quote_identifier($identifier) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $identifier)) {
            throw new InvalidArgumentException('Invalid database identifier');
        }

        return '`' . str_replace('`', '``', (string) $identifier) . '`';
    }
}

if (!function_exists('app_ensure_enum_value')) {
    function app_ensure_enum_value($table, $column, $value) {
        global $pdo;

        if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $table) || !preg_match('/^[A-Za-z0-9_]+$/', (string) $column)) {
            return false;
        }

        $stmt = $pdo->prepare("
            SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ");
        $stmt->execute([$table, $column]);
        $column_info = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$column_info || stripos((string) $column_info['COLUMN_TYPE'], 'enum(') !== 0) {
            return false;
        }

        preg_match_all("/'((?:[^']|'')*)'/", (string) $column_info['COLUMN_TYPE'], $matches);
        $values = array_map(static function ($enum_value) {
            return str_replace("''", "'", $enum_value);
        }, $matches[1] ?? []);

        if (in_array($value, $values, true)) {
            return true;
        }

        $values[] = $value;
        $enum_sql = 'ENUM(' . implode(', ', array_map(static function ($enum_value) use ($pdo) {
            return $pdo->quote($enum_value);
        }, $values)) . ')';
        $null_sql = strtoupper((string) $column_info['IS_NULLABLE']) === 'NO' ? 'NOT NULL' : 'NULL';
        $default_value = $column_info['COLUMN_DEFAULT'];
        if (is_string($default_value) && strlen($default_value) >= 2 && $default_value[0] === "'" && substr($default_value, -1) === "'") {
            $default_value = str_replace("''", "'", substr($default_value, 1, -1));
        }
        $default_sql = $default_value !== null ? ' DEFAULT ' . $pdo->quote($default_value) : '';

        $pdo->exec(
            'ALTER TABLE ' . app_quote_identifier($table) .
            ' MODIFY ' . app_quote_identifier($column) . ' ' .
            $enum_sql . ' ' . $null_sql . $default_sql
        );

        return true;
    }
}

if (!function_exists('ensure_archive_status_values')) {
    function ensure_archive_status_values() {
        try {
            app_ensure_enum_value('quotations', 'status', 'archived');
            app_ensure_enum_value('job_orders', 'status', 'pending');
            app_ensure_enum_value('job_orders', 'status', 'archived');
        } catch (Exception $e) {
            error_log('Unable to ensure archive status values: ' . $e->getMessage());
        }
    }
}

if (!function_exists('job_orders_column_exists')) {
    function job_orders_column_exists($column) {
        return app_column_exists('job_orders', $column);
    }
}

if (!function_exists('ensure_customer_branch_records_table')) {
    function ensure_customer_branch_records_table() {
        global $pdo;

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS customer_branch_records (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    customer_id INT NOT NULL,
                    branch_id INT NOT NULL,
                    status ENUM('active', 'inactive') DEFAULT 'active',
                    last_visit_at DATETIME NULL,
                    created_by INT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_customer_branch (customer_id, branch_id),
                    INDEX idx_customer_branch_records_customer (customer_id),
                    INDEX idx_customer_branch_records_branch (branch_id),
                    INDEX idx_customer_branch_records_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Exception $e) {
            error_log('Unable to create customer_branch_records table: ' . $e->getMessage());
        }
    }
}

if (!function_exists('sync_customer_branch_records')) {
    function sync_customer_branch_records() {
        global $pdo;

        try {
            $pdo->exec("
                INSERT INTO customer_branch_records (customer_id, branch_id, status, last_visit_at)
                SELECT customer_id, branch_id, 'active', MAX(last_visit_at)
                FROM (
                    SELECT id AS customer_id, branch_id, updated_at AS last_visit_at
                    FROM customers
                    WHERE branch_id IS NOT NULL AND status = 'active'

                    UNION ALL

                    SELECT customer_id, branch_id, COALESCE(created_at, quotation_date) AS last_visit_at
                    FROM quotations
                    WHERE branch_id IS NOT NULL

                    UNION ALL

                    SELECT customer_id, branch_id, COALESCE(created_at, job_date) AS last_visit_at
                    FROM job_orders
                    WHERE branch_id IS NOT NULL

                    UNION ALL

                    SELECT customer_id, branch_id, COALESCE(created_at, service_date) AS last_visit_at
                    FROM service_history
                    WHERE branch_id IS NOT NULL

                    UNION ALL

                    SELECT customer_id, branch_id, COALESCE(created_at, visit_date) AS last_visit_at
                    FROM customer_visits
                    WHERE branch_id IS NOT NULL

                    UNION ALL

                    SELECT customer_id, branch_id, updated_at AS last_visit_at
                    FROM vehicles
                    WHERE branch_id IS NOT NULL AND status = 'active'
                ) branch_sources
                WHERE customer_id IS NOT NULL AND branch_id IS NOT NULL
                GROUP BY customer_id, branch_id
                ON DUPLICATE KEY UPDATE
                    last_visit_at = GREATEST(
                        COALESCE(last_visit_at, '1970-01-01 00:00:00'),
                        COALESCE(VALUES(last_visit_at), '1970-01-01 00:00:00')
                    ),
                    updated_at = updated_at
            ");
        } catch (Exception $e) {
            error_log('Unable to sync customer branch records: ' . $e->getMessage());
        }
    }
}

if (!function_exists('ensure_vehicle_condition_column')) {
    function ensure_vehicle_condition_column() {
        try {
            global $pdo;

            if (!app_column_exists('vehicles', 'condition')) {
                $pdo->exec("ALTER TABLE vehicles ADD COLUMN `condition` ENUM('excellent', 'good', 'fair', 'poor') DEFAULT 'good' AFTER vin");
            }
        } catch (Exception $e) {
            error_log('Unable to add vehicle condition column: ' . $e->getMessage());
        }
    }
}

if (!function_exists('ensure_vehicle_ownership_history_table')) {
    function ensure_vehicle_ownership_history_table() {
        global $pdo;

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS vehicle_ownership_history (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    vehicle_id INT NOT NULL,
                    customer_id INT NOT NULL,
                    owned_from DATE NULL,
                    owned_until DATE NULL,
                    is_current TINYINT(1) NOT NULL DEFAULT 1,
                    transfer_notes TEXT NULL,
                    created_by INT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
                    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
                    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                    INDEX idx_vehicle_ownership_vehicle (vehicle_id),
                    INDEX idx_vehicle_ownership_customer (customer_id),
                    INDEX idx_vehicle_ownership_current (vehicle_id, is_current),
                    INDEX idx_vehicle_ownership_dates (owned_from, owned_until)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Exception $e) {
            error_log('Unable to create vehicle ownership history table: ' . $e->getMessage());
        }
    }
}

if (!function_exists('sync_current_vehicle_ownership_history')) {
    function sync_current_vehicle_ownership_history() {
        global $pdo;

        try {
            if (!app_table_exists('vehicle_ownership_history')) {
                return;
            }

            $pdo->exec("
                UPDATE vehicle_ownership_history h
                INNER JOIN vehicles v ON v.id = h.vehicle_id
                SET h.is_current = 0,
                    h.owned_until = COALESCE(h.owned_until, DATE(COALESCE(v.updated_at, NOW()))),
                    h.updated_at = NOW()
                WHERE h.is_current = 1
                  AND v.customer_id IS NOT NULL
                  AND h.customer_id <> v.customer_id
            ");

            $created_by_select = app_column_exists('vehicles', 'created_by_user_id')
                ? 'v.created_by_user_id'
                : 'NULL';

            $pdo->exec("
                INSERT INTO vehicle_ownership_history (
                    vehicle_id,
                    customer_id,
                    owned_from,
                    owned_until,
                    is_current,
                    transfer_notes,
                    created_by
                )
                SELECT
                    v.id,
                    v.customer_id,
                    DATE(COALESCE(v.created_at, NOW())),
                    NULL,
                    1,
                    'Current owner imported from vehicle record',
                    $created_by_select
                FROM vehicles v
                LEFT JOIN vehicle_ownership_history h
                    ON h.vehicle_id = v.id
                   AND h.customer_id = v.customer_id
                   AND h.is_current = 1
                WHERE v.customer_id IS NOT NULL
                  AND v.status = 'active'
                  AND h.id IS NULL
            ");
        } catch (Exception $e) {
            error_log('Unable to sync vehicle ownership history: ' . $e->getMessage());
        }
    }
}

if (!function_exists('ensure_inventory_transaction_tagging_columns')) {
    function ensure_inventory_transaction_tagging_columns() {
        global $pdo;

        try {
            if (!app_table_exists('inventory_transactions')) {
                return;
            }

            $columns = [
                'customer_id' => 'INT NULL AFTER created_by',
                'vehicle_id' => 'INT NULL AFTER customer_id',
                'job_order_id' => 'INT NULL AFTER vehicle_id',
                'quotation_id' => 'INT NULL AFTER job_order_id',
                'quotation_item_id' => 'INT NULL AFTER quotation_id',
            ];

            foreach ($columns as $column => $definition) {
                if (!app_column_exists('inventory_transactions', $column, true)) {
                    $pdo->exec(
                        'ALTER TABLE inventory_transactions ADD COLUMN ' .
                        app_quote_identifier($column) . ' ' . $definition
                    );
                    app_column_exists('inventory_transactions', $column, true);
                }
            }

            $indexes = [
                'idx_inv_tx_customer' => 'customer_id',
                'idx_inv_tx_vehicle' => 'vehicle_id',
                'idx_inv_tx_job' => 'job_order_id',
                'idx_inv_tx_quote' => 'quotation_id',
                'idx_inv_tx_quote_item' => 'quotation_item_id',
            ];

            foreach ($indexes as $index => $column) {
                if (!app_index_exists('inventory_transactions', $index, true)) {
                    $pdo->exec(
                        'ALTER TABLE inventory_transactions ADD INDEX ' .
                        app_quote_identifier($index) . ' (' . app_quote_identifier($column) . ')'
                    );
                    app_index_exists('inventory_transactions', $index, true);
                }
            }
        } catch (Exception $e) {
            error_log('Unable to add inventory transaction tagging columns: ' . $e->getMessage());
        }
    }
}

if (!function_exists('sync_inventory_transaction_customer_tags')) {
    function sync_inventory_transaction_customer_tags() {
        global $pdo;

        try {
            if (!app_table_exists('inventory_transactions')) {
                return;
            }

            foreach (['customer_id', 'vehicle_id', 'job_order_id', 'quotation_id', 'quotation_item_id'] as $column) {
                if (!app_column_exists('inventory_transactions', $column, true)) {
                    return;
                }
            }

            if (app_table_exists('quotations')) {
                $pdo->exec("
                    UPDATE inventory_transactions t
                    INNER JOIN quotations q ON q.id = t.reference_id
                    SET t.customer_id = COALESCE(t.customer_id, q.customer_id),
                        t.vehicle_id = COALESCE(t.vehicle_id, q.vehicle_id),
                        t.quotation_id = COALESCE(t.quotation_id, q.id)
                    WHERE t.reference_type = 'quotation'
                      AND (
                          t.customer_id IS NULL
                          OR t.vehicle_id IS NULL
                          OR t.quotation_id IS NULL
                      )
                ");
            }

            if (app_table_exists('job_orders')) {
                $pdo->exec("
                    UPDATE inventory_transactions t
                    INNER JOIN job_orders jo ON jo.id = t.reference_id
                    SET t.customer_id = COALESCE(t.customer_id, jo.customer_id),
                        t.vehicle_id = COALESCE(t.vehicle_id, jo.vehicle_id),
                        t.job_order_id = COALESCE(t.job_order_id, jo.id),
                        t.quotation_id = COALESCE(t.quotation_id, jo.quotation_id)
                    WHERE t.reference_type = 'job_order'
                      AND (
                          t.customer_id IS NULL
                          OR t.vehicle_id IS NULL
                          OR t.job_order_id IS NULL
                          OR t.quotation_id IS NULL
                      )
                ");
            }

            if (app_table_exists('quotation_items') && app_table_exists('quotations')) {
                $job_join = app_table_exists('job_orders')
                    ? "LEFT JOIN (
                            SELECT quotation_id, MIN(id) AS job_order_id
                            FROM job_orders
                            WHERE quotation_id IS NOT NULL
                            GROUP BY quotation_id
                        ) jo ON jo.quotation_id = q.id"
                    : "";

                $pdo->exec("
                    UPDATE inventory_transactions t
                    INNER JOIN quotation_items qi ON qi.id = t.reference_id
                    INNER JOIN quotations q ON q.id = qi.quotation_id
                    $job_join
                    SET t.customer_id = COALESCE(t.customer_id, q.customer_id),
                        t.vehicle_id = COALESCE(t.vehicle_id, q.vehicle_id),
                        t.quotation_id = COALESCE(t.quotation_id, q.id),
                        t.quotation_item_id = COALESCE(t.quotation_item_id, qi.id),
                        t.job_order_id = COALESCE(t.job_order_id, " . (app_table_exists('job_orders') ? 'jo.job_order_id' : 'NULL') . ")
                    WHERE t.reference_type = 'job_order_item'
                      AND (
                          t.customer_id IS NULL
                          OR t.vehicle_id IS NULL
                          OR t.quotation_id IS NULL
                          OR t.quotation_item_id IS NULL
                          OR t.job_order_id IS NULL
                      )
                ");
            }

            if (app_table_exists('inter_branch_transfer_requests')) {
                $job_join = app_table_exists('job_orders')
                    ? "LEFT JOIN (
                            SELECT quotation_id, MIN(id) AS job_order_id
                            FROM job_orders
                            WHERE quotation_id IS NOT NULL
                            GROUP BY quotation_id
                        ) jo ON jo.quotation_id = tr.quotation_id"
                    : "";

                $pdo->exec("
                    UPDATE inventory_transactions t
                    INNER JOIN inter_branch_transfer_requests tr ON tr.id = t.reference_id
                    LEFT JOIN quotations q ON q.id = tr.quotation_id
                    $job_join
                    SET t.customer_id = COALESCE(t.customer_id, tr.customer_id, q.customer_id),
                        t.vehicle_id = COALESCE(t.vehicle_id, q.vehicle_id),
                        t.quotation_id = COALESCE(t.quotation_id, tr.quotation_id),
                        t.job_order_id = COALESCE(t.job_order_id, " . (app_table_exists('job_orders') ? 'jo.job_order_id' : 'NULL') . ")
                    WHERE t.reference_type = 'inter_branch_transfer'
                      AND (
                          t.customer_id IS NULL
                          OR t.vehicle_id IS NULL
                          OR t.quotation_id IS NULL
                          OR t.job_order_id IS NULL
                      )
                ");
            }
        } catch (Exception $e) {
            error_log('Unable to sync inventory transaction customer tags: ' . $e->getMessage());
        }
    }
}

if (!function_exists('app_inventory_transaction_tag_empty_label')) {
    function app_inventory_transaction_tag_empty_label($reference_type, $transaction_type = '') {
        $type = strtolower(trim((string) $reference_type));
        $movement_type = strtolower(trim((string) $transaction_type));

        if ($movement_type === 'adjustment') {
            return 'Inventory Adjustment';
        }

        $labels = [
            'counter_sale' => 'Walk-in / Counter Sale',
            'direct_sale' => 'Walk-in / Counter Sale',
            'walk_in' => 'Walk-in / Counter Sale',
            'damaged' => 'Damaged / Defective Stock',
            'shop_use' => 'Shop Internal Use',
            'other' => 'Inventory Adjustment',
            'branch_transfer' => 'Branch Transfer',
            'inter_branch_transfer' => 'Branch Transfer',
            'supplier_delivery' => 'Supplier Delivery',
            'opening_balance' => 'Opening Balance',
            'adjustment' => 'Inventory Adjustment',
            'inventory_recount_down' => 'Inventory Adjustment',
            'inventory_recount_up' => 'Inventory Adjustment',
            'job_order' => 'Service Job Order',
            'job_order_item' => 'Service Job Order',
            'quotation' => 'Quotation Estimate',
        ];

        return $labels[$type] ?? 'Unassigned';
    }
}

if (!function_exists('ensure_job_orders_assigned_technician_name_column')) {
    function ensure_job_orders_assigned_technician_name_column() {
        try {
            global $pdo;
            if (job_orders_column_exists('assigned_technician_name')) {
                $stmt = $pdo->prepare("
                    SELECT CHARACTER_MAXIMUM_LENGTH
                    FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'job_orders'
                      AND COLUMN_NAME = 'assigned_technician_name'
                    LIMIT 1
                ");
                $stmt->execute();
                $length = $stmt->fetchColumn();

                if ($length !== false && (int) $length < 255) {
                    $pdo->exec("ALTER TABLE job_orders MODIFY COLUMN assigned_technician_name VARCHAR(255) NULL");
                }
                return;
            }

            $pdo->exec("ALTER TABLE job_orders ADD COLUMN assigned_technician_name VARCHAR(255) NULL AFTER vehicle_id");
        } catch (Exception $e) {
            error_log('Unable to add assigned_technician_name column: ' . $e->getMessage());
        }
    }
}

if (!function_exists('ensure_job_orders_estimated_duration_column')) {
    function ensure_job_orders_estimated_duration_column() {
        try {
            global $pdo;

            if (!job_orders_column_exists('estimated_duration')) {
                $pdo->exec("ALTER TABLE job_orders ADD COLUMN estimated_duration VARCHAR(120) NULL AFTER scheduled_end_time");
            }
        } catch (Exception $e) {
            error_log('Unable to add job_orders.estimated_duration column: ' . $e->getMessage());
        }
    }
}

if (!function_exists('ensure_job_orders_scheduled_end_date_column')) {
    function ensure_job_orders_scheduled_end_date_column() {
        try {
            global $pdo;

            if (!job_orders_column_exists('scheduled_end_date')) {
                $pdo->exec("ALTER TABLE job_orders ADD COLUMN scheduled_end_date DATE NULL AFTER scheduled_end_time");
            }
        } catch (Exception $e) {
            error_log('Unable to add job_orders.scheduled_end_date column: ' . $e->getMessage());
        }
    }
}

if (!function_exists('ensure_quotation_service_inspection_columns')) {
    function ensure_quotation_service_inspection_columns() {
        global $pdo;

        try {
            if (!app_column_exists('quotations', 'inspection_complaint')) {
                $pdo->exec("ALTER TABLE quotations ADD COLUMN inspection_complaint TEXT NULL AFTER valid_until");
            }

            if (!app_column_exists('quotations', 'inspection_findings')) {
                $pdo->exec("ALTER TABLE quotations ADD COLUMN inspection_findings TEXT NULL AFTER inspection_complaint");
            }

            if (!app_column_exists('quotations', 'inspection_recommendations')) {
                $pdo->exec("ALTER TABLE quotations ADD COLUMN inspection_recommendations TEXT NULL AFTER inspection_findings");
            }

            if (!app_column_exists('quotations', 'inspection_mileage')) {
                $pdo->exec("ALTER TABLE quotations ADD COLUMN inspection_mileage INT NULL AFTER inspection_recommendations");
            }
        } catch (Exception $e) {
            error_log('Unable to add quotation service inspection columns: ' . $e->getMessage());
        }
    }
}

if (!function_exists('ensure_service_catalog_table')) {
    function ensure_service_catalog_table() {
        global $pdo;

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS service_catalog (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    name VARCHAR(150) NOT NULL,
                    category VARCHAR(80) DEFAULT 'Service',
                    price DECIMAL(10, 2) DEFAULT 0.00,
                    labor_cost DECIMAL(10, 2) DEFAULT 0.00,
                    estimated_duration VARCHAR(120) NULL,
                    description TEXT NULL,
                    is_variable_price TINYINT(1) DEFAULT 0,
                    status ENUM('active', 'inactive') DEFAULT 'active',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_service_catalog_name (name),
                    INDEX idx_service_catalog_status (status),
                    INDEX idx_service_catalog_category (category)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            if (!app_column_exists('service_catalog', 'labor_cost')) {
                $pdo->exec("ALTER TABLE service_catalog ADD COLUMN labor_cost DECIMAL(10, 2) DEFAULT 0.00 AFTER price");
            }

            if (!app_column_exists('service_catalog', 'estimated_duration')) {
                $pdo->exec("ALTER TABLE service_catalog ADD COLUMN estimated_duration VARCHAR(120) NULL AFTER labor_cost");
            }

            if (!app_column_exists('service_catalog', 'description')) {
                $pdo->exec("ALTER TABLE service_catalog ADD COLUMN description TEXT NULL AFTER estimated_duration");
            }

            if (!app_column_exists('service_catalog', 'is_variable_price')) {
                $pdo->exec("ALTER TABLE service_catalog ADD COLUMN is_variable_price TINYINT(1) DEFAULT 0 AFTER description");
            }

            $defaults = [
                ['Computerized Four Wheel Alignment', 'Alignment', 1920, 1920, '1 hour full alignment; 30 minutes for 2-in/2-out adjustment', 'Full computerized four wheel alignment. 2-in/2-out adjustment starts at 480 per adjustment.', 1],
                ['Wheel Balancing / Computerized Wheel Balancing', 'Tires', 700, 700, '2 hours for 4 wheels', 'Computerized wheel balancing for four wheels.', 0],
                ['Tire Mounting and Rotation / Pneumatic Tire Mounting', 'Tires', 200, 200, '4 hours when combined with wheel balancing', 'Pneumatic tire mounting. Default labor is per tire.', 1],
                ['Under Chassis and Suspension Repair', 'Suspension', 4500, 4500, 'Minor repair 1-2 hours; full suspension replacement up to 2 days', 'Price depends on issue and material availability.', 1],
                ['Oil Change and Engine Tune-Up', 'Maintenance', 1000, 1000, '1 hour', 'Engine tune-up with oil change. Oil change only starts at 500.', 1],
                ['Suspension Parts Installation', 'Suspension', 6250, 6250, 'Up to 2 days depending on kit', 'Labor estimate based on around one-fourth of a 25000 total kit/service package.', 1],
                ['Nitrogen Air Tire Inflation', 'Tires', 100, 100, '5-10 minutes per tire', 'Nitrogen inflation, default price per tire.', 1],
                ['Battery Check-Up and Fast Charging', 'Electrical', 100, 100, '30 minutes', 'Battery check-up and fast charging.', 0],
                ['Automatic Transmission Flushing / ATF Changer Machine to Automatic Transmission', 'Transmission', 2000, 2000, '3 hours', 'ATF changer machine service for automatic transmission.', 0],
                ['Brake Cleaning and Adjustment / Brake Repair', 'Brakes', 1600, 1600, '45 minutes for 4 wheels; repair time depends on parts availability', 'Brake cleaning is 800 front and 800 rear. Repair price may vary.', 1],
                ['Manual Clutch Repair', 'Transmission', 6000, 6000, '1 day', 'Manual clutch repair labor estimate.', 1],
                ['Big Bike / Car Tire Change', 'Tires', 200, 200, '30 minutes per tire', 'Default price per tire.', 1],
                ['Car Sanitizing Service (BACKTOZERO)', 'Sanitizing', 1500, 1500, '10 minutes', 'BACKTOZERO car sanitizing service.', 0],
                ['Auto Diagnostic Scanning / Auto Diagnostic Scanner', 'Diagnostics', 1500, 1500, '5 minutes', 'Diagnostic scanning using scanner tool.', 0],
                ['Preventive Maintenance Check-Up', 'Maintenance', 2500, 2500, '1 hour 45 minutes', 'Package includes oil change plus additional check-up time.', 0],
                ['Cold Patch Vulcanizing and Tire Repair', 'Tires', 270, 270, '15 minutes per tire', 'Cold patch vulcanizing and tire repair, default price per tire.', 1],
                ['EGR Cleaning', 'Maintenance', 1200, 1200, '1 hour', 'EGR cleaning service.', 0],
                ['Car Accessories Installation', 'Accessories', 500, 500, '30 minutes to 2 hours depending on accessory and parts availability', 'Labor may be free when the accessory is purchased from the company.', 1],
                ['AC Refrigerant Charging', 'Air Conditioning', 1500, 1500, '20 minutes full charging', 'Full charging is 1500. Topping freon starts at 500.', 1],
                ['Tire Rotation', 'Tires', 400, 400, '30-40 minutes for 4 wheels', 'Default total for 4 wheels; 100 per tire.', 1],
            ];

            $service_count = (int) $pdo->query("SELECT COUNT(*) FROM service_catalog")->fetchColumn();
            if ($service_count === 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO service_catalog (
                        name, category, price, labor_cost, estimated_duration, description, is_variable_price, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
                ");

                foreach ($defaults as $service) {
                    $stmt->execute($service);
                }
            }

            $pdo->exec("
                UPDATE service_catalog
                SET labor_cost = price
                WHERE labor_cost IS NULL OR labor_cost = 0
            ");
        } catch (Exception $e) {
            error_log('Unable to create service_catalog table: ' . $e->getMessage());
        }
    }
}

if (!function_exists('ensure_customers_branch_column')) {
    function ensure_customers_branch_column() {
        global $pdo;

        try {
            if (!app_column_exists('customers', 'branch_id')) {
                $pdo->exec("ALTER TABLE customers ADD COLUMN branch_id INT NULL AFTER customer_type");
                $pdo->exec("ALTER TABLE customers ADD INDEX idx_customers_branch (branch_id)");
            }

            $pdo->exec("
                UPDATE customers c
                LEFT JOIN (
                    SELECT customer_id, MAX(branch_id) AS branch_id
                    FROM (
                        SELECT customer_id, branch_id FROM quotations WHERE branch_id IS NOT NULL
                        UNION ALL
                        SELECT customer_id, branch_id FROM job_orders WHERE branch_id IS NOT NULL
                        UNION ALL
                        SELECT customer_id, branch_id FROM customer_visits WHERE branch_id IS NOT NULL
                    ) branch_sources
                    GROUP BY customer_id
                ) source_branch ON source_branch.customer_id = c.id
                SET c.branch_id = COALESCE(source_branch.branch_id, c.branch_id, 1)
                WHERE c.branch_id IS NULL
            ");
        } catch (Exception $e) {
            error_log('Unable to add customers.branch_id column: ' . $e->getMessage());
        }
    }
}

if (!function_exists('ensure_vehicles_branch_column')) {
    function ensure_vehicles_branch_column() {
        global $pdo;

        try {
            if (!app_column_exists('vehicles', 'branch_id')) {
                $pdo->exec("ALTER TABLE vehicles ADD COLUMN branch_id INT NULL AFTER customer_id");
                $pdo->exec("ALTER TABLE vehicles ADD INDEX idx_vehicles_branch (branch_id)");
            }

            $pdo->exec("
                UPDATE vehicles v
                LEFT JOIN customers c ON c.id = v.customer_id
                SET v.branch_id = COALESCE(c.branch_id, 1)
                WHERE v.branch_id IS NULL
            ");
        } catch (Exception $e) {
            error_log('Unable to add vehicles.branch_id column: ' . $e->getMessage());
        }
    }
}

ensure_vehicle_condition_column();
ensure_job_orders_assigned_technician_name_column();
ensure_job_orders_scheduled_end_date_column();
ensure_job_orders_estimated_duration_column();
ensure_quotation_service_inspection_columns();
ensure_archive_status_values();
ensure_service_catalog_table();
ensure_customers_branch_column();
ensure_vehicles_branch_column();
ensure_vehicle_ownership_history_table();
sync_current_vehicle_ownership_history();
ensure_inventory_transaction_tagging_columns();
sync_inventory_transaction_customer_tags();
ensure_customer_branch_records_table();
sync_customer_branch_records();
?>
