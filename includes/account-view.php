<?php
declare(strict_types=1);
// Wspólny widok poza public/. Kontroler przed dołączeniem wymaga właściwej roli.
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
    <title><?= e($pageTitle) ?> — CutTime</title>
    <link rel="stylesheet" href="/css/auth.css">
    <script src="/js/session.js" defer></script>
</head>
<body class="info-page">
<main class="info-card">
    <a class="brand brand-dark" href="<?= e(role_home($user['role'])) ?>">CutTime<span aria-hidden="true">.</span></a>
    <p class="eyebrow">TWOJE KONTO</p>
    <h1><?= e($pageTitle) ?></h1>
    <?php foreach ($messages as $message): ?>
        <div class="notice <?= $message['type'] === 'error' ? 'notice-error' : 'notice-success' ?>"
            role="<?= $message['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($message['message']) ?></div>
    <?php endforeach; ?>
    <p class="intro">Witaj, <?= e($user['name']) ?>! Logowanie na konto <?= e($user['email']) ?> jest aktywne.</p>
    <p><?= e($pageDescription) ?></p>
    <nav aria-label="Nawigacja konta">
        <p><a href="<?= e(role_home($user['role'])) ?>">Moja część serwisu</a> · <a href="/account.php">Moje konto</a></p>
    </nav>
    <form action="/logout.php" method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <button class="button" type="submit">Wyloguj się <span aria-hidden="true">→</span></button>
    </form>
</main>
</body>
</html>
