<?php
/**
 * Forecasting API Handler
 * Inventory forecasting and analytics
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/forecasting.php';
session_name(SESSION_NAME);
session_start();

// Check authentication
if (!is_logged_in()) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$user = app_get_session_user();
$action = $_POST['action'] ?? $_GET['action'] ?? null;

// Handle Export Forecasting Recommendations (CSV)
if ($action === 'export_recommendations' || $action === 'export_csv') {
    try {
        $branches = forecast_load_inventory_branches($pdo, $user);
        $allowed_branch_ids = array_map(static function ($branch) {
            return (int) $branch['id'];
        }, $branches);

        if (($user['role'] ?? '') !== 'admin') {
            // Front Desk is strictly constrained to session branch ID
            $branch_filter = (string) (int) ($user['branch_id'] ?? 0);
            $allowed_branch_ids = array_values(array_intersect($allowed_branch_ids, [(int) ($user['branch_id'] ?? 0)]));
        } else {
            $branch_filter = trim($_GET['branch'] ?? $_GET['branch_id'] ?? 'all');
            if ($branch_filter === '0' || $branch_filter === '') {
                $branch_filter = 'all';
            }
            if ($branch_filter !== 'all' && !has_branch_access((int) $branch_filter)) {
                throw new Exception('Unauthorized access');
            }
        }

        $forecast = forecast_build_inventory_dss($pdo, [
            'category' => $_GET['category'] ?? 'all',
            'brand' => $_GET['brand'] ?? '',
            'size' => $_GET['size'] ?? '',
            'branch' => $branch_filter,
            'status' => $_GET['status'] ?? 'all',
            'search' => $_GET['search'] ?? '',
            'year' => $_GET['year'] ?? 'latest',
            'view' => $_GET['view'] ?? 'weekly',
            'sort' => $_GET['sort'] ?? 'urgency',
            'allowed_branch_ids' => $allowed_branch_ids,
        ]);

        forecast_send_csv($forecast);
        exit;

    } catch (Exception $e) {
        error_log('Forecast export error: ' . $e->getMessage());
        http_response_code(400);
        die('Export failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }
}

// Handle Get Inventory Forecast and Decision Support
if ($action === 'forecast_inventory' || $action === 'decision_support') {
    try {
        $branches = forecast_load_inventory_branches($pdo, $user);
        $allowed_branch_ids = array_map(static function ($branch) {
            return (int) $branch['id'];
        }, $branches);

        if (($user['role'] ?? '') !== 'admin') {
            $branch_filter = (string) (int) ($user['branch_id'] ?? 0);
            $allowed_branch_ids = array_values(array_intersect($allowed_branch_ids, [(int) ($user['branch_id'] ?? 0)]));
        } else {
            $branch_filter = trim($_GET['branch'] ?? $_GET['branch_id'] ?? 'all');
            if ($branch_filter === '0' || $branch_filter === '') {
                $branch_filter = 'all';
            }
            if ($branch_filter !== 'all' && !has_branch_access((int) $branch_filter)) {
                throw new Exception('Unauthorized access');
            }
        }

        $forecast = forecast_build_inventory_dss($pdo, [
            'category' => $_GET['category'] ?? 'all',
            'brand' => $_GET['brand'] ?? '',
            'size' => $_GET['size'] ?? '',
            'branch' => $branch_filter,
            'status' => $_GET['status'] ?? 'all',
            'search' => $_GET['search'] ?? '',
            'year' => $_GET['year'] ?? 'latest',
            'view' => $_GET['view'] ?? 'weekly',
            'sort' => $_GET['sort'] ?? 'urgency',
            'allowed_branch_ids' => $allowed_branch_ids,
        ]);

        http_response_code(200);
        die(json_encode([
            'success' => true,
            'data' => $forecast,
        ]));

    } catch (Exception $e) {
        error_log('Forecast inventory error: ' . $e->getMessage());
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
}

// Handle Get Job Analytics
if ($action === 'job_analytics') {
    try {
        $branch_id = intval($_GET['branch_id'] ?? $user['branch_id']);
        $days = intval($_GET['days'] ?? 30);

        // Check authorization
        if (!has_branch_access($branch_id)) {
            throw new Exception('Unauthorized access');
        }

        // Get job completion rate
        $stmt = $pdo->prepare("
            SELECT
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
                COUNT(CASE WHEN status = 'in-progress' THEN 1 END) as in_progress,
                COUNT(CASE WHEN status = 'waiting' THEN 1 END) as waiting,
                COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled
            FROM job_orders
            WHERE branch_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ");

        $stmt->execute([$branch_id, $days]);
        $analytics = $stmt->fetch();

        // Get daily job creation trend
        $stmt = $pdo->prepare("
            SELECT DATE(created_at) as date, COUNT(*) as jobs
            FROM job_orders
            WHERE branch_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY DATE(created_at)
            ORDER BY DATE(created_at) DESC
        ");

        $stmt->execute([$branch_id, $days]);
        $daily_trend = $stmt->fetchAll();

        $response = [
            'success' => true,
            'data' => [
                'summary' => $analytics,
                'trend' => $daily_trend
            ]
        ];

        http_response_code(200);
        die(json_encode($response));

    } catch (Exception $e) {
        error_log('Job analytics error: ' . $e->getMessage());
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => $e->getMessage()]));
    }
}

// Invalid action
http_response_code(400);
die(json_encode(['success' => false, 'message' => 'Invalid action']));
?>
