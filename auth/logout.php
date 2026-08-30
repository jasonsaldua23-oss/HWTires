<?php
require_once __DIR__ . '/../includes/config.php';
$query = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
redirect(APP_URL . '/logout.php' . $query);
