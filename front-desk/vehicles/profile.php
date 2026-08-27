<?php
/**
 * Front-Desk Vehicle Profile & History View
 */

require_once '../../includes/config.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$user = app_get_session_user();
if (!is_array($user)) {
    redirect('/hwtires/index.php');
}

$page_title = 'Vehicle Profile';

require_once '../../includes/vehicle-profile-workspace.php';
