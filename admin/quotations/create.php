<?php
require_once '../../includes/config.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$user = app_get_session_user();
$role = is_array($user) ? ($user['role'] ?? 'admin') : 'admin';

set_flash_message('Admin quotation creation is disabled. Use the front desk quotation flow for branch operations.', 'warning');
redirect('/hwtires/' . $role . '/quotations/');
