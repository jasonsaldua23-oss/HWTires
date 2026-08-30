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
if ($user && isset($user['id'])) {
    log_audit('users', 'logout', (int)$user['id']);
}

// Unset all session data
$_SESSION = [];

// Expire and clear session cookie in browser
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

// Destroy session on server
session_destroy();

// Prevent caching of logout response
app_send_no_cache_headers();

// Redirect to login page
$redirect_url = APP_URL . '/index.php' . (isset($_GET['timeout']) ? '?timeout=1' : '');
redirect($redirect_url);
