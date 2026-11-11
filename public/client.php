<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_role('client');
$pageTitle = 'Część klienta';
$pageDescription = 'Przejrzyj ofertę salonu i wybierz usługę. Wybór pracownika, terminu i potwierdzenie wizyty będą dostępne wkrótce.';
require dirname(__DIR__) . '/includes/account-view.php';
