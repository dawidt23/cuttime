<?php
declare(strict_types=1);
// Wspólny widok poza public/. Kontroler przed dołączeniem wymaga właściwej roli.
html_response_headers();
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
    <?php require __DIR__ . '/flash-view.php'; ?>
    <p class="intro">Witaj, <?= e($user['name']) ?>! Logowanie na konto <?= e($user['email']) ?> jest aktywne.</p>
    <p><?= e($pageDescription) ?></p>
    <?php require __DIR__ . '/account-navigation.php'; ?>
</main>
</body>
</html>
