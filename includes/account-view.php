<?php
declare(strict_types=1);
// Wspólny layout. Chronione kontrolery sprawdzają rolę; oferta jest publiczna.
html_response_headers();
$messages = consume_flash();
$adminSection = $adminSection ?? null; // Ustawiane wyłącznie przez chroniony kontroler.
$offerSection = $offerSection ?? null; // Ustawiane wyłącznie przez kontroler oferty.
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
<body class="info-page<?= $adminSection !== null ? ' admin-page' : ($offerSection !== null ? ' offer-page' : '') ?>">
<main class="info-card<?= $adminSection !== null ? ' admin-card' : ($offerSection !== null ? ' offer-card' : '') ?>">
    <a class="brand brand-dark" href="<?= e($offerSection !== null ? '/services.php' : role_home($user['role'])) ?>">CutTime<span aria-hidden="true">.</span></a>
    <p class="eyebrow"><?= $adminSection !== null ? 'PANEL ADMINISTRATORA' : ($offerSection !== null ? 'TWÓJ STYL. TWÓJ CZAS.' : 'TWOJE KONTO') ?></p>
    <h1><?= e($pageTitle) ?></h1>
    <?php require __DIR__ . '/flash-view.php'; ?>
    <?php if ($offerSection === null): ?>
        <p class="intro">Witaj, <?= e($user['name']) ?>! Logowanie na konto <?= e($user['email']) ?> jest aktywne.</p>
    <?php endif; ?>
    <?php if ($pageDescription !== ''): ?><p class="<?= $offerSection !== null ? 'intro' : '' ?>"><?= e($pageDescription) ?></p><?php endif; ?>
    <?php if ($offerSection !== null): ?>
        <?php require __DIR__ . '/offer-view.php'; ?>
    <?php endif; ?>
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
        <?php elseif ($adminSection === 'reservations'): ?>
            <?php require __DIR__ . '/admin-reservations-view.php'; ?>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($user !== null): ?>
        <?php require __DIR__ . '/account-navigation.php'; ?>
    <?php else: ?>
        <nav class="account-nav" aria-label="Nawigacja publiczna">
            <a href="/services.php"<?= $offerSection === 'list' ? ' aria-current="page"' : '' ?>>Oferta usług</a>
            <a href="/login.php">Zaloguj się</a>
            <a href="/register.php">Utwórz konto</a>
        </nav>
    <?php endif; ?>
</main>
</body>
</html>
