<?php
declare(strict_types=1);
// Wspólny widok poza public/. Kontroler przed dołączeniem wymaga właściwej roli.
html_response_headers();
$messages = consume_flash();
$adminSection = $adminSection ?? null; // Ustawiane wyłącznie przez chroniony kontroler.
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
<body class="info-page<?= $adminSection !== null ? ' admin-page' : '' ?>">
<main class="info-card<?= $adminSection !== null ? ' admin-card' : '' ?>">
    <a class="brand brand-dark" href="<?= e(role_home($user['role'])) ?>">CutTime<span aria-hidden="true">.</span></a>
    <p class="eyebrow"><?= $adminSection !== null ? 'PANEL ADMINISTRATORA' : 'TWOJE KONTO' ?></p>
    <h1><?= e($pageTitle) ?></h1>
    <?php require __DIR__ . '/flash-view.php'; ?>
    <p class="intro">Witaj, <?= e($user['name']) ?>! Logowanie na konto <?= e($user['email']) ?> jest aktywne.</p>
    <p><?= e($pageDescription) ?></p>
    <?php if ($adminSection !== null): ?>
        <?php require __DIR__ . '/admin-navigation.php'; ?>
        <?php if ($adminSection === 'dashboard'): ?>
            <?php require __DIR__ . '/admin-dashboard-view.php'; ?>
        <?php elseif ($adminSection === 'categories'): ?>
            <?php require __DIR__ . '/admin-categories-view.php'; ?>
        <?php elseif ($adminSection === 'services'): ?>
            <?php require __DIR__ . '/admin-services-view.php'; ?>
        <?php elseif ($adminSection === 'employees'): ?>
            <?php require __DIR__ . '/admin-employees-view.php'; ?>
        <?php elseif ($adminSection === 'employee-services'): ?>
            <?php require __DIR__ . '/admin-employee-services-view.php'; ?>
        <?php elseif ($adminSection === 'availability'): ?>
            <?php require __DIR__ . '/admin-availability-view.php'; ?>
        <?php endif; ?>
    <?php endif; ?>
    <?php require __DIR__ . '/account-navigation.php'; ?>
</main>
</body>
</html>
