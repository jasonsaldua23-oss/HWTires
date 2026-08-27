<?php
/**
 * Job Order Detail View - Front-Desk Version
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

$job_detail_context = 'front-desk';
$page_title = 'Job Order Details';

require_once '../../includes/job-order-detail-view.php';
