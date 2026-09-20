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
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Helper to reliably fetch config from $_ENV, $_SERVER, or getenv
$get_env_val = function($key, $default = '') {
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
    $val = getenv($key);
    if ($val !== false && $val !== '') return $val;
    return $default;
};

// Database credentials (with environment variable support for cloud hosting like Render)
define('DB_HOST', $get_env_val('DB_HOST', 'localhost'));
define('DB_USER', $get_env_val('DB_USER', 'root'));
define('DB_PASS', isset($_ENV['DB_PASS']) ? $_ENV['DB_PASS'] : (isset($_SERVER['DB_PASS']) ? $_SERVER['DB_PASS'] : (getenv('DB_PASS') !== false ? getenv('DB_PASS') : '')));
define('DB_NAME', $get_env_val('DB_NAME', 'hwtires'));
define('DB_PORT', (int)($get_env_val('DB_PORT', 3306)));

// Application constants
define('APP_NAME', $get_env_val('APP_NAME', 'HW Tires Management'));
if (!defined('APP_URL')) {
    $env_app_url = $get_env_val('APP_URL', null);
    if ($env_app_url !== null && $env_app_url !== '') {
        define('APP_URL', rtrim($env_app_url, '/'));
    } else {
        // Auto-detect root vs subfolder
        $doc_root = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : '';
        $app_root = realpath(__DIR__ . '/..');
        define('APP_URL', ($doc_root && $app_root && $doc_root === $app_root) ? '' : '/hwtires');
    }
}
define('APP_TIMEZONE', $get_env_val('APP_TIMEZONE', 'Asia/Manila'));
date_default_timezone_set(APP_TIMEZONE);

// Session configuration
define('SESSION_TIMEOUT', 3600); // Default fallback session timeout in seconds
define('SESSION_NAME', 'hwtires_session');

// Pagination
define('RECORDS_PER_PAGE', 25);

// Database connection (only once)
if (!isset($pdo)) {
    try {
        $pdo_options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];

        // Enable SSL/TLS encryption for remote cloud databases (TiDB Cloud, Aiven, etc.)
        if (DB_HOST !== 'localhost' && DB_HOST !== '127.0.0.1') {
            if (defined('PDO::MYSQL_ATTR_SSL_CA')) {
                $pdo_options[PDO::MYSQL_ATTR_SSL_CA] = true;
            }
            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $pdo_options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }
        }

        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            $pdo_options
        );
    } catch (PDOException $e) {
        // Log detailed error to local debug file
        $log_entry = date('Y-m-d H:i:s') . ' | Database Connection Error: ' . $e->getMessage() . " | Host: " . DB_HOST . " | Port: " . DB_PORT . " | User: " . DB_USER . "\n";
        @file_put_contents(__DIR__ . '/../debug_db_error.log', $log_entry, FILE_APPEND);
        error_log('Database Connection Error: ' . $e->getMessage());

        // Check if running in local development or if debug parameter is requested
        $is_debug = (php_sapi_name() === 'cli') || 
                    (isset($_SERVER['SERVER_NAME']) && in_array($_SERVER['SERVER_NAME'], ['localhost', '127.0.0.1', '::1'])) ||
                    (isset($_GET['debug_db']) && $_GET['debug_db'] === '1');

        if ($is_debug) {
            $env_status = file_exists(__DIR__ . '/../.env') ? 'Found (.env exists)' : '<span style="color:red;">MISSING (.env does not exist in project root!)</span>';
            die('<!DOCTYPE html><html><head><title>Database Connection Error</title><style>body{font-family:sans-serif;padding:30px;background:#f8f9fa;color:#333;} .box{background:#fff;border-left:4px solid #dc3545;padding:20px;border-radius:4px;box-shadow:0 2px 4px rgba(0,0,0,0.1);max-width:700px;margin:auto;}</style></head><body><div class="box"><h2>Database Connection Failed</h2><p><b>Error:</b> ' . htmlspecialchars($e->getMessage()) . '</p><p><b>.env Status:</b> ' . $env_status . '<br><b>Host:</b> ' . htmlspecialchars(DB_HOST) . '<br><b>Port:</b> ' . htmlspecialchars(DB_PORT) . '<br><b>Database:</b> ' . htmlspecialchars(DB_NAME) . '<br><b>User:</b> ' . htmlspecialchars(DB_USER) . '<br><b>Password set:</b> ' . (strlen(DB_PASS) > 0 ? 'YES (' . strlen(DB_PASS) . ' chars)' : '<span style="color:red;">NO (Empty)</span>') . '</p><p style="color:#6c757d;font-size:13px;">Verify your <code>.env</code> file in <code>public_html/.env</code> on Hostinger.</p></div></body></html>');
        }

        die('Database connection failed. Please contact administrator. <!-- Add ?debug_db=1 to URL to diagnose -->');
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

if (!function_exists('get_csrf_token')) {
    function get_csrf_token() {
        return generate_csrf_token();
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
 * Generate cryptographically secure temporary password
 */
if (!function_exists('app_generate_temporary_password')) {
    function app_generate_temporary_password($length = 12) {
        $length = max(10, min(32, (int) $length));
        
        $uppers = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lowers = 'abcdefghjkmnpqrstuvwxyz';
        $digits = '23456789';
        $symbols = '!@#$%&*+?';
        
        $pwd = [
            $uppers[random_int(0, strlen($uppers) - 1)],
            $uppers[random_int(0, strlen($uppers) - 1)],
            $lowers[random_int(0, strlen($lowers) - 1)],
            $lowers[random_int(0, strlen($lowers) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $symbols[random_int(0, strlen($symbols) - 1)],
            $symbols[random_int(0, strlen($symbols) - 1)],
        ];
        
        $all = $uppers . $lowers . $digits . $symbols;
        $all_len = strlen($all);
        
        while (count($pwd) < $length) {
            $pwd[] = $all[random_int(0, $all_len - 1)];
        }
        
        for ($i = count($pwd) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            $temp = $pwd[$i];
            $pwd[$i] = $pwd[$j];
            $pwd[$j] = $temp;
        }
        
        return implode('', $pwd);
    }
}

/**
 * Validate password strength against system complexity requirements.
 * Requirements:
 * - Minimum 8 characters
 * - At least 1 uppercase letter (A-Z)
 * - At least 1 lowercase letter (a-z)
 * - At least 1 digit (0-9)
 * - At least 1 special character (non-alphanumeric, non-whitespace)
 *
 * @param string $password
 * @return array List of error messages, or empty array if valid.
 */
if (!function_exists('app_validate_password_strength')) {
    function app_validate_password_strength(string $password): array {
        $errors = [];

        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must include at least one uppercase letter.';
        }

        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must include at least one lowercase letter.';
        }

        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must include at least one number.';
        }

        if (!preg_match('/[^A-Za-z0-9\s]/', $password)) {
            $errors[] = 'Password must include at least one special character.';
        }

        return $errors;
    }
}

/**
 * Check login attempt lockout (rate limiting)
 */
if (!function_exists('app_check_login_lockout')) {
    function app_check_login_lockout(PDO $pdo, $email, $ip) {
        $email = strtolower(trim((string) $email));
        $ip = trim((string) $ip);
        if ($email === '' || $ip === '') {
            return ['locked' => false, 'remaining_seconds' => 0, 'attempts' => 0];
        }

        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS attempts, MIN(attempted_at) AS oldest_attempt
                FROM login_attempts
                WHERE email = ? AND ip_address = ?
                  AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            ");
            $stmt->execute([$email, $ip]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $attempts = (int) ($row['attempts'] ?? 0);
            if ($attempts >= 5) {
                $oldest = !empty($row['oldest_attempt']) ? strtotime($row['oldest_attempt']) : time();
                $elapsed = time() - $oldest;
                $remaining = max(1, 900 - $elapsed);
                return ['locked' => true, 'remaining_seconds' => $remaining, 'attempts' => $attempts];
            }

            return ['locked' => false, 'remaining_seconds' => 0, 'attempts' => $attempts];
        } catch (Exception $e) {
            error_log('Login lockout check error: ' . $e->getMessage());
            return ['locked' => false, 'remaining_seconds' => 0, 'attempts' => 0];
        }
    }
}

/**
 * Record a failed login attempt
 */
if (!function_exists('app_record_failed_login')) {
    function app_record_failed_login(PDO $pdo, $email, $ip) {
        $email = strtolower(trim((string) $email));
        $ip = trim((string) $ip);
        if ($email === '' || $ip === '') {
            return;
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO login_attempts (ip_address, email, attempted_at) VALUES (?, ?, NOW())");
            $stmt->execute([$ip, $email]);

            if (random_int(1, 10) === 1) {
                $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            }
        } catch (Exception $e) {
            error_log('Record failed login error: ' . $e->getMessage());
        }
    }
}

/**
 * Clear failed login attempts for an account on success
 */
if (!function_exists('app_clear_failed_logins')) {
    function app_clear_failed_logins(PDO $pdo, $email, $ip) {
        $email = strtolower(trim((string) $email));
        $ip = trim((string) $ip);
        if ($email === '' || $ip === '') {
            return;
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE email = ? AND ip_address = ?");
            $stmt->execute([$email, $ip]);
        } catch (Exception $e) {
            error_log('Clear failed logins error: ' . $e->getMessage());
        }
    }
}

/**
 * Retrieve effective session inactivity timeout in seconds.
 * Allowed values: 900 (15m), 1800 (30m), 2700 (45m), 3600 (1h), 7200 (2h).
 * Falls back to 3600 if missing, corrupt, invalid, or database is unavailable.
 */
if (!function_exists('app_get_session_timeout')) {
    function app_get_session_timeout(?PDO $pdo_conn = null, bool $refresh = false): int {
        static $cached_timeout = null;

        if ($cached_timeout !== null && !$refresh) {
            return $cached_timeout;
        }

        $allowed_timeouts = [900, 1800, 2700, 3600, 7200];
        $default_timeout = defined('SESSION_TIMEOUT') ? (int) SESSION_TIMEOUT : 3600;

        try {
            $conn = $pdo_conn;
            if (!$conn) {
                global $pdo;
                $conn = $pdo ?? null;
            }

            if ($conn instanceof PDO) {
                $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'session_inactivity_timeout' LIMIT 1");
                $stmt->execute();
                $val = $stmt->fetchColumn();

                if ($val !== false && is_numeric($val)) {
                    $val_int = (int) $val;
                    if (in_array($val_int, $allowed_timeouts, true)) {
                        $cached_timeout = $val_int;
                        return $cached_timeout;
                    }
                }
            }
        } catch (Throwable $e) {
            // Gracefully degrade to default timeout on any database error
        }

        $cached_timeout = $default_timeout;
        return $cached_timeout;
    }
}

/**
 * Check if user is logged in and enforce session security
 */
if (!function_exists('is_logged_in')) {
    function is_logged_in() {
        $user = app_get_session_user();
        if (!is_array($user) || empty($user) || empty($user['id'])) {
            return false;
        }

        $timeout = app_get_session_timeout();
        if ($timeout > 0) {
            $last_act = $_SESSION['last_activity'] ?? null;
            if ($last_act !== null && (time() - (int) $last_act > $timeout)) {
                if (function_exists('log_audit')) {
                    log_audit('users', 'logout', (int) $user['id'], null, ['reason' => 'inactivity_timeout']);
                }
                $_SESSION = [];
                if (ini_get("session.use_cookies")) {
                    $params = session_get_cookie_params();
                    setcookie(
                        session_name(),
                        '',
                        time() - 42000,
                        $params["path"] ?? '/',
                        $params["domain"] ?? '',
                        $params["secure"] ?? false,
                        $params["httponly"] ?? true
                    );
                }
                session_destroy();
                if (!headers_sent() && php_sapi_name() !== 'cli') {
                    redirect(APP_URL . '/index.php?timeout=1');
                }
                return false;
            }
            $_SESSION['last_activity'] = time();
        }

        if (!empty($user['must_change_password'])) {
            $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            $is_allowed = ($script === 'force-change-password.php' || $script === 'logout.php' || strpos($uri, 'logout.php') !== false);
            if (!$is_allowed && !headers_sent() && php_sapi_name() !== 'cli') {
                redirect(APP_URL . '/force-change-password.php');
            }
        }

        return true;
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
 * Format UTC datetime string in Philippine Time (PHT / Asia/Manila)
 */
if (!function_exists('app_format_datetime_pht')) {
    function app_format_datetime_pht($utc_datetime_str, $format = 'M d, Y h:i A') {
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

/**
 * Log user action for audit trail
 */
if (!function_exists('log_audit')) {
    function log_audit($table_name, $action, $record_id, $old_values = null, $new_values = null) {
        global $pdo;
        $user = app_get_session_user();

        // Automatically sanitize sensitive fields (passwords, hashes, tokens)
        $sanitize_payload = function($payload) use (&$sanitize_payload) {
            if (!is_array($payload)) {
                return $payload;
            }
            $sensitive_keys = ['password', 'password_hash', 'password_confirm', 'temp_password', 'csrf_token'];
            $clean = [];
            foreach ($payload as $k => $v) {
                if (in_array(strtolower((string) $k), $sensitive_keys, true)) {
                    continue;
                }
                if (is_array($v)) {
                    $clean[$k] = $sanitize_payload($v);
                } else {
                    $clean[$k] = $v;
                }
            }
            return $clean;
        };

        $clean_old = $old_values ? $sanitize_payload($old_values) : null;
        $clean_new = $new_values ? $sanitize_payload($new_values) : null;

        try {
            $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $user['id'] ?? null,
                $action,
                $table_name,
                $record_id,
                $clean_old ? json_encode($clean_old) : null,
                $clean_new ? json_encode($clean_new) : null,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            error_log('Audit log error: ' . $e->getMessage());
        }
    }
}

/**
 * Prevent caching of sensitive pages in browser bfcache
 */
if (!function_exists('app_send_no_cache_headers')) {
    function app_send_no_cache_headers() {
        if (!headers_sent()) {
            header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0");
            header("Pragma: no-cache");
            header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
        }
    }
}

/**
 * Redirect to a page
 */
if (!function_exists('redirect')) {
    function redirect($url) {
        app_send_no_cache_headers();
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

            if (preg_match('/^(?:technicians?(?:\(s\))?(?:\s+assigned)?|assigned\s+technicians?(?:\(s\))?)\s*:\s*(.+)$/i', $part, $match)) {
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

if (!function_exists('app_display_item_name')) {
    function app_display_item_name($name, $category = null) {
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }
        $cat = strtolower(trim((string) $category));
        if ($cat === '' || $cat === 'tire' || $cat === 'tires') {
            $cleaned = preg_replace('/\s+tires?$/i', '', $name);
            if ($cleaned !== null && $cleaned !== '') {
                return $cleaned;
            }
        }
        return $name;
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

            if (preg_match('/^sales(?:\s+in\s+charge)?\s*:/i', $part) || preg_match('/^(?:technicians?(?:\(s\))?(?:\s+assigned)?|assigned\s+technicians?(?:\(s\))?)\s*:/i', $part)) {
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

if (!function_exists('app_get_builtin_vehicle_catalog')) {
    /**
     * Built-in Vehicle Make & Model Catalog (Baseline)
     */
    function app_get_builtin_vehicle_catalog(): array {
        return [
            "Audi" => ["A1", "A3", "A4", "A5", "A6", "A7", "A8", "Q2", "Q3", "Q5", "Q7", "Q8", "e-tron", "TT", "R8"],
            "BMW" => ["1 Series", "2 Series", "3 Series", "4 Series", "5 Series", "6 Series", "7 Series", "8 Series", "X1", "X2", "X3", "X4", "X5", "X6", "X7", "Z4", "M2", "M3", "M4", "M5", "M8", "i4", "iX", "iX3", "i7"],
            "BYD" => ["Atto 3", "Dolphin", "Seal", "Sealion 6", "Tang", "Han", "Song Plus", "Yuan Plus"],
            "Changan" => ["Alsvin", "CS35 Plus", "CS55 Plus", "UNI-T", "UNI-K", "UNI-V", "X7 Plus"],
            "Chery" => ["Tiggo 2", "Tiggo 2 Pro", "Tiggo 5X", "Tiggo 5X Pro", "Tiggo 7 Pro", "Tiggo 8 Pro", "Arrizo 5"],
            "Chevrolet" => ["Captiva", "Colorado", "Corvette", "Cruze", "Optra", "Sail", "Spark", "Suburban", "Tahoe", "Tracker", "Trailblazer", "Trax"],
            "Ford" => ["EcoSport", "Escape", "Everest", "Expedition", "Explorer", "F-150", "Fiesta", "Focus", "Lynx", "Mustang", "Ranger", "Ranger Raptor", "Territory", "Transit"],
            "Foton" => ["Gratour", "Thunder", "Toplander", "Tornado", "Transvan", "Traveller", "View Transvan"],
            "GAC" => ["Empow", "Emkoo", "GA4", "GN6", "GS3", "GS3 Emzoom", "GS4", "GS8", "M6 Pro", "M8"],
            "Geely" => ["Azkarra", "Coolray", "Emgrand", "GX3 Pro", "Monjaro", "Okavango", "Tugella"],
            "Hino" => ["300 Series", "500 Series", "700 Series", "Dutro"],
            "Honda" => ["Accord", "BR-V", "Brio", "City", "City Hatchback", "Civic", "Civic Type R", "CR-V", "CR-Z", "HR-V", "Jazz", "Mobilio", "Odyssey", "Pilot"],
            "Hyundai" => ["Accent", "Creta", "Custin", "Elantra", "Grand Starex", "H-100", "Ioniq 5", "Ioniq 6", "Kona", "Santa Fe", "Sonata", "Stargazer", "Staria", "Tucson", "Venue"],
            "Isuzu" => ["Crosswind", "D-Max", "D-Max Boondock", "Highlander", "mu-X", "N-Series (Elf)", "Panther", "Traviz", "Trooper"],
            "Jaguar" => ["E-Pace", "F-Pace", "F-Type", "I-Pace", "XE", "XF", "XJ"],
            "Jeep" => ["Cherokee", "Compass", "Gladiator", "Grand Cherokee", "Renegade", "Wrangler", "Wrangler Rubicon"],
            "Kia" => ["Carens", "Carnival", "EV6", "Forte", "K2500", "Picanto", "Rio", "Seltos", "Soluto", "Sonet", "Sorento", "Soul", "Sportage", "Stonic"],
            "Land Rover" => ["Defender", "Discovery", "Discovery Sport", "Range Rover", "Range Rover Evoque", "Range Rover Sport", "Range Rover Velar"],
            "Lexus" => ["ES", "GX", "IS", "LC", "LBX", "LM", "LS", "LX", "NX", "RC", "RX", "UX"],
            "Mazda" => ["BT-50", "CX-3", "CX-30", "CX-5", "CX-60", "CX-8", "CX-9", "CX-90", "Mazda 2", "Mazda 3", "Mazda 6", "MX-5 Miata"],
            "Mercedes-Benz" => ["A-Class", "AMG GT", "B-Class", "C-Class", "CLA", "CLE", "CLS", "E-Class", "G-Class", "GLA", "GLB", "GLC", "GLE", "GLS", "S-Class", "SLK / SLC", "Sprinter", "V-Class"],
            "MG" => ["MG 3", "MG 4 EV", "MG 5", "MG Cyberster", "MG GT", "MG HS", "MG One", "MG ZS", "MG ZS EV", "RX5"],
            "Mini" => ["Clubman", "Cooper", "Cooper S", "Countryman", "John Cooper Works"],
            "Mitsubishi" => ["Adventure", "Eclipse Cross", "Grandis", "L200", "L300", "Mirage", "Mirage G4", "Montero Sport", "Outlander", "Pajero", "Strada", "Triton", "Xforce", "Xpander", "Xpander Cross"],
            "Nissan" => ["Almera", "Cefiro", "GT-R", "Juke", "Kicks e-Power", "Livina", "Navara", "Patrol", "Patrol Royale", "Sentra", "Sylphy", "Terra", "Urvan / NV350", "X-Trail"],
            "Peugeot" => ["2008", "3008", "5008", "508", "Traveller"],
            "Porsche" => ["718 Boxster", "718 Cayman", "911", "Cayenne", "Macan", "Panamera", "Taycan"],
            "Subaru" => ["BRZ", "Crosstrek", "Evoltis", "Forester", "Impreza", "Legacy", "Levorg", "Outback", "WRX", "XV"],
            "Suzuki" => ["Alto", "APV", "Carry", "Celerio", "Ciaz", "Dzire", "Ertiga", "Ertiga Hybrid", "Grand Vitara", "Jimny", "S-Presso", "Swift", "SX4", "Vitara", "XL7"],
            "Toyota" => ["86", "Alphard", "Avanza", "Camry", "Corolla Altis", "Corolla Cross", "Fortuner", "GR 86", "GR Yaris", "Hiace", "Hiace Commuter", "Hiace Super Grandia", "Hilux", "Hilux Conquest", "Hilux GR-S", "Innova", "Land Cruiser 300", "Land Cruiser Prado", "Prius", "Raize", "RAV4", "Revo", "Rush", "Supra", "Tamaraw FX", "Veloz", "Vios", "Wigo", "Yaris", "Yaris Cross"],
            "Volkswagen" => ["Beetle", "Golf", "Lamando", "Lavida", "Passat", "Polo", "Santana", "T-Cross", "Teramont", "Tiguan", "Transporter"],
            "Volvo" => ["C40 Recharge", "S60", "S90", "V60", "V90", "XC40", "XC60", "XC90"]
        ];
    }
}

if (!function_exists('app_get_custom_vehicle_catalog')) {
    /**
     * Retrieve custom vehicle makes and models from system_settings table
     */
    function app_get_custom_vehicle_catalog(): array {
        global $pdo;
        if (!($pdo instanceof PDO)) {
            return [];
        }
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'custom_vehicle_catalog' ORDER BY updated_at DESC LIMIT 1");
            $stmt->execute();
            $raw = $stmt->fetchColumn();
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        } catch (Exception $e) {
            error_log('Error reading custom_vehicle_catalog: ' . $e->getMessage());
        }
        return [];
    }
}

if (!function_exists('app_normalize_vehicle_catalog_text')) {
    /**
     * Normalize custom catalog make/model string
     */
    function app_normalize_vehicle_catalog_text(string $text): string {
        $clean = trim($text);
        $clean = preg_replace('/\s+/', ' ', $clean);
        if (mb_strlen($clean, 'UTF-8') > 50) {
            $clean = mb_substr($clean, 0, 50, 'UTF-8');
        }
        if ($clean !== '') {
            $is_all_lower = ($clean === mb_strtolower($clean, 'UTF-8'));
            $is_all_upper = ($clean === mb_strtoupper($clean, 'UTF-8'));
            $len = mb_strlen($clean, 'UTF-8');

            if ($is_all_lower) {
                $clean = mb_convert_case($clean, MB_CASE_TITLE, 'UTF-8');
            } elseif ($is_all_upper && $len > 3) {
                $clean = mb_convert_case(mb_strtolower($clean, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
            }
        }
        return $clean;
    }
}

if (!function_exists('app_persist_custom_vehicle_make_model')) {
    /**
     * Persist new custom vehicle make and model into system_settings if not already present
     */
    function app_persist_custom_vehicle_make_model(string $make, string $model): array {
        global $pdo;

        $norm_make = app_normalize_vehicle_catalog_text($make);
        $norm_model = app_normalize_vehicle_catalog_text($model);

        if ($norm_make === '' || $norm_model === '') {
            return [
                'make' => $norm_make,
                'model' => $norm_model,
                'added_make' => false,
                'added_model' => false
            ];
        }

        $builtin_catalog = app_get_builtin_vehicle_catalog();
        $custom_catalog = app_get_custom_vehicle_catalog();

        // 1. Check if make exists in built-in (case-insensitive)
        $canonical_make = null;
        $is_builtin_make = false;
        foreach ($builtin_catalog as $b_make => $b_models) {
            if (strcasecmp($b_make, $norm_make) === 0) {
                $canonical_make = $b_make;
                $is_builtin_make = true;
                break;
            }
        }

        // 2. If not in built-in, check in custom catalog (case-insensitive)
        if ($canonical_make === null) {
            foreach ($custom_catalog as $c_make => $c_models) {
                if (strcasecmp($c_make, $norm_make) === 0) {
                    $canonical_make = $c_make;
                    break;
                }
            }
        }

        $added_make = false;
        if ($canonical_make === null) {
            $canonical_make = $norm_make;
            $custom_catalog[$canonical_make] = [];
            $added_make = true;
        }

        // 3. Check if model exists under canonical_make
        $canonical_model = null;
        if ($is_builtin_make && isset($builtin_catalog[$canonical_make])) {
            foreach ($builtin_catalog[$canonical_make] as $b_mod) {
                if (strcasecmp($b_mod, $norm_model) === 0) {
                    $canonical_model = $b_mod;
                    break;
                }
            }
        }

        if ($canonical_model === null && isset($custom_catalog[$canonical_make]) && is_array($custom_catalog[$canonical_make])) {
            foreach ($custom_catalog[$canonical_make] as $c_mod) {
                if (strcasecmp($c_mod, $norm_model) === 0) {
                    $canonical_model = $c_mod;
                    break;
                }
            }
        }

        $added_model = false;
        if ($canonical_model === null) {
            $canonical_model = $norm_model;
            if (!isset($custom_catalog[$canonical_make]) || !is_array($custom_catalog[$canonical_make])) {
                $custom_catalog[$canonical_make] = [];
            }
            $custom_catalog[$canonical_make][] = $canonical_model;
            $added_model = true;
        }

        // 4. Save to system_settings and log audit if any addition occurred
        if ($added_make || $added_model) {
            if ($pdo instanceof PDO) {
                try {
                    $json = json_encode($custom_catalog, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

                    $check_stmt = $pdo->prepare("SELECT 1 FROM system_settings WHERE setting_key = 'custom_vehicle_catalog' LIMIT 1");
                    $check_stmt->execute();
                    if ($check_stmt->fetchColumn()) {
                        $update_stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = 'custom_vehicle_catalog'");
                        $update_stmt->execute([$json]);
                    } else {
                        $insert_stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES ('custom_vehicle_catalog', ?, NOW())");
                        $insert_stmt->execute([$json]);
                    }

                    if ($added_make) {
                        log_audit('system_settings', 'create', 0, null, [
                            'setting_key' => 'custom_vehicle_catalog',
                            'action_description' => 'Added vehicle make: ' . $canonical_make,
                            'make' => $canonical_make
                        ]);
                    }
                    if ($added_model) {
                        log_audit('system_settings', 'create', 0, null, [
                            'setting_key' => 'custom_vehicle_catalog',
                            'action_description' => 'Added vehicle model: ' . $canonical_make . ' ' . $canonical_model,
                            'make' => $canonical_make,
                            'model' => $canonical_model
                        ]);
                    }
                } catch (Exception $e) {
                    error_log('Error saving custom vehicle catalog: ' . $e->getMessage());
                }
            }
        }

        return [
            'make' => $canonical_make,
            'model' => $canonical_model,
            'added_make' => $added_make,
            'added_model' => $added_model
        ];
    }
}

if (!function_exists('app_compose_philippine_address')) {
    /**
     * Compose standardized Philippine address string from components
     * Format: {Street}, {Barangay}, {City/Municipality}, {Province}, {Region}
     */
    function app_compose_philippine_address(string $street, string $barangay, string $city, string $province, string $region): string {
        $parts = array_filter([
            trim($street),
            trim($barangay),
            trim($city),
            trim($province),
            trim($region)
        ], function ($v) {
            return $v !== '';
        });
        return implode(', ', $parts);
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

if (!function_exists('app_search_term_variants')) {
    function app_search_term_variants($term) {
        $term = trim((string) $term);
        if ($term === '') {
            return [];
        }
        $variants = [$term];

        // 1. Collapse duplicate letters (e.g. 'montellbano' -> 'montelbano')
        $dedup = preg_replace('/(.)\\1+/u', '$1', $term);
        if ($dedup !== $term && $dedup !== '') {
            $variants[] = $dedup;
        }

        // 2. Common Philippine surname & word spelling variations:
        // 'montelibano' / 'montellibano' / 'montellbano' / 'montelbano'
        if (preg_match('/^monte?ll?e?i?bano$/i', $term)) {
            $variants[] = 'montelibano';
        }
        // 'javellana' / 'javelana'
        if (preg_match('/^jave?ll?ana$/i', $term)) {
            $variants[] = 'javellana';
        }
        // 'castillo' / 'castilo'
        if (preg_match('/^casti?ll?o$/i', $term)) {
            $variants[] = 'castillo';
        }
        // 'escalante' / 'escalate'
        if (preg_match('/^escala?n?te$/i', $term)) {
            $variants[] = 'escalante';
        }

        return array_values(array_unique($variants));
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

if (!function_exists('app_inventory_transaction_source_display')) {
    function app_inventory_transaction_source_display(array $transaction) {
        $type = strtolower(trim((string) ($transaction['transaction_type'] ?? '')));
        $ref_type = strtolower(trim((string) ($transaction['reference_type'] ?? '')));
        $notes = trim((string) ($transaction['notes'] ?? ''));
        $supplier_name = trim((string) ($transaction['supplier_name'] ?? ''));

        if ($type === 'stock_in') {
            if ($ref_type === 'tangub_warehouse' || stripos($notes, 'Tangub Central Warehouse') !== false || stripos($notes, 'Tangub Warehouse') !== false) {
                return 'Central Warehouse (Tangub Hub)';
            }
            if ($ref_type === 'sancarlos_warehouse' || stripos($notes, 'San Carlos Warehouse') !== false) {
                return 'Auxiliary Warehouse (San Carlos Hub)';
            }
            if ($ref_type === 'branch_transfer' || $ref_type === 'inter_branch_transfer' || stripos($notes, 'received from') !== false) {
                if (preg_match('/received from\s+([^,;|\.]+)/i', $notes, $m)) {
                    return 'Stock Transfer — ' . trim($m[1]);
                }
                if ($supplier_name !== '') {
                    return 'Stock Transfer — ' . $supplier_name;
                }
                return 'Stock Transfer from Other Branch';
            }
            if ($ref_type === 'adjustment' || stripos($notes, 'Physical Count') !== false || stripos($notes, 'Adjustment') !== false) {
                return 'Physical Inventory Adjustment';
            }
            if ($ref_type === 'initial_stock' || stripos($notes, 'Initial stock') !== false) {
                return 'Initial Stock Setup';
            }
            if ($ref_type === 'quotation') {
                return 'Quotation Reversal';
            }

            // Direct supplier delivery
            if (preg_match('/Supplier Delivery[^:]*:\s*([^|;]+)/i', $notes, $m)) {
                $parsed_sup = trim($m[1]);
                if ($parsed_sup !== '') {
                    return 'Direct Supplier Delivery — ' . $parsed_sup;
                }
            }
            if ($supplier_name !== '') {
                return 'Direct Supplier Delivery — ' . $supplier_name;
            }
            return 'Direct Supplier Delivery (Manila / Distributor)';
        }

        // For stock out and other movements, fallback to standard tag label
        return app_inventory_transaction_tag_empty_label($ref_type, $type);
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

// Execute database schema setup and sync only when explicitly requested or via CLI
if (getenv('RUN_MIGRATIONS') === 'true' || (php_sapi_name() === 'cli' && empty($_SERVER['HTTP_HOST']))) {
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
}
?>
