<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_role('client');
$pageTitle = 'Część klienta';
$pageDescription = 'Przejrzyj ofertę salonu, wybierz pracownika i wolny termin, a następnie potwierdź rezerwację wizyty.';
require dirname(__DIR__) . '/includes/account-view.php';
