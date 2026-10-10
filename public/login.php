<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
header('Content-Type: text/html; charset=UTF-8');
// Wyłącznie informacja, aby odnośnik po rejestracji nie prowadził do błędu 404.
// Brak formularza, sprawdzania hasła i ustawiania zalogowanego użytkownika.
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Logowanie — CutTime</title>
    <link rel="stylesheet" href="/css/auth.css">
</head>
<body class="info-page">
<main class="info-card">
    <a class="brand brand-dark" href="/register.php">CutTime<span aria-hidden="true">.</span></a>
    <p class="eyebrow">TWOJE KONTO</p>
    <h1>Logowanie już wkrótce</h1>
    <p class="intro">Rejestracja jest dostępna. Możliwość logowania przygotujemy w kolejnym kroku. Jeśli utworzyłeś konto, nie musisz rejestrować się ponownie.</p>
    <a class="button" href="/register.php">Wróć do rejestracji <span aria-hidden="true">→</span></a>
</main>
</body>
</html>
