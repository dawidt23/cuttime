<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_role('client');
$pageTitle = 'Część klienta';
$pageDescription = 'Zarezerwuj wizytę z oferty salonu. W sekcji Moje rezerwacje sprawdzisz swoje terminy i anulujesz przyszłą wizytę.';
require dirname(__DIR__) . '/includes/account-view.php';
