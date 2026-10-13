<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_roles(['client', 'employee', 'admin']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Ta metoda żądania nie jest obsługiwana.');
}

// require_roles() pobiera konto przez current_user(), po ID z sesji.
$values = array_intersect_key($user, array_flip(['name', 'surname', 'email', 'phone']));
$values['phone'] ??= '';
$errors = [];
$editing = false;
require dirname(__DIR__) . '/includes/profile-view.php';
