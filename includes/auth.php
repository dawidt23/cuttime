<?php
declare(strict_types=1);

/** ID jest ustawiane przez log_in() dopiero po uwierzytelnieniu. */
function current_user_id(): ?int
{
    start_secure_session();
    $id = $_SESSION['user_id'] ?? null;

    return is_int($id) && $id > 0 ? $id : null;
}

/** Odczyt aktywnego konta i aktualnej roli; sam nie chroni strony. */
function current_user(): ?array
{
    $id = current_user_id();
    if ($id === null) {
        unset($_SESSION['user_id'], $_SESSION['user_role']);
        return null;
    }

    $statement = db()->prepare(
        'SELECT id, name, surname, email, phone, role FROM users WHERE id = :id AND active = 1'
    );
    $statement->execute(['id' => $id]);
    $user = $statement->fetch();

    if ($user === false || !in_array($user['role'], ['client', 'employee', 'admin'], true)) {
        unset($_SESSION['user_id'], $_SESSION['user_role']);
        return null;
    }

    $_SESSION['user_role'] = $user['role'];
    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

/** Przygotowanie do późniejszych kontroli ról; rola pochodzi z bazy, nie z formularza. */
function current_user_role(): ?string
{
    $user = current_user();

    return $user['role'] ?? null;
}

/** Wywołuj po bootstrapie, przed HTML każdej strony wymagającej logowania. */
function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        redirect('/login.php');
    }

    return $user;
}

/** Kończy bieżącą sesję. Kontroler musi wcześniej sprawdzić POST i CSRF. */
function log_out(): void
{
    start_secure_session();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        if (!setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $cookie['path'],
            'domain' => $cookie['domain'],
            'secure' => $cookie['secure'],
            'httponly' => $cookie['httponly'],
            'samesite' => $cookie['samesite'],
        ])) {
            throw new RuntimeException('Unable to clear session cookie.');
        }
    }

    if (!session_destroy()) {
        throw new RuntimeException('Unable to end session.');
    }
    // Nie pozwalamy helperom odtworzyć starego ID w tym samym żądaniu.
    unset($_COOKIE[session_name()]);
    session_id('');
}

/** Walidacja nie otwiera bazy. Tylko values wolno ponownie pokazać w formularzu. */
function validate_login(array $input): array
{
    $values = ['email' => ''];
    $errors = [];
    $password = '';

    foreach (['email' => 254, 'password' => 72] as $field => $maxBytes) {
        $value = $input[$field] ?? '';
        if (!is_string($value)) {
            $errors[$field] = 'Wpisz pojedynczą wartość tekstową.';
            continue;
        }
        // Ograniczenie rozmiaru przed operacjami Unicode i sprawdzaniem hasha.
        if (strlen($value) > $maxBytes) {
            $errors[$field] = 'Wpisana wartość jest zbyt długa.';
            continue;
        }
        if (preg_match('//u', $value) !== 1 || preg_match('/\p{C}/u', $value) === 1) {
            $errors[$field] = 'Wartość zawiera niedozwolone znaki.';
            continue;
        }
        if (preg_match('/\A[\s\p{Z}]*\z/u', $value) === 1) {
            $errors[$field] = $field === 'email' ? 'Podaj adres e-mail.' : 'Podaj hasło.';
            continue;
        }
        if ($field === 'email') {
            $values['email'] = strtolower(preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $value));
            if (filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
                $errors['email'] = 'Podaj poprawny adres e-mail.';
            }
        } else {
            // Bez trimowania i bez minimum rejestracji: istnieją też starsze konta testowe.
            $password = $value;
        }
    }

    return ['values' => $values, 'errors' => $errors, 'password' => $password];
}

/** Jedno uwierzytelnianie dla wszystkich ról. Opcjonalne PDO służy testom. */
function log_in(array $input, ?PDO $connection = null): array
{
    $validated = validate_login($input);
    $result = ['values' => $validated['values'], 'errors' => $validated['errors'], 'success' => false];
    if ($result['errors'] !== []) {
        return $result;
    }

    $connection ??= db();
    $statement = $connection->prepare('SELECT id, password, role, active FROM users WHERE email = :email LIMIT 1');
    $statement->execute(['email' => $validated['values']['email']]);
    $user = $statement->fetch();

    // Stały hash zastępczy nie należy do konta. Również brak użytkownika wymaga
    // wykonania password_verify(), bez tworzenia nowego hasha w każdym żądaniu.
    $dummyHash = '$2y$12$4Umg0rCJwMswRw/l.SwHvuQV01coP0eWmGzd61QH2RvAOMANUBGC.';
    $passwordMatches = password_verify($validated['password'], $user === false ? $dummyHash : $user['password']);
    if ($user === false || !$passwordMatches || (int) $user['active'] !== 1
        || (int) $user['id'] <= 0 || !in_array($user['role'], ['client', 'employee', 'admin'], true)) {
        $result['errors']['_form'] = 'Nieprawidłowy adres e-mail lub hasło.';
        return $result;
    }

    start_secure_session();
    if (!session_regenerate_id(true)) {
        throw new RuntimeException('Unable to renew session.');
    }
    // Nowa sesja uwierzytelniona: bez danych formularza, hasła, hasha i starego CSRF.
    $_SESSION = ['user_id' => (int) $user['id'], 'user_role' => $user['role']];
    $result['success'] = true;

    return $result;
}
