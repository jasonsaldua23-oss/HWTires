<?php
/**
 * Highway Tires Management System
 * Database Configuration Template (Example)
 *
 * Copy this file to includes/config.php and update with your local environment credentials.
 */

// Database credentials
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'hwtires');
define('DB_PORT', 3306);

// Application constants
define('APP_NAME', 'HW Tires Management');
if (!defined('APP_URL')) {
    define('APP_URL', '/hwtires');
}
define('APP_TIMEZONE', 'Asia/Manila');

// Session configuration
define('SESSION_TIMEOUT', 3600); // 1 hour in seconds
define('SESSION_NAME', 'hwtires_session');

// Pagination
define('RECORDS_PER_PAGE', 25);

// Database connection
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
        error_log('Database Connection Error: ' . $e->getMessage());
        die('Database connection failed. Please contact administrator.');
    }
}
