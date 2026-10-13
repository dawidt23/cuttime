<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_role('client');
$pageTitle = 'Część klienta';
$pageDescription = 'Jesteś zalogowany jako klient. Możliwości planowania wizyt udostępnimy w kolejnych etapach.';
require dirname(__DIR__) . '/includes/account-view.php';
