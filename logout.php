<?php
/**
 * Logout Handler
 */

require_once 'includes/config.php';
session_name(SESSION_NAME);
session_start();

// Get user info before destroying session
$user = app_get_session_user();

// Log the logout action
if ($user) {
    log_audit('users', 'logout', $user['id']);
}

// Destroy session
session_destroy();

// Redirect to login
redirect(APP_URL . '/index.php');
?>
