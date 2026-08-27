<?php
/**
 * Admin service status has been merged into Job Order.
 */

require_once '../../includes/config.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$user = app_get_session_user();

if (($user['role'] ?? '') === 'admin') {
    redirect('/hwtires/admin/job-orders/');
}

redirect('/hwtires/front-desk/service-status/');
?>
