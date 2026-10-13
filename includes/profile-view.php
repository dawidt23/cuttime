<?php
declare(strict_types=1);
// Widok poza public/; oba kontrolery przed dołączeniem sprawdzają zalogowanie i rolę.
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
$messages = consume_flash();
$pageTitle = $editing ? 'Edytuj dane' : 'Mój profil';
$roleLabels = ['client' => 'Klient', 'employee' => 'Pracownik', 'admin' => 'Administrator'];
$fields = [
    'name' => ['Imię', 'text', 'given-name', 50, 'Litery, spacje, apostrofy i myślniki; maks. 50 znaków.'],
    'surname' => ['Nazwisko', 'text', 'family-name', 80, 'Litery, spacje, apostrofy i myślniki; maks. 80 znaków.'],
    'email' => ['Adres e-mail', 'email', 'email', 254, 'Ten adres służy do logowania na Twoje konto.'],
    'phone' => ['Telefon', 'tel', 'tel', 32, 'Polski numer: 9 cyfr, opcjonalnie +48, spacje lub myślniki.'],
];
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
<main class="info-card profile-card">
    <a class="brand brand-dark" href="<?= e(role_home($user['role'])) ?>">CutTime<span aria-hidden="true">.</span></a>
    <p class="eyebrow">TWOJE KONTO</p>
    <h1><?= e($pageTitle) ?></h1>
    <p class="intro">Rola konta: <strong><?= e($roleLabels[$user['role']]) ?></strong></p>
    <?php foreach ($messages as $message): ?>
        <div class="notice <?= $message['type'] === 'error' ? 'notice-error' : 'notice-success' ?>"
            role="<?= $message['type'] === 'error' ? 'alert' : 'status' ?>"><?= e($message['message']) ?></div>
    <?php endforeach; ?>
    <?php if ($editing): ?>
        <p class="intro">Zaktualizuj swoje dane. Wszystkie cztery pola są wymagane.</p>
        <?php if ($errors !== []): ?>
            <div class="notice notice-error" role="alert" tabindex="-1" id="form-errors">
                <strong>Nie udało się zapisać danych.</strong>
                <?php if (isset($errors['_form'])): ?>
                    <p><?= e($errors['_form']) ?></p>
                <?php else: ?>
                    <ul>
                        <?php foreach ($errors as $field => $error): ?>
                            <li><a href="#<?= e($field) ?>"><?= e($fields[$field][0]) ?>: <?= e($error) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <form action="/profile-edit.php" method="post">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <div class="form-grid">
                <?php foreach ($fields as $field => [$label, $type, $autocomplete, $max, $hint]): ?>
                    <div class="field<?= in_array($field, ['email', 'phone'], true) ? ' field-wide' : '' ?>">
                        <label for="<?= e($field) ?>"><?= e($label) ?></label>
                        <input id="<?= e($field) ?>" name="<?= e($field) ?>" type="<?= e($type) ?>"
                            value="<?= e($values[$field]) ?>" autocomplete="<?= e($autocomplete) ?>"
                            maxlength="<?= e((string) $max) ?>" required
                            <?php if ($type === 'tel'): ?>inputmode="tel"<?php endif; ?>
                            <?php if (isset($errors[$field])): ?>aria-invalid="true"<?php endif; ?>
                            aria-describedby="<?= e($field) ?>-hint<?= isset($errors[$field]) ? ' ' . e($field) . '-error' : '' ?>">
                        <small id="<?= e($field) ?>-hint" class="hint"><?= e($hint) ?></small>
                        <?php if (isset($errors[$field])): ?>
                            <span class="field-error" id="<?= e($field) ?>-error"><?= e($errors[$field]) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <button class="button" type="submit">Zapisz zmiany <span aria-hidden="true">→</span></button>
        </form>
        <p class="login-note"><a href="/profile.php">Anuluj i wróć do profilu</a></p>
    <?php else: ?>
        <dl class="profile-details">
            <?php foreach ($fields as $field => [$label]): ?>
                <div><dt><?= e($label) ?></dt><dd><?= e($values[$field] === '' ? 'Nie podano' : $values[$field]) ?></dd></div>
            <?php endforeach; ?>
        </dl>
        <a class="button" href="/profile-edit.php">Edytuj dane <span aria-hidden="true">→</span></a>
    <?php endif; ?>
    <nav class="login-note" aria-label="Nawigacja konta">
        <a href="<?= e(role_home($user['role'])) ?>">Moja część serwisu</a> · <a href="/account.php">Moje konto</a>
    </nav>
</main>
</body>
</html>
