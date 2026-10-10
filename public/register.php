<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/registration.php';

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
$values = array_fill_keys(['name', 'surname', 'email', 'phone'], '');
$errors = [];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    // Limit dotyczy całego żądania, także dodatkowych pól. Nie zapisujemy haseł w sesji.
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
        http_response_code(413);
        $errors['_form'] = 'Formularz zawiera zbyt dużo danych. Wpisz krótsze wartości.';
    } elseif (!csrf_is_valid($_POST['_csrf'] ?? null)) {
        http_response_code(403);
        $errors['_form'] = 'Formularz wygasł lub nie został poprawnie wysłany. Spróbuj ponownie.';
    } else {
        $result = register_client($_POST);
        $values = $result['values'];
        $errors = $result['errors'];
        if ($result['success']) {
            unset($_SESSION['_csrf_token']);
            flash('success', 'Konto zostało utworzone. Dziękujemy za rejestrację w CutTime!');
            redirect('/register.php');
        }
        http_response_code(422);
    }
} elseif ($method !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Ta metoda żądania nie jest obsługiwana.');
}

$messages = consume_flash();
$registered = false;
foreach ($messages as $message) {
    if ($message['type'] === 'success') {
        $registered = true;
    }
}
$fields = [
    'name' => ['Imię', 'text', 'given-name', 50, 'Anna'],
    'surname' => ['Nazwisko', 'text', 'family-name', 80, 'Kowalska'],
    'email' => ['Adres e-mail', 'email', 'email', 254, 'anna@example.com'],
    'phone' => ['Telefon', 'tel', 'tel', 32, '+48 500 100 200'],
    'password' => ['Hasło', 'password', 'new-password', 72, 'Co najmniej 12 znaków'],
    'password_confirmation' => ['Powtórz hasło', 'password', 'new-password', 72, 'Wpisz hasło ponownie'],
];
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Utwórz konto — CutTime</title>
    <link rel="stylesheet" href="/css/auth.css">
</head>
<body>
<main class="auth-layout">
    <aside class="brand-panel" aria-label="CutTime">
        <a class="brand" href="/register.php">CutTime<span aria-hidden="true">.</span></a>
        <div class="brand-copy">
            <p class="eyebrow">TWÓJ STYL. TWÓJ CZAS.</p>
            <h1>Dobry dzień<br>zaczyna się<br>od Ciebie.</h1>
            <p>Dołącz do CutTime. Twoje konto będzie pierwszym krokiem do wygodnego planowania wizyt w salonie.</p>
        </div>
        <p class="brand-footer">Miejsce na nowy początek.</p>
    </aside>

    <section class="form-panel" aria-labelledby="form-title">
        <div class="form-container">
            <p class="eyebrow">KONTO KLIENTA</p>
            <h2 id="form-title"><?= $registered ? 'Witaj w CutTime!' : 'Utwórz konto' ?></h2>
            <?php foreach ($messages as $message): ?>
                <div class="notice notice-success" role="status"><?= e($message['message']) ?></div>
            <?php endforeach; ?>
            <?php if ($registered): ?>
                <p class="intro">Twoje dane zostały zapisane. Możliwość logowania udostępnimy w kolejnym kroku.</p>
                <a class="button" href="/login.php">Przejdź do strony logowania <span aria-hidden="true">→</span></a>
            <?php else: ?>
                <p class="intro">Wypełnij dane poniżej. Wszystkie pola są wymagane.</p>
                <?php if ($errors !== []): ?>
                    <div class="notice notice-error" role="alert" tabindex="-1" id="form-errors">
                        <strong>Nie udało się utworzyć konta.</strong>
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
                <form action="/register.php" method="post">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <div class="form-grid">
                        <?php foreach ($fields as $field => [$label, $type, $autocomplete, $max, $placeholder]): ?>
                            <div class="field<?= in_array($field, ['email', 'phone'], true) ? ' field-wide' : '' ?>">
                                <label for="<?= e($field) ?>"><?= e($label) ?></label>
                                <input id="<?= e($field) ?>" name="<?= e($field) ?>" type="<?= e($type) ?>"
                                    autocomplete="<?= e($autocomplete) ?>" maxlength="<?= e((string) $max) ?>" required
                                    placeholder="<?= e($placeholder) ?>"
                                    <?php if ($type !== 'password'): ?>value="<?= e($values[$field]) ?>"<?php endif; ?>
                                    <?php if ($type === 'password'): ?>minlength="12"<?php endif; ?>
                                    <?php if ($type === 'tel'): ?>inputmode="tel"<?php endif; ?>
                                    <?php if (isset($errors[$field])): ?>aria-invalid="true"<?php endif; ?>
                                    aria-describedby="<?= e($field) ?>-hint<?= isset($errors[$field]) ? ' ' . e($field) . '-error' : '' ?>">
                                <small id="<?= e($field) ?>-hint" class="hint">
                                    <?php if ($field === 'password'): ?>Minimum 12 znaków, maksymalnie 72 bajty. Polskie znaki zajmują więcej bajtów.
                                    <?php elseif ($field === 'password_confirmation'): ?>Powtórz dokładnie to samo hasło.
                                    <?php elseif ($field === 'phone'): ?>Polski numer: 9 cyfr, opcjonalnie +48, spacje lub myślniki.
                                    <?php elseif ($field === 'email'): ?>Ten adres będzie służyć do logowania.
                                    <?php else: ?>Litery, spacje, apostrofy i myślniki; maks. <?= e((string) $max) ?> znaków.
                                    <?php endif; ?>
                                </small>
                                <?php if (isset($errors[$field])): ?>
                                    <span class="field-error" id="<?= e($field) ?>-error"><?= e($errors[$field]) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="button" type="submit">Utwórz konto <span aria-hidden="true">→</span></button>
                </form>
                <p class="login-note">Masz już konto? <a href="/login.php">Informacje o logowaniu</a></p>
            <?php endif; ?>
            <p class="form-footer">CutTime · Twój czas na dobrą fryzurę</p>
        </div>
    </section>
</main>
</body>
</html>
