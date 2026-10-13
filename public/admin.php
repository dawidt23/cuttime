<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_role('admin');
$pageTitle = 'Część administratora';
$pageDescription = 'Jesteś zalogowany jako administrator. Zarządzanie salonem udostępnimy w kolejnych etapach.';
require dirname(__DIR__) . '/includes/account-view.php';
