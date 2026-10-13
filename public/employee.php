<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_role('employee');
$pageTitle = 'Część pracownika';
$pageDescription = 'Jesteś zalogowany jako pracownik. Terminarz i obsługę wizyt udostępnimy w kolejnych etapach.';
require dirname(__DIR__) . '/includes/account-view.php';
