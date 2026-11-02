<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_role('admin');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Ta metoda żądania nie jest obsługiwana.');
}

require_once dirname(__DIR__) . '/includes/admin-dashboard.php';
$dashboard = admin_dashboard();
$adminSection = 'dashboard';
$pageTitle = 'Dashboard';
$pageDescription = 'Przegląd klientów, zespołu, oferty i rezerwacji w jednym miejscu.';
require dirname(__DIR__) . '/includes/account-view.php';
