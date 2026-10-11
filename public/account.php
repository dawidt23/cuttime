<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_login();

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
$messages = consume_flash();
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Twoje konto — CutTime</title>
    <link rel="stylesheet" href="/css/auth.css">
    <script src="/js/session.js" defer></script>
</head>
<body class="info-page">
<main class="info-card">
    <a class="brand brand-dark" href="/account.php">CutTime<span aria-hidden="true">.</span></a>
    <p class="eyebrow">TWOJE KONTO</p>
    <h1>Jesteś zalogowany</h1>
    <?php foreach ($messages as $message): ?>
        <div class="notice notice-success" role="status"><?= e($message['message']) ?></div>
    <?php endforeach; ?>
    <p class="intro">Witaj, <?= e($user['name']) ?>! Logowanie na konto <?= e($user['email']) ?> jest aktywne.</p>
    <p class="hint">Kolejne możliwości Twojego konta udostępnimy wkrótce.</p>
    <form action="/logout.php" method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <button class="button" type="submit">Wyloguj się <span aria-hidden="true">→</span></button>
    </form>
</main>
</body>
</html>
