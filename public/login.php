<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
html_response_headers();
$values = ['email' => ''];
$errors = [];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Ta metoda żądania nie jest obsługiwana.');
}

$user = current_user();
if ($user !== null) {
    redirect(role_home($user['role']));
}
if ($method === 'POST') {
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
        http_response_code(413);
        $errors['_form'] = 'Formularz zawiera zbyt dużo danych. Wpisz krótsze wartości.';
    } elseif (!csrf_is_valid($_POST['_csrf'] ?? null)) {
        http_response_code(403);
        $errors['_form'] = 'Formularz wygasł lub nie został poprawnie wysłany. Spróbuj ponownie.';
    } else {
        $result = log_in($_POST);
        $values = $result['values'];
        $errors = $result['errors'];
        if ($result['success']) {
            flash('success', 'Logowanie zakończone pomyślnie.');
            $user = require_login();
            redirect(role_home($user['role']));
        }
        http_response_code(422);
    }
}

$messages = consume_flash();
if (($_GET['logged_out'] ?? null) === '1') {
    // Stały komunikat; nie tworzymy nowej sesji flash w kontrolerze wylogowania.
    $messages[] = ['type' => 'success', 'message' => 'Wylogowano pomyślnie.'];
}
$fields = [
    'email' => ['Adres e-mail', 'email', 'username', 254, 'anna@example.com'],
    'password' => ['Hasło', 'password', 'current-password', 72, 'Wpisz swoje hasło'],
];
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Zaloguj się — CutTime</title>
    <link rel="stylesheet" href="/css/auth.css">
</head>
<body>
<main class="auth-layout">
    <aside class="brand-panel" aria-label="CutTime">
        <a class="brand" href="/login.php">CutTime<span aria-hidden="true">.</span></a>
        <div class="brand-copy">
            <p class="eyebrow">TWÓJ STYL. TWÓJ CZAS.</p>
            <p class="brand-title">Miło Cię<br>znów<br>widzieć.</p>
            <p>Twój czas na dobrą fryzurę. Wróć do swojego konta w CutTime.</p>
        </div>
        <p class="brand-footer">Miejsce na nowy początek.</p>
    </aside>

    <section class="form-panel" aria-labelledby="form-title">
        <div class="form-container">
            <p class="eyebrow">TWOJE KONTO</p>
            <h1 class="form-title" id="form-title">Zaloguj się</h1>
            <?php require dirname(__DIR__) . '/includes/flash-view.php'; ?>
            <p class="intro">Podaj adres e-mail i hasło do swojego konta.</p>
            <?php if ($errors !== []): ?>
                <div class="notice notice-error" role="alert" tabindex="-1" id="form-errors" autofocus>
                    <strong>Nie udało się zalogować.</strong>
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
            <form action="/login.php" method="post">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <div class="form-grid">
                    <?php foreach ($fields as $field => [$label, $type, $autocomplete, $max, $placeholder]): ?>
                        <div class="field field-wide">
                            <label for="<?= e($field) ?>"><?= e($label) ?></label>
                            <input id="<?= e($field) ?>" name="<?= e($field) ?>" type="<?= e($type) ?>"
                                autocomplete="<?= e($autocomplete) ?>" maxlength="<?= e((string) $max) ?>" required
                                placeholder="<?= e($placeholder) ?>"
                                <?php if ($field === 'email'): ?>value="<?= e($values['email']) ?>"<?php endif; ?>
                                <?php if (isset($errors[$field])): ?>aria-invalid="true" aria-describedby="<?= e($field) ?>-error"<?php endif; ?>>
                            <?php if (isset($errors[$field])): ?>
                                <span class="field-error" id="<?= e($field) ?>-error"><?= e($errors[$field]) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button class="button" type="submit">Zaloguj się <span aria-hidden="true">→</span></button>
            </form>
            <p class="login-note">Nie masz konta? <a href="/register.php">Utwórz konto</a></p>
            <p class="form-footer">CutTime · Twój czas na dobrą fryzurę</p>
        </div>
    </section>
</main>
</body>
</html>
