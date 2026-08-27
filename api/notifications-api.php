<?php
/**
 * Notification state API.
 */

require_once __DIR__ . '/../includes/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(SESSION_NAME);
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user = app_get_session_user();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    if (!in_array($action, ['mark_read', 'mark_unread'], true)) {
        throw new Exception('Invalid action');
    }

    $raw_ids = (string) ($_POST['ids'] ?? $_GET['ids'] ?? '');
    $ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/[,\s]+/', $raw_ids)))));
    $ids = array_slice($ids, 0, 100);

    if (empty($ids)) {
        echo json_encode(['success' => true, 'updated' => 0]);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = $ids;
    $params[] = (int) ($user['id'] ?? 0);

    $ownership_sql = 'user_id = ?';
    if ((int) ($user['branch_id'] ?? 0) > 0) {
        $ownership_sql .= ' OR (user_id IS NULL AND branch_id = ?)';
        $params[] = (int) $user['branch_id'];
    }

    $state_sql = $action === 'mark_unread'
        ? 'is_read = 0, read_at = NULL'
        : 'is_read = 1, read_at = COALESCE(read_at, NOW())';

    $stmt = $pdo->prepare("
        UPDATE transfer_notifications
        SET $state_sql
        WHERE id IN ($placeholders)
          AND ($ownership_sql)
    ");
    $stmt->execute($params);

    echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
